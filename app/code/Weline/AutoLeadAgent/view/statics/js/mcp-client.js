/**
 * Browser MCP 客户端
 * 连接和工具调用
 */

var MCPClient = (function () {
    'use strict';

    var connected = false;
    var tools = [];
    var connectionId = null;
    var extensionId = null;

    /**
     * 获取扩展 ID
     * @returns {Promise<string|null>} 扩展 ID 或 null
     */
    async function getExtensionId() {
        if (extensionId) {
            return extensionId;
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
     * @returns {Promise<boolean>} 连接是否成功
     */
    async function connectMCP() {
        try {
            // 检查扩展是否可用
            if (typeof chrome === 'undefined' || !chrome.runtime) {
                throw new Error('Chrome Extension API is not available');
            }

            // 获取扩展 ID
            const extId = await getExtensionId();
            if (!extId || extId === 'content-script') {
                console.warn('[MCPClient] Extension ID not available, MCP connection not possible');
                console.warn('[MCPClient] MCP tools will not be available, but agent can still work without tools');
                connected = false;
                return false;
            }

            // 尝试连接扩展
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
                connectionId = response.connectionId;
                tools = response.tools || [];
                console.log('[MCPClient] Connected to Browser MCP, tools:', tools.length);
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
     * @returns {Promise<boolean>} 是否可用
     */
    async function isMCPAvailable() {
        try {
            if (typeof chrome === 'undefined' || !chrome.runtime) {
                return false;
            }

            const extId = await getExtensionId();
            if (!extId || extId === 'content-script') {
                return false;
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
     * @param {string} toolName 工具名称
     * @param {Object} args 工具参数
     * @returns {Promise<Object>} 工具执行结果
     */
    async function callTool(toolName, args) {
        if (!connected) {
            await connectMCP();
        }

        try {
            const extId = await getExtensionId();
            if (!extId || extId === 'content-script') {
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
        try {
            if (typeof chrome === 'undefined' || !chrome.runtime) {
                throw new Error('Chrome Extension API is not available');
            }

            const extId = await getExtensionId();
            if (!extId || extId === 'content-script') {
                throw new Error('Extension ID not available');
            }

            const response = await new Promise((resolve, reject) => {
                chrome.runtime.sendMessage(extId, {
                    type: 'WASM_EXECUTE_TOOL',
                    id: meta && meta.id ? meta.id : ('tc_' + Date.now()),
                    name: toolName,
                    arguments: args || {},
                    meta: meta || {}
                }, function(response) {
                    if (chrome.runtime.lastError) {
                        reject(new Error(chrome.runtime.lastError.message));
                    } else {
                        resolve(response);
                    }
                });
            });

            return response;

        } catch (error) {
            console.error('[MCPClient] WASM tool execution failed:', error);
            return {
                success: false,
                name: toolName,
                error: { code: 'EXECUTION_ERROR', message: error.message }
            };
        }
    }

    /**
     * WASM 智能体批量调用工具
     * 
     * @param {Array} calls 工具调用列表 [{ id, name, arguments }, ...]
     * @param {Object} meta 元信息 { taskId, iteration }
     * @returns {Promise<Object>} 批量执行结果
     */
    async function executeToolsBatchForWasm(calls, meta) {
        try {
            if (typeof chrome === 'undefined' || !chrome.runtime) {
                throw new Error('Chrome Extension API is not available');
            }

            const extId = await getExtensionId();
            if (!extId || extId === 'content-script') {
                throw new Error('Extension ID not available');
            }

            const response = await new Promise((resolve, reject) => {
                chrome.runtime.sendMessage(extId, {
                    type: 'WASM_EXECUTE_TOOLS_BATCH',
                    calls: calls,
                    meta: meta || {}
                }, function(response) {
                    if (chrome.runtime.lastError) {
                        reject(new Error(chrome.runtime.lastError.message));
                    } else {
                        resolve(response);
                    }
                });
            });

            return response;

        } catch (error) {
            console.error('[MCPClient] WASM batch tool execution failed:', error);
            return {
                success: false,
                error: { code: 'BATCH_EXECUTION_ERROR', message: error.message },
                results: []
            };
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

        // WASM 智能体直接调用接口
        executeToolForWasm: executeToolForWasm,
        executeToolsBatchForWasm: executeToolsBatchForWasm
    };

})();

// 导出到全局
if (typeof window !== 'undefined') {
    window.MCPClient = MCPClient;
}

