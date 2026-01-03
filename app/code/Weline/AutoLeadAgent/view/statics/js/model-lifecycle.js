/**
 * 模型生命周期管理
 * 负责模型的加载、卸载、状态监控
 */

var ModelLifecycle = (function () {
    'use strict';

    var state = {
        loaded: false,
        loading: false,
        modelType: null, // 'chrome_ai' | 'webllm' | null
        modelId: null,
        memoryUsage: null,
        loadTime: null,
        error: null
    };

    var callbacks = {
        onStatusChange: null,
        onLoadStart: null,
        onLoadComplete: null,
        onUnload: null,
        onError: null
    };

    /**
     * 初始化
     */
    function init(options) {
        options = options || {};

        if (options.onStatusChange) callbacks.onStatusChange = options.onStatusChange;
        if (options.onLoadStart) callbacks.onLoadStart = options.onLoadStart;
        if (options.onLoadComplete) callbacks.onLoadComplete = options.onLoadComplete;
        if (options.onUnload) callbacks.onUnload = options.onUnload;
        if (options.onError) callbacks.onError = options.onError;

        // 绑定页面离开事件
        bindPageEvents();

        // 初始化状态检查
        checkModelStatus();

        // 绑定 UI 按钮
        bindUIButtons();

        console.log('[ModelLifecycle] 初始化完成');
    }

    /**
     * 绑定页面事件（用于自动卸载）
     */
    function bindPageEvents() {
        // 页面离开时卸载模型
        window.addEventListener('beforeunload', function () {
            if (state.loaded) {
                console.log('[ModelLifecycle] 页面即将离开，卸载模型');
                unloadModel();
            }
        });

        // 页面可见性变化时可选卸载
        document.addEventListener('visibilitychange', function () {
            if (document.hidden && state.loaded) {
                console.log('[ModelLifecycle] 页面进入后台');
                // 可选：在页面进入后台时卸载模型以节省资源
                // 暂时不自动卸载，由用户手动控制
            }
        });
    }

    /**
     * 绑定 UI 按钮
     */
    function bindUIButtons() {
        // 加载按钮
        var loadBtn = document.getElementById('hf-model-load-btn');
        if (loadBtn) {
            loadBtn.addEventListener('click', function () {
                loadModel();
            });
        }

        // 卸载按钮（配置页）
        var unloadBtn = document.getElementById('hf-model-unload-btn');
        if (unloadBtn) {
            unloadBtn.addEventListener('click', function () {
                if (confirm('确定要卸载模型吗？\n\n卸载后将释放内存，但需要重新加载才能使用。')) {
                    unloadModel();
                }
            });
        }
    }

    /**
     * 检查模型状态
     */
    function checkModelStatus() {
        if (typeof HFModelManager === 'undefined') {
            updateState({ loaded: false, modelType: null });
            return;
        }

        var managerState = HFModelManager.getState();
        updateState({
            loaded: managerState.loaded,
            loading: managerState.loading,
            modelType: managerState.type,
            modelId: managerState.modelId,
            error: managerState.error
        });
    }

    /**
     * 加载模型
     */
    async function loadModel(modelId) {
        if (state.loading) {
            console.log('[ModelLifecycle] 模型正在加载中，请等待...');
            return false;
        }

        if (state.loaded) {
            console.log('[ModelLifecycle] 模型已加载');
            return true;
        }

        // 获取模型 ID
        if (!modelId) {
            var configCard = document.getElementById('hf-model-config-card');
            if (configCard) {
                modelId = configCard.getAttribute('data-current-model-id');
            }
        }

        if (!modelId) {
            var errorMsg = '请先选择要加载的模型';
            updateState({ error: errorMsg });
            if (typeof ConfigUtils !== 'undefined') {
                ConfigUtils.safeToast('warning', errorMsg);
            }
            return false;
        }

        updateState({ loading: true, error: null });
        updateUI('loading');

        if (callbacks.onLoadStart) {
            callbacks.onLoadStart(modelId);
        }

        try {
            var startTime = performance.now();

            // 初始化 HFModelManager
            if (typeof HFModelManager !== 'undefined') {
                HFModelManager.init({
                    modelId: modelId,
                    cacheSize: getCacheSize()
                });

                // 加载模型
                await HFModelManager.loadModel();

                var loadTime = performance.now() - startTime;
                var managerState = HFModelManager.getState();

                updateState({
                    loaded: true,
                    loading: false,
                    modelType: managerState.type,
                    modelId: modelId,
                    loadTime: loadTime,
                    error: null
                });

                updateUI('loaded');

                if (callbacks.onLoadComplete) {
                    callbacks.onLoadComplete({
                        modelId: modelId,
                        modelType: managerState.type,
                        loadTime: loadTime
                    });
                }

                if (typeof ConfigUtils !== 'undefined') {
                    ConfigUtils.safeToast('success', '模型加载成功 (' + (loadTime / 1000).toFixed(1) + 's)');
                }

                console.log('[ModelLifecycle] 模型加载完成:', modelId, '类型:', managerState.type, '耗时:', loadTime.toFixed(0) + 'ms');
                return true;
            } else {
                throw new Error('HFModelManager 未定义');
            }
        } catch (error) {
            console.error('[ModelLifecycle] 模型加载失败:', error);

            updateState({
                loaded: false,
                loading: false,
                error: error.message
            });

            updateUI('error');

            if (callbacks.onError) {
                callbacks.onError(error);
            }

            if (typeof ConfigUtils !== 'undefined') {
                ConfigUtils.safeToast('error', '模型加载失败: ' + error.message);
            }

            return false;
        }
    }

    /**
     * 卸载模型
     */
    function unloadModel() {
        if (!state.loaded && !state.loading) {
            console.log('[ModelLifecycle] 模型未加载，无需卸载');
            return;
        }

        try {
            if (typeof HFModelManager !== 'undefined' && HFModelManager.isLoaded()) {
                HFModelManager.unload();
            }

            // 尝试触发垃圾回收
            if (typeof gc === 'function') {
                gc();
            }

            updateState({
                loaded: false,
                loading: false,
                modelType: null,
                memoryUsage: null,
                loadTime: null,
                error: null
            });

            updateUI('unloaded');

            if (callbacks.onUnload) {
                callbacks.onUnload();
            }

            if (typeof ConfigUtils !== 'undefined') {
                ConfigUtils.safeToast('info', '模型已卸载，内存已释放');
            }

            console.log('[ModelLifecycle] 模型已卸载');
        } catch (error) {
            console.error('[ModelLifecycle] 卸载模型失败:', error);
        }
    }

    /**
     * 更新状态
     */
    function updateState(newState) {
        Object.assign(state, newState);

        if (callbacks.onStatusChange) {
            callbacks.onStatusChange(state);
        }
    }

    /**
     * 更新 UI
     */
    function updateUI(status) {
        var statusIcon = document.getElementById('hf-runtime-status-icon');
        var statusText = document.getElementById('hf-runtime-status-text');
        var modelTypeSpan = document.getElementById('hf-runtime-model-type');
        var loadBtn = document.getElementById('hf-model-load-btn');
        var unloadBtn = document.getElementById('hf-model-unload-btn');
        var memoryInfo = document.getElementById('hf-runtime-memory-info');
        var memoryValue = document.getElementById('hf-runtime-memory-value');

        // 更新测试按钮状态
        var testBtn = document.getElementById('hf_agent_test_btn');
        if (testBtn) {
            testBtn.disabled = status !== 'loaded';
        }

        // 任务页
        var taskModelStatus = document.getElementById('task-model-status');
        var taskUnloadBtn = document.getElementById('task-model-unload-btn');

        switch (status) {
            case 'loading':
                if (statusIcon) statusIcon.style.color = '#ffc107';
                if (statusText) statusText.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>正在加载...';
                if (loadBtn) loadBtn.style.display = 'none';
                if (unloadBtn) unloadBtn.style.display = 'none';
                if (modelTypeSpan) modelTypeSpan.style.display = 'none';
                break;

            case 'loaded':
                if (statusIcon) statusIcon.style.color = '#28a745';
                if (statusText) statusText.textContent = '已加载';
                if (modelTypeSpan) {
                    modelTypeSpan.style.display = 'inline';
                    modelTypeSpan.className = 'badge ms-2 ' + (state.modelType === 'chrome_ai' ? 'bg-success' : 'bg-primary');
                    modelTypeSpan.textContent = state.modelType === 'chrome_ai' ? 'Chrome AI' : 'WebLLM';
                }
                if (loadBtn) loadBtn.style.display = 'inline-block';
                if (unloadBtn) unloadBtn.style.display = 'inline-block';
                if (memoryInfo) memoryInfo.style.display = 'block';
                if (memoryValue) memoryValue.textContent = getMemoryUsageText();

                // 任务页
                if (taskModelStatus) {
                    taskModelStatus.innerHTML = '<span class="badge bg-success"><i class="mdi mdi-check-circle"></i> 已加载</span>';
                }
                if (taskUnloadBtn) taskUnloadBtn.style.display = 'inline-block';
                break;

            case 'unloaded':
                if (statusIcon) statusIcon.style.color = '#6c757d';
                if (statusText) statusText.textContent = '未加载';
                if (modelTypeSpan) modelTypeSpan.style.display = 'none';
                if (loadBtn) {
                    loadBtn.style.display = hasConfiguredModel() ? 'inline-block' : 'none';
                }
                if (unloadBtn) unloadBtn.style.display = 'none';
                if (memoryInfo) memoryInfo.style.display = 'none';

                // 任务页
                if (taskModelStatus) {
                    taskModelStatus.innerHTML = '<span class="badge bg-secondary"><i class="mdi mdi-sleep"></i> 待机</span>';
                }
                if (taskUnloadBtn) taskUnloadBtn.style.display = 'none';
                break;

            case 'error':
                if (statusIcon) statusIcon.style.color = '#dc3545';
                if (statusText) statusText.textContent = '加载失败';
                if (modelTypeSpan) modelTypeSpan.style.display = 'none';
                if (loadBtn) loadBtn.style.display = 'inline-block';
                if (unloadBtn) unloadBtn.style.display = 'none';
                if (memoryInfo) memoryInfo.style.display = 'none';

                // 任务页
                if (taskModelStatus) {
                    taskModelStatus.innerHTML = '<span class="badge bg-danger"><i class="mdi mdi-alert-circle"></i> 错误</span>';
                }
                break;
        }

        // 更新测试按钮状态
        var testBtn = document.getElementById('hf_agent_test_btn');
        if (testBtn) {
            testBtn.disabled = status !== 'loaded';
        }
    }

    /**
     * 检查是否有配置的模型
     */
    function hasConfiguredModel() {
        var configCard = document.getElementById('hf-model-config-card');
        if (configCard) {
            var modelId = configCard.getAttribute('data-current-model-id');
            return !!modelId && modelId.trim() !== '';
        }
        return false;
    }

    /**
     * 获取缓存大小设置
     */
    function getCacheSize() {
        var cacheInput = document.getElementById('hf_model_cache_size');
        if (cacheInput) {
            return parseInt(cacheInput.value, 10) || 10240;
        }
        return 10240;
    }

    /**
     * 获取内存使用文本
     */
    function getMemoryUsageText() {
        if (typeof performance !== 'undefined' && performance.memory) {
            var usedMB = (performance.memory.usedJSHeapSize / 1024 / 1024).toFixed(1);
            var totalMB = (performance.memory.totalJSHeapSize / 1024 / 1024).toFixed(1);
            return usedMB + ' MB / ' + totalMB + ' MB';
        }
        return '无法获取';
    }

    /**
     * 下载完成后自动加载模型
     */
    function autoLoadAfterDownload(modelId) {
        console.log('[ModelLifecycle] 下载完成，自动加载模型:', modelId);
        loadModel(modelId);
    }

    /**
     * 获取当前状态
     */
    function getState() {
        return Object.assign({}, state);
    }

    /**
     * 检查模型是否已加载
     */
    function isLoaded() {
        return state.loaded;
    }

    return {
        init: init,
        loadModel: loadModel,
        unloadModel: unloadModel,
        checkModelStatus: checkModelStatus,
        autoLoadAfterDownload: autoLoadAfterDownload,
        getState: getState,
        isLoaded: isLoaded,
        updateUI: updateUI
    };
})();

// 导出到全局
if (typeof window !== 'undefined') {
    window.ModelLifecycle = ModelLifecycle;
}
