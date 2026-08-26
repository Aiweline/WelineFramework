(function () {
    'use strict';

    function escapeText(value) {
        return String(value == null ? '' : value);
    }

    async function searchApi() {
        if (!window.Weline || !window.Weline.Api || typeof window.Weline.Api.resource !== 'function') {
            return null;
        }
        try {
            return await window.Weline.Api.resource('search');
        } catch (e) {
            return null;
        }
    }

    function suggestionLabels(result, query) {
        var payload = result && result.data && typeof result.data === 'object' ? result.data : result;
        var hits = payload && Array.isArray(payload.hits) ? payload.hits : [];
        var labels = [];
        var seen = Object.create(null);

        hits.forEach(function (hit) {
            if (!hit || typeof hit !== 'object') return;
            var title = String(hit.title || hit.name || '').trim();
            if (!title) return;
            var key = title.toLowerCase();
            if (seen[key]) return;
            seen[key] = true;
            labels.push(title);
        });

        if (labels.length) {
            return labels.slice(0, 8);
        }

        // 无真实命中时不造假建议，保持空列表
        return [];
    }

    function mountThemeMenu(root) {
        if (!root) {
            return;
        }
        var menuRoot = root.matches('[data-w-component~="menu"]')
            ? root
            : root.querySelector('[data-w-component~="menu"]');
        if (!menuRoot) {
            return;
        }
        if (window.Weline && window.Weline.UI && typeof window.Weline.UI.mount === 'function') {
            window.Weline.UI.mount(menuRoot);
        }
    }

    function initTypeMenu(root) {
        var dropdown = root.querySelector('.search-type-dropdown[data-w-component="menu"]');
        if (!dropdown) {
            return;
        }

        var hiddenInput = dropdown.querySelector('.search-type-input, input[name="type"]');
        var categoryInput = dropdown.querySelector('.search-category-id-input, input[name="category_id"]');
        var form = dropdown.closest('form');
        if (!categoryInput && form) {
            categoryInput = document.createElement('input');
            categoryInput.type = 'hidden';
            categoryInput.name = 'category_id';
            categoryInput.className = 'search-category-id-input';
            categoryInput.value = '';
            form.appendChild(categoryInput);
        }
        var label = dropdown.querySelector('.search-type-label, .search-category-label');
        var panel = dropdown.querySelector('[data-w-menu-panel]');

        function setNodeOpen(node, open) {
            if (!node) return;
            var submenu = null;
            var kids = node.children;
            for (var i = 0; i < kids.length; i++) {
                if (kids[i].getAttribute && kids[i].getAttribute('data-search-type-submenu') !== null) {
                    submenu = kids[i];
                    break;
                }
            }
            var branch = node.querySelector(':scope > [data-search-type-branch], :scope > .search-type-option--branch');
            node.classList.toggle('is-open', open);
            node.setAttribute('data-state', open ? 'open' : 'closed');
            if (submenu) {
                submenu.hidden = !open;
            }
            if (branch) {
                branch.setAttribute('aria-expanded', open ? 'true' : 'false');
            }
            if (!open) {
                node.querySelectorAll('[data-search-type-node].is-open').forEach(function (child) {
                    setNodeOpen(child, false);
                });
            }
        }

        function closeSiblingNodes(node) {
            var parent = node && node.parentElement;
            if (!parent) return;
            Array.prototype.forEach.call(parent.children, function (sib) {
                if (sib !== node && sib.getAttribute && sib.getAttribute('data-search-type-node') !== null) {
                    setNodeOpen(sib, false);
                }
            });
        }

        function closeAllNodes() {
            dropdown.querySelectorAll('[data-search-type-node].is-open').forEach(function (node) {
                setNodeOpen(node, false);
            });
        }

        dropdown.querySelectorAll('[data-search-type-node].has-children').forEach(function (node) {
            node.addEventListener('mouseenter', function () {
                closeSiblingNodes(node);
                setNodeOpen(node, true);
            });

            var branch = node.querySelector(':scope > [data-search-type-branch]');
            if (branch) {
                branch.addEventListener('click', function (event) {
                    // Type-level branch (no option role): toggle flyout for touch.
                    if (!branch.hasAttribute('data-search-type-option')) {
                        event.preventDefault();
                        event.stopPropagation();
                        var willOpen = node.getAttribute('data-state') !== 'open';
                        closeSiblingNodes(node);
                        setNodeOpen(node, willOpen);
                    }
                });
            }
        });

        if (panel) {
            panel.addEventListener('mouseleave', function () {
                closeAllNodes();
            });
        }

        dropdown.querySelectorAll('[data-search-type-option]').forEach(function (option) {
            option.addEventListener('click', function (event) {
                var node = option.closest('[data-search-type-node]');
                if (node && node.classList.contains('has-children')) {
                    var isOpen = node.getAttribute('data-state') === 'open';
                    if (!isOpen) {
                        event.preventDefault();
                        event.stopPropagation();
                        closeSiblingNodes(node);
                        setNodeOpen(node, true);
                        return;
                    }
                }

                var value = option.getAttribute('data-value') || '';
                var text = option.getAttribute('data-display-label')
                    || option.getAttribute('data-label')
                    || (option.textContent || '').trim();
                var categoryId = option.getAttribute('data-category-id') || '';
                if (hiddenInput) {
                    hiddenInput.value = value;
                }
                if (categoryInput) {
                    categoryInput.value = categoryId;
                }
                if (label) {
                    label.textContent = text;
                }
                dropdown.querySelectorAll('[data-search-type-option]').forEach(function (item) {
                    var active = item === option;
                    item.classList.toggle('is-active', active);
                    item.setAttribute('aria-selected', active ? 'true' : 'false');
                });
                closeAllNodes();
            });
        });

        mountThemeMenu(dropdown);
    }

    function initRoot(root) {
        if (!root || root.getAttribute('data-w-header-search-init') === '1') {
            return;
        }
        root.setAttribute('data-w-header-search-init', '1');

        initTypeMenu(root);

        var searchInput = root.querySelector('.search-input');
        var suggestions = root.querySelector('.search-suggestions');
        var autoComplete = root.getAttribute('data-autocomplete') === 'true';

        if (!autoComplete || !searchInput || !suggestions) {
            return;
        }

        var debounceTimer;
        var list = suggestions.querySelector('.suggestion-list');

        searchInput.addEventListener('input', function () {
            clearTimeout(debounceTimer);
            var query = this.value.trim();

            if (query.length < 2) {
                suggestions.hidden = true;
                return;
            }

            debounceTimer = setTimeout(async function () {
                if (!list) return;
                while (list.firstChild) list.removeChild(list.firstChild);

                try {
                    var api = await searchApi();
                    if (!api || typeof api.search !== 'function') {
                        suggestions.hidden = true;
                        return;
                    }
                    var result = await api.search({ q: query });
                    var labels = suggestionLabels(result, query);
                    if (!labels.length) {
                        suggestions.hidden = true;
                        return;
                    }
                    labels.forEach(function (label) {
                        var item = document.createElement('div');
                        item.className = 'suggestion-item';
                        item.textContent = escapeText(label);
                        list.appendChild(item);
                    });
                    suggestions.hidden = false;
                } catch (e) {
                    suggestions.hidden = true;
                }
            }, 300);
        });

        suggestions.addEventListener('click', function (e) {
            var target = e.target && e.target.closest ? e.target.closest('.suggestion-item') : null;
            if (!target) return;
            searchInput.value = target.textContent || '';
            suggestions.hidden = true;
            var form = searchInput.closest('form');
            if (form) form.submit();
        });

        document.addEventListener('click', function (e) {
            if (!root.contains(e.target)) {
                suggestions.hidden = true;
            }
        });
    }

    function boot() {
        document.querySelectorAll('[data-js-ns="header-search"], [data-w-search]').forEach(function (root) {
            var host = root.matches('[data-w-search]') ? root : root.querySelector('[data-w-search]');
            initRoot(host || root);
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
