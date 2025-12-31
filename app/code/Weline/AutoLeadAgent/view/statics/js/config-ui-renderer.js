/**
 * AutoLeadAgent 配置页 - UI 渲染器
 */

var ConfigUIRenderer = (function () {
    'use strict';

    var elements = {
        badge: 'hf-model-current-badge',
        listBody: 'hf_model_list_body',
        detailName: 'hf_model_detail_name',
        detailStatus: 'hf_model_detail_status',
        detailSize: 'hf_model_detail_size',
        detailTask: 'hf_model_detail_task',
        detailTags: 'hf_model_detail_tags'
    };

    function getEl(id) { return document.getElementById(id); }

    /**
     * 渲染模型列表
     */
    function renderModelList(models, onModelSelect) {
        var listBody = getEl(elements.listBody);
        if (!listBody) return;
        listBody.innerHTML = '';

        console.log('[ConfigUIRenderer] 渲染模型列表，数量:', models ? models.length : '加载中');
        if (models && models.length > 0) {
            console.log('[ConfigUIRenderer] 第一个模型示例:', models[0]);
        }

        // models 为 null 表示加载中
        if (models === null) {
            listBody.innerHTML = '<tr><td colspan="4" class="text-center py-4">' +
                '<div class="spinner-border spinner-border-sm text-primary me-2" role="status"></div>' +
                '<span class="small text-muted">正在连接扩展并获取 Hugging Face 模型...</span>' +
                '<div class="mt-2 small text-warning" style="font-size: 0.75rem;">提示：如果一直显示此信息，请确保已安装并开启 <span class="fw-bold">AutoLeadAgent 扩展</span></div>' +
                '</td></tr>';
            return;
        }

        if (!models || !models.length) {
            listBody.innerHTML = '<tr><td colspan="4" class="text-muted small text-center">未找到匹配的模型</td></tr>';
            return;
        }

        models.forEach(function (m) {
            var modelId = m.id || m.name || '';
            var tr = document.createElement('tr');
            tr.className = 'hf-model-row';
            tr.setAttribute('data-model-id', modelId);

            var tdName = document.createElement('td');
            tdName.style.width = '20%';
            tdName.style.maxWidth = '20%';
            tdName.style.overflow = 'hidden';
            tdName.style.textOverflow = 'ellipsis';
            tdName.style.whiteSpace = 'nowrap';
            tdName.textContent = modelId;
            tdName.title = modelId; // 添加 tooltip 显示完整名称

            var tdSize = document.createElement('td');
            tdSize.className = 'text-end small text-muted';
            tdSize.textContent = ConfigUtils.formatFileSizeFromMB(m.estimated_size_mb) || '-';

            var tdDownloads = document.createElement('td');
            tdDownloads.className = 'text-end small text-muted';
            tdDownloads.textContent = ConfigUtils.formatNumber(m.downloads || 0);

            var tdAction = document.createElement('td');
            tdAction.className = 'text-center';
            tdAction.innerHTML = '<button class="btn btn-sm btn-outline-primary select-btn">选择</button>';

            tr.appendChild(tdName);
            tr.appendChild(tdSize);
            tr.appendChild(tdDownloads);
            tr.appendChild(tdAction);

            tr.addEventListener('click', function () {
                if (onModelSelect) onModelSelect(modelId, m);
                var rows = listBody.querySelectorAll('.hf-model-row');
                rows.forEach(function (r) { r.classList.remove('table-active'); });
                tr.classList.add('table-active');
            });

            listBody.appendChild(tr);
        });
    }

    /**
     * 渲染模型详情
     */
    function renderModelDetail(info) {
        var detailName = getEl(elements.detailName);
        var detailStatus = getEl(elements.detailStatus);
        var detailSize = getEl(elements.detailSize);
        var detailTask = getEl(elements.detailTask);
        var detailTags = getEl(elements.detailTags);

        if (detailName) detailName.textContent = info.id || info.name || '尚未选择模型';
        if (detailStatus) detailStatus.innerHTML = info.id ? '<span class="badge bg-secondary">已选择</span>' : '';
        if (detailSize) detailSize.textContent = info.estimated_size_mb ? ('大小约：' + ConfigUtils.formatFileSizeFromMB(info.estimated_size_mb)) : '';
        if (detailTask) detailTask.textContent = info.pipeline_tag ? ('任务类型：' + info.pipeline_tag) : '';
        
        if (detailTags) {
            detailTags.innerHTML = '';
            (info.tags || []).forEach(function (t) {
                var span = document.createElement('span');
                span.className = 'badge bg-light text-dark me-1 mb-1';
                span.textContent = t;
                detailTags.appendChild(span);
            });
        }
    }

    /**
     * 更新当前模型徽章
     */
    function updateCurrentBadge(modelId) {
        var badge = getEl(elements.badge);
        if (badge) badge.textContent = modelId || '未配置模型';
    }

    /**
     * 更新下载进度 UI
     */
    function updateDownloadProgress(state) {
        var modal = document.getElementById('hf-download-modal');
        if (!modal) return;

        // 兼容不同版本的 Bootstrap
        if (state.isDownloading && !modal.classList.contains('show')) {
            try {
                // Bootstrap 5.x
                if (typeof bootstrap !== 'undefined' && bootstrap.Modal) {
                    var bsModal;
                    if (typeof bootstrap.Modal.getOrCreateInstance === 'function') {
                        bsModal = bootstrap.Modal.getOrCreateInstance(modal);
                    } else {
                        // Bootstrap 5.0 早期版本
                        bsModal = bootstrap.Modal.getInstance(modal) || new bootstrap.Modal(modal);
                    }
                    bsModal.show();
                } else {
                    // 降级：jQuery Bootstrap
                    if (typeof $ !== 'undefined' && typeof $.fn.modal !== 'undefined') {
                        $(modal).modal('show');
                    }
                }
            } catch (e) {
                console.warn('[ConfigUIRenderer] 无法显示下载模态框:', e);
            }
        }

        // 总进度
        var totalBar = document.getElementById('hf-download-progress-bar');
        var totalText = document.getElementById('hf-download-progress-text');
        if (totalBar) {
            totalBar.style.width = state.progress + '%';
            totalBar.textContent = Math.round(state.progress) + '%';
            totalBar.setAttribute('aria-valuenow', state.progress);
        }
        if (totalText) {
            totalText.textContent = ConfigUtils.formatFileSize(state.downloadedSize) + ' / ' + 
                                   ConfigUtils.formatFileSize(state.totalSize) + ' (' + Math.round(state.progress) + '%)';
        }

        // 当前文件
        var fileText = document.getElementById('hf-download-progress-file');
        if (fileText) fileText.textContent = '当前文件: ' + (state.currentFile || '准备中...');
        
        // 当前文件进度
        var fileBar = document.getElementById('hf-download-file-progress-bar');
        var fileProgressText = document.getElementById('hf-download-file-progress-text');
        if (fileBar && state.currentFileProgress !== undefined) {
            fileBar.style.width = state.currentFileProgress + '%';
            fileBar.textContent = Math.round(state.currentFileProgress) + '%';
            fileBar.setAttribute('aria-valuenow', state.currentFileProgress);
        }
        if (fileProgressText && state.currentFileSize > 0) {
            fileProgressText.textContent = ConfigUtils.formatFileSize(state.currentFileDownloaded || 0) + ' / ' + 
                                          ConfigUtils.formatFileSize(state.currentFileSize);
        }
    }

    /**
     * 隐藏下载模态框
     */
    function hideDownloadModal() {
        var modal = document.getElementById('hf-download-modal');
        if (modal) {
            try {
                if (typeof bootstrap !== 'undefined' && bootstrap.Modal) {
                    var bsModal = bootstrap.Modal.getInstance(modal);
                    if (bsModal) bsModal.hide();
                } else if (typeof $ !== 'undefined' && typeof $.fn.modal !== 'undefined') {
                    $(modal).modal('hide');
                }
            } catch (e) {
                console.warn('[ConfigUIRenderer] 无法隐藏下载模态框:', e);
            }
        }
    }

    return {
        renderModelList: renderModelList,
        renderModelDetail: renderModelDetail,
        updateCurrentBadge: updateCurrentBadge,
        updateDownloadProgress: updateDownloadProgress,
        hideDownloadModal: hideDownloadModal
    };
})();

// 导出到全局
if (typeof window !== 'undefined') {
    window.ConfigUIRenderer = ConfigUIRenderer;
}
