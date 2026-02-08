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

        // 监听扩展端模型事件广播（loaded/unloaded/loading/error）
        bindExtensionModelEvents();

        // 初始化状态检查
        checkModelStatus();

        // 检查缓存状态
        await checkCachedModels();

        // 绑定 UI 按钮
        bindUIButtons();

        // 自动加载模型（查询扩展端状态，必要时发送加载指令）
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
     * 通知扩展保存模型配置（扩展会持久化到 chrome.storage，用于自动加载）
     */
    function notifyExtensionModelConfig(modelId, enabled) {
        try {
            window.postMessage({
                type: 'MODEL_SAVE_CONFIG',
                payload: { modelId: modelId, enabled: enabled !== false }
            }, '*');
            console.log('[ModelLifecycle] 已通知扩展保存模型配置:', modelId, 'enabled:', enabled !== false);
        } catch (e) {
            console.warn('[ModelLifecycle] 通知扩展失败:', e);
        }
    }

    /**
     * 检查扩展端模型状态（loaded / loading / idle）
     * 超时 5s，返回 { loaded: bool, loading: bool, modelId: string|null }
     */
    function checkExtensionModelLoaded() {
        return new Promise(function (resolve) {
            var handler = function (event) {
                if (event.source !== window || !event.data) return;
                if (event.data.type === 'MODEL_STATUS_RESPONSE') {
                    window.removeEventListener('message', handler);
                    clearTimeout(timer);
                    var r = event.data.response;
                    if (r && r.success && r.status) {
                        resolve({
                            loaded: !!r.status.isLoaded,
                            loading: !!r.status.isLoading,
                            modelId: r.status.modelId || null
                        });
                    } else {
                        resolve({ loaded: false, loading: false });
                    }
                }
            };
            window.addEventListener('message', handler);
            window.postMessage({ type: 'MODEL_STATUS_REQUEST' }, '*');
            var timer = setTimeout(function () {
                window.removeEventListener('message', handler);
                resolve({ loaded: false, loading: false });
            }, 5000);
        });
    }

    /**
     * 自动加载模型（页面访问时）
     * 
     * 核心原则：模型只在扩展内运行，页面只查询状态并展示 UI。
     * - 扩展已加载 → 同步状态到页面，不做任何加载
     * - 扩展正在加载 → 等待，不做任何加载
     * - 扩展未加载 → 向扩展发送 MODEL_LOAD 指令，由扩展加载
     * - 绝不在页面端调用 HFModelManager.loadModel()
     */
    async function autoLoadModel() {
        var modelId = getConfiguredModelId();
        if (!modelId) {
            console.log('[ModelLifecycle] 未配置模型，跳过自动加载');
            return;
        }

        if (state.loaded) {
            console.log('[ModelLifecycle] 模型已标记为已加载，跳过');
            return;
        }

        // 通知扩展保存配置（让扩展知道当前使用哪个模型）
        notifyExtensionModelConfig(modelId, true);

        // 查询扩展端模型状态（重试机制，超时 5s）
        var extStatus = { loaded: false };
        for (var attempt = 0; attempt < 2; attempt++) {
            try {
                extStatus = await checkExtensionModelLoaded();
                if (extStatus.loaded || extStatus.loading) break;
                // 第一次失败后等 1s 再试（扩展可能还在启动）
                if (attempt === 0) await new Promise(function (r) { setTimeout(r, 1000); });
            } catch (e) {
                console.warn('[ModelLifecycle] 扩展状态检查失败 (attempt ' + (attempt + 1) + '):', e.message);
            }
        }

        if (extStatus.loaded) {
            // 扩展已加载 → 同步状态
            console.log('[ModelLifecycle] 扩展端模型已运行:', extStatus.modelId || modelId);
            updateState({
                loaded: true,
                loading: false,
                modelType: 'extension',
                modelId: extStatus.modelId || modelId,
                error: null
            });
            updateUI('loaded');
            if (callbacks.onLoadComplete) {
                callbacks.onLoadComplete({ modelId: extStatus.modelId || modelId, modelType: 'extension', loadTime: 0 });
            }
            return;
        }

        if (extStatus.loading) {
            // 扩展正在加载 → 等待，不发重复指令
            console.log('[ModelLifecycle] 扩展正在加载模型，等待中...');
            updateState({ loading: true, error: null });
            updateUI('loading');
            // 启动轮询等待加载完成
            waitForExtensionModelReady(modelId);
            return;
        }

        // 扩展未加载 → 向扩展发送加载指令
        console.log('[ModelLifecycle] 扩展端模型未运行，发送加载指令:', modelId);
        updateState({ loading: true, error: null });
        updateUI('loading');
        requestExtensionLoadModel(modelId);
    }

    /**
     * 向扩展发送模型加载指令（通过 content script 中继）
     */
    function requestExtensionLoadModel(modelId) {
        try {
            window.postMessage({
                type: 'MODEL_LOAD_REQUEST',
                modelId: modelId
            }, '*');
            console.log('[ModelLifecycle] 已向扩展发送 MODEL_LOAD_REQUEST:', modelId);
        } catch (e) {
            console.warn('[ModelLifecycle] 发送加载指令失败:', e);
        }

        // 启动轮询等待加载完成
        waitForExtensionModelReady(modelId);
    }

    /**
     * 轮询等待扩展端模型加载完成（最长 5 分钟）
     */
    function waitForExtensionModelReady(modelId) {
        var maxWait = 300000; // 5 分钟
        var interval = 3000;  // 每 3s 检查一次
        var elapsed = 0;

        var pollTimer = setInterval(async function () {
            elapsed += interval;
            if (elapsed > maxWait || state.loaded) {
                clearInterval(pollTimer);
                if (!state.loaded) {
                    console.warn('[ModelLifecycle] 等待扩展加载模型超时');
                    updateState({ loading: false, error: '扩展加载模型超时' });
                    updateUI('error');
                }
                return;
            }
            try {
                var status = await checkExtensionModelLoaded();
                if (status.loaded) {
                    clearInterval(pollTimer);
                    console.log('[ModelLifecycle] 扩展端模型加载完成:', status.modelId || modelId);
                    updateState({
                        loaded: true,
                        loading: false,
                        modelType: 'extension',
                        modelId: status.modelId || modelId,
                        error: null
                    });
                    updateUI('loaded');
                    if (callbacks.onLoadComplete) {
                        callbacks.onLoadComplete({ modelId: status.modelId || modelId, modelType: 'extension', loadTime: elapsed });
                    }
                }
            } catch (e) { /* ignore, retry next interval */ }
        }, interval);
    }

    // 下载弹窗是否已显示
    var downloadModalShown = false;
    var downloadStartTime = 0;
    var lastProgressPayload = null;

    /**
     * 显示下载进度弹窗
     */
    function showDownloadModal() {
        if (downloadModalShown) return;
        var modal = document.getElementById('hf-download-modal');
        if (!modal) return;
        downloadModalShown = true;
        downloadStartTime = Date.now();

        // 在弹窗标题中显示模型名称
        var titleEl = modal.querySelector('.modal-title');
        if (titleEl && state.modelId) {
            var shortName = state.modelId.split('/').pop() || state.modelId;
            titleEl.innerHTML = '<i class="mdi mdi-download text-primary me-2"></i>正在下载模型: ' + shortName;
        }

        // 重置进度条
        var totalBar = document.getElementById('hf-download-progress-bar');
        var fileBar = document.getElementById('hf-download-file-progress-bar');
        if (totalBar) { totalBar.style.width = '0%'; totalBar.textContent = '0%'; }
        if (fileBar) { fileBar.style.width = '0%'; fileBar.textContent = '0%'; }

        try {
            if (typeof bootstrap !== 'undefined' && bootstrap.Modal) {
                var bsModal;
                if (typeof bootstrap.Modal.getOrCreateInstance === 'function') {
                    bsModal = bootstrap.Modal.getOrCreateInstance(modal);
                } else {
                    bsModal = bootstrap.Modal.getInstance(modal) || new bootstrap.Modal(modal);
                }
                bsModal.show();
            } else if (typeof $ !== 'undefined' && typeof $.fn.modal !== 'undefined') {
                $(modal).modal('show');
            }
        } catch (e) {
            console.warn('[ModelLifecycle] 无法显示下载模态框:', e);
        }
    }

    /**
     * 隐藏下载进度弹窗
     */
    function hideDownloadModal() {
        if (!downloadModalShown) return;
        downloadModalShown = false;
        var modal = document.getElementById('hf-download-modal');
        if (!modal) return;
        try {
            if (typeof bootstrap !== 'undefined' && bootstrap.Modal) {
                var bsModal = bootstrap.Modal.getInstance(modal);
                if (bsModal) bsModal.hide();
            } else if (typeof $ !== 'undefined' && typeof $.fn.modal !== 'undefined') {
                $(modal).modal('hide');
            }
        } catch (e) {
            console.warn('[ModelLifecycle] 无法隐藏下载模态框:', e);
        }
    }

    /**
     * 更新下载进度弹窗 UI
     */
    function updateDownloadModalUI(payload) {
        lastProgressPayload = payload;

        // 总进度条
        var totalBar = document.getElementById('hf-download-progress-bar');
        var totalText = document.getElementById('hf-download-progress-text');
        var speedText = document.getElementById('hf-download-speed');
        var progress = payload.progress || 0;

        if (totalBar) {
            totalBar.style.width = progress + '%';
            totalBar.textContent = Math.round(progress) + '%';
            totalBar.setAttribute('aria-valuenow', progress);
        }
        if (totalText) {
            var downloaded = payload.downloadedSize || 0;
            var total = payload.totalSize || 0;
            totalText.textContent = formatSize(downloaded) + ' / ' + formatSize(total) + ' (' + Math.round(progress) + '%)';
        }

        // 下载速度 — 使用已下载量和经过时间估算
        if (speedText) {
            var elapsed = (Date.now() - downloadStartTime) / 1000;
            var downloaded = payload.downloadedSize || 0;
            if (elapsed > 1 && downloaded > 0) {
                var speedBps = downloaded / elapsed;
                speedText.textContent = '速度: ' + formatSize(speedBps) + '/s';
            } else {
                speedText.textContent = '速度: 计算中...';
            }
        }

        // 当前文件
        var fileText = document.getElementById('hf-download-progress-file');
        if (fileText) {
            var fileInfo = payload.currentFile || '准备中...';
            if (payload.filesDone !== undefined && payload.filesTotal !== undefined && payload.filesTotal > 0) {
                fileInfo += ' (' + payload.filesDone + '/' + payload.filesTotal + ')';
            }
            fileText.textContent = '当前文件: ' + fileInfo;
        }

        // 当前文件进度条
        var fileBar = document.getElementById('hf-download-file-progress-bar');
        var fileProgressText = document.getElementById('hf-download-file-progress-text');
        var fileProgress = payload.currentFileProgress || 0;
        if (fileBar) {
            fileBar.style.width = fileProgress + '%';
            fileBar.textContent = Math.round(fileProgress) + '%';
            fileBar.setAttribute('aria-valuenow', fileProgress);
        }
        if (fileProgressText) {
            var fileLoaded = payload.currentFileLoaded || 0;
            var fileTotal = payload.currentFileTotal || 0;
            fileProgressText.textContent = formatSize(fileLoaded) + ' / ' + formatSize(fileTotal);
        }

        // 剩余时间
        var etaText = document.getElementById('hf-download-file-eta');
        if (etaText) {
            var elapsed = (Date.now() - downloadStartTime) / 1000;
            var downloaded = payload.downloadedSize || 0;
            var total = payload.totalSize || 0;
            if (elapsed > 2 && downloaded > 0 && total > downloaded) {
                var speedBps = downloaded / elapsed;
                var remainingSeconds = (total - downloaded) / speedBps;
                if (remainingSeconds < 60) {
                    etaText.textContent = '剩余时间: ' + Math.round(remainingSeconds) + ' 秒';
                } else if (remainingSeconds < 3600) {
                    etaText.textContent = '剩余时间: ' + Math.round(remainingSeconds / 60) + ' 分钟';
                } else {
                    etaText.textContent = '剩余时间: ' + (remainingSeconds / 3600).toFixed(1) + ' 小时';
                }
            } else {
                etaText.textContent = '剩余时间: 计算中...';
            }
        }
    }

    /**
     * 格式化文件大小
     */
    function formatSize(bytes) {
        if (!bytes || bytes <= 0) return '0 B';
        if (bytes < 1024) return bytes + ' B';
        if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB';
        if (bytes < 1024 * 1024 * 1024) return (bytes / (1024 * 1024)).toFixed(1) + ' MB';
        return (bytes / (1024 * 1024 * 1024)).toFixed(2) + ' GB';
    }

    /**
     * 监听扩展端模型下载/加载进度
     */
    function bindExtensionModelProgress() {
        window.addEventListener('message', function (event) {
            if (event.source !== window || !event.data) return;
            if (event.data.type !== 'AUTOLEADAGENT_MODEL_LOAD_PROGRESS') return;

            var payload = event.data.payload || {};
            console.log('[ModelLifecycle] 模型下载进度:', Math.round(payload.progress || 0) + '%', payload.currentFile || '');

            // 有实际下载行为时弹出进度弹窗
            if (payload.isDownloading || (payload.progress > 0 && payload.progress < 100)) {
                showDownloadModal();
                updateDownloadModalUI(payload);
            }

            // 下载完成（所有文件 done 或 status=ready）
            if (payload.status === 'ready' || (payload.progress >= 100 && !payload.isDownloading)) {
                // 延迟 1s 隐藏，让用户看到 100%
                setTimeout(function () {
                    hideDownloadModal();
                    if (typeof ConfigUtils !== 'undefined') {
                        ConfigUtils.safeToast('success', '模型下载完成，正在初始化...');
                    }
                }, 1000);
            }
        });
    }

    /**
     * 监听扩展端模型事件广播
     * content script 将 background 的 MODEL_EVENT 转为 AUTOLEADAGENT_MODEL_EVENT 发到页面
     */
    function bindExtensionModelEvents() {
        // 同时绑定下载进度事件
        bindExtensionModelProgress();

        window.addEventListener('message', function (event) {
            if (event.source !== window || !event.data) return;
            if (event.data.type !== 'AUTOLEADAGENT_MODEL_EVENT') return;

            var eventName = event.data.event;
            var eventData = event.data.data || {};
            console.log('[ModelLifecycle] 收到扩展模型事件:', eventName, eventData);

            switch (eventName) {
                case 'loaded':
                    // 模型加载完成 → 确保关闭下载弹窗
                    hideDownloadModal();
                    updateState({
                        loaded: true,
                        loading: false,
                        modelType: 'extension',
                        modelId: eventData.modelId || state.modelId,
                        error: null
                    });
                    updateUI('loaded');
                    if (callbacks.onLoadComplete) {
                        callbacks.onLoadComplete({ modelId: eventData.modelId, modelType: 'extension', loadTime: 0 });
                    }
                    break;
                case 'loading':
                    updateState({ loading: true, error: null });
                    updateUI('loading');
                    break;
                case 'unloaded':
                    updateState({ loaded: false, loading: false, modelType: null, modelId: null });
                    updateUI('unloaded');
                    if (callbacks.onUnload) callbacks.onUnload();
                    break;
                case 'load_error':
                    hideDownloadModal();
                    updateState({ loaded: false, loading: false, error: eventData.error || '加载失败' });
                    updateUI('error');
                    if (callbacks.onError) callbacks.onError(eventData.error);
                    break;
                case 'status':
                    // 状态同步
                    if (eventData.isLoaded) {
                        updateState({
                            loaded: true, loading: false, modelType: 'extension',
                            modelId: eventData.modelId || state.modelId, error: null
                        });
                        updateUI('loaded');
                    } else if (eventData.isLoading) {
                        updateState({ loading: true, error: null });
                        updateUI('loading');
                    }
                    break;
            }
        });
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
     * 绑定页面事件
     * 
     * 注意：不再在 beforeunload/pagehide 时卸载模型，原因：
     * 1. 浏览器的 bfcache（后退/前进缓存）可以保留 JS 堆，保持模型在内存中
     * 2. 卸载模型会阻止 bfcache，导致每次导航都要重新加载
     * 3. 浏览器关闭/标签页关闭时会自动释放内存，无需手动卸载
     * 4. 用户可通过 UI 上的"卸载"按钮手动释放
     */
    function bindPageEvents() {
        // 页面可见性变化时处理
        document.addEventListener('visibilitychange', function () {
            if (document.hidden) {
                console.log('[ModelLifecycle] 页面进入后台，模型保持加载');
            } else {
                console.log('[ModelLifecycle] 页面恢复前台，检查扩展端模型状态');
                // 页面恢复前台时，向扩展查询最新模型状态
                if (!state.loaded && !state.loading && config.autoLoad) {
                    autoLoadModel();
                }
            }
        });

        // 监听 pageshow 事件（bfcache 恢复时触发）
        window.addEventListener('pageshow', function (event) {
            if (event.persisted) {
                // 从 bfcache 恢复，查询扩展端模型状态
                console.log('[ModelLifecycle] 从 bfcache 恢复，查询扩展端模型状态');
                if (!state.loaded && config.autoLoad) {
                    autoLoadModel();
                }
            }
        });
    }

    /**
     * 绑定 UI 按钮
     */
    function bindUIButtons() {
        // 加载按钮 → 向扩展发送加载指令
        var loadBtn = document.getElementById('hf-model-load-btn');
        if (loadBtn) {
            loadBtn.addEventListener('click', function () {
                var modelId = getConfiguredModelId();
                if (!modelId) {
                    if (typeof ConfigUtils !== 'undefined') ConfigUtils.safeToast('warning', '请先选择模型');
                    return;
                }
                if (state.loaded) {
                    console.log('[ModelLifecycle] 模型已加载');
                    return;
                }
                updateState({ loading: true, error: null });
                updateUI('loading');
                notifyExtensionModelConfig(modelId, true);
                requestExtensionLoadModel(modelId);
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
     * 
     * 不再检查页面端 HFModelManager，因为模型只在扩展内运行。
     * 初始状态保持 loaded=false，由 autoLoadModel() 查询扩展后更新。
     */
    function checkModelStatus() {
        // 初始状态：不假设已加载，等 autoLoadModel 查询扩展确认
        console.log('[ModelLifecycle] 初始状态检查，等待扩展端确认');
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
                // 显示已加载的模型名称
                var displayModelId = state.modelId || '';
                if (statusText) statusText.textContent = '已加载' + (displayModelId ? ' — ' + displayModelId : '');
                if (modelTypeSpan) {
                    modelTypeSpan.style.display = 'inline';
                    if (state.modelType === 'extension') {
                        modelTypeSpan.className = 'badge ms-2 bg-info';
                        modelTypeSpan.textContent = '扩展托管';
                    } else if (state.modelType === 'chrome_ai') {
                        modelTypeSpan.className = 'badge ms-2 bg-success';
                        modelTypeSpan.textContent = 'Chrome AI';
                    } else {
                        modelTypeSpan.className = 'badge ms-2 bg-primary';
                        modelTypeSpan.textContent = 'WebLLM';
                    }
                }
                // 已加载时隐藏加载按钮，只显示卸载按钮
                if (loadBtn) loadBtn.style.display = 'none';
                if (unloadBtn) unloadBtn.style.display = 'inline-block';
                if (memoryInfo) memoryInfo.style.display = 'block';
                if (memoryValue) memoryValue.textContent = getMemoryUsageText();

                // 任务页
                if (taskModelStatus) {
                    taskModelStatus.innerHTML = '<span class="badge bg-success"><i class="mdi mdi-check-circle"></i> 已加载' + (displayModelId ? ' — ' + displayModelId : '') + '</span>';
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

        // 测试按钮始终启用（有 smartParseCommand 作为模型分析的 fallback）
        var testBtn = document.getElementById('hf_agent_test_btn');
        if (testBtn) {
            testBtn.disabled = false;
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
        updateUI: updateUI,
        notifyExtensionModelConfig: notifyExtensionModelConfig
    };
})();

// 导出到全局
if (typeof window !== 'undefined') {
    window.ModelLifecycle = ModelLifecycle;
}
