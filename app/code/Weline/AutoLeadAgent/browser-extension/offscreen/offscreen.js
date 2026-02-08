/**
 * Offscreen Document - 模型推理桥接
 *
 * 运行在 Offscreen Document 中（有完整的 DOM 和 Worker 支持），
 * 负责创建 inference-worker.js 并转发 background ↔ worker 消息。
 *
 * 生命周期：由 background-bridge.js 按需创建，Chrome 可能在空闲时回收。
 * 回收后 Worker 和模型也会丢失，下次请求时自动重建。
 */

(function () {
  "use strict";

  var worker = null;
  var pendingRequests = {};
  var requestCounter = 0;
  // 映射 worker id → background requestId（用于流式 token 转发）
  var workerIdToRequestId = {};

  /**
   * 获取或创建 inference worker
   */
  function getWorker() {
    if (!worker) {
      var workerUrl = chrome.runtime.getURL("inference-worker.js");
      worker = new Worker(workerUrl, { type: "module" });

      worker.onmessage = function (event) {
        var data = event.data;
        var id = data.id;

        // progress 事件无需等待 pending，直接转发到 background
        if (data.type === "progress") {
          try {
            chrome.runtime.sendMessage({
              target: "background",
              type: "MODEL_LOAD_PROGRESS",
              payload: data.payload || {}
            });
          } catch (_) {}
          return;
        }

        var pending = pendingRequests[id];
        if (!pending) return;

        if (data.type === "error") {
          clearTimeout(pending.timer);
          delete pendingRequests[id];
          delete workerIdToRequestId[id];
          pending.reject(new Error(data.error || "Worker error"));
        } else if (data.type === "result" || data.type === "status") {
          clearTimeout(pending.timer);
          delete pendingRequests[id];
          delete workerIdToRequestId[id];
          pending.resolve(data.payload);
        } else if (data.type === "token") {
          // 流式 token — 转发到 background
          var bgRequestId = workerIdToRequestId[id];
          if (bgRequestId && data.payload) {
            try {
              chrome.runtime.sendMessage({
                target: "background",
                type: "INFERENCE_TOKEN",
                requestId: bgRequestId,
                token: data.payload.token || '',
                fullText: data.payload.fullText || ''
              });
            } catch (_) {}
          }
        }
      };

      worker.onerror = function (error) {
        console.error("[Offscreen] Worker error:", error);
        for (var id in pendingRequests) {
          if (pendingRequests.hasOwnProperty(id)) {
            clearTimeout(pendingRequests[id].timer);
            pendingRequests[id].reject(new Error("Worker crashed"));
          }
        }
        pendingRequests = {};
        worker = null;
      };
    }
    return worker;
  }

  /**
   * 向 worker 发消息
   * @param {string} bgRequestId 可选的 background requestId（用于流式 token 回传映射）
   */
  function sendWorkerMessage(type, payload, timeoutMs, bgRequestId) {
    return new Promise(function (resolve, reject) {
      var id = "off_" + ++requestCounter;
      var w = getWorker();

      // 建立 worker id → background requestId 的映射（用于 token 转发）
      if (bgRequestId) {
        workerIdToRequestId[id] = bgRequestId;
      }

      var timer = setTimeout(function () {
        delete pendingRequests[id];
        delete workerIdToRequestId[id];
        reject(new Error("Worker request timeout"));
      }, timeoutMs || 120000);

      pendingRequests[id] = { resolve: resolve, reject: reject, timer: timer };
      w.postMessage({ type: type, id: id, payload: payload });
    });
  }

  /**
   * 监听来自 background 的消息
   */
  chrome.runtime.onMessage.addListener(function (message, sender, sendResponse) {
    if (!message || message.target !== "offscreen") return false;

    var requestId = message.requestId;

    function reply(payload) {
      chrome.runtime.sendMessage({
        target: "background",
        requestId: requestId,
        payload: payload,
      });
    }

    switch (message.type) {
      case "MODEL_STATUS":
        sendWorkerMessage("status", {}, 5000)
          .then(function (status) { reply(status); })
          .catch(function () {
            reply({ modelId: null, isLoaded: false, isLoading: false });
          });
        break;

      case "MODEL_LOAD":
        sendWorkerMessage("load", { modelId: message.modelId }, 300000)
          .then(function (result) { reply(result); })
          .catch(function (err) { reply({ error: err.message }); });
        break;

      case "MODEL_UNLOAD":
        sendWorkerMessage("unload", {}, 10000)
          .then(function (result) { reply(result); })
          .catch(function (err) { reply({ error: err.message }); });
        break;

      case "MODEL_INFERENCE":
        var prompt = message.prompt || "";
        var options = message.options || {};
        var messages = [
          { role: "system", content: "You are a helpful assistant." },
          { role: "user", content: prompt },
        ];
        sendWorkerMessage(
          "generate",
          {
            messages: messages,
            maxTokens: options.maxTokens || 512,
            temperature: options.temperature || 0.7,
          },
          120000,
          requestId  // 传递 background requestId 用于 token 映射
        )
          .then(function (result) { reply(result); })
          .catch(function (err) { reply({ error: err.message }); });
        break;

      default:
        return false;
    }

    // 不使用 sendResponse（通过 runtime.sendMessage 回传）
    return false;
  });

  console.log("[Offscreen] Inference offscreen document ready");
})();
