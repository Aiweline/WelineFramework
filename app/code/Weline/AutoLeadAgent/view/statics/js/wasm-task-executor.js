/**
 * WASM 任务执行器
 * 
 * WASM 主导决策，JS 只负责：
 * 1. 任务配置注入
 * 2. MCP 工具执行
 * 3. 状态心跳与日志
 * 
 * 架构：
 * - WASM (agent_brain.cpp): ReAct 决策循环、状态管理、MCP 调用编码
 * - JS (此文件): 任务启动、工具执行、结果回写、UI 更新
 */

var WasmTaskExecutor = (function () {
    'use strict';

    // ========================================================================
    // 状态定义
    // ========================================================================
    var ExecutorState = {
        IDLE: 'idle',
        LOADING: 'loading',
        RUNNING: 'running',
        PAUSED: 'paused',
        COMPLETED: 'completed',
        ERROR: 'error'
    };

    // 当前状态
    var state = {
        status: ExecutorState.IDLE,
        taskId: null,
        wasmLoaded: false,
        loopRunning: false,
        loopInterval: null,
        statusInterval: null,
        lastStatus: null,
        startTime: null,
        pendingToolCall: null
    };

    // 配置
    var config = {
        wasmPath: null,
        loopIntervalMs: 200,      // 决策循环间隔
        statusIntervalMs: 500,    // 状态更新间隔
        toolTimeoutMs: 30000      // 工具执行超时
    };

    // 回调
    var callbacks = {
        onStatusChange: null,
        onLog: null,
        onProgress: null,
        onComplete: null,
        onError: null
    };

    // ========================================================================
    // 初始化
    // ========================================================================

    /**
     * 初始化执行器
     * @param {Object} options 配置选项
     */
    function init(options) {
        options = options || {};
        
        if (options.wasmPath) config.wasmPath = options.wasmPath;
        if (options.loopIntervalMs) config.loopIntervalMs = options.loopIntervalMs;
        if (options.statusIntervalMs) config.statusIntervalMs = options.statusIntervalMs;
        if (options.toolTimeoutMs) config.toolTimeoutMs = options.toolTimeoutMs;

        // 设置回调
        if (options.onStatusChange) callbacks.onStatusChange = options.onStatusChange;
        if (options.onLog) callbacks.onLog = options.onLog;
        if (options.onProgress) callbacks.onProgress = options.onProgress;
        if (options.onComplete) callbacks.onComplete = options.onComplete;
        if (options.onError) callbacks.onError = options.onError;

        log('info', 'WASM 任务执行器初始化完成');
    }

    /**
     * 加载 WASM 模块
     * @returns {Promise<boolean>}
     */
    async function loadWasm() {
        if (state.wasmLoaded) {
            return true;
        }

        if (typeof WasmBridge === 'undefined') {
            log('error', 'WasmBridge 未定义');
            return false;
        }

        try {
            updateStatus(ExecutorState.LOADING);
            log('info', '正在加载 WASM 模块...');

            var wasmPath = config.wasmPath || 'agent-core.wasm';
            await WasmBridge.loadWasmModule(wasmPath);

            if (WasmBridge.isLoaded()) {
                state.wasmLoaded = true;
                log('info', 'WASM 模块加载成功，版本: ' + WasmBridge.getVersion());
                return true;
            } else {
                log('error', 'WASM 模块加载失败');
                return false;
            }

        } catch (error) {
            log('error', 'WASM 模块加载失败: ' + error.message);
            return false;
        }
    }

    // ========================================================================
    // 任务管理
    // ========================================================================

    /**
     * 启动任务（WASM 主导）
     * @param {Object} taskConfig 任务配置
     * @returns {Promise<boolean>}
     */
    async function startTask(taskConfig) {
        if (state.status === ExecutorState.RUNNING) {
            log('warn', '任务已在运行中');
            return false;
        }

        // 确保 WASM 已加载
        if (!state.wasmLoaded) {
            var loaded = await loadWasm();
            if (!loaded) {
                updateStatus(ExecutorState.ERROR);
                return false;
            }
        }

        // 验证 MCP 可用性
        if (!await checkMCPAvailability()) {
            log('error', 'MCP 扩展不可用');
            updateStatus(ExecutorState.ERROR);
            return false;
        }

        try {
            state.taskId = taskConfig.taskId || ('task_' + Date.now());
            state.startTime = Date.now();
            state.pendingToolCall = null;

            log('info', '启动任务: ' + state.taskId);

            // 构建 WASM 任务配置
            var wasmConfig = buildWasmTaskConfig(taskConfig);

            // 启动 WASM 任务
            await WasmBridge.startTaskInWasm(wasmConfig, {
                onDecision: handleWasmDecision,
                onStatus: handleWasmStatus,
                onComplete: handleWasmComplete,
                onError: handleWasmError,
                onLog: handleWasmLog
            });

            updateStatus(ExecutorState.RUNNING);

            // 启动决策循环
            startDecisionLoop();

            return true;

        } catch (error) {
            log('error', '任务启动失败: ' + error.message);
            updateStatus(ExecutorState.ERROR);
            if (callbacks.onError) {
                callbacks.onError({ message: error.message });
            }
            return false;
        }
    }

    /**
     * 停止任务
     */
    function stopTask() {
        log('info', '停止任务: ' + state.taskId);

        stopDecisionLoop();
        
        if (WasmBridge && WasmBridge.stopTask) {
            WasmBridge.stopTask();
        }

        updateStatus(ExecutorState.IDLE);
    }

    /**
     * 暂停任务
     */
    function pauseTask() {
        if (state.status !== ExecutorState.RUNNING) {
            return;
        }

        log('info', '暂停任务');
        stopDecisionLoop();
        updateStatus(ExecutorState.PAUSED);
    }

    /**
     * 恢复任务
     */
    function resumeTask() {
        if (state.status !== ExecutorState.PAUSED) {
            return;
        }

        log('info', '恢复任务');
        startDecisionLoop();
        updateStatus(ExecutorState.RUNNING);
    }

    // ========================================================================
    // 决策循环
    // ========================================================================

    /**
     * 启动决策循环
     */
    function startDecisionLoop() {
        if (state.loopRunning) {
            return;
        }

        state.loopRunning = true;

        // 决策循环
        state.loopInterval = setInterval(async function() {
            if (!state.loopRunning || state.status !== ExecutorState.RUNNING) {
                return;
            }

            // 如果有待处理的工具调用，等待
            if (state.pendingToolCall) {
                return;
            }

            try {
                await runDecisionStep();
            } catch (error) {
                log('error', '决策步骤失败: ' + error.message);
            }

        }, config.loopIntervalMs);

        // 状态心跳
        state.statusInterval = setInterval(function() {
            updateStatusFromWasm();
        }, config.statusIntervalMs);
    }

    /**
     * 停止决策循环
     */
    function stopDecisionLoop() {
        state.loopRunning = false;

        if (state.loopInterval) {
            clearInterval(state.loopInterval);
            state.loopInterval = null;
        }

        if (state.statusInterval) {
            clearInterval(state.statusInterval);
            state.statusInterval = null;
        }
    }

    /**
     * 执行一步决策
     */
    async function runDecisionStep() {
        if (!WasmBridge || !WasmBridge.isLoaded()) {
            return;
        }

        try {
            // 获取 WASM 的下一步决策
            var decision = await WasmBridge.runWasmLoopStep();

            if (!decision) {
                return;
            }

            // 处理决策
            if (decision.type === 'tool_call') {
                await executeToolCall(decision);
            } else if (decision.type === 'complete') {
                handleTaskComplete(decision);
            } else if (decision.type === 'error') {
                handleTaskError(decision);
            }

        } catch (error) {
            log('error', '决策执行失败: ' + error.message);
        }
    }

    // ========================================================================
    // 工具执行
    // ========================================================================

    /**
     * 执行工具调用
     * @param {Object} decision WASM 决策
     */
    async function executeToolCall(decision) {
        if (!decision || !decision.name) {
            log('error', '无效的工具调用决策');
            return;
        }

        state.pendingToolCall = decision;
        log('info', '执行工具: ' + decision.name);

        try {
            // 通过 MCP 执行工具
            var result = await executeViaMCP(decision.name, decision.arguments || {});

            // 构建结果
            var toolResult = {
                id: decision.id,
                name: decision.name,
                success: result.success !== false,
                result: result
            };

            if (!toolResult.success && result.error) {
                toolResult.error = result.error;
            }

            // 将结果回写给 WASM
            WasmBridge.applyToolResult(toolResult);

            log('info', '工具执行完成: ' + decision.name + ' (' + (toolResult.success ? '成功' : '失败') + ')');

        } catch (error) {
            log('error', '工具执行失败: ' + error.message);

            // 回写错误结果
            WasmBridge.applyToolResult({
                id: decision.id,
                name: decision.name,
                success: false,
                error: { code: 'EXECUTION_ERROR', message: error.message }
            });
        }

        state.pendingToolCall = null;
    }

    /**
     * 通过 MCP 执行工具
     * @param {string} toolName 工具名称
     * @param {Object} args 参数
     * @returns {Promise<Object>}
     */
    async function executeViaMCP(toolName, args) {
        if (typeof MCPClient === 'undefined') {
            throw new Error('MCPClient 未定义');
        }

        try {
            var result = await MCPClient.callTool(toolName, args);
            return result;
        } catch (error) {
            return {
                success: false,
                error: { code: 'MCP_ERROR', message: error.message }
            };
        }
    }

    // ========================================================================
    // 回调处理
    // ========================================================================

    /**
     * 处理 WASM 决策回调
     */
    function handleWasmDecision(decision) {
        log('debug', 'WASM 决策: ' + decision.type + ' - ' + (decision.name || ''));
    }

    /**
     * 处理 WASM 状态回调
     */
    function handleWasmStatus(status) {
        state.lastStatus = status;

        if (callbacks.onProgress) {
            callbacks.onProgress({
                phase: status.phase,
                step: status.step,
                iteration: status.iteration,
                maxIterations: status.maxIterations,
                candidatesCount: status.candidatesCount,
                customersFound: status.customersFound
            });
        }
    }

    /**
     * 处理 WASM 完成回调
     */
    function handleWasmComplete(result) {
        handleTaskComplete(result);
    }

    /**
     * 处理 WASM 错误回调
     */
    function handleWasmError(error) {
        handleTaskError(error);
    }

    /**
     * 处理 WASM 日志回调
     */
    function handleWasmLog(logEntry) {
        log(logEntry.level, logEntry.msg);
    }

    /**
     * 处理任务完成
     */
    function handleTaskComplete(result) {
        log('info', '任务完成');
        stopDecisionLoop();
        updateStatus(ExecutorState.COMPLETED);

        if (callbacks.onComplete) {
            callbacks.onComplete({
                taskId: state.taskId,
                duration: Date.now() - state.startTime,
                foundCustomers: result.foundCustomers || [],
                stats: result.stats || {}
            });
        }
    }

    /**
     * 处理任务错误
     */
    function handleTaskError(error) {
        log('error', '任务错误: ' + (error.message || '未知错误'));
        stopDecisionLoop();
        updateStatus(ExecutorState.ERROR);

        if (callbacks.onError) {
            callbacks.onError({
                taskId: state.taskId,
                message: error.message || '未知错误'
            });
        }
    }

    // ========================================================================
    // 辅助函数
    // ========================================================================

    /**
     * 构建 WASM 任务配置
     */
    function buildWasmTaskConfig(taskConfig) {
        var profile = taskConfig.profile || taskConfig.sourceTypeProfile || {};
        var searchEngines = taskConfig.selectedSearchEngines || profile.selected_search_engines || [];
        var keywords = profile.keywords || [];

        // 选择第一个搜索引擎
        var searchEngine = searchEngines.length > 0 ? searchEngines[0] : 'Google';
        var searchEngineUrls = {
            'Google': 'https://www.google.com/search?q=',
            'Bing': 'https://www.bing.com/search?q=',
            'Baidu': 'https://www.baidu.com/s?wd=',
            'DuckDuckGo': 'https://html.duckduckgo.com/html/?q='
        };

        // 构建搜索关键词
        var keywordsStr = '';
        if (Array.isArray(keywords)) {
            keywordsStr = keywords.join(' ');
        } else if (typeof keywords === 'string') {
            keywordsStr = keywords;
        }

        // 如果没有关键词，使用画像名称
        if (!keywordsStr && profile.name) {
            keywordsStr = profile.name;
        }

        return {
            taskId: taskConfig.taskId || ('task_' + Date.now()),
            sourceType: 'search_engine',
            profile: profile,
            keywords: keywordsStr,
            searchEngine: searchEngine,
            searchEngineUrl: searchEngineUrls[searchEngine] || searchEngineUrls['Google'],
            maxIterations: taskConfig.maxIterations || 20,
            maxCandidates: taskConfig.maxCandidates || 10
        };
    }

    /**
     * 检查 MCP 可用性
     */
    async function checkMCPAvailability() {
        if (typeof MCPClient === 'undefined') {
            return false;
        }

        try {
            return await MCPClient.isMCPAvailable();
        } catch (error) {
            log('warn', 'MCP 检查失败: ' + error.message);
            return false;
        }
    }

    /**
     * 从 WASM 更新状态
     */
    function updateStatusFromWasm() {
        if (!WasmBridge || !WasmBridge.isLoaded()) {
            return;
        }

        var status = WasmBridge.getWasmStatus();
        if (status) {
            state.lastStatus = status;

            // 处理日志
            if (status.logs && status.logs.length > 0) {
                status.logs.forEach(function(logEntry) {
                    log(logEntry.level, logEntry.msg);
                });
            }

            // 触发进度回调
            if (callbacks.onProgress) {
                callbacks.onProgress({
                    phase: status.phase,
                    step: status.step,
                    iteration: status.iteration,
                    maxIterations: status.maxIterations,
                    candidatesCount: status.candidatesCount,
                    customersFound: status.customersFound,
                    currentUrl: status.currentUrl
                });
            }

            // 检查完成
            if (status.done) {
                WasmBridge.runWasmLoopStep(); // 获取最终结果
            }
        }
    }

    /**
     * 更新状态
     */
    function updateStatus(newStatus) {
        var oldStatus = state.status;
        state.status = newStatus;

        if (callbacks.onStatusChange) {
            callbacks.onStatusChange(newStatus, oldStatus);
        }
    }

    /**
     * 日志
     */
    function log(level, message) {
        var timestamp = new Date().toISOString();
        var prefix = '[WasmExecutor]';

        switch (level) {
            case 'error':
                console.error(prefix, message);
                break;
            case 'warn':
                console.warn(prefix, message);
                break;
            case 'info':
                console.log(prefix, message);
                break;
            case 'debug':
                console.debug(prefix, message);
                break;
            default:
                console.log(prefix, message);
        }

        if (callbacks.onLog) {
            callbacks.onLog({
                timestamp: timestamp,
                level: level,
                message: message
            });
        }
    }

    // ========================================================================
    // 公共接口
    // ========================================================================
    return {
        // 状态常量
        State: ExecutorState,

        // 初始化
        init: init,
        loadWasm: loadWasm,

        // 任务管理
        startTask: startTask,
        stopTask: stopTask,
        pauseTask: pauseTask,
        resumeTask: resumeTask,

        // 状态查询
        getStatus: function() { return state.status; },
        getTaskId: function() { return state.taskId; },
        getLastStatus: function() { return state.lastStatus; },
        isRunning: function() { return state.status === ExecutorState.RUNNING; },
        isWasmLoaded: function() { return state.wasmLoaded; }
    };

})();

// 导出到全局
if (typeof window !== 'undefined') {
    window.WasmTaskExecutor = WasmTaskExecutor;
}

if (typeof self !== 'undefined') {
    self.WasmTaskExecutor = WasmTaskExecutor;
}

