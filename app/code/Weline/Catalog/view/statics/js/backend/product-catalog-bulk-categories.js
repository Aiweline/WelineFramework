(function () {
    'use strict';

    var trigger = document.querySelector('[data-catalog-bulk-categories]');
    var dialog = document.querySelector('[data-catalog-bulk-categories-dialog]');
    if (!trigger || !dialog) {
        return;
    }

    var summary = dialog.querySelector('[data-catalog-bulk-categories-summary]');
    var listRoot = dialog.querySelector('[data-catalog-bulk-category-list]');
    var searchInput = dialog.querySelector('[data-catalog-bulk-category-search]');
    var submitButton = dialog.querySelector('[data-catalog-bulk-categories-submit]');
    var cancelButton = dialog.querySelector('[data-catalog-bulk-categories-cancel]');
    var form = dialog.querySelector('[data-catalog-bulk-categories-form]');

    var categories = [];
    var selectionCount = 0;
    var websiteId = 0;
    var productApiPromise = null;
    var catalogApiPromise = null;

    function toast(tone, message) {
        var text = String(message || '');
        var ui = window.Weline && window.Weline.UI ? window.Weline.UI.toast : null;
        if (ui && typeof ui[tone] === 'function') {
            ui[tone](text);
            return;
        }
        if (tone === 'error') {
            window.alert(text);
        }
    }

    function productApi() {
        if (!productApiPromise) {
            productApiPromise = Promise.resolve().then(function () {
                if (!window.Weline || !window.Weline.Api || typeof window.Weline.Api.resource !== 'function') {
                    throw new Error('商品后台 API 尚未加载');
                }
                return window.Weline.Api.resource('product_admin');
            });
        }
        return productApiPromise;
    }

    function catalogApi() {
        if (!catalogApiPromise) {
            catalogApiPromise = Promise.resolve().then(function () {
                if (!window.Weline || !window.Weline.Api || typeof window.Weline.Api.resource !== 'function') {
                    throw new Error('分类 API 尚未加载');
                }
                return window.Weline.Api.resource('catalog');
            });
        }
        return catalogApiPromise;
    }

    function flattenTree(nodes, depth, output) {
        (nodes || []).forEach(function (node) {
            if (!node || typeof node !== 'object') {
                return;
            }
            var categoryId = parseInt(node.category_id || node.id || '0', 10);
            if (categoryId > 0) {
                output.push({
                    category_id: categoryId,
                    name: String(node.name || node.label || node.path || ('#' + categoryId)),
                    path: String(node.path || ''),
                    depth: depth,
                });
            }
            flattenTree(node.nodes || node.children || [], depth + 1, output);
        });
    }

    function selectedItems() {
        var catalog = window.WelineProductAdminCatalog;
        if (catalog && typeof catalog.getSelection === 'function') {
            return catalog.getSelection();
        }
        return [];
    }

    function selectedMode() {
        var checked = dialog.querySelector('input[name="catalog_bulk_mode"]:checked');
        return checked instanceof HTMLInputElement ? checked.value : 'add';
    }

    function selectedCategoryIds() {
        return Array.prototype.map.call(
            listRoot.querySelectorAll('[data-catalog-bulk-category-id]:checked'),
            function (input) {
                return parseInt(input.value, 10);
            },
        ).filter(function (value) {
            return Number.isInteger(value) && value > 0;
        });
    }

    function updateSubmitState() {
        if (!(submitButton instanceof HTMLButtonElement)) {
            return;
        }
        var mode = selectedMode();
        var categoryIds = selectedCategoryIds();
        submitButton.disabled = selectionCount === 0
            || (mode !== 'remove' && categoryIds.length === 0);
    }

    function renderCategories() {
        if (!(listRoot instanceof HTMLElement)) {
            return;
        }
        if (categories.length === 0) {
            listRoot.innerHTML = '<p class="w-text" data-tone="muted" data-size="sm">当前 Website 还没有分类。</p>';
            updateSubmitState();
            return;
        }
        listRoot.innerHTML = categories.map(function (category) {
            var indent = category.depth > 0 ? 'padding-left:' + (category.depth * 0.85) + 'rem' : '';
            var searchText = (category.name + ' ' + category.path).toLowerCase();
            return ''
                + '<label class="w-catalog-bulk-categories__row" data-search-text="' + searchText.replace(/"/g, '&quot;') + '" style="' + indent + '">'
                + '<input type="checkbox" data-catalog-bulk-category-id value="' + category.category_id + '">'
                + '<span><strong>' + category.name.replace(/</g, '&lt;') + '</strong>'
                + (category.path ? '<small>' + category.path.replace(/</g, '&lt;') + '</small>' : '')
                + '</span></label>';
        }).join('');
        listRoot.addEventListener('change', function (event) {
            if (event.target instanceof HTMLInputElement && event.target.matches('[data-catalog-bulk-category-id]')) {
                updateSubmitState();
            }
        });
        updateSubmitState();
    }

    function filterCategories(query) {
        var needle = String(query || '').trim().toLowerCase();
        listRoot.querySelectorAll('.w-catalog-bulk-categories__row').forEach(function (row) {
            if (!(row instanceof HTMLElement)) {
                return;
            }
            var haystack = row.getAttribute('data-search-text') || '';
            row.hidden = needle !== '' && !haystack.includes(needle);
        });
    }

    function currentLocale() {
        var root = document.documentElement;
        if (root && root.getAttribute('data-local')) {
            return String(root.getAttribute('data-local') || '').trim();
        }
        var match = document.cookie.match(/(?:^|;\s*)lang_local=([^;]+)/);
        return match ? decodeURIComponent(match[1]) : '';
    }

    function loadCategories() {
        if (!(listRoot instanceof HTMLElement)) {
            return Promise.resolve();
        }
        listRoot.innerHTML = '<p class="w-text" data-tone="muted" data-size="sm">正在加载分类…</p>';
        var embedded = document.getElementById('product-catalog-category-options');
        if (embedded) {
            try {
                var parsed = JSON.parse(embedded.textContent || '[]');
                categories = Array.isArray(parsed) ? parsed : [];
                renderCategories();
                return Promise.resolve();
            } catch (error) {
                // fall through to API
            }
        }
        return catalogApi().then(function (resource) {
            var locale = currentLocale();
            return resource.tree({
                space: 'product',
                scope_level: 'website',
                website_id: websiteId,
                locale: locale,
            }, {
                keepBusinessResult: true,
                silent: true,
            });
        }).then(function (result) {
            var payload = result && result.data ? result.data : result;
            var tree = Array.isArray(payload) ? payload : (payload.tree || payload.nodes || []);
            categories = [];
            flattenTree(tree, 0, categories);
            renderCategories();
        }).catch(function (error) {
            listRoot.innerHTML = '<p class="w-text" data-tone="danger" data-size="sm">分类加载失败</p>';
            toast('error', error && error.message ? error.message : '分类加载失败');
        });
    }

    function applyBulkCategories() {
        var items = selectedItems();
        var mode = selectedMode();
        var categoryIds = selectedCategoryIds();
        if (items.length === 0) {
            return Promise.resolve();
        }
        if (mode !== 'remove' && categoryIds.length === 0) {
            toast('error', '请至少选择一个分类');
            return Promise.resolve();
        }
        if (!(submitButton instanceof HTMLButtonElement)) {
            return Promise.resolve();
        }
        submitButton.disabled = true;
        return productApi().then(function (resource) {
            if (!resource || typeof resource.bulkAssignCategories !== 'function') {
                throw new Error('商品后台 API 不支持 bulkAssignCategories');
            }
            return resource.bulkAssignCategories({
                website_id: websiteId,
                mode: mode,
                category_ids: categoryIds,
                items: items.map(function (item) {
                    return {
                        global_product_uuid: item.global_product_uuid,
                        product_id: item.product_id,
                    };
                }),
            }, {
                keepBusinessResult: true,
                silent: true,
            });
        }).then(function (result) {
            var payload = result && result.data ? result.data : result;
            if (payload && payload.success === false) {
                throw new Error(payload.message || '批量分类调整失败');
            }
            toast('success', (payload && payload.message) || '批量分类调整完成');
            dialog.close();
            window.location.reload();
        }).catch(function (error) {
            toast('error', error && error.message ? error.message : '批量分类调整失败');
        }).finally(function () {
            updateSubmitState();
        });
    }

    function syncSelection(event) {
        var detail = event && event.detail ? event.detail : {};
        selectionCount = parseInt(detail.count, 10) || 0;
        websiteId = parseInt(detail.website_id, 10) || 0;
        if (window.WelineProductAdminCatalog && typeof window.WelineProductAdminCatalog.getWebsiteId === 'function') {
            websiteId = window.WelineProductAdminCatalog.getWebsiteId();
        }
        trigger.disabled = selectionCount === 0;
        if (summary instanceof HTMLElement) {
            summary.textContent = selectionCount > 0
                ? ('已选择 ' + selectionCount + ' 个商品')
                : '';
        }
        updateSubmitState();
    }

    trigger.addEventListener('click', function () {
        if (selectionCount === 0) {
            return;
        }
        if (typeof dialog.showModal === 'function') {
            dialog.showModal();
        }
        loadCategories();
    });

    if (cancelButton instanceof HTMLButtonElement) {
        cancelButton.addEventListener('click', function () {
            dialog.close();
        });
    }

    if (searchInput instanceof HTMLInputElement) {
        searchInput.addEventListener('input', function () {
            filterCategories(searchInput.value);
        });
    }

    dialog.querySelectorAll('input[name="catalog_bulk_mode"]').forEach(function (input) {
        input.addEventListener('change', updateSubmitState);
    });

    if (form instanceof HTMLFormElement) {
        form.addEventListener('submit', function (event) {
            event.preventDefault();
            applyBulkCategories();
        });
    }

    document.addEventListener('weline:product:catalog:selection-change', syncSelection);
    syncSelection({detail: {count: 0, website_id: 0}});
})();
