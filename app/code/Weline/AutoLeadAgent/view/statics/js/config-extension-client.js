/**
 * AutoLeadAgent 配置页 - 扩展通信客户端
 */

var ConfigExtensionClient = (function () {
    'use strict';

    var extensionId = null;
    var extensionReady = false;
    var extensionVersion = null;
    var extensionIdDetecting = false;

    // 标记扩展已就绪的内部方法
    function markExtensionReady(data) {
        if (extensionReady) return; // 防止重复触发
        extensionReady = true;
        extensionId = data.extensionId || extensionId;
        extensionVersion = data.version || extensionVersion;
        console.log('[ExtensionClient] 扩展已就绪，版本:', extensionVersion, '扩展ID:', extensionId);
        // 触发自定义事件，通知其他组件（如 config-models.js）
        window.dispatchEvent(new CustomEvent('autoleadagent-ready', { detail: data }));
    }

    // 监听扩展消息（兼容所有消息类型）
    window.addEventListener('message', function (event) {
        if (event.source !== window) return;
        if (!event.data || typeof event.data !== 'object') return;
        var type = event.data.type;
        // 兼容 content script 主动广播和被动发现响应的多种消息类型
        if (type === 'AUTOLEADAGENT_READY' ||
            type === 'AUTOLEADAGENT_DISCOVERED' ||
            type === 'EXTENSION_DISCOVERED' ||
            type === 'AUTOLEADAGENT_EXTENSION_FOUND') {
            markExtensionReady(event.data);
        }
    });

    // 主动发送发现请求（不依赖 chrome.runtime，通过 content script 中继）
    function discoverExtension() {
        window.postMessage({ type: 'DISCOVER_AUTOLEADAGENT_EXTENSION' }, '*');
        window.postMessage({ type: 'DISCOVER_EXTENSION' }, '*');
    }

    // 页面加载后主动发现
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', discoverExtension);
    } else {
        discoverExtension();
    }
    // 延迟再发一次，确保 content script 已注入
    setTimeout(discoverExtension, 800);

    /**
     * 检测扩展 ID
     */
    function detectExtensionId() {
        return new Promise(function (resolve) {
            // 已通过 postMessage 检测到扩展
            if (extensionReady) {
                resolve(extensionId || 'content-script');
                return;
            }
            if (extensionId) {
                resolve(extensionId);
                return;
            }

            // 尝试主动发现
            var discovered = false;
            var onDiscover = function (event) {
                if (event.source !== window || !event.data) return;
                var type = event.data.type;
                if (type === 'AUTOLEADAGENT_READY' ||
                    type === 'AUTOLEADAGENT_DISCOVERED' ||
                    type === 'EXTENSION_DISCOVERED' ||
                    type === 'AUTOLEADAGENT_EXTENSION_FOUND') {
                    if (discovered) return;
                    discovered = true;
                    window.removeEventListener('message', onDiscover);
                    markExtensionReady(event.data);
                    resolve(event.data.extensionId || 'content-script');
                }
            };
            window.addEventListener('message', onDiscover);
            window.postMessage({ type: 'DISCOVER_AUTOLEADAGENT_EXTENSION' }, '*');

            // 超时回退到 chrome.runtime 方式
            setTimeout(function () {
                if (discovered) return;
                window.removeEventListener('message', onDiscover);

                if (typeof chrome === 'undefined' || !chrome.runtime || !chrome.runtime.sendMessage) {
                    resolve(null);
                    return;
                }
                var possibleIds = window.AUTOLEADAGENT_EXTENSION_IDS || [];
                if (possibleIds.length === 0) {
                    resolve(null);
                    return;
                }
                var triedCount = 0;
                var found = false;
                possibleIds.forEach(function (testId) {
                    try {
                        chrome.runtime.sendMessage(testId, { action: 'ping' }, function (response) {
                            triedCount++;
                            if (found) return;
                            if (chrome.runtime.lastError) {
                                if (triedCount >= possibleIds.length) resolve(null);
                                return;
                            }
                            if (response && response.success) {
                                found = true;
                                extensionId = testId;
                                resolve(testId);
                            } else if (triedCount >= possibleIds.length) {
                                resolve(null);
                            }
                        });
                    } catch (e) {
                        triedCount++;
                        if (triedCount >= possibleIds.length && !found) resolve(null);
                    }
                });
            }, 2000);
        });
    }

    /**
     * 发送消息到扩展
     */
    function sendMessage(message, callback) {
        detectExtensionId().then(function (extId) {
            if (!extId || extId === 'content-script') {
                var requestId = 'hf_req_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9);
                var hasResponded = false;
                var timeoutId = null;

                var responseHandler = function (event) {
                    if (event.source !== window) return;
                    if (event.data && event.data.type === 'AUTOLEADAGENT_RESPONSE' && event.data.requestId === requestId) {
                        if (hasResponded) return;
                        hasResponded = true;
                        window.removeEventListener('message', responseHandler);
                        if (timeoutId) clearTimeout(timeoutId);
                        if (callback) callback(event.data.response);
                    }
                };
                window.addEventListener('message', responseHandler);

                var action = message.type || message.action || 'unknown';
                window.postMessage({
                    type: 'AUTOLEADAGENT_REQUEST',
                    action: action,
                    payload: message,
                    requestId: requestId
                }, '*');

                var timeout = (action === 'ping') ? 5000 : (message.type === 'HF_DOWNLOAD_MODEL' ? 1800000 : 60000);
                timeoutId = setTimeout(function () {
                    if (hasResponded) return;
                    hasResponded = true;
                    window.removeEventListener('message', responseHandler);
                    if (callback) {
                        callback({
                            success: false,
                            error: '扩展响应超时（' + Math.floor(timeout / 1000) + '秒）',
                            errorType: 'timeout_error'
                        });
                    }
                }, timeout);
                return;
            }

            try {
                chrome.runtime.sendMessage(extId, message, function (response) {
                    if (chrome.runtime.lastError) {
                        var errorMsg = chrome.runtime.lastError.message;
                        if (callback) callback({ success: false, error: '扩展通信错误: ' + errorMsg });
                        return;
                    }
                    if (callback) callback(response);
                });
            } catch (e) {
                if (callback) callback({ success: false, error: '发送消息异常: ' + e.message });
            }
        });
    }

    return {
        sendMessage: sendMessage,
        isReady: function() { return extensionReady; },
        getVersion: function() { return extensionVersion; },
        getExtensionId: function() { return detectExtensionId(); }
    };
})();

// 导出到全局
if (typeof window !== 'undefined') {
    window.ConfigExtensionClient = ConfigExtensionClient;
}
