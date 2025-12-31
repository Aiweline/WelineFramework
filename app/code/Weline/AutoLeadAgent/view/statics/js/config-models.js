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

        /**
         * 搜索模型
         */
        function searchModels() {
            var q = (searchInput.value || '').trim();
            var task = taskSelect.value || 'text-generation';

            ConfigUIRenderer.renderModelList([], null); // 清空列表并显示加载中
            
            ConfigExtensionClient.sendMessage({
                type: 'HF_SEARCH_MODELS',
                query: q,
                task: task,
                limit: 50
            }, function (response) {
                if (!response || !response.success) {
                    ConfigUtils.safeToast('error', (response && response.error) || '搜索模型失败');
                    return;
                }
                ConfigUIRenderer.renderModelList(response.data, function(id, info) {
                    selectedModelId = id;
                    loadModelInfo(id);
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
         * 保存配置并触发下载
         */
        function saveModelConfig() {
            if (!selectedModelId) {
                ConfigUtils.safeToast('error', '请先在列表中选择一个模型');
                return;
            }

            var enabled = enabledInput ? enabledInput.checked : false;
            var cache = cacheInput ? parseInt(cacheInput.value || '10240', 10) : 10240;

            saveBtn.disabled = true;
            saveBtn.innerHTML = '<i class="mdi mdi-loading mdi-spin"></i> 处理中...';

            // 1. 开始流式下载
            ConfigDownloadManager.startDownload(selectedModelId, function(state) {
                // 更新 UI 进度（可以在此处调用 UIRenderer 更新进度条）
                console.log('[HFModelConfig] 下载进度:', state.progress.toFixed(2) + '%', state.currentFile);
            }).then(function (response) {
                // 2. 保存到后端配置
                var url = ConfigUtils.buildUrl('save-model-config');
                var formData = new FormData();
                formData.append('model_id', selectedModelId);
                formData.append('enabled', enabled ? '1' : '0');
                formData.append('cache_size', String(cache));

                return fetch(url, { method: 'POST', body: formData });
            }).then(res => res.json())
            .then(data => {
                if (data.success) {
                    ConfigUtils.safeToast('success', '模型配置已保存');
                    setTimeout(() => window.location.reload(), 1000);
                } else {
                    throw new Error(data.message || '保存失败');
                }
            })
            .catch(err => {
                ConfigUtils.safeToast('error', err.message);
                saveBtn.disabled = false;
                saveBtn.innerHTML = '保存为当前模型';
            });
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
