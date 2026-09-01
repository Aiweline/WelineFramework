(function () {
    'use strict';

    var configEl = document.getElementById('promotion-theme-product-config');
    var pageRoot = document.querySelector('[data-testid="promotion-theme-form"]');
    // 不依赖 <form>：w:form 偶发结构变化时仍要能刷新预览；优先 form，否则用整页根节点。
    var form = (pageRoot && pageRoot.querySelector('form')) || pageRoot || document;
    var previewRoot = document.getElementById('promotion-theme-filter-preview-results');
    var previewBtn = document.getElementById('promotion-theme-filter-preview-btn');

    if (!configEl || !previewRoot) {
        return;
    }

    var config = {};
    try {
        config = JSON.parse(configEl.textContent || '{}') || {};
    } catch (error) {
        config = {};
    }

    config.searchUrl = String(configEl.getAttribute('data-search-url') || config.searchUrl || '').trim();
    config.previewUrl = String(configEl.getAttribute('data-preview-url') || config.previewUrl || config.searchUrl || '').trim();
    config.pageSize = Math.max(1, Math.min(48, parseInt(String(config.pageSize || 10), 10) || 10));

    var labels = Object.assign({
        empty: '当前筛选条件下暂无命中商品，请调整规则或检查发布状态。',
        scopeRequired: '活动主题必须指定 Website（一站一活动；0 为默认网站）。',
        previewFailed: '预览请求失败，请刷新页面后重试。',
        loading: '正在加载命中商品…',
        previewSummary: '共 {{total}} 条命中（前台最多展示 {{limit}} 条）',
        pageInfo: '第 {{page}} / {{page_count}} 页',
        prevPage: '上一页',
        nextPage: '下一页',
        image: '图片',
        id: 'ID',
        website: '网站',
        sku: 'SKU',
        name: '名称',
        status: '状态',
    }, config.labels || {});

    var pickModeSelect = document.getElementById('promotion-theme-pick-mode');
    var filterPanel = document.getElementById('promotion-theme-filter-panel');
    var manualPanel = document.getElementById('promotion-theme-manual-panel');
    var productControls = document.querySelector('[data-testid="promotion-theme-product-controls"]');
    var debounceTimer = null;
    var previewRequestId = 0;
    var currentPage = 1;

    function toast(tone, message) {
        var text = String(message || '').trim();
        if (!text) {
            return;
        }
        try {
            if (window.Weline && window.Weline.UI && window.Weline.UI.toast && typeof window.Weline.UI.toast[tone] === 'function') {
                window.Weline.UI.toast[tone](text);
                return;
            }
        } catch (error) {
            // fall through
        }
        if (window.console && typeof window.console.warn === 'function') {
            window.console.warn('[promotion-theme-preview]', tone, text);
        }
    }

    function currentPickMode() {
        return pickModeSelect ? String(pickModeSelect.value || 'manual') : String(config.pickMode || 'manual');
    }

    function readNamedValue(name) {
        var input = form.querySelector ? form.querySelector('[name="' + name + '"]') : null;
        if (!input) {
            input = document.querySelector('[name="' + name + '"]');
        }
        return input ? String(input.value || '').trim() : '';
    }

    function scopePayload() {
        var websiteRaw = readNamedValue('website_id');

        return {
            // website_id=0 是「默认网站」，合法；仅未选时为空串。
            website_raw: websiteRaw,
            website_id: websiteRaw === '' ? NaN : parseInt(websiteRaw, 10),
            store_code: readNamedValue('store_code'),
            channel_code: readNamedValue('channel_code'),
        };
    }

    function filterPayload() {
        var payload = {};
        [
            'filter_product_type',
            'filter_status',
            'filter_name',
            'filter_sku',
            'filter_product_code',
            'filter_new_within_days',
            'filter_limit',
        ].forEach(function (name) {
            var value = readNamedValue(name);
            if (value !== '' || name === 'filter_new_within_days' || name === 'filter_limit') {
                payload[name] = value;
            }
        });

        return payload;
    }

    function csrfFields() {
        var fields = {};
        ['form_key', 'csrf_token', '_token'].forEach(function (name) {
            var value = readNamedValue(name);
            if (value !== '') {
                fields[name] = value;
            }
        });
        if (!fields.csrf_token && window.site && window.site.csrf_token) {
            fields.csrf_token = String(window.site.csrf_token);
        }
        return fields;
    }

    function escapeHtml(value) {
        return String(value || '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function fillTemplate(template, vars) {
        var text = String(template || '');
        Object.keys(vars || {}).forEach(function (key) {
            text = text.split('{{' + key + '}}').join(String(vars[key]));
            // 兼容旧 %{n}（若未被 __() 吃掉）
            text = text.split('%{' + key + '}').join(String(vars[key]));
        });
        // 兼容旧序号占位
        if (vars.total !== undefined) {
            text = text.split('%{1}').join(String(vars.total));
        }
        if (vars.limit !== undefined) {
            text = text.split('%{2}').join(String(vars.limit));
        }
        return text;
    }

    function formatSummary(total, limit) {
        var t = Number(total);
        var l = Number(limit);
        if (!Number.isFinite(t)) {
            t = 0;
        }
        if (!Number.isFinite(l)) {
            l = 12;
        }
        return fillTemplate(labels.previewSummary || '共 {{total}} 条命中（前台最多展示 {{limit}} 条）', {
            total: t,
            limit: l,
        });
    }

    function setStatus(message, tone) {
        var safeTone = tone || 'muted';
        var text = String(message || '').trim() || labels.empty;
        previewRoot.innerHTML = '<p class="w-text promotion-theme-filter-preview__status" data-tone="'
            + escapeHtml(safeTone) + '">' + escapeHtml(text) + '</p>';
    }

    function renderPager(meta) {
        var page = Math.max(1, parseInt(String(meta.page || 1), 10) || 1);
        var pageCount = Math.max(1, parseInt(String(meta.page_count || 1), 10) || 1);
        if (pageCount <= 1) {
            return '';
        }

        var info = fillTemplate(labels.pageInfo || '第 {{page}} / {{page_count}} 页', {
            page: page,
            page_count: pageCount,
        });
        var html = '<div class="promotion-theme-filter-preview__pager w-cluster" data-align="center" data-justify="between" data-gap="sm" data-testid="promotion-theme-filter-preview-pager">';
        html += '<button type="button" class="w-button" data-tone="neutral" data-size="sm" data-preview-page="'
            + (page - 1) + '"' + (page <= 1 ? ' disabled' : '') + '>'
            + escapeHtml(labels.prevPage || '上一页') + '</button>';
        html += '<span class="w-text promotion-theme-filter-preview__page-info" data-tone="muted">'
            + escapeHtml(info) + '</span>';
        html += '<button type="button" class="w-button" data-tone="neutral" data-size="sm" data-preview-page="'
            + (page + 1) + '"' + (page >= pageCount ? ' disabled' : '') + '>'
            + escapeHtml(labels.nextPage || '下一页') + '</button>';
        html += '</div>';
        return html;
    }

    function renderFilterPreview(items, meta) {
        meta = meta || {};
        if (meta.message && (!items || items.length === 0)) {
            setStatus(meta.message, meta.tone || 'warning');
            return;
        }

        if (!items || items.length === 0) {
            setStatus(labels.empty, 'muted');
            return;
        }

        var total = typeof meta.total === 'number' ? meta.total : items.length;
        var limit = typeof meta.limit === 'number' ? meta.limit : parseInt(filterPayload().filter_limit || '12', 10) || 12;
        var page = typeof meta.page === 'number' ? meta.page : currentPage;
        var pageCount = typeof meta.page_count === 'number' ? meta.page_count : 1;
        currentPage = page;

        var html = '<p class="w-text promotion-theme-filter-preview__status" data-tone="muted">'
            + escapeHtml(formatSummary(total, limit)) + '</p>';
        html += '<div class="promotion-theme-filter-preview__table-wrap"><table class="w-table promotion-theme-filter-preview__table" data-testid="promotion-theme-filter-preview-table"><thead><tr>';
        html += '<th>' + escapeHtml(labels.image || 'Image') + '</th>';
        html += '<th>' + escapeHtml(labels.id || 'ID') + '</th>';
        html += '<th>' + escapeHtml(labels.website || 'Website') + '</th>';
        html += '<th>' + escapeHtml(labels.sku || 'SKU') + '</th>';
        html += '<th>' + escapeHtml(labels.name || 'Name') + '</th>';
        html += '<th>' + escapeHtml(labels.status || 'Status') + '</th>';
        html += '</tr></thead><tbody>';

        items.forEach(function (item) {
            var imageUrl = String(item.image_url || '').trim();
            html += '<tr>';
            html += '<td class="promotion-theme-filter-preview__thumb-cell">';
            if (imageUrl) {
                html += '<img class="promotion-theme-filter-preview__thumb" src="' + escapeHtml(imageUrl)
                    + '" alt="' + escapeHtml(item.name || item.sku || '') + '" loading="lazy" width="48" height="48">';
            } else {
                html += '<span class="promotion-theme-filter-preview__thumb is-empty" aria-hidden="true"></span>';
            }
            html += '</td>';
            html += '<td>' + parseInt(item.product_id, 10) + '</td>';
            var websiteText = String(item.website_label || '').trim();
            if (!websiteText) {
                var wid = parseInt(item.website_id, 10);
                websiteText = Number.isFinite(wid) ? (wid === 0 ? '默认网站' : ('W#' + String(wid))) : '';
            }
            html += '<td>' + escapeHtml(websiteText) + '</td>';
            html += '<td>' + escapeHtml(item.sku || '') + '</td>';
            html += '<td>' + escapeHtml(item.name || '') + '</td>';
            html += '<td>' + escapeHtml(item.status || '') + '</td>';
            html += '</tr>';
        });

        html += '</tbody></table></div>';
        html += renderPager({ page: page, page_count: pageCount });
        previewRoot.innerHTML = html;
    }

    function requestHeaders() {
        var headers = {
            'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
            'X-Requested-With': 'XMLHttpRequest',
            'Accept': 'application/json',
        };
        if (window.site && window.site.csrf_token) {
            headers['X-CSRF-TOKEN'] = String(window.site.csrf_token);
        }

        return headers;
    }

    function failPreview(message, tone) {
        var safeTone = tone || 'danger';
        var text = String(message || labels.previewFailed || labels.empty || '').trim() || labels.previewFailed;
        setStatus(text, safeTone);
        toast(safeTone === 'warning' ? 'warning' : 'error', text);
    }

    function previewFilterProducts(page) {
        if (currentPickMode() !== 'filter') {
            return;
        }

        if (!config.previewUrl) {
            failPreview(labels.previewFailed);
            return;
        }

        var payload = scopePayload();
        // 0=默认网站（可有商品）；仅未选择 Website 时拦截。
        if (payload.website_raw === '' || !Number.isFinite(payload.website_id) || payload.website_id < 0) {
            failPreview(labels.scopeRequired, 'warning');
            return;
        }

        var targetPage = page === undefined || page === null ? currentPage : parseInt(String(page), 10);
        if (!Number.isFinite(targetPage) || targetPage < 1) {
            targetPage = 1;
        }
        currentPage = targetPage;

        Object.assign(payload, filterPayload(), csrfFields(), {
            page: String(currentPage),
            page_size: String(config.pageSize),
        });
        delete payload.website_raw;
        var requestId = ++previewRequestId;
        setStatus(labels.loading, 'muted');
        if (previewBtn) {
            previewBtn.disabled = true;
        }

        fetch(config.previewUrl, {
            method: 'POST',
            headers: requestHeaders(),
            credentials: 'same-origin',
            body: new URLSearchParams(payload).toString(),
        }).then(function (response) {
            return response.text().then(function (body) {
                var data = null;
                try {
                    data = body ? JSON.parse(body) : null;
                } catch (error) {
                    var snippet = String(body || '').replace(/\s+/g, ' ').slice(0, 160);
                    throw new Error(snippet || labels.previewFailed);
                }
                if (!response.ok) {
                    throw new Error((data && data.message) ? data.message : (labels.previewFailed + ' (HTTP ' + response.status + ')'));
                }
                return data;
            });
        }).then(function (data) {
            if (requestId !== previewRequestId) {
                return;
            }
            if (!data || data.success === false) {
                failPreview(data && data.message ? data.message : labels.empty);
                return;
            }

            renderFilterPreview(data.items || [], {
                total: typeof data.total === 'number' ? data.total : (data.items || []).length,
                limit: typeof data.limit === 'number' ? data.limit : parseInt(payload.filter_limit || '12', 10) || 12,
                page: typeof data.page === 'number' ? data.page : currentPage,
                page_count: typeof data.page_count === 'number' ? data.page_count : 1,
                page_size: typeof data.page_size === 'number' ? data.page_size : config.pageSize,
            });
            if (!(data.items || []).length) {
                toast('warning', labels.empty);
            }
        }).catch(function (error) {
            if (requestId !== previewRequestId) {
                return;
            }
            failPreview(error && error.message ? error.message : labels.previewFailed);
        }).finally(function () {
            if (requestId === previewRequestId && previewBtn) {
                previewBtn.disabled = false;
            }
        });
    }

    function schedulePreview() {
        if (currentPickMode() !== 'filter') {
            return;
        }
        clearTimeout(debounceTimer);
        debounceTimer = setTimeout(function () {
            currentPage = 1;
            previewFilterProducts(1);
        }, 450);
    }

    function syncPickModeUi() {
        var mode = currentPickMode();
        if (filterPanel) {
            filterPanel.hidden = mode !== 'filter';
        }
        if (manualPanel) {
            manualPanel.hidden = mode === 'filter';
        }
        if (productControls) {
            productControls.classList.toggle('is-manual', mode !== 'filter');
        }
        if (mode === 'filter') {
            currentPage = 1;
            previewFilterProducts(1);
        }
    }

    if (pickModeSelect) {
        pickModeSelect.addEventListener('change', syncPickModeUi);
    }
    if (previewBtn) {
        previewBtn.addEventListener('click', function (event) {
            event.preventDefault();
            event.stopPropagation();
            currentPage = 1;
            previewFilterProducts(1);
        });
    }

    previewRoot.addEventListener('click', function (event) {
        var target = event.target;
        if (!target || !target.closest) {
            return;
        }
        var btn = target.closest('[data-preview-page]');
        if (!btn || btn.disabled) {
            return;
        }
        event.preventDefault();
        var next = parseInt(String(btn.getAttribute('data-preview-page') || '1'), 10);
        if (!Number.isFinite(next) || next < 1) {
            return;
        }
        previewFilterProducts(next);
    });

    [
        'filter_product_type',
        'filter_status',
        'filter_name',
        'filter_sku',
        'filter_product_code',
        'filter_new_within_days',
        'filter_limit',
        'website_id',
        'store_code',
        'channel_code',
    ].forEach(function (name) {
        var input = form.querySelector ? form.querySelector('[name="' + name + '"]') : null;
        if (!input) {
            input = document.querySelector('[name="' + name + '"]');
        }
        if (!input) {
            return;
        }
        input.addEventListener('change', schedulePreview);
        if (input.type === 'text' || input.type === 'search' || input.type === 'number') {
            input.addEventListener('input', schedulePreview);
        }
    });

    if (config.initialPreview && currentPickMode() === 'filter') {
        if (config.initialPreview.success === false && config.initialPreview.message) {
            setStatus(config.initialPreview.message, 'warning');
        } else if (config.initialPreview.items && config.initialPreview.items.length) {
            renderFilterPreview(config.initialPreview.items || [], config.initialPreview);
        }
    }

    syncPickModeUi();
})();
