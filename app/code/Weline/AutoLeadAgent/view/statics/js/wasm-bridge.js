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
 *
 * 对应 wasm/src：agent_brain.cpp、mcp_protocol.cpp
 * 编译需使用 Emscripten（CMakeLists.txt），WASI SDK 仅编译 agent_core
 */

var WasmBridge = (function () {
    'use strict';

    var wasmModule = null;
    var wasmMemory = null;
    var loaded = false;
    var loading = false;
    var initPromise = null;

    var wasmFunctions = {
        startTask: null,
        nextDecision: null,
        applyResult: null,
        getStatus: null,
        stopTask: null,
        isReady: null,
        getVersion: null,
        createAgentBrain: null,
        decideNextAction: null,
        updateState: null
    };

    var currentTask = {
        id: null,
        running: false,
        loopInterval: null,
        statusInterval: null,
        callbacks: { onDecision: null, onStatus: null, onComplete: null, onError: null, onLog: null }
    };

    var config = {
        wasmPath: '/Weline/AutoLeadAgent/view/statics/wasm/agent-core.wasm',
        loopIntervalMs: 100,
        statusIntervalMs: 500,
        maxMemoryMB: 256
    };

    function stringToWasm(str) {
        if (!wasmModule || !wasmModule.exports) throw new Error('WASM module not loaded');
        var encoder = new TextEncoder();
        var bytes = encoder.encode(str + '\0');
        var ptr = wasmModule.exports.malloc(bytes.length);
        if (!ptr) throw new Error('Failed to allocate WASM memory');
        var memory = new Uint8Array(wasmModule.exports.memory.buffer);
        memory.set(bytes, ptr);
        return ptr;
    }

    function wasmToString(ptr) {
        if (!wasmModule || !wasmModule.exports || !ptr) return '';
        var memory = new Uint8Array(wasmModule.exports.memory.buffer);
        var end = ptr;
        while (memory[end] !== 0 && end < memory.length) end++;
        return new TextDecoder().decode(memory.slice(ptr, end));
    }

    function freeWasmMemory(ptr) {
        if (wasmModule && wasmModule.exports && wasmModule.exports.free && ptr) {
            wasmModule.exports.free(ptr);
        }
    }

    async function loadWasmModule(wasmPath) {
        if (loaded && wasmModule) return;
        if (loading && initPromise) return initPromise;

        loading = true;
        var resolvedPath = wasmPath || config.wasmPath;

        initPromise = (async function () {
            try {
                // WASI 模块通常自带 memory，不强制传入 env.memory
                var importObject = {
                    env: { abort: function () { console.error('[WasmBridge] WASM abort'); } },
                    wasi_snapshot_preview1: {
                        fd_write: function () { return 0; },
                        fd_read: function () { return 0; },
                        fd_close: function () { return 0; },
                        fd_seek: function () { return 0; },
                        proc_exit: function () {},
                        environ_sizes_get: function () { return 0; },
                        environ_get: function () { return 0; }
                    }
                };

                var response = await fetch(resolvedPath);
                if (!response.ok) throw new Error('Failed to fetch WASM: ' + response.status);
                var wasm = await WebAssembly.instantiateStreaming(response, importObject);
                wasmModule = wasm.instance;
                initWasmFunctions();
                loaded = true;
                loading = false;
            } catch (error) {
                loading = false;
                throw error;
            }
        })();
        return initPromise;
    }

    function initWasmFunctions() {
        if (!wasmModule || !wasmModule.exports) return;
        var e = wasmModule.exports;
        wasmFunctions.startTask = e.wasm_start_task || null;
        wasmFunctions.nextDecision = e.wasm_next_decision || null;
        wasmFunctions.applyResult = e.wasm_apply_tool_result || null;
        wasmFunctions.getStatus = e.wasm_get_status || null;
        wasmFunctions.stopTask = e.wasm_stop_task || null;
        wasmFunctions.isReady = e.wasm_is_ready || null;
        wasmFunctions.getVersion = e.wasm_get_version || null;
        wasmFunctions.createAgentBrain = e.createAgentBrain || null;
        wasmFunctions.decideNextAction = e.decideNextAction || null;
        wasmFunctions.updateState = e.updateState || null;
    }

    async function startTaskInWasm(taskConfig, callbacks) {
        if (!loaded) throw new Error('WASM module not loaded');
        if (currentTask.running) throw new Error('Another task is already running');
        if (!wasmFunctions.startTask) throw new Error('wasm_start_task not available');

        currentTask.callbacks = callbacks || {};
        currentTask.id = taskConfig.taskId || ('task_' + Date.now());
        currentTask.running = true;

        var taskPtr = stringToWasm(JSON.stringify(taskConfig));
        wasmFunctions.startTask(taskPtr);
        freeWasmMemory(taskPtr);
        startStatusHeartbeat();
        if (currentTask.callbacks.onStatus) {
            currentTask.callbacks.onStatus({ taskId: currentTask.id, phase: 'STARTED', message: '任务已启动' });
        }
    }

    async function runWasmLoopStep() {
        if (!loaded || !currentTask.running || !wasmFunctions.nextDecision) return null;
        var decisionPtr = wasmFunctions.nextDecision();
        var decisionJson = wasmToString(decisionPtr);
        if (!decisionJson) return null;
        var decision = JSON.parse(decisionJson);
        if (decision.type === 'complete') { handleTaskComplete(decision); return decision; }
        if (decision.type === 'error') { handleTaskError(decision); return decision; }
        if (decision.type === 'tool_call' && currentTask.callbacks.onDecision) {
            currentTask.callbacks.onDecision(decision);
        }
        return decision;
    }

    function applyToolResult(result) {
        if (!loaded || !wasmFunctions.applyResult) return;
        var ptr = stringToWasm(JSON.stringify(result));
        wasmFunctions.applyResult(ptr);
        freeWasmMemory(ptr);
    }

    function getWasmStatus() {
        if (!loaded || !wasmFunctions.getStatus) return null;
        var ptr = wasmFunctions.getStatus();
        var json = wasmToString(ptr);
        return json ? JSON.parse(json) : null;
    }

    function stopTask() {
        if (!currentTask.running) return;
        stopStatusHeartbeat();
        if (wasmFunctions.stopTask) wasmFunctions.stopTask();
        currentTask.running = false;
        if (currentTask.callbacks.onComplete) currentTask.callbacks.onComplete({ taskId: currentTask.id, stopped: true });
    }

    function startStatusHeartbeat() {
        if (currentTask.statusInterval) clearInterval(currentTask.statusInterval);
        currentTask.statusInterval = setInterval(function () {
            if (!currentTask.running) { stopStatusHeartbeat(); return; }
            var status = getWasmStatus();
            if (status) {
                if (currentTask.callbacks.onStatus) currentTask.callbacks.onStatus(status);
                if (status.logs && status.logs.length && currentTask.callbacks.onLog) {
                    status.logs.forEach(function (log) { currentTask.callbacks.onLog(log); });
                }
                if (status.done) runWasmLoopStep();
            }
        }, config.statusIntervalMs);
    }

    function stopStatusHeartbeat() {
        if (currentTask.statusInterval) {
            clearInterval(currentTask.statusInterval);
            currentTask.statusInterval = null;
        }
    }

    function handleTaskComplete(result) {
        stopStatusHeartbeat();
        currentTask.running = false;
        if (currentTask.callbacks.onComplete) currentTask.callbacks.onComplete(result);
    }

    function handleTaskError(error) {
        stopStatusHeartbeat();
        currentTask.running = false;
        if (currentTask.callbacks.onError) currentTask.callbacks.onError(error);
    }

    function isReady() { return loaded && wasmFunctions.isReady && wasmFunctions.isReady() === 1; }
    function getVersion() { return (loaded && wasmFunctions.getVersion) ? wasmToString(wasmFunctions.getVersion()) || 'unknown' : 'unknown'; }
    function isLoaded() { return loaded && wasmModule !== null; }
    function isTaskRunning() { return currentTask.running; }
    function getCurrentTaskId() { return currentTask.id; }

    function unload() {
        stopTask();
        wasmFunctions = {};
        wasmModule = null;
        wasmMemory = null;
        loaded = false;
        loading = false;
        initPromise = null;
    }

    async function decideNextAction(context, profile, prompt) {
        if (!loaded) {
            if (typeof ModelInference !== 'undefined') {
                var d = await ModelInference.generateDecision(prompt);
                return JSON.stringify(d);
            }
            throw new Error('WASM not loaded and ModelInference not available');
        }
        try {
            if (wasmFunctions.decideNextAction) {
                var cP = stringToWasm(context), pP = stringToWasm(profile), prP = stringToWasm(prompt);
                var r = wasmToString(wasmFunctions.decideNextAction(cP, pP, prP));
                freeWasmMemory(cP); freeWasmMemory(pP); freeWasmMemory(prP);
                return r;
            }
            if (typeof ModelInference !== 'undefined') {
                var d = await ModelInference.generateDecision(prompt);
                return JSON.stringify(d);
            }
        } catch (e) {
            if (typeof ModelInference !== 'undefined') {
                var d = await ModelInference.generateDecision(prompt);
                return JSON.stringify(d);
            }
            throw e;
        }
        throw new Error('No decision function available');
    }

    function updateState(state) {
        if (!loaded || !wasmFunctions.updateState) return;
        var ptr = stringToWasm(state);
        wasmFunctions.updateState(ptr);
        freeWasmMemory(ptr);
    }

    return {
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
        decideNextAction: decideNextAction,
        updateState: updateState
    };
})();

if (typeof self !== 'undefined') self.WasmBridge = WasmBridge;
if (typeof window !== 'undefined') window.WasmBridge = WasmBridge;
