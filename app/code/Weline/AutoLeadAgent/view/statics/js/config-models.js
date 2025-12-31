/**
 * AutoLeadAgent 配置页 - Hugging Face 模型管理前端逻辑
 * 已优化：使用 ConfigUtils, ConfigExtensionClient, ConfigUIRenderer 模块
 */

(function () {
    'use strict';

    function bindHFModelConfig() {
        var card = document.getElementById('hf-model-config-card');
        if (!card) return;

        var currentModelId = card.getAttribute('data-current-model-id') || '';
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
                        setTimeout(function() {
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
            
            ConfigExtensionClient.sendMessage({
                type: 'HF_SEARCH_MODELS',
                query: q,
                task: task,
                limit: 50
            }, function (response) {
                if (!response) {
                    ConfigUIRenderer.renderModelList([]); // 清空加载状态
                    ConfigUtils.safeToast('error', '扩展未响应，请检查扩展是否已安装并启用');
                    return;
                }
                if (!response.success) {
                    ConfigUIRenderer.renderModelList([]); // 清空加载状态
                    ConfigUtils.safeToast('error', response.error || '搜索模型失败');
                    return;
                }
                ConfigUIRenderer.renderModelList(response.data, function(id, info) {
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
            });
        }

        /**
         * 加载详情
         */
        function loadModelInfo(modelId) {
            ConfigExtensionClient.sendMessage({
                type: 'HF_GET_MODEL_INFO',
                modelId: modelId
            }, function (response) {
                if (response && response.success) {
                    ConfigUIRenderer.renderModelDetail(response.data);
                } else {
                    ConfigUtils.safeToast('error', '获取模型信息失败');
                }
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

            var enabled = enabledInput ? enabledInput.checked : false;
            var cache = cacheInput ? parseInt(cacheInput.value || '10240', 10) : 10240;

            saveBtn.disabled = true;
            saveBtn.innerHTML = '<i class="mdi mdi-loading mdi-spin"></i> 保存中...';

            try {
                // 0. 检查模型是否已下载
                var isModelDownloaded = false;
                var downloadStatus = null;
                if (typeof LocalFileStorage !== 'undefined') {
                    downloadStatus = LocalFileStorage.checkModelDownloadedByMetadata(selectedModelId);
                    isModelDownloaded = downloadStatus.downloaded;
                }
                
                var shouldDownload = false;
                
                if (isModelDownloaded) {
                    // 模型已下载，询问是否直接加载
                    var loadNow = confirm('模型已下载完成（' + ConfigUtils.formatFileSize(downloadStatus.totalSize) + '）。\n\n是否立即加载模型？');
                    if (loadNow) {
                        // 不需要下载，稍后加载
                        shouldDownload = false;
                    }
                } else {
                    // 模型未下载，询问是否下载
                    shouldDownload = confirm('是否立即下载模型到本地缓存？\n\n（下载后可在离线环境使用端侧推理）\n\n点击"确定"将弹出目录选择对话框。');
                }
                
                // 1. 如果用户选择下载，先请求文件系统权限（必须在用户手势上下文中）
                if (shouldDownload && typeof LocalFileStorage !== 'undefined' && LocalFileStorage.requestModelDirectoryPermission) {
                    saveBtn.innerHTML = '<i class="mdi mdi-loading mdi-spin"></i> 选择保存目录...';
                    console.log('[HFModelConfig] 请求文件系统权限...');
                    try {
                        await LocalFileStorage.requestModelDirectoryPermission(selectedModelId);
                        console.log('[HFModelConfig] 文件系统权限已获取');
                        
                        // 保存目录路径
                        if (LocalFileStorage.saveSelectedDirectoryPath) {
                            LocalFileStorage.saveSelectedDirectoryPath(selectedModelId);
                        }
                    } catch (permErr) {
                        var permErrMsg = permErr.message || '权限获取失败';
                        if (permErrMsg.indexOf('aborted') !== -1 || permErrMsg.indexOf('user abort') !== -1) {
                            ConfigUtils.safeToast('warning', '已取消选择保存目录');
                            saveBtn.disabled = false;
                            saveBtn.innerHTML = '保存为当前模型';
                            return;
                        } else {
                            throw new Error('文件系统权限获取失败：' + permErrMsg);
                        }
                    }
                }
                
                // 2. 保存配置到后端（无论下载是否成功）
                // Weline 框架将 URL 转换为小写，驼峰命名转换为连字符格式
                var url = ConfigUtils.buildUrl('save-model-config');
                var formData = new FormData();
                formData.append('model_id', selectedModelId);
                formData.append('enabled', enabled ? '1' : '0');
                formData.append('cache_size', String(cache));

                console.log('[HFModelConfig] 保存配置到后端:', url, 'model_id:', selectedModelId, 'enabled:', enabled, 'cache:', cache);
                saveBtn.innerHTML = '<i class="mdi mdi-loading mdi-spin"></i> 保存配置...';
                var res = await fetch(url, { method: 'POST', body: formData });
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
                card.setAttribute('data-current-model-id', selectedModelId);
                
                // 更新下载状态显示
                updateDownloadStatusUI(selectedModelId, downloadStatus || { downloaded: false });
                
                // 3. 如果模型已下载，直接加载模型
                if (isModelDownloaded && !shouldDownload) {
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
                
                // 4. 如果用户选择下载，开始下载
                if (shouldDownload) {
                    // 禁用所有相关按钮
                    var allButtons = document.querySelectorAll('#hf-model-config-card button, #hf_model_search_btn, #hf_model_save_btn');
                    var originalButtonStates = [];
                    allButtons.forEach(function(btn) {
                        originalButtonStates.push({ btn: btn, disabled: btn.disabled });
                        btn.disabled = true;
                    });
                    
                    // 绑定取消按钮
                    var cancelBtn = document.getElementById('hf-download-cancel-btn');
                    var cancelHandler = function() {
                        ConfigDownloadManager.cancelDownload();
                    };
                    if (cancelBtn) {
                        cancelBtn.addEventListener('click', cancelHandler);
                    }
                    
                    saveBtn.innerHTML = '<i class="mdi mdi-loading mdi-spin"></i> 下载中...';
                    
                    try {
                        // 开始流式下载
                        await ConfigDownloadManager.startDownload(selectedModelId, function(state) {
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
                        allButtons.forEach(function(item, index) {
                            if (originalButtonStates[index]) {
                                item.btn.disabled = originalButtonStates[index].disabled;
                            }
                        });
                        
                        // 移除取消按钮监听器
                        if (cancelBtn) {
                            cancelBtn.removeEventListener('click', cancelHandler);
                        }
                    }
                }
                
                // 更新按钮状态
                saveBtn.disabled = false;
                saveBtn.innerHTML = '<i class="mdi mdi-check me-1"></i>已保存';
                saveBtn.classList.remove('btn-primary');
                saveBtn.classList.add('btn-success');
                
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
        
        if (searchInput) {
            searchInput.addEventListener('keydown', function(e) {
                if (e.key === 'Enter') { e.preventDefault(); searchModels(); }
            });
        }

        // 自动加载：等待扩展就绪后再执行，或者 1.5 秒后保底执行
        var autoLoadTried = false;
        function autoLoad() {
            if (autoLoadTried) return;
            autoLoadTried = true;
            searchModels();
        }

        window.addEventListener('autoleadagent-ready', function(event) {
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

        setTimeout(function() {
            autoLoad();
            // 如果 2 秒后扩展仍未就绪，保持引导显示
            setTimeout(function() {
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
     * 兼容性导出：hf-model-manager.js 使用
     */
    window.ensureModelDownloaded = function(modelId) {
        return new Promise((resolve, reject) => {
            ConfigExtensionClient.sendMessage({
                type: 'HF_DOWNLOAD_MODEL',
                modelId: modelId
            }, function(response) {
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
