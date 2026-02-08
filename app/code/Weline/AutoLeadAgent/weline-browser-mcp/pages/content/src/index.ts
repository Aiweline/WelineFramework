/**
 * AutoLeadAgent Content Script
 * 负责：
 *   1. 页面与扩展后台之间的消息中继
 *   2. 浮动按钮 UI 注入（Shadow DOM 隔离）
 *   3. 目标页面检测 + 页面进入/离开通知
 *
 * 通信路径：
 *   页面 → window.postMessage → content script → chrome.runtime.sendMessage → background
 *   background → chrome.tabs.sendMessage → content script → window.postMessage → 页面
 */

const EXTENSION_ID = chrome.runtime.id;
const LOG_PREFIX = '[AutoLeadAgent:Content]';
const EXT_VERSION = chrome.runtime.getManifest().version;

console.log(LOG_PREFIX, 'content script loaded, extension ID:', EXTENSION_ID);

// ==================== 页面类型检测 ====================
const TARGET_PATTERNS = [
  /\/auto-lead-agent\/backend\/config/i,
  /\/auto-lead-agent\/backend\/index/i,
];
const isTargetPage = TARGET_PATTERNS.some((p) => p.test(location.pathname));
const pageType: 'config' | 'index' | 'other' = /\/config/i.test(location.pathname)
  ? 'config'
  : /\/index/i.test(location.pathname)
    ? 'index'
    : 'other';

// ==================== 主动广播扩展已就绪 ====================
const readyPayload = { type: 'AUTOLEADAGENT_READY', extensionId: EXTENSION_ID, version: EXT_VERSION };
window.postMessage(readyPayload, '*');
setTimeout(() => window.postMessage(readyPayload, '*'), 500);
setTimeout(() => window.postMessage(readyPayload, '*'), 1500);

// 通知 background 页面进入/离开
if (isTargetPage) {
  chrome.runtime.sendMessage({ type: 'PAGE_ENTER', url: location.href }, () => {
    if (chrome.runtime.lastError) { /* ignore */ }
  });
  window.addEventListener('beforeunload', () => {
    chrome.runtime.sendMessage({ type: 'PAGE_LEAVE' }, () => {});
  });
}

// ==================== 页面 → 扩展 消息中继 ====================
window.addEventListener('message', (event: MessageEvent) => {
  if (event.source !== window) return;
  const data = event.data;
  if (!data || typeof data !== 'object') return;

  // 发现扩展请求
  if (data.type === 'DISCOVER_AUTOLEADAGENT_EXTENSION' || data.type === 'DISCOVER_EXTENSION') {
    const dp = { extensionId: EXTENSION_ID, version: EXT_VERSION };
    window.postMessage({ type: 'AUTOLEADAGENT_DISCOVERED', ...dp }, '*');
    window.postMessage({ type: 'EXTENSION_DISCOVERED', ...dp }, '*');
    window.postMessage({ type: 'AUTOLEADAGENT_EXTENSION_FOUND', ...dp }, '*');
    return;
  }

  // 通用请求中继
  if (data.type === 'AUTOLEADAGENT_REQUEST' && data.payload) {
    const { payload } = data;
    const requestId = data.requestId || 'req_' + Date.now();
    chrome.runtime.sendMessage(
      { type: payload.type || data.action, payload, requestId },
      (response) => {
        window.postMessage({
          type: 'AUTOLEADAGENT_RESPONSE',
          requestId,
          response: response || null,
          error: chrome.runtime.lastError?.message || null,
        }, '*');
      },
    );
    return;
  }

  // PING
  if (data.type === 'PING_AUTOLEADAGENT') {
    chrome.runtime.sendMessage({ type: 'PING' }, (response) => {
      window.postMessage({
        type: 'PONG_AUTOLEADAGENT',
        success: !chrome.runtime.lastError,
        extensionId: EXTENSION_ID,
        version: EXT_VERSION,
        response: response || null,
      }, '*');
    });
    return;
  }

  // MODEL 请求透传
  if (data.type === 'MODEL_INFERENCE_REQUEST') {
    const iid = data.inferenceId || 'inf_' + Date.now();
    chrome.runtime.sendMessage(
      { type: 'MODEL_INFERENCE', prompt: data.prompt, options: data.options || {}, inferenceId: iid },
      (r) => {
        window.postMessage({ type: 'MODEL_INFERENCE_RESPONSE', inferenceId: iid, response: r || null, error: chrome.runtime.lastError?.message || null }, '*');
      },
    );
    return;
  }
  if (data.type === 'MODEL_STATUS_REQUEST') {
    chrome.runtime.sendMessage({ type: 'MODEL_STATUS' }, (r) => {
      window.postMessage({ type: 'MODEL_STATUS_RESPONSE', response: r || null, error: chrome.runtime.lastError?.message || null }, '*');
    });
    return;
  }
  if (data.type === 'MODEL_LOAD_REQUEST') {
    chrome.runtime.sendMessage({ type: 'MODEL_LOAD', modelId: data.modelId }, (r) => {
      window.postMessage({ type: 'MODEL_LOAD_RESPONSE', response: r || null, error: chrome.runtime.lastError?.message || null }, '*');
    });
    return;
  }
  if (data.type === 'MODEL_UNLOAD_REQUEST') {
    chrome.runtime.sendMessage({ type: 'MODEL_UNLOAD' }, (r) => {
      window.postMessage({ type: 'MODEL_UNLOAD_RESPONSE', response: r || null, error: chrome.runtime.lastError?.message || null }, '*');
    });
    return;
  }

  // MODEL_SAVE_CONFIG 转发
  if (data.type === 'MODEL_SAVE_CONFIG') {
    chrome.runtime.sendMessage({ type: 'MODEL_SAVE_CONFIG', payload: data.payload || data }, (r) => {
      window.postMessage({ type: 'MODEL_SAVE_CONFIG_RESPONSE', response: r || null, error: chrome.runtime.lastError?.message || null }, '*');
    });
    return;
  }

  // TASK_PROGRESS 转发
  if (data.type === 'TASK_PROGRESS') {
    chrome.runtime.sendMessage({ type: 'TASK_PROGRESS', log: data.log || data.payload }, () => {
      if (chrome.runtime.lastError) { /* ignore */ }
    });
    return;
  }
});

// ==================== 扩展 → 页面 事件推送 ====================
chrome.runtime.onMessage.addListener((message, _sender, sendResponse) => {
  if (!message) return false;
  if (message.type === 'MODEL_EVENT') {
    window.postMessage({ type: 'AUTOLEADAGENT_MODEL_EVENT', event: message.event, data: message.data }, '*');
    updateFloatingBtnStatus(message.event, message.data);
    sendResponse({ received: true });
  }
  if (message.type === 'TASK_PROGRESS') {
    window.postMessage({ type: 'AUTOLEADAGENT_TASK_PROGRESS', log: message.log }, '*');
    appendProgressToPanel(message.log);
    sendResponse({ received: true });
  }
  if (message.type === 'TASK_PROGRESS_BATCH') {
    if (message.logs?.length) {
      message.logs.forEach((log: any) => appendProgressToPanel(log));
    }
    sendResponse({ received: true });
  }
  return false;
});

// ==================== 浮动按钮 UI ====================
let panelOpen = false;
let currentModelStatus = { event: 'unknown', data: {} as any };
let shadowRoot: ShadowRoot | null = null;
let panelEl: HTMLElement | null = null;
let logContainer: HTMLElement | null = null;
let statusDot: HTMLElement | null = null;
let statusLine: HTMLElement | null = null;

function injectFloatingButton() {
  if (document.getElementById('ala-floating-host')) return;

  const host = document.createElement('div');
  host.id = 'ala-floating-host';
  host.style.cssText = 'position:fixed;top:0;left:0;width:0;height:0;z-index:2147483647;pointer-events:none;';
  document.body.appendChild(host);

  shadowRoot = host.attachShadow({ mode: 'closed' });

  const style = document.createElement('style');
  style.textContent = buildCSS();
  shadowRoot.appendChild(style);

  const btn = document.createElement('div');
  btn.className = 'ala-btn';
  btn.innerHTML =
    '<svg class="ala-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' +
    '<path d="M12 2a4 4 0 0 1 4 4v1h1a3 3 0 0 1 3 3v2a3 3 0 0 1-3 3h-1v1a4 4 0 0 1-8 0v-1H7a3 3 0 0 1-3-3v-2a3 3 0 0 1 3-3h1V6a4 4 0 0 1 4-4z"/>' +
    '<circle cx="9" cy="10" r="1" fill="currentColor"/>' +
    '<circle cx="15" cy="10" r="1" fill="currentColor"/>' +
    '<path d="M9 14h6"/>' +
    '</svg>' +
    '<span class="ala-label">\u5bfb\u5ba2\u52a9\u624b</span>' +
    '<span class="ala-dot" id="ala-dot"></span>';
  btn.addEventListener('click', togglePanel);
  shadowRoot.appendChild(btn);

  statusDot = shadowRoot.getElementById('ala-dot');

  panelEl = document.createElement('div');
  panelEl.className = 'ala-panel';
  panelEl.innerHTML = buildPanelHTML();
  shadowRoot.appendChild(panelEl);

  const closeBtn = panelEl.querySelector('#ala-close');
  if (closeBtn) closeBtn.addEventListener('click', togglePanel);
  logContainer = panelEl.querySelector('#ala-logs');
  statusLine = panelEl.querySelector('#ala-model-status-line');

  fetchFullStatus();
}

function buildCSS(): string {
  return `
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    .ala-btn {
      pointer-events: auto; cursor: pointer; position: fixed; right: 12px; top: 50%;
      transform: translateY(-50%); display: flex; align-items: center; gap: 6px;
      background: rgba(37,99,235,0.92); color: #fff; padding: 8px 14px 8px 10px;
      border-radius: 24px; font-family: -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;
      font-size: 13px; font-weight: 500; box-shadow: 0 2px 12px rgba(0,0,0,0.25);
      transition: all 0.2s ease; user-select: none; white-space: nowrap; z-index: 1;
    }
    .ala-btn:hover { background: rgba(29,78,216,0.96); box-shadow: 0 4px 20px rgba(0,0,0,0.3); transform: translateY(-50%) scale(1.03); }
    .ala-icon { width: 18px; height: 18px; flex-shrink: 0; }
    .ala-label { line-height: 1; }
    .ala-dot { width: 8px; height: 8px; border-radius: 50%; flex-shrink: 0; background: #9ca3af; transition: background 0.3s; }
    .ala-dot.green { background: #22c55e; box-shadow: 0 0 6px #22c55e; }
    .ala-dot.yellow { background: #eab308; box-shadow: 0 0 6px #eab308; animation: ala-pulse 1.5s infinite; }
    .ala-dot.red { background: #ef4444; }
    @keyframes ala-pulse { 0%,100% { opacity:1; } 50% { opacity:0.4; } }

    .ala-panel {
      pointer-events: auto; position: fixed; right: -360px; top: 50%; transform: translateY(-50%);
      width: 340px; max-height: 70vh; background: #1e1e2e; color: #cdd6f4; border-radius: 12px;
      box-shadow: 0 8px 32px rgba(0,0,0,0.4); font-family: -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;
      font-size: 13px; display: flex; flex-direction: column; transition: right 0.3s ease; z-index: 2;
      overflow: hidden;
    }
    .ala-panel.open { right: 12px; }

    .ala-panel-header {
      display: flex; align-items: center; justify-content: space-between; padding: 12px 14px;
      background: #313244; border-bottom: 1px solid #45475a; flex-shrink: 0;
    }
    .ala-panel-title { font-weight: 600; font-size: 14px; }
    .ala-close-btn { background: none; border: none; color: #a6adc8; cursor: pointer; font-size: 18px; line-height: 1; padding: 2px 6px; border-radius: 4px; }
    .ala-close-btn:hover { background: #45475a; color: #cdd6f4; }

    .ala-status-card { padding: 10px 14px; background: #181825; border-bottom: 1px solid #313244; flex-shrink: 0; }
    .ala-status-row { display: flex; align-items: center; gap: 8px; font-size: 12px; }
    .ala-status-dot { width: 6px; height: 6px; border-radius: 50%; flex-shrink: 0; }

    .ala-log-area {
      flex: 1; overflow-y: auto; padding: 8px 12px; min-height: 100px;
      font-family: "Consolas","Monaco","Courier New",monospace; font-size: 12px; line-height: 1.6;
    }
    .ala-log-entry { padding: 1px 0; word-break: break-all; }
    .ala-log-entry.info { color: #89b4fa; }
    .ala-log-entry.warn { color: #f9e2af; }
    .ala-log-entry.error { color: #f38ba8; }
    .ala-log-entry.success { color: #a6e3a1; }

    .ala-guide { padding: 20px 14px; text-align: center; }
    .ala-guide p { color: #a6adc8; margin-bottom: 14px; font-size: 13px; }
    .ala-guide-btn {
      display: block; width: 100%; padding: 10px; margin-bottom: 8px; border: 1px solid #45475a;
      background: #313244; color: #cdd6f4; border-radius: 8px; cursor: pointer; font-size: 13px;
      text-decoration: none; text-align: center; transition: background 0.15s;
    }
    .ala-guide-btn:hover { background: #45475a; }
    .ala-empty { color: #585b70; text-align: center; padding: 24px 0; font-size: 12px; }
  `;
}

function buildPanelHTML(): string {
  if (isTargetPage) {
    return `
      <div class="ala-panel-header">
        <span class="ala-panel-title">\u5bfb\u5ba2\u52a9\u624b</span>
        <button class="ala-close-btn" id="ala-close">\u00d7</button>
      </div>
      <div class="ala-status-card">
        <div class="ala-status-row">
          <span class="ala-status-dot" id="ala-panel-dot" style="background:#9ca3af;"></span>
          <span id="ala-model-status-line">\u6b63\u5728\u83b7\u53d6\u6a21\u578b\u72b6\u6001...</span>
        </div>
      </div>
      <div class="ala-log-area" id="ala-logs">
        <div class="ala-empty">\u6682\u65e0\u4efb\u52a1\u8fdb\u5ea6</div>
      </div>`;
  }
  return `
    <div class="ala-panel-header">
      <span class="ala-panel-title">\u5bfb\u5ba2\u52a9\u624b</span>
      <button class="ala-close-btn" id="ala-close">\u00d7</button>
    </div>
    <div class="ala-guide">
      <p>\u6b64\u529f\u80fd\u4ec5\u5728\u4ee5\u4e0b\u9875\u9762\u53ef\u7528\uff1a</p>
      <a class="ala-guide-btn" id="ala-goto-index">\u2192 \u5bfb\u5ba2\u4efb\u52a1\u9875</a>
      <a class="ala-guide-btn" id="ala-goto-config">\u2192 \u914d\u7f6e\u9875</a>
    </div>`;
}

function togglePanel() {
  panelOpen = !panelOpen;
  if (panelEl) {
    if (panelOpen) {
      panelEl.classList.add('open');
      if (!isTargetPage) loadGuideUrls();
      else fetchFullStatus();
    } else {
      panelEl.classList.remove('open');
    }
  }
}

function loadGuideUrls() {
  chrome.runtime.sendMessage({ type: 'GET_TARGET_URLS' }, (r) => {
    if (chrome.runtime.lastError || !r) return;
    const idxBtn = panelEl?.querySelector('#ala-goto-index') as HTMLAnchorElement | null;
    const cfgBtn = panelEl?.querySelector('#ala-goto-config') as HTMLAnchorElement | null;
    if (idxBtn && r.indexUrl) { idxBtn.href = r.indexUrl; idxBtn.target = '_self'; }
    if (cfgBtn && r.configUrl) { cfgBtn.href = r.configUrl; cfgBtn.target = '_self'; }
  });
}

function fetchFullStatus() {
  chrome.runtime.sendMessage({ type: 'GET_FULL_STATUS' }, (r) => {
    if (chrome.runtime.lastError || !r?.success) return;
    const ms = r.modelState || {};
    const cfg = r.config || {};
    if (ms.isLoaded) {
      setDotColor('green');
      setStatusText('\u6a21\u578b: ' + (ms.modelId || cfg.modelId || '?') + ' \u2014 \u5df2\u52a0\u8f7d (\u6269\u5c55\u6258\u7ba1)');
    } else if (ms.isLoading) {
      setDotColor('yellow');
      setStatusText('\u6a21\u578b: ' + (cfg.modelId || '?') + ' \u2014 \u52a0\u8f7d\u4e2d...');
    } else if (cfg.modelId && cfg.enabled) {
      setDotColor('gray');
      setStatusText('\u6a21\u578b: ' + cfg.modelId + ' \u2014 \u5f85\u52a0\u8f7d');
    } else {
      setDotColor('gray');
      setStatusText('\u672a\u914d\u7f6e\u6a21\u578b');
    }
  });
  chrome.runtime.sendMessage({ type: 'GET_PROGRESS_CACHE' }, (r) => {
    if (chrome.runtime.lastError || !r?.logs) return;
    r.logs.forEach((log: any) => appendProgressToPanel(log));
  });
}

function setDotColor(color: 'green' | 'yellow' | 'red' | 'gray') {
  const cls = color === 'green' ? 'green' : color === 'yellow' ? 'yellow' : color === 'red' ? 'red' : '';
  if (statusDot) statusDot.className = 'ala-dot ' + cls;
  const panelDot = panelEl?.querySelector('#ala-panel-dot') as HTMLElement | null;
  if (panelDot) {
    const bg = color === 'green' ? '#22c55e' : color === 'yellow' ? '#eab308' : color === 'red' ? '#ef4444' : '#9ca3af';
    panelDot.style.background = bg;
  }
}

function setStatusText(text: string) {
  if (statusLine) statusLine.textContent = text;
}

function updateFloatingBtnStatus(event: string, data: any) {
  data = data || {};
  currentModelStatus = { event, data };
  switch (event) {
    case 'loaded':
      setDotColor('green');
      setStatusText('\u6a21\u578b: ' + (data.modelId || '?') + ' \u2014 \u5df2\u52a0\u8f7d (\u6269\u5c55\u6258\u7ba1)');
      break;
    case 'loading':
      setDotColor('yellow');
      setStatusText('\u6a21\u578b: ' + (data.modelId || '?') + ' \u2014 \u52a0\u8f7d\u4e2d...');
      break;
    case 'unloaded':
      setDotColor('gray');
      setStatusText('\u6a21\u578b\u5df2\u5378\u8f7d');
      break;
    case 'load_error':
      setDotColor('red');
      setStatusText('\u52a0\u8f7d\u5931\u8d25: ' + (data.error || 'Unknown'));
      break;
    case 'status':
      if (data.isLoaded) { setDotColor('green'); setStatusText('\u6a21\u578b: ' + (data.modelId || '?') + ' \u2014 \u5df2\u52a0\u8f7d'); }
      else if (data.isLoading) { setDotColor('yellow'); setStatusText('\u52a0\u8f7d\u4e2d...'); }
      else { setDotColor('gray'); setStatusText('\u5f85\u673a'); }
      break;
  }
}

let progressHasContent = false;
function appendProgressToPanel(log: any) {
  if (!logContainer || !isTargetPage) return;
  if (!progressHasContent) {
    logContainer.innerHTML = '';
    progressHasContent = true;
  }
  const entry = document.createElement('div');
  entry.className = 'ala-log-entry ' + (log.level || 'info');
  const time = log.time || new Date().toLocaleTimeString();
  entry.textContent = '[' + time + '] ' + (log.message || JSON.stringify(log));
  logContainer.appendChild(entry);
  logContainer.scrollTop = logContainer.scrollHeight;
}

// ==================== 注入时机 ====================
function tryInject() {
  if (document.body) {
    injectFloatingButton();
  } else {
    document.addEventListener('DOMContentLoaded', injectFloatingButton);
  }
}

if (window === window.top) {
  tryInject();
}
