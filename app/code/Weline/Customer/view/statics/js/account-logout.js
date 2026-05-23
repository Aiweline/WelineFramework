(function () {
    'use strict';

    if (window.__welineAccountLogoutBound) {
        return;
    }
    window.__welineAccountLogoutBound = true;

    function readLogoutConfig() {
        var el = document.getElementById('weline-account-logout-config');
        if (!el) {
            return {};
        }

        try {
            return JSON.parse(el.textContent || '{}') || {};
        } catch (error) {
            return {};
        }
    }

    function getNoticeApi() {
        return window.Weline
            && window.Weline.Theme
            && window.Weline.Theme.Notice
            ? window.Weline.Theme.Notice
            : null;
    }

    function isLogoutLink(link) {
        if (!(link instanceof HTMLAnchorElement)) {
            return false;
        }

        if (link.hasAttribute('data-account-logout-bound')) {
            return false;
        }

        if (link.matches('[data-account-logout-confirm], .account-sidebar__nav-link--logout, .account-index__logout, .motor-account-sidebar__link--logout')) {
            return true;
        }

        var href = String(link.getAttribute('href') || '');
        return href.indexOf('customer/account/logout') !== -1;
    }

    function confirmLogout(config) {
        var notice = getNoticeApi();
        var options = {
            title: config.title || '退出登录',
            message: config.message || '确定要退出登录吗？',
            confirmText: config.confirmText || '确认退出',
            cancelText: config.cancelText || '取消'
        };

        if (notice && typeof notice.confirm === 'function') {
            return notice.confirm(options);
        }

        return Promise.resolve(window.confirm(options.message));
    }

    function bindLogoutLink(link, config) {
        if (!isLogoutLink(link)) {
            return;
        }

        link.setAttribute('data-account-logout-bound', 'true');
        link.addEventListener('click', function (event) {
            event.preventDefault();

            var targetUrl = link.href;
            if (!targetUrl) {
                return;
            }

            confirmLogout(config).then(function (confirmed) {
                if (confirmed) {
                    window.location.assign(targetUrl);
                }
            });
        });
    }

    function scanLogoutLinks(config, root) {
        var scope = root && root.querySelectorAll ? root : document;
        scope.querySelectorAll('a[href*="customer/account/logout"], [data-account-logout-confirm]').forEach(function (link) {
            bindLogoutLink(link, config);
        });
    }

    function initAccountLogout() {
        var config = readLogoutConfig();
        scanLogoutLinks(config, document);

        if (typeof MutationObserver === 'function') {
            var observer = new MutationObserver(function (mutations) {
                mutations.forEach(function (mutation) {
                    mutation.addedNodes.forEach(function (node) {
                        if (!(node instanceof Element)) {
                            return;
                        }

                        if (node.matches && node.matches('a[href*="customer/account/logout"], [data-account-logout-confirm]')) {
                            bindLogoutLink(node, config);
                            return;
                        }

                        scanLogoutLinks(config, node);
                    });
                });
            });

            observer.observe(document.documentElement, { childList: true, subtree: true });
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initAccountLogout);
    } else {
        initAccountLogout();
    }
})();
