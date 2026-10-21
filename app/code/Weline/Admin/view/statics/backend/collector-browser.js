/**
 * Admin 后台通用「收集器浏览器」：事件 / 插件 / 路由三页共用。
 *
 * 结构契约（由模板提供）：
 *   根：      [data-collector-browser]
 *   搜索：    [data-collector-search] + [data-collector-search-clear]
 *   筛选：    [data-collector-filter="status|module|method|event"]
 *   分组：    [data-collector-group][data-module][data-searchable]（内含 disclosure 组件）
 *   行：      [data-collector-row][data-status|data-module|data-method|data-event][data-searchable]
 *   结果：    [data-collector-result] > [data-collector-result-text]
 *   展开/折叠：[data-collector-expand-all] / [data-collector-collapse-all]
 *
 * 迁自 System/{Events,Plugins,Modules/router}/index.phtml 页面内联脚本；
 * 由 data-weline-load="adminCollectorBrowser" 加载（Admin/view/statics/backend/weline.modules.js 登记）。
 */
(function () {
    'use strict';

    function toAttr(name) {
        return 'data-' + String(name || '').replace(/([A-Z])/g, '-$1').toLowerCase();
    }

    function init(root) {
        if (!root || root.getAttribute('data-collector-ready') === '1') {
            return;
        }
        root.setAttribute('data-collector-ready', '1');

        var groups = Array.prototype.slice.call(root.querySelectorAll('[data-collector-group]'));
        var search = root.querySelector('[data-collector-search]');
        var clear = root.querySelector('[data-collector-search-clear]');
        var filters = Array.prototype.slice.call(root.querySelectorAll('[data-collector-filter]'));
        var result = root.querySelector('[data-collector-result]');
        var resultText = root.querySelector('[data-collector-result-text]');
        var expandBtn = root.querySelector('[data-collector-expand-all]');
        var collapseBtn = root.querySelector('[data-collector-collapse-all]');
        var timer = null;

        function triggerOf(node) {
            if (!node) {
                return null;
            }
            return node.matches('[data-w-disclosure-trigger]')
                ? node
                : node.querySelector('[data-w-disclosure-trigger]');
        }

        function setOpen(group, open) {
            var trigger = triggerOf(group);
            if (!trigger) {
                return;
            }
            var expanded = trigger.getAttribute('aria-expanded') === 'true';
            if (open !== expanded) {
                trigger.click();
            }
        }

        function filtersActive() {
            for (var i = 0; i < filters.length; i++) {
                if (filters[i].value) {
                    return true;
                }
            }
            return false;
        }

        function rowMatches(row, query, groupMatched) {
            if (!groupMatched) {
                var haystack = (row.getAttribute('data-searchable') || '') + ' ' + (row.textContent || '');
                if (haystack.toLowerCase().indexOf(query) === -1) {
                    return false;
                }
            }
            for (var i = 0; i < filters.length; i++) {
                var select = filters[i];
                var value = select.value;
                if (!value) {
                    continue;
                }
                var actual = row.getAttribute(toAttr(select.getAttribute('data-collector-filter'))) || '';
                if (String(value).toLowerCase() !== String(actual).toLowerCase()) {
                    return false;
                }
            }
            return true;
        }

        function apply() {
            var query = search ? search.value.trim().toLowerCase() : '';
            var visibleGroups = 0;
            var visibleRows = 0;

            groups.forEach(function (group) {
                var groupHaystack = (group.getAttribute('data-searchable') || '') + ' ' + (group.getAttribute('data-name') || '');
                var groupMatched = query !== '' && groupHaystack.toLowerCase().indexOf(query) !== -1;
                var groupVisible = false;

                Array.prototype.slice.call(group.querySelectorAll('[data-collector-row]')).forEach(function (row) {
                    var matched = rowMatches(row, query, groupMatched);
                    row.hidden = !matched;
                    if (matched) {
                        groupVisible = true;
                        visibleRows++;
                    }
                });

                group.hidden = !groupVisible;
                if (groupVisible) {
                    visibleGroups++;
                    if (query !== '' || filtersActive()) {
                        setOpen(group, true);
                    }
                }
            });

            if (result) {
                var active = query !== '' || filtersActive();
                result.hidden = !active;
                if (active && resultText) {
                    resultText.textContent = visibleGroups > 0
                        ? (root.getAttribute('data-collector-summary') || '匹配 %count% 条')
                            .replace('%count%', String(visibleRows))
                            .replace('%groups%', String(visibleGroups))
                        : (root.getAttribute('data-collector-empty') || '没有匹配项');
                }
            }
        }

        function schedule() {
            window.clearTimeout(timer);
            timer = window.setTimeout(apply, 200);
        }

        if (search) {
            search.addEventListener('keyup', schedule);
            search.addEventListener('search', schedule);
        }
        if (clear) {
            clear.addEventListener('click', function () {
                if (!search) {
                    return;
                }
                search.value = '';
                apply();
                search.focus();
            });
        }
        filters.forEach(function (select) {
            select.addEventListener('change', apply);
        });
        if (expandBtn) {
            expandBtn.addEventListener('click', function () {
                groups.forEach(function (group) {
                    setOpen(group, true);
                });
            });
        }
        if (collapseBtn) {
            collapseBtn.addEventListener('click', function () {
                groups.forEach(function (group) {
                    setOpen(group, false);
                });
            });
        }
        document.addEventListener('keydown', function (event) {
            if ((event.ctrlKey || event.metaKey) && (event.key === 'f' || event.key === 'F') && search) {
                event.preventDefault();
                search.focus();
            }
        });
    }

    function boot() {
        Array.prototype.slice.call(document.querySelectorAll('[data-collector-browser]')).forEach(init);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
