/**
 * ReAct Agent 实现
 * WASM 决策 + JS 执行 + MCP
 */

var ReActAgent = (function () {
    'use strict';

    var modelInference = null;
    var mcpClient = null;
    var wasmBridge = null;
    var maxIterations = 100; // 最大循环次数

    /**
     * 初始化 ReAct Agent
     * @param {Object} options 配置选项
     */
    function init(options) {
        options = options || {};
        maxIterations = options.maxIterations || 100;

        // 初始化依赖
        if (typeof ModelInference !== 'undefined') {
            modelInference = ModelInference;
        }
        if (typeof MCPClient !== 'undefined') {
            mcpClient = MCPClient;
        }
        if (typeof WasmBridge !== 'undefined') {
            wasmBridge = WasmBridge;
        }

        console.log('[ReActAgent] Initialized');
    }

    /**
     * Think 阶段：WASM 决策
     * @param {Object} currentState 当前状态
     * @param {Object} profile 客户画像
     * @param {Array} availableTools 可用工具列表
     * @returns {Promise<Object>} 决策对象
     */
    async function think(currentState, profile, availableTools) {
        if (!modelInference) {
            throw new Error('Model inference is not initialized');
        }

        try {
            // 获取可用工具列表（如果未提供）
            if (!availableTools || availableTools.length === 0) {
                if (mcpClient) {
                    try {
                        availableTools = await mcpClient.listTools();
                        // 如果获取工具失败，使用空数组继续
                        if (!availableTools || !Array.isArray(availableTools)) {
                            console.warn('[ReActAgent] Failed to get tools, using empty list');
                            availableTools = [];
                        }
                    } catch (error) {
                        console.warn('[ReActAgent] Error getting tools:', error.message || error);
                        availableTools = [];
                    }
                } else {
                    availableTools = [];
                }
            }

            // 检测语言
            const language = await modelInference.detectLanguage(
                JSON.stringify(currentState) + JSON.stringify(profile)
            ) || 'zh';

            // 生成决策提示词（包含工具描述）
            const prompt = AutoLeadAgentPrompts.generateDecisionPrompt(
                currentState,
                profile,
                availableTools,
                language
            );

            // 如果 WASM 桥接可用，通过 WASM 调用模型
            if (wasmBridge && wasmBridge.isLoaded()) {
                // WASM 决策
                const decisionJson = await wasmBridge.decideNextAction(
                    JSON.stringify(currentState),
                    JSON.stringify(profile),
                    prompt
                );
                return JSON.parse(decisionJson);
            } else {
                // 直接调用模型
                const decision = await modelInference.generateDecision(prompt);
                return decision;
            }
        } catch (error) {
            console.error('[ReActAgent] Think failed:', error);
            throw error;
        }
    }

    /**
     * Act 阶段：JS 执行 MCP 工具
     * @param {Object} decision 决策对象
     * @returns {Promise<Object>} 工具执行结果
     */
    async function act(decision) {
        if (!mcpClient) {
            throw new Error('MCP client is not initialized');
        }

        try {
            // 解析决策
            if (!decision || !decision.tool) {
                throw new Error('Invalid decision: tool is required');
            }

            const toolName = decision.tool;
            const args = decision.arguments || {};

            console.log('[ReActAgent] Acting:', toolName, args);

            // 调用 MCP 工具
            const result = await mcpClient.callTool(toolName, args);

            return {
                success: true,
                tool: toolName,
                result: result,
            };
        } catch (error) {
            console.error('[ReActAgent] Act failed:', error);
            return {
                success: false,
                error: error.message,
            };
        }
    }

    /**
     * Observe 阶段：更新状态
     * @param {Object} toolResult 工具执行结果
     * @param {Object} currentState 当前状态
     * @returns {Object} 更新后的状态
     */
    function observe(toolResult, currentState) {
        // 更新当前状态
        const newState = {
            ...currentState,
            lastAction: toolResult.tool,
            lastResult: toolResult.result,
            timestamp: Date.now(),
        };

        // 根据工具结果更新状态
        if (toolResult.success && toolResult.result) {
            const result = toolResult.result;

            // 更新 URL（如果是导航操作）
            if (result.url) {
                newState.currentUrl = result.url;
            }

            // 更新页面内容（如果是快照操作；browser_snapshot 返回 textContent）
            if (result.html || result.text || result.textContent) {
                newState.pageContent = result.textContent || result.text || result.html;
            }

            // 更新提取的数据（如果是提取操作）
            if (result.emails || result.phones || result.socialMediaAccounts) {
                if (!newState.extractedData) {
                    newState.extractedData = {};
                }
                if (result.emails) {
                    newState.extractedData.emails = result.emails;
                }
                if (result.phones) {
                    newState.extractedData.phones = result.phones;
                }
                if (result.socialMediaAccounts) {
                    newState.extractedData.socialMediaAccounts = result.socialMediaAccounts;
                }
            }
        }

        // 如果 WASM 桥接可用，更新 WASM 状态
        if (wasmBridge && wasmBridge.isLoaded()) {
            wasmBridge.updateState(JSON.stringify(newState));
        }

        return newState;
    }

    /**
     * ReAct 主循环
     * @param {number} taskId 任务ID
     * @param {Object} profile 客户画像
     * @param {Function} onProgress 进度回调
     * @returns {Promise<Object>} 任务结果
     */
    async function reactLoop(taskId, profile, onProgress) {
        onProgress = onProgress || function() {};

        // 初始化状态
        let currentState = {
            taskId: taskId,
            step: 'initializing',
            currentUrl: null,
            pageContent: null,
            extractedUrls: [],
            foundCustomers: [],
            extractedData: {},
            iteration: 0,
        };

        // 连接 MCP
        if (mcpClient && !mcpClient.isConnected()) {
            await mcpClient.connectMCP();
        }

        // 获取可用工具
        let availableTools = [];
        if (mcpClient) {
            availableTools = await mcpClient.listTools();
        }

        try {
            // ReAct 循环
            for (let i = 0; i < maxIterations; i++) {
                currentState.iteration = i + 1;

                // 1. Think: 模型分析当前状态，生成决策
                onProgress({
                    phase: 'think',
                    iteration: i + 1,
                    state: currentState,
                });

                const decision = await think(currentState, profile, availableTools);

                // 检查是否完成
                if (decision.action === 'complete' || decision.action === 'finish') {
                    console.log('[ReActAgent] Task completed');
                    break;
                }

                // 2. Act: 执行工具调用
                onProgress({
                    phase: 'act',
                    iteration: i + 1,
                    decision: decision,
                });

                const toolResult = await act(decision);

                // 3. Observe: 更新状态
                onProgress({
                    phase: 'observe',
                    iteration: i + 1,
                    result: toolResult,
                });

                currentState = observe(toolResult, currentState);

                // 导航工具成功但无页面内容时，自动快照以填充上下文
                var isNavTool = (decision.tool === 'go_to_url' || decision.tool === 'browser_navigate' || decision.tool === 'search_google' || decision.tool === 'go_back');
                if (isNavTool && toolResult.success && !currentState.pageContent && mcpClient) {
                    await new Promise(function (r) { setTimeout(r, 2000); });
                    try {
                        var snap = await mcpClient.callTool('browser_snapshot', {});
                        if (snap) {
                            var snapResult = (snap.result !== undefined) ? snap.result : snap;
                            currentState = observe({ success: true, tool: 'browser_snapshot', result: snapResult }, currentState);
                        }
                    } catch (e) {
                        console.warn('[ReActAgent] Auto snapshot after navigation failed:', e.message);
                    }
                }

                // 检查是否找到客户
                if (currentState.extractedData && 
                    (currentState.extractedData.emails?.length > 0 ||
                     currentState.extractedData.phones?.length > 0 ||
                     Object.keys(currentState.extractedData.socialMediaAccounts || {}).length > 0)) {
                    // 添加到找到的客户列表
                    currentState.foundCustomers.push({
                        url: currentState.currentUrl,
                        data: currentState.extractedData,
                        timestamp: Date.now(),
                    });
                }

                // 短暂延迟，避免过快执行
                await new Promise(resolve => setTimeout(resolve, 100));
            }

            return {
                success: true,
                foundCustomers: currentState.foundCustomers,
                totalIterations: currentState.iteration,
            };
        } catch (error) {
            console.error('[ReActAgent] Loop failed:', error);
            return {
                success: false,
                error: error.message,
                foundCustomers: currentState.foundCustomers || [],
            };
        }
    }

    // 导出公共 API
    return {
        init: init,
        think: think,
        act: act,
        observe: observe,
        reactLoop: reactLoop,
    };

})();

// 导出到全局
if (typeof window !== 'undefined') {
    window.ReActAgent = ReActAgent;
}

