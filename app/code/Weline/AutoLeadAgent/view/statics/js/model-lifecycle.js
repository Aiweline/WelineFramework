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

    // 配置选项
    var config = {
        autoLoad: true,           // 是否自动加载模型
        autoUnload: true,         // 页面关闭时是否自动卸载
        autoLoadDelay: 500        // 自动加载延迟（毫秒）
    };

    /**
     * 初始化
     */
    async function init(options) {
        options = options || {};

        if (options.onStatusChange) callbacks.onStatusChange = options.onStatusChange;
        if (options.onLoadStart) callbacks.onLoadStart = options.onLoadStart;
        if (options.onLoadComplete) callbacks.onLoadComplete = options.onLoadComplete;
        if (options.onUnload) callbacks.onUnload = options.onUnload;
        if (options.onError) callbacks.onError = options.onError;

        // 合并配置
        if (typeof options.autoLoad !== 'undefined') config.autoLoad = options.autoLoad;
        if (typeof options.autoUnload !== 'undefined') config.autoUnload = options.autoUnload;
        if (typeof options.autoLoadDelay !== 'undefined') config.autoLoadDelay = options.autoLoadDelay;

        // 请求持久化存储（防止浏览器清理缓存）
        await requestPersistentStorageIfNeeded();

        // 绑定页面离开事件
        bindPageEvents();

        // 初始化状态检查
        checkModelStatus();

        // 检查缓存状态
        await checkCachedModels();

        // 绑定 UI 按钮
        bindUIButtons();

        // 自动加载模型
        if (config.autoLoad) {
            autoLoadModel();
        }

        console.log('[ModelLifecycle] 初始化完成, 自动加载:', config.autoLoad);
    }

    /**
     * 请求持久化存储（防止浏览器自动清理）
     */
    async function requestPersistentStorageIfNeeded() {
        if (typeof LocalFileStorage !== 'undefined' && LocalFileStorage.requestPersistentStorage) {
            try {
                const isPersisted = await LocalFileStorage.isStoragePersisted();
                if (!isPersisted) {
                    console.log('[ModelLifecycle] 请求持久化存储权限...');
                    await LocalFileStorage.requestPersistentStorage();
                } else {
                    console.log('[ModelLifecycle] 存储已持久化');
                }
            } catch (error) {
                console.warn('[ModelLifecycle] 请求持久化存储失败:', error);
            }
        }
    }

    /**
     * 检查已缓存的模型
     */
    async function checkCachedModels() {
        var modelId = getConfiguredModelId();
        if (!modelId) {
            console.log('[ModelLifecycle] 未配置模型，跳过缓存检查');
            return;
        }

        console.log('[ModelLifecycle] 检查模型缓存状态:', modelId);

        // 检查 LocalFileStorage
        if (typeof LocalFileStorage !== 'undefined') {
            try {
                var hasFiles = await LocalFileStorage.hasModelFiles(modelId);
                if (hasFiles) {
                    console.log('[ModelLifecycle] 检测到模型缓存 (LocalFileStorage):', modelId);
                    updateCacheStatusUI(true, modelId);
                    return;
                }
            } catch (error) {
                console.warn('[ModelLifecycle] 检查 LocalFileStorage 缓存失败:', error);
            }
        }

        // 检查 ModelStorage
        if (typeof ModelStorage !== 'undefined' && ModelStorage.hasModelFiles) {
            try {
                var hasFiles = await ModelStorage.hasModelFiles(modelId);
                if (hasFiles) {
                    console.log('[ModelLifecycle] 检测到模型缓存 (ModelStorage):', modelId);
                    updateCacheStatusUI(true, modelId);
                    return;
                }
            } catch (error) {
                console.warn('[ModelLifecycle] 检查 ModelStorage 缓存失败:', error);
            }
        }

        console.log('[ModelLifecycle] 未检测到模型缓存:', modelId);
        updateCacheStatusUI(false, modelId);
    }

    /**
     * 更新缓存状态 UI
     */
    function updateCacheStatusUI(cached, modelId) {
        var cacheStatusEl = document.getElementById('hf-model-cache-status');
        if (cacheStatusEl) {
            if (cached) {
                cacheStatusEl.innerHTML = '<span class="badge bg-success"><i class="mdi mdi-check-circle"></i> 已缓存</span>';
                cacheStatusEl.title = '模型已缓存到本地，可快速加载';
            } else {
                cacheStatusEl.innerHTML = '<span class="badge bg-secondary"><i class="mdi mdi-cloud-download-outline"></i> 未缓存</span>';
                cacheStatusEl.title = '首次加载时将从 Hugging Face 下载';
            }
        }
    }

    /**
     * 自动加载模型（页面访问时）
     */
    async function autoLoadModel() {
        // 检查是否已配置模型
        var modelId = getConfiguredModelId();
        if (!modelId) {
            console.log('[ModelLifecycle] 未配置模型，跳过自动加载');
            return;
        }

        // 检查模型是否已加载
        if (state.loaded || state.loading) {
            console.log('[ModelLifecycle] 模型已加载或正在加载，跳过自动加载');
            return;
        }

        // 延迟加载，等待页面完全渲染
        setTimeout(async function() {
            console.log('[ModelLifecycle] 开始自动加载模型:', modelId);
            try {
                await loadModel(modelId);
            } catch (error) {
                console.error('[ModelLifecycle] 自动加载模型失败:', error);
            }
        }, config.autoLoadDelay);
    }

    /**
     * 获取配置的模型 ID
     */
    function getConfiguredModelId() {
        // 尝试从页面元素获取（配置页）
        var configCard = document.getElementById('hf-model-config-card');
        if (configCard) {
            var modelId = configCard.getAttribute('data-current-model-id');
            if (modelId && modelId.trim()) {
                return modelId.trim();
            }
        }

        // 尝试从隐藏字段获取
        var hiddenInput = document.getElementById('hf_model_id');
        if (hiddenInput && hiddenInput.value) {
            return hiddenInput.value.trim();
        }

        // 尝试从任务页隐藏字段获取
        var taskPageModelId = document.getElementById('task-page-model-id');
        if (taskPageModelId && taskPageModelId.value) {
            // 检查是否启用
            var enabled = taskPageModelId.getAttribute('data-enabled');
            if (enabled === '1') {
                return taskPageModelId.value.trim();
            }
        }

        // 尝试从 badge 获取
        var badge = document.getElementById('hf-model-current-badge');
        if (badge) {
            var text = badge.textContent.trim();
            if (text && text !== '未配置模型' && text !== 'Not Configured') {
                return text;
            }
        }

        // 尝试从 HFModelManager 配置获取
        if (typeof HFModelManager !== 'undefined') {
            try {
                var managerState = HFModelManager.getState();
                if (managerState && managerState.modelId) {
                    return managerState.modelId;
                }
            } catch (e) {
                // 忽略
            }
        }

        return null;
    }

    /**
     * 绑定页面事件（用于自动加载/卸载）
     */
    function bindPageEvents() {
        // 页面离开时卸载模型
        window.addEventListener('beforeunload', function (event) {
            if (config.autoUnload && state.loaded) {
                console.log('[ModelLifecycle] 页面即将离开，卸载模型');
                unloadModel();
            }
        });

        // 页面关闭时确保卸载
        window.addEventListener('unload', function () {
            if (config.autoUnload && state.loaded) {
                console.log('[ModelLifecycle] 页面关闭，强制卸载模型');
                try {
                    if (typeof HFModelManager !== 'undefined' && HFModelManager.isLoaded()) {
                        HFModelManager.unload();
                    }
                } catch (e) {
                    // 忽略错误
                }
            }
        });

        // 页面可见性变化时处理
        document.addEventListener('visibilitychange', function () {
            if (document.hidden) {
                console.log('[ModelLifecycle] 页面进入后台');
                // 页面进入后台时不自动卸载，保持模型状态
            } else {
                console.log('[ModelLifecycle] 页面恢复前台');
                // 页面恢复前台时，如果模型未加载且配置了自动加载，则尝试加载
                if (config.autoLoad && !state.loaded && !state.loading) {
                    var modelId = getConfiguredModelId();
                    if (modelId) {
                        console.log('[ModelLifecycle] 页面恢复，检查是否需要重新加载模型');
                        // 检查 HFModelManager 的实际状态
                        if (typeof HFModelManager !== 'undefined') {
                            var managerState = HFModelManager.getState();
                            if (!managerState.loaded) {
                                autoLoadModel();
                            } else {
                                // 同步状态
                                updateState({
                                    loaded: true,
                                    modelType: managerState.type,
                                    modelId: managerState.modelId
                                });
                                updateUI('loaded');
                            }
                        }
                    }
                }
            }
        });

        // 监听页面 pagehide 事件（移动端/Safari 更可靠）
        window.addEventListener('pagehide', function (event) {
            if (config.autoUnload && state.loaded) {
                console.log('[ModelLifecycle] 页面隐藏，卸载模型');
                try {
                    if (typeof HFModelManager !== 'undefined' && HFModelManager.isLoaded()) {
                        HFModelManager.unload();
                    }
                } catch (e) {
                    // 忽略错误
                }
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
            modelId = getConfiguredModelId();
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
        return !!getConfiguredModelId();
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
        autoLoadModel: autoLoadModel,
        getConfiguredModelId: getConfiguredModelId,
        hasConfiguredModel: hasConfiguredModel,
        getState: getState,
        isLoaded: isLoaded,
        updateUI: updateUI
    };
})();

// 导出到全局
if (typeof window !== 'undefined') {
    window.ModelLifecycle = ModelLifecycle;
}
