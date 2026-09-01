(function () {
    'use strict';

    function pageRoot() {
        return document.querySelector('[data-compare-page]');
    }

    function labels() {
        var node = document.getElementById('weline-compare-page-labels');
        if (!node) {
            return {};
        }
        try {
            return JSON.parse(node.textContent || '{}') || {};
        } catch (error) {
            return {};
        }
    }

    function escapeHtml(value) {
        return String(value == null ? '' : value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function formatMeta(count, max) {
        var text = labels().meta || '已选 %{1} / %{2} 件';
        return text.replace('%{1}', String(count)).replace('%{2}', String(max));
    }

    function setPanelState(state) {
        var root = pageRoot();
        if (!root) {
            return;
        }
        var loading = root.querySelector('[data-compare-page-loading]');
        var empty = root.querySelector('[data-compare-page-empty]');
        var content = root.querySelector('[data-compare-page-content]');
        if (loading) {
            loading.hidden = state !== 'loading';
        }
        if (empty) {
            empty.hidden = state !== 'empty';
        }
        if (content) {
            content.hidden = state !== 'content';
        }
    }

    function updateMeta(count, max) {
        var root = pageRoot();
        if (!root) {
            return;
        }
        var meta = root.querySelector('[data-compare-page-meta]');
        if (meta) {
            meta.textContent = formatMeta(count, max);
        }
    }

    function buildTableHtml(view) {
        var items = Array.isArray(view.items) ? view.items : [];
        if (items.length === 0) {
            return '';
        }

        var specRows = Array.isArray(view.specRows) ? view.specRows : [];
        var ratingLabelClass = view.ratingLabelClass || '';
        var ratingCellClasses = Array.isArray(view.ratingCellClasses) ? view.ratingCellClasses : [];
        var priceCellClasses = Array.isArray(view.priceCellClasses) ? view.priceCellClasses : [];
        var i18n = labels();

        var html = ''
            + '<div class="storefront-compare__table-wrap" style="--compare-product-cols: ' + items.length + '">'
            + '<table class="storefront-compare__table"><thead><tr>'
            + '<th scope="col">' + escapeHtml(i18n.attr || '属性') + '</th>';

        items.forEach(function (item) {
            html += '<th scope="col"><a href="' + escapeHtml(item.url || '#') + '">' + escapeHtml(item.name || '') + '</a></th>';
        });

        html += '</tr></thead><tbody>';

        html += '<tr><th scope="row">' + escapeHtml(i18n.image || '图片') + '</th>';
        items.forEach(function (item) {
            html += '<td>';
            if (item.image) {
                html += '<img src="' + escapeHtml(item.image) + '" alt="" class="storefront-compare__thumb" loading="lazy">';
            }
            html += '</td>';
        });
        html += '</tr>';

        html += '<tr class="storefront-compare__row"><th scope="row">' + escapeHtml(i18n.price || '价格') + '</th>';
        items.forEach(function (item, index) {
            html += '<td class="' + escapeHtml(priceCellClasses[index] || '') + '">' + escapeHtml(item.formatted_price || '') + '</td>';
        });
        html += '</tr>';

        html += '<tr class="storefront-compare__row"><th scope="row" class="' + escapeHtml(ratingLabelClass) + '">' + escapeHtml(i18n.rating || '评分') + '</th>';
        items.forEach(function (item, index) {
            var ratingText = Number(item.rating || 0).toFixed(1) + ' (' + Number(item.review_count || 0) + ')';
            html += '<td class="' + escapeHtml(ratingCellClasses[index] || '') + '">' + escapeHtml(ratingText) + '</td>';
        });
        html += '</tr>';

        html += '<tr class="storefront-compare__row"><th scope="row">' + escapeHtml(i18n.sku || 'SKU') + '</th>';
        items.forEach(function (item) {
            html += '<td>' + escapeHtml(item.sku || '—') + '</td>';
        });
        html += '</tr>';

        if (specRows.length > 0) {
            html += '<tr class="storefront-compare__section"><th scope="row" colspan="' + (items.length + 1) + '">' + escapeHtml(i18n.specs || '规格参数') + '</th></tr>';
            specRows.forEach(function (specRow) {
                var values = Array.isArray(specRow.values) ? specRow.values : [];
                var cellClasses = Array.isArray(specRow.cell_classes) ? specRow.cell_classes : [];
                html += '<tr class="storefront-compare__row"><th scope="row" class="' + escapeHtml(specRow.label_class || '') + '">' + escapeHtml(specRow.label || '') + '</th>';
                values.forEach(function (value, index) {
                    var display = String(value || '').trim() !== '' ? value : '—';
                    html += '<td class="' + escapeHtml(cellClasses[index] || '') + '">' + escapeHtml(display) + '</td>';
                });
                html += '</tr>';
            });
        }

        html += '<tr><th scope="row">' + escapeHtml(i18n.action || '操作') + '</th>';
        items.forEach(function (item) {
            html += '<td><button type="button" class="storefront-compare__remove" data-compare-remove data-product-id="' + Number(item.product_id || 0) + '">' + escapeHtml(i18n.remove || '移除') + '</button></td>';
        });
        html += '</tr></tbody></table></div>';

        return html;
    }

    function renderFromPayload(data) {
        var root = pageRoot();
        if (!root || !data || typeof data !== 'object') {
            return;
        }

        var items = Array.isArray(data.items) ? data.items : [];
        var count = Number(data.compare_count != null ? data.compare_count : items.length);
        var max = Number(data.max || root.getAttribute('data-compare-max') || 8);
        var content = root.querySelector('[data-compare-page-content]');

        updateMeta(count, max);

        if (items.length === 0) {
            if (content) {
                content.innerHTML = '';
            }
            setPanelState('empty');
            return;
        }

        if (content) {
            content.innerHTML = buildTableHtml(data);
        }
        setPanelState('content');
    }

    function handleLoadError() {
        if (!pageRoot()) {
            return;
        }
        setPanelState('empty');
        if (window.Weline && window.Weline.UI && window.Weline.UI.toast && typeof window.Weline.UI.toast.show === 'function') {
            window.Weline.UI.toast.show(labels().failed || '加载对比数据失败，请稍后重试', { tone: 'error' });
        }
    }

    window.WelineComparePage = {
        renderFromPayload: renderFromPayload,
        handleLoadError: handleLoadError,
        refresh: function () {
            if (window.WelineCompareShopper && typeof window.WelineCompareShopper.refreshCompareState === 'function') {
                return window.WelineCompareShopper.refreshCompareState();
            }
            return Promise.resolve();
        },
        isActive: function () {
            return !!pageRoot();
        }
    };

    if (pageRoot()) {
        setPanelState('loading');
    }
})();
