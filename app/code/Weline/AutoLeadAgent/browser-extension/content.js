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

    // 监听来自网页的消息
    window.addEventListener('message', function (event) {
        // 验证消息来源
        if (event.source !== window) return;
        if (!event.data || event.data.type !== 'AUTOLEADAGENT_REQUEST') return;

        console.log('[AutoLeadAgent Content] Received message from page:', event.data);

        const { action, payload, requestId } = event.data;

        // 转发到后台脚本
        // 注意：对于异步操作（如crawl），需要保持消息通道开放
        console.log('[AutoLeadAgent Content] 转发消息到 background:', { action, payload, requestId });

        // 添加超时检测机制
        // 对于 crawl 操作，不设置超时（因为需要几分钟时间）
        // 对于 ping 操作，使用短超时（5秒）
        let responseReceived = false;
        let timeoutId = null;

        // 只有 ping 操作才设置短超时，crawl 操作不设置超时
        if (action === 'ping') {
            timeoutId = setTimeout(() => {
                if (!responseReceived) {
                    console.error('[AutoLeadAgent Content] Background 响应超时（5秒），可能 background script 未运行');
                    // 返回错误响应给网页
                    window.postMessage({
                        type: 'AUTOLEADAGENT_RESPONSE',
                        requestId: requestId,
                        response: {
                            success: false,
                            error: 'Background script 响应超时，请检查扩展是否正常运行',
                            errorType: 'timeout_error'
                        }
                    }, '*');
                }
            }, 5000);
        } else if (action === 'crawl') {
            // crawl 操作可能需要很长时间（几分钟），不设置超时
            // 但可以设置一个很长的超时作为安全措施（10分钟）
            timeoutId = setTimeout(() => {
                if (!responseReceived) {
                    console.error('[AutoLeadAgent Content] Background crawl 响应超时（10分钟），可能 background script 处理时间过长');
                    // 返回错误响应给网页
                    window.postMessage({
                        type: 'AUTOLEADAGENT_RESPONSE',
                        requestId: requestId,
                        response: {
                            success: false,
                            error: 'Background script 处理时间过长（超过10分钟），请检查网络连接或搜索引擎状态',
                            errorType: 'timeout_error'
                        }
                    }, '*');
                }
            }, 600000); // 10分钟超时
        }

        try {
            // 检查扩展是否可用
            if (!chrome.runtime || !chrome.runtime.sendMessage) {
                throw new Error('扩展上下文无效：扩展可能已被重新加载，请刷新页面');
            }

            chrome.runtime.sendMessage({
                action: action,
                ...payload,
                requestId: requestId
            }, function (response) {
                responseReceived = true;
                clearTimeout(timeoutId);

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
            clearTimeout(timeoutId);
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

    // 监听来自后台脚本的日志消息
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

        // 处理其他消息类型
        return false;
    });

    // 添加错误处理，确保消息不会丢失
    window.addEventListener('error', function (event) {
        console.error('[AutoLeadAgent Content] 页面错误:', event.error);
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

