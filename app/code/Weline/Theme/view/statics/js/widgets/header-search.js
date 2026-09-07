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

    function suggestionHits(result) {
        var payload = result && result.data && typeof result.data === 'object' ? result.data : result;
        var hits = [];
        if (payload && Array.isArray(payload.hits)) {
            hits = payload.hits.slice();
        }
        if (payload && payload.sections && typeof payload.sections === 'object') {
            Object.keys(payload.sections).forEach(function (code) {
                var sectionHits = payload.sections[code];
                if (!Array.isArray(sectionHits)) return;
                sectionHits.forEach(function (hit) {
                    if (!hit || typeof hit !== 'object') return;
                    if (!hit.type) hit.type = code;
                    hits.push(hit);
                });
            });
        }
        return hits;
    }

    function suggestionLabels(result, query) {
        var hits = suggestionHits(result);
        var labels = [];
        var seen = Object.create(null);

        hits.forEach(function (hit) {
            if (!hit || typeof hit !== 'object') return;
            var title = String(hit.title || hit.name || '').trim();
            if (!title) return;
            var url = String(hit.url || '').trim();
            var entityId = String(hit.entity_id || hit.key || '').trim();
            var dedupe = (url || entityId || title).toLowerCase();
            if (seen[dedupe]) return;
            seen[dedupe] = true;
            var moduleName = String(hit.module || (hit.payload && hit.payload.module) || '').trim();
            var templateTitle = String(hit.template_title || (hit.payload && hit.payload.template_title) || '').trim();
            var areaLabel = String(hit.area_label || (hit.payload && hit.payload.area_label) || '').trim();
            var keyPath = String(hit.key || (hit.payload && hit.payload.key) || entityId || '').trim();
            var group = String(hit.group || (hit.payload && hit.payload.group) || moduleName || hit.type || '').trim();
            var breadcrumb = String(hit.breadcrumb || (hit.payload && hit.payload.breadcrumb) || '').trim();
            if (!breadcrumb) {
                breadcrumb = [moduleName, areaLabel, templateTitle].filter(Boolean).join(' › ');
            }
            labels.push({
                title: title,
                url: url,
                subtitle: String(hit.subtitle || breadcrumb || keyPath || '').trim(),
                type: String(hit.type || hit.indexer || '').trim(),
                module: moduleName,
                template: templateTitle,
                area: areaLabel,
                key: keyPath,
                group: group || '结果',
                breadcrumb: breadcrumb
            });
        });

        if (labels.length) {
            return labels.slice(0, 16);
        }

        // 无真实命中时不造假建议，保持空列表
        return [];
    }

    function appendSuggestionRows(list, labels) {
        if (!list || !labels || !labels.length) return;

        var hasHierarchy = labels.some(function (row) {
            return !!(row.module || row.template || row.key || row.group);
        });
        if (!hasHierarchy) {
            labels.forEach(function (row) {
                list.appendChild(buildSuggestionItem(row));
            });
            return;
        }

        var groups = [];
        var groupMap = Object.create(null);
        labels.forEach(function (row) {
            var groupKey = row.group || row.module || row.type || '结果';
            if (!groupMap[groupKey]) {
                groupMap[groupKey] = {
                    key: groupKey,
                    label: groupKey,
                    items: []
                };
                groups.push(groupMap[groupKey]);
            }
            groupMap[groupKey].items.push(row);
        });

        groups.forEach(function (group) {
            var section = document.createElement('div');
            section.className = 'suggestion-group';
            section.setAttribute('data-suggestion-group', group.key);

            var heading = document.createElement('div');
            heading.className = 'suggestion-group__label';
            heading.textContent = escapeText(group.label);
            section.appendChild(heading);

            group.items.forEach(function (row) {
                section.appendChild(buildSuggestionItem(row));
            });
            list.appendChild(section);
        });
    }

    function buildSuggestionItem(row) {
        var item = document.createElement('div');
        item.className = 'suggestion-item';
        if (row.url) {
            item.setAttribute('data-url', row.url);
        }
        if (row.subtitle) {
            item.title = row.subtitle;
        }

        var titleEl = document.createElement('div');
        titleEl.className = 'suggestion-item__title';
        titleEl.textContent = escapeText(row.title);
        item.appendChild(titleEl);

        var metaBits = [];
        if (row.template) metaBits.push(row.template);
        if (row.area) metaBits.push(row.area);
        if (metaBits.length || row.key) {
            var metaEl = document.createElement('div');
            metaEl.className = 'suggestion-item__meta';
            if (metaBits.length) {
                var pathEl = document.createElement('span');
                pathEl.className = 'suggestion-item__path';
                pathEl.textContent = escapeText(metaBits.join(' · '));
                metaEl.appendChild(pathEl);
            }
            if (row.key) {
                var keyEl = document.createElement('code');
                keyEl.className = 'suggestion-item__key';
                keyEl.textContent = escapeText(row.key);
                metaEl.appendChild(keyEl);
            }
            item.appendChild(metaEl);
        } else if (row.subtitle) {
            var subEl = document.createElement('div');
            subEl.className = 'suggestion-item__subtitle';
            subEl.textContent = escapeText(row.subtitle);
            item.appendChild(subEl);
        }

        return item;
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
        var navigateHits = root.getAttribute('data-navigate-hits') === 'true';
        var searchArea = String(root.getAttribute('data-search-area') || 'frontend').trim() || 'frontend';
        var form = root.querySelector('form');

        if (form && form.getAttribute('data-w-search-backend-form') === '1') {
            form.addEventListener('submit', function (event) {
                if (!navigateHits) return;
                event.preventDefault();
                var first = suggestions && suggestions.querySelector('.suggestion-item[data-url]');
                var url = first ? first.getAttribute('data-url') : '';
                if (url) {
                    window.location.href = url;
                }
            });
        }

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
                    var typeInput = root.querySelector('input[name="type"]');
                    var type = typeInput ? String(typeInput.value || 'all') : 'all';
                    var result = await api.search({ q: query, type: type, area: searchArea, page_size: searchArea === 'backend' ? 24 : 12 });
                    var labels = suggestionLabels(result, query);
                    if (!labels.length) {
                        suggestions.hidden = true;
                        return;
                    }
                    appendSuggestionRows(list, labels);
                    suggestions.hidden = false;
                } catch (e) {
                    suggestions.hidden = true;
                }
            }, 300);
        });

        suggestions.addEventListener('click', function (e) {
            var target = e.target && e.target.closest ? e.target.closest('.suggestion-item') : null;
            if (!target) return;
            var url = target.getAttribute('data-url') || '';
            if (navigateHits && url) {
                suggestions.hidden = true;
                window.location.href = url;
                return;
            }
            searchInput.value = (target.querySelector('.suggestion-item__title') || target).textContent || '';
            suggestions.hidden = true;
            var formEl = searchInput.closest('form');
            if (formEl) formEl.submit();
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
