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

    function searchProducts(websiteId, keyword, limit) {
        var filters = { status: 'published' };
        var kw = String(keyword || '').trim();
        if (kw !== '') {
            filters.name = kw;
            filters.sku = kw;
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

    function normalizeRow(row) {
        var skus = Array.isArray(row.skus) ? row.skus : [];
        return {
            product_id: parseInt(row.product_id, 10) || 0,
            website_id: parseInt(row.website_id, 10) || 0,
            website_label: String(row.website_label || '').trim(),
            name: String(row.name || '').trim(),
            sku: String(row.sku || skus[0] || '').trim(),
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
        var websiteHiddenName = 'product_website_ids[]';
        var limit = parseInt(root.getAttribute('data-limit') || '20', 10) || 20;
        var searchUrl = String(root.getAttribute('data-search-url') || '').trim();
        var crossWebsite = root.getAttribute('data-cross-website') === '1' || searchUrl !== '';
        var selected = {};
        var resultsRoot = root.querySelector('[data-product-admin-picker-results]');
        var selectedRoot = root.querySelector('[data-product-admin-picker-selected]');
        var keywordInput = root.querySelector('[data-product-admin-picker-keyword]');
        var searchButton = root.querySelector('[data-product-admin-picker-search]');

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
            var input = form.querySelector ? form.querySelector('[name="' + websiteField + '"]') : null;
            var raw = input ? String(input.value || '').trim() : '';
            if (raw === '') {
                return NaN;
            }
            return parseInt(raw, 10);
        }

        function scopePayload() {
            var storeInput = form.querySelector('[name="' + storeField + '"]');
            var channelInput = form.querySelector('[name="' + channelField + '"]');
            return {
                website_id: scopeWebsiteId(),
                store_code: storeInput ? String(storeInput.value || '').trim() : '',
                channel_code: channelInput ? String(channelInput.value || '').trim() : '',
                keyword: keywordInput ? String(keywordInput.value || '').trim() : '',
                limit: limit
            };
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
                return;
            }
            var html = '<div class="w-stack" data-gap="sm"><strong>' + escapeHtml(labels.selectedTitle || 'Selected') + ' (' + items.length + ')</strong><ul class="w-product-admin-picker__list">';
            items.forEach(function (item) {
                var prefix = item.website_label ? ('[' + item.website_label + '] ') : (item.website_id > 0 ? ('W#' + item.website_id + ' · ') : '');
                html += '<li class="w-cluster" data-align="center" data-justify="between">'
                    + '<span>' + escapeHtml(prefix + '#' + item.product_id + ' · ' + (item.name || item.sku || '')) + '</span>'
                    + '<button type="button" class="w-button" data-tone="neutral" data-size="sm" data-product-admin-picker-remove="' + escapeHtml(selectionKey(item)) + '">'
                    + escapeHtml(labels.remove || 'Remove') + '</button>'
                    + '<input type="hidden" name="' + escapeHtml(hiddenName) + '" value="' + item.product_id + '">'
                    + '<input type="hidden" name="' + escapeHtml(websiteHiddenName) + '" value="' + (item.website_id || 0) + '">'
                    + '</li>';
            });
            html += '</ul></div>';
            selectedRoot.innerHTML = html;
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
            // Cross-website / default-website scope must always expose the website column.
            if (crossWebsite && scopeWebsiteId() <= 0) {
                showWebsite = true;
            }
            var html = '<table class="w-table"><thead><tr>';
            if (showWebsite) {
                html += '<th>' + escapeHtml(labels.website || 'Website') + '</th>';
            }
            html += '<th>ID</th><th>SKU</th><th>' + escapeHtml(labels.name || 'Name') + '</th><th></th></tr></thead><tbody>';
            items.forEach(function (item) {
                var key = selectionKey(item);
                html += '<tr>';
                if (showWebsite) {
                    var websiteText = String(item.website_label || '').trim();
                    if (!websiteText && (parseInt(item.website_id, 10) || 0) > 0) {
                        websiteText = 'W#' + String(item.website_id);
                    }
                    html += '<td>' + escapeHtml(websiteText) + '</td>';
                }
                html += '<td>' + item.product_id + '</td><td>' + escapeHtml(item.sku || '') + '</td><td>' + escapeHtml(item.name || '') + '</td><td>'
                    + '<button type="button" class="w-button" data-tone="primary" data-size="sm"'
                    + ' data-product-admin-picker-add="' + escapeHtml(key) + '"'
                    + ' data-product-id="' + item.product_id + '"'
                    + ' data-website-id="' + (item.website_id || 0) + '"'
                    + ' data-website-label="' + escapeHtml(item.website_label || '') + '"'
                    + ' data-product-name="' + escapeHtml(item.name || '') + '"'
                    + ' data-product-sku="' + escapeHtml(item.sku || '') + '"'
                    + (selected[key] ? ' disabled' : '') + '>' + escapeHtml(labels.add || 'Add') + '</button>'
                    + '</td></tr>';
            });
            html += '</tbody></table>';
            resultsRoot.innerHTML = html;
        }

        function addProduct(row) {
            var mode = currentMode();
            if (mode === 'single') {
                selected = {};
            }
            selected[selectionKey(row)] = row;
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
                : searchProducts(websiteId, keywordInput ? keywordInput.value : '', limit);
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
            if (target.hasAttribute('data-product-admin-picker-search')) {
                event.preventDefault();
                runSearch();
                return;
            }
            var addKey = target.getAttribute('data-product-admin-picker-add');
            if (addKey) {
                event.preventDefault();
                addProduct({
                    product_id: parseInt(target.getAttribute('data-product-id') || addKey.split(':')[1], 10) || 0,
                    website_id: parseInt(target.getAttribute('data-website-id') || addKey.split(':')[0], 10) || 0,
                    website_label: String(target.getAttribute('data-website-label') || '').trim(),
                    sku: String(target.getAttribute('data-product-sku') || '').trim(),
                    name: String(target.getAttribute('data-product-name') || '').trim()
                });
                if (currentMode() === 'single') {
                    resultsRoot.querySelectorAll('[data-product-admin-picker-add]').forEach(function (button) {
                        button.setAttribute('disabled', 'disabled');
                    });
                } else {
                    target.setAttribute('disabled', 'disabled');
                }
                return;
            }
            var removeKey = target.getAttribute('data-product-admin-picker-remove');
            if (removeKey) {
                event.preventDefault();
                delete selected[removeKey];
                renderSelected();
                var addButton = resultsRoot && resultsRoot.querySelector('[data-product-admin-picker-add="' + removeKey + '"]');
                if (addButton) {
                    addButton.removeAttribute('disabled');
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
    }

    function boot() {
        document.querySelectorAll('[data-product-admin-picker]').forEach(initPicker);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
