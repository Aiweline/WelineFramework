'use strict';

/**
 * 悬浮助手（content script）：
 * 1. 扫描当前页面的 [data-acq-ticket]（兜底：64 位 hex 文本），识别后台签发的票据；
 * 2. 识别到票据自动展开面板，点击「初始化模型并运行」即可启动，无需手工填写；
 * 3. 面板显示模型初始化进度、运行状态与日志，并提供暂停 / 继续 / 停止 / 打开运行详情。
 *
 * 票据只在内存中使用，不写入 storage、不写日志。
 */
(() => {
  if (globalThis.__welineAcqAssistant) {
    return;
  }
  globalThis.__welineAcqAssistant = true;

  const TICKET_PATTERN = /^[a-f0-9]{64}$/i;
  const POLL_INTERVAL_MS = 1200;
  const SCAN_INTERVAL_MS = 1500;
  const HOST_ID = 'weline-acq-assistant-host';

  const state = {
    ticket: '',
    baseUrl: '',
    runId: 0,
    expanded: false,
    dismissedForTicket: '',
    busy: false,
    runtime: null,
    scanTimer: null,
    pollTimer: null,
    observer: null,
    host: null,
    root: null,
  };

  const MODE_LABELS = {
    ai_module: 'AI 模块模型',
    local_computer: '本地电脑模型',
    browser_local: '浏览器端侧模型',
  };

  function maskTicket(ticket) {
    if (!ticket) {
      return '—';
    }
    return `${ticket.slice(0, 6)}…${ticket.slice(-4)}`;
  }

  function findTicket() {
    const nodes = document.querySelectorAll('[data-acq-ticket]');
    for (const node of nodes) {
      const raw = String(
        node.getAttribute('data-acq-ticket')
        || node.textContent
        || ''
      ).trim();
      if (TICKET_PATTERN.test(raw)) {
        return raw;
      }
    }

    const fallback = document.getElementById('run-ticket');
    const text = fallback ? String(fallback.textContent || '').trim() : '';
    if (TICKET_PATTERN.test(text)) {
      return text;
    }

    return '';
  }

  function findBaseUrl(ticketNode) {
    const attrNode = (ticketNode && ticketNode.closest('[data-acq-bridge-base]'))
      || document.querySelector('[data-acq-bridge-base]');
    const raw = attrNode ? String(attrNode.getAttribute('data-acq-bridge-base') || '').trim() : '';
    if (/^https?:\/\//i.test(raw)) {
      return raw.replace(/\/+$/, '');
    }
    return location.origin;
  }

  let lastScanAt = 0;

  function detectTicket() {
    const now = Date.now();
    if (now - lastScanAt < 400) {
      return;
    }
    lastScanAt = now;
    const ticket = findTicket();
    if (!ticket) {
      if (state.ticket) {
        state.ticket = '';
        state.runId = 0;
        render();
      }
      return;
    }
    if (ticket === state.ticket) {
      return;
    }

    state.ticket = ticket;
    state.baseUrl = findBaseUrl(document.querySelector('[data-acq-ticket]') || document.getElementById('run-ticket'));
    state.runId = 0;
    state.busy = false;
    state.runtime = null;
    // 新票据自动弹出（同一票据手动收起后不再自动弹）
    if (state.dismissedForTicket !== ticket) {
      state.expanded = true;
    }
    render();
    pollRuntime();
  }

  function buildUi() {
    if (state.host) {
      return;
    }

    const host = document.createElement('div');
    host.id = HOST_ID;
    host.style.cssText = 'position:fixed;right:24px;bottom:180px;z-index:2147483647;';
    const root = host.attachShadow({ mode: 'open' });
    root.innerHTML = `
      <style>
        :host { all: initial; }
        .wrap { font: 13px/1.5 -apple-system, "PingFang SC", "Microsoft YaHei", system-ui, sans-serif; color: #1f2430; }
        .bubble {
          width: 48px; height: 48px; border-radius: 50%; border: 0; cursor: pointer;
          background: #2f6bff; color: #fff; box-shadow: 0 8px 24px rgba(31, 36, 48, .24);
          display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 15px;
          margin-left: auto; position: relative;
        }
        .bubble:focus-visible { outline: 2px solid #2f6bff; outline-offset: 3px; }
        .dot {
          position: absolute; top: 3px; right: 3px; width: 10px; height: 10px; border-radius: 50%;
          background: #18a058; box-shadow: 0 0 0 2px #fff;
        }
        .panel {
          width: min(360px, calc(100vw - 32px)); margin-bottom: 12px;
          max-height: calc(100vh - 260px); display: flex; flex-direction: column;
          background: #fff; border: 1px solid #e3e6ee; border-radius: 14px;
          box-shadow: 0 16px 48px rgba(24, 30, 45, .22); overflow: hidden;
        }
        .panel[hidden] { display: none; }
        .head { display: flex; align-items: center; gap: 8px; padding: 12px 14px; border-bottom: 1px solid #eef0f5; }
        .head strong { font-size: 14px; }
        .pill { margin-left: auto; font-size: 11px; padding: 2px 8px; border-radius: 999px; background: #eef0f5; color: #5b6478; }
        .pill[data-tone="info"] { background: #e8efff; color: #2f6bff; }
        .pill[data-tone="success"] { background: #e6f6ee; color: #18a058; }
        .pill[data-tone="warning"] { background: #fff3e0; color: #b26a00; }
        .pill[data-tone="danger"] { background: #fdecec; color: #c62828; }
        .body { padding: 12px 14px; display: grid; gap: 10px; overflow: auto; min-height: 0; }
        .row { display: flex; align-items: center; gap: 8px; font-size: 12px; color: #5b6478; }
        .row code { font-size: 12px; background: #f5f6fa; border-radius: 6px; padding: 1px 6px; color: #1f2430; }
        .hint { font-size: 12px; color: #5b6478; }
        .progress { height: 4px; border-radius: 999px; background: #eef0f5; overflow: hidden; }
        .progress > i { display: block; height: 100%; width: 30%; background: #2f6bff; border-radius: 999px; transition: width .3s ease; }
        .actions { display: flex; flex-wrap: wrap; gap: 8px; }
        button.btn {
          border-radius: 8px; border: 1px solid #d7dbe6; background: #fff; color: #1f2430;
          padding: 7px 12px; font-size: 12px; cursor: pointer;
        }
        button.btn:focus-visible { outline: 2px solid #2f6bff; outline-offset: 2px; }
        button.btn[disabled] { opacity: .55; cursor: not-allowed; }
        button.btn.primary { background: #2f6bff; border-color: #2f6bff; color: #fff; }
        button.btn.danger { color: #c62828; border-color: #f0c6c6; }
        .stats { display: grid; grid-template-columns: repeat(3, 1fr); gap: 8px; }
        .stat { background: #f7f8fb; border-radius: 10px; padding: 6px 8px; }
        .stat b { display: block; font-size: 14px; }
        .stat span { font-size: 11px; color: #5b6478; }
        .logs { margin: 0; padding: 8px 10px; list-style: none; background: #0f1320; border-radius: 10px; color: #cfd6e6; font-size: 11px; line-height: 1.6; max-height: 132px; overflow: auto; }
        .logs li + li { margin-top: 2px; }
        .foot { display: flex; justify-content: space-between; align-items: center; padding: 10px 14px; border-top: 1px solid #eef0f5; }
        .link { background: none; border: 0; color: #2f6bff; font-size: 12px; cursor: pointer; padding: 0; }
        .link:focus-visible { outline: 2px solid #2f6bff; outline-offset: 2px; }
        @media (prefers-reduced-motion: reduce) { .progress > i { transition: none; } }
      </style>
      <div class="wrap">
        <section class="panel" id="panel" hidden aria-label="AI 获客助手">
          <header class="head">
            <strong>AI 获客助手</strong>
            <span class="pill" id="pill">未识别票据</span>
          </header>
          <div class="body">
            <div class="row"><span>票据</span><code id="ticket">—</code></div>
            <div class="row"><span>模型</span><span id="model">—</span></div>
            <div class="progress" id="progress" hidden><i id="progress-bar" style="width:8%"></i></div>
            <div class="hint" id="hint">打开「AI 获客 → 获客智能体」签发运行，票据出现后这里会自动弹出。</div>
            <div class="stats" id="stats" hidden>
              <div class="stat"><b id="saved">0</b><span>已入库</span></div>
              <div class="stat"><b id="searches">0</b><span>搜索</span></div>
              <div class="stat"><b id="cost">0</b><span>成本</span></div>
            </div>
            <div class="actions">
              <button type="button" class="btn primary" id="run">初始化模型并运行</button>
              <button type="button" class="btn" id="pause" hidden>暂停</button>
              <button type="button" class="btn" id="resume" hidden>继续</button>
              <button type="button" class="btn danger" id="stop" hidden>停止</button>
            </div>
            <ul class="logs" id="logs" hidden></ul>
          </div>
          <footer class="foot">
            <button type="button" class="link" id="open">打开运行记录</button>
            <button type="button" class="link" id="collapse">收起</button>
          </footer>
        </section>
        <button type="button" class="bubble" id="bubble" aria-label="AI 获客助手">
          <span>AI</span><span class="dot" id="dot" hidden></span>
        </button>
      </div>
    `;
    (document.body || document.documentElement).appendChild(host);
    state.host = host;
    state.root = root;

    root.getElementById('bubble').addEventListener('click', () => {
      state.expanded = !state.expanded;
      if (!state.expanded) {
        state.dismissedForTicket = state.ticket;
      }
      render();
      if (state.expanded) {
        pollRuntime();
      }
    });
    root.getElementById('collapse').addEventListener('click', () => {
      state.expanded = false;
      state.dismissedForTicket = state.ticket;
      render();
    });
    root.getElementById('run').addEventListener('click', () => {
      if (['done', 'stopped'].includes(state.runtime?.phase)) {
        window.open(buildRunUrl(), '_blank', 'noopener');
        return;
      }
      startRun();
    });
    root.getElementById('pause').addEventListener('click', () => control('pause'));
    root.getElementById('resume').addEventListener('click', () => control('resume'));
    root.getElementById('stop').addEventListener('click', () => control('stop'));
    root.getElementById('open').addEventListener('click', () => {
      window.open(buildRunUrl(), '_blank', 'noopener');
    });
  }

  function buildRunUrl() {
    const runId = Number(state.runtime?.run?.id || state.runId || 0);
    const base = state.baseUrl || location.origin;
    const match = location.pathname.match(/^(.*\/ai_acquisition\/backend\/)/);
    if (match) {
      return `${base}${match[1]}run${runId ? `#run-${runId}` : ''}`;
    }
    return `${base}/ai_acquisition/backend/run`;
  }

  function tone(runtime) {
    if (!runtime) {
      return { label: '未运行', tone: 'muted' };
    }
    if (runtime.running) {
      return runtime.paused
        ? { label: '已暂停', tone: 'warning' }
        : { label: '执行中', tone: 'info' };
    }
    if (runtime.phase === 'init') {
      return { label: '初始化模型', tone: 'info' };
    }
    if (runtime.phase === 'stopped') {
      return { label: '已停止', tone: 'muted' };
    }
    if (runtime.phase === 'failed') {
      return { label: '启动失败', tone: 'danger' };
    }
    if (runtime.phase === 'done') {
      return { label: '已结束', tone: 'success' };
    }
    return { label: '待运行', tone: 'muted' };
  }

  function render() {
    buildUi();
    const root = state.root;
    if (!root) {
      return;
    }
    if (state.host) {
      const phase = state.runtime
        ? (state.runtime.running ? (state.runtime.paused ? 'paused' : 'running') : state.runtime.phase)
        : 'idle';
      state.host.dataset.acqPhase = phase;
    }
    const panel = root.getElementById('panel');
    panel.hidden = !state.expanded;
    root.getElementById('dot').hidden = !state.ticket;

    const ticketEl = root.getElementById('ticket');
    ticketEl.textContent = state.ticket ? maskTicket(state.ticket) : '—';

    const runtime = state.runtime;
    const status = tone(runtime);
    const pill = root.getElementById('pill');
    pill.textContent = status.label;
    pill.setAttribute('data-tone', status.tone);

    const modelMode = runtime?.modelMode || '';
    const modelLabel = runtime?.modelLabel || '';
    root.getElementById('model').textContent = modelMode
      ? `${MODE_LABELS[modelMode] || modelMode}${modelLabel ? ` · ${modelLabel}` : ''}`
      : '—';

    const hint = root.getElementById('hint');
    const progress = root.getElementById('progress');
    const runtimePhase = runtime?.phase || '';
    if (!state.ticket) {
      hint.textContent = '当前页面没有票据；到「AI 获客 → 获客智能体」创建运行后，这里会自动弹出。';
      hint.hidden = false;
    } else if (runtime?.running && runtime.paused) {
      hint.textContent = '运行已暂停，点击「继续」恢复；扩展会等待你的操作。';
      hint.hidden = false;
    } else if (runtime?.running) {
      hint.textContent = '扩展正在本机浏览器内执行搜索，进度见下方日志。';
      hint.hidden = false;
    } else if (runtimePhase === 'init') {
      hint.textContent = runtime?.progress || '正在下载 / 初始化所选模型…';
      hint.hidden = false;
    } else if (runtimePhase === 'failed') {
      hint.textContent = `运行未开始：${runtime.lastError || '模型初始化失败'}`;
      hint.hidden = false;
    } else if (runtimePhase === 'done' || runtimePhase === 'stopped') {
      hint.textContent = '本次运行已结束，可在运行记录里查看线索与成本。';
      hint.hidden = false;
    } else if (state.ticket && !runtime) {
      hint.textContent = '已识别票据，点击「初始化模型并运行」开始。';
      hint.hidden = false;
    }

    const showProgress = runtimePhase === 'init';
    progress.hidden = !showProgress;
    if (showProgress) {
      const text = String(runtime?.progress || '');
      const match = text.match(/(\d{1,3})\s*%/);
      const percent = match ? Math.max(4, Math.min(98, Number(match[1]))) : 30;
      const bar = root.getElementById('progress-bar');
      bar.style.width = `${percent}%`;
      bar.style.opacity = match ? '1' : '.65';
    }

    const runBtn = root.getElementById('run');
    const pauseBtn = root.getElementById('pause');
    const resumeBtn = root.getElementById('resume');
    const stopBtn = root.getElementById('stop');
    const running = Boolean(runtime?.running);
    const paused = Boolean(runtime?.paused);

    runBtn.hidden = running || runtimePhase === 'init';
    runBtn.disabled = !state.ticket || state.busy;
    if (runtimePhase === 'done' || runtimePhase === 'stopped') {
      runBtn.textContent = '重新签发运行';
    } else if (runtimePhase === 'failed') {
      runBtn.textContent = '重试初始化';
    } else {
      runBtn.textContent = '初始化模型并运行';
    }

    pauseBtn.hidden = !running || paused;
    resumeBtn.hidden = !running || !paused;
    stopBtn.hidden = !running;

    const stats = root.getElementById('stats');
    const logs = root.getElementById('logs');
    stats.hidden = !running && !['done', 'stopped'].includes(runtimePhase);
    logs.hidden = !runtime?.logs?.length;
    if (running || runtimePhase === 'done' || runtimePhase === 'stopped') {
      root.getElementById('saved').textContent = String(Number(runtime?.saved) || 0);
      root.getElementById('searches').textContent = String(Number(runtime?.searches) || 0);
      const cost = Number(runtime?.cost) || 0;
      root.getElementById('cost').textContent = cost > 0 ? `¥${cost.toFixed(4)}` : '0';
    }
    if (logs.hidden === false) {
      logs.textContent = '';
      for (const line of (runtime.logs || []).slice(-6)) {
        const li = document.createElement('li');
        li.textContent = String(line);
        logs.appendChild(li);
      }
      logs.scrollTop = logs.scrollHeight;
    }
  }

  async function startRun() {
    if (!state.ticket || state.busy) {
      return;
    }
    state.busy = true;
    render();
    try {
      const response = await chrome.runtime.sendMessage({
        type: 'WELINE_START',
        payload: { baseUrl: state.baseUrl, ticket: state.ticket },
      });
      if (!response?.ok) {
        throw new Error(response?.message || '启动失败');
      }
    } catch (error) {
      state.busy = false;
      const hint = state.root?.getElementById('hint');
      if (hint) {
        hint.textContent = `启动失败：${error.message || error}`;
        hint.hidden = false;
      }
      render();
      return;
    }
    state.busy = false;
    if (state.host) {
      state.host.dataset.acqPhase = 'running';
    }
    pollRuntime();
  }

  async function control(action) {
    try {
      await chrome.runtime.sendMessage({ type: `WELINE_${action.toUpperCase()}` });
    } catch (error) {
      // 后台未就绪时忽略，下一次轮询会纠正状态
    }
    pollRuntime();
  }

  async function pollRuntime() {
    try {
      const response = await chrome.runtime.sendMessage({ type: 'WELINE_STATE' });
      state.runtime = response?.state || null;
      if (state.runtime?.run?.id) {
        state.runId = Number(state.runtime.run.id) || state.runId;
      }
      render();
    } catch (error) {
      // 后台休眠时静默
    }
  }

  function startTimers() {
    if (!state.pollTimer) {
      state.pollTimer = setInterval(() => {
        if (state.expanded) {
          pollRuntime();
        }
      }, POLL_INTERVAL_MS);
    }
    if (!state.scanTimer) {
      state.scanTimer = setInterval(detectTicket, SCAN_INTERVAL_MS);
    }
    if (!state.observer && document.body) {
      state.observer = new MutationObserver(() => detectTicket());
      state.observer.observe(document.body, { childList: true, subtree: true, characterData: true });
    }
  }

  window.addEventListener('weline-acq-ping', () => {
    window.dispatchEvent(new CustomEvent('weline-acq-pong', {
      detail: {
        phase: state.runtime?.phase || 'idle',
        ticket: state.ticket ? maskTicket(state.ticket) : '',
      },
    }));
  });

  // CustomEvent.detail 无法跨 isolated world 传递（Chrome 序列化为 null），
  // 票据与基地址一律从 DOM 读取：#run-ticket[data-acq-ticket] 由后台创建时写入。
  window.addEventListener('weline-acq-start', () => {
    if (state.host) {
      state.host.dataset.acqPhase = 'starting';
    }
    // detectTicket 有 400ms 节流；强制绕过节流立即拾取新票据。
    lastScanAt = 0;
    detectTicket();
    if (!state.ticket) {
      // 兜底：直接读 DOM（同 findTicket 的主路径）
      const code = document.getElementById('run-ticket');
      const raw = code ? String(code.getAttribute('data-acq-ticket') || code.textContent || '').trim() : '';
      if (/^[a-f0-9]{64}$/i.test(raw)) {
        state.ticket = raw;
        state.baseUrl = location.origin;
        state.busy = false;
        state.runtime = null;
        state.expanded = true;
        state.dismissedForTicket = '';
        render();
      }
    }
    if (!state.ticket) {
      return;
    }
    if (!state.baseUrl) {
      state.baseUrl = location.origin;
    }
    state.expanded = true;
    state.dismissedForTicket = '';
    render();
    startRun();
  });

  function init() {
    if (!/^https?:$/.test(location.protocol)) {
      return;
    }
    buildUi();
    detectTicket();
    startTimers();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init, { once: true });
  } else {
    init();
  }
})();
