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
            tdName.textContent = modelId;

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

    return {
        renderModelList: renderModelList,
        renderModelDetail: renderModelDetail,
        updateCurrentBadge: updateCurrentBadge
    };
})();

// 导出到全局
if (typeof window !== 'undefined') {
    window.ConfigUIRenderer = ConfigUIRenderer;
}
