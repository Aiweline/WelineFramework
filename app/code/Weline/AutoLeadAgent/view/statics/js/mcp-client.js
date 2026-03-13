/**
 * Browser MCP 客户端
 * 连接和工具调用
 * 支持 AutoLeadAgent 扩展（基于 Nanobrowser）、Playwright MCP 和自定义扩展
 */

var MCPClient = (function () {
    'use strict';

    var connected = false;
    var tools = [];
    var connectionId = null;
    var extensionId = null;
    var playwrightMCPDetected = false;
    var autoLeadAgentDetected = false;
    var mcpType = null; // 'autoleadagent' | 'playwright' | 'playwright-server' | 'custom' | null

    // Playwright MCP 服务器默认端口
    var PLAYWRIGHT_MCP_PORTS = [8931, 3000, 8080];
    var playwrightMCPServerUrl = null;
    
    // AutoLeadAgent 扩展 ID 存储键
    var AUTOLEADAGENT_EXTENSION_ID_KEY = 'autoLeadAgentExtensionId';

    /**
     * 检测 AutoLeadAgent 扩展
     * @returns {Promise<boolean>}
     */
    async function detectAutoLeadAgentExtension() {
        // 如果已检测到，直接返回
        if (autoLeadAgentDetected && extensionId) {
            return true;
        }

        // 方法1（优先）: 通过 content script 发现扩展
        // content script 注入到每个页面，通过 window.postMessage 通信
        // 不依赖 chrome.runtime（普通网页上不可用）
        var discovered = await discoverExtensionViaContentScript();
        if (discovered) {
            return true;
        }

        // 方法2: 尝试从 localStorage 获取保存的扩展 ID（仅在 chrome.runtime 可用时有效）
        if (typeof chrome !== 'undefined' && chrome.runtime && chrome.runtime.sendMessage) {
            try {
                var savedId = localStorage.getItem(AUTOLEADAGENT_EXTENSION_ID_KEY);
                if (savedId) {
                    var pingResult = await pingExtension(savedId);
                    if (pingResult) {
                        extensionId = savedId;
                        autoLeadAgentDetected = true;
                        mcpType = 'autoleadagent';
                        console.log('[MCPClient] AutoLeadAgent extension detected (from saved ID):', savedId);
                        return true;
                    }
                }
            } catch (e) {}

            // 方法3: 尝试已知的扩展 ID 列表
            if (typeof window !== 'undefined' && window.AUTOLEADAGENT_EXTENSION_IDS) {
                for (var i = 0; i < window.AUTOLEADAGENT_EXTENSION_IDS.length; i++) {
                    var testId = window.AUTOLEADAGENT_EXTENSION_IDS[i];
                    var pingResult = await pingExtension(testId);
                    if (pingResult) {
                        extensionId = testId;
                        autoLeadAgentDetected = true;
                        mcpType = 'autoleadagent';
                        try { localStorage.setItem(AUTOLEADAGENT_EXTENSION_ID_KEY, testId); } catch (e) {}
                        console.log('[MCPClient] AutoLeadAgent extension detected:', testId);
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * 通过 ping 测试扩展是否响应
     * @param {string} extId 扩展 ID
     * @returns {Promise<boolean>}
     */
    async function pingExtension(extId) {
        if (!extId || typeof chrome === 'undefined' || !chrome.runtime) {
            return false;
        }

        return new Promise(function(resolve) {
            try {
                chrome.runtime.sendMessage(extId, { type: 'PING' }, function(response) {
                    if (chrome.runtime.lastError) {
                        resolve(false);
                    } else {
                        resolve(response && (response.success || response.pong || response.type === 'PONG'));
                    }
                });
                // 超时处理
                setTimeout(function() { resolve(false); }, 2000);
            } catch (e) {
                resolve(false);
            }
        });
    }

    /**
     * 通过 content script 发现扩展
     * @returns {Promise<boolean>}
     */
    async function discoverExtensionViaContentScript() {
        return new Promise(function(resolve) {
            var timeout = null;
            var messageHandler = function(event) {
                if (event.data && 
                    (event.data.type === 'AUTOLEADAGENT_DISCOVERED' || 
                     event.data.type === 'EXTENSION_DISCOVERED') &&
                    event.data.extensionId) {
                    window.removeEventListener('message', messageHandler);
                    clearTimeout(timeout);
                    extensionId = event.data.extensionId;
                    autoLeadAgentDetected = true;
                    mcpType = 'autoleadagent';
                    // 保存到 localStorage
                    try {
                        localStorage.setItem(AUTOLEADAGENT_EXTENSION_ID_KEY, event.data.extensionId);
                    } catch (e) {}
                    console.log('[MCPClient] AutoLeadAgent extension discovered via content script:', event.data.extensionId);
                    resolve(true);
                }
            };

            window.addEventListener('message', messageHandler);

            // 发送发现请求
            window.postMessage({ type: 'DISCOVER_AUTOLEADAGENT_EXTENSION' }, '*');
            window.postMessage({ type: 'DISCOVER_EXTENSION' }, '*');

            // 超时
            timeout = setTimeout(function() {
                window.removeEventListener('message', messageHandler);
                resolve(false);
            }, 2000);
        });
    }

    /**
     * 检测 Playwright MCP（扩展或服务器）
     * @returns {Promise<boolean>}
     */
    async function detectPlaywrightMCP() {
        // 方法1: 检查全局变量（扩展注入）
        if (typeof window !== 'undefined') {
            if (window.__playwright_mcp__ || window.playwrightMCP || window.PlaywrightMCPBridge) {
                console.log('[MCPClient] Playwright MCP detected via global variable');
                playwrightMCPDetected = true;
                mcpType = 'playwright';
                return true;
            }
        }

        // 方法2: 检测 Playwright MCP 服务器（HTTP 端点）
        for (var i = 0; i < PLAYWRIGHT_MCP_PORTS.length; i++) {
            var port = PLAYWRIGHT_MCP_PORTS[i];
            var detected = await checkPlaywrightMCPServer(port);
            if (detected) {
                console.log('[MCPClient] Playwright MCP server detected on port', port);
                playwrightMCPDetected = true;
                mcpType = 'playwright-server';
                playwrightMCPServerUrl = 'http://localhost:' + port;
                return true;
            }
        }

        // 方法3: 检查 DOM 标记
        if (typeof document !== 'undefined') {
            var mcpIndicator = document.querySelector('[data-playwright-mcp]') || 
                               document.querySelector('#playwright-mcp-bridge');
            if (mcpIndicator) {
                console.log('[MCPClient] Playwright MCP detected via DOM');
                playwrightMCPDetected = true;
                mcpType = 'playwright';
                return true;
            }
        }

        // 方法4: 通过 postMessage 探测（扩展 content script）
        var postMessageDetected = await detectViaPostMessage();
        if (postMessageDetected) {
            return true;
        }

        console.log('[MCPClient] Playwright MCP not detected');
        return false;
    }

    /**
     * 检测 Playwright MCP 服务器
     * @param {number} port 端口号
     * @returns {Promise<boolean>}
     */
    async function checkPlaywrightMCPServer(port) {
        try {
            var controller = new AbortController();
            var timeoutId = setTimeout(function() { controller.abort(); }, 2000);

            var response = await fetch('http://localhost:' + port + '/mcp', {
                method: 'OPTIONS',
                signal: controller.signal
            }).catch(function() { return null; });

            clearTimeout(timeoutId);

            if (response && (response.ok || response.status === 204 || response.status === 405)) {
                return true;
            }
        } catch (e) {
            // 忽略错误
        }
        return false;
    }

    /**
     * 通过 postMessage 探测扩展
     * @returns {Promise<boolean>}
     */
    async function detectViaPostMessage() {
        return new Promise(function(resolve) {
            var timeout = null;
            var messageHandler = function(event) {
                if (event.data && (event.data.type === 'PLAYWRIGHT_MCP_PONG' || 
                                   event.data.type === 'MCP_PONG' ||
                                   event.data.source === 'playwright-mcp')) {
                    window.removeEventListener('message', messageHandler);
                    clearTimeout(timeout);
                    console.log('[MCPClient] Playwright MCP detected via postMessage');
                    playwrightMCPDetected = true;
                    mcpType = 'playwright';
                    resolve(true);
                }
            };

            window.addEventListener('message', messageHandler);

            // 发送探测消息
            window.postMessage({ type: 'PLAYWRIGHT_MCP_PING' }, '*');
            window.postMessage({ type: 'MCP_PING' }, '*');

            // 超时
            timeout = setTimeout(function() {
                window.removeEventListener('message', messageHandler);
                resolve(false);
            }, 1000);
        });
    }

    /**
     * 获取扩展 ID
     * @returns {Promise<string|null>} 扩展 ID 或 null
     */
    async function getExtensionId() {
        if (extensionId) {
            return extensionId;
        }

        // 如果已检测到 Playwright MCP，使用 content script 方式
        if (playwrightMCPDetected) {
            return 'playwright-mcp';
        }

        // 尝试从全局函数获取（如果 config-models.js 已加载）
        if (typeof window !== 'undefined' && window.getExtensionId) {
            try {
                extensionId = await window.getExtensionId();
                return extensionId;
            } catch (error) {
                console.warn('[MCPClient] Failed to get extension ID from window.getExtensionId:', error);
            }
        }

        // 尝试从已知的扩展 ID 列表获取
        if (typeof window !== 'undefined' && window.AUTOLEADAGENT_EXTENSION_IDS && window.AUTOLEADAGENT_EXTENSION_IDS.length > 0) {
            // 尝试第一个扩展 ID
            extensionId = window.AUTOLEADAGENT_EXTENSION_IDS[0];
            return extensionId;
        }

        // 如果都没有，返回 null（将使用 content script 方式）
        return null;
    }

    /**
     * 发送消息到扩展（带扩展 ID 检查）
     * @param {Object} message 消息对象
     * @param {Function} callback 回调函数
     */
    function sendMessageToExtension(message, callback) {
        getExtensionId().then(function(extId) {
            if (extId && extId !== 'content-script') {
                // 使用扩展 ID 发送消息
                chrome.runtime.sendMessage(extId, message, callback);
            } else {
                // 使用 content script 方式（通过 window.postMessage）
                if (typeof window !== 'undefined') {
                    window.postMessage({
                        type: 'AUTOLEADAGENT_REQUEST',
                        action: message.type || 'MCP_MESSAGE',
                        payload: message
                    }, '*');
                    // content script 方式需要特殊处理，这里先尝试直接调用
                    if (callback) {
                        callback(null);
                    }
                } else if (callback) {
                    callback(null);
                }
            }
        }).catch(function(error) {
            console.error('[MCPClient] Failed to get extension ID:', error);
            if (callback) {
                callback(null);
            }
        });
    }

    /**
     * 连接到 Browser MCP 扩展
     * 支持 AutoLeadAgent 扩展、Playwright MCP 和自定义扩展
     * @returns {Promise<boolean>} 连接是否成功
     */
    async function connectMCP() {
        try {
            // 首先检测 AutoLeadAgent 扩展
            if (!autoLeadAgentDetected) {
                await detectAutoLeadAgentExtension();
            }

            // 如果检测到 AutoLeadAgent 扩展，通过 chrome.runtime.sendMessage 连接
            if (autoLeadAgentDetected && extensionId) {
                console.log('[MCPClient] Connecting to AutoLeadAgent extension:', extensionId);
                connected = true;
                mcpType = 'autoleadagent';
                connectionId = 'autoleadagent-' + Date.now();
                
                // AutoLeadAgent 扩展的工具列表（基于 Nanobrowser）
                tools = [
                    { name: 'search_google', description: '在 Google 搜索' },
                    { name: 'go_to_url', description: '导航到 URL' },
                    { name: 'browser_navigate', description: '导航到 URL（同 go_to_url）' },
                    { name: 'browser_snapshot', description: '获取页面快照，返回 textContent' },
                    { name: 'browser_extract', description: '提取页面结构化数据（email、phone、social）' },
                    { name: 'go_back', description: '返回上一页' },
                    { name: 'click_element', description: '点击元素' },
                    { name: 'input_text', description: '输入文本' },
                    { name: 'switch_tab', description: '切换标签页' },
                    { name: 'open_tab', description: '打开新标签页' },
                    { name: 'close_tab', description: '关闭标签页' },
                    { name: 'cache_content', description: '缓存内容' },
                    { name: 'scroll_to_percent', description: '滚动到百分比位置' },
                    { name: 'scroll_to_text', description: '滚动到文本位置' },
                    { name: 'send_keys', description: '发送按键' },
                    { name: 'get_dropdown_options', description: '获取下拉选项' },
                    { name: 'select_dropdown_option', description: '选择下拉选项' },
                    { name: 'wait', description: '等待' },
                    { name: 'done', description: '完成任务' }
                ];
                
                console.log('[MCPClient] Connected to AutoLeadAgent extension, tools:', tools.length);
                return true;
            }

            // 检测 Playwright MCP
            if (!playwrightMCPDetected) {
                await detectPlaywrightMCP();
            }

            // 如果检测到 Playwright MCP，使用 postMessage 方式连接
            if (playwrightMCPDetected) {
                console.log('[MCPClient] Connecting to Playwright MCP via postMessage');
                connected = true;
                mcpType = 'playwright';
                connectionId = 'playwright-' + Date.now();
                
                // Playwright MCP 的工具列表
                tools = [
                    { name: 'browser_navigate', description: '导航到URL' },
                    { name: 'browser_snapshot', description: '获取页面快照，返回 textContent 供模型分析' },
                    { name: 'browser_extract', description: '提取页面结构化数据（email、phone、social 等）' },
                    { name: 'browser_click', description: '点击元素' },
                    { name: 'browser_type', description: '输入文本' },
                    { name: 'browser_fill_form', description: '填写表单' },
                    { name: 'browser_select_option', description: '选择下拉选项' },
                    { name: 'browser_hover', description: '鼠标悬停' },
                    { name: 'browser_press_key', description: '按键' },
                    { name: 'browser_wait_for', description: '等待元素' },
                    { name: 'browser_take_screenshot', description: '截图' },
                    { name: 'browser_tabs', description: '管理标签页' },
                    { name: 'browser_close', description: '关闭页面' }
                ];
                
                console.log('[MCPClient] Connected to Playwright MCP, tools:', tools.length);
                return true;
            }

            // 检查自定义扩展
            if (typeof chrome === 'undefined' || !chrome.runtime) {
                throw new Error('Chrome Extension API is not available');
            }

            // 获取扩展 ID
            const extId = await getExtensionId();
            if (!extId || extId === 'content-script' || extId === 'playwright-mcp' || extId === 'autoleadagent') {
                console.warn('[MCPClient] Extension ID not available, MCP connection not possible');
                console.warn('[MCPClient] MCP tools will not be available, but agent can still work without tools');
                connected = false;
                return false;
            }

            // 尝试连接自定义扩展
            const response = await new Promise((resolve, reject) => {
                chrome.runtime.sendMessage(extId, {
                    type: 'MCP_CONNECT',
                    action: 'connect'
                }, function(response) {
                    if (chrome.runtime.lastError) {
                        reject(new Error(chrome.runtime.lastError.message));
                    } else {
                        resolve(response);
                    }
                });
            });

            if (response && response.success) {
                connected = true;
                mcpType = 'custom';
                connectionId = response.connectionId;
                tools = response.tools || [];
                console.log('[MCPClient] Connected to Custom MCP, tools:', tools.length);
                return true;
            } else {
                throw new Error('Failed to connect to MCP');
            }
        } catch (error) {
            console.error('[MCPClient] Connection failed:', error);
            connected = false;
            return false;
        }
    }

    /**
     * 断开 MCP 连接
     */
    async function disconnectMCP() {
        if (connected && connectionId) {
            try {
                const extId = await getExtensionId();
                if (extId && extId !== 'content-script') {
                    chrome.runtime.sendMessage(extId, {
                        type: 'MCP_DISCONNECT',
                        connectionId: connectionId
                    });
                }
            } catch (error) {
                console.error('[MCPClient] Disconnect error:', error);
            }
        }
        connected = false;
        connectionId = null;
        tools = [];
    }

    /**
     * 检查 Browser MCP 扩展是否可用
     * 优先检测 AutoLeadAgent 扩展，然后是 Playwright MCP
     * @returns {Promise<boolean>} 是否可用
     */
    async function isMCPAvailable() {
        try {
            // 首先检测 AutoLeadAgent 扩展
            var autoLeadAgentDetected = await detectAutoLeadAgentExtension();
            if (autoLeadAgentDetected) {
                console.log('[MCPClient] AutoLeadAgent extension is available');
                return true;
            }

            // 然后检测 Playwright MCP
            var playwrightDetected = await detectPlaywrightMCP();
            if (playwrightDetected) {
                console.log('[MCPClient] Playwright MCP is available');
                return true;
            }

            // 检测自定义扩展
            if (typeof chrome === 'undefined' || !chrome.runtime) {
                console.log('[MCPClient] Chrome runtime not available');
                return false;
            }

            const extId = await getExtensionId();
            if (!extId || extId === 'content-script') {
                console.log('[MCPClient] No extension ID found');
                return false;
            }

            // 如果是特殊标记，已经在上面检测过了
            if (extId === 'playwright-mcp' || extId === 'autoleadagent') {
                return true;
            }

            const response = await new Promise((resolve) => {
                chrome.runtime.sendMessage(extId, {
                    type: 'MCP_PING'
                }, function(response) {
                    if (chrome.runtime.lastError) {
                        resolve(false);
                    } else {
                        resolve(response && response.success);
                    }
                });
            });

            return response === true;
        } catch (error) {
            console.warn('[MCPClient] MCP availability check error:', error);
            return false;
        }
    }

    /**
     * 获取可用的 MCP 工具列表
     * @returns {Promise<Array>} 工具列表
     */
    async function listTools() {
        // 如果未连接，尝试连接
        if (!connected) {
            const connected = await connectMCP();
            // 如果连接失败，返回空数组而不是抛出错误
            if (!connected) {
                console.warn('[MCPClient] MCP not connected, returning empty tools list');
                return [];
            }
        }

        // 如果工具列表为空，尝试获取
        if (tools.length === 0) {
            try {
                const extId = await getExtensionId();
                if (!extId || extId === 'content-script') {
                    console.warn('[MCPClient] Extension ID not available, returning empty tools list');
                    return [];
                }

                const response = await new Promise((resolve, reject) => {
                    chrome.runtime.sendMessage(extId, {
                        type: 'MCP_LIST_TOOLS',
                        connectionId: connectionId
                    }, function(response) {
                        if (chrome.runtime.lastError) {
                            reject(new Error(chrome.runtime.lastError.message));
                        } else {
                            resolve(response);
                        }
                    });
                });

                if (response && response.tools) {
                    tools = response.tools;
                }
            } catch (error) {
                console.warn('[MCPClient] Failed to list tools:', error.message || error);
                // 返回空数组而不是抛出错误，让智能体可以在没有工具的情况下继续工作
                return [];
            }
        }

        return tools;
    }

    /**
     * 调用 MCP 工具
     * 支持 AutoLeadAgent 扩展、Playwright MCP（postMessage）和自定义扩展
     * @param {string} toolName 工具名称
     * @param {Object} args 工具参数
     * @returns {Promise<Object>} 工具执行结果
     */
    async function callTool(toolName, args) {
        if (!connected) {
            await connectMCP();
        }

        try {
            // AutoLeadAgent 扩展使用 chrome.runtime.sendMessage
            if (mcpType === 'autoleadagent' && extensionId) {
                return await callAutoLeadAgentTool(toolName, args);
            }

            // Playwright MCP 使用 postMessage
            if (mcpType === 'playwright' || mcpType === 'playwright-server' || playwrightMCPDetected) {
                return await callPlaywrightMCPTool(toolName, args);
            }

            // 自定义扩展使用 chrome.runtime.sendMessage
            const extId = await getExtensionId();
            if (!extId || extId === 'content-script' || extId === 'playwright-mcp' || extId === 'autoleadagent') {
                throw new Error('Extension ID not available');
            }

            const response = await new Promise((resolve, reject) => {
                chrome.runtime.sendMessage(extId, {
                    type: 'MCP_CALL_TOOL',
                    connectionId: connectionId,
                    tool: toolName,
                    arguments: args || {}
                }, function(response) {
                    if (chrome.runtime.lastError) {
                        reject(new Error(chrome.runtime.lastError.message));
                    } else {
                        resolve(response);
                    }
                });
            });

            if (response && response.success) {
                return response.result || {};
            } else {
                throw new Error(response?.error || 'Tool call failed');
            }
        } catch (error) {
            console.error('[MCPClient] Tool call failed:', error);
            throw error;
        }
    }

    /**
     * 通过 content script 中继发送消息到扩展后台
     * 兼容普通网页（chrome.runtime 不可用的场景）
     */
    function sendViaContentScript(payload, timeoutMs) {
        timeoutMs = timeoutMs || 60000;
        return new Promise(function(resolve, reject) {
            var requestId = 'mcp_' + Date.now() + '_' + Math.random().toString(36).substr(2, 6);
            var done = false;
            var timer = setTimeout(function() {
                if (done) return;
                done = true;
                window.removeEventListener('message', handler);
                reject(new Error('Extension request timeout (' + Math.round(timeoutMs / 1000) + 's)'));
            }, timeoutMs);

            function handler(event) {
                if (event.source !== window || !event.data) return;
                if (event.data.type === 'AUTOLEADAGENT_RESPONSE' && event.data.requestId === requestId) {
                    if (done) return;
                    done = true;
                    window.removeEventListener('message', handler);
                    clearTimeout(timer);
                    var resp = event.data.response;
                    var err = event.data.error;
                    if (err) { reject(new Error(err)); }
                    else if (resp && resp.error) { reject(new Error(resp.error)); }
                    else { resolve(resp || { success: true }); }
                }
            }
            window.addEventListener('message', handler);

            // 通过 content script 中继
            window.postMessage({
                type: 'AUTOLEADAGENT_REQUEST',
                payload: payload,
                requestId: requestId
            }, '*');
        });
    }

    /**
     * 调用 AutoLeadAgent 扩展工具
     * 使用 WASM_EXECUTE_TOOL 协议直接调用工具（比 EXECUTE_TASK 自然语言包装更可靠）
     * 优先通过 content script 中继（兼容普通网页），回退到 chrome.runtime 直连
     */
    async function callAutoLeadAgentTool(toolName, args) {
        var toolCallId = 'tc_' + Date.now() + '_' + Math.random().toString(36).substr(2, 4);
        var payload = { type: 'WASM_EXECUTE_TOOL', id: toolCallId, name: toolName, arguments: args || {} };

        // 优先通过 content script 中继（普通网页上 chrome.runtime 不可用）
        try {
            return await sendViaContentScript(payload, 60000);
        } catch (relayErr) {
            console.warn('[MCPClient] Content script relay failed, trying direct:', relayErr.message);
        }

        // 回退：直连 chrome.runtime（仅在扩展页面中可用）
        if (typeof chrome !== 'undefined' && chrome.runtime && chrome.runtime.sendMessage && extensionId) {
            return new Promise(function(resolve, reject) {
                chrome.runtime.sendMessage(extensionId, payload, function(response) {
                    if (chrome.runtime.lastError) reject(new Error(chrome.runtime.lastError.message));
                    else if (response && response.error) reject(new Error(typeof response.error === 'object' ? response.error.message : response.error));
                    else resolve(response || { success: true });
                });
                setTimeout(function() { reject(new Error('Direct call timeout (60s)')); }, 60000);
            });
        }

        throw new Error('Cannot communicate with extension: no relay or chrome.runtime available');
    }

    /**
     * 将工具调用转换为自然语言任务描述
     * @param {string} toolName 工具名称
     * @param {Object} args 工具参数
     * @returns {string} 任务描述
     */
    function formatTaskFromTool(toolName, args) {
        switch (toolName) {
            case 'search_google':
                return '在 Google 搜索: ' + (args.query || args.text || '');
            case 'go_to_url':
                return '导航到: ' + (args.url || '');
            case 'go_back':
                return '返回上一页';
            case 'click_element':
                return '点击元素: ' + (args.index !== undefined ? '索引 ' + args.index : (args.selector || args.text || ''));
            case 'input_text':
                return '在元素 ' + (args.index !== undefined ? '索引 ' + args.index : args.selector) + ' 输入: ' + (args.text || '');
            case 'switch_tab':
                return '切换到标签页: ' + (args.tab_id || args.index || '');
            case 'open_tab':
                return '在新标签页打开: ' + (args.url || '');
            case 'close_tab':
                return '关闭标签页: ' + (args.tab_id || '当前');
            case 'cache_content':
                return '缓存内容: ' + (args.content || '');
            case 'scroll_to_percent':
                return '滚动到 ' + (args.yPercent || 0) + '%';
            case 'scroll_to_text':
                return '滚动到文本: ' + (args.text || '');
            case 'send_keys':
                return '发送按键: ' + (args.keys || '');
            case 'get_dropdown_options':
                return '获取下拉选项: 元素索引 ' + (args.index || 0);
            case 'select_dropdown_option':
                return '选择下拉选项: ' + (args.text || args.value || '');
            case 'wait':
                return '等待 ' + (args.seconds || 3) + ' 秒';
            case 'done':
                return '任务完成: ' + (args.text || '');
            default:
                return toolName + ': ' + JSON.stringify(args);
        }
    }

    /**
     * 调用 Playwright MCP 工具
     * 支持服务器模式（HTTP）和扩展模式（postMessage）
     * @param {string} toolName 工具名称
     * @param {Object} args 工具参数
     * @returns {Promise<Object>} 工具执行结果
     */
    async function callPlaywrightMCPTool(toolName, args) {
        // 服务器模式：通过 HTTP API 调用
        if (mcpType === 'playwright-server' && playwrightMCPServerUrl) {
            return await callPlaywrightMCPViaHTTP(toolName, args);
        }

        // 扩展模式：通过 postMessage 调用
        return await callPlaywrightMCPViaPostMessage(toolName, args);
    }

    /**
     * 通过 HTTP API 调用 Playwright MCP 服务器
     */
    async function callPlaywrightMCPViaHTTP(toolName, args) {
        try {
            var response = await fetch(playwrightMCPServerUrl + '/mcp', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    jsonrpc: '2.0',
                    id: Date.now(),
                    method: 'tools/call',
                    params: {
                        name: toolName,
                        arguments: args || {}
                    }
                })
            });

            if (!response.ok) {
                throw new Error('HTTP ' + response.status);
            }

            var result = await response.json();
            if (result.error) {
                throw new Error(result.error.message || 'MCP error');
            }

            return result.result || {};
        } catch (error) {
            console.error('[MCPClient] HTTP call failed:', error);
            throw error;
        }
    }

    /**
     * 通过 postMessage 调用 Playwright MCP 扩展
     */
    async function callPlaywrightMCPViaPostMessage(toolName, args) {
        return new Promise(function(resolve, reject) {
            var callId = 'mcp_call_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9);
            var timeout = null;

            var messageHandler = function(event) {
                if (event.data && 
                    (event.data.type === 'PLAYWRIGHT_MCP_RESULT' || event.data.type === 'MCP_RESULT') && 
                    event.data.callId === callId) {
                    window.removeEventListener('message', messageHandler);
                    clearTimeout(timeout);

                    if (event.data.error) {
                        reject(new Error(event.data.error));
                    } else {
                        resolve(event.data.result || {});
                    }
                }
            };

            window.addEventListener('message', messageHandler);

            // 发送工具调用请求
            window.postMessage({
                type: 'PLAYWRIGHT_MCP_CALL',
                callId: callId,
                tool: toolName,
                arguments: args || {}
            }, '*');

            // 超时处理
            timeout = setTimeout(function() {
                window.removeEventListener('message', messageHandler);
                reject(new Error('Playwright MCP tool call timeout (30s). Please ensure the MCP server is running: npx @playwright/mcp@latest --port 8931'));
            }, 30000);
        });
    }

    /**
     * 获取工具的 schema 定义
     * @param {string} toolName 工具名称
     * @returns {Promise<Object>} Schema 定义
     */
    async function getToolSchema(toolName) {
        const toolList = await listTools();
        const tool = toolList.find(t => t.name === toolName);
        return tool || null;
    }

    /**
     * 检查连接状态
     * @returns {boolean} 是否已连接
     */
    function isConnected() {
        return connected;
    }

    // ========================================================================
    // WASM 智能体直接工具调用接口（不需要 MCP 连接）
    // ========================================================================

    /**
     * WASM 智能体直接调用工具
     * 不需要先建立 MCP 连接，直接发送工具调用请求
     * 
     * @param {string} toolName 工具名称
     * @param {Object} args 工具参数
     * @param {Object} meta 元信息 { id, taskId, iteration, origin }
     * @returns {Promise<Object>} 工具执行结果
     */
    async function executeToolForWasm(toolName, args, meta) {
        var payload = {
            type: 'WASM_EXECUTE_TOOL',
            id: meta && meta.id ? meta.id : ('tc_' + Date.now()),
            name: toolName,
            arguments: args || {},
            meta: meta || {}
        };

        // 优先 content script 中继
        try {
            return await sendViaContentScript(payload, 60000);
        } catch (relayErr) {
            console.warn('[MCPClient] WASM tool relay failed, trying direct:', relayErr.message);
        }

        // 回退：直连
        try {
            if (typeof chrome !== 'undefined' && chrome.runtime && chrome.runtime.sendMessage) {
                var extId = await getExtensionId();
                if (extId && extId !== 'content-script') {
                    return await new Promise(function(resolve, reject) {
                        chrome.runtime.sendMessage(extId, payload, function(response) {
                            if (chrome.runtime.lastError) reject(new Error(chrome.runtime.lastError.message));
                            else resolve(response);
                        });
                    });
                }
            }
        } catch (directErr) {
            console.warn('[MCPClient] WASM tool direct call failed:', directErr.message);
        }

        return {
            success: false,
            name: toolName,
            error: { code: 'EXECUTION_ERROR', message: 'Cannot reach extension via relay or direct' }
        };
    }

    /**
     * WASM 智能体批量调用工具
     * 
     * @param {Array} calls 工具调用列表 [{ id, name, arguments }, ...]
     * @param {Object} meta 元信息 { taskId, iteration }
     * @returns {Promise<Object>} 批量执行结果
     */
    async function executeToolsBatchForWasm(calls, meta) {
        var payload = {
            type: 'WASM_EXECUTE_TOOLS_BATCH',
            calls: calls,
            meta: meta || {}
        };

        // 优先 content script 中继
        try {
            return await sendViaContentScript(payload, 120000);
        } catch (relayErr) {
            console.warn('[MCPClient] WASM batch relay failed, trying direct:', relayErr.message);
        }

        // 回退：直连
        try {
            if (typeof chrome !== 'undefined' && chrome.runtime && chrome.runtime.sendMessage) {
                var extId = await getExtensionId();
                if (extId && extId !== 'content-script') {
                    return await new Promise(function(resolve, reject) {
                        chrome.runtime.sendMessage(extId, payload, function(response) {
                            if (chrome.runtime.lastError) reject(new Error(chrome.runtime.lastError.message));
                            else resolve(response);
                        });
                    });
                }
            }
        } catch (directErr) {
            console.warn('[MCPClient] WASM batch direct call failed:', directErr.message);
        }

        return {
            success: false,
            error: { code: 'BATCH_EXECUTION_ERROR', message: 'Cannot reach extension via relay or direct' },
            results: []
        };
    }

    /**
     * 获取 MCP 状态信息
     * @returns {Object} 状态信息
     */
    function getMCPStatus() {
        return {
            connected: connected,
            mcpType: mcpType,
            autoLeadAgentDetected: autoLeadAgentDetected,
            playwrightMCPDetected: playwrightMCPDetected,
            playwrightMCPServerUrl: playwrightMCPServerUrl,
            extensionId: extensionId,
            toolsCount: tools.length
        };
    }

    /**
     * 重新检测 MCP
     * @returns {Promise<boolean>}
     */
    async function redetectMCP() {
        // 重置状态
        autoLeadAgentDetected = false;
        playwrightMCPDetected = false;
        mcpType = null;
        playwrightMCPServerUrl = null;
        connected = false;
        extensionId = null;
        
        // 重新检测
        return await isMCPAvailable();
    }

    /**
     * 发送任务给 AutoLeadAgent 扩展
     * 这是一个高级接口，直接发送自然语言任务
     * @param {string} task 任务描述（自然语言）
     * @returns {Promise<Object>} 任务执行结果
     */
    async function sendTaskToExtension(task) {
        if (!autoLeadAgentDetected || !extensionId) {
            await detectAutoLeadAgentExtension();
        }

        var payload = { type: 'EXECUTE_TASK', command: 'newTask', task: task };

        // 优先通过 content script 中继
        try {
            return await sendViaContentScript(payload, 120000);
        } catch (relayErr) {
            console.warn('[MCPClient] sendTaskToExtension relay failed:', relayErr.message);
        }

        // 回退：直连
        if (typeof chrome !== 'undefined' && chrome.runtime && chrome.runtime.sendMessage && extensionId) {
            return new Promise(function(resolve, reject) {
                chrome.runtime.sendMessage(extensionId, payload, function(response) {
                    if (chrome.runtime.lastError) reject(new Error(chrome.runtime.lastError.message));
                    else if (response && response.error) reject(new Error(response.error));
                    else resolve(response || { success: true });
                });
            });
        }

        throw new Error('AutoLeadAgent extension not found or not reachable.');
    }

    /**
     * 打开 AutoLeadAgent 扩展的侧边栏
     * @returns {Promise<boolean>}
     */
    async function openExtensionSidePanel() {
        if (!autoLeadAgentDetected || !extensionId) {
            await detectAutoLeadAgentExtension();
        }

        try {
            return await sendViaContentScript({ type: 'OPEN_SIDE_PANEL' }, 5000);
        } catch (e) {
            // 回退：直连
            if (typeof chrome !== 'undefined' && chrome.runtime && chrome.runtime.sendMessage && extensionId) {
                return new Promise(function(resolve, reject) {
                    chrome.runtime.sendMessage(extensionId, { type: 'OPEN_SIDE_PANEL' }, function(response) {
                        if (chrome.runtime.lastError) reject(new Error(chrome.runtime.lastError.message));
                        else resolve(true);
                    });
                });
            }
            throw new Error('AutoLeadAgent extension not found');
        }
    }

    // 导出公共 API
    return {
        // 标准 MCP 接口
        connectMCP: connectMCP,
        disconnectMCP: disconnectMCP,
        isMCPAvailable: isMCPAvailable,
        listTools: listTools,
        callTool: callTool,
        getToolSchema: getToolSchema,
        isConnected: isConnected,

        // AutoLeadAgent 扩展专用
        detectAutoLeadAgentExtension: detectAutoLeadAgentExtension,
        sendTaskToExtension: sendTaskToExtension,
        openExtensionSidePanel: openExtensionSidePanel,

        // Playwright MCP 专用
        detectPlaywrightMCP: detectPlaywrightMCP,
        redetectMCP: redetectMCP,
        getMCPStatus: getMCPStatus,

        // WASM 智能体直接调用接口
        executeToolForWasm: executeToolForWasm,
        executeToolsBatchForWasm: executeToolsBatchForWasm
    };

})();

// 导出到全局
if (typeof window !== 'undefined') {
    window.MCPClient = MCPClient;
}

