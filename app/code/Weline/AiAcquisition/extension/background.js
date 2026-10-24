'use strict';

// 页面去重（与后端 ScanRegistry 同口径）：已扫描页面不再重复抓取。
importScripts('scan-dedupe.js');

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
  phase: 'idle',
  paused: false,
  progress: '',
  remoteStatus: '',
  lastError: '',
  scan: null,
  usedQueries: null,
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
    phase: state.phase,
    paused: state.paused,
    progress: state.progress,
    remoteStatus: state.remoteStatus,
    lastError: state.lastError,
    ticketMasked: state.ticket ? `${state.ticket.slice(0, 6)}…${state.ticket.slice(-4)}` : '',
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

  // 5xx / 网络抖动自动重试：本地结果已产生的场景不能让整个运行失败。
  let lastFailure = null;
  for (let attempt = 1; attempt <= 3; attempt += 1) {
    let response;
    try {
      response = await fetchWithTimeout(buildUrl(baseUrl, path), request, REQUEST_TIMEOUT_MS);
    } catch (error) {
      lastFailure = error;
      if (attempt < 3) { await sleep(2000); }
      continue;
    }
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
      // 4xx（除 408/429）是请求本身的问题，重试无意义
      if (response.status >= 500 || response.status === 408 || response.status === 429) {
        lastFailure = failure;
        if (attempt < 3) { await sleep(2000); }
        continue;
      }
      throw failure;
    }
    if (!data || data.success === false) {
      const failure = new Error((data && data.message) || '接口返回失败');
      failure.status = response.status;
      throw failure;
    }
    return data;
  }
  throw lastFailure || new Error('接口请求失败');
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
  state.paused = false;
  state.phase = 'init';
  state.progress = '';
  state.remoteStatus = '';
  state.lastError = '';
  broadcast();

  try {
    const context = await apiRequest(
      state.baseUrl,
      `${API_PREFIX}/context?ticket=${encodeURIComponent(ticket)}`
    );
    state.run = context.run || null;
    state.scan = self.WelineScanDedupe.makeTracker(context.scan);
    state.usedQueries = new Set();
    state.modelMode = String(context.model_mode || 'ai_module');
    state.modelLabel = String(
      state.modelMode === 'local_computer'
        ? (context.local_model || '本地模型')
        : (state.modelMode === 'browser_local' ? (context.browser_model || '浏览器端侧模型') : (context.run?.model_code || 'AI 模块模型'))
    );
    log(`已识别票据，运行 #${context.run?.id ?? '?'}（${state.modelMode}）`);
    broadcast();

    await prepareModel(context);
    try {
      await markReady();
    } catch (error) {
      // 模型已初始化：ready 上报失败（重试 3 次后仍 5xx）不终止运行，
      // 后续每轮 /bridge/status 会把后端状态对齐为 running。
      log(`运行就绪上报失败（不影响本次运行）：${error.message || error}`);
    }
    state.phase = 'running';
    state.progress = '';
    log('模型已就绪，开始运行');
    broadcast();

    chrome.alarms.create(KEEPALIVE_ALARM, { periodInMinutes: 0.5 });
    state.sseAbort = null;
    startSseLoop(state.baseUrl, ticket).catch(() => {});
    const runner = state.modelMode === 'browser_local' ? runBrowserAgent : runAgent;
    runner(context, payload.querySlots).catch((error) => finishRun('failed', error));
  } catch (error) {
    await failBeforeRun(error);
    throw error;
  }
}

/**
 * 模型初始化阶段失败：保留 pending 运行与票据，允许修好模型后原地重试；
 * 此时不调用 /complete，避免把还没接入的运行提前判死。
 */
async function failBeforeRun(error) {
  state.lastError = String(error?.message || error);
  log(`运行失败：${state.lastError}`);
  state.running = false;
  state.stopRequested = false;
  state.paused = false;
  state.phase = 'failed';
  try {
    state.sseAbort?.abort();
  } catch (e) {
    // 已关闭
  }
  state.sseAbort = null;
  try {
    chrome.alarms.clear(KEEPALIVE_ALARM);
  } catch (e) {
    // 忽略
  }
  broadcast();
}

/**
 * 运行前初始化所选模型：
 * - ai_module：云端模型，无需本地初始化
 * - local_computer：探活本机 OpenAI 兼容服务
 * - browser_local：WebLLM 预加载权重 / Chrome 内置 Prompt API 可用性检查
 */
async function prepareModel(context) {
  const mode = String(context.model_mode || 'ai_module');
  if (mode === 'ai_module') {
    log('AI 模块模型：由服务端调用，无需本地初始化');
    return;
  }
  if (mode === 'local_computer') {
    await probeLocalModel(String(context.local_endpoint || ''), String(context.local_model || ''));
    log(`本地模型已就绪：${context.local_model || ''}`);
    return;
  }

  const model = String(context.browser_model || 'chrome-builtin');
  if (model === 'chrome-builtin') {
    const api = browserBuiltinLanguageModel();
    if (!api) {
      throw new Error('当前浏览器不支持内置 Prompt API；请使用 Chrome 138+ 或改选 WebLLM 小模型');
    }
    const availability = typeof api.availability === 'function' ? await api.availability() : 'available';
    if (availability === 'unavailable' || availability === 'no') {
      throw new Error('浏览器内置模型不可用；请在 chrome://flags 启用 Prompt API 并下载模型');
    }
    log(availability === 'available' ? '浏览器内置模型已就绪' : `浏览器内置模型需要下载（${availability}）`);
    return;
  }

  await ensureOffscreenDocument();
  let lastError = null;
  for (let attempt = 1; attempt <= 3; attempt += 1) {
    try {
      await new Promise((resolve, reject) => {
        chrome.runtime.sendMessage({
          target: 'weline-offscreen',
          type: 'WEBLLM_PREPARE',
          model,
        }, (reply) => {
          if (chrome.runtime.lastError) {
            reject(new Error(chrome.runtime.lastError.message));
            return;
          }
          if (!reply || !reply.ok) {
            reject(new Error(reply?.message || '浏览器模型初始化失败'));
            return;
          }
          resolve(reply);
        });
      });
      lastError = null;
      break;
    } catch (error) {
      lastError = error;
      log(`浏览器模型初始化第 ${attempt} 次失败：${error.message || error}`);
      if (attempt < 3) {
        await sleep(4000);
      }
    }
  }
  if (lastError) {
    throw new Error(`${lastError.message || lastError}（已自动重试 3 次；已下载的分片会复用）`);
  }
  log(`浏览器模型已就绪：${model}`);
}

async function probeLocalModel(endpoint, model) {
  const chatUrl = normalizeLocalEndpoint(endpoint);
  const base = chatUrl.replace(/\/chat\/completions$/i, '');
  try {
    const response = await fetchWithTimeout(`${base}/models`, {
      method: 'GET',
      headers: { Accept: 'application/json' },
    }, 8000);
    if (response.ok) {
      return;
    }
  } catch (error) {
    // 部分服务不提供 /models，退化为最小推理请求
  }
  let response;
  try {
    response = await fetchWithTimeout(chatUrl, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      body: JSON.stringify({
        model,
        messages: [{ role: 'user', content: 'ping' }],
        max_tokens: 1,
        stream: false,
      }),
    }, 30000);
  } catch (error) {
    throw new Error(`本地模型服务不可达：${base}（请确认 Ollama / LM Studio 已启动，并允许扩展来源访问）`);
  }
  if (!response.ok) {
    throw new Error(`本地模型初始化失败：HTTP ${response.status}`);
  }
}

async function markReady() {
  await apiRequest(state.baseUrl, `${API_PREFIX}/ready`, {
    method: 'POST',
    body: {
      ticket: state.ticket,
      model_mode: state.modelMode,
      model_label: state.modelLabel,
    },
  });
}

async function fetchRemoteStatus() {
  const reply = await apiRequest(
    state.baseUrl,
    `${API_PREFIX}/status?ticket=${encodeURIComponent(state.ticket)}`
  );
  return reply?.run || null;
}

async function controlRun(action) {
  if (!state.baseUrl || !state.ticket) {
    return null;
  }
  const reply = await apiRequest(state.baseUrl, `${API_PREFIX}/control`, {
    method: 'POST',
    body: { ticket: state.ticket, action },
  });
  return reply?.run || null;
}

/**
 * 每轮前感知后台状态：暂停则等待继续，终态则停止本地循环。
 */
async function checkControl() {
  if (!state.baseUrl || !state.ticket) {
    return;
  }
  let remote = null;
  try {
    remote = await fetchRemoteStatus();
  } catch (error) {
    return;
  }
  if (!remote) {
    return;
  }
  state.remoteStatus = String(remote.status || '');
  if (state.remoteStatus === 'paused') {
    await waitWhilePaused();
    return;
  }
  if (['cancelled', 'completed', 'failed'].includes(state.remoteStatus)) {
    log(`后台运行已结束：${remote.status}`);
    stopRun('remote:' + remote.status);
  }
}

async function waitWhilePaused() {
  if (state.paused) {
    return;
  }
  state.paused = true;
  log('运行已暂停，等待继续');
  broadcast();
  while (state.running && state.paused) {
    await sleep(2000);
    let remote = null;
    try {
      remote = await fetchRemoteStatus();
    } catch (error) {
      continue;
    }
    const status = String(remote?.status || '');
    state.remoteStatus = status;
    if (status === 'running') {
      state.paused = false;
      log('已继续运行');
      broadcast();
      return;
    }
    if (['cancelled', 'completed', 'failed'].includes(status)) {
      stopRun('remote:' + status);
      return;
    }
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
    state.lastError = String(error.message || error);
    log(`运行失败：${state.lastError}`);
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
  state.paused = false;
  state.phase = status === 'cancelled' ? 'stopped' : (status === 'failed' ? 'failed' : 'done');
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
    '保存前先用 list_existing_leads 检查是否已存在；',
    '工具会自动跳过该站点已扫描过的页面（返回 skipped 或 reason），遇到时换关键词或换候选页，禁止重复抓同一页。'
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

/**
 * 浏览器端侧小模型（1B 级）走脚本化取证流水线：
 * 检索 → 抓页面 → 模型判定并提取公开联系方式 → save_lead。
 * 比自由工具循环更稳：模型只做「选/提取」，不背工具协议。
 */
async function runBrowserAgent(context, extraSlots) {
  const wasm = await instantiateWasm();
  const target = clampInt(context.run?.target_count, 1, 50, DEFAULT_TARGET_COUNT);
  const maxPages = clampInt(context.run?.max_iterations, 1, 20, 8);
  let queries = mergeSlots(context.query_slots, extraSlots);
  const category = guessCategory(context);
  if (queries.length === 0) {
    // 端侧小模型生成的检索词不稳定，这里直接用「品类+海外买家」固定模板，
    // 保证检索结果落在真实买家的页面上（shop/contact/distributor 而不是百科/词典）。
    const base = category;
    if (base === 'hanfu') {
      // 实测 Bing RSS 对 hanfu 词典查询固定返回百科/旅游页；cosplay/中式服装零售商才是
      // 真正会进货的海外买家，用「品类+渠道词」组合拿店主联系页。
      queries = [
        'cosplay shop hanfu contact email',
        `${base} cosplay retailer wholesale inquiry`,
        'miccostumes ezcosplay procosplay contact email',
      ];
    } else if (base === 'influencer') {
      queries = [
        'hanfu influencer instagram collaboration email',
        `${base} tiktok creator brand ambassador contact`,
        'hanfu youtuber review collaboration business email',
      ];
    } else {
      queries = [
        `${base} shop USA contact email`,
        `${base} store Europe wholesale enquiry`,
        `${base} boutique distributor wanted`,
      ];
    }
  }
  if (queries.length === 0) {
    queries = [`${category} wholesale distributor`, `${category} shop owner supplier`, `looking for ${category} supplier`];
  }
  log(`端侧流水线检索词：${queries.slice(0, 5).join(' | ')}`);

  // 必须含品类词，防止模型给出「wholesale distributor needed」这类与站点无关的通用词。
  const CATEGORY_RE = /hanfu|chinese|qipao|cheongsam|cosplay|oriental|wushu|kungfu|traditional/i;
  const profileText = String(context.prompt || '') + ' ' + String(context.website?.name || '');
  const profileHasCategory = /hanfu|汉服|qipao|旗袍|chinese/i.test(profileText);
  const influencerMode = category === 'influencer';
  queries = queries.filter((q) => influencerMode || CATEGORY_RE.test(q) || !profileHasCategory);
  if (queries.length === 0) {
    queries = [`${category} shop USA contact`, `${category} store Europe email`, `${category} rental studio contact`];
  }
  let inspected = 0;
  outer:
  for (const query of queries) {
    if (state.stopRequested) break;
    const queryKey = String(query || '').trim().toLowerCase();
    if (state.usedQueries && state.usedQueries.has(queryKey)) {
      log(`跳过重复检索词：${query}`);
      continue;
    }
    if (state.usedQueries) state.usedQueries.add(queryKey);
    await checkControl();
    if (state.stopRequested) break;

    let search = null;
    for (let attempt = 1; attempt <= 3 && !search; attempt += 1) {
      try {
        const reply = await searchInBrowser(query);
        if (reply && Array.isArray(reply.results)) {
          search = reply;
        } else {
          log(`检索失败「${query}」第 ${attempt} 次：${reply?.error || '无结果'}`);
          await sleep(2500);
        }
      } catch (error) {
        log(`检索失败「${query}」第 ${attempt} 次：${error.message || error}`);
        await sleep(2500);
      }
    }
    const rawResults = Array.isArray(search?.results) ? search.results : [];
    state.searches += 1;
    const filtered = filterToolResults(rawResults);
    const results = filtered.results;
    log(`检索「${query}」：${rawResults.length} 条，可新抓 ${results.length} 条${filtered.skipped ? `（跳过 ${filtered.skipped} 条已看过）` : ''}（${search?.engine || 'none'}）`);
    if (results.length === 0) continue;

    for (const item of results.slice(0, 6)) {
      if (state.saved >= target || inspected >= maxPages || state.stopRequested) break outer;
      await checkControl();
      if (state.stopRequested) break outer;
      inspected += 1;

      let page = null;
      let emails = [];
      // 首页无公开邮箱时，依次尝试常见联系/关于子页（零售站点邮箱多在联系页）
      const candidates = [item.url].concat(contactSubpaths(item.url));
      for (const candidate of candidates) {
        try {
          page = await inspectPage(candidate);
        } catch (error) {
          continue;
        }
        if (!page || page.error) {
          continue;
        }
        if (state.scan) {
          state.scan.mark(candidate);
        }
        emails = Array.isArray(page.emails) ? page.emails.filter(isBusinessEmail) : [];
        if (emails.length > 0) {
          break;
        }
      }
      if (!page || page.error) {
        log(`抓取失败：${item.url}`);
        continue;
      }
      if (emails.length === 0) {
        log(`跳过（无公开邮箱）：${item.url}`);
        continue;
      }
      log(`核实：${item.title || item.url} · ${emails.length} 个公开邮箱`);

      let lead;
      try {
        lead = await extractLead(context, page, item, emails);
      } catch (error) {
        log(`模型提取失败：${error.message || error}`);
        continue;
      }
      if (!lead) {
        log('模型判定：不是合格买家或分数不足，跳过');
        continue;
      }
      let result;
      try {
        result = await saveLeadTool(lead, wasm);
      } catch (error) {
        // 入库回传失败（站点维护/网络）不终止运行：记录后继续找下一条。
        log(`入库回传失败（不影响本次运行）：${error.message || error}`);
        broadcast();
        continue;
      }
      if (result?.created) {
        state.saved += 1;
        log(`入库：${lead.company_name}（${lead.email}）score=${result.ai_score}`);
      } else if (result?.saved) {
        log(`已存在：${lead.company_name}`);
      } else {
        log(`未入库：${result?.message || result?.reason || '未知原因'}`);
      }
      broadcast();
      if (state.saved >= target) break outer;
    }
    reportScanned();
  }

  reportScanned();
  await finishRun(state.stopRequested ? 'cancelled' : 'completed');
}

function isBusinessEmail(email) {
  const value = String(email || '').trim().toLowerCase();
  if (!/^[a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,}$/i.test(value)) {
    return false;
  }
  return !/\.(png|jpe?g|gif|webp|svg|css|js|ico)$/i.test(value);
}

async function browserChat(context, messages, params = {}) {
  await ensureOffscreenDocument();
  let response = null;
  let lastError = null;
  for (let attempt = 1; attempt <= 2; attempt += 1) {
    try {
      response = await new Promise((resolve, reject) => {
        const timer = setTimeout(() => reject(new Error('浏览器模型推理超时（5 分钟）')), 300000);
        chrome.runtime.sendMessage({
          target: 'weline-offscreen',
          type: 'WEBLLM_CHAT',
          model: String(context.browser_model || ''),
          messages,
          tools: [],
          params,
        }, (reply) => {
          clearTimeout(timer);
          if (chrome.runtime.lastError) {
            reject(new Error(chrome.runtime.lastError.message));
            return;
          }
          resolve(reply);
        });
      });
      if (response && response.ok) {
        break;
      }
      lastError = new Error((response && response.message) || '浏览器模型运行失败');
    } catch (error) {
      lastError = error;
    }
    if (attempt < 2) {
      log(`浏览器模型推理第 ${attempt} 次失败（${lastError?.message || lastError}），重试`);
      await sleep(2000);
    }
  }
  if (!response || !response.ok) {
    throw lastError || new Error('浏览器模型运行失败');
  }
  const content = String(response.message?.content || '');
  const usage = response.usage || {};
  await reportLocalStep({
    assistant: '浏览器端侧模型推理',
    tool_calls: [],
    usage: {
      prompt_tokens: Number(usage.prompt_tokens) || Math.ceil(messages.map((m) => String(m.content || '')).join('').length / 4),
      completion_tokens: Number(usage.completion_tokens) || Math.ceil(content.length / 4),
    },
    model_mode: 'browser_local',
  }).catch(() => null);
  return content;
}

async function browserQueries(context) {
  const prompt = [
    '只输出 JSON：{"queries":["...","...","..."]}。',
    '该站点是汉服/中式服装出海电商，要找「海外进货买家」：销售中式服装/汉服/旗袍/cosplay 的海外零售店、汉服写真馆、国风活动公司、进口批发商。',
    '生成 3 条英文检索词，直接沿用示例格式（不要加引号、不要组合多个短语）：',
    '示例1：hanfu shop USA contact',
    '示例2：Chinese clothing store Europe',
    '示例3：hanfu rental studio email',
    '禁止生成供应商侧词（如 hanfu manufacturer、hanfu factory），也禁止百科/旅游词/通用批发词。',
    '目标站点：' + String(context.website?.name || '') + ' ' + String(context.website?.url || ''),
    '站点画像节选：' + String(context.prompt || '').slice(0, 600),
  ].join('\n');
  const content = await browserChat(context, [
    { role: 'system', content: '只输出 JSON。' },
    { role: 'user', content: prompt },
  ], { temperature: 0.2, max_tokens: 200 });
  const parsed = parseBrowserJson(content);
  const queries = Array.isArray(parsed.queries) ? parsed.queries : [];
  return queries.map((q) => String(q || '').trim()).filter((q) => q.length > 3).slice(0, 5);
}

function guessCategory(context) {
  const text = String(context.prompt || '') + ' ' + String(context.website?.name || '');
  // 明确要求找网红时走 influencer 检索模板
  if (/(网红|达人|博主|kol|influencer|creator)/i.test(text)) {
    return 'influencer';
  }
  const map = { '汉服': 'hanfu', '旗袍': 'qipao', '马面裙': 'mamianqun skirt', 'cosplay': 'cosplay costume', '茶': 'tea' };
  for (const [key, value] of Object.entries(map)) {
    if (text.includes(key)) {
      return value;
    }
  }
  const host = String(context.website?.url || '').replace(/^https?:\/\//, '').split('/')[0];
  return host.split('.')[0] || 'product';
}

async function extractLead(context, page, item, emails) {
  const prompt = [
    '只输出 JSON，不要解释。',
    '该站点是「汉服/中式服装出海电商」，正在找海外进货买家。',
    '买家定义（满足其一即算）：海外销售中式服装/汉服/旗袍/汉元素服饰的零售店、网店店主、批发分销商、进口商、汉服租赁/写真馆、国风活动主办；',
    '不是买家的例子：汉服百科/旅游介绍、中国境内的汉服工厂/品牌方（它们是同行不是买家）、纯内容博客。',
    '目标站点：' + String(context.website?.name || '') + ' ' + String(context.website?.url || ''),
    '站点画像节选：' + String(context.prompt || '').slice(0, 600),
    '页面 URL：' + page.url,
    '页面标题：' + String(page.title || ''),
    '页面公开邮箱：' + emails.slice(0, 3).join(', '),
    '页面正文节选：' + String(page.text || '').slice(0, 1000),
    '输出结构：{"company_name":"","email":"","country_code":"","source_excerpt":"页面里能证明它是买家/有联系方式的原文 1 句","axes":[0,0,0,0,0],"reason":"为什么是买家","lead_type":"...","channel":"...","influence":"..."}',
    'lead_type 从这些里选一个：wholesaler(批发商)/retailer(零售商)/shop_owner(网店主)/influencer(网红KOL)/event_organizer(活动主办)/importer(进口商)/photostudio(写真馆)/rental(租赁店)/other(其他)。网红/博主/自媒体/达人账号也是买家（他们会带货或合作，类型填 influencer）。',
    'channel 从这些里选一个：independent_site(独立站)/marketplace(电商平台如amazon/etsy)/social_media(社媒主页)/blog(博客)/directory(黄页名录)/forum(论坛社区)/search(搜索页)。',
    'influence 从这些里选一个：mega(粉丝10万+)/mid_tier(1万-10万)/nano(<1万)/unknown(看不出)。社媒/视频主页有粉丝量时按量判断。',
    'axes 五轴各 0-2：[品类匹配, 采购意图, B2B属性, 数量或时间, 渠道可用]。按买家定义打分：海外汉服/中式服装零售或租赁店通常 品类2 采购1-2 B2B2 数量0-1 渠道2（总分≥7）；百科/游记/博客输出 {}。',
    'email 只能从上面的公开邮箱里选，不能编造。company_name 用页面标题里的店名/公司名。字数少一点。',
  ].join('\n');
  const content = await browserChat(context, [
    { role: 'system', content: '只输出 JSON。' },
    { role: 'user', content: prompt },
  ], { temperature: 0.1, max_tokens: 400 });

  const parsed = parseBrowserJson(content);
  const email = String(parsed.email || '').trim().toLowerCase();
  const axes = Array.isArray(parsed.axes) ? parsed.axes.slice(0, 5).map((v) => Math.trunc(Number(v) || 0)) : [];
  const company = String(parsed.company_name || '').trim();
  const score = axes.length === 5 ? axes.reduce((a, b) => a + b, 0) : 0;
  if (!company || !isBusinessEmail(email) || score < MIN_LEAD_SCORE) {
    return null;
  }
  const tags = buildLeadTags(parsed, page, item);
  return {
    company_name: company.slice(0, 120),
    email,
    country_code: String(parsed.country_code || '').trim().toUpperCase().slice(0, 2),
    source_url: String(page.url || item.url || ''),
    source_title: String(page.title || item.title || '').slice(0, 200),
    source_excerpt: String(parsed.source_excerpt || '').slice(0, 500),
    ai_reason: String(parsed.reason || '').slice(0, 500),
    axes,
    tags,
  };
}

/**
 * 组装分类标签：lead_type/channel/influence（模型输出）+ 渠道兜底（按来源 URL 推断）。
 * 输出格式 `group:code`，后端 TagCatalog 做白名单归一。
 */
function buildLeadTags(parsed, page, item) {
  const tags = [];
  const leadType = String(parsed.lead_type || '').trim().toLowerCase();
  if (leadType) {
    tags.push('buyer_type:' + leadType);
  }
  const channel = String(parsed.channel || '').trim().toLowerCase()
    || guessChannelFromUrl(String(page.url || item.url || ''));
  if (channel) {
    tags.push('channel:' + channel);
  }
  const influence = String(parsed.influence || '').trim().toLowerCase();
  if (influence) {
    tags.push('influence:' + influence);
  }
  return tags;
}

/**
 * 常见联系/关于子页路径：首页无邮箱时逐个尝试（最多 3 个）。
 */
function contactSubpaths(url) {
  let base;
  try {
    const u = new URL(url);
    base = u.origin + (u.pathname === '/' ? '' : u.pathname.replace(/\/[^/]*$/, ''));
  } catch (error) {
    return [];
  }
  const paths = ['/contact', '/contact-us', '/about', '/pages/contact', '/pages/about-us'];
  return paths.slice(0, 3).map((p) => base + p);
}

function guessChannelFromUrl(url) {
  const host = String(url || '').toLowerCase();
  if (/amazon\.|ebay\.|etsy\.|aliexpress\.|temu\.|shopee\./.test(host)) {
    return 'marketplace';
  }
  if (/instagram\.com|tiktok\.com|youtube\.com|facebook\.com|pinterest\.|x\.com|twitter\.|douyin\./.test(host)) {
    return 'social_media';
  }
  if (/reddit\.com|forum|bbs|quora\.|zhihu\./.test(host)) {
    return 'forum';
  }
  if (/blog|medium\.com|wordpress|news\.|blogspot/.test(host)) {
    return 'blog';
  }
  if (/yelp\.|yellowpages|directory/.test(host)) {
    return 'directory';
  }
  if (/google\.|bing\./.test(host)) {
    return 'search';
  }
  return 'independent_site';
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

    await checkControl();
    if (state.stopRequested) {
      break;
    }

    state.iterations += 1;
    trimMessages(messages);
    let step;
    try {
      step = await modelStep(context, messages, TOOL_DEFINITIONS, { temperature: 0.3, max_tokens: 1200 });
    } catch (error) {
      if (error && error.status === 409 && /暂停/.test(String(error.message))) {
        state.iterations -= 1;
        await waitWhilePaused();
        continue;
      }
      throw error;
    }

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
      // 小模型偶尔不按协议调用工具：未到轮次上限时明确要求它继续取证，而不是直接收尾。
      if (state.iterations < maxIterations && state.saved < target) {
        messages.push({
          role: 'user',
          content: '请继续：只返回 JSON，并至少调用一个工具。优先 web_search（英文买家意向词 + 品类词）；拿到结果后再决定 inspect_page 或 save_lead。',
        });
        continue;
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

  const settled = await settleBeforeFinish();
  await finishRun(
    state.stopRequested || settled === 'cancelled' ? 'cancelled' : (settled === 'failed' ? 'failed' : 'completed')
  );
}

/**
 * 结束前对齐后台状态：暂停就等，已被后台取消就按取消收尾，避免把 paused 覆盖成 completed。
 */
async function settleBeforeFinish() {
  for (let attempt = 0; attempt < 5; attempt += 1) {
    if (!state.baseUrl || !state.ticket) {
      return 'running';
    }
    let remote = null;
    try {
      remote = await fetchRemoteStatus();
    } catch (error) {
      return 'running';
    }
    const status = String(remote?.status || '');
    state.remoteStatus = status;
    if (status === 'paused') {
      await waitWhilePaused();
      continue;
    }
    if (status === 'cancelled' || status === 'completed' || status === 'failed') {
      return status;
    }
    return 'running';
  }
  return 'running';
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
    if (error && error.status === 409 && /暂停/.test(String(error.message))) {
      await waitWhilePaused();
      return reportLocalStep(result);
    }
    // 本地步已跑完：后端记账失败（重试 3 次后仍 5xx/网络错）只记录，不中断整次运行。
    log(`本地步骤回传失败（已重试，不影响运行）：${error.message || error}`);
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


async function ensureOffscreenDocument() {
  if (!chrome.offscreen) {
    throw new Error('当前扩展没有 offscreen 权限，无法运行 WebLLM 浏览器模型');
  }
  if (typeof chrome.offscreen.hasDocument === 'function' && await chrome.offscreen.hasDocument()) {
    return;
  }
  try {
    await chrome.offscreen.createDocument({
      url: 'offscreen.html',
      reasons: ['DOM_PARSER'],
      justification: 'Run browser-side WebLLM for AI acquisition',
    });
  } catch (error) {
    if (!String(error?.message || '').includes('Only a single offscreen')) {
      throw error;
    }
  }
}

async function webllmStep(context, messages, tools, params) {
  const model = String(context.browser_model || '').trim();
  if (!model) {
    throw new Error('运行没有配置浏览器模型');
  }
  log(`浏览器模型：${model}`);
  await ensureOffscreenDocument();

  const toolList = tools.map((tool) => tool.function ? tool.function : tool)
    .map((tool) => `- ${tool.name} ${JSON.stringify(tool.parameters?.properties || {})}`)
    .join('\n');
  const profile = String(context.prompt || '').slice(0, 2200);
  const history = browserHistoryText(messages);
  const hint = nextActionHint(messages);
  const prompt = [
    '你是运行在浏览器内的 Weline B2B 获客智能体。',
    '只输出一行 JSON，结构：{"assistant":"一句说明","tool_calls":[{"name":"工具名","arguments":{}}]}。',
    '禁止重复题目、禁止解释、禁止 Markdown 代码块。没有可用工具时输出 {"assistant":"...","tool_calls":[]}。',
    '可用工具（只能选这四个）：',
    toolList,
    'save_lead.axes 是 5 个 0-2 的整数：[品类匹配, 采购意图, B2B属性, 数量或时间, 渠道可用]，总分必须 ≥6。',
    '只采公开页面与公开商务邮箱，不编造公司名/邮箱/价格。',
    '',
    '目标站点画像（节选）：',
    profile,
    '',
    '已发生的对话与工具结果：',
    history,
    '',
    `下一步必须执行：${hint}`,
    '现在只输出 JSON：',
  ].join('\n');

  let content = '';
  let parsed = { assistant: '', tool_calls: [] };
  for (let attempt = 1; attempt <= 2; attempt += 1) {
    const response = await new Promise((resolve, reject) => {
      const timer = setTimeout(() => reject(new Error('浏览器模型推理超时（5 分钟）')), 300000);
      chrome.runtime.sendMessage({
        target: 'weline-offscreen',
        type: 'WEBLLM_CHAT',
        model,
        messages: attempt === 1
          ? [
            { role: 'system', content: '你是浏览器内的获客智能体，每次只输出一个 JSON 对象。' },
            { role: 'user', content: prompt },
          ]
          : [
            { role: 'system', content: '只输出 JSON，不能有任何其他文字。' },
            { role: 'user', content: prompt },
            { role: 'assistant', content: String(content || '').slice(0, 800) },
            { role: 'user', content: '上面的输出不符合要求。请只输出一个 JSON 对象：{"assistant":"...","tool_calls":[{"name":"web_search","arguments":{"query":"..."}}]}' },
          ],
        tools: [],
        params: { temperature: 0.1, max_tokens: 600 },
      }, (reply) => {
        clearTimeout(timer);
        if (chrome.runtime.lastError) {
          reject(new Error(chrome.runtime.lastError.message));
          return;
        }
        resolve(reply);
      });
    });
    if (!response || !response.ok) {
      throw new Error(response?.message || '浏览器模型运行失败');
    }
    content = String(response.message?.content || '');
    const looksJson = /\{/.test(content);
    parsed = looksJson ? parseBrowserJson(content) : { assistant: content, tool_calls: [] };
    const hasCalls = Array.isArray(parsed.tool_calls) && parsed.tool_calls.length > 0;
    const isJsonReply = looksJson && (hasCalls || typeof parsed.assistant === 'string');
    if (hasCalls || (isJsonReply && content.trim().startsWith('{'))) {
      break;
    }
    log(`浏览器模型输出未按 JSON 协议（第 ${attempt} 次），追加约束重试`);
  }

  let assistant = String(parsed.assistant || content || '');
  let toolCalls = normalizeToolCalls(Array.isArray(parsed.tool_calls) ? parsed.tool_calls : []);
  const allowed = new Set(['web_search', 'inspect_page', 'save_lead', 'list_existing_leads']);
  toolCalls = toolCalls.filter((call) => allowed.has(call.function.name));

  return reportLocalStep({
    assistant,
    tool_calls: toolCalls,
    usage: {
      prompt_tokens: Math.ceil(prompt.length / 4),
      completion_tokens: Math.ceil(content.length / 4),
    },
    model_mode: 'browser_local',
  });
}

/**
 * 小模型的上下文压缩：把工具结果折算成文本，避免 chat 模板对 tool 角色支持不全。
 */
function browserHistoryText(messages) {
  const lines = [];
  for (const message of messages.slice(-14)) {
    const role = String(message.role || 'user');
    if (role === 'system') {
      continue;
    }
    let content = typeof message.content === 'string' ? message.content : JSON.stringify(message.content || '');
    if (role === 'tool') {
      content = '工具结果：' + content.replace(/\s+/g, ' ').slice(0, 1500);
    } else if (role === 'assistant') {
      const calls = Array.isArray(message.tool_calls) ? message.tool_calls : [];
      const names = calls.map((call) => call?.function?.name || call?.name || '').filter(Boolean).join(',');
      content = content.slice(0, 500) + (names ? `（调用工具：${names}）` : '');
    } else {
      content = content.slice(0, 500);
    }
    lines.push(`${role.toUpperCase()}: ${content}`);
  }
  return lines.join('\n') || '（还没有发生任何步骤）';
}

/**
 * 给 1B 级模型一个明确的下一步，避免它在多步任务里空转。
 */
function nextActionHint(messages) {
  const last = messages[messages.length - 1];
  if (!last) {
    return '先调用 web_search，query 用英文「买家意向词 + 品类词」，例如 wholesale / distributor / importer + 你的品类。';
  }
  if (last.role === 'tool') {
    const text = typeof last.content === 'string' ? last.content : JSON.stringify(last.content || '');
    if (/"url"|"source_url"/.test(text) && /"title"/.test(text)) {
      return '上一步是搜索结果。从中选 1 个最像买家的 URL，调用 inspect_page 核实联系人。';
    }
    if (/email/i.test(text) && /@/.test(text)) {
      return '上一步页面里出现了公开商务邮箱。调用 save_lead：company_name 用公司名，source_url 用页面 URL，email 用页面上的邮箱，country_code 可留空，axes 给 5 个 0-2 的整数，source_excerpt 摘 1 句原文。';
    }
    return '上一步页面没有可用邮箱。换下一个候选：调用 inspect_page 或再 web_search。';
  }
  return '继续推进：需要候选就 web_search，需要核实就 inspect_page，找到带公开商务邮箱的买家就 save_lead。';
}

async function browserLocalStep(context, messages, tools, params) {
  const browserModel = String(context.browser_model || 'chrome-builtin');
  if (browserModel !== 'chrome-builtin') {
    return webllmStep(context, messages, tools, params);
  }
  return chromeBuiltinStep(context, messages, tools, params);
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

async function chromeBuiltinStep(context, messages, tools, params) {
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
        const filtered = filterToolResults(result.results);
        postEvent('search', { query, count: filtered.results.length, skipped: filtered.skipped, engine: result.engine });
        return Object.assign({}, result, { results: filtered.results, skipped: filtered.skipped, hint: filtered.hint });
      }
      return result;
    }
    case 'inspect_page': {
      const url = String(args.url || '').trim();
      if (!url) {
        return { error: 'url 为空' };
      }
      if (state.scan && state.scan.isSeen(url)) {
        return { url: url, skipped: true, reason: '该页面已扫描过，请换别的候选页或换关键词。' };
      }
      const page = await inspectPage(url);
      if (state.scan) {
        state.scan.mark(url);
        reportScanned();
      }
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

/**
 * 上报本次已抓取的页面，落站点级台账，下次运行直接跳过。
 * 失败不打断运行（只是少了跨运行去重）。
 */
function reportScanned() {
  if (!state.running || !state.ticket || !state.scan) return;
  const urls = state.scan.drain();
  if (urls.length === 0) return;
  apiRequest(state.baseUrl, `${API_PREFIX}/scan`, {
    method: 'POST',
    body: { ticket: state.ticket, urls: urls },
  }).catch((error) => {
    log(`已扫描页面上报失败（不影响本次运行）：${error.message || error}`);
  });
}

function postEvent(type, payload) {
  if (!state.running || !state.ticket) {
    return;
  }
  apiRequest(state.baseUrl, `${API_PREFIX}/event`, {
    method: 'POST',
    body: { ticket: state.ticket, type, payload },
  }).catch(async (error) => {
    if (error && error.status === 409 && /暂停/.test(String(error.message))) {
      await waitWhilePaused();
      postEvent(type, payload);
      return;
    }
    log(`事件上报失败：${error.message}`);
  });
}

/**
 * 过滤检索结果：剔除该站点已扫描页面与已获客域名，并给出换词提示。
 * @returns {{results: Array, skipped: number, hint: string}}
 */
function filterToolResults(results) {
  return self.WelineScanDedupe.filterResults(results, state.scan);
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

  if (message.type === 'WEBLLM_PROGRESS') {
    state.progress = String(message.text || '');
    if (state.running) {
      log(`浏览器模型：${state.progress}`);
    }
    broadcast();
    return false;
  }

  if (message.type === 'WELINE_START') {
    startRun(message.payload || {})
      .then(() => sendResponse({ ok: true }))
      .catch((error) => sendResponse({ ok: false, message: error.message || '启动失败' }));
    return true;
  }

  if (message.type === 'WELINE_PAUSE') {
    controlRun('pause')
      .then((run) => {
        state.paused = run?.status === 'paused';
        log('已请求暂停');
        broadcast();
        sendResponse({ ok: true, run });
      })
      .catch((error) => sendResponse({ ok: false, message: error.message || '暂停失败' }));
    return true;
  }

  if (message.type === 'WELINE_RESUME') {
    controlRun('resume')
      .then((run) => {
        state.paused = false;
        log('已请求继续');
        broadcast();
        sendResponse({ ok: true, run });
      })
      .catch((error) => sendResponse({ ok: false, message: error.message || '继续失败' }));
    return true;
  }

  if (message.type === 'WELINE_STOP') {
    controlRun('stop')
      .catch(() => null)
      .then(() => {
        stopRun('user');
        sendResponse({ ok: true });
      });
    return true;
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
