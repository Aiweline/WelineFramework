(function () {
  "use strict";

  var EXTENSION_ID = chrome.runtime.id;
  var LOG_PREFIX = "[AutoLeadAgent:Content]";
  var EXT_VERSION = chrome.runtime.getManifest().version;

  console.log(LOG_PREFIX, "content script loaded, extension ID:", EXTENSION_ID);

  // ==================== 页面类型检测 ====================
  var TARGET_PATTERNS = [
    /\/auto-lead-agent\/backend\/config/i,
    /\/auto-lead-agent\/backend\/index/i,
  ];
  var isTargetPage = TARGET_PATTERNS.some(function (p) { return p.test(location.pathname); });
  var pageType = /\/config/i.test(location.pathname) ? "config" : (/\/index/i.test(location.pathname) ? "index" : "other");

  // ==================== 主动广播扩展已就绪 ====================
  var readyPayload = { type: "AUTOLEADAGENT_READY", extensionId: EXTENSION_ID, version: EXT_VERSION };
  window.postMessage(readyPayload, "*");
  setTimeout(function () { window.postMessage(readyPayload, "*"); }, 500);
  setTimeout(function () { window.postMessage(readyPayload, "*"); }, 1500);

  // 通知 background 页面进入/离开
  if (isTargetPage) {
    chrome.runtime.sendMessage({ type: "PAGE_ENTER", url: location.href }, function () {
      if (chrome.runtime.lastError) { /* ignore */ }
    });
    // 页面卸载时通知离开
    window.addEventListener("beforeunload", function () {
      chrome.runtime.sendMessage({ type: "PAGE_LEAVE" }, function () {});
    });
  }

  // ==================== 页面 → 扩展 消息中继 ====================
  window.addEventListener("message", function (event) {
    if (event.source !== window) return;
    var data = event.data;
    if (!data || typeof data !== "object") return;

    // 发现扩展请求
    if (data.type === "DISCOVER_AUTOLEADAGENT_EXTENSION" || data.type === "DISCOVER_EXTENSION") {
      var dp = { extensionId: EXTENSION_ID, version: EXT_VERSION };
      window.postMessage(Object.assign({ type: "AUTOLEADAGENT_DISCOVERED" }, dp), "*");
      window.postMessage(Object.assign({ type: "EXTENSION_DISCOVERED" }, dp), "*");
      window.postMessage(Object.assign({ type: "AUTOLEADAGENT_EXTENSION_FOUND" }, dp), "*");
      return;
    }

    // 通用请求中继
    if (data.type === "AUTOLEADAGENT_REQUEST" && data.payload) {
      var payload = data.payload;
      var requestId = data.requestId || "req_" + Date.now();
      chrome.runtime.sendMessage(
        { type: payload.type || data.action, payload: payload, requestId: requestId },
        function (response) {
          window.postMessage({ type: "AUTOLEADAGENT_RESPONSE", requestId: requestId, response: response || null, error: chrome.runtime.lastError ? chrome.runtime.lastError.message : null }, "*");
        }
      );
      return;
    }

    // PING
    if (data.type === "PING_AUTOLEADAGENT") {
      chrome.runtime.sendMessage({ type: "PING" }, function (response) {
        window.postMessage({ type: "PONG_AUTOLEADAGENT", success: !chrome.runtime.lastError, extensionId: EXTENSION_ID, version: EXT_VERSION, response: response || null }, "*");
      });
      return;
    }

    // MODEL 请求透传
    if (data.type === "MODEL_INFERENCE_REQUEST") {
      var iid = data.inferenceId || "inf_" + Date.now();
      chrome.runtime.sendMessage({ type: "MODEL_INFERENCE", prompt: data.prompt, options: data.options || {}, inferenceId: iid }, function (r) {
        window.postMessage({ type: "MODEL_INFERENCE_RESPONSE", inferenceId: iid, response: r || null, error: chrome.runtime.lastError ? chrome.runtime.lastError.message : null }, "*");
      });
      return;
    }
    if (data.type === "MODEL_STATUS_REQUEST") {
      chrome.runtime.sendMessage({ type: "MODEL_STATUS" }, function (r) {
        window.postMessage({ type: "MODEL_STATUS_RESPONSE", response: r || null, error: chrome.runtime.lastError ? chrome.runtime.lastError.message : null }, "*");
      });
      return;
    }
    if (data.type === "MODEL_LOAD_REQUEST") {
      chrome.runtime.sendMessage({ type: "MODEL_LOAD", modelId: data.modelId }, function (r) {
        window.postMessage({ type: "MODEL_LOAD_RESPONSE", response: r || null, error: chrome.runtime.lastError ? chrome.runtime.lastError.message : null }, "*");
      });
      return;
    }
    if (data.type === "MODEL_UNLOAD_REQUEST") {
      chrome.runtime.sendMessage({ type: "MODEL_UNLOAD" }, function (r) {
        window.postMessage({ type: "MODEL_UNLOAD_RESPONSE", response: r || null, error: chrome.runtime.lastError ? chrome.runtime.lastError.message : null }, "*");
      });
      return;
    }

    // MODEL_SAVE_CONFIG 转发
    if (data.type === "MODEL_SAVE_CONFIG") {
      chrome.runtime.sendMessage({ type: "MODEL_SAVE_CONFIG", payload: data.payload || data }, function (r) {
        window.postMessage({ type: "MODEL_SAVE_CONFIG_RESPONSE", response: r || null, error: chrome.runtime.lastError ? chrome.runtime.lastError.message : null }, "*");
      });
      return;
    }

    // TASK_PROGRESS：先本地显示到浮动面板，再转发到 background
    if (data.type === "TASK_PROGRESS") {
      var progressLog = data.log || data.payload || {};
      appendProgressToPanel(progressLog);
      chrome.runtime.sendMessage({ type: "TASK_PROGRESS", log: progressLog }, function () {
        if (chrome.runtime.lastError) { /* ignore */ }
      });
      return;
    }
  });

  // ==================== 扩展 → 页面 事件推送 ====================
  chrome.runtime.onMessage.addListener(function (message, sender, sendResponse) {
    if (!message) return false;
    if (message.type === "MODEL_EVENT") {
      window.postMessage({ type: "AUTOLEADAGENT_MODEL_EVENT", event: message.event, data: message.data }, "*");
      // 更新浮动按钮状态
      updateFloatingBtnStatus(message.event, message.data);
      sendResponse({ received: true });
    }
    if (message.type === "MODEL_LOAD_PROGRESS") {
      // 模型下载/加载进度 → 转发到页面
      window.postMessage({ type: "AUTOLEADAGENT_MODEL_LOAD_PROGRESS", payload: message.payload || {} }, "*");
      sendResponse({ received: true });
    }
    if (message.type === "TASK_PROGRESS") {
      window.postMessage({ type: "AUTOLEADAGENT_TASK_PROGRESS", log: message.log }, "*");
      appendProgressToPanel(message.log);
      sendResponse({ received: true });
    }
    if (message.type === "TASK_PROGRESS_BATCH") {
      if (message.logs && message.logs.length) {
        message.logs.forEach(function (log) { appendProgressToPanel(log); });
      }
      sendResponse({ received: true });
    }
    // 流式推理 token 转发到页面 + 浮动面板实时显示
    if (message.type === "AUTOLEADAGENT_INFERENCE_TOKEN") {
      window.postMessage({
        type: "MODEL_INFERENCE_TOKEN",
        inferenceId: message.inferenceId || '',
        token: message.token || '',
        fullText: message.fullText || ''
      }, "*");
      // 同时在浮动面板实时展示 AI 推理过程
      appendStreamTokenToPanel(message.token || '');
      sendResponse({ received: true });
    }
    return false;
  });

  // ==================== 浮动按钮 UI（Shadow DOM 隔离） ====================

  // 状态
  var panelOpen = false;
  var currentModelStatus = { event: "unknown", data: {} };
  var shadowRoot = null;
  var panelEl = null;
  var logContainer = null;
  var statusDot = null;
  var statusLine = null;

  function injectFloatingButton() {
    // 避免重复注入
    if (document.getElementById("ala-floating-host")) return;

    var host = document.createElement("div");
    host.id = "ala-floating-host";
    host.style.cssText = "position:fixed;top:0;left:0;width:0;height:0;z-index:2147483647;pointer-events:none;";
    document.body.appendChild(host);

    shadowRoot = host.attachShadow({ mode: "closed" });

    // CSS
    var style = document.createElement("style");
    style.textContent = buildCSS();
    shadowRoot.appendChild(style);

    // 按钮
    var btn = document.createElement("div");
    btn.className = "ala-btn";
    btn.innerHTML =
      '<svg class="ala-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' +
      '<path d="M12 2a4 4 0 0 1 4 4v1h1a3 3 0 0 1 3 3v2a3 3 0 0 1-3 3h-1v1a4 4 0 0 1-8 0v-1H7a3 3 0 0 1-3-3v-2a3 3 0 0 1 3-3h1V6a4 4 0 0 1 4-4z"/>' +
      '<circle cx="9" cy="10" r="1" fill="currentColor"/>' +
      '<circle cx="15" cy="10" r="1" fill="currentColor"/>' +
      '<path d="M9 14h6"/>' +
      '</svg>' +
      '<span class="ala-label">\u5bfb\u5ba2\u52a9\u624b</span>' +
      '<span class="ala-dot" id="ala-dot"></span>';
    btn.addEventListener("click", togglePanel);
    shadowRoot.appendChild(btn);

    statusDot = shadowRoot.getElementById("ala-dot");

    // 面板
    panelEl = document.createElement("div");
    panelEl.className = "ala-panel";
    panelEl.innerHTML = buildPanelHTML();
    shadowRoot.appendChild(panelEl);

    // 面板内事件绑定
    var closeBtn = panelEl.querySelector("#ala-close");
    if (closeBtn) closeBtn.addEventListener("click", togglePanel);
    logContainer = panelEl.querySelector("#ala-logs");
    statusLine = panelEl.querySelector("#ala-model-status-line");

    if (isTargetPage) {
      // 「测试控制台」按钮 → 通知页面打开配置页的测试弹窗
      var consoleBtn = panelEl.querySelector("#ala-open-console");
      if (consoleBtn) consoleBtn.addEventListener("click", function () {
        window.postMessage({ type: "ALA_OPEN_TEST_CONSOLE" }, "*");
      });

      // 底部命令输入栏
      var cmdInput = panelEl.querySelector("#ala-cmd-input");
      var cmdSend = panelEl.querySelector("#ala-cmd-send");
      function sendCmd() {
        var val = cmdInput ? cmdInput.value.trim() : "";
        if (!val) return;
        // 向页面发送测试命令
        window.postMessage({ type: "ALA_EXECUTE_COMMAND", command: val }, "*");
        appendProgressToPanel({ time: new Date().toLocaleTimeString(), message: "$ " + val, level: "info" });
        if (cmdInput) cmdInput.value = "";
      }
      if (cmdSend) cmdSend.addEventListener("click", sendCmd);
      if (cmdInput) cmdInput.addEventListener("keydown", function (e) {
        if (e.key === "Enter") sendCmd();
      });
    } else {
      // 非目标页：URL 导入
      var urlInput = panelEl.querySelector("#ala-url-input");
      var urlGo = panelEl.querySelector("#ala-url-go");
      if (urlGo) urlGo.addEventListener("click", function () {
        var url = urlInput ? urlInput.value.trim() : "";
        if (!url) return;
        // 获取寻客任务页 URL，拼上 URL 参数跳转过去
        chrome.runtime.sendMessage({ type: "GET_TARGET_URLS" }, function (r) {
          if (chrome.runtime.lastError || !r) return;
          var target = r.indexUrl || r.configUrl;
          if (target) {
            var sep = target.includes("?") ? "&" : "?";
            window.location.href = target + sep + "import_url=" + encodeURIComponent(url);
          } else {
            // 没有保存过目标 URL，提示用户先打开配置页
            if (urlInput) urlInput.placeholder = "\u8bf7\u5148\u6253\u5f00\u914d\u7f6e\u9875\u6216\u5bfb\u5ba2\u9875...";
          }
        });
      });
      if (urlInput) urlInput.addEventListener("keydown", function (e) {
        if (e.key === "Enter" && urlGo) urlGo.click();
      });
    }

    // 初始状态获取
    fetchFullStatus();
  }

  function buildCSS() {
    return '' +
      '*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }' +
      '.ala-btn {' +
      '  pointer-events: auto; cursor: pointer; position: fixed; right: 12px; top: 50%;' +
      '  transform: translateY(-50%); display: flex; align-items: center; gap: 6px;' +
      '  background: rgba(37,99,235,0.92); color: #fff; padding: 8px 14px 8px 10px;' +
      '  border-radius: 24px; font-family: -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;' +
      '  font-size: 13px; font-weight: 500; box-shadow: 0 2px 12px rgba(0,0,0,0.25);' +
      '  transition: all 0.2s ease; user-select: none; white-space: nowrap; z-index: 1;' +
      '}' +
      '.ala-btn:hover { background: rgba(29,78,216,0.96); box-shadow: 0 4px 20px rgba(0,0,0,0.3); transform: translateY(-50%) scale(1.03); }' +
      '.ala-icon { width: 18px; height: 18px; flex-shrink: 0; }' +
      '.ala-label { line-height: 1; }' +
      '.ala-dot {' +
      '  width: 8px; height: 8px; border-radius: 50%; flex-shrink: 0;' +
      '  background: #9ca3af; transition: background 0.3s;' +
      '}' +
      '.ala-dot.green { background: #22c55e; box-shadow: 0 0 6px #22c55e; }' +
      '.ala-dot.yellow { background: #eab308; box-shadow: 0 0 6px #eab308; animation: ala-pulse 1.5s infinite; }' +
      '.ala-dot.red { background: #ef4444; }' +
      '@keyframes ala-pulse { 0%,100% { opacity:1; } 50% { opacity:0.4; } }' +
      '' +
      '.ala-panel {' +
      '  pointer-events: auto; position: fixed; right: -360px; top: 50%; transform: translateY(-50%);' +
      '  width: 340px; max-height: 70vh; background: #1e1e2e; color: #cdd6f4; border-radius: 12px;' +
      '  box-shadow: 0 8px 32px rgba(0,0,0,0.4); font-family: -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;' +
      '  font-size: 13px; display: flex; flex-direction: column; transition: right 0.3s ease; z-index: 2;' +
      '  overflow: hidden;' +
      '}' +
      '.ala-panel.open { right: 12px; }' +
      '' +
      '.ala-panel-header {' +
      '  display: flex; align-items: center; justify-content: space-between; padding: 12px 14px;' +
      '  background: #313244; border-bottom: 1px solid #45475a; flex-shrink: 0;' +
      '}' +
      '.ala-panel-title { font-weight: 600; font-size: 14px; }' +
      '.ala-close-btn { background: none; border: none; color: #a6adc8; cursor: pointer; font-size: 18px; line-height: 1; padding: 2px 6px; border-radius: 4px; }' +
      '.ala-close-btn:hover { background: #45475a; color: #cdd6f4; }' +
      '' +
      '.ala-status-card {' +
      '  padding: 10px 14px; background: #181825; border-bottom: 1px solid #313244; flex-shrink: 0;' +
      '}' +
      '.ala-status-row { display: flex; align-items: center; gap: 8px; font-size: 12px; }' +
      '.ala-status-dot { width: 6px; height: 6px; border-radius: 50%; flex-shrink: 0; }' +
      '' +
      '.ala-log-area {' +
      '  flex: 1; overflow-y: auto; padding: 8px 12px; min-height: 100px;' +
      '  font-family: "Consolas","Monaco","Courier New",monospace; font-size: 12px; line-height: 1.6;' +
      '}' +
      '.ala-log-entry { padding: 1px 0; word-break: break-all; }' +
      '.ala-log-entry.info { color: #89b4fa; }' +
      '.ala-log-entry.warn { color: #f9e2af; }' +
      '.ala-log-entry.error { color: #f38ba8; }' +
      '.ala-log-entry.success { color: #a6e3a1; }' +
      '' +
      '.ala-guide {' +
      '  padding: 20px 14px; text-align: center;' +
      '}' +
      '.ala-guide p { color: #a6adc8; margin-bottom: 14px; font-size: 13px; }' +
      '.ala-guide-btn {' +
      '  display: block; width: 100%; padding: 10px; margin-bottom: 8px; border: 1px solid #45475a;' +
      '  background: #313244; color: #cdd6f4; border-radius: 8px; cursor: pointer; font-size: 13px;' +
      '  text-decoration: none; text-align: center; transition: background 0.15s;' +
      '}' +
      '.ala-guide-btn:hover { background: #45475a; }' +
      '.ala-empty { color: #585b70; text-align: center; padding: 24px 0; font-size: 12px; }' +
      '' +
      '.ala-actions { padding: 8px 14px; border-bottom: 1px solid #313244; flex-shrink: 0; display: flex; gap: 6px; }' +
      '.ala-action-btn {' +
      '  flex: 1; padding: 7px 10px; background: #313244; border: 1px solid #45475a; border-radius: 6px;' +
      '  color: #cdd6f4; cursor: pointer; font-size: 12px; text-align: center; transition: background 0.15s;' +
      '}' +
      '.ala-action-btn:hover { background: #45475a; }' +
      '' +
      '.ala-cmd-bar {' +
      '  padding: 8px 12px; background: #313244; border-top: 1px solid #45475a; flex-shrink: 0;' +
      '  display: flex; align-items: center; gap: 6px;' +
      '}' +
      '.ala-cmd-prompt { color: #89b4fa; font-family: monospace; font-size: 13px; flex-shrink: 0; }' +
      '.ala-cmd-input {' +
      '  flex: 1; background: #1e1e2e; border: 1px solid #45475a; border-radius: 4px;' +
      '  padding: 5px 8px; color: #cdd6f4; font-family: monospace; font-size: 12px; outline: none;' +
      '}' +
      '.ala-cmd-input:focus { border-color: #89b4fa; }' +
      '.ala-cmd-send {' +
      '  background: #89b4fa; border: none; border-radius: 4px; padding: 5px 10px;' +
      '  color: #1e1e2e; cursor: pointer; font-weight: 600; font-size: 13px; flex-shrink: 0;' +
      '}' +
      '.ala-cmd-send:hover { background: #74c7ec; }' +
      '' +
      '.ala-url-row { display: flex; gap: 6px; }' +
      '.ala-url-input {' +
      '  flex: 1; background: #1e1e2e; border: 1px solid #45475a; border-radius: 6px;' +
      '  padding: 8px 10px; color: #cdd6f4; font-size: 12px; outline: none;' +
      '}' +
      '.ala-url-input:focus { border-color: #89b4fa; }' +
      '.ala-url-go {' +
      '  background: #89b4fa; border: none; border-radius: 6px; padding: 8px 14px;' +
      '  color: #1e1e2e; cursor: pointer; font-weight: 600; font-size: 12px; flex-shrink: 0;' +
      '}' +
      '.ala-url-go:hover { background: #74c7ec; }' +
      '.ala-log-area::-webkit-scrollbar { width: 6px; }' +
      '.ala-log-area::-webkit-scrollbar-track { background: #181825; border-radius: 3px; }' +
      '.ala-log-area::-webkit-scrollbar-thumb { background: #45475a; border-radius: 3px; }' +
      '.ala-log-area::-webkit-scrollbar-thumb:hover { background: #585b70; }' +
      '';
  }

  function buildPanelHTML() {
    if (isTargetPage) {
      return '' +
        '<div class="ala-panel-header">' +
        '  <span class="ala-panel-title">\u5bfb\u5ba2\u52a9\u624b</span>' +
        '  <button class="ala-close-btn" id="ala-close">\u00d7</button>' +
        '</div>' +
        '<div class="ala-status-card">' +
        '  <div class="ala-status-row">' +
        '    <span class="ala-status-dot" id="ala-panel-dot" style="background:#9ca3af;"></span>' +
        '    <span id="ala-model-status-line">\u6b63\u5728\u83b7\u53d6\u6a21\u578b\u72b6\u6001...</span>' +
        '  </div>' +
        '</div>' +
        '<div class="ala-actions">' +
        '  <button class="ala-action-btn" id="ala-open-console">\ud83d\udda5\ufe0f \u6d4b\u8bd5\u63a7\u5236\u53f0</button>' +
        '</div>' +
        '<div class="ala-log-area" id="ala-logs">' +
        '  <div class="ala-empty">\u6682\u65e0\u4efb\u52a1\u8fdb\u5ea6</div>' +
        '</div>' +
        '<div class="ala-cmd-bar">' +
        '  <span class="ala-cmd-prompt">$</span>' +
        '  <input type="text" id="ala-cmd-input" class="ala-cmd-input" placeholder="\u8f93\u5165\u6307\u4ee4\u6d4b\u8bd5\uff0c\u5982\u201c\u8bbf\u95ee google.com\u201d" />' +
        '  <button id="ala-cmd-send" class="ala-cmd-send">\u2192</button>' +
        '</div>';
    } else {
      return '' +
        '<div class="ala-panel-header">' +
        '  <span class="ala-panel-title">\u5bfb\u5ba2\u52a9\u624b</span>' +
        '  <button class="ala-close-btn" id="ala-close">\u00d7</button>' +
        '</div>' +
        '<div class="ala-status-card">' +
        '  <div class="ala-status-row">' +
        '    <span class="ala-status-dot" id="ala-panel-dot" style="background:#9ca3af;"></span>' +
        '    <span id="ala-model-status-line">\u6b63\u5728\u83b7\u53d6\u72b6\u6001...</span>' +
        '  </div>' +
        '</div>' +
        '<div class="ala-guide">' +
        '  <p>\u5bfb\u5ba2\u529f\u80fd\u9700\u5728\u4e13\u5c5e\u9875\u9762\u8fd0\u884c\uff1a</p>' +
        '  <a class="ala-guide-btn" id="ala-goto-index">\u2192 \u5bfb\u5ba2\u4efb\u52a1\u9875</a>' +
        '  <a class="ala-guide-btn" id="ala-goto-config">\u2192 \u914d\u7f6e\u9875</a>' +
        '  <div class="ala-url-import">' +
        '    <p style="margin-top:14px;margin-bottom:6px;color:#a6adc8;font-size:12px;">\u6216\u8f93\u5165\u94fe\u63a5\u5bfc\u5165\u5bfb\u5ba2\u9875\u9762\uff1a</p>' +
        '    <div class="ala-url-row">' +
        '      <input type="text" id="ala-url-input" class="ala-url-input" placeholder="https://..." />' +
        '      <button id="ala-url-go" class="ala-url-go">\u5bfc\u5165</button>' +
        '    </div>' +
        '  </div>' +
        '</div>';
    }
  }

  function togglePanel() {
    panelOpen = !panelOpen;
    if (panelEl) {
      if (panelOpen) {
        panelEl.classList.add("open");
        fetchFullStatus();
        if (!isTargetPage) loadGuideUrls();
      } else {
        panelEl.classList.remove("open");
      }
    }
  }

  // 获取引导 URL
  function loadGuideUrls() {
    chrome.runtime.sendMessage({ type: "GET_TARGET_URLS" }, function (r) {
      if (chrome.runtime.lastError || !r) return;
      var idxBtn = panelEl && panelEl.querySelector("#ala-goto-index");
      var cfgBtn = panelEl && panelEl.querySelector("#ala-goto-config");
      if (idxBtn && r.indexUrl) { idxBtn.href = r.indexUrl; idxBtn.target = "_self"; }
      if (cfgBtn && r.configUrl) { cfgBtn.href = r.configUrl; cfgBtn.target = "_self"; }
    });
  }

  // 获取完整状态
  function fetchFullStatus() {
    chrome.runtime.sendMessage({ type: "GET_FULL_STATUS" }, function (r) {
      if (chrome.runtime.lastError || !r || !r.success) return;
      var ms = r.modelState || {};
      var cfg = r.config || {};
      if (ms.isLoaded) {
        setDotColor("green");
        setStatusText("\u6a21\u578b: " + (ms.modelId || cfg.modelId || "?") + " \u2014 \u5df2\u52a0\u8f7d (\u6269\u5c55\u6258\u7ba1)");
      } else if (ms.isLoading) {
        setDotColor("yellow");
        setStatusText("\u6a21\u578b: " + (cfg.modelId || "?") + " \u2014 \u52a0\u8f7d\u4e2d...");
      } else if (cfg.modelId && cfg.enabled) {
        setDotColor("gray");
        setStatusText("\u6a21\u578b: " + cfg.modelId + " \u2014 \u5f85\u52a0\u8f7d");
      } else {
        setDotColor("gray");
        setStatusText("\u672a\u914d\u7f6e\u6a21\u578b");
      }
    });
    // 加载历史进度
    chrome.runtime.sendMessage({ type: "GET_PROGRESS_CACHE" }, function (r) {
      if (chrome.runtime.lastError || !r || !r.logs) return;
      r.logs.forEach(function (log) { appendProgressToPanel(log); });
    });
  }

  function setDotColor(color) {
    var cls = color === "green" ? "green" : color === "yellow" ? "yellow" : color === "red" ? "red" : "";
    if (statusDot) { statusDot.className = "ala-dot " + cls; }
    var panelDot = panelEl && panelEl.querySelector("#ala-panel-dot");
    if (panelDot) {
      var bg = color === "green" ? "#22c55e" : color === "yellow" ? "#eab308" : color === "red" ? "#ef4444" : "#9ca3af";
      panelDot.style.background = bg;
    }
  }

  function setStatusText(text) {
    if (statusLine) statusLine.textContent = text;
  }

  function updateFloatingBtnStatus(event, data) {
    data = data || {};
    currentModelStatus = { event: event, data: data };
    switch (event) {
      case "loaded":
        setDotColor("green");
        setStatusText("\u6a21\u578b: " + (data.modelId || "?") + " \u2014 \u5df2\u52a0\u8f7d (\u6269\u5c55\u6258\u7ba1)");
        break;
      case "loading":
        setDotColor("yellow");
        setStatusText("\u6a21\u578b: " + (data.modelId || "?") + " \u2014 \u52a0\u8f7d\u4e2d...");
        break;
      case "unloaded":
        setDotColor("gray");
        setStatusText("\u6a21\u578b\u5df2\u5378\u8f7d");
        break;
      case "load_error":
        setDotColor("red");
        setStatusText("\u52a0\u8f7d\u5931\u8d25: " + (data.error || "Unknown"));
        break;
      case "status":
        if (data.isLoaded) { setDotColor("green"); setStatusText("\u6a21\u578b: " + (data.modelId || "?") + " \u2014 \u5df2\u52a0\u8f7d"); }
        else if (data.isLoading) { setDotColor("yellow"); setStatusText("\u52a0\u8f7d\u4e2d..."); }
        else { setDotColor("gray"); setStatusText("\u5f85\u673a"); }
        break;
    }
  }

  var progressHasContent = false;
  function appendProgressToPanel(log) {
    if (!logContainer || !isTargetPage) return;
    if (!progressHasContent) {
      logContainer.innerHTML = "";
      progressHasContent = true;
    }
    // 新日志到来时，结束上一个流式输出块
    finishStreamBlock();
    var entry = document.createElement("div");
    entry.className = "ala-log-entry " + (log.level || "info");
    var time = log.time || new Date().toLocaleTimeString();
    entry.textContent = "[" + time + "] " + (log.message || JSON.stringify(log));
    logContainer.appendChild(entry);
    logContainer.scrollTop = logContainer.scrollHeight;
  }

  // 流式 token 输出块（浮动面板内 AI 推理可视化）
  var currentStreamBlock = null;
  var streamIdleTimer = null;

  function appendStreamTokenToPanel(token) {
    if (!logContainer) return;
    // 创建或复用流式块
    if (!currentStreamBlock) {
      if (!progressHasContent) { logContainer.innerHTML = ""; progressHasContent = true; }
      currentStreamBlock = document.createElement("div");
      currentStreamBlock.className = "ala-log-entry ala-stream-block";
      currentStreamBlock.style.cssText = "border-left:2px solid #89b4fa;padding-left:6px;margin:4px 0;white-space:pre-wrap;word-break:break-all;color:#a6adc8;font-size:11px;line-height:1.5;";
      var label = document.createElement("span");
      label.style.cssText = "color:#89b4fa;font-weight:bold;font-size:10px;display:block;margin-bottom:2px;";
      label.textContent = "\ud83e\udd16 AI \u63a8\u7406\u4e2d...";
      currentStreamBlock.appendChild(label);
      var textEl = document.createElement("span");
      textEl.className = "ala-stream-text";
      currentStreamBlock.appendChild(textEl);
      logContainer.appendChild(currentStreamBlock);
    }
    var textEl = currentStreamBlock.querySelector(".ala-stream-text");
    if (textEl) textEl.textContent += token;
    logContainer.scrollTop = logContainer.scrollHeight;
    // 自动结束：如果 3 秒没有新 token，认为推理完成
    if (streamIdleTimer) clearTimeout(streamIdleTimer);
    streamIdleTimer = setTimeout(finishStreamBlock, 3000);
  }

  function finishStreamBlock() {
    if (streamIdleTimer) { clearTimeout(streamIdleTimer); streamIdleTimer = null; }
    if (currentStreamBlock) {
      currentStreamBlock.style.borderLeftColor = "#a6e3a1";
      var label = currentStreamBlock.querySelector("span");
      if (label && label.style.color === "rgb(137, 180, 250)") {
        label.textContent = "\u2705 AI \u63a8\u7406\u5b8c\u6210";
        label.style.color = "#a6e3a1";
      }
      currentStreamBlock = null;
    }
  }

  // ==================== 注入时机 ====================
  function tryInject() {
    if (document.body) {
      injectFloatingButton();
    } else {
      document.addEventListener("DOMContentLoaded", injectFloatingButton);
    }
  }

  // 仅在顶层 frame 注入
  if (window === window.top) {
    tryInject();
  }
})();
