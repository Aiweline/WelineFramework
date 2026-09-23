/**
 * Footer 侧边悬浮社媒：贴边收起 / 展开，记忆本机会话偏好。
 * 收起位移同时写 inline transform，避免发布 CSS 缓存滞后时无动画/无位移。
 */
(function (global) {
    'use strict';

    var STORAGE_KEY = 'weline.footerSocialFloat.collapsed';
    var mounted = typeof WeakSet === 'function' ? new WeakSet() : null;

    function readStoredCollapsed() {
        try {
            return global.localStorage && global.localStorage.getItem(STORAGE_KEY) === '1';
        } catch (e) {
            return false;
        }
    }

    function writeStoredCollapsed(collapsed) {
        try {
            if (!global.localStorage) {
                return;
            }
            if (collapsed) {
                global.localStorage.setItem(STORAGE_KEY, '1');
            } else {
                global.localStorage.removeItem(STORAGE_KEY);
            }
        } catch (e) {
            /* ignore quota / private mode */
        }
    }

    function applyShift(root, collapsed) {
        var side = (root.getAttribute('data-side') || 'left').toLowerCase();
        var shift = collapsed
            ? (side === 'right' ? 'translateY(-50%) translateX(100%)' : 'translateY(-50%) translateX(-100%)')
            : 'translateY(-50%)';
        root.style.transform = shift;
    }

    function setCollapsed(root, collapsed) {
        var panel = root.querySelector('[data-float-panel]');
        var toggle = root.querySelector('[data-float-toggle]');
        var expandedLabel = root.getAttribute('data-expanded-label') || '';
        var collapsedLabel = root.getAttribute('data-collapsed-label') || '';

        root.classList.toggle('is-collapsed', collapsed);
        root.setAttribute('data-collapsed', collapsed ? '1' : '0');
        applyShift(root, collapsed);

        if (panel) {
            panel.setAttribute('aria-hidden', collapsed ? 'true' : 'false');
            if ('inert' in panel) {
                panel.inert = collapsed;
            }
            var links = panel.querySelectorAll('a');
            for (var i = 0; i < links.length; i += 1) {
                if (collapsed) {
                    links[i].setAttribute('tabindex', '-1');
                } else {
                    links[i].removeAttribute('tabindex');
                }
            }
        }

        if (toggle) {
            toggle.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
            toggle.setAttribute('aria-label', collapsed ? collapsedLabel : expandedLabel);
        }

        writeStoredCollapsed(collapsed);
    }

    function mount(root) {
        if (!root || (mounted && mounted.has(root))) {
            return;
        }
        var toggle = root.querySelector('[data-float-toggle]');
        if (!toggle) {
            return;
        }
        if (mounted) {
            mounted.add(root);
        }

        setCollapsed(root, readStoredCollapsed());

        toggle.addEventListener('click', function () {
            setCollapsed(root, !root.classList.contains('is-collapsed'));
        });
    }

    function scan(scope) {
        var roots = (scope || document).querySelectorAll
            ? (scope || document).querySelectorAll('[data-testid="footer-social-float"]')
            : [];
        for (var i = 0; i < roots.length; i += 1) {
            mount(roots[i]);
        }
    }

    function boot() {
        scan(document);
    }

    global.WelineFooterSocialFloat = {
        mount: mount,
        scan: scan,
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot, { once: true });
    } else {
        boot();
    }
}(typeof window !== 'undefined' ? window : this));
