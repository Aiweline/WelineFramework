/**
 * AutoLeadAgent 配置页 - Hugging Face 模型管理前端逻辑
 * 已优化：使用 ConfigUtils, ConfigExtensionClient, ConfigUIRenderer 模块
 */

(function () {
    'use strict';

    function bindHFModelConfig() {
        var card = document.getElementById('hf-model-config-card');
        if (!card) return;

        var currentModelId = (card.getAttribute('data-current-model-id') || '').trim();
        var enabledInput = document.getElementById('hf_model_enabled');
        var cacheInput = document.getElementById('hf_model_cache_size');
        var searchInput = document.getElementById('hf_model_search_input');
        var taskSelect = document.getElementById('hf_model_task_select');
        var searchBtn = document.getElementById('hf_model_search_btn');
        var saveBtn = document.getElementById('hf_model_save_btn');
        var refreshBtn = document.getElementById('hf_model_refresh_btn');

        var selectedModelId = currentModelId;

        // 初始化 UI
        ConfigUIRenderer.updateCurrentBadge(currentModelId);
        if (currentModelId) {
            loadModelInfo(currentModelId);
            // 检测模型是否已下载，并自动加载
            checkAndAutoLoadModel(currentModelId);
        }

        /**
         * 显示下载进度弹窗
         */
        function showDownloadProgressModal(modelId) {
            var modal = document.getElementById('hf-download-modal');
            if (!modal) {
                console.warn('[HFModelConfig] 下载进度弹窗元素不存在');
                return;
            }

            // 更新弹窗标题
            var titleEl = modal.querySelector('.modal-title');
            if (titleEl && modelId) {
                var shortName = modelId.split('/').pop() || modelId;
                titleEl.innerHTML = '<i class="mdi mdi-download text-primary me-2"></i>下载模型: ' + shortName;
            }

            // 重置进度条
            var totalBar = document.getElementById('hf-download-progress-bar');
            var fileBar = document.getElementById('hf-download-file-progress-bar');
            var progressText = document.getElementById('hf-download-progress-text');
            var fileText = document.getElementById('hf-download-progress-file');
            
            if (totalBar) { totalBar.style.width = '0%'; totalBar.textContent = '0%'; }
            if (fileBar) { fileBar.style.width = '0%'; }
            if (progressText) { progressText.textContent = '准备下载...'; }
            if (fileText) { fileText.textContent = '当前文件: 等待开始...'; }

            // 隐藏关闭按钮（下载中不允许关闭）
            var footer = document.getElementById('hf-download-modal-footer');
            if (footer) footer.style.display = 'none';

            // 显示弹窗
            try {
                if (typeof bootstrap !== 'undefined' && bootstrap.Modal) {
                    var bsModal;
                    if (typeof bootstrap.Modal.getOrCreateInstance === 'function') {
                        bsModal = bootstrap.Modal.getOrCreateInstance(modal);
                    } else {
                        bsModal = bootstrap.Modal.getInstance(modal) || new bootstrap.Modal(modal);
                    }
                    bsModal.show();
                    console.log('[HFModelConfig] 下载进度弹窗已显示');
                } else if (typeof $ !== 'undefined' && typeof $.fn.modal !== 'undefined') {
                    $(modal).modal('show');
                    console.log('[HFModelConfig] 下载进度弹窗已显示 (jQuery)');
                } else {
                    console.warn('[HFModelConfig] 无法显示弹窗：Bootstrap 或 jQuery 不可用');
                }
            } catch (e) {
                console.error('[HFModelConfig] 显示下载弹窗失败:', e);
            }
        }

        /**
         * 检测模型是否已下载，如果已下载则自动加载
         */
        function checkAndAutoLoadModel(modelId) {
            if (!modelId) return;

            if (typeof LocalFileStorage !== 'undefined') {
                var downloadStatus = LocalFileStorage.checkModelDownloadedByMetadata(modelId);

                // 更新下载状态显示
                updateDownloadStatusUI(modelId, downloadStatus);

                if (downloadStatus.downloaded) {
                    console.log('[HFModelConfig] 检测到模型已下载:', modelId, '文件数:', downloadStatus.fileCount);

                    // 自动加载模型
                    if (typeof ModelLifecycle !== 'undefined' && !ModelLifecycle.isLoaded()) {
                        console.log('[HFModelConfig] 自动加载已下载的模型...');
                        // 延迟加载，确保页面完全初始化
                        setTimeout(function () {
                            ModelLifecycle.loadModel(modelId);
                        }, 500);
                    }
                } else {
                    console.log('[HFModelConfig] 模型未下载或不完整:', modelId);
                }
            }
        }

        /**
         * 更新下载状态 UI
         */
        function updateDownloadStatusUI(modelId, downloadStatus) {
            var badge = document.getElementById('hf-model-current-badge');
            if (badge) {
                if (downloadStatus.downloaded) {
                    var sizeText = '';
                    if (downloadStatus.totalSize > 0) {
                        sizeText = ' (' + ConfigUtils.formatFileSize(downloadStatus.totalSize) + ')';
                    }
                    badge.innerHTML = '<i class="mdi mdi-check-circle me-1"></i>' + modelId + sizeText;
                    badge.className = 'badge bg-success';
                } else {
                    badge.innerHTML = '<i class="mdi mdi-cloud-download-outline me-1"></i>' + modelId + ' (未下载)';
                    badge.className = 'badge bg-warning text-dark';
                }
            }
        }

        /**
         * 搜索模型
         */
        function searchModels() {
            var q = (searchInput.value || '').trim();
            var task = taskSelect.value || 'text-generation';

            ConfigUIRenderer.renderModelList(null); // 显示加载中状态

            // 先尝试通过扩展搜索，如果扩展不可用则降级到后端 API
            ConfigExtensionClient.sendMessage({
                type: 'HF_SEARCH_MODELS',
                query: q,
                task: task,
                limit: 50
            }, function (response) {
                // 如果扩展未响应或失败，降级到后端 API
                if (!response || !response.success) {
                    var errorMsg = response && response.error ? response.error : '扩展搜索失败';
                    console.log('[HFModelConfig] 扩展搜索失败，降级到后端 API', errorMsg);
                    
                    // 如果是网络错误，显示提示但不显示错误 toast（因为会使用后端 API）
                    if (response && response.errorType === 'NetworkError') {
                        console.warn('[HFModelConfig] 网络连接问题，自动切换到后端 API');
                    } else if (response && response.error) {
                        // 其他错误也显示提示
                        ConfigUtils.safeToast('warning', '扩展搜索失败，正在使用后端 API: ' + errorMsg.replace(/\n/g, ' '));
                    }
                    
                    // 自动降级到后端 API
                    searchModelsViaBackend(q, task);
                    return;
                }

                // 处理扩展返回的数据
                handleSearchResponse(response.data);
            });
        }

        /**
         * 通过后端 API 搜索模型（降级方案）
         */
        function searchModelsViaBackend(query, task) {
            var url = ConfigUtils.buildUrl('search-hugging-face-models', {
                q: query,
                task: task,
                limit: 50
            });

            console.log('[HFModelConfig] 通过后端 API 搜索模型:', url);

            fetch(url)
                .then(function(response) {
                    if (!response.ok) {
                        throw new Error('HTTP ' + response.status + ': ' + response.statusText);
                    }
                    return response.json();
                })
                .then(function(result) {
                    if (!result.success) {
                        throw new Error(result.message || '搜索模型失败');
                    }
                    handleSearchResponse(result.data || []);
                })
                .catch(function(error) {
                    console.error('[HFModelConfig] 后端 API 搜索失败:', error);
                    ConfigUIRenderer.renderModelList([]); // 清空加载状态
                    ConfigUtils.safeToast('error', '搜索模型失败: ' + error.message);
                });
        }

        /**
         * 推荐的兼容模型（已确认可在 transformers.js v3.4+ 中运行）
         */
        var RECOMMENDED_MODELS = [
            // 轻量级优先 —— 浏览器中 > 500MB 可能导致崩溃
            { id: 'onnx-community/gemma-3-270m-it-ONNX', downloads: 965, likes: 24, _recommended: true, _recLabel: '⭐ 首选 · 270M · ~150MB · 最稳定', _sizeEstimate: '~150MB', _sizeMB: 150 },
            { id: 'onnx-community/SmolLM2-360M-ONNX', downloads: 18, likes: 1, _recommended: true, _recLabel: '⭐ 推荐 · 360M · ~200MB · 轻量快速', _sizeEstimate: '~200MB', _sizeMB: 200 },
            { id: 'onnx-community/Qwen2.5-0.5B-Instruct', downloads: 2230, likes: 11, _recommended: true, _recLabel: '推荐 · 0.5B · ~300MB · 中文好', _sizeEstimate: '~300MB', _sizeMB: 300 },
            { id: 'onnx-community/Qwen3-0.6B-ONNX', downloads: 44, likes: 3, _recommended: true, _recLabel: '⚠️ 较大 · 0.6B · ~400MB · 可能卡顿', _sizeEstimate: '~400MB', _sizeMB: 400 },
            { id: 'onnx-community/Llama-3.2-1B-Instruct-ONNX', downloads: 1580, likes: 29, _recommended: true, _recLabel: '⚠️ 大模型 · 1B · ~600MB · 需8G+内存', _sizeEstimate: '~600MB', _sizeMB: 600 },
            { id: 'onnx-community/Qwen2.5-1.5B-Instruct', downloads: 449, likes: 6, _recommended: true, _recLabel: '⚠️ 大模型 · 1.5B · ~900MB · 可能崩溃', _sizeEstimate: '~900MB', _sizeMB: 900 },
        ];

        /**
         * 不兼容的模型类型（已知不支持的架构）
         * 注：transformers.js v3.8.1 已支持 Qwen3、Gemma3n、SmolLM3 等
         */
        var INCOMPATIBLE_PATTERNS = [];

        /**
         * 处理搜索响应数据
         */
        function handleSearchResponse(models) {
            if (!models || !Array.isArray(models)) {
                ConfigUIRenderer.renderModelList([]);
                ConfigUtils.safeToast('error', '模型列表格式错误');
                return;
            }

            // 过滤不兼容的模型（如 Qwen3 非 ONNX 版本）
            var filteredModels = models.filter(function (m) {
                var mid = (m.id || m.name || '').toLowerCase();
                for (var i = 0; i < INCOMPATIBLE_PATTERNS.length; i++) {
                    if (INCOMPATIBLE_PATTERNS[i].test(mid)) return false;
                }
                return true;
            });

            // 将推荐模型置顶（去重后合并）
            var existingIds = {};
            filteredModels.forEach(function (m) { existingIds[(m.id || m.name || '').toLowerCase()] = true; });
            var topModels = [];
            RECOMMENDED_MODELS.forEach(function (rm) {
                if (!existingIds[rm.id.toLowerCase()]) {
                    topModels.push(rm);
                } else {
                    // 已在搜索结果中 → 标记为推荐
                    filteredModels.forEach(function (m) {
                        if ((m.id || m.name || '').toLowerCase() === rm.id.toLowerCase()) {
                            m._recommended = true;
                            m._recLabel = rm._recLabel;
                        }
                    });
                }
            });
            filteredModels = topModels.concat(filteredModels);

            // 兼容性检查：过滤掉不支持 WebLLM/ONNX 格式的模型
            if (filteredModels.length > 0) {
                if (typeof HFModelManager !== 'undefined' && HFModelManager.isModelSupportedForWebLLM) {
                    filteredModels = filteredModels.filter(function (m) {
                        var modelId = m.id || m.name || '';
                        var isSupported = HFModelManager.isModelSupportedForWebLLM(modelId);
                        if (!isSupported) {
                            console.log('[HFModelConfig] 过滤不支持的模型:', modelId);
                        }
                        return isSupported;
                    });
                }
            }

            ConfigUIRenderer.renderModelList(filteredModels, function (id, info) {
                selectedModelId = id;
                loadModelInfo(id);

                // 检查该模型是否已下载
                if (typeof LocalFileStorage !== 'undefined') {
                    var downloadStatus = LocalFileStorage.checkModelDownloadedByMetadata(id);
                    if (downloadStatus.downloaded) {
                        ConfigUIRenderer.updateCurrentBadge(id + ' (已下载，待保存)');
                    } else {
                        ConfigUIRenderer.updateCurrentBadge(id + ' (待保存)');
                    }
                } else {
                    ConfigUIRenderer.updateCurrentBadge(id + ' (待保存)');
                }
            });
        }

        /**
         * 加载详情
         */
        function loadModelInfo(modelId) {
            var id = (modelId != null && modelId !== undefined) ? String(modelId).trim() : '';
            if (!id) {
                ConfigUtils.safeToast('error', '模型ID不能为空');
                return;
            }

            // 先尝试通过扩展获取，如果扩展不可用则降级到后端 API
            ConfigExtensionClient.sendMessage({
                type: 'HF_GET_MODEL_INFO',
                modelId: id
            }, function (response) {
                // 如果扩展未响应或失败，降级到后端 API
                if (!response || !response.success) {
                    console.log('[HFModelConfig] 扩展获取模型信息失败，降级到后端 API');
                    loadModelInfoViaBackend(id);
                    return;
                }

                // 处理扩展返回的数据
                ConfigUIRenderer.renderModelDetail(response.data);
            });
        }

        /**
         * 通过后端 API 获取模型信息（降级方案）
         */
        function loadModelInfoViaBackend(modelId) {
            var id = (modelId != null && modelId !== undefined) ? String(modelId).trim() : '';
            if (!id) {
                ConfigUtils.safeToast('error', '模型ID不能为空');
                return;
            }
            var url = ConfigUtils.buildUrl('get-model-info', {
                model_id: id
            });

            console.log('[HFModelConfig] 通过后端 API 获取模型信息:', url);

            fetch(url)
                .then(function(response) {
                    if (!response.ok) {
                        throw new Error('HTTP ' + response.status + ': ' + response.statusText);
                    }
                    return response.json();
                })
                .then(function(result) {
                    if (!result.success) {
                        throw new Error(result.message || '获取模型信息失败');
                    }
                    ConfigUIRenderer.renderModelDetail(result.data);
                })
                .catch(function(error) {
                    console.error('[HFModelConfig] 后端 API 获取模型信息失败:', error);
                    ConfigUtils.safeToast('error', '获取模型信息失败: ' + error.message);
                });
        }

        /**
         * 保存配置（先保存配置到后端，再可选地下载模型）
         */
        async function saveModelConfig() {
            if (!selectedModelId) {
                ConfigUtils.safeToast('error', '请先在列表中选择一个模型');
                return;
            }

            // 大模型警告 —— 检查模型大小并提示用户
            var selectedModel = null;
            for (var i = 0; i < RECOMMENDED_MODELS.length; i++) {
                if (RECOMMENDED_MODELS[i].id === selectedModelId) { selectedModel = RECOMMENDED_MODELS[i]; break; }
            }
            if (selectedModel && selectedModel._sizeMB && selectedModel._sizeMB >= 400) {
                var confirmMsg = '⚠️ 警告：该模型预估大小约 ' + selectedModel._sizeEstimate + '，加载后占用内存可能超过 ' + Math.round(selectedModel._sizeMB * 2.5) + 'MB。\n\n大模型可能导致浏览器卡顿甚至崩溃。\n\n推荐选择较小的模型（如 gemma-3-270m-it ~150MB）。\n\n确定要继续吗？';
                if (!confirm(confirmMsg)) {
                    return;
                }
            }

            var enabled = enabledInput ? enabledInput.checked : false;
            var cache = cacheInput ? parseInt(cacheInput.value || '10240', 10) : 10240;

            saveBtn.disabled = true;
            saveBtn.innerHTML = '<i class="mdi mdi-loading mdi-spin"></i> 处理中...';

            try {
                // 0. 检查模型是否已下载
                var isModelDownloaded = false;
                var downloadStatus = null;
                if (typeof LocalFileStorage !== 'undefined') {
                    downloadStatus = LocalFileStorage.checkModelDownloadedByMetadata(selectedModelId);
                    isModelDownloaded = downloadStatus.downloaded;
                }

                // 1. 如果模型已下载，直接加载
                if (isModelDownloaded) {
                    console.log('[HFModelConfig] 模型已下载，直接加载');

                    // 保存配置到后端（JSON 提交）
                    var url = ConfigUtils.buildUrl('save-model-config');
                    var postBody = JSON.stringify({
                        model_id: selectedModelId,
                        enabled: enabled ? '1' : '0',
                        cache_size: String(cache)
                    });

                    console.log('[HFModelConfig] 保存配置到后端:', url, 'model_id:', selectedModelId, 'enabled:', enabled, 'cache:', cache);
                    saveBtn.innerHTML = '<i class="mdi mdi-loading mdi-spin"></i> 保存配置...';
                    var res = await fetch(url, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, body: postBody });
                    console.log('[HFModelConfig] 响应状态:', res.status, res.statusText);

                    var responseText = await res.text();
                    console.log('[HFModelConfig] 响应内容:', responseText);

                    var data;
                    try {
                        data = JSON.parse(responseText);
                    } catch (parseErr) {
                        console.error('[HFModelConfig] JSON 解析失败:', parseErr);
                        throw new Error('服务器响应格式错误: ' + responseText.substring(0, 200));
                    }

                    if (!data.success) {
                        throw new Error(data.message || '保存配置失败');
                    }

                    console.log('[HFModelConfig] 后端配置保存成功');
                    ConfigUtils.safeToast('success', '模型配置已保存');

                    // 更新配置卡片的 data 属性
                    var card = document.getElementById('hf-model-config-card');
                    if (card) {
                        card.setAttribute('data-current-model-id', selectedModelId);
                    }

                    // 加载模型
                    saveBtn.innerHTML = '<i class="mdi mdi-loading mdi-spin"></i> 加载模型...';

                    if (typeof ModelLifecycle !== 'undefined') {
                        try {
                            await ModelLifecycle.loadModel(selectedModelId);
                            ConfigUtils.safeToast('success', '模型加载成功');
                        } catch (loadErr) {
                            console.error('[HFModelConfig] 模型加载失败:', loadErr);
                            ConfigUtils.safeToast('warning', '模型加载失败: ' + loadErr.message);
                        }
                    }

                    saveBtn.disabled = false;
                    saveBtn.innerHTML = '<i class="mdi mdi-check me-1"></i>已保存';
                    saveBtn.classList.remove('btn-primary');
                    saveBtn.classList.add('btn-success');
                    return;
                }

                // 2. 模型未下载，直接继续保存流程（模型统一存储在 pub/models 目录）

                // 3. 保存配置到后端（JSON 提交）
                var url = ConfigUtils.buildUrl('save-model-config');
                var postBody = JSON.stringify({
                    model_id: selectedModelId,
                    enabled: enabled ? '1' : '0',
                    cache_size: String(cache)
                });

                console.log('[HFModelConfig] 保存配置到后端:', url, 'model_id:', selectedModelId, 'enabled:', enabled, 'cache:', cache);
                saveBtn.innerHTML = '<i class="mdi mdi-loading mdi-spin"></i> 保存配置...';
                var res = await fetch(url, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, body: postBody });
                console.log('[HFModelConfig] 响应状态:', res.status, res.statusText);

                var responseText = await res.text();
                console.log('[HFModelConfig] 响应内容:', responseText);

                var data;
                try {
                    data = JSON.parse(responseText);
                } catch (parseErr) {
                    console.error('[HFModelConfig] JSON 解析失败:', parseErr);
                    throw new Error('服务器响应格式错误: ' + responseText.substring(0, 200));
                }

                if (!data.success) {
                    throw new Error(data.message || '保存配置失败');
                }

                console.log('[HFModelConfig] 后端配置保存成功');
                ConfigUtils.safeToast('success', '模型配置已保存');

                // 更新配置卡片的 data 属性
                var card = document.getElementById('hf-model-config-card');
                if (card) {
                    card.setAttribute('data-current-model-id', selectedModelId);
                }

                // 4. 开始下载模型
                ConfigUtils.safeToast('info', '开始下载模型，请稍候...');

                // 禁用所有相关按钮
                var allButtons = document.querySelectorAll('#hf-model-config-card button, #hf_model_search_btn, #hf_model_save_btn');
                var originalButtonStates = [];
                allButtons.forEach(function (btn) {
                    originalButtonStates.push({ btn: btn, disabled: btn.disabled });
                    btn.disabled = true;
                });

                // 绑定取消按钮
                var cancelBtn = document.getElementById('hf-download-cancel-btn');
                var cancelHandler = function () {
                    ConfigDownloadManager.cancelDownload();
                };
                if (cancelBtn) {
                    cancelBtn.addEventListener('click', cancelHandler);
                }

                saveBtn.innerHTML = '<i class="mdi mdi-loading mdi-spin"></i> 下载中...';

                // 主动显示下载进度弹窗
                showDownloadProgressModal(selectedModelId);

                try {
                    // 开始流式下载
                    await ConfigDownloadManager.startDownload(selectedModelId, function (state) {
                        // 确保弹窗显示并更新进度
                        state.isDownloading = true;
                        ConfigUIRenderer.updateDownloadProgress(state);
                        console.log('[HFModelConfig] 下载进度:', state.progress.toFixed(2) + '%', state.currentFile);
                    });

                    ConfigUIRenderer.hideDownloadModal();
                    ConfigUtils.safeToast('success', '模型下载完成');

                    // 下载完成后自动加载模型
                    if (typeof ModelLifecycle !== 'undefined') {
                        ModelLifecycle.autoLoadAfterDownload(selectedModelId);
                    }
                } catch (downloadErr) {
                    ConfigUIRenderer.hideDownloadModal();
                    var downloadErrMsg = downloadErr.message || '下载失败';
                    if (downloadErrMsg.indexOf('取消') !== -1 || downloadErrMsg.indexOf('aborted') !== -1 || downloadErrMsg.indexOf('user abort') !== -1) {
                        ConfigUtils.safeToast('warning', '已取消下载');
                    } else {
                        ConfigUtils.safeToast('warning', '模型下载失败（配置已保存）：' + downloadErrMsg);
                    }
                } finally {
                    // 恢复按钮状态
                    originalButtonStates.forEach(function (item) {
                        if (item && item.btn) {
                            item.btn.disabled = item.disabled;
                        }
                    });

                    // 移除取消按钮监听器
                    if (cancelBtn) {
                        cancelBtn.removeEventListener('click', cancelHandler);
                    }

                    // 更新按钮状态
                    saveBtn.disabled = false;
                    saveBtn.innerHTML = '<i class="mdi mdi-check me-1"></i>已保存';
                    saveBtn.classList.remove('btn-primary');
                    saveBtn.classList.add('btn-success');
                }

            } catch (err) {
                ConfigUIRenderer.hideDownloadModal();
                ConfigUtils.safeToast('error', err.message || '保存失败');
                saveBtn.disabled = false;
                saveBtn.innerHTML = '保存为当前模型';
            }
        }

        // 绑定事件
        if (searchBtn) searchBtn.addEventListener('click', searchModels);
        if (saveBtn) saveBtn.addEventListener('click', saveModelConfig);
        if (refreshBtn) refreshBtn.addEventListener('click', () => loadModelInfo(selectedModelId));
        
        // 网络配置（镜像和代理）
        bindNetworkConfigEvents();

        if (searchInput) {
            searchInput.addEventListener('keydown', function (e) {
                if (e.key === 'Enter') { e.preventDefault(); searchModels(); }
            });
        }

        // 监听模型下载完成事件，刷新模型列表
        window.addEventListener('model-download-complete', function (event) {
            console.log('[HFModelConfig] 模型下载完成事件:', event.detail.modelId);
            // 刷新模型列表以更新下载状态
            searchModels();
        });

        // 自动加载：等待扩展就绪后再执行，或者 1.5 秒后保底执行
        var autoLoadTried = false;
        function autoLoad() {
            if (autoLoadTried) return;
            autoLoadTried = true;
            searchModels();
        }

        window.addEventListener('autoleadagent-ready', function (event) {
            // 扩展就绪后，更新 UI 状态
            var guide = document.getElementById('extension-install-guide');
            var info = document.getElementById('extension-installed-info');
            var badge = document.getElementById('extension-status-badge');
            var versionDisplay = document.getElementById('extension-version-display');

            if (guide) guide.style.display = 'none';
            if (info) info.style.display = 'block';
            if (badge) {
                badge.className = 'badge bg-success';
                badge.textContent = '已连接';
            }
            if (versionDisplay && event.detail && event.detail.version) {
                versionDisplay.textContent = '(v' + event.detail.version + ')';
            }

            autoLoad();
        });

        setTimeout(function () {
            autoLoad();
            // 如果 2 秒后扩展仍未就绪，保持引导显示
            setTimeout(function () {
                if (!ConfigExtensionClient.isReady()) {
                    var guide = document.getElementById('extension-install-guide');
                    var info = document.getElementById('extension-installed-info');
                    var badge = document.getElementById('extension-status-badge');

                    if (guide) guide.style.display = 'block';
                    if (info) info.style.display = 'none';
                    if (badge) {
                        badge.className = 'badge bg-danger';
                        badge.textContent = '未检测到';
                    }

                    // 同时也隐藏加载状态
                    ConfigUIRenderer.renderModelList([]);
                }
            }, 500);
        }, 1500);
    }

    /**
     * 绑定网络配置事件（镜像和代理）
     */
    function bindNetworkConfigEvents() {
        var useMirrorCheckbox = document.getElementById('hf_use_mirror');
        var mirrorUrlGroup = document.getElementById('hf_mirror_url_group');
        var mirrorUrlInput = document.getElementById('hf_mirror_url');
        var proxyEnabledCheckbox = document.getElementById('hf_proxy_enabled');
        var proxyUrlGroup = document.getElementById('hf_proxy_url_group');
        var proxyUrlInput = document.getElementById('hf_proxy_url');
        
        if (!useMirrorCheckbox && !proxyEnabledCheckbox) return;
        
        // 镜像开关切换
        if (useMirrorCheckbox && mirrorUrlGroup) {
            useMirrorCheckbox.addEventListener('change', function() {
                mirrorUrlGroup.style.display = this.checked ? '' : 'none';
                saveNetworkConfig();
            });
        }
        
        // 代理开关切换
        if (proxyEnabledCheckbox && proxyUrlGroup) {
            proxyEnabledCheckbox.addEventListener('change', function() {
                proxyUrlGroup.style.display = this.checked ? '' : 'none';
                saveNetworkConfig();
            });
        }
        
        // 镜像 URL 输入框失焦保存
        if (mirrorUrlInput) {
            mirrorUrlInput.addEventListener('blur', function() {
                saveNetworkConfig();
            });
        }
        
        // 代理 URL 输入框失焦保存
        if (proxyUrlInput) {
            proxyUrlInput.addEventListener('blur', function() {
                saveNetworkConfig();
            });
        }
        
        /**
         * 保存网络配置到后端
         */
        function saveNetworkConfig() {
            var useMirror = useMirrorCheckbox ? useMirrorCheckbox.checked : false;
            var mirrorUrl = mirrorUrlInput ? mirrorUrlInput.value.trim() : 'https://hf-mirror.com';
            var proxyEnabled = proxyEnabledCheckbox ? proxyEnabledCheckbox.checked : false;
            var proxyUrl = proxyUrlInput ? proxyUrlInput.value.trim() : '';
            
            var url = ConfigUtils.buildUrl('save-network-config');
            var postBody = JSON.stringify({
                use_mirror: useMirror ? '1' : '0',
                mirror_url: mirrorUrl,
                proxy_enabled: proxyEnabled ? '1' : '0',
                proxy_url: proxyUrl
            });
            
            console.log('[HFModelConfig] 保存网络配置:', url, postBody);
            
            fetch(url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: postBody
            })
            .then(function(res) { 
                console.log('[HFModelConfig] 响应状态:', res.status);
                return res.text(); 
            })
            .then(function(text) {
                console.log('[HFModelConfig] 响应内容:', text);
                var data;
                try {
                    data = JSON.parse(text);
                } catch (e) {
                    console.error('[HFModelConfig] JSON 解析失败:', e, text);
                    ConfigUtils.safeToast('error', '网络配置保存失败：响应格式错误');
                    return;
                }
                if (data.success) {
                    console.log('[HFModelConfig] 网络配置已保存成功');
                    ConfigUtils.safeToast('success', '网络配置已保存');
                } else {
                    console.error('[HFModelConfig] 网络配置保存失败:', data.message);
                    ConfigUtils.safeToast('error', data.message || '网络配置保存失败');
                }
            })
            .catch(function(err) {
                console.error('[HFModelConfig] 网络配置保存错误:', err);
                ConfigUtils.safeToast('error', '网络配置保存失败：' + err.message);
            });
        }
    }

    /**
     * 兼容性导出：hf-model-manager.js 使用
     */
    window.ensureModelDownloaded = function (modelId) {
        return new Promise((resolve, reject) => {
            ConfigExtensionClient.sendMessage({
                type: 'HF_DOWNLOAD_MODEL',
                modelId: modelId
            }, function (response) {
                if (response && response.success) resolve(response);
                else reject(new Error((response && response.error) || '下载失败'));
            });
        });
    };

    // 页面加载完成后初始化
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', bindHFModelConfig);
    } else {
        bindHFModelConfig();
    }

})();
