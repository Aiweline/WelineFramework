/**
 * WASM 桥接层 - 智能体大脑接口
 * 
 * 职责：
 * - 加载和初始化 WASM 模块
 * - 提供 JS ↔ WASM 的数据通道
 * - 任务注入与启动
 * - 决策循环驱动（step by step）
 * - 状态心跳与日志获取
 * 
 * WASM 主导决策，JS 只负责：
 * 1. 把任务配置写入 WASM
 * 2. 执行 WASM 产出的 MCP 工具调用
 * 3. 把工具执行结果回写给 WASM
 * 4. 轮询获取状态和日志
 */

var WasmBridge = (function () {
    'use strict';

    // ========================================================================
    // 模块状态
    // ========================================================================
    var wasmModule = null;
    var wasmMemory = null;
    var loaded = false;
    var loading = false;
    var initPromise = null;

    // WASM 函数引用（通过 cwrap 包装）
    var wasmFunctions = {
        startTask: null,      // wasm_start_task(taskJson)
        nextDecision: null,   // wasm_next_decision() -> JSON
        applyResult: null,    // wasm_apply_tool_result(resultJson)
        getStatus: null,      // wasm_get_status() -> JSON
        stopTask: null,       // wasm_stop_task()
        isReady: null,        // wasm_is_ready() -> int
        getVersion: null,     // wasm_get_version() -> string
        // 兼容旧接口
        createAgentBrain: null,
        decideNextAction: null,
        updateState: null
    };

    // 任务状态
    var currentTask = {
        id: null,
        running: false,
        loopInterval: null,
        statusInterval: null,
        callbacks: {
            onDecision: null,
            onStatus: null,
            onComplete: null,
            onError: null,
            onLog: null
        }
    };

    // 配置
    var config = {
        wasmPath: 'agent-core.wasm',
        loopIntervalMs: 100,       // 决策循环间隔
        statusIntervalMs: 500,     // 状态心跳间隔
        maxMemoryMB: 256           // 最大内存
    };

    // ========================================================================
    // WASM 内存管理辅助函数
    // ========================================================================

    /**
     * 将 JS 字符串写入 WASM 内存
     * @param {string} str 要写入的字符串
     * @returns {number} WASM 内存指针
     */
    function stringToWasm(str) {
        if (!wasmModule || !wasmModule.exports) {
            throw new Error('WASM module not loaded');
        }

        var encoder = new TextEncoder();
        var bytes = encoder.encode(str + '\0');
        var ptr = wasmModule.exports.malloc(bytes.length);
        
        if (!ptr) {
            throw new Error('Failed to allocate WASM memory');
        }

        var memory = new Uint8Array(wasmModule.exports.memory.buffer);
        memory.set(bytes, ptr);
        
        return ptr;
    }

    /**
     * 从 WASM 内存读取字符串
     * @param {number} ptr WASM 内存指针
     * @returns {string} 读取的字符串
     */
    function wasmToString(ptr) {
        if (!wasmModule || !wasmModule.exports || !ptr) {
            return '';
        }

        var memory = new Uint8Array(wasmModule.exports.memory.buffer);
        var end = ptr;
        
        // 查找字符串结束符
        while (memory[end] !== 0 && end < memory.length) {
            end++;
        }

        var bytes = memory.slice(ptr, end);
        var decoder = new TextDecoder();
        return decoder.decode(bytes);
    }

    /**
     * 释放 WASM 内存
     * @param {number} ptr WASM 内存指针
     */
    function freeWasmMemory(ptr) {
        if (wasmModule && wasmModule.exports && wasmModule.exports.free && ptr) {
            wasmModule.exports.free(ptr);
        }
    }

    // ========================================================================
    // WASM 加载与初始化
    // ========================================================================

    /**
     * 加载 WASM 模块
     * @param {string} wasmPath WASM 文件路径
     * @returns {Promise<void>}
     */
    async function loadWasmModule(wasmPath) {
        if (loaded && wasmModule) {
            console.log('[WasmBridge] WASM module already loaded');
            return;
        }

        if (loading && initPromise) {
            console.log('[WasmBridge] WASM module is loading, waiting...');
            return initPromise;
        }

        loading = true;
        var resolvedPath = wasmPath || config.wasmPath;

        initPromise = (async () => {
            try {
                console.log('[WasmBridge] Loading WASM module from:', resolvedPath);

                // 创建 WASM 内存
                wasmMemory = new WebAssembly.Memory({
                    initial: 256,
                    maximum: config.maxMemoryMB * 16  // 每页 64KB
                });

                // 导入对象
                var importObject = {
                    env: {
                        memory: wasmMemory,
                        // 标准 libc 函数（如果需要）
                        abort: function() {
                            console.error('[WasmBridge] WASM abort called');
                        },
                        __memory_base: 0,
                        __table_base: 0
                    },
                    wasi_snapshot_preview1: {
                        // WASI 接口（最小实现）
                        fd_write: function() { return 0; },
                        fd_read: function() { return 0; },
                        fd_close: function() { return 0; },
                        fd_seek: function() { return 0; },
                        proc_exit: function() {},
                        environ_sizes_get: function() { return 0; },
                        environ_get: function() { return 0; }
                    }
                };

                // 加载 WASM
                var response = await fetch(resolvedPath);
                if (!response.ok) {
                    throw new Error('Failed to fetch WASM: ' + response.status);
                }

                var wasm = await WebAssembly.instantiateStreaming(response, importObject);
                wasmModule = wasm.instance;
                
                // 初始化函数包装
                initWasmFunctions();
                
                loaded = true;
                loading = false;

                console.log('[WasmBridge] WASM module loaded successfully');
                console.log('[WasmBridge] Version:', getVersion());

            } catch (error) {
                console.error('[WasmBridge] Failed to load WASM module:', error);
                loading = false;
                throw error;
            }
        })();

        return initPromise;
    }

    /**
     * 初始化 WASM 函数包装
     */
    function initWasmFunctions() {
        if (!wasmModule || !wasmModule.exports) {
            return;
        }

        var exports = wasmModule.exports;

        // 新的智能体大脑接口
        wasmFunctions.startTask = exports.wasm_start_task || null;
        wasmFunctions.nextDecision = exports.wasm_next_decision || null;
        wasmFunctions.applyResult = exports.wasm_apply_tool_result || null;
        wasmFunctions.getStatus = exports.wasm_get_status || null;
        wasmFunctions.stopTask = exports.wasm_stop_task || null;
        wasmFunctions.isReady = exports.wasm_is_ready || null;
        wasmFunctions.getVersion = exports.wasm_get_version || null;

        // 兼容旧接口
        wasmFunctions.createAgentBrain = exports.createAgentBrain || null;
        wasmFunctions.decideNextAction = exports.decideNextAction || null;
        wasmFunctions.updateState = exports.updateState || null;

        console.log('[WasmBridge] Available functions:', Object.keys(exports));
    }

    // ========================================================================
    // 任务管理
    // ========================================================================

    /**
     * 启动任务（WASM 主导决策）
     * @param {Object} taskConfig 任务配置
     * @param {Object} callbacks 回调函数
     * @returns {Promise<void>}
     */
    async function startTaskInWasm(taskConfig, callbacks) {
        if (!loaded) {
            throw new Error('WASM module not loaded');
        }

        if (currentTask.running) {
            throw new Error('Another task is already running');
        }

        // 确保有必要的函数
        if (!wasmFunctions.startTask) {
            throw new Error('wasm_start_task function not available');
        }

        // 设置回调
        currentTask.callbacks = callbacks || {};
        currentTask.id = taskConfig.taskId || ('task_' + Date.now());
        currentTask.running = true;

        try {
            // 将任务配置写入 WASM
            var taskJson = JSON.stringify(taskConfig);
            var taskPtr = stringToWasm(taskJson);
            
            wasmFunctions.startTask(taskPtr);
            freeWasmMemory(taskPtr);

            console.log('[WasmBridge] Task started:', currentTask.id);

            // 启动状态心跳
            startStatusHeartbeat();

            // 触发回调
            if (currentTask.callbacks.onStatus) {
                currentTask.callbacks.onStatus({
                    taskId: currentTask.id,
                    phase: 'STARTED',
                    message: '任务已启动'
                });
            }

        } catch (error) {
            currentTask.running = false;
            console.error('[WasmBridge] Failed to start task:', error);
            throw error;
        }
    }

    /**
     * 执行一步决策循环
     * @returns {Promise<Object>} 决策结果
     */
    async function runWasmLoopStep() {
        if (!loaded || !currentTask.running) {
            return null;
        }

        if (!wasmFunctions.nextDecision) {
            throw new Error('wasm_next_decision function not available');
        }

        try {
            // 获取下一步决策
            var decisionPtr = wasmFunctions.nextDecision();
            var decisionJson = wasmToString(decisionPtr);
            
            if (!decisionJson) {
                return null;
            }

            var decision = JSON.parse(decisionJson);
            console.log('[WasmBridge] Decision:', decision.type, decision.name || '');

            // 处理决策类型
            if (decision.type === 'complete') {
                // 任务完成
                handleTaskComplete(decision);
                return decision;
            }

            if (decision.type === 'error') {
                // 任务出错
                handleTaskError(decision);
                return decision;
            }

            if (decision.type === 'tool_call') {
                // 工具调用 - 触发回调让外部执行
                if (currentTask.callbacks.onDecision) {
                    currentTask.callbacks.onDecision(decision);
                }
                return decision;
            }

            return decision;

        } catch (error) {
            console.error('[WasmBridge] Loop step error:', error);
            throw error;
        }
    }

    /**
     * 应用工具执行结果
     * @param {Object} result 工具执行结果
     */
    function applyToolResult(result) {
        if (!loaded || !wasmFunctions.applyResult) {
            console.warn('[WasmBridge] Cannot apply result: WASM not ready');
            return;
        }

        try {
            var resultJson = JSON.stringify(result);
            var resultPtr = stringToWasm(resultJson);
            
            wasmFunctions.applyResult(resultPtr);
            freeWasmMemory(resultPtr);

            console.log('[WasmBridge] Applied result for:', result.name);

        } catch (error) {
            console.error('[WasmBridge] Failed to apply result:', error);
        }
    }

    /**
     * 获取当前状态
     * @returns {Object|null} 状态对象
     */
    function getWasmStatus() {
        if (!loaded || !wasmFunctions.getStatus) {
            return null;
        }

        try {
            var statusPtr = wasmFunctions.getStatus();
            var statusJson = wasmToString(statusPtr);
            
            if (!statusJson) {
                return null;
            }

            return JSON.parse(statusJson);

        } catch (error) {
            console.error('[WasmBridge] Failed to get status:', error);
            return null;
        }
    }

    /**
     * 停止当前任务
     */
    function stopTask() {
        if (!currentTask.running) {
            return;
        }

        // 停止心跳
        stopStatusHeartbeat();

        // 通知 WASM 停止
        if (wasmFunctions.stopTask) {
            wasmFunctions.stopTask();
        }

        currentTask.running = false;
        console.log('[WasmBridge] Task stopped:', currentTask.id);

        // 触发回调
        if (currentTask.callbacks.onComplete) {
            currentTask.callbacks.onComplete({
                taskId: currentTask.id,
                stopped: true
            });
        }
    }

    // ========================================================================
    // 心跳与状态监控
    // ========================================================================

    /**
     * 启动状态心跳
     */
    function startStatusHeartbeat() {
        if (currentTask.statusInterval) {
            clearInterval(currentTask.statusInterval);
        }

        currentTask.statusInterval = setInterval(function() {
            if (!currentTask.running) {
                stopStatusHeartbeat();
                return;
            }

            var status = getWasmStatus();
            if (status) {
                // 触发状态回调
                if (currentTask.callbacks.onStatus) {
                    currentTask.callbacks.onStatus(status);
                }

                // 触发日志回调
                if (status.logs && status.logs.length > 0 && currentTask.callbacks.onLog) {
                    status.logs.forEach(function(log) {
                        currentTask.callbacks.onLog(log);
                    });
                }

                // 检查是否完成
                if (status.done) {
                    runWasmLoopStep(); // 获取最终结果
                }
            }

        }, config.statusIntervalMs);
    }

    /**
     * 停止状态心跳
     */
    function stopStatusHeartbeat() {
        if (currentTask.statusInterval) {
            clearInterval(currentTask.statusInterval);
            currentTask.statusInterval = null;
        }
    }

    // ========================================================================
    // 完成/错误处理
    // ========================================================================

    /**
     * 处理任务完成
     * @param {Object} result 完成结果
     */
    function handleTaskComplete(result) {
        stopStatusHeartbeat();
        currentTask.running = false;

        console.log('[WasmBridge] Task completed:', currentTask.id);

        if (currentTask.callbacks.onComplete) {
            currentTask.callbacks.onComplete(result);
        }
    }

    /**
     * 处理任务错误
     * @param {Object} error 错误信息
     */
    function handleTaskError(error) {
        stopStatusHeartbeat();
        currentTask.running = false;

        console.error('[WasmBridge] Task error:', error.message);

        if (currentTask.callbacks.onError) {
            currentTask.callbacks.onError(error);
        }
    }

    // ========================================================================
    // 辅助函数
    // ========================================================================

    /**
     * 检查 WASM 是否就绪
     * @returns {boolean}
     */
    function isReady() {
        if (!loaded || !wasmFunctions.isReady) {
            return false;
        }
        return wasmFunctions.isReady() === 1;
    }

    /**
     * 获取 WASM 版本
     * @returns {string}
     */
    function getVersion() {
        if (!loaded || !wasmFunctions.getVersion) {
            return 'unknown';
        }
        var versionPtr = wasmFunctions.getVersion();
        return wasmToString(versionPtr) || 'unknown';
    }

    /**
     * 检查 WASM 是否已加载
     * @returns {boolean}
     */
    function isLoaded() {
        return loaded && wasmModule !== null;
    }

    /**
     * 检查任务是否正在运行
     * @returns {boolean}
     */
    function isTaskRunning() {
        return currentTask.running;
    }

    /**
     * 获取当前任务 ID
     * @returns {string|null}
     */
    function getCurrentTaskId() {
        return currentTask.id;
    }

    /**
     * 卸载 WASM 模块
     */
    function unload() {
        stopTask();
        wasmFunctions = {};
        wasmModule = null;
        wasmMemory = null;
        loaded = false;
        loading = false;
        initPromise = null;
    }

    // ========================================================================
    // 兼容旧接口
    // ========================================================================

    /**
     * 初始化智能体大脑（兼容旧接口）
     * @returns {Promise<void>}
     */
    async function initAgentBrain() {
        if (!loaded) {
            throw new Error('WASM module is not loaded');
        }

        if (wasmFunctions.createAgentBrain) {
            wasmFunctions.createAgentBrain();
            console.log('[WasmBridge] Agent brain initialized (legacy)');
        }
    }

    /**
     * WASM 决策（兼容旧接口，降级到 JS）
     * @param {string} context JSON 字符串（当前状态）
     * @param {string} profile JSON 字符串（客户画像）
     * @param {string} prompt 提示词
     * @returns {Promise<string>} 决策 JSON 字符串
     */
    async function decideNextAction(context, profile, prompt) {
        if (!loaded) {
            // 降级到 JS 模型调用
            if (typeof ModelInference !== 'undefined') {
                var decision = await ModelInference.generateDecision(prompt);
                return JSON.stringify(decision);
            }
            throw new Error('Agent brain is not initialized and ModelInference is not available');
        }

        try {
            // 尝试使用新接口
            if (wasmFunctions.nextDecision) {
                var decisionPtr = wasmFunctions.nextDecision();
                return wasmToString(decisionPtr);
            }

            // 尝试使用旧接口
            if (wasmFunctions.decideNextAction) {
                var contextPtr = stringToWasm(context);
                var profilePtr = stringToWasm(profile);
                var promptPtr = stringToWasm(prompt);
                
                var resultPtr = wasmFunctions.decideNextAction(contextPtr, profilePtr, promptPtr);
                var result = wasmToString(resultPtr);
                
                freeWasmMemory(contextPtr);
                freeWasmMemory(profilePtr);
                freeWasmMemory(promptPtr);
                
                return result;
            }

            // 降级到 JS
            if (typeof ModelInference !== 'undefined') {
                var decision = await ModelInference.generateDecision(prompt);
                return JSON.stringify(decision);
            }
            
            throw new Error('No decision function available');

        } catch (error) {
            console.error('[WasmBridge] Decision failed:', error);
            
            // 降级到 JS
            if (typeof ModelInference !== 'undefined') {
                var decision = await ModelInference.generateDecision(prompt);
                return JSON.stringify(decision);
            }
            throw error;
        }
    }

    /**
     * 更新 WASM 状态（兼容旧接口）
     * @param {string} state JSON 字符串
     */
    function updateState(state) {
        if (!loaded) {
            return;
        }

        try {
            if (wasmFunctions.applyResult) {
                var statePtr = stringToWasm(state);
                wasmFunctions.applyResult(statePtr);
                freeWasmMemory(statePtr);
            } else if (wasmFunctions.updateState) {
                var statePtr = stringToWasm(state);
                wasmFunctions.updateState(statePtr);
                freeWasmMemory(statePtr);
            }
        } catch (error) {
            console.error('[WasmBridge] State update failed:', error);
        }
    }

    /**
     * 桥接数据到 WASM（兼容旧接口）
     * @param {string} data JSON 字符串
     * @returns {number} WASM 内存指针
     */
    function bridgeToWasm(data) {
        return stringToWasm(data);
    }

    /**
     * 从 WASM 桥接数据（兼容旧接口）
     * @param {number} pointer 内存指针
     * @param {number} length 数据长度（忽略，自动检测）
     * @returns {string} JSON 字符串
     */
    function bridgeFromWasm(pointer, length) {
        return wasmToString(pointer);
    }

    // ========================================================================
    // 导出公共 API
    // ========================================================================
    return {
        // 新接口（WASM 主导）
        loadWasmModule: loadWasmModule,
        startTaskInWasm: startTaskInWasm,
        runWasmLoopStep: runWasmLoopStep,
        applyToolResult: applyToolResult,
        getWasmStatus: getWasmStatus,
        stopTask: stopTask,
        isReady: isReady,
        getVersion: getVersion,
        isLoaded: isLoaded,
        isTaskRunning: isTaskRunning,
        getCurrentTaskId: getCurrentTaskId,
        unload: unload,

        // 兼容旧接口
        initAgentBrain: initAgentBrain,
        bridgeToWasm: bridgeToWasm,
        bridgeFromWasm: bridgeFromWasm,
        decideNextAction: decideNextAction,
        updateState: updateState
    };

})();

// 导出到全局（在 Service Worker 中）
if (typeof self !== 'undefined') {
    self.WasmBridge = WasmBridge;
}

// 导出到全局（在普通页面中）
if (typeof window !== 'undefined') {
    window.WasmBridge = WasmBridge;
}
