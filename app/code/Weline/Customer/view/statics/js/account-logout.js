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

    function getThemeNoticeApi() {
        if (window.Weline && window.Weline.Theme && window.Weline.Theme.Notice
            && typeof window.Weline.Theme.Notice.confirm === 'function') {
            return window.Weline.Theme.Notice;
        }
        if (window.Theme && window.Theme.Notice && typeof window.Theme.Notice.confirm === 'function') {
            return window.Theme.Notice;
        }
        if (window.Weline && window.Weline.Notice && typeof window.Weline.Notice.confirm === 'function') {
            return window.Weline.Notice;
        }
        if (window.Toast && typeof window.Toast.confirm === 'function') {
            return window.Toast;
        }
        return null;
    }

    function getThemeUiDialogConfirm() {
        if (window.Weline && window.Weline.UI && window.Weline.UI.dialog
            && typeof window.Weline.UI.dialog.confirm === 'function') {
            return window.Weline.UI.dialog.confirm.bind(window.Weline.UI.dialog);
        }
        return null;
    }

    function waitForThemeConfirm(timeoutMs) {
        var deadline = Date.now() + (timeoutMs || 3000);
        return new Promise(function (resolve) {
            function tick() {
                if (getThemeNoticeApi() || getThemeUiDialogConfirm()) {
                    resolve(true);
                    return;
                }
                if (Date.now() >= deadline) {
                    resolve(false);
                    return;
                }
                window.setTimeout(tick, 50);
            }
            tick();
        });
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

    function confirmWithThemeUi(config) {
        var uiConfirm = getThemeUiDialogConfirm();
        if (!uiConfirm) {
            return Promise.resolve(null);
        }

        return Promise.resolve(uiConfirm(config.message, {
            title: config.title,
            confirmLabel: config.confirmText,
            cancelLabel: config.cancelText,
            tone: 'danger',
            confirmTone: 'danger',
            cancelable: true
        })).then(function (confirmed) {
            return !!confirmed;
        });
    }

    function confirmLogout(config) {
        var options = {
            title: config.title || '退出登录',
            message: config.message || '确定要退出登录吗？',
            confirmText: config.confirmText || '确认退出',
            cancelText: config.cancelText || '取消'
        };

        var notice = getThemeNoticeApi();
        if (notice) {
            return Promise.resolve(notice.confirm(options)).then(function (confirmed) {
                return !!confirmed;
            });
        }

        return confirmWithThemeUi(options).then(function (confirmed) {
            if (confirmed !== null) {
                return confirmed;
            }

            // Theme UI may still be mounting; wait briefly, never use window.confirm.
            return waitForThemeConfirm(3000).then(function (ready) {
                if (!ready) {
                    console.warn('[Weline Account] Theme confirm dialog is unavailable; logout cancelled.');
                    return false;
                }

                notice = getThemeNoticeApi();
                if (notice) {
                    return Promise.resolve(notice.confirm(options)).then(function (ok) {
                        return !!ok;
                    });
                }

                return confirmWithThemeUi(options).then(function (ok) {
                    return ok === null ? false : !!ok;
                });
            });
        });
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

    window.WelineCustomerLogout = {
        __full: true,
        init: initAccountLogout,
    };
})();
