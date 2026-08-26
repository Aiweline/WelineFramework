(function () {
    'use strict';

    var root = document.querySelector('[data-product-admin="categories"]');
    if (!root) {
        return;
    }

    var searchInput = document.getElementById('product-category-search');
    var tree = root.querySelector('[data-testid="product-category-tree"]');
    var parentSelect = root.querySelector('[data-category-parent-select]');
    var pathInput = root.querySelector('[data-category-path-input]');
    var pathPreview = root.querySelector('[data-category-path-preview]');

    function normalizeSlug(value) {
        return String(value || '')
            .trim()
            .replace(/^\/+/, '')
            .replace(/\/+$/, '')
            .replace(/[^a-zA-Z0-9/_-]+/g, '-')
            .replace(/-+/g, '-')
            .replace(/\/{2,}/g, '/');
    }

    function selectedParentPath() {
        if (!parentSelect) {
            return '';
        }
        var option = parentSelect.options[parentSelect.selectedIndex];
        if (!option) {
            return '';
        }
        return String(option.getAttribute('data-path') || '').trim();
    }

    function formatMessage(template, value) {
        return String(template || '').replace('%{1}', value).replace('{path}', value);
    }

    function updatePathPreview() {
        if (!pathPreview) {
            return;
        }
        var suggestTemplate = root.getAttribute('data-text-path-prefix-suggest') || '';
        var tipTemplate = root.getAttribute('data-text-path-prefix-tip') || '';
        var parentPath = selectedParentPath();
        var current = String(pathInput && pathInput.value ? pathInput.value : '').trim();
        if (current === '' && parentPath !== '') {
            pathPreview.textContent = formatMessage(suggestTemplate, parentPath + '/your-slug');
            pathPreview.hidden = false;
            return;
        }
        if (parentPath !== '' && current !== '' && current.indexOf(parentPath + '/') !== 0 && current !== parentPath) {
            pathPreview.textContent = formatMessage(tipTemplate, parentPath + '/');
            pathPreview.hidden = false;
            return;
        }
        pathPreview.hidden = true;
        pathPreview.textContent = '';
    }

    function bindPathHelpers() {
        if (parentSelect) {
            parentSelect.addEventListener('change', updatePathPreview);
        }
        if (pathInput) {
            pathInput.addEventListener('input', function () {
                var value = pathInput.value;
                if (value !== '' && value.charAt(0) !== '/') {
                    pathInput.value = '/' + normalizeSlug(value);
                }
                updatePathPreview();
            });
            pathInput.addEventListener('blur', function () {
                var value = normalizeSlug(pathInput.value);
                pathInput.value = value === '' ? '' : '/' + value;
                updatePathPreview();
            });
        }
        updatePathPreview();
    }

    function setExpanded(item, expanded) {
        var toggle = item.querySelector(':scope > .w-product-category-tree__row .w-product-category-tree__toggle');
        var childList = item.querySelector(':scope > .w-tree');
        if (!childList) {
            return;
        }
        item.classList.toggle('is-collapsed', !expanded);
        if (toggle) {
            toggle.setAttribute('aria-expanded', expanded ? 'true' : 'false');
        }
    }

    function bindTreeToggles() {
        if (!tree) {
            return;
        }
        tree.addEventListener('click', function (event) {
            var target = event.target;
            if (!(target instanceof Element)) {
                return;
            }
            var toggle = target.closest('.w-product-category-tree__toggle');
            if (!toggle) {
                return;
            }
            event.preventDefault();
            var item = toggle.closest('.w-product-category-tree__item');
            if (!item) {
                return;
            }
            setExpanded(item, item.classList.contains('is-collapsed'));
        });

        root.querySelectorAll('[data-category-action="expand-all"]').forEach(function (button) {
            button.addEventListener('click', function () {
                tree.querySelectorAll('.w-product-category-tree__item').forEach(function (item) {
                    setExpanded(item, true);
                });
            });
        });
        root.querySelectorAll('[data-category-action="collapse-all"]').forEach(function (button) {
            button.addEventListener('click', function () {
                tree.querySelectorAll('.w-product-category-tree__item').forEach(function (item) {
                    setExpanded(item, false);
                });
            });
        });
    }

    function bindSearch() {
        if (!searchInput || !tree) {
            return;
        }
        searchInput.addEventListener('input', function () {
            var query = String(searchInput.value || '').trim().toLowerCase();
            tree.querySelectorAll('.w-product-category-tree__item').forEach(function (item) {
                var name = String(item.getAttribute('data-category-name') || '').toLowerCase();
                var path = String(item.getAttribute('data-category-path') || '').toLowerCase();
                var matched = query === '' || name.indexOf(query) !== -1 || path.indexOf(query) !== -1;
                item.hidden = !matched;
                if (matched && query !== '') {
                    setExpanded(item, true);
                    var parentItem = item.parentElement ? item.parentElement.closest('.w-product-category-tree__item') : null;
                    while (parentItem) {
                        setExpanded(parentItem, true);
                        parentItem.hidden = false;
                        parentItem = parentItem.parentElement
                            ? parentItem.parentElement.closest('.w-product-category-tree__item')
                            : null;
                    }
                }
            });
        });
    }

    bindPathHelpers();
    bindTreeToggles();
    bindSearch();

    if (window.location.hash === '#category-create') {
        var createSection = document.getElementById('category-create');
        if (createSection && typeof createSection.scrollIntoView === 'function') {
            createSection.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
    }
}());
