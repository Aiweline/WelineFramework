(function () {
    'use strict';

    function isHidden(el) {
        return !!(el && (el.hidden || el.getAttribute('hidden') !== null));
    }

    function tabLabel(el) {
        if (!el || el.nodeType !== 1) {
            return '';
        }
        var label = String(el.getAttribute('data-mini-cart-tab-label') || '').trim();
        if (label) {
            return label;
        }
        var nested = el.querySelector('[data-mini-cart-tab-label]');
        if (nested && nested !== el && !isHidden(nested)) {
            label = String(nested.getAttribute('data-mini-cart-tab-label') || '').trim();
            if (label) {
                return label;
            }
        }
        var source = el.querySelector('[data-mini-cart-tab-label-source]');
        if (source && !isHidden(source)) {
            label = String(source.textContent || '').trim();
            if (label) {
                return label;
            }
        }
        // 隐藏的 source（迷你车订单留言）仍可作为标签源。
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
        var wrapper = el.querySelector('[data-widget-name]');
        if (wrapper) {
            label = String(wrapper.getAttribute('data-widget-name') || '').trim();
            if (label) {
                return label;
            }
        }
        return '';
    }

    /**
     * Stable panel identity so rebuild can restore the user's tab after DOM churn
     * (e.g. first reveal of 订单留言 lazy-loads orderNotice → MutationObserver → rebuild).
     */
    function widgetIdentity(node) {
        if (!node || node.nodeType !== 1) {
            return '';
        }
        var couponSel = '[data-marketing-checkout-coupon], [data-testid="checkout-coupon"],'
            + ' [data-testid="marketing-checkout-coupon"], .w-marketing-checkout-coupon';
        var noteSel = '[data-order-notice-surface], [data-testid="order-notice-widget"], .w-order-notice';
        var creditSel = '[data-b2b-checkout-credit]';
        var helpSel = '[data-helppay-placement], [data-testid="checkout-summary-help-pay"],'
            + ' [data-testid="cart-summary-help-pay"]';
        if (node.matches(couponSel) || node.querySelector(couponSel)) {
            return 'coupon';
        }
        if (node.matches(noteSel) || node.querySelector(noteSel)) {
            return 'note';
        }
        if (node.matches(creditSel) || node.querySelector(creditSel)) {
            return 'credit';
        }
        if (node.matches(helpSel) || node.querySelector(helpSel)) {
            return 'helppay';
        }
        return tabLabel(node);
    }

    /**
     * 空槽（如未注入的帮我付）或整棵部件已 hidden 时不得生成无文案胶囊页签。
     */
    function isExtrasTabCandidate(node) {
        if (!node || node.nodeType !== 1) {
            return false;
        }
        if (node.matches('[data-mini-cart-extras-tabs]')) {
            return false;
        }
        if (isHidden(node)) {
            return false;
        }

        var roots = node.querySelectorAll(
            '[data-mini-cart-tab-label], [data-b2b-checkout-credit], [data-marketing-checkout-coupon],'
            + ' [data-order-notice-surface], [data-helppay-placement], [data-testid="order-notice-widget"],'
            + ' [data-testid="checkout-coupon"], [data-testid="marketing-checkout-coupon"], [data-testid="checkout-summary-help-pay"],'
            + ' [data-testid="cart-summary-help-pay"]'
        );
        if (roots.length) {
            var anyVisible = false;
            for (var i = 0; i < roots.length; i += 1) {
                if (!isHidden(roots[i])) {
                    anyVisible = true;
                    break;
                }
            }
            if (!anyVisible) {
                return false;
            }
        }

        return tabLabel(node) !== '';
    }

    function activateTab(shell, index, opts) {
        opts = opts || {};
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
        // Rebuild/init must not scrollIntoView — that fights drawer layout; user click may.
        if (opts.scroll !== false && activeTab && typeof activeTab.scrollIntoView === 'function') {
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

    function resolveStartIndex(widgets, opts) {
        opts = opts || {};
        var startIndex = 0;
        if (opts.preferredIdentity) {
            for (var i = 0; i < widgets.length; i += 1) {
                if (widgetIdentity(widgets[i]) === opts.preferredIdentity) {
                    return i;
                }
            }
        }
        if (typeof opts.preferredIndex === 'number' && !isNaN(opts.preferredIndex)) {
            startIndex = Math.max(0, Math.min(opts.preferredIndex, widgets.length - 1));
        }
        return startIndex;
    }

    function initExtras(extras, opts) {
        if (!extras || extras.getAttribute('data-extras-tabs-init') === '1') {
            return;
        }
        opts = opts || {};

        var widgets = Array.prototype.filter.call(extras.children, isExtrasTabCandidate);
        if (!widgets.length) {
            return;
        }

        extras.classList.add('mini-cart-drawer__extras--compact');
        if (widgets.length < 2) {
            extras.setAttribute('data-extras-tabs-init', '1');
            return;
        }

        var startIndex = resolveStartIndex(widgets, opts);

        var shell = document.createElement('div');
        shell.className = 'mini-cart-drawer__extras-tabs';
        shell.setAttribute('data-mini-cart-extras-tabs', '1');
        if (opts.tobDefaulted) {
            shell.setAttribute('data-b2b-tob-default-tab', '1');
        }

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
            button.className = 'mini-cart-drawer__extras-tab' + (index === startIndex ? ' is-active' : '');
            button.setAttribute('role', 'tab');
            button.setAttribute('id', id);
            button.setAttribute('data-mini-cart-extras-tab', '1');
            button.setAttribute('aria-controls', id + '-panel');
            button.setAttribute('aria-selected', index === startIndex ? 'true' : 'false');
            button.tabIndex = index === startIndex ? 0 : -1;
            button.textContent = tabLabel(widget);
            button.addEventListener('click', function () {
                activateTab(shell, index);
            });
            tablist.appendChild(button);

            var panel = document.createElement('div');
            panel.className = 'mini-cart-drawer__extras-panel' + (index === startIndex ? ' is-active' : '');
            panel.setAttribute('role', 'tabpanel');
            panel.setAttribute('id', id + '-panel');
            panel.setAttribute('aria-labelledby', id);
            panel.setAttribute('data-mini-cart-extras-panel', '1');
            panel.hidden = index !== startIndex;
            panel.appendChild(widget);
            panels.appendChild(panel);
        });

        shell.appendChild(tablist);
        shell.appendChild(panels);
        extras.appendChild(shell);
        shell.setAttribute('data-active-tab', String(startIndex));
        bindSwipe(shell);
        extras.setAttribute('data-extras-tabs-init', '1');
        // Ensure class/hidden/aria stay consistent even if startIndex !== 0.
        activateTab(shell, startIndex, { scroll: false });
        window.dispatchEvent(new CustomEvent('weshop:mini-cart:extras-ready'));
    }

    function rebuildExtras(extras) {
        if (!extras) {
            return;
        }
        var preferredIdentity = '';
        var preferredIndex = 0;
        var tobDefaulted = false;
        var shell = extras.querySelector('[data-mini-cart-extras-tabs]');
        if (shell) {
            preferredIndex = Number(shell.getAttribute('data-active-tab') || '0') || 0;
            tobDefaulted = shell.getAttribute('data-b2b-tob-default-tab') === '1';
            var panels = shell.querySelectorAll('[data-mini-cart-extras-panel]');
            if (panels[preferredIndex]) {
                preferredIdentity = widgetIdentity(panels[preferredIndex]);
            }
            panels.forEach(function (panel) {
                while (panel.firstChild) {
                    extras.insertBefore(panel.firstChild, shell);
                }
            });
            shell.remove();
        }
        extras.removeAttribute('data-extras-tabs-init');
        extras.classList.remove('mini-cart-drawer__extras--compact');
        initExtras(extras, {
            preferredIdentity: preferredIdentity,
            preferredIndex: preferredIndex,
            tobDefaulted: tobDefaulted
        });
    }

    function boot() {
        document.querySelectorAll('.mini-cart-drawer__extras, [data-cart-summary-extras="1"]').forEach(function (extras) {
            // If a newly visible candidate sits outside the tab shell (e.g. tob
            // credit unhidden after retail init), rebuild instead of no-op.
            if (extras.getAttribute('data-extras-tabs-init') === '1') {
                var shell = extras.querySelector('[data-mini-cart-extras-tabs]');
                var orphans = Array.prototype.filter.call(extras.children, function (node) {
                    return isExtrasTabCandidate(node)
                        && !(shell && shell.contains(node));
                });
                if (orphans.length) {
                    rebuildExtras(extras);
                    return;
                }
            }
            initExtras(extras);
        });
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
        rebuild: rebuildExtras,
        activateTab: activateTab,
        widgetIdentity: widgetIdentity
    };
})();
