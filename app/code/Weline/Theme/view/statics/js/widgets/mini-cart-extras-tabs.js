(function () {
    'use strict';

    function tabLabel(el) {
        var label = String(el.getAttribute('data-mini-cart-tab-label') || '').trim();
        if (label) {
            return label;
        }
        var nested = el.querySelector('[data-mini-cart-tab-label]');
        if (nested && nested !== el) {
            label = String(nested.getAttribute('data-mini-cart-tab-label') || '').trim();
            if (label) {
                return label;
            }
        }
        var source = el.querySelector('[data-mini-cart-tab-label-source]');
        if (source) {
            label = String(source.textContent || '').trim();
            if (label) {
                return label;
            }
        }
        label = String(el.getAttribute('data-widget-name') || el.getAttribute('data-wslot-widget-name') || '').trim();
        if (label) {
            return label;
        }
        return '';
    }

    function activateTab(shell, index) {
        var tabs = shell.querySelectorAll('[data-mini-cart-extras-tab]');
        var panels = shell.querySelectorAll('[data-mini-cart-extras-panel]');
        if (!tabs.length || !panels.length) {
            return;
        }
        var next = Math.max(0, Math.min(index, tabs.length - 1));
        tabs.forEach(function (tab, i) {
            var active = i === next;
            tab.classList.toggle('is-active', active);
            tab.setAttribute('aria-selected', active ? 'true' : 'false');
            tab.tabIndex = active ? 0 : -1;
        });
        panels.forEach(function (panel, i) {
            var active = i === next;
            panel.classList.toggle('is-active', active);
            panel.hidden = !active;
        });
        shell.setAttribute('data-active-tab', String(next));
        var activeTab = tabs[next];
        if (activeTab && typeof activeTab.scrollIntoView === 'function') {
            activeTab.scrollIntoView({ block: 'nearest', inline: 'nearest' });
        }
    }

    function bindSwipe(shell) {
        var track = shell.querySelector('[data-mini-cart-extras-panels]');
        if (!track) {
            return;
        }
        var startX = 0;
        var startY = 0;
        track.addEventListener('touchstart', function (event) {
            if (!event.touches || !event.touches.length) {
                return;
            }
            startX = event.touches[0].clientX;
            startY = event.touches[0].clientY;
        }, { passive: true });
        track.addEventListener('touchend', function (event) {
            if (!event.changedTouches || !event.changedTouches.length) {
                return;
            }
            var deltaX = event.changedTouches[0].clientX - startX;
            var deltaY = event.changedTouches[0].clientY - startY;
            if (Math.abs(deltaX) < 40 || Math.abs(deltaX) < Math.abs(deltaY)) {
                return;
            }
            var current = Number(shell.getAttribute('data-active-tab') || '0');
            activateTab(shell, deltaX < 0 ? current + 1 : current - 1);
        }, { passive: true });
    }

    function initExtras(extras) {
        if (!extras || extras.getAttribute('data-extras-tabs-init') === '1') {
            return;
        }

        var widgets = Array.prototype.filter.call(extras.children, function (node) {
            // 跳过已 hidden 的槽（例：零售结账隐藏批发信用），避免仍生成对应页签按钮。
            return node.nodeType === 1
                && !node.matches('[data-mini-cart-extras-tabs]')
                && !node.hidden;
        });
        if (!widgets.length) {
            return;
        }

        extras.classList.add('mini-cart-drawer__extras--compact');
        if (widgets.length < 2) {
            extras.setAttribute('data-extras-tabs-init', '1');
            return;
        }

        var shell = document.createElement('div');
        shell.className = 'mini-cart-drawer__extras-tabs';
        shell.setAttribute('data-mini-cart-extras-tabs', '1');

        var tablist = document.createElement('div');
        tablist.className = 'mini-cart-drawer__extras-tablist';
        tablist.setAttribute('role', 'tablist');
        tablist.setAttribute('aria-label', extras.getAttribute('data-wslot-name') || 'Footer extras');

        var panels = document.createElement('div');
        panels.className = 'mini-cart-drawer__extras-panels';
        panels.setAttribute('data-mini-cart-extras-panels', '1');

        var shellUid = 'extras-' + String(Date.now()) + '-' + String(Math.floor(Math.random() * 100000));
        widgets.forEach(function (widget, index) {
            var id = shellUid + '-tab-' + String(index);
            var button = document.createElement('button');
            button.type = 'button';
            button.className = 'mini-cart-drawer__extras-tab' + (index === 0 ? ' is-active' : '');
            button.setAttribute('role', 'tab');
            button.setAttribute('id', id);
            button.setAttribute('data-mini-cart-extras-tab', '1');
            button.setAttribute('aria-controls', id + '-panel');
            button.setAttribute('aria-selected', index === 0 ? 'true' : 'false');
            button.tabIndex = index === 0 ? 0 : -1;
            button.textContent = tabLabel(widget);
            button.addEventListener('click', function () {
                activateTab(shell, index);
            });
            tablist.appendChild(button);

            var panel = document.createElement('div');
            panel.className = 'mini-cart-drawer__extras-panel' + (index === 0 ? ' is-active' : '');
            panel.setAttribute('role', 'tabpanel');
            panel.setAttribute('id', id + '-panel');
            panel.setAttribute('aria-labelledby', id);
            panel.setAttribute('data-mini-cart-extras-panel', '1');
            panel.hidden = index !== 0;
            panel.appendChild(widget);
            panels.appendChild(panel);
        });

        shell.appendChild(tablist);
        shell.appendChild(panels);
        extras.appendChild(shell);
        shell.setAttribute('data-active-tab', '0');
        bindSwipe(shell);
        extras.setAttribute('data-extras-tabs-init', '1');
        window.dispatchEvent(new CustomEvent('weshop:mini-cart:extras-ready'));
    }

    function rebuildExtras(extras) {
        if (!extras) {
            return;
        }
        var shell = extras.querySelector('[data-mini-cart-extras-tabs]');
        if (shell) {
            var panels = shell.querySelectorAll('[data-mini-cart-extras-panel]');
            panels.forEach(function (panel) {
                while (panel.firstChild) {
                    extras.insertBefore(panel.firstChild, shell);
                }
            });
            shell.remove();
        }
        extras.removeAttribute('data-extras-tabs-init');
        extras.classList.remove('mini-cart-drawer__extras--compact');
        initExtras(extras);
    }

    function boot() {
        document.querySelectorAll('.mini-cart-drawer__extras, [data-cart-summary-extras="1"]').forEach(initExtras);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }

    window.addEventListener('weshop:mini-cart:open', boot);
    window.addEventListener('weshop:cart-summary:extras-ready', boot);

    window.WelineMiniCartExtras = {
        boot: boot,
        initExtras: initExtras,
        rebuild: rebuildExtras
    };
})();
