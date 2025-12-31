/**
 * AutoLeadAgent 配置页 - 扩展通信客户端
 */

var ConfigExtensionClient = (function () {
    'use strict';

    var extensionId = null;
    var extensionReady = false;
    var extensionVersion = null;
    var extensionIdDetecting = false;

    // 监听扩展就绪消息
    window.addEventListener('message', function (event) {
        if (event.source !== window) return;
        if (event.data && event.data.type === 'AUTOLEADAGENT_READY') {
            extensionReady = true;
            extensionVersion = event.data.version;
            console.log('[ExtensionClient] 扩展已就绪，版本:', extensionVersion);
        }
    });

    /**
     * 检测扩展 ID
     */
    function detectExtensionId() {
        return new Promise(function (resolve) {
            if (extensionReady) {
                resolve('content-script');
                return;
            }
            if (extensionId) {
                resolve(extensionId);
                return;
            }
            if (extensionIdDetecting) {
                var checkInterval = setInterval(function () {
                    if (!extensionIdDetecting) {
                        clearInterval(checkInterval);
                        resolve(extensionId || (extensionReady ? 'content-script' : null));
                    }
                }, 100);
                return;
            }

            extensionIdDetecting = true;
            if (typeof chrome === 'undefined' || !chrome.runtime || !chrome.runtime.sendMessage) {
                extensionIdDetecting = false;
                resolve('content-script');
                return;
            }

            var possibleIds = window.AUTOLEADAGENT_EXTENSION_IDS || [];
            if (possibleIds.length === 0) {
                extensionIdDetecting = false;
                resolve('content-script');
                return;
            }

            var triedCount = 0;
            var found = false;
            possibleIds.forEach(function (extId) {
                try {
                    chrome.runtime.sendMessage(extId, { action: 'ping' }, function (response) {
                        triedCount++;
                        if (found) return;
                        if (chrome.runtime.lastError) {
                            if (triedCount >= possibleIds.length) {
                                extensionIdDetecting = false;
                                resolve(null);
                            }
                            return;
                        }
                        if (response && response.success) {
                            found = true;
                            extensionId = extId;
                            extensionIdDetecting = false;
                            console.log('[ExtensionClient] 检测到扩展 ID:', extId, '版本:', response.version);
                            resolve(extId);
                        } else if (triedCount >= possibleIds.length) {
                            extensionIdDetecting = false;
                            resolve(null);
                        }
                    });
                } catch (e) {
                    triedCount++;
                    if (triedCount >= possibleIds.length && !found) {
                        extensionIdDetecting = false;
                        resolve(null);
                    }
                }
            });
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
        getVersion: function() { return extensionVersion; }
    };
})();

// 导出到全局
if (typeof window !== 'undefined') {
    window.ConfigExtensionClient = ConfigExtensionClient;
}
