/**
 * Storefront search type/category tree filter (Weline_Search).
 * Registered as module `searchTypeFilter` in view/statics/frontend/weline.modules.js;
 * host declares data-weline-load="searchTypeFilter" and data-search-type-filter.
 *
 * Replaces the former inline <script> + inline onchange="..." handler:
 *  - tree toggles expand/collapse a node
 *  - radio change writes the node's data-category-id into the form's category_id
 *    (empty value => category_id disabled so it does not appear in the GET URL)
 */
(function () {
    'use strict';

    function initRoot(root) {
        if (!root || root.getAttribute('data-filter-tree-init') === '1') {
            return;
        }
        root.setAttribute('data-filter-tree-init', '1');

        root.addEventListener('click', function (event) {
            var btn = event.target && event.target.closest ? event.target.closest('[data-search-filter-toggle]') : null;
            if (!btn || !root.contains(btn)) {
                return;
            }
            event.preventDefault();
            event.stopPropagation();
            var node = btn.closest('[data-search-filter-node]');
            if (!node) {
                return;
            }
            var children = node.querySelector(':scope > [data-search-filter-children]');
            var open = node.getAttribute('data-state') !== 'open';
            node.classList.toggle('is-open', open);
            node.setAttribute('data-state', open ? 'open' : 'closed');
            btn.setAttribute('aria-expanded', open ? 'true' : 'false');
            if (children) {
                children.hidden = !open;
            }
        });

        root.addEventListener('change', function (event) {
            var input = event.target;
            if (!input || input.type !== 'radio' || input.name !== 'type' || !root.contains(input)) {
                return;
            }
            var form = input.form;
            if (!form) {
                return;
            }
            var category = form.category_id;
            if (category) {
                var value = input.getAttribute('data-category-id') || '';
                if (value) {
                    category.disabled = false;
                    category.value = value;
                } else {
                    category.value = '';
                    category.disabled = true;
                }
            }
            form.submit();
        });
    }

    function boot() {
        document.querySelectorAll('[data-search-type-filter]').forEach(initRoot);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot, { once: true });
    } else {
        boot();
    }

    window.WelineSearchTypeFilter = window.WelineSearchTypeFilter || { ready: true };
}());
