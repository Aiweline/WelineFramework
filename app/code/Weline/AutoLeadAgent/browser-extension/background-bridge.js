/**
 * Background Bridge
 * 在原始 background.iife.js 之上添加：
 *   - PING 响应（扩展发现 + 连通性检测）
 *   - MODEL_INFERENCE / MODEL_LOAD / MODEL_UNLOAD / MODEL_STATUS 处理
 *   - 使用 Offscreen Document 进行本地模型推理
 *   - 页面感知：追踪目标页面（配置页/寻客任务页），自动加载/卸载模型
 *   - 模型配置持久化（chrome.storage.local）
 *   - 任务进度中继与缓存
 */

// ====== 先加载原始后台脚本（保留所有已有功能） ======
import "./background.iife.js";

// ====== 模型 ID 自动纠正 ======
const ONNX_MODEL_MAP = {
  'Qwen/Qwen3-0.6B':            'onnx-community/Qwen3-0.6B-ONNX',
  'Qwen/Qwen3-1.7B':            'onnx-community/Qwen3-1.7B-ONNX',
  'Qwen/Qwen3-4B':              'onnx-community/Qwen3-4B-ONNX',
  'Qwen/Qwen2.5-0.5B-Instruct': 'onnx-community/Qwen2.5-0.5B-Instruct',
  'Qwen/Qwen2.5-1.5B-Instruct': 'onnx-community/Qwen2.5-1.5B-Instruct',
  'meta-llama/Llama-3.2-1B-Instruct': 'onnx-community/Llama-3.2-1B-Instruct-ONNX',
  'google/gemma-3-270m-it':      'onnx-community/gemma-3-270m-it-ONNX',
  'HuggingFaceTB/SmolLM2-360M':  'onnx-community/SmolLM2-360M-ONNX',
};
function resolveOnnxModelId(id) {
  if (!id) return id;
  if (ONNX_MODEL_MAP[id]) { console.log("[BG] 自动纠正模型 ID:", id, "->", ONNX_MODEL_MAP[id]); return ONNX_MODEL_MAP[id]; }
  return id;
}

// ====== Offscreen Document 管理 ======
const OFFSCREEN_URL = "offscreen/offscreen.html";
let offscreenCreating = null;

async function ensureOffscreen() {
  if (typeof chrome.runtime.getContexts === "function") {
    const contexts = await chrome.runtime.getContexts({
      contextTypes: ["OFFSCREEN_DOCUMENT"],
      documentUrls: [chrome.runtime.getURL(OFFSCREEN_URL)],
    });
    if (contexts.length > 0) return;
  }
  if (offscreenCreating) { await offscreenCreating; return; }
  offscreenCreating = chrome.offscreen
    .createDocument({ url: OFFSCREEN_URL, reasons: ["WORKERS"], justification: "Run local AI model inference via Web Worker" })
    .catch((err) => { if (!String(err).includes("Only a single offscreen")) console.error("[BG] ensureOffscreen error:", err); })
    .finally(() => { offscreenCreating = null; });
  await offscreenCreating;
}

// 推理请求追踪：requestId → { tabId, inferenceId }（用于流式 token 转发）
const inferenceRequestMap = new Map();

function sendToOffscreen(msg, timeoutMs = 120000) {
  return new Promise(async (resolve, reject) => {
    await ensureOffscreen();
    const requestId = "bg_" + Date.now() + "_" + Math.random().toString(36).substr(2, 6);
    const timer = setTimeout(() => {
      chrome.runtime.onMessage.removeListener(handler);
      inferenceRequestMap.delete(requestId);
      reject(new Error("Offscreen request timeout"));
    }, timeoutMs);
    function handler(message) {
      if (message && message.target === "background" && message.requestId === requestId) {
        // 忽略 token 消息（由全局 token handler 处理），只在最终结果时 resolve
        if (message.type === "INFERENCE_TOKEN") return;
        chrome.runtime.onMessage.removeListener(handler);
        clearTimeout(timer);
        inferenceRequestMap.delete(requestId);
        resolve(message);
        return false;
      }
    }
    chrome.runtime.onMessage.addListener(handler);
    chrome.runtime.sendMessage({ target: "offscreen", requestId, ...msg });
    return requestId;
  });
}

/**
 * 使用指定 requestId 发送消息到 offscreen（用于推理请求，以便 token 能正确路由回来）
 */
function sendToOffscreenWithId(requestId, msg, timeoutMs = 120000) {
  return new Promise(async (resolve, reject) => {
    await ensureOffscreen();
    const timer = setTimeout(() => {
      chrome.runtime.onMessage.removeListener(handler);
      inferenceRequestMap.delete(requestId);
      reject(new Error("Offscreen request timeout"));
    }, timeoutMs);
    function handler(message) {
      if (message && message.target === "background" && message.requestId === requestId) {
        if (message.type === "INFERENCE_TOKEN") return; // token 由全局 handler 处理
        chrome.runtime.onMessage.removeListener(handler);
        clearTimeout(timer);
        resolve(message);
        return false;
      }
    }
    chrome.runtime.onMessage.addListener(handler);
    chrome.runtime.sendMessage({ target: "offscreen", requestId, ...msg });
  });
}

// 全局监听模型下载进度消息 → 广播到所有 tab（目标页面优先，也覆盖刚加入的）
chrome.runtime.onMessage.addListener((message) => {
  if (!message || message.target !== "background" || message.type !== "MODEL_LOAD_PROGRESS") return;
  const payload = message.payload || {};
  const msg = { type: "MODEL_LOAD_PROGRESS", payload };
  // 广播到所有 tab（确保刚打开的页面也能收到）
  chrome.tabs.query({}, function (tabs) {
    for (const tab of tabs) {
      if (tab.id) {
        chrome.tabs.sendMessage(tab.id, msg).catch(() => {});
      }
    }
  });
});

// 全局监听流式 token 消息（从 offscreen 转发）→ 发送到请求来源的 tab
chrome.runtime.onMessage.addListener((message) => {
  if (!message || message.target !== "background" || message.type !== "INFERENCE_TOKEN") return;
  const tracking = inferenceRequestMap.get(message.requestId);
  if (!tracking) return;
  // 转发 token 到请求来源的 tab
  try {
    chrome.tabs.sendMessage(tracking.tabId, {
      type: "AUTOLEADAGENT_INFERENCE_TOKEN",
      inferenceId: tracking.inferenceId,
      token: message.token || '',
      fullText: message.fullText || ''
    });
  } catch (_) {}
});

// ====== 页面追踪：目标页面检测 + 模型自动生命周期 ======
const TARGET_URL_PATTERNS = [
  /\/auto-lead-agent\/backend\/config/i,
  /\/auto-lead-agent\/backend\/index/i,
];
const targetTabs = new Map();        // tabId -> { url, timestamp }
const pendingLeaves = new Map();     // tabId -> timeoutId（离开宽限期）
let unloadTimer = null;              // 卸载倒计时
const UNLOAD_DELAY_MS = 30000;       // 两个页面都不存在 30s 后卸载
const LEAVE_GRACE_MS = 5000;         // 页面刷新宽限期 5s（避免 beforeunload 误触卸载）
const HEARTBEAT_INTERVAL_MS = 15000; // 每 15s 心跳检查一次目标页面是否存在
let heartbeatTimer = null;
let currentModelState = { modelId: null, isLoaded: false, isLoading: false };

function isTargetUrl(url) {
  if (!url) return false;
  return TARGET_URL_PATTERNS.some((p) => p.test(url));
}

/**
 * 目标页面出现 —— 只做状态同步，不重新加载模型
 * 模型是否加载由 autoLoadModel 在「首次从无到有」或「用户手动操作」时决定
 */
function onTargetPageEnter(tabId, url) {
  // 取消该 tab 的离开宽限期（页面刷新场景：beforeunload 后马上又 complete）
  if (pendingLeaves.has(tabId)) {
    clearTimeout(pendingLeaves.get(tabId));
    pendingLeaves.delete(tabId);
    console.log("[BG] Cancelled pending leave for tab:", tabId, "(page refresh)");
  }

  const wasEmpty = targetTabs.size === 0;
  targetTabs.set(tabId, { url, timestamp: Date.now() });
  console.log("[BG] Target page enter, tab:", tabId, "total:", targetTabs.size);

  // 保存 URL 到 storage（供非目标页面引导跳转）
  saveTargetPageUrl(url);

  // 清除 15s 卸载倒计时
  if (unloadTimer) {
    clearTimeout(unloadTimer);
    unloadTimer = null;
    console.log("[BG] Unload timer cleared — target page present");
  }

  // 首次从「无目标页面」到「有目标页面」，且模型未加载 → 尝试自动加载
  if (wasEmpty && !currentModelState.isLoaded && !currentModelState.isLoading) {
    autoLoadModel();
  }

  // 广播当前模型状态给新进入的页面（无论模型是否加载）
  broadcastModelEvent("status", currentModelState, tabId);
  // 发送缓存的进度日志
  if (progressLogCache.length > 0) {
    chrome.tabs.sendMessage(tabId, {
      type: "TASK_PROGRESS_BATCH",
      logs: progressLogCache,
    }).catch(() => {});
  }
}

/**
 * 目标页面离开 —— 使用宽限期防止页面刷新误判
 * 宽限期内如果同 tabId 再次 enter，视为刷新，不算离开
 */
function onTargetPageLeave(tabId) {
  // 如果已有宽限期在跑，先清掉重置
  if (pendingLeaves.has(tabId)) {
    clearTimeout(pendingLeaves.get(tabId));
  }

  console.log("[BG] Target page leave (pending), tab:", tabId, "— grace:", (LEAVE_GRACE_MS / 1000) + "s");

  const timer = setTimeout(() => {
    pendingLeaves.delete(tabId);
    targetTabs.delete(tabId);
    console.log("[BG] Target page confirmed leave, tab:", tabId, "remaining:", targetTabs.size);

    // 所有目标页面都走了 → 启动 15s 卸载倒计时
    if (targetTabs.size === 0 && currentModelState.isLoaded) {
      console.log("[BG] All target pages gone — starting " + (UNLOAD_DELAY_MS / 1000) + "s unload countdown");
      if (unloadTimer) clearTimeout(unloadTimer);
      unloadTimer = setTimeout(async () => {
        unloadTimer = null;
        if (targetTabs.size === 0 && currentModelState.isLoaded) {
          console.log("[BG] Unload countdown expired — unloading model");
          try {
            await sendToOffscreen({ type: "MODEL_UNLOAD" }, 10000);
            currentModelState = { modelId: null, isLoaded: false, isLoading: false };
            broadcastModelEvent("unloaded", {});
          } catch (e) {
            console.error("[BG] Auto-unload failed:", e);
          }
        }
      }, UNLOAD_DELAY_MS);
    }
  }, LEAVE_GRACE_MS);

  pendingLeaves.set(tabId, timer);
}

/**
 * Tab 被彻底关闭 —— 无宽限，直接移除
 */
function onTabRemoved(tabId) {
  // 取消宽限期
  if (pendingLeaves.has(tabId)) {
    clearTimeout(pendingLeaves.get(tabId));
    pendingLeaves.delete(tabId);
  }

  if (!targetTabs.has(tabId)) return;
  targetTabs.delete(tabId);
  console.log("[BG] Target tab CLOSED:", tabId, "remaining:", targetTabs.size);

  if (targetTabs.size === 0 && currentModelState.isLoaded) {
    console.log("[BG] All target pages gone (tab closed) — starting " + (UNLOAD_DELAY_MS / 1000) + "s unload countdown");
    if (unloadTimer) clearTimeout(unloadTimer);
    unloadTimer = setTimeout(async () => {
      unloadTimer = null;
      if (targetTabs.size === 0 && currentModelState.isLoaded) {
        console.log("[BG] Unload countdown expired — unloading model");
        try {
          await sendToOffscreen({ type: "MODEL_UNLOAD" }, 10000);
          currentModelState = { modelId: null, isLoaded: false, isLoading: false };
          broadcastModelEvent("unloaded", {});
        } catch (e) {
          console.error("[BG] Auto-unload failed:", e);
        }
      }
    }, UNLOAD_DELAY_MS);
  }
}

// 模型预估内存（MB），用于预检
const MODEL_SIZE_ESTIMATES = {
  'gemma-3-270m': 150, 'SmolLM2-360M': 200, 'Qwen2.5-0.5B': 300,
  'Qwen3-0.6B': 400, 'Llama-3.2-1B': 600, 'Qwen2.5-1.5B': 900,
};

// 自动加载模型（从 chrome.storage 读取配置，仅在首次需要时调用）
async function autoLoadModel() {
  try {
    const data = await chrome.storage.local.get(["ala_model_id", "ala_model_enabled"]);
    if (!data.ala_model_id || data.ala_model_enabled === false) {
      console.log("[BG] No model configured or disabled, skip auto-load");
      return;
    }
    const modelId = resolveOnnxModelId(data.ala_model_id);
    // 如果纠正了 ID，同步更新到 storage
    if (modelId !== data.ala_model_id) {
      chrome.storage.local.set({ ala_model_id: modelId });
    }

    // 内存预检（仅 Chromium 支持，可选）：在加载前估算所需内存
    let estimatedMB = 300;
    for (const [key, mb] of Object.entries(MODEL_SIZE_ESTIMATES)) {
      if (modelId && modelId.includes(key)) { estimatedMB = mb; break; }
    }
    if (typeof globalThis !== "undefined" && globalThis.performance?.memory) {
      const usedMB = globalThis.performance.memory.usedJSHeapSize / (1024 * 1024);
      const limitMB = globalThis.performance.memory.jsHeapSizeLimit / (1024 * 1024);
      const availableMB = limitMB - usedMB;
      const requiredMB = estimatedMB * 2.5;
      if (availableMB < requiredMB) {
        console.warn("[BG] 内存不足，跳过自动加载:", "可用", Math.round(availableMB), "MB, 需要约", Math.round(requiredMB), "MB");
        broadcastModelEvent("load_error", { error: `内存不足：需要约 ${Math.round(requiredMB)}MB，当前可用 ${Math.round(availableMB)}MB。请选择更小的模型或关闭其他标签页。` });
        return;
      }
    }

    console.log("[BG] Auto-loading model (first target page appeared):", modelId);
    currentModelState.isLoading = true;
    broadcastModelEvent("loading", { modelId });

    const res = await sendToOffscreen({ type: "MODEL_LOAD", modelId }, 300000);
    currentModelState = { modelId, isLoaded: true, isLoading: false };
    broadcastModelEvent("loaded", { modelId });
    console.log("[BG] Model auto-loaded:", modelId);
    startHeartbeat();
  } catch (err) {
    currentModelState.isLoading = false;
    console.error("[BG] Auto-load failed:", err);
    broadcastModelEvent("load_error", { error: err.message });
    // 加载失败后尝试卸载 offscreen 中的残留，释放内存
    try {
      await sendToOffscreen({ type: "MODEL_UNLOAD" }, 10000);
    } catch (_) { /* ignore */ }
  }
}

/**
 * 心跳检查：每 15s 扫描所有 tab，确认目标页面是否仍存在
 * 如果发现 targetTabs 中的 tab 已不存在或已导航离开 → 清理
 * 如果所有目标页面消失 → 启动 30s 卸载倒计时
 */
function startHeartbeat() {
  if (heartbeatTimer) return; // 已在运行
  console.log("[BG] Starting heartbeat (every " + (HEARTBEAT_INTERVAL_MS / 1000) + "s)");
  heartbeatTimer = setInterval(async () => {
    if (targetTabs.size === 0 && !currentModelState.isLoaded) {
      // 无目标页面且模型未加载 → 停止心跳
      stopHeartbeat();
      return;
    }
    try {
      const allTabs = await chrome.tabs.query({});
      const allTabIds = new Set(allTabs.map(t => t.id));
      let changed = false;

      // 检查 targetTabs 中的 tab 是否仍然存在且仍为目标 URL
      for (const [tabId] of targetTabs) {
        const tab = allTabs.find(t => t.id === tabId);
        if (!tab || !isTargetUrl(tab.url)) {
          targetTabs.delete(tabId);
          console.log("[BG] Heartbeat: removed stale tab:", tabId);
          changed = true;
        }
      }

      // 如果所有目标页面消失 → 启动卸载倒计时
      if (changed && targetTabs.size === 0 && currentModelState.isLoaded) {
        console.log("[BG] Heartbeat: no target pages found — starting " + (UNLOAD_DELAY_MS / 1000) + "s unload countdown");
        if (unloadTimer) clearTimeout(unloadTimer);
        unloadTimer = setTimeout(async () => {
          unloadTimer = null;
          if (targetTabs.size === 0 && currentModelState.isLoaded) {
            console.log("[BG] Heartbeat unload countdown expired — unloading model");
            try {
              await sendToOffscreen({ type: "MODEL_UNLOAD" }, 10000);
              currentModelState = { modelId: null, isLoaded: false, isLoading: false };
              broadcastModelEvent("unloaded", {});
              stopHeartbeat();
            } catch (e) {
              console.error("[BG] Heartbeat auto-unload failed:", e);
            }
          }
        }, UNLOAD_DELAY_MS);
      }
    } catch (e) {
      console.warn("[BG] Heartbeat error:", e.message);
    }
  }, HEARTBEAT_INTERVAL_MS);
}

function stopHeartbeat() {
  if (heartbeatTimer) {
    clearInterval(heartbeatTimer);
    heartbeatTimer = null;
    console.log("[BG] Heartbeat stopped");
  }
}

// 保存目标页面 URL
function saveTargetPageUrl(url) {
  try {
    const urlObj = new URL(url);
    const basePath = urlObj.origin + urlObj.pathname.replace(/\/auto-lead-agent\/backend\/.*$/, "");
    const configUrl = basePath + "/auto-lead-agent/backend/config";
    const indexUrl = basePath + "/auto-lead-agent/backend/index";
    chrome.storage.local.set({ ala_config_url: configUrl, ala_index_url: indexUrl });
  } catch (e) {}
}

// Tab 监听：检测页面变化（依赖 chrome.tabs API，比 content script 的 PAGE_ENTER 更可靠）
chrome.tabs.onUpdated.addListener(function (tabId, changeInfo, tab) {
  if (changeInfo.status !== "complete" || !tab.url) return;
  if (isTargetUrl(tab.url)) {
    onTargetPageEnter(tabId, tab.url);
  } else if (targetTabs.has(tabId)) {
    // 同一个 tab 导航到了非目标页面
    onTargetPageLeave(tabId);
  }
});

chrome.tabs.onRemoved.addListener(function (tabId) {
  onTabRemoved(tabId);
});

// 启动时扫描已打开的目标页面
chrome.tabs.query({}, function (tabs) {
  for (const tab of tabs) {
    if (tab.id && tab.url && isTargetUrl(tab.url)) {
      onTargetPageEnter(tab.id, tab.url);
    }
  }
});

// ====== 任务进度中继与缓存 ======
const MAX_PROGRESS_CACHE = 200;
let progressLogCache = [];

function addProgressLog(log) {
  progressLogCache.push(log);
  if (progressLogCache.length > MAX_PROGRESS_CACHE) {
    progressLogCache = progressLogCache.slice(-MAX_PROGRESS_CACHE);
  }
}

function broadcastProgress(log, excludeTabId) {
  addProgressLog(log);
  // 广播到所有目标页面
  for (const [tabId] of targetTabs) {
    if (tabId === excludeTabId) continue;
    chrome.tabs.sendMessage(tabId, { type: "TASK_PROGRESS", log }).catch(() => {});
  }
}

// ====== 消息处理（来自 content script） ======
chrome.runtime.onMessage.addListener(function (message, sender, sendResponse) {
  if (!message || typeof message.type !== "string") return false;
  if (message.target === "background") return false;

  const senderTabId = sender && sender.tab ? sender.tab.id : null;

  switch (message.type) {
    case "PING": {
      sendResponse({
        success: true,
        version: chrome.runtime.getManifest().version,
        features: ["model_inference", "browser_automation", "auto_lifecycle"],
      });
      return false;
    }

    // --- 页面进入/离开通知 ---
    case "PAGE_ENTER": {
      if (senderTabId && message.url) {
        onTargetPageEnter(senderTabId, message.url);
      }
      sendResponse({ success: true, modelState: currentModelState });
      return false;
    }
    case "PAGE_LEAVE": {
      if (senderTabId) onTargetPageLeave(senderTabId);
      sendResponse({ success: true });
      return false;
    }

    // --- 模型配置持久化 ---
    case "MODEL_SAVE_CONFIG": {
      const cfg = message.payload || message;
      const correctedModelId = resolveOnnxModelId(cfg.modelId) || null;
      chrome.storage.local.set({
        ala_model_id: correctedModelId,
        ala_model_enabled: cfg.enabled !== false,
      }, function () {
        console.log("[BG] Model config saved:", correctedModelId, "enabled:", cfg.enabled !== false);
        sendResponse({ success: true });
      });
      return true;
    }

    // --- 获取保存的页面 URL（供非目标页面引导跳转） ---
    case "GET_TARGET_URLS": {
      chrome.storage.local.get(["ala_config_url", "ala_index_url"], function (data) {
        sendResponse({
          success: true,
          configUrl: data.ala_config_url || null,
          indexUrl: data.ala_index_url || null,
        });
      });
      return true;
    }

    // --- 获取完整模型状态（含加载/就绪/配置信息） ---
    case "GET_FULL_STATUS": {
      chrome.storage.local.get(["ala_model_id", "ala_model_enabled"], function (data) {
        sendResponse({
          success: true,
          modelState: currentModelState,
          config: { modelId: data.ala_model_id || null, enabled: data.ala_model_enabled !== false },
          targetPagesCount: targetTabs.size,
        });
      });
      return true;
    }

    // --- 任务进度 ---
    case "TASK_PROGRESS": {
      broadcastProgress(message.log || message.payload, senderTabId);
      sendResponse({ success: true });
      return false;
    }
    case "GET_PROGRESS_CACHE": {
      sendResponse({ success: true, logs: progressLogCache });
      return false;
    }
    case "CLEAR_PROGRESS": {
      progressLogCache = [];
      sendResponse({ success: true });
      return false;
    }

    // --- 模型操作 ---
    case "MODEL_STATUS": {
      sendToOffscreen({ type: "MODEL_STATUS" }, 5000)
        .then((res) => {
          currentModelState = res.payload || currentModelState;
          sendResponse({ success: true, status: currentModelState });
        })
        .catch(() => sendResponse({ success: true, status: currentModelState }));
      return true;
    }

    case "MODEL_LOAD": {
      const rawModelId = message.modelId || (message.payload && message.payload.modelId);
      if (!rawModelId) { sendResponse({ success: false, error: "No modelId provided" }); return false; }
      const modelId = resolveOnnxModelId(rawModelId);

      currentModelState.isLoading = true;
      broadcastModelEvent("loading", { modelId });

      // 保存纠正后的模型 ID
      chrome.storage.local.set({ ala_model_id: modelId, ala_model_enabled: true });

      sendToOffscreen({ type: "MODEL_LOAD", modelId }, 300000)
        .then((res) => {
          currentModelState = { modelId, isLoaded: true, isLoading: false };
          sendResponse({ success: true, result: res.payload });
          broadcastModelEvent("loaded", { modelId });
          startHeartbeat();
        })
        .catch((err) => {
          currentModelState.isLoading = false;
          sendResponse({ success: false, error: err.message });
          broadcastModelEvent("load_error", { error: err.message });
        });
      return true;
    }

    case "MODEL_UNLOAD": {
      sendToOffscreen({ type: "MODEL_UNLOAD" }, 10000)
        .then((res) => {
          currentModelState = { modelId: null, isLoaded: false, isLoading: false };
          sendResponse({ success: true, result: res.payload });
          broadcastModelEvent("unloaded", {});
        })
        .catch((err) => sendResponse({ success: false, error: err.message }));
      return true;
    }

    case "MODEL_INFERENCE": {
      const prompt = message.prompt || (message.payload && message.payload.prompt);
      const options = message.options || (message.payload && message.payload.options) || {};
      const inferenceId = message.inferenceId || '';
      if (!prompt) { sendResponse({ success: false, error: "No prompt provided" }); return false; }

      // 先检查模型真实状态（offscreen 可能被 Chrome 回收过）
      (async () => {
        try {
          let ready = false;
          try {
            const statusRes = await sendToOffscreen({ type: "MODEL_STATUS" }, 5000);
            const st = statusRes.payload || statusRes;
            ready = !!(st && st.isLoaded);
          } catch (_) { ready = false; }

          if (!ready && currentModelState.modelId) {
            // 纠正模型 ID（可能是旧的非 ONNX 模型）
            currentModelState.modelId = resolveOnnxModelId(currentModelState.modelId);
            console.log("[BG] Model not in worker, auto-reloading:", currentModelState.modelId);
            broadcastModelEvent("loading", { modelId: currentModelState.modelId });
            try {
              const loadRes = await sendToOffscreen({ type: "MODEL_LOAD", modelId: currentModelState.modelId }, 300000);
              const loadPayload = loadRes.payload || loadRes;
              if (loadPayload && loadPayload.error) {
                console.error("[BG] Auto-reload failed:", loadPayload.error);
                broadcastModelEvent("load_error", { error: loadPayload.error });
                sendResponse({ success: false, error: "Model reload failed: " + loadPayload.error });
                return;
              }
              currentModelState.isLoaded = true;
              broadcastModelEvent("loaded", { modelId: currentModelState.modelId });
            } catch (reloadErr) {
              console.error("[BG] Auto-reload exception:", reloadErr);
              broadcastModelEvent("load_error", { error: reloadErr.message });
              sendResponse({ success: false, error: "Model reload exception: " + reloadErr.message });
              return;
            }
          } else if (!ready && !currentModelState.modelId) {
            // 尝试从 storage 获取配置的模型 ID
            let savedModelId = null;
            try {
              const data = await chrome.storage.local.get(["ala_model_id", "ala_model_enabled"]);
              if (data.ala_model_id && data.ala_model_enabled !== false) savedModelId = resolveOnnxModelId(data.ala_model_id);
              // 纠正后同步到 storage
              if (savedModelId && savedModelId !== data.ala_model_id) chrome.storage.local.set({ ala_model_id: savedModelId });
            } catch (_) {}
            if (savedModelId) {
              console.log("[BG] No current model but found saved config:", savedModelId);
              currentModelState.modelId = savedModelId;
              broadcastModelEvent("loading", { modelId: savedModelId });
              try {
                await sendToOffscreen({ type: "MODEL_LOAD", modelId: savedModelId }, 300000);
                currentModelState.isLoaded = true;
                broadcastModelEvent("loaded", { modelId: savedModelId });
              } catch (loadErr) {
                sendResponse({ success: false, error: "Model load failed: " + loadErr.message });
                return;
              }
            } else {
              sendResponse({ success: false, error: "No model configured" });
              return;
            }
          }

          // 生成 requestId 并注册流式 token 转发追踪
          const streamRequestId = "bg_inf_" + Date.now() + "_" + Math.random().toString(36).substr(2, 6);
          if (senderTabId && inferenceId) {
            inferenceRequestMap.set(streamRequestId, { tabId: senderTabId, inferenceId: inferenceId });
          }

          // 发送推理请求到 offscreen（使用预生成的 requestId）
          const res = await sendToOffscreenWithId(streamRequestId, { type: "MODEL_INFERENCE", prompt, options: { maxTokens: options.maxTokens || 512, temperature: options.temperature || 0.7 } }, 120000);
          inferenceRequestMap.delete(streamRequestId);
          // 检查 offscreen 返回的 payload 是否包含错误
          const payload = res.payload || res;
          if (payload && payload.error) {
            console.warn("[BG] Inference returned error:", payload.error);
            // 如果是 "Model not loaded"，尝试自动重新加载并重试一次
            if (payload.error.includes("not loaded") && currentModelState.modelId) {
              currentModelState.modelId = resolveOnnxModelId(currentModelState.modelId);
              console.log("[BG] Attempting auto-reload and retry for:", currentModelState.modelId);
              broadcastModelEvent("loading", { modelId: currentModelState.modelId });
              await sendToOffscreen({ type: "MODEL_LOAD", modelId: currentModelState.modelId }, 300000);
              currentModelState.isLoaded = true;
              broadcastModelEvent("loaded", { modelId: currentModelState.modelId });
              // 重试推理
              const retryRequestId = "bg_inf_retry_" + Date.now() + "_" + Math.random().toString(36).substr(2, 6);
              if (senderTabId && inferenceId) {
                inferenceRequestMap.set(retryRequestId, { tabId: senderTabId, inferenceId: inferenceId });
              }
              const retryRes = await sendToOffscreenWithId(retryRequestId, { type: "MODEL_INFERENCE", prompt, options: { maxTokens: options.maxTokens || 512, temperature: options.temperature || 0.7 } }, 120000);
              inferenceRequestMap.delete(retryRequestId);
              const retryPayload = retryRes.payload || retryRes;
              if (retryPayload && retryPayload.error) {
                sendResponse({ success: false, error: retryPayload.error });
              } else {
                sendResponse({ success: true, result: retryPayload });
              }
            } else {
              sendResponse({ success: false, error: payload.error });
            }
          } else {
            sendResponse({ success: true, result: payload });
          }
        } catch (err) {
          sendResponse({ success: false, error: err.message });
        }
      })();
      return true;
    }

    // --- EXECUTE_TASK：接收自然语言任务并执行 ---
    case "EXECUTE_TASK": {
      // content script 中继时数据在 message.payload 中
      const p = message.payload || message;
      const task = p.task || "";
      if (!task) { sendResponse({ success: false, error: "No task provided" }); return false; }
      console.log("[BG] EXECUTE_TASK received:", task);

      handleExecuteTask(task, senderTabId)
        .then((result) => sendResponse({ success: true, result }))
        .catch((err) => sendResponse({ success: false, error: err.message }));
      return true;
    }

    // --- WASM_EXECUTE_TOOL：直接工具调用 ---
    case "WASM_EXECUTE_TOOL": {
      // content script 中继时数据在 message.payload 中
      const tp = message.payload || message;
      const toolId = tp.id;
      const toolName = tp.name;
      const toolArgs = tp.arguments || tp.args || {};
      const toolMeta = tp.meta || {};
      console.log("[BG] WASM_EXECUTE_TOOL:", toolName, "ID:", toolId);

      if (!toolName) { sendResponse({ success: false, error: "Tool name is required" }); return false; }

      executeToolDirect(toolName, toolArgs, senderTabId)
        .then((result) => sendResponse({ success: true, id: toolId, name: toolName, result, meta: toolMeta }))
        .catch((err) => sendResponse({ success: false, id: toolId, name: toolName, error: { code: "TOOL_EXECUTION_ERROR", message: err.message }, meta: toolMeta }));
      return true;
    }

    // --- WASM_EXECUTE_TOOLS_BATCH：批量工具调用 ---
    case "WASM_EXECUTE_TOOLS_BATCH": {
      const bp = message.payload || message;
      const calls = bp.calls || [];
      const batchMeta = bp.meta || {};
      console.log("[BG] WASM_EXECUTE_TOOLS_BATCH, count:", calls.length);

      executeBatchTools(calls, senderTabId)
        .then((results) => sendResponse({ success: true, results, meta: batchMeta }))
        .catch((err) => sendResponse({ success: false, error: err.message, meta: batchMeta }));
      return true;
    }

    default:
      return false;
  }
});

// ====== 直接工具执行引擎 ======

/**
 * 获取执行操作的目标 tabId
 * 优先使用发送者所在标签的下一个活动标签，或新建标签
 */
// 记住用于自动化操作的"工作标签页"ID，避免每次操作都选到不同标签
let workTabId = null;

async function getActiveTab(senderTabId) {
  // 优先复用上次的工作标签页
  if (workTabId) {
    try {
      const t = await chrome.tabs.get(workTabId);
      if (t && t.id && t.id !== senderTabId) return t;
    } catch (_) { workTabId = null; }
  }
  try {
    const [tab] = await chrome.tabs.query({ active: true, currentWindow: true });
    if (tab && tab.id && tab.id !== senderTabId) return tab;
  } catch (e) {}
  // 如果活动标签就是发送者，找其他标签
  const allTabs = await chrome.tabs.query({ currentWindow: true });
  for (const t of allTabs) {
    if (t.id && t.id !== senderTabId && !t.url.startsWith("chrome://") && !t.url.startsWith("chrome-extension://")) return t;
  }
  return null;
}

/**
 * 将标签页切到前台（让用户能看到操作）
 */
async function bringTabToFront(tabId) {
  try {
    await chrome.tabs.update(tabId, { active: true });
    // 同时确保窗口也在前台
    const tab = await chrome.tabs.get(tabId);
    if (tab && tab.windowId) {
      await chrome.windows.update(tab.windowId, { focused: true });
    }
  } catch (e) {
    console.warn("[BG] bringTabToFront failed:", e);
  }
}

/**
 * 直接执行单个浏览器工具
 */
async function executeToolDirect(toolName, args, senderTabId) {
  console.log("[BG] Executing tool:", toolName, args);

  switch (toolName) {
    case "go_to_url":
    case "browser_navigate": {
      const url = args.url;
      if (!url) throw new Error("URL is required");
      const tab = await getActiveTab(senderTabId);
      if (tab) {
        await chrome.tabs.update(tab.id, { url });
        workTabId = tab.id;
        await bringTabToFront(tab.id);
        return { success: true, url, tabId: tab.id, method: "tab_update" };
      } else {
        const newTab = await chrome.tabs.create({ url, active: true });
        workTabId = newTab.id;
        return { success: true, url, tabId: newTab.id, method: "tab_create" };
      }
    }

    case "open_tab": {
      const url = args.url || "about:blank";
      const newTab = await chrome.tabs.create({ url });
      return { success: true, tabId: newTab.id, url };
    }

    case "close_tab": {
      const tabId = args.tabId;
      if (tabId) await chrome.tabs.remove(tabId);
      return { success: true };
    }

    case "switch_tab": {
      const tabId = args.tabId;
      if (tabId) await chrome.tabs.update(tabId, { active: true });
      return { success: true };
    }

    case "go_back": {
      const tab = await getActiveTab(senderTabId);
      if (tab) {
        await bringTabToFront(tab.id);
        await chrome.tabs.goBack(tab.id);
      }
      return { success: true };
    }

    case "search_google": {
      const query = args.query || args.text || "";
      const url = "https://www.google.com/search?q=" + encodeURIComponent(query);
      const tab = await getActiveTab(senderTabId);
      if (tab) {
        await chrome.tabs.update(tab.id, { url });
        workTabId = tab.id;
        await bringTabToFront(tab.id);
        return { success: true, url, tabId: tab.id };
      } else {
        const newTab = await chrome.tabs.create({ url, active: true });
        workTabId = newTab.id;
        return { success: true, url, tabId: newTab.id };
      }
    }

    case "browser_snapshot": {
      const tab = await getActiveTab(senderTabId);
      if (!tab) throw new Error("No active tab for snapshot");
      const results = await chrome.scripting.executeScript({
        target: { tabId: tab.id },
        func: () => {
          // 获取页面结构概要
          const title = document.title;
          const url = location.href;
          const text = document.body ? document.body.innerText.substring(0, 5000) : "";
          // 获取可交互元素
          const interactiveElements = [];
          const clickables = document.querySelectorAll("a, button, input, select, textarea, [role='button'], [onclick]");
          clickables.forEach((el, i) => {
            if (i > 50) return; // 限制数量
            const tag = el.tagName.toLowerCase();
            const txt = (el.innerText || el.value || el.placeholder || el.getAttribute("aria-label") || "").substring(0, 80);
            const href = el.href || "";
            const elType = el.type || "";
            const elName = el.name || "";
            const elPlaceholder = el.placeholder || "";
            interactiveElements.push({ index: i, tag, text: txt, href, type: elType, name: elName, placeholder: elPlaceholder });
          });
          return { title, url, textContent: text, interactiveElements, elementCount: clickables.length };
        },
      });
      return results[0] ? results[0].result : { error: "Script execution failed" };
    }

    case "click_element": {
      const tab = await getActiveTab(senderTabId);
      if (!tab) throw new Error("No active tab for click");
      await bringTabToFront(tab.id);
      const clickIndex = args.index !== undefined ? args.index : -1;
      const clickText = args.text || "";
      const results = await chrome.scripting.executeScript({
        target: { tabId: tab.id },
        func: (idx, txt) => {
          const clickables = document.querySelectorAll("a, button, input, select, textarea, [role='button'], [onclick]");
          let target = null;
          if (idx >= 0 && idx < clickables.length) {
            target = clickables[idx];
          } else if (txt) {
            for (const el of clickables) {
              if ((el.innerText || "").includes(txt) || (el.value || "").includes(txt)) {
                target = el;
                break;
              }
            }
          }
          if (target) {
            target.click();
            return { success: true, clicked: target.tagName + ": " + (target.innerText || target.value || "").substring(0, 50) };
          }
          return { success: false, error: "Element not found" };
        },
        args: [clickIndex, clickText],
      });
      return results[0] ? results[0].result : { error: "Script execution failed" };
    }

    case "input_text": {
      const tab = await getActiveTab(senderTabId);
      if (!tab) throw new Error("No active tab for input");
      await bringTabToFront(tab.id);
      const inputIndex = args.index !== undefined ? args.index : -1;
      const inputText = args.text || "";
      const useSnapshotIndex = args.snapshotIndex !== undefined ? args.snapshotIndex : -1;
      const results = await chrome.scripting.executeScript({
        target: { tabId: tab.id },
        func: (idx, txt, snapIdx) => {
          let target = null;
          // 优先：通过快照的 clickable 索引定位
          if (snapIdx >= 0) {
            const clickables = document.querySelectorAll("a, button, input, select, textarea, [role='button'], [onclick]");
            if (snapIdx < clickables.length) {
              const el = clickables[snapIdx];
              const tag = el.tagName.toLowerCase();
              if (tag === 'input' || tag === 'textarea' || el.getAttribute('contenteditable') !== null) {
                target = el;
              }
            }
          }
          // 其次：通过 input-only 索引
          if (!target && idx >= 0) {
            const inputs = document.querySelectorAll("input, textarea, [contenteditable]");
            if (idx < inputs.length) target = inputs[idx];
          }
          // 兜底：找第一个可见的文本输入框
          if (!target) {
            const inputs = document.querySelectorAll("input[type='text'], input[type='search'], input:not([type]), textarea, [contenteditable]");
            for (const el of inputs) {
              if (el.offsetParent !== null) {
                target = el;
                break;
              }
            }
          }
          if (target) {
            target.focus();
            if (target.getAttribute("contenteditable") !== null) {
              target.innerText = txt;
            } else {
              target.value = txt;
              target.dispatchEvent(new Event("input", { bubbles: true }));
              target.dispatchEvent(new Event("change", { bubbles: true }));
            }
            return { success: true, inputTo: target.tagName + "[" + (target.name || target.id || "") + "]" };
          }
          return { success: false, error: "Input element not found" };
        },
        args: [inputIndex, inputText, useSnapshotIndex],
      });
      return results[0] ? results[0].result : { error: "Script execution failed" };
    }

    case "send_keys":
    case "browser_press_key": {
      const tab = await getActiveTab(senderTabId);
      if (!tab) throw new Error("No active tab for send_keys");
      await bringTabToFront(tab.id);
      const keys = args.keys || args.key || "Enter";
      const results = await chrome.scripting.executeScript({
        target: { tabId: tab.id },
        func: (k) => {
          const el = document.activeElement;
          if (el) {
            const opts = { key: k, code: k === 'Enter' ? 'Enter' : 'Key' + k.toUpperCase(), keyCode: k === 'Enter' ? 13 : 0, which: k === 'Enter' ? 13 : 0, bubbles: true, cancelable: true };
            el.dispatchEvent(new KeyboardEvent("keydown", opts));
            el.dispatchEvent(new KeyboardEvent("keypress", opts));
            el.dispatchEvent(new KeyboardEvent("keyup", opts));
            // Enter 键：尝试提交最近的表单
            if (k === "Enter") {
              const form = el.closest("form");
              if (form) {
                form.requestSubmit ? form.requestSubmit() : form.submit();
                return { success: true, key: k, formSubmitted: true };
              }
            }
          }
          return { success: true, key: k };
        },
        args: [keys],
      });
      return results[0] ? results[0].result : { error: "Script execution failed" };
    }

    case "scroll_to_percent": {
      const tab = await getActiveTab(senderTabId);
      if (!tab) throw new Error("No active tab for scroll");
      await bringTabToFront(tab.id);
      const percent = args.percent !== undefined ? args.percent : 50;
      await chrome.scripting.executeScript({
        target: { tabId: tab.id },
        func: (p) => {
          const maxScroll = document.documentElement.scrollHeight - window.innerHeight;
          window.scrollTo({ top: (maxScroll * p) / 100, behavior: "smooth" });
        },
        args: [percent],
      });
      return { success: true, percent };
    }

    case "scroll_to_text": {
      const tab = await getActiveTab(senderTabId);
      if (!tab) throw new Error("No active tab for scroll");
      await bringTabToFront(tab.id);
      const text = args.text || "";
      const results = await chrome.scripting.executeScript({
        target: { tabId: tab.id },
        func: (txt) => {
          const walker = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT);
          while (walker.nextNode()) {
            if (walker.currentNode.textContent.includes(txt)) {
              walker.currentNode.parentElement.scrollIntoView({ behavior: "smooth", block: "center" });
              return { success: true, found: true };
            }
          }
          return { success: false, found: false };
        },
        args: [text],
      });
      return results[0] ? results[0].result : { error: "Script execution failed" };
    }

    case "browser_extract": {
      const tab = await getActiveTab(senderTabId);
      if (!tab) throw new Error("No active tab for extract");
      const results = await chrome.scripting.executeScript({
        target: { tabId: tab.id },
        func: () => {
          const text = document.body ? document.body.innerText : "";
          const emails = text.match(/[\w.+-]+@[\w-]+\.[\w.-]+/g) || [];
          const phones = text.match(/(?:\+?\d{1,3}[-.]?)?\(?\d{2,4}\)?[-.\s]?\d{3,4}[-.\s]?\d{3,4}/g) || [];
          const links = Array.from(document.querySelectorAll("a[href]")).slice(0, 30).map(a => ({ text: a.innerText.substring(0, 60), href: a.href }));
          return { emails: [...new Set(emails)], phones: [...new Set(phones)], links, title: document.title, url: location.href };
        },
      });
      return results[0] ? results[0].result : { error: "Script execution failed" };
    }

    case "extract_user_profile": {
      const tab = await getActiveTab(senderTabId);
      if (!tab) throw new Error("No active tab for extract_user_profile");
      await bringTabToFront(tab.id);
      const results = await chrome.scripting.executeScript({
        target: { tabId: tab.id },
        func: () => {
          var text = document.body ? document.body.innerText : '';
          var title = document.title;
          var url = location.href;

          // 头像
          var avatarEl = document.querySelector('img[class*="avatar"], img[class*="Avatar"], img[alt*="头像"], .avatar img, .user-avatar img');
          var avatar = avatarEl ? avatarEl.src : '';

          // 用户名
          var nameEl = document.querySelector('h1, .username, .user-name, .ProfileHeader-name, .name, [class*="nickname"], [class*="UserName"]');
          var name = nameEl ? nameEl.innerText.trim().substring(0, 50) : '';

          // 简介
          var bioEl = document.querySelector('.bio, .description, .ProfileHeader-headline, [class*="signature"], [class*="intro"], [class*="desc"]');
          var bio = bioEl ? bioEl.innerText.trim().substring(0, 300) : '';

          // 统计数据
          var stats = {};
          var statEls = document.querySelectorAll('[class*="count"], [class*="stat"], [class*="number"], [class*="follow"]');
          statEls.forEach(function (el) {
            var t = el.innerText.trim();
            if (/\d/.test(t) && t.length < 30) {
              var parent = el.parentElement;
              var label = parent ? parent.innerText.replace(t, '').trim().substring(0, 20) : '';
              if (label) stats[label] = t;
            }
          });

          // 联系方式
          var contacts = { emails: [], phones: [], wechats: [], qqs: [], links: [] };
          var m;
          var emailR = /[\w.+-]+@[\w-]+\.[\w.-]+/g;
          while ((m = emailR.exec(text)) !== null) contacts.emails.push(m[0]);
          var phoneR = /(?:\+?\d{1,3}[-.]?)?\d{3,4}[-.\s]?\d{3,4}[-.\s]?\d{3,4}/g;
          while ((m = phoneR.exec(text)) !== null) {
            var clean = m[0].replace(/[\s-().]/g, '');
            if (clean.length >= 7) contacts.phones.push(m[0]);
          }
          var wxR = /(?:微信|wechat|wx)[号:\s]*[：:]?\s*([a-zA-Z0-9_-]{5,20})/gi;
          while ((m = wxR.exec(text)) !== null) contacts.wechats.push(m[1]);
          var qqR = /(?:QQ|qq)[号:\s]*[：:]?\s*(\d{5,12})/gi;
          while ((m = qqR.exec(text)) !== null) contacts.qqs.push(m[1]);

          // 社交链接
          document.querySelectorAll('a[href*="linkedin"], a[href*="twitter"], a[href*="github"], a[href*="weibo"], a[href*="t.me"]')
            .forEach(function (a) { contacts.links.push(a.href); });

          // 最近发帖
          var recentPosts = [];
          var postEls = document.querySelectorAll('.ContentItem, .feed-item, .note-item, article, [class*="post-item"], [class*="card-item"]');
          var count = 0;
          postEls.forEach(function (el) {
            if (count >= 5) return;
            var h = el.querySelector('h2, h3, .title, [class*="title"]');
            var postTitle = h ? h.innerText.trim().substring(0, 100) : '';
            var postText = el.innerText.trim().substring(0, 200);
            if (postText.length > 20) { recentPosts.push({ title: postTitle, preview: postText }); count++; }
          });

          return {
            name: name, bio: bio, avatar: avatar, stats: stats,
            contacts: contacts, recentPosts: recentPosts,
            pageTitle: title, pageUrl: url, fullText: text.substring(0, 3000)
          };
        },
      });
      return results[0] ? results[0].result : { error: "Script execution failed" };
    }

    case "wait": {
      const seconds = args.seconds || 3;
      await new Promise((r) => setTimeout(r, seconds * 1000));
      return { success: true, waited: seconds };
    }

    case "done": {
      return { success: true, message: args.message || "Task completed" };
    }

    default:
      throw new Error("Unknown tool: " + toolName);
  }
}

/**
 * 批量执行工具（顺序）
 */
async function executeBatchTools(calls, senderTabId) {
  const results = [];
  for (const call of calls) {
    try {
      const result = await executeToolDirect(call.name, call.arguments || {}, senderTabId);
      results.push({ success: true, id: call.id, name: call.name, result });
    } catch (err) {
      results.push({ success: false, id: call.id, name: call.name, error: { code: "TOOL_EXECUTION_ERROR", message: err.message } });
    }
  }
  return results;
}

/**
 * 处理 EXECUTE_TASK：自然语言任务执行
 * 尝试：1) 通过 side panel port 委派给 IIFE executor  2) 基础任务自行执行
 */
async function handleExecuteTask(task, senderTabId) {
  console.log("[BG] Handling EXECUTE_TASK:", task);

  // 简单 URL 导航检测
  const urlMatch = task.match(/https?:\/\/[^\s]+/i);
  if (urlMatch) {
    const url = urlMatch[0];
    const result = await executeToolDirect("go_to_url", { url }, senderTabId);

    // 如果任务只是导航，直接返回
    const afterUrl = task.replace(url, "").trim();
    if (!afterUrl) return result;

    // 有后续操作说明 → 等待页面加载后获取快照
    await new Promise((r) => setTimeout(r, 3000));
    const snapshot = await executeToolDirect("browser_snapshot", {}, senderTabId);

    return {
      navigated: result,
      snapshot: snapshot,
      pendingTask: afterUrl,
      message: "Navigated to URL. Page snapshot captured. Complex task requires the side panel executor or a loaded AI model to proceed with: " + afterUrl,
    };
  }

  // 不含 URL 的通用任务
  return {
    success: false,
    message: "Complex natural language tasks require the extension side panel (with LLM) or a loaded AI model. Task received: " + task,
    hint: "Open the extension side panel and send the task there, or load an AI model on the config page.",
  };
}

// ====== 工具函数 ======
function broadcastModelEvent(event, data, onlyTabId) {
  const msg = { type: "MODEL_EVENT", event, data };
  if (onlyTabId) {
    chrome.tabs.sendMessage(onlyTabId, msg).catch(() => {});
    return;
  }
  chrome.tabs.query({}, function (tabs) {
    for (const tab of tabs) {
      if (tab.id) chrome.tabs.sendMessage(tab.id, msg).catch(() => {});
    }
  });
}

console.log("[BackgroundBridge] Model inference bridge loaded (Offscreen API + Auto Lifecycle)");
