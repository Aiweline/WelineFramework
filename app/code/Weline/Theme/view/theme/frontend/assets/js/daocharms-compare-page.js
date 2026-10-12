/**
 * DaoCharms compare page (design_theme · UI 08).
 * Overrides default table hydrate with dual-column card grid.
 * Hydrate still owned by WelineCompareShopper; this only reshapes renderFromPayload.
 */
(function () {
    'use strict';

    var patched = false;

    function labels() {
        var node = document.getElementById('weline-compare-page-labels');
        if (!node) {
            return {};
        }
        try {
            return JSON.parse(node.textContent || '{}') || {};
        } catch (e) {
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

    function moneyDisplay(item) {
        var raw = String(item.formatted_price || '').trim();
        if (/^\$/.test(raw)) {
            return raw;
        }
        var n = Number(item.price);
        if (!isNaN(n) && n > 0) {
            return '$' + n.toFixed(2);
        }
        return raw.replace(/^USD\s+/i, '$');
    }

    function specMap(item) {
        var map = {};
        var specs = Array.isArray(item.specifications) ? item.specifications : [];
        specs.forEach(function (spec) {
            if (!spec || typeof spec !== 'object') {
                return;
            }
            var code = String(spec.code || '').toLowerCase();
            var value = String(spec.value || '').trim();
            if (code && value) {
                map[code] = value;
            }
        });
        var attrs = Array.isArray(item.attributes) ? item.attributes : [];
        attrs.forEach(function (attr) {
            if (!attr || typeof attr !== 'object') {
                return;
            }
            var code = String(attr.code || attr.key || '').toLowerCase();
            var value = String(attr.value || attr.label || '').trim();
            if (code && value && !map[code]) {
                map[code] = value;
            }
        });
        return map;
    }

    function inferFacet(item, key) {
        var map = specMap(item);
        if (map[key]) {
            return map[key];
        }
        var name = String(item.name || '');
        var lower = name.toLowerCase();
        if (key === 'material') {
            if (/obsidian|黑曜/.test(lower)) {
                return 'Natural obsidian';
            }
            return map.material || '—';
        }
        if (key === 'form') {
            if (/ruyi|如意/.test(lower)) {
                return 'Oval, ruyi-crowned';
            }
            if (/octagon|八卦|bagua/.test(lower) && /octagon|八角/.test(lower)) {
                return 'Octagonal';
            }
            if (/disc|圆盘|peace/.test(lower)) {
                return 'Round disc';
            }
            return '—';
        }
        if (key === 'symbol') {
            if (/bagua|八卦/.test(lower)) {
                return 'Bagua';
            }
            if (/yin.?yang|太极|阴阳/.test(lower)) {
                return 'Yin-Yang';
            }
            return '—';
        }
        if (key === 'care') {
            return 'Wipe with a soft cloth';
        }
        return '—';
    }

    function buildCardGrid(view) {
        var items = Array.isArray(view.items) ? view.items : [];
        if (items.length === 0) {
            return '';
        }
        var i18n = labels();
        var rows = [
            { key: 'price', label: i18n.price || 'Price', value: function (item) { return moneyDisplay(item); } },
            { key: 'material', label: i18n.material || 'Material', value: function (item) { return inferFacet(item, 'material'); } },
            { key: 'form', label: i18n.form || 'Form', value: function (item) { return inferFacet(item, 'form'); } },
            { key: 'symbol', label: i18n.symbol || 'Symbol', value: function (item) { return inferFacet(item, 'symbol'); } },
            { key: 'care', label: i18n.care || 'Care', value: function (item) { return inferFacet(item, 'care'); } }
        ];

        var html = ''
            + '<div class="dc-compare" style="--dc-compare-cols: ' + items.length + '" data-testid="daocharms-compare-grid">'
            + '<div class="dc-compare-cards">';

        items.forEach(function (item) {
            var pid = Number(item.product_id || 0);
            html += '<article class="dc-compare-card" data-product-id="' + pid + '">';
            html += '<a class="dc-compare-card__media" href="' + escapeHtml(item.url || '#') + '">';
            if (item.image) {
                html += '<img src="' + escapeHtml(item.image) + '" alt="" loading="lazy" width="480" height="480">';
            }
            html += '</a>';
            html += '<h2 class="dc-compare-card__name"><a href="' + escapeHtml(item.url || '#') + '">'
                + escapeHtml(item.name || '') + '</a></h2>';
            html += '<p class="dc-compare-card__price">' + escapeHtml(moneyDisplay(item)) + '</p>';
            html += '<button type="button" class="dc-compare-card__bag" data-compare-add-bag data-product-id="'
                + pid + '">' + escapeHtml(i18n.add_to_bag || 'Add to bag') + '</button>';
            html += '<button type="button" class="dc-compare-card__remove" data-compare-remove data-product-id="'
                + pid + '">' + escapeHtml(i18n.remove || 'Remove') + '</button>';
            html += '</article>';
        });

        html += '</div><div class="dc-compare-matrix">';
        rows.forEach(function (row) {
            html += '<div class="dc-compare-grid__label">' + escapeHtml(row.label) + '</div>';
            items.forEach(function (item) {
                html += '<div class="dc-compare-grid__cell">' + escapeHtml(row.value(item)) + '</div>';
            });
        });
        html += '</div></div>';
        return html;
    }

    function setPanelState(state) {
        var root = document.querySelector('.storefront-compare--daocharms[data-compare-page]');
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

    async function addToBag(productId) {
        if (!(productId > 0) || !window.Weline || !window.Weline.Api) {
            return;
        }
        try {
            var cart = await window.Weline.Api.resource('cart');
            if (cart && typeof cart.add === 'function') {
                await cart.add({ product_id: productId, qty: 1 });
            }
        } catch (e) {
            /* toast optional — shopper chrome may handle */
        }
    }

    function bindBagClicks(root) {
        if (!root || root.__dcCompareBagBound) {
            return;
        }
        root.__dcCompareBagBound = true;
        root.addEventListener('click', function (event) {
            var btn = event.target && event.target.closest
                ? event.target.closest('[data-compare-add-bag]')
                : null;
            if (!btn) {
                return;
            }
            event.preventDefault();
            var pid = Number(btn.getAttribute('data-product-id') || 0);
            addToBag(pid);
        });
    }

    function renderDaocharms(data) {
        var root = document.querySelector('.storefront-compare--daocharms[data-compare-page]');
        if (!root || !data || typeof data !== 'object') {
            return false;
        }
        var items = Array.isArray(data.items) ? data.items : [];
        var content = root.querySelector('[data-compare-page-content]');
        var meta = root.querySelector('[data-compare-page-meta]');
        var count = Number(data.compare_count != null ? data.compare_count : items.length);
        var max = Number(data.max || root.getAttribute('data-compare-max') || 8);
        var i18n = labels();
        if (meta) {
            meta.textContent = String(i18n.meta || 'Selected %{1} / %{2}.')
                .replace('%{1}', String(count))
                .replace('%{2}', String(max));
        }
        if (items.length === 0) {
            if (content) {
                content.innerHTML = '';
            }
            setPanelState('empty');
            return true;
        }
        if (content) {
            content.innerHTML = buildCardGrid(data);
        }
        setPanelState('content');
        bindBagClicks(root);
        return true;
    }

    function patch() {
        if (patched) {
            return true;
        }
        if (!document.querySelector('.storefront-compare--daocharms[data-compare-page]')) {
            return true;
        }
        if (!window.WelineComparePage || typeof window.WelineComparePage.renderFromPayload !== 'function') {
            return false;
        }
        var original = window.WelineComparePage.renderFromPayload.bind(window.WelineComparePage);
        window.WelineComparePage.renderFromPayload = function (data) {
            if (renderDaocharms(data)) {
                return;
            }
            return original(data);
        };
        patched = true;
        if (window.WelineCompareShopper && typeof window.WelineCompareShopper.refreshCompareState === 'function') {
            window.WelineCompareShopper.refreshCompareState();
        }
        return true;
    }

    function boot() {
        if (patch()) {
            return;
        }
        var tries = 0;
        var timer = window.setInterval(function () {
            tries += 1;
            if (patch() || tries > 40) {
                window.clearInterval(timer);
            }
        }, 100);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
