(function () {
    'use strict';

    function escapeHtml(value) {
        return String(value || '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function toast(tone, message) {
        var text = String(message || '');
        if (window.Weline && window.Weline.UI && window.Weline.UI.toast && typeof window.Weline.UI.toast[tone] === 'function') {
            window.Weline.UI.toast[tone](text);
            return;
        }
    }

    function apiResource() {
        if (!window.Weline || !window.Weline.Api || typeof window.Weline.Api.resource !== 'function') {
            return Promise.reject(new Error('商品后台 API 尚未加载，请刷新页面后重试'));
        }
        return Promise.resolve(window.Weline.Api.resource('product_admin'));
    }

    function businessResult(value) {
        var current = value;
        for (var depth = 0; depth < 3; depth += 1) {
            if (!current || typeof current !== 'object' || !current.data || typeof current.data !== 'object') {
                break;
            }
            if (Object.prototype.hasOwnProperty.call(current, 'error_code')
                || Object.prototype.hasOwnProperty.call(current, 'items')
                || Object.prototype.hasOwnProperty.call(current, 'success')) {
                break;
            }
            current = current.data;
        }
        return current || {};
    }

    function searchProducts(websiteId, keyword, limit, locale) {
        var filters = { status: 'published' };
        var kw = String(keyword || '').trim();
        if (kw !== '') {
            // Single box: match name OR sku OR product_code (server keyword filter).
            // Do not set name+sku together — those are AND and hide name-only hits.
            filters.keyword = kw;
        }
        var localeCode = String(locale || '').trim();
        if (localeCode !== '') {
            filters.locale = localeCode;
            filters.locale_code = localeCode;
        }
        return apiResource().then(function (resource) {
            if (!resource || typeof resource.search !== 'function') {
                throw new Error('商品后台 API 不支持 search');
            }
            return resource.search({
                website_id: websiteId,
                filters: filters
            }, { keepBusinessResult: true, silent: true });
        }).then(businessResult);
    }

    function currencySymbol(currency) {
        var code = String(currency || '').toUpperCase();
        if (code === 'CNY' || code === 'RMB' || code === 'JPY') {
            return '¥';
        }
        if (code === 'USD') {
            return '$';
        }
        if (code === 'EUR') {
            return '€';
        }
        if (code === 'GBP') {
            return '£';
        }
        return code ? (code + ' ') : '';
    }

    function extractPrice(row) {
        var existing = String(row.price_label || row.price_display || row.price || '').trim();
        if (existing !== '' && !Array.isArray(row.prices)) {
            return {
                price_label: existing,
                currency: String(row.currency || '').trim(),
                amount_minor: row.amount_minor != null ? parseInt(row.amount_minor, 10) : null
            };
        }
        var prices = Array.isArray(row.prices) ? row.prices : [];
        var preferred = ['CNY', 'USD', 'EUR', 'GBP'];
        var pick = null;
        var i;
        for (i = 0; i < preferred.length; i += 1) {
            for (var j = 0; j < prices.length; j += 1) {
                var candidate = prices[j];
                if (!candidate || candidate.cleared) {
                    continue;
                }
                if (String(candidate.currency || '').toUpperCase() !== preferred[i]) {
                    continue;
                }
                if (candidate.amount_minor === null || candidate.amount_minor === undefined || candidate.amount_minor === '') {
                    continue;
                }
                pick = candidate;
                break;
            }
            if (pick) {
                break;
            }
        }
        if (!pick) {
            for (i = 0; i < prices.length; i += 1) {
                if (prices[i] && !prices[i].cleared && prices[i].amount_minor !== null && prices[i].amount_minor !== undefined && prices[i].amount_minor !== '') {
                    pick = prices[i];
                    break;
                }
            }
        }
        if (!pick) {
            return { price_label: '', currency: '', amount_minor: null };
        }
        var minor = parseInt(pick.amount_minor, 10);
        if (!Number.isFinite(minor)) {
            return { price_label: '', currency: '', amount_minor: null };
        }
        var currency = String(pick.currency || 'CNY').toUpperCase();
        var major = (minor / 100).toFixed(2);
        return {
            price_label: currencySymbol(currency) + major,
            currency: currency,
            amount_minor: minor
        };
    }

    function normalizeRow(row) {
        var skus = Array.isArray(row.skus) ? row.skus : [];
        var media = row.main_media && typeof row.main_media === 'object' ? row.main_media : null;
        var image = '';
        if (media) {
            image = String(media.preview_url || media.display_url || media.url || '').trim();
        }
        if (!image) {
            image = String(row.image || row.image_url || row.thumbnail || '').trim();
        }
        var price = extractPrice(row);
        return {
            product_id: parseInt(row.product_id, 10) || 0,
            website_id: parseInt(row.website_id, 10) || 0,
            website_label: String(row.website_label || '').trim(),
            name: String(row.name || '').trim(),
            sku: String(row.sku || skus[0] || '').trim(),
            image: image,
            price_label: price.price_label,
            currency: price.currency,
            amount_minor: price.amount_minor,
            status: String(row.status || ''),
            product_type: String(row.product_type || '')
        };
    }

    function selectionKey(row) {
        var websiteId = parseInt(row.website_id, 10) || 0;
        var productId = parseInt(row.product_id, 10) || 0;
        return String(websiteId) + ':' + String(productId);
    }

    function initPicker(root) {
        if (!(root instanceof HTMLElement) || root.getAttribute('data-product-admin-picker-ready') === '1') {
            return;
        }
        root.setAttribute('data-product-admin-picker-ready', '1');

        var form = root.closest('form') || document.querySelector('[data-testid="promotion-theme-form"]') || document;
        if (!form) {
            return;
        }

        var labels = {};
        try {
            labels = JSON.parse(root.getAttribute('data-labels') || '{}');
        } catch (error) {
            labels = {};
        }

        var websiteField = root.getAttribute('data-website-field') || 'website_id';
        var storeField = root.getAttribute('data-store-field') || 'store_code';
        var channelField = root.getAttribute('data-channel-field') || 'channel_code';
        var hiddenName = root.getAttribute('data-name') || 'product_ids[]';
        var syncInputId = String(root.getAttribute('data-sync-input') || '').trim();
        var syncInput = syncInputId ? document.getElementById(syncInputId) : null;
        var useNamedHiddens = hiddenName !== '';
        var websiteHiddenName = 'product_website_ids[]';
        var limit = parseInt(root.getAttribute('data-limit') || '20', 10) || 20;
        var searchUrl = String(root.getAttribute('data-search-url') || '').trim();
        var crossWebsite = root.getAttribute('data-cross-website') === '1' || searchUrl !== '';
        var defaultWebsiteId = parseInt(root.getAttribute('data-default-website-id') || '0', 10);
        if (!Number.isFinite(defaultWebsiteId) || defaultWebsiteId < 0) {
            defaultWebsiteId = 0;
        }
        var selected = {};
        var resultsRoot = root.querySelector('[data-product-admin-picker-results]');
        var selectedRoot = root.querySelector('[data-product-admin-picker-selected]');
        var keywordInput = root.querySelector('[data-product-admin-picker-keyword]');
        var searchButton = root.querySelector('[data-product-admin-picker-search]');
        var dialog = root.querySelector('[data-product-admin-picker-dialog]');

        function uiApi() {
            return window.Weline && window.Weline.UI ? window.Weline.UI : null;
        }

        function ensureDialogMounted() {
            var ui = uiApi();
            if (!dialog || !(dialog instanceof HTMLElement) || !ui) {
                return;
            }
            if (typeof ui.mount === 'function') {
                ui.mount(dialog);
            }
        }

        function openDialog() {
            ensureDialogMounted();
            var ui = uiApi();
            if (dialog && ui && ui.dialog && typeof ui.dialog.open === 'function') {
                ui.dialog.open(dialog);
                // 打开即拉取已发布商品，避免空白床
                renderResults([], labels.loading || '加载中…');
                runSearch();
                if (keywordInput && typeof keywordInput.focus === 'function') {
                    window.setTimeout(function () {
                        try {
                            keywordInput.focus({ preventScroll: true });
                        } catch (error) {
                            keywordInput.focus();
                        }
                    }, 0);
                }
                return;
            }
            toast('error', '主题 Dialog 不可用，请刷新后重试');
        }

        function closeDialog(reason) {
            var ui = uiApi();
            if (dialog && ui && ui.dialog && typeof ui.dialog.close === 'function') {
                ui.dialog.close(dialog, reason || 'done');
            }
        }

        ensureDialogMounted();

        try {
            var initialRows = JSON.parse(root.getAttribute('data-selected') || '[]');
            var initialWebsiteIds = [];
            if (Array.isArray(initialRows) && initialRows.website_ids) {
                initialWebsiteIds = initialRows.website_ids;
            }
            (Array.isArray(initialRows) ? initialRows : []).forEach(function (item, index) {
                var row = normalizeRow(item);
                if (row.product_id <= 0) {
                    return;
                }
                if (row.website_id <= 0 && initialWebsiteIds[index] !== undefined) {
                    row.website_id = parseInt(initialWebsiteIds[index], 10) || 0;
                }
                selected[selectionKey(row)] = row;
            });
        } catch (error) {
            selected = {};
        }

        function currentMode() {
            var modeField = root.getAttribute('data-mode-field') || '';
            if (modeField) {
                var modeInput = form.querySelector('[name="' + modeField + '"]');
                var pickMode = modeInput ? String(modeInput.value || 'manual') : 'manual';
                return pickMode === 'single' ? 'single' : 'multiple';
            }
            return String(root.getAttribute('data-mode') || 'multiple') === 'single' ? 'single' : 'multiple';
        }

        function scopeWebsiteId() {
            var input = form.querySelector && websiteField
                ? form.querySelector('[name="' + websiteField + '"]')
                : null;
            if (!input) {
                return crossWebsite ? defaultWebsiteId : NaN;
            }
            var raw = String(input.value || '').trim();
            if (raw === '') {
                return crossWebsite ? defaultWebsiteId : NaN;
            }
            return parseInt(raw, 10);
        }

        function syncCommaValue() {
            if (!(syncInput instanceof HTMLInputElement || syncInput instanceof HTMLTextAreaElement)) {
                return;
            }
            var ids = Object.keys(selected).map(function (key) {
                return selected[key].product_id;
            }).filter(function (id) {
                return id > 0;
            });
            var next = ids.join(',');
            if (String(syncInput.value || '') === next) {
                return;
            }
            syncInput.value = next;
            try {
                syncInput.dispatchEvent(new Event('input', { bubbles: true }));
                syncInput.dispatchEvent(new Event('change', { bubbles: true }));
            } catch (error) {
                // ignore
            }
            if (window.Weline && window.Weline.Widget && window.Weline.Widget.Params
                && typeof window.Weline.Widget.Params.emitValueChange === 'function') {
                window.Weline.Widget.Params.emitValueChange(syncInput, { source: 'product-admin-picker' });
            }
        }

        function scopePayload() {
            var storeInput = form.querySelector('[name="' + storeField + '"]');
            var channelInput = form.querySelector('[name="' + channelField + '"]');
            return {
                website_id: scopeWebsiteId(),
                store_code: storeInput ? String(storeInput.value || '').trim() : '',
                channel_code: channelInput ? String(channelInput.value || '').trim() : '',
                keyword: keywordInput ? String(keywordInput.value || '').trim() : '',
                locale: resolvePickerLocale(),
                limit: limit
            };
        }

        function resolvePickerLocale() {
            var themeEl = document.getElementById('themeEditor');
            if (themeEl) {
                var fromDataset = String(
                    themeEl.getAttribute('data-config-locale')
                    || themeEl.dataset.configLocale
                    || themeEl.getAttribute('data-locale-code')
                    || themeEl.dataset.localeCode
                    || ''
                ).trim();
                if (fromDataset !== '') {
                    return fromDataset;
                }
            }
            if (window.Weline && window.Weline.Theme && window.Weline.Theme.Editor
                && window.Weline.Theme.Editor.state
                && window.Weline.Theme.Editor.state.configLocale) {
                return String(window.Weline.Theme.Editor.state.configLocale || '').trim();
            }
            return String(document.documentElement.lang || '').trim();
        }

        function thumbHtml(item, alt) {
            var src = String(item.image || '').trim();
            if (!src) {
                return '<span class="w-product-admin-picker__thumb w-product-admin-picker__thumb--empty" aria-hidden="true"></span>';
            }
            return '<img class="w-product-admin-picker__thumb" src="' + escapeHtml(src) + '" alt="'
                + escapeHtml(alt || item.name || '') + '" loading="lazy" width="48" height="48">';
        }

        function formatSelectedLabel(item) {
            var id = parseInt(item.product_id, 10) || 0;
            var name = String(item.name || '').trim();
            var sku = String(item.sku || '').trim();
            var websitePrefix = item.website_label
                ? ('[' + item.website_label + '] ')
                : ((parseInt(item.website_id, 10) || 0) > 0 ? ('W#' + item.website_id + ' · ') : '');
            var isPlaceholder = name === '' || name === ('#' + id);
            var title = isPlaceholder ? (sku || ('#' + id)) : name;
            var metaParts = ['#' + id];
            if (sku && sku !== title) {
                metaParts.push(sku);
            }
            return thumbHtml(item, title)
                + '<span class="w-product-admin-picker__item-main">'
                + '<span class="w-product-admin-picker__item-name">' + escapeHtml(websitePrefix + title) + '</span>'
                + '<span class="w-product-admin-picker__item-meta w-text" data-tone="muted">' + escapeHtml(metaParts.join(' · ')) + '</span>'
                + (item.price_label
                    ? ('<span class="w-product-admin-picker__item-price">' + escapeHtml(String(item.price_label)) + '</span>')
                    : '')
                + '</span>';
        }

        function renderSelected() {
            if (!selectedRoot) {
                return;
            }
            var items = Object.keys(selected).map(function (key) {
                return selected[key];
            });
            if (items.length === 0) {
                selectedRoot.innerHTML = '<p class="w-text" data-tone="muted">' + escapeHtml(labels.selectedTitle || 'Selected') + ' · 0</p>';
                syncCommaValue();
                return;
            }
            var html = '<div class="w-stack" data-gap="sm"><strong>' + escapeHtml(labels.selectedTitle || 'Selected') + ' (' + items.length + ')</strong><ul class="w-product-admin-picker__list">';
            items.forEach(function (item) {
                html += '<li class="w-cluster w-product-admin-picker__selected-row" data-align="center" data-justify="between">'
                    + formatSelectedLabel(item)
                    + '<button type="button" class="w-button" data-tone="neutral" data-size="sm" data-product-admin-picker-remove="' + escapeHtml(selectionKey(item)) + '">'
                    + escapeHtml(labels.remove || 'Remove') + '</button>';
                if (useNamedHiddens) {
                    html += '<input type="hidden" name="' + escapeHtml(hiddenName) + '" value="' + item.product_id + '">'
                        + '<input type="hidden" name="' + escapeHtml(websiteHiddenName) + '" value="' + (item.website_id || 0) + '">';
                }
                html += '</li>';
            });
            html += '</ul></div>';
            selectedRoot.innerHTML = html;
            syncCommaValue();
        }

        function needsLabelEnrichment(row) {
            var id = parseInt(row.product_id, 10) || 0;
            var name = String(row.name || '').trim();
            var sku = String(row.sku || '').trim();
            return id > 0 && ((name === '' || name === ('#' + id)) || sku === '' || String(row.image || '').trim() === '' || String(row.price_label || '').trim() === '');
        }

        function enrichSelectedLabels() {
            var pendingIds = Object.keys(selected).map(function (key) {
                return selected[key];
            }).filter(needsLabelEnrichment).map(function (row) {
                return row.product_id;
            });
            if (pendingIds.length === 0) {
                return;
            }
            var websiteId = scopeWebsiteId();
            if ((!Number.isFinite(websiteId) || websiteId < 0) && !crossWebsite) {
                return;
            }
            apiResource().then(function (resource) {
                if (!resource || typeof resource.search !== 'function') {
                    return null;
                }
                return resource.search({
                    website_id: Number.isFinite(websiteId) ? websiteId : defaultWebsiteId,
                    filters: (function () {
                        var filters = { product_ids: pendingIds };
                        var localeCode = resolvePickerLocale();
                        if (localeCode !== '') {
                            filters.locale = localeCode;
                            filters.locale_code = localeCode;
                        }
                        return filters;
                    })()
                }, { keepBusinessResult: true, silent: true });
            }).then(businessResult).then(function (payload) {
                var items = (payload && payload.items) ? payload.items : [];
                var changed = false;
                items.forEach(function (raw) {
                    var row = normalizeRow(raw);
                    if (row.product_id <= 0) {
                        return;
                    }
                    var key = selectionKey(row);
                    if (!selected[key] && selected['0:' + row.product_id]) {
                        key = '0:' + row.product_id;
                    }
                    if (!selected[key]) {
                        return;
                    }
                    if (row.name) {
                        selected[key].name = row.name;
                        changed = true;
                    }
                    if (row.sku) {
                        selected[key].sku = row.sku;
                        changed = true;
                    }
                    if (row.image) {
                        selected[key].image = row.image;
                        changed = true;
                    }
                    if (row.price_label) {
                        selected[key].price_label = row.price_label;
                        selected[key].currency = row.currency;
                        selected[key].amount_minor = row.amount_minor;
                        changed = true;
                    }
                    if (row.website_label) {
                        selected[key].website_label = row.website_label;
                    }
                });
                if (changed) {
                    renderSelected();
                }
            }).catch(function () {
                // keep placeholders
            });
        }

        function renderResults(items, message) {
            if (!resultsRoot) {
                return;
            }
            if (!items || items.length === 0) {
                resultsRoot.innerHTML = '<p class="w-text" data-tone="muted">' + escapeHtml(message || labels.empty || '') + '</p>';
                return;
            }
            var showWebsite = items.some(function (item) {
                return (parseInt(item.website_id, 10) || 0) > 0 || String(item.website_label || '').trim() !== '';
            });
            var html = '<table class="w-table w-product-admin-picker__table"><thead><tr>';
            html += '<th class="w-product-admin-picker__col-thumb">' + escapeHtml(labels.image || '图') + '</th>';
            if (showWebsite) {
                html += '<th>' + escapeHtml(labels.website || 'Website') + '</th>';
            }
            html += '<th>ID</th><th>SKU</th><th>' + escapeHtml(labels.name || 'Name') + '</th>'
                + '<th>' + escapeHtml(labels.price || '价格') + '</th><th></th></tr></thead><tbody>';
            items.forEach(function (item) {
                var key = selectionKey(item);
                html += '<tr>';
                html += '<td class="w-product-admin-picker__col-thumb">' + thumbHtml(item, item.name) + '</td>';
                if (showWebsite) {
                    var websiteText = String(item.website_label || '').trim();
                    if (!websiteText && (parseInt(item.website_id, 10) || 0) > 0) {
                        websiteText = 'W#' + String(item.website_id);
                    }
                    html += '<td>' + escapeHtml(websiteText) + '</td>';
                }
                html += '<td>' + item.product_id + '</td><td>' + escapeHtml(item.sku || '') + '</td>'
                    + '<td>' + escapeHtml(item.name || '') + '</td>'
                    + '<td class="w-product-admin-picker__col-price">' + escapeHtml(item.price_label || '—') + '</td><td>'
                    + '<button type="button" class="w-button" data-tone="primary" data-size="sm"'
                    + ' data-product-admin-picker-add="' + escapeHtml(key) + '"'
                    + ' data-product-id="' + item.product_id + '"'
                    + ' data-website-id="' + (item.website_id || 0) + '"'
                    + ' data-website-label="' + escapeHtml(item.website_label || '') + '"'
                    + ' data-product-name="' + escapeHtml(item.name || '') + '"'
                    + ' data-product-sku="' + escapeHtml(item.sku || '') + '"'
                    + ' data-product-image="' + escapeHtml(item.image || '') + '"'
                    + ' data-product-price="' + escapeHtml(item.price_label || '') + '"'
                    + (selected[key] ? ' disabled' : '') + '>' + escapeHtml(labels.add || 'Add') + '</button>'
                    + '</td></tr>';
            });
            html += '</tbody></table>';
            resultsRoot.innerHTML = html;
        }

        function addProduct(row) {
            var mode = currentMode();
            var normalized = normalizeRow(row);
            if (mode === 'single') {
                selected = {};
            }
            selected[selectionKey(normalized)] = normalized;
            renderSelected();
        }

        function searchViaEndpoint() {
            var payload = scopePayload();
            var headers = {
                'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                'X-Requested-With': 'XMLHttpRequest'
            };
            if (window.site && window.site.csrf_token) {
                headers['X-CSRF-TOKEN'] = String(window.site.csrf_token);
            }
            return fetch(searchUrl, {
                method: 'POST',
                headers: headers,
                credentials: 'same-origin',
                body: new URLSearchParams(payload).toString()
            }).then(function (response) {
                return response.json();
            });
        }

        function runSearch() {
            var websiteId = scopeWebsiteId();
            // website_id=0 是默认网站；仅未选择时拦截。
            if ((!Number.isFinite(websiteId) || websiteId < 0) && !crossWebsite) {
                renderResults([], labels.scopeRequired || labels.empty || '');
                toast('warning', labels.scopeRequired || '');
                return;
            }
            searchButton && (searchButton.disabled = true);
            var request = crossWebsite && searchUrl !== ''
                ? searchViaEndpoint()
                : searchProducts(websiteId, keywordInput ? keywordInput.value : '', limit, resolvePickerLocale());
            Promise.resolve(request).then(function (payload) {
                if (!payload || payload.success === false) {
                    renderResults([], payload && payload.message ? payload.message : labels.empty);
                    return;
                }
                var items = (payload.items || []).map(normalizeRow).filter(function (row) {
                    return row.product_id > 0;
                }).slice(0, limit);
                renderResults(items, labels.empty);
            }).catch(function (error) {
                renderResults([], error && error.message ? error.message : labels.empty);
                toast('error', error && error.message ? error.message : labels.empty);
            }).finally(function () {
                searchButton && (searchButton.disabled = false);
            });
        }

        root.addEventListener('click', function (event) {
            var target = event.target;
            if (!(target instanceof HTMLElement)) {
                return;
            }
            if (target.closest('[data-product-admin-picker-open]')) {
                event.preventDefault();
                openDialog();
                return;
            }
            if (target.closest('[data-product-admin-picker-done]')) {
                event.preventDefault();
                closeDialog('done');
                return;
            }
            if (target.hasAttribute('data-product-admin-picker-search') || target.closest('[data-product-admin-picker-search]')) {
                event.preventDefault();
                runSearch();
                return;
            }
            var addBtn = target.closest('[data-product-admin-picker-add]');
            if (addBtn) {
                event.preventDefault();
                addProduct({
                    product_id: parseInt(addBtn.getAttribute('data-product-id') || (addBtn.getAttribute('data-product-admin-picker-add') || '').split(':')[1], 10) || 0,
                    website_id: parseInt(addBtn.getAttribute('data-website-id') || (addBtn.getAttribute('data-product-admin-picker-add') || '').split(':')[0], 10) || 0,
                    website_label: String(addBtn.getAttribute('data-website-label') || '').trim(),
                    sku: String(addBtn.getAttribute('data-product-sku') || '').trim(),
                    name: String(addBtn.getAttribute('data-product-name') || '').trim(),
                    image: String(addBtn.getAttribute('data-product-image') || '').trim(),
                    price_label: String(addBtn.getAttribute('data-product-price') || '').trim()
                });
                if (currentMode() === 'single') {
                    resultsRoot.querySelectorAll('[data-product-admin-picker-add]').forEach(function (button) {
                        button.setAttribute('disabled', 'disabled');
                    });
                } else {
                    addBtn.setAttribute('disabled', 'disabled');
                }
                return;
            }
            var removeBtn = target.closest('[data-product-admin-picker-remove]');
            if (removeBtn) {
                event.preventDefault();
                var removeKey = removeBtn.getAttribute('data-product-admin-picker-remove');
                if (removeKey) {
                    delete selected[removeKey];
                    renderSelected();
                    var addButton = resultsRoot && resultsRoot.querySelector('[data-product-admin-picker-add="' + removeKey + '"]');
                    if (addButton) {
                        addButton.removeAttribute('disabled');
                    }
                }
            }
        });

        var modeFieldName = root.getAttribute('data-mode-field') || '';
        if (modeFieldName) {
            var modeInput = form.querySelector('[name="' + modeFieldName + '"]');
            if (modeInput) {
                modeInput.addEventListener('change', function () {
                    if (currentMode() === 'single' && Object.keys(selected).length > 1) {
                        var firstKey = Object.keys(selected)[0];
                        var next = {};
                        if (firstKey) {
                            next[firstKey] = selected[firstKey];
                        }
                        selected = next;
                        renderSelected();
                    }
                });
            }
        }

        renderSelected();
        enrichSelectedLabels();
    }

    function mount(root) {
        var scope = root && root.querySelectorAll ? root : document;
        scope.querySelectorAll('[data-product-admin-picker]').forEach(initPicker);
    }

    function boot() {
        mount(document);
    }

    window.Weline = window.Weline || {};
    window.Weline.Product = window.Weline.Product || {};
    window.Weline.Product.AdminPicker = Object.assign(window.Weline.Product.AdminPicker || {}, {
        mount: mount,
        init: mount
    });

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
