'use strict';

/**
 * Weline AI 获客浏览器桥 —— MV3 service worker。
 *
 * 职责：票据连接、SSE 事件接收、智能体循环（POST /step）、工具执行
 * （web_search / inspect_page / save_lead / list_existing_leads）、
 * WASM 策略内核（打分与循环预算）。
 *
 * 安全：所有后端请求 credentials:'omit' 且 20s 超时；票据只存内存，
 * 不写 storage、不进日志；后端地址必须与连接上下文同一来源。
 */

const API_PREFIX = '/ai_acquisition/frontend/bridge';
const REQUEST_TIMEOUT_MS = 20000;
const DEFAULT_MAX_ITERATIONS = 8;
const DEFAULT_TARGET_COUNT = 10;
const MIN_LEAD_SCORE = 6;
const MAX_SEARCH_RESULTS = 10;
const MAX_LOG_ENTRIES = 40;
const MAX_TOOL_RESULT_CHARS = 8000;
const KEEPALIVE_ALARM = 'weline-bridge-keepalive';

const TOOL_DEFINITIONS = [
  {
    type: 'function',
    function: {
      name: 'web_search',
      description: '用公开搜索引擎检索候选企业，返回标题、URL 与摘要。',
      parameters: {
        type: 'object',
        properties: {
          query: { type: 'string', description: '检索词，建议包含买家意向词与地区' },
        },
        required: ['query'],
      },
    },
  },
  {
    type: 'function',
    function: {
      name: 'inspect_page',
      description: '读取一个公开页面的标题、正文摘要与公开邮箱，用于核实线索。',
      parameters: {
        type: 'object',
        properties: {
          url: { type: 'string', description: '要检查的公开页面 URL' },
        },
        required: ['url'],
      },
    },
  },
  {
    type: 'function',
    function: {
      name: 'save_lead',
      description: '保存一条合格潜客（5 轴评分 ≥6 才入库），返回是否新建或合并。',
      parameters: {
        type: 'object',
        properties: {
          company_name: { type: 'string' },
          company_domain: { type: 'string' },
          country_code: { type: 'string', description: 'ISO2 国家代码' },
          contact_name: { type: 'string' },
          contact_role: { type: 'string' },
          email: { type: 'string', description: '公开商务邮箱，禁止编造' },
          source_url: { type: 'string' },
          source_title: { type: 'string' },
          source_excerpt: { type: 'string' },
          ai_preference: { type: 'string' },
          ai_business_direction: { type: 'string' },
          ai_reason: { type: 'string' },
          axes: {
            type: 'array',
            description: '5 轴评分，每轴 0–2',
            items: { type: 'integer', minimum: 0, maximum: 2 },
            minItems: 5,
            maxItems: 5,
          },
        },
        required: ['company_name', 'source_url', 'axes'],
      },
    },
  },
  {
    type: 'function',
    function: {
      name: 'list_existing_leads',
      description: '按关键词查询已入库线索，避免重复添加。',
      parameters: {
        type: 'object',
        properties: {
          keyword: { type: 'string', description: '公司、域名或邮箱关键词' },
        },
        required: ['keyword'],
      },
    },
  },
];

const state = {
  running: false,
  stopRequested: false,
  baseUrl: '',
  ticket: '',
  allowedOrigin: '',
  run: null,
  modelMode: 'ai_module',
  modelLabel: '',
  logs: [],
  cost: 0,
  saved: 0,
  searches: 0,
  iterations: 0,
  lastEventId: 0,
  sseAbort: null,
  wasm: null,
};

function maskEmails(text) {
  return String(text || '').replace(
    /[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/gi,
    (email) => {
      const at = email.indexOf('@');
      if (at <= 0) {
        return '***';
      }
      return `${email.slice(0, 2)}***@${email.slice(at + 1)}`;
    }
  );
}

function log(message) {
  const time = new Date().toLocaleTimeString('zh-CN', { hour12: false });
  state.logs.push(`[${time}] ${maskEmails(message).slice(0, 300)}`);
  if (state.logs.length > MAX_LOG_ENTRIES) {
    state.logs.splice(0, state.logs.length - MAX_LOG_ENTRIES);
  }
  broadcast();
}

function publicState() {
  return {
    running: state.running,
    modelMode: state.modelMode || 'ai_module',
    modelLabel: state.modelLabel || '',
    stopRequested: state.stopRequested,
    run: state.run,
    cost: Number(state.cost.toFixed(6)),
    saved: state.saved,
    searches: state.searches,
    iterations: state.iterations,
    lastEventId: state.lastEventId,
    logs: state.logs.slice(-MAX_LOG_ENTRIES),
  };
}

function broadcast() {
  try {
    chrome.runtime.sendMessage({ type: 'WELINE_STATE', state: publicState() }).catch(() => {});
  } catch (error) {
    // 弹窗未打开时忽略
  }
}

function sleep(milliseconds) {
  return new Promise((resolve) => setTimeout(resolve, milliseconds));
}

function clampInt(value, min, max, fallback) {
  const number = Number.parseInt(String(value ?? ''), 10);
  if (!Number.isFinite(number)) {
    return fallback;
  }
  return Math.max(min, Math.min(max, number));
}

function assertBaseUrl(raw) {
  const text = String(raw || '').trim();
  if (!text) {
    throw new Error('后端地址为空');
  }
  let url;
  try {
    url = new URL(text);
  } catch (error) {
    throw new Error('后端地址格式不正确');
  }
  if (url.protocol !== 'http:' && url.protocol !== 'https:') {
    throw new Error('后端地址必须是 http(s)');
  }
  if (url.username || url.password) {
    throw new Error('后端地址不能包含账号密码');
  }
  return url;
}

function buildUrl(baseUrl, path) {
  const base = assertBaseUrl(baseUrl);
  const baseHref = base.href.endsWith('/') ? base.href : `${base.href}/`;
  const url = new URL(String(path).replace(/^\/+/, ''), baseHref);
  if (url.origin !== base.origin) {
    throw new Error('请求地址与后端来源不一致');
  }
  if (state.allowedOrigin && url.origin !== state.allowedOrigin) {
    throw new Error('后端来源在运行期间被更改');
  }
  return url.toString();
}

async function fetchWithTimeout(url, options = {}, timeoutMs = REQUEST_TIMEOUT_MS) {
  const controller = new AbortController();
  const timer = setTimeout(() => controller.abort(), timeoutMs);
  try {
    return await fetch(url, { ...options, credentials: 'omit', signal: controller.signal });
  } finally {
    clearTimeout(timer);
  }
}

async function apiRequest(baseUrl, path, options = {}) {
  const { method = 'GET', body = null } = options;
  const request = {
    method,
    headers: { Accept: 'application/json' },
  };
  if (body !== null) {
    request.headers['Content-Type'] = 'application/json';
    request.body = JSON.stringify(body);
  }

  const response = await fetchWithTimeout(buildUrl(baseUrl, path), request, REQUEST_TIMEOUT_MS);
  const text = await response.text();
  let data = null;
  try {
    data = text ? JSON.parse(text) : null;
  } catch (error) {
    data = null;
  }

  if (!response.ok) {
    const message = (data && (data.message || data.msg)) || `HTTP ${response.status}`;
    const failure = new Error(message);
    failure.status = response.status;
    throw failure;
  }
  if (!data || data.success === false) {
    const failure = new Error((data && data.message) || '接口返回失败');
    failure.status = response.status;
    throw failure;
  }
  return data;
}

async function instantiateWasm() {
  if (state.wasm) {
    return state.wasm;
  }
  const response = await fetch(chrome.runtime.getURL('wasm/agent_core.wasm'));
  if (!response.ok) {
    throw new Error('WASM 文件加载失败');
  }
  const bytes = await response.arrayBuffer();
  const { instance } = await WebAssembly.instantiate(bytes, {});
  state.wasm = instance.exports;
  return state.wasm;
}

async function startRun(payload) {
  if (state.running) {
    throw new Error('已有运行在进行');
  }

  const base = assertBaseUrl(payload.baseUrl);
  const ticket = String(payload.ticket || '').trim();
  if (!/^[a-f0-9]{16,128}$/i.test(ticket)) {
    throw new Error('票据格式不正确');
  }

  state.running = true;
  state.stopRequested = false;
  state.allowedOrigin = base.origin;
  state.baseUrl = base.href.replace(/\/+$/, '');
  state.ticket = ticket;
  state.run = null;
  state.logs = [];
  state.modelMode = 'ai_module';
  state.modelLabel = '';
  state.cost = 0;
  state.saved = 0;
  state.searches = 0;
  state.iterations = 0;
  state.lastEventId = 0;
  broadcast();

  try {
    const context = await apiRequest(
      state.baseUrl,
      `${API_PREFIX}/context?ticket=${encodeURIComponent(ticket)}`
    );
    state.run = context.run || null;
    state.modelMode = String(context.model_mode || 'ai_module');
    state.modelLabel = String(
      state.modelMode === 'local_computer'
        ? (context.local_model || '本地模型')
        : (state.modelMode === 'browser_local' ? (context.browser_model || '浏览器端侧模型') : (context.run?.model_code || 'AI 模块模型'))
    );
    log(`已连接运行 #${context.run?.id ?? '?'}（${state.modelMode}）`);
    chrome.alarms.create(KEEPALIVE_ALARM, { periodInMinutes: 0.5 });
    state.sseAbort = null;
    startSseLoop(state.baseUrl, ticket).catch(() => {});
    runAgent(context, payload.querySlots).catch((error) => finishRun('failed', error));
  } catch (error) {
    await finishRun('failed', error);
    throw error;
  }
}

function stopRun(reason) {
  if (!state.running) {
    return;
  }
  state.stopRequested = true;
  try {
    state.sseAbort?.abort();
  } catch (error) {
    // 已关闭
  }
  log(`收到停止请求：${reason}`);
}

async function finishRun(status, error = null) {
  if (!state.running) {
    return;
  }
  if (error) {
    log(`运行失败：${error.message || error}`);
  }

  const stats = {
    searches_count: state.searches,
    leads_saved: state.saved,
    leads_found: state.saved,
    iterations: state.iterations,
  };
  await completeRun(status, stats);

  state.running = false;
  state.stopRequested = false;
  state.ticket = '';
  state.allowedOrigin = '';
  try {
    state.sseAbort?.abort();
  } catch (error) {
    // 已关闭
  }
  state.sseAbort = null;
  try {
    chrome.alarms.clear(KEEPALIVE_ALARM);
  } catch (error) {
    // 忽略
  }
  broadcast();
}

async function completeRun(status, stats) {
  if (!state.baseUrl || !state.ticket) {
    return;
  }
  try {
    await apiRequest(state.baseUrl, `${API_PREFIX}/complete`, {
      method: 'POST',
      body: { ticket: state.ticket, status, stats },
    });
  } catch (error) {
    log(`结束上报失败：${error.message}`);
  }
}

function parseSseChunk(chunk) {
  const frame = { event: 'message', data: null, id: null };
  const dataLines = [];
  for (const rawLine of chunk.split('\n')) {
    const line = rawLine.replace(/\r$/, '');
    if (!line || line.startsWith(':')) {
      continue;
    }
    const separator = line.indexOf(':');
    const field = separator === -1 ? line : line.slice(0, separator);
    const value = separator === -1 ? '' : line.slice(separator + 1).replace(/^ /, '');
    if (field === 'event') {
      frame.event = value;
    } else if (field === 'id') {
      frame.id = value;
    } else if (field === 'data') {
      dataLines.push(value);
    }
  }
  if (dataLines.length > 0) {
    const text = dataLines.join('\n');
    try {
      frame.data = JSON.parse(text);
    } catch (error) {
      frame.data = text;
    }
  }
  return frame;
}

async function openSse(url) {
  const controller = new AbortController();
  state.sseAbort = controller;
  const timer = setTimeout(() => controller.abort(), REQUEST_TIMEOUT_MS);
  try {
    return await fetch(url, {
      method: 'GET',
      headers: { Accept: 'text/event-stream' },
      credentials: 'omit',
      signal: controller.signal,
    });
  } finally {
    clearTimeout(timer);
  }
}

async function consumeSse(response, onFrame) {
  if (!response.body) {
    return;
  }
  const reader = response.body.getReader();
  const decoder = new TextDecoder();
  let buffer = '';
  // 服务端单连接最多 25s，这里 30s 兜底断开，避免半开连接拖住 service worker
  const watchdog = setTimeout(() => {
    try {
      state.sseAbort?.abort();
    } catch (error) {
      // 已关闭
    }
  }, 30000);
  try {
    while (true) {
      const { done, value } = await reader.read();
      if (done) {
        break;
      }
      buffer += decoder.decode(value, { stream: true });
      let index;
      while ((index = buffer.indexOf('\n\n')) !== -1) {
        const chunk = buffer.slice(0, index);
        buffer = buffer.slice(index + 2);
        onFrame(parseSseChunk(chunk));
      }
    }
  } finally {
    clearTimeout(watchdog);
    try {
      reader.releaseLock();
    } catch (error) {
      // 忽略
    }
  }
}

async function startSseLoop(baseUrl, ticket) {
  let lastEventId = 0;
  while (state.running) {
    let response = null;
    try {
      const suffix = lastEventId > 0 ? `&last_event_id=${lastEventId}` : '';
      response = await openSse(
        buildUrl(baseUrl, `${API_PREFIX}/stream?ticket=${encodeURIComponent(ticket)}${suffix}`)
      );
    } catch (error) {
      if (state.stopRequested) {
        break;
      }
    }

    if (response) {
      if (!response.ok) {
        log(`事件流连接失败 HTTP ${response.status}`);
      } else {
        try {
          await consumeSse(response, (frame) => {
            if (frame.id) {
              const id = Number(frame.id);
              if (Number.isFinite(id)) {
                lastEventId = Math.max(lastEventId, id);
                state.lastEventId = lastEventId;
              }
            }
            if (frame.event === 'error') {
              const message = String(frame.data?.message || '事件流错误');
              log(message);
              if (message.includes('票据')) {
                stopRun('ticket');
              }
              return;
            }
            if (frame.event !== 'bridge') {
              return;
            }
            const type = frame.data?.type;
            if (type === 'cancel' || type === 'stop') {
              stopRun('server');
              return;
            }
            if (type && type !== 'connected') {
              log(`服务端事件：${type}`);
            }
          });
        } catch (error) {
          if (state.stopRequested) {
            break;
          }
        }
      }
    }

    if (!state.running) {
      break;
    }
    await sleep(800);
  }
}

function mergeSlots(primary, extra) {
  const slots = [];
  for (const source of [primary, extra]) {
    if (!Array.isArray(source)) {
      continue;
    }
    for (const slot of source) {
      const text = String(slot || '').trim();
      if (text && !slots.includes(text)) {
        slots.push(text);
      }
      if (slots.length >= 20) {
        return slots;
      }
    }
  }
  return slots;
}

function buildSystemPrompt(context) {
  const lines = ['你是 Weline AI 获客智能体，运行在用户浏览器内。'];
  if (context.prompt) {
    lines.push(String(context.prompt));
  }
  lines.push(
    '规则：只使用 web_search / inspect_page / save_lead / list_existing_leads 四个工具；',
    '只采公开页面与公开商务联系方式，不绕过登录、付费墙或验证码；',
    '不编造邮箱、联系人、价格；save_lead 必须给出 5 轴 0–2 分（axes）与证据 source_url；',
    '保存前先用 list_existing_leads 检查是否已存在。'
  );
  if (context.website && context.website.url) {
    lines.push(`目标站点：${context.website.name || ''} ${context.website.url}`);
  }
  lines.push(
    `目标线索数：${clampInt(context.run?.target_count, 1, 50, DEFAULT_TARGET_COUNT)}，`,
    `最大轮次：${clampInt(context.run?.max_iterations, 1, 12, DEFAULT_MAX_ITERATIONS)}。`
  );
  return lines.join('\n');
}

function buildKickoff(context, slots) {
  const lines = ['开始获客：先用 web_search 找候选企业，再用 inspect_page 核实公开联系方式。'];
  if (slots.length > 0) {
    lines.push(`可用检索词：${slots.join('；')}`);
  }
  return lines.join('\n');
}

// 后端单次 step 最多接受 60 条消息；这里按 40 条滚动裁剪，
// 保留 system 与最近的对话，并丢弃孤立的 tool 消息。
function trimMessages(messages, limit = 40) {
  if (messages.length <= limit) {
    return;
  }
  const system = messages[0]?.role === 'system' ? messages.shift() : null;
  while (messages.length > limit - (system ? 1 : 0)) {
    messages.shift();
  }
  while (messages.length > 0 && messages[0].role === 'tool') {
    messages.shift();
  }
  if (system) {
    messages.unshift(system);
  }
}

async function runAgent(context, extraSlots) {
  const wasm = await instantiateWasm();
  const slots = mergeSlots(context.query_slots, extraSlots);
  const maxIterations = clampInt(context.run?.max_iterations, 1, 12, DEFAULT_MAX_ITERATIONS);
  const target = clampInt(context.run?.target_count, 1, 50, DEFAULT_TARGET_COUNT);
  const messages = [
    { role: 'system', content: buildSystemPrompt(context) },
    { role: 'user', content: buildKickoff(context, slots) },
  ];
  let usedSlots = 0;

  while (!state.stopRequested) {
    if (wasm.should_continue(state.iterations, state.saved, maxIterations) === 0) {
      break;
    }
    if (state.saved >= target) {
      break;
    }

    const slot = wasm.next_query_slot(usedSlots, slots.length);
    if (slot >= 0) {
      usedSlots = slot + 1;
      messages.push({ role: 'user', content: `本轮优先使用检索词：${slots[slot]}` });
    }

    state.iterations += 1;
    trimMessages(messages);
    const step = await modelStep(context, messages, TOOL_DEFINITIONS, { temperature: 0.3, max_tokens: 1200 });

    state.cost += Number(step.cost) || 0;
    broadcast();

    const assistantMessage = step.assistant_message || {
      role: 'assistant',
      content: step.assistant || '',
    };
    messages.push(assistantMessage);

    const toolCalls = Array.isArray(step.tool_calls) ? step.tool_calls : [];
    if (toolCalls.length === 0) {
      if (step.assistant) {
        log(`模型输出：${String(step.assistant).slice(0, 160)}`);
      }
      break;
    }

    for (const call of toolCalls) {
      if (state.stopRequested) {
        break;
      }
      const name = call?.function?.name || call?.name || 'unknown';
      let result;
      try {
        result = await executeTool(call, wasm);
      } catch (error) {
        result = { error: error.message || '工具执行失败' };
      }
      if (name === 'web_search') {
        state.searches += 1;
      }
      if (name === 'save_lead' && result && result.created) {
        state.saved += 1;
      }
      log(`工具 ${name}：${summarizeToolResult(result)}`);
      messages.push({
        role: 'tool',
        tool_call_id: call?.id || call?.tool_call_id || name,
        content: JSON.stringify(result).slice(0, MAX_TOOL_RESULT_CHARS),
      });
    }
    broadcast();
  }

  await finishRun(state.stopRequested ? 'cancelled' : 'completed');
}


/**
 * 三种模型执行方式：
 * - ai_module：后端 /step 调 AI 模块模型（当前默认）
 * - local_computer：扩展直接调本机 Ollama / OpenAI 兼容服务
 * - browser_local：扩展用 Chrome 内置 Prompt API（Gemini Nano）在浏览器内推理
 *
 * 后两种不把站点内容发给云端模型；只把 assistant/tool_calls/usage 回传后端记账。
 */
async function modelStep(context, messages, tools, params) {
  if (context.model_mode === 'local_computer') {
    return localComputerStep(context, messages, tools, params);
  }
  if (context.model_mode === 'browser_local') {
    return browserLocalStep(context, messages, tools, params);
  }

  return apiRequest(state.baseUrl, `${API_PREFIX}/step`, {
    method: 'POST',
    body: {
      ticket: state.ticket,
      messages,
      tools,
      params,
    },
  });
}

function normalizeLocalEndpoint(raw) {
  let text = String(raw || '').trim();
  if (!/^https?:\/\//i.test(text)) {
    throw new Error('本地模型地址必须是 http(s)');
  }
  text = text.replace(/\/+$/, '');
  if (/\/v1\/chat\/completions$/i.test(text)) {
    return text;
  }
  if (/\/v1$/i.test(text)) {
    return `${text}/chat/completions`;
  }
  return `${text}/v1/chat/completions`;
}

function normalizeToolCalls(rawCalls) {
  const calls = Array.isArray(rawCalls) ? rawCalls : [];
  return calls.slice(0, 10).map((call, index) => {
    const fn = call?.function || call || {};
    const name = String(fn.name || call?.name || '').trim();
    const args = fn.arguments ?? call?.arguments ?? {};
    return {
      id: String(call?.id || call?.tool_call_id || `local_${Date.now()}_${index}`),
      type: 'function',
      function: {
        name,
        arguments: typeof args === 'string' ? args : JSON.stringify(args || {}),
      },
    };
  }).filter((call) => call.function.name !== '');
}

async function reportLocalStep(result) {
  try {
    const reported = await apiRequest(state.baseUrl, `${API_PREFIX}/step`, {
      method: 'POST',
      body: {
        ticket: state.ticket,
        local_result: {
          assistant: result.assistant || '',
          tool_calls: result.tool_calls || [],
          usage: result.usage || {},
        },
      },
    });
    return {
      ...result,
      ...reported,
      cost: 0,
      model_mode: result.model_mode,
    };
  } catch (error) {
    log(`本地步骤回传失败：${error.message}`);
    return { ...result, cost: 0 };
  }
}

async function localComputerStep(context, messages, tools, params) {
  const endpoint = normalizeLocalEndpoint(context.local_endpoint);
  const model = String(context.local_model || '').trim();
  if (!model) {
    throw new Error('运行没有配置本地模型名');
  }
  log(`本地模型：${model}`);
  const response = await fetchWithTimeout(endpoint, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      Accept: 'application/json',
    },
    body: JSON.stringify({
      model,
      messages,
      tools,
      tool_choice: 'auto',
      temperature: Number(params?.temperature) || 0.3,
      max_tokens: Number(params?.max_tokens) || 1200,
      stream: false,
    }),
  }, 120000);
  const text = await response.text();
  let data = null;
  try {
    data = text ? JSON.parse(text) : null;
  } catch (error) {
    data = null;
  }
  if (!response.ok || !data) {
    throw new Error((data && (data.error?.message || data.message)) || `本地模型 HTTP ${response.status}`);
  }
  const message = data.choices?.[0]?.message || {};
  const usage = data.usage || {};
  const result = {
    assistant: String(message.content || ''),
    tool_calls: normalizeToolCalls(message.tool_calls),
    usage: {
      prompt_tokens: Number(usage.prompt_tokens) || 0,
      completion_tokens: Number(usage.completion_tokens) || 0,
    },
    model_mode: 'local_computer',
  };
  return reportLocalStep(result);
}

function browserBuiltinLanguageModel() {
  return globalThis.LanguageModel
    || globalThis.ai?.languageModel
    || globalThis.chrome?.ai?.languageModel
    || null;
}

function browserToolPrompt(tools) {
  const toolsText = JSON.stringify(tools.map((tool) => tool.function ? {
    name: tool.function.name,
    description: tool.function.description,
    parameters: tool.function.parameters,
  } : tool));
  return [
    '你是运行在浏览器内的 Weline AI 获客智能体。',
    '只输出一个 JSON 对象，不要 Markdown 代码块，不要解释。',
    'JSON 结构：{"assistant":"给用户看的简短说明","tool_calls":[{"name":"web_search","arguments":{"query":"..."}}]}。',
    '没有工具可调用时 tool_calls 必须是空数组。可用工具定义：',
    toolsText,
    '规则：公开页面/公开商务邮箱；不编造邮箱；save_lead 必须给 5 轴 axes 与 source_url。',
  ].join('\n');
}

function browserTranscript(messages) {
  const lines = [];
  for (const message of messages.slice(-18)) {
    const role = String(message.role || 'user');
    if (role === 'system') {
      continue;
    }
    let content = typeof message.content === 'string' ? message.content : JSON.stringify(message.content || '');
    content = content.slice(0, 1800);
    lines.push(`${role.toUpperCase()}: ${content}`);
  }
  return lines.join('\n\n').slice(0, 14000);
}

function parseBrowserJson(raw) {
  let text = String(raw || '').trim();
  text = text.replace(/^```(?:json)?/i, '').replace(/```$/, '').trim();
  const start = text.indexOf('{');
  const end = text.lastIndexOf('}');
  if (start >= 0 && end > start) {
    text = text.slice(start, end + 1);
  }
  try {
    return JSON.parse(text);
  } catch (error) {
    return { assistant: String(raw || ''), tool_calls: [] };
  }
}

async function browserLocalStep(context, messages, tools, params) {
  const api = browserBuiltinLanguageModel();
  if (!api) {
    throw new Error('当前浏览器不支持内置 Prompt API；请用 Chrome 138+ 或改选本地电脑模型');
  }
  const availability = typeof api.availability === 'function' ? await api.availability() : 'available';
  if (availability === 'unavailable' || availability === 'no') {
    throw new Error('浏览器内置模型不可用；请在 chrome://flags 启用 Prompt API 并下载模型');
  }
  if (availability === 'downloadable' || availability === 'downloading' || availability === 'after-download') {
    log('浏览器内置模型需要下载，首次运行会较慢');
  }
  const session = await api.create({
    initialPrompts: [{ role: 'system', content: browserToolPrompt(tools) }],
    monitor(monitor) {
      monitor.addEventListener('downloadprogress', (event) => {
        const percent = Math.round((Number(event.loaded) / Math.max(1, Number(event.total))) * 100);
        if (percent > 0) {
          log(`浏览器模型下载 ${percent}%`);
        }
      });
    },
  });
  try {
    const prompt = [
      '继续完成获客任务。只返回 JSON。',
      browserTranscript(messages),
      `本轮参数：${JSON.stringify(params || {})}`,
    ].join('\n\n');
    const raw = await session.prompt(prompt);
    const parsed = parseBrowserJson(raw);
    const result = {
      assistant: String(parsed.assistant || ''),
      tool_calls: normalizeToolCalls(parsed.tool_calls),
      usage: {
        prompt_tokens: Math.ceil(prompt.length / 4),
        completion_tokens: Math.ceil(String(raw || '').length / 4),
      },
      model_mode: 'browser_local',
    };
    return reportLocalStep(result);
  } finally {
    try {
      session.destroy();
    } catch (error) {
      // 忽略销毁失败
    }
  }
}

function parseToolArguments(raw) {
  if (raw && typeof raw === 'object') {
    return raw;
  }
  if (typeof raw !== 'string' || !raw.trim()) {
    return {};
  }
  try {
    const parsed = JSON.parse(raw);
    return parsed && typeof parsed === 'object' ? parsed : {};
  } catch (error) {
    return {};
  }
}

async function executeTool(call, wasm) {
  const name = call?.function?.name || call?.name || '';
  const args = parseToolArguments(call?.function?.arguments ?? call?.arguments);

  switch (name) {
    case 'web_search': {
      const query = String(args.query || '').trim();
      if (!query) {
        return { error: 'query 为空' };
      }
      const result = await searchInBrowser(query);
      if (Array.isArray(result.results)) {
        postEvent('search', { query, count: result.results.length, engine: result.engine });
      }
      return result;
    }
    case 'inspect_page': {
      const url = String(args.url || '').trim();
      if (!url) {
        return { error: 'url 为空' };
      }
      const page = await inspectPage(url);
      postEvent('inspect', { url, title: page.title || '' });
      return page;
    }
    case 'save_lead':
      return saveLeadTool(args, wasm);
    case 'list_existing_leads':
      return listExistingLeads(String(args.keyword || '').trim());
    default:
      return { error: `未知工具 ${name}` };
  }
}

function postEvent(type, payload) {
  if (!state.running || !state.ticket) {
    return;
  }
  apiRequest(state.baseUrl, `${API_PREFIX}/event`, {
    method: 'POST',
    body: { ticket: state.ticket, type, payload },
  }).catch((error) => log(`事件上报失败：${error.message}`));
}

async function saveLeadTool(args, wasm) {
  const axes = Array.isArray(args.axes)
    ? args.axes.slice(0, 5).map((value) => Math.trunc(Number(value) || 0))
    : [];
  let score = Number.isFinite(Number(args.ai_score)) ? Math.trunc(Number(args.ai_score)) : 0;

  if (axes.length === 5) {
    score = wasm.score_lead(axes[0], axes[1], axes[2], axes[3], axes[4]);
    if (score < MIN_LEAD_SCORE) {
      return { saved: false, skipped: true, reason: 'score_below_threshold', ai_score: score };
    }
  }

  const lead = {
    company_name: String(args.company_name || '').trim(),
    company_domain: String(args.company_domain || '').trim(),
    country_code: String(args.country_code || '').trim(),
    contact_name: String(args.contact_name || '').trim(),
    contact_role: String(args.contact_role || '').trim(),
    email: String(args.email || '').trim(),
    source_url: String(args.source_url || '').trim(),
    source_title: String(args.source_title || '').trim(),
    source_platform: String(args.source_platform || 'web').trim(),
    source_excerpt: String(args.source_excerpt || '').slice(0, 500),
    ai_preference: String(args.ai_preference || '').trim(),
    ai_business_direction: String(args.ai_business_direction || '').trim(),
    ai_reason: String(args.ai_reason || '').slice(0, 500),
    ai_score: Math.max(0, Math.min(10, score)),
    status: 'new',
  };

  if (!lead.email && !lead.company_domain && !lead.source_url) {
    return { saved: false, error: '线索缺少邮箱、公司域名与来源 URL，无法去重入库' };
  }

  const result = await apiRequest(state.baseUrl, `${API_PREFIX}/lead`, {
    method: 'POST',
    body: { ticket: state.ticket, lead },
  });

  return {
    saved: true,
    created: Boolean(result.created),
    lead_id: result.lead?.id ?? null,
    ai_score: lead.ai_score,
  };
}

async function listExistingLeads(keyword) {
  const query = new URLSearchParams({
    ticket: state.ticket,
    keyword,
    limit: '10',
  });
  const result = await apiRequest(state.baseUrl, `${API_PREFIX}/leads?${query.toString()}`);
  const items = Array.isArray(result.items) ? result.items : [];
  return {
    total: Number(result.total) || 0,
    items: items.map((item) => ({
      company_name: item.company_name || '',
      company_domain: item.company_domain || '',
      country_code: item.country_code || '',
      email: item.email || '',
      status: item.status || '',
    })),
  };
}

function summarizeToolResult(result) {
  if (!result || typeof result !== 'object') {
    return String(result).slice(0, 80);
  }
  if (result.error) {
    return `错误：${result.error}`;
  }
  if (Array.isArray(result.results)) {
    return `结果 ${result.results.length} 条（${result.engine || 'unknown'}）`;
  }
  if (result.created !== undefined) {
    return result.created ? '已入库' : '已存在，合并';
  }
  if (result.skipped) {
    return '跳过（评分不足）';
  }
  if (Array.isArray(result.items)) {
    return `已有线索 ${result.items.length} 条`;
  }
  if (typeof result.title === 'string') {
    return `页面已抽取：${result.title.slice(0, 60)}`;
  }
  return '完成';
}

async function searchInBrowser(query) {
  const keyword = String(query || '').trim();
  if (!keyword) {
    return { error: 'query 为空' };
  }

  try {
    const response = await fetchWithTimeout(
      `https://www.bing.com/search?format=rss&count=10&q=${encodeURIComponent(keyword)}`,
      { headers: { Accept: 'application/rss+xml, application/xml;q=0.9' } },
      REQUEST_TIMEOUT_MS
    );
    if (response.ok) {
      const items = parseBingRss(await response.text());
      if (items.length > 0) {
        return { engine: 'bing', results: items.slice(0, MAX_SEARCH_RESULTS) };
      }
    }
  } catch (error) {
    // 回退到 DuckDuckGo
  }

  try {
    const response = await fetchWithTimeout(
      'https://html.duckduckgo.com/html/',
      {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `q=${encodeURIComponent(keyword)}`,
      },
      REQUEST_TIMEOUT_MS
    );
    if (response.ok) {
      const items = parseDuckDuckGoHtml(await response.text());
      if (items.length > 0) {
        return { engine: 'duckduckgo', results: items.slice(0, MAX_SEARCH_RESULTS) };
      }
    }
  } catch (error) {
    // 两个引擎都失败
  }

  return { error: '搜索失败（Bing RSS 与 DuckDuckGo 均不可用）' };
}

function parseBingRss(xml) {
  const results = [];
  const itemPattern = /<item>([\s\S]*?)<\/item>/gi;
  let match;
  while ((match = itemPattern.exec(String(xml || ''))) !== null && results.length < MAX_SEARCH_RESULTS) {
    const block = match[1];
    const title = cleanText(decodeHtmlEntities(readTag(block, 'title')));
    const url = cleanText(decodeHtmlEntities(readTag(block, 'link')));
    const snippet = cleanText(decodeHtmlEntities(readTag(block, 'description'))).slice(0, 300);
    if (url) {
      results.push({ title, url, snippet });
    }
  }
  return results;
}

function parseDuckDuckGoHtml(html) {
  const results = [];
  const anchorPattern = /<a\b([^>]*)>([\s\S]*?)<\/a>/gi;
  let match;
  while ((match = anchorPattern.exec(String(html || ''))) !== null && results.length < MAX_SEARCH_RESULTS) {
    if (!/class="[^"]*result__a[^"]*"/i.test(match[1])) {
      continue;
    }
    const href = /href="([^"]+)"/i.exec(match[1]);
    if (!href) {
      continue;
    }
    const url = normalizeDuckDuckGoUrl(decodeHtmlEntities(href[1]));
    const title = cleanText(decodeHtmlEntities(stripTags(match[2])));
    if (url) {
      results.push({ title, url, snippet: '' });
    }
  }

  const snippetPattern = /<a[^>]*class="[^"]*result__snippet[^"]*"[^>]*>([\s\S]*?)<\/a>/gi;
  const snippets = [];
  while ((match = snippetPattern.exec(String(html || ''))) !== null && snippets.length < MAX_SEARCH_RESULTS) {
    snippets.push(cleanText(decodeHtmlEntities(stripTags(match[1]))).slice(0, 300));
  }
  for (let index = 0; index < results.length && index < snippets.length; index += 1) {
    results[index].snippet = snippets[index];
  }

  return results;
}

function normalizeDuckDuckGoUrl(raw) {
  let url = String(raw || '').trim();
  if (!url) {
    return '';
  }
  if (url.startsWith('//')) {
    url = `https:${url}`;
  }
  try {
    const parsed = new URL(url);
    if (parsed.hostname.endsWith('duckduckgo.com') && parsed.searchParams.get('uddg')) {
      return parsed.searchParams.get('uddg') || '';
    }
    if (parsed.protocol === 'http:' || parsed.protocol === 'https:') {
      return parsed.toString();
    }
  } catch (error) {
    return '';
  }
  return '';
}

function normalizeHttpUrl(raw) {
  const text = String(raw || '').trim();
  if (!text) {
    return null;
  }
  const candidate = /^https?:\/\//i.test(text) ? text : `https://${text}`;
  try {
    const url = new URL(candidate);
    if (url.protocol !== 'http:' && url.protocol !== 'https:') {
      return null;
    }
    const host = url.hostname.toLowerCase();
    if (
      host === 'localhost'
      || host.endsWith('.local')
      || host.endsWith('.internal')
      || /^127\./.test(host)
      || /^10\./.test(host)
      || /^192\.168\./.test(host)
      || /^172\.(1[6-9]|2\d|3[01])\./.test(host)
      || /^169\.254\./.test(host)
      || host === '[::1]'
      || host === '::1'
    ) {
      return null;
    }
    return url;
  } catch (error) {
    return null;
  }
}

async function inspectPage(rawUrl) {
  const target = normalizeHttpUrl(rawUrl);
  if (!target) {
    return { error: 'URL 不合法' };
  }

  try {
    const [tab] = await chrome.tabs.query({ active: true, currentWindow: true });
    if (tab?.id && tab.url && normalizeHttpUrl(tab.url)?.href === target.href) {
      await chrome.scripting.executeScript({ target: { tabId: tab.id }, files: ['content.js'] });
      const frames = await chrome.scripting.executeScript({
        target: { tabId: tab.id },
        func: () =>
          typeof globalThis.__welineExtractPage === 'function'
            ? globalThis.__welineExtractPage()
            : null,
      });
      const page = frames?.[0]?.result;
      if (page && typeof page === 'object') {
        return page;
      }
    }
  } catch (error) {
    // 权限或注入失败时回退到后台抓取
  }

  try {
    const response = await fetchWithTimeout(
      target.href,
      { headers: { Accept: 'text/html,application/xhtml+xml' } },
      REQUEST_TIMEOUT_MS
    );
    if (!response.ok) {
      return { error: `HTTP ${response.status}` };
    }
    const html = (await response.text()).slice(0, 400000);
    return extractPageFromHtml(html, target.href);
  } catch (error) {
    return { error: '页面抓取失败' };
  }
}

function extractPageFromHtml(html, url) {
  const source = String(html || '');
  const title = cleanText(decodeHtmlEntities(readTag(source, 'title')));
  const description =
    readMeta(source, 'description') || readMeta(source, 'og:description');
  const siteName = readMeta(source, 'og:site_name');
  const visible = source
    .replace(/<script[\s\S]*?<\/script>/gi, ' ')
    .replace(/<style[\s\S]*?<\/style>/gi, ' ')
    .replace(/<noscript[\s\S]*?<\/noscript>/gi, ' ');
  const text = cleanText(decodeHtmlEntities(stripTags(visible))).slice(0, 4000);

  return {
    url,
    title,
    description: cleanText(decodeHtmlEntities(description)),
    site_name: cleanText(decodeHtmlEntities(siteName)),
    text,
    emails: extractEmails(`${source}\n${text}`).slice(0, 20),
  };
}

function extractEmails(text) {
  const found = String(text || '').match(/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/gi) || [];
  const emails = [];
  for (const email of found) {
    const lower = email.toLowerCase();
    if (/\.(png|jpe?g|gif|webp|svg|css|js|ico)$/i.test(lower)) {
      continue;
    }
    if (!emails.includes(lower)) {
      emails.push(lower);
    }
    if (emails.length >= 20) {
      break;
    }
  }
  return emails;
}

function readTag(block, name) {
  const pattern = new RegExp(`<${name}[^>]*>([\\s\\S]*?)</${name}>`, 'i');
  const match = pattern.exec(String(block || ''));
  if (!match) {
    return '';
  }
  return match[1].replace(/^<!\[CDATA\[/, '').replace(/\]\]>$/, '');
}

function readMeta(html, name) {
  const pattern = new RegExp(
    `<meta[^>]+(?:name|property)=["']${name.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}["'][^>]*>`,
    'i'
  );
  const tag = pattern.exec(String(html || ''));
  if (!tag) {
    return '';
  }
  const content = /content=["']([\s\S]*?)["']/i.exec(tag[0]);
  return content ? content[1] : '';
}

function stripTags(html) {
  return String(html || '').replace(/<[^>]+>/g, ' ');
}

function cleanText(text) {
  return String(text || '').replace(/\s+/g, ' ').trim();
}

function decodeHtmlEntities(text) {
  return String(text || '')
    .replace(/&nbsp;/gi, ' ')
    .replace(/&amp;/gi, '&')
    .replace(/&lt;/gi, '<')
    .replace(/&gt;/gi, '>')
    .replace(/&quot;/gi, '"')
    .replace(/&#39;|&apos;/gi, "'")
    .replace(/&#x([0-9a-f]+);/gi, (_, hex) => String.fromCodePoint(Number.parseInt(hex, 16)))
    .replace(/&#(\d+);/g, (_, code) => String.fromCodePoint(Number.parseInt(code, 10)));
}

chrome.runtime.onMessage.addListener((message, _sender, sendResponse) => {
  if (!message || typeof message.type !== 'string') {
    return false;
  }

  if (message.type === 'WELINE_START') {
    startRun(message.payload || {})
      .then(() => sendResponse({ ok: true }))
      .catch((error) => sendResponse({ ok: false, message: error.message || '启动失败' }));
    return true;
  }

  if (message.type === 'WELINE_STOP') {
    stopRun('user');
    sendResponse({ ok: true });
    return false;
  }

  if (message.type === 'WELINE_STATE') {
    sendResponse({ ok: true, state: publicState() });
    return false;
  }

  return false;
});

chrome.alarms.onAlarm.addListener((alarm) => {
  if (alarm.name === KEEPALIVE_ALARM && state.running) {
    broadcast();
  }
});
