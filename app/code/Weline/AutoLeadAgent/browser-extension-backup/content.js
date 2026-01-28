/**
 * AutoLeadAgent 浏览器扩展 - 内容脚本
 * 
 * 注入到所有页面，用于：
 * - 监听来自网页的消息
 * - 提取页面数据
 * - 与后台脚本通信
 */

(function () {
    'use strict';

    const EXTENSION_ID = 'autoleadagent';

    // 存储 Port 连接（用于下载操作）
    window.__downloadPorts = window.__downloadPorts || new Map();

    // 监听来自网页的消息
    window.addEventListener('message', function (event) {
        // 验证消息来源
        if (event.source !== window) return;
        if (!event.data || event.data.type !== 'AUTOLEADAGENT_REQUEST') return;

        console.log('[AutoLeadAgent Content] Received message from page:', event.data);

        const { action, payload, requestId } = event.data;

        // 检测下载请求，使用 Port 连接
        if (action === 'HF_DOWNLOAD_MODEL' || (payload && payload.type === 'HF_DOWNLOAD_MODEL')) {
            const modelId = payload ? payload.modelId : null;
            if (!modelId) {
                window.postMessage({
                    type: 'AUTOLEADAGENT_RESPONSE',
                    requestId: requestId,
                    response: {
                        success: false,
                        error: '模型ID不能为空'
                    }
                }, '*');
                return;
            }

            console.log('[AutoLeadAgent Content] 检测到下载请求，建立 Port 连接:', modelId);

            // 如果已有连接，先断开
            if (window.__downloadPorts.has(modelId)) {
                const oldPort = window.__downloadPorts.get(modelId);
                oldPort.disconnect();
                window.__downloadPorts.delete(modelId);
            }

            // 建立 Port 连接
            const port = chrome.runtime.connect({ name: 'hf-download' });

            // 监听 Port 消息并转发到网页
            port.onMessage.addListener((message) => {
                console.log('[AutoLeadAgent Content] 收到 Port 消息:', message);
                
                // 转发到网页
                window.postMessage({
                    type: 'AUTOLEADAGENT_DOWNLOAD_MESSAGE',
                    data: message
                }, '*');
            });

            // 处理 Port 断开
            port.onDisconnect.addListener(() => {
                console.log('[AutoLeadAgent Content] Port 连接断开:', modelId);
                window.__downloadPorts.delete(modelId);
                
                // 通知网页连接断开
                window.postMessage({
                    type: 'AUTOLEADAGENT_DOWNLOAD_MESSAGE',
                    data: {
                        type: 'download-disconnected',
                        modelId: modelId
                    }
                }, '*');
            });

            // 保存 Port 引用
            window.__downloadPorts.set(modelId, port);

            // 发送下载请求
            port.postMessage({
                type: 'start-download',
                modelId: modelId
            });

            // 立即返回响应，表示已建立连接
            window.postMessage({
                type: 'AUTOLEADAGENT_RESPONSE',
                requestId: requestId,
                response: {
                    success: true,
                    portConnected: true,
                    modelId: modelId,
                    message: 'Port 连接已建立，下载已开始'
                }
            }, '*');

            return; // 不继续处理，已通过 Port 处理
        }

        // 转发到后台脚本（其他消息）
        // 注意：对于异步操作（如crawl），需要保持消息通道开放
        console.log('[AutoLeadAgent Content] 转发消息到 background:', { action, payload, requestId });

        // 添加超时检测机制（统一超时处理，与 config-models.js 保持一致）
        let responseReceived = false;
        let timeoutId = null;
        
        // 根据操作类型设置不同的超时时间
        let timeout = 30000; // 默认30秒
        if (action === 'ping') {
            timeout = 5000; // ping 操作5秒超时
        } else if (action === 'HF_DOWNLOAD_MODEL' || (payload && payload.type === 'HF_DOWNLOAD_MODEL')) {
            timeout = 1800000; // 模型下载操作30分钟超时（模型文件可能很大）
        } else if (action && action.startsWith('HF_')) {
            timeout = 60000; // 其他 HF_ 类型的操作60秒超时
        } else if (action === 'crawl') {
            timeout = 600000; // crawl 操作10分钟超时
        }
        
        // 设置超时处理
        timeoutId = setTimeout(() => {
            // 防止超时后仍处理响应
            if (responseReceived) {
                return;
            }
            responseReceived = true;
            
            const timeoutSeconds = Math.floor(timeout / 1000);
            const timeoutMinutes = Math.floor(timeout / 60000);
            const timeoutMsg = timeoutMinutes >= 1
                ? `Background script 响应超时（已等待 ${timeoutMinutes} 分钟）`
                : `Background script 响应超时（已等待 ${timeoutSeconds} 秒）`;
            
            console.error('[AutoLeadAgent Content]', timeoutMsg, 'action:', action, 'requestId:', requestId);
            
            // 返回错误响应给网页
            window.postMessage({
                type: 'AUTOLEADAGENT_RESPONSE',
                requestId: requestId,
                response: {
                    success: false,
                    error: timeoutMsg + '，请检查扩展是否正常运行',
                    errorType: 'timeout_error',
                    timeout: timeout
                }
            }, '*');
        }, timeout);

        try {
            // 检查扩展是否可用
            if (!chrome.runtime || !chrome.runtime.sendMessage) {
                throw new Error('扩展上下文无效：扩展可能已被重新加载，请刷新页面');
            }

            // 构建消息对象：优先使用 payload 中的字段，但确保 action 和 requestId 存在
            // payload 中可能包含 type 字段（如 HF_GET_MODEL_INFO），需要保留
            const messageToSend = {
                ...payload, // 先展开 payload（包含 type、modelId 等字段）
                action: action, // 添加 action 字段（用于兼容）
                requestId: requestId // 确保 requestId 存在
            };
            
            chrome.runtime.sendMessage(messageToSend, function (response) {
                // 防止重复处理响应
                if (responseReceived) {
                    console.warn('[AutoLeadAgent Content] 收到重复响应，已忽略:', requestId);
                    return;
                }
                responseReceived = true;
                
                // 清理超时
                if (timeoutId) {
                    clearTimeout(timeoutId);
                    timeoutId = null;
                }

                // 检查是否有错误
                if (chrome.runtime.lastError) {
                    const errorMessage = chrome.runtime.lastError.message;
                    const errorDetails = {
                        message: errorMessage,
                        name: chrome.runtime.lastError.name || 'ChromeRuntimeError'
                    };
                    console.error('[AutoLeadAgent Content] Extension error:', JSON.stringify(errorDetails, null, 2));

                    // 特殊处理 Extension context invalidated 错误
                    if (errorMessage && errorMessage.includes('Extension context invalidated')) {
                        console.error('[AutoLeadAgent Content] 扩展上下文已失效，扩展可能已被重新加载');
                        window.postMessage({
                            type: 'AUTOLEADAGENT_RESPONSE',
                            requestId: requestId,
                            response: {
                                success: false,
                                error: '扩展上下文已失效：扩展可能已被重新加载，请刷新页面后重试',
                                errorType: 'extension_context_invalidated',
                                suggestion: '请刷新页面并重新加载扩展'
                            }
                        }, '*');
                        return;
                    }

                    // 返回错误响应给网页
                    window.postMessage({
                        type: 'AUTOLEADAGENT_RESPONSE',
                        requestId: requestId,
                        response: {
                            success: false,
                            error: '扩展通信错误: ' + errorMessage,
                            errorType: 'extension_error'
                        }
                    }, '*');
                    return;
                }

                // 返回结果给网页
                if (response) {
                    console.log('[AutoLeadAgent Content] 收到 background 响应:', response);
                    window.postMessage({
                        type: 'AUTOLEADAGENT_RESPONSE',
                        requestId: requestId,
                        response: response
                    }, '*');
                } else {
                    // 如果没有响应，可能是异步操作还在进行中
                    console.warn('[AutoLeadAgent Content] No response received, may be async operation');
                }
            });
        } catch (error) {
            // 防止重复处理错误
            if (responseReceived) {
                console.warn('[AutoLeadAgent Content] 收到重复错误，已忽略:', requestId);
                return;
            }
            responseReceived = true;
            
            // 清理超时
            if (timeoutId) {
                clearTimeout(timeoutId);
                timeoutId = null;
            }
            
            console.error('[AutoLeadAgent Content] 发送消息异常:', error);

            // 检查是否是扩展上下文失效错误
            const errorMessage = error.message || '';
            const isContextInvalidated = errorMessage.includes('Extension context invalidated') ||
                errorMessage.includes('扩展上下文无效') ||
                errorMessage.includes('扩展上下文已失效');

            window.postMessage({
                type: 'AUTOLEADAGENT_RESPONSE',
                requestId: requestId,
                response: {
                    success: false,
                    error: isContextInvalidated
                        ? '扩展上下文已失效：扩展可能已被重新加载，请刷新页面后重试'
                        : '发送消息异常: ' + errorMessage,
                    errorType: isContextInvalidated ? 'extension_context_invalidated' : 'send_error',
                    suggestion: isContextInvalidated ? '请刷新页面并重新加载扩展' : undefined
                }
            }, '*');
        }
    });

    // 监听来自后台脚本的消息
    chrome.runtime.onMessage.addListener(function (message, sender, sendResponse) {
        if (message && message.type === 'AUTOLEADAGENT_LOG') {
            console.log('[AutoLeadAgent Content] 收到日志消息:', message.message);
            // 转发日志到网页
            try {
                window.postMessage({
                    type: 'AUTOLEADAGENT_LOG',
                    logType: message.logType,
                    message: message.message,
                    timestamp: message.timestamp
                }, '*');
            } catch (error) {
                console.error('[AutoLeadAgent Content] 转发日志失败:', error);
            }
            // 返回 true 表示异步响应
            return true;
        }

        // 转发 HuggingFace 相关消息到网页（如 HF_DOWNLOAD_PROGRESS）
        // 注意：HF_SEARCH_MODELS 和 HF_GET_MODEL_INFO 的响应应该通过 AUTOLEADAGENT_RESPONSE 格式返回
        // 这里只转发进度更新等实时消息
        if (message && message.type && message.type.startsWith('HF_')) {
            // 如果是进度消息或其他实时消息，直接转发
            if (message.type === 'HF_DOWNLOAD_PROGRESS' || message.type === 'HF_FILE_DOWNLOADED') {
                try {
                    window.postMessage(message, '*');
                } catch (error) {
                    console.error('[AutoLeadAgent Content] 转发 HF 消息失败:', error);
                }
                return true;
            }
            // 其他 HF_ 消息（如响应）应该通过 AUTOLEADAGENT_RESPONSE 格式返回，这里不处理
        }

        // 处理其他消息类型
        return false;
    });

    // 添加错误处理，确保消息不会丢失
    window.addEventListener('error', function (event) {
        // 构建详细的错误信息
        var errorInfo = {
            message: event.message || '未知错误',
            filename: event.filename || '未知文件',
            lineno: event.lineno || 0,
            colno: event.colno || 0
        };
        
        // 如果有 error 对象，添加更多信息
        if (event.error) {
            errorInfo.error = event.error.toString();
            errorInfo.stack = event.error.stack;
        }
        
        // 只记录有意义的错误（排除一些常见的无害错误）
        var errorMessage = errorInfo.message.toLowerCase();
        var shouldLog = !errorMessage.includes('script error') && 
                       !errorMessage.includes('non-error promise rejection');
        
        if (shouldLog) {
            console.error('[AutoLeadAgent Content] 页面错误:', errorInfo);
        }
    });

    // 通知网页扩展已加载
    window.postMessage({
        type: 'AUTOLEADAGENT_READY',
        version: chrome.runtime.getManifest().version
    }, '*');

    console.log('[AutoLeadAgent Content] Content script loaded');

    /**
     * 页面数据提取工具
     */
    window.AutoLeadAgentExtractor = {
        /**
         * 提取 LinkedIn 搜索结果
         */
        extractLinkedIn: function (maxResults = 10) {
            const results = [];
            const items = document.querySelectorAll('.reusable-search__result-container');

            items.forEach(function (item, index) {
                if (index >= maxResults) return;

                const nameEl = item.querySelector('.entity-result__title-text a span[aria-hidden="true"]');
                const titleEl = item.querySelector('.entity-result__primary-subtitle');
                const locationEl = item.querySelector('.entity-result__secondary-subtitle');
                const linkEl = item.querySelector('.entity-result__title-text a');
                const imgEl = item.querySelector('.presence-entity__image');

                if (nameEl) {
                    results.push({
                        platform: 'linkedin',
                        name: nameEl.textContent.trim(),
                        title: titleEl ? titleEl.textContent.trim() : '',
                        location: locationEl ? locationEl.textContent.trim() : '',
                        profileUrl: linkEl ? linkEl.href : '',
                        avatar: imgEl ? imgEl.src : '',
                        extractedAt: new Date().toISOString()
                    });
                }
            });

            return results;
        },

        /**
         * 提取 Twitter/X 搜索结果
         */
        extractTwitter: function (maxResults = 10) {
            const results = [];
            const items = document.querySelectorAll('[data-testid="cellInnerDiv"]');

            items.forEach(function (item, index) {
                if (index >= maxResults) return;

                const userCell = item.querySelector('[data-testid="UserCell"]');
                if (!userCell) return;

                const nameEl = userCell.querySelector('[data-testid="User-Name"] span span');
                const handleEl = userCell.querySelector('[data-testid="User-Name"] a');
                const bioEl = userCell.querySelector('[data-testid="UserDescription"]');
                const linkEl = userCell.querySelector('a[href^="/"]');
                const imgEl = userCell.querySelector('img[src*="profile_images"]');

                if (nameEl || handleEl) {
                    results.push({
                        platform: 'twitter',
                        name: nameEl ? nameEl.textContent.trim() : '',
                        handle: handleEl ? handleEl.textContent.trim() : '',
                        bio: bioEl ? bioEl.textContent.trim() : '',
                        profileUrl: linkEl ? 'https://twitter.com' + linkEl.getAttribute('href') : '',
                        avatar: imgEl ? imgEl.src : '',
                        extractedAt: new Date().toISOString()
                    });
                }
            });

            return results;
        },

        /**
         * 提取 Facebook 搜索结果
         */
        extractFacebook: function (maxResults = 10) {
            const results = [];
            const items = document.querySelectorAll('[role="article"]');

            items.forEach(function (item, index) {
                if (index >= maxResults) return;

                const linkEl = item.querySelector('a[role="link"]');
                const nameEl = linkEl ? linkEl.querySelector('span') : null;
                const imgEl = item.querySelector('image, img');

                if (nameEl) {
                    results.push({
                        platform: 'facebook',
                        name: nameEl.textContent.trim(),
                        profileUrl: linkEl ? linkEl.href : '',
                        avatar: imgEl ? (imgEl.href || imgEl.src) : '',
                        extractedAt: new Date().toISOString()
                    });
                }
            });

            return results;
        },

        /**
         * 提取 YouTube 频道结果
         */
        extractYouTube: function (maxResults = 10) {
            const results = [];
            const items = document.querySelectorAll('ytd-channel-renderer');

            items.forEach(function (item, index) {
                if (index >= maxResults) return;

                const nameEl = item.querySelector('#channel-title');
                const subsEl = item.querySelector('#subscribers');
                const descEl = item.querySelector('#description-text');
                const linkEl = item.querySelector('a#main-link');
                const imgEl = item.querySelector('img');

                if (nameEl) {
                    results.push({
                        platform: 'youtube',
                        name: nameEl.textContent.trim(),
                        followers: subsEl ? subsEl.textContent.trim() : '',
                        bio: descEl ? descEl.textContent.trim() : '',
                        profileUrl: linkEl ? linkEl.href : '',
                        avatar: imgEl ? imgEl.src : '',
                        extractedAt: new Date().toISOString()
                    });
                }
            });

            return results;
        },

        /**
         * 提取 Instagram 搜索结果
         */
        extractInstagram: function (maxResults = 10) {
            const results = [];
            // Instagram 的搜索结果结构经常变化
            const items = document.querySelectorAll('a[href^="/"][role="link"]');

            items.forEach(function (item, index) {
                if (index >= maxResults) return;

                const nameEl = item.querySelector('span span');
                const handleEl = item.querySelector('span');
                const imgEl = item.querySelector('img');

                if (nameEl || handleEl) {
                    const href = item.getAttribute('href');
                    if (href && href.match(/^\/[^\/]+\/?$/)) {
                        results.push({
                            platform: 'instagram',
                            name: nameEl ? nameEl.textContent.trim() : '',
                            handle: href.replace(/\//g, ''),
                            profileUrl: 'https://instagram.com' + href,
                            avatar: imgEl ? imgEl.src : '',
                            extractedAt: new Date().toISOString()
                        });
                    }
                }
            });

            return results;
        },

        /**
         * 通用提取方法
         */
        extract: function (platform, maxResults) {
            switch (platform) {
                case 'linkedin':
                    return this.extractLinkedIn(maxResults);
                case 'twitter':
                    return this.extractTwitter(maxResults);
                case 'facebook':
                    return this.extractFacebook(maxResults);
                case 'youtube':
                    return this.extractYouTube(maxResults);
                case 'instagram':
                    return this.extractInstagram(maxResults);
                default:
                    console.warn('[AutoLeadAgent] Unknown platform:', platform);
                    return [];
            }
        }
    };

})();

