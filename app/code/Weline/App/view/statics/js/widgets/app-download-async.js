(function (window, document) {
    'use strict';
    // runtime 可能晚于本文件加载（同为声明式 js）：轮询等待就绪再 register
    var waited = 0;
    (function waitRuntime() {
        if (window.WelineWidgetAssets) { register(); return; }
        if ((waited += 100) < 10000) { setTimeout(waitRuntime, 100); }
    })();
    function register() {
        window.WelineWidgetAssets.register('app-download-async', function (anchor) {
            var root = (anchor.closest && anchor.closest('[data-app-download]')) || anchor.parentElement;
            if (!root) { return; }
            bind(root);
            registerPwa(root);
            var run = function () { fill(root); };
            if (window.requestIdleCallback) window.requestIdleCallback(run, { timeout: 2000 });
            else setTimeout(run, 300);
        });
    }
    function fill(root) {
        var api = (window.WELINE_APP_BASE || '') + '/api/weline_app/rest/v1/version/version';
        ['android', 'ios', 'web'].forEach(function (platform) {
            fetch(api + '?platform=' + platform, { headers: { 'Accept': 'application/json' } })
                .then(function (r) { return r.ok ? r.json() : null; })
                .then(function (j) {
                    var d = j && (j.data || j);
                    var els = root.querySelectorAll('[data-app-download-version="' + platform + '"]');
                    if (d && d.version) {
                        els.forEach(function (el) { el.textContent = d.version; });
                        var dl = root.querySelector('[data-app-download-link="' + platform + '"]');
                        if (dl && d.url) dl.setAttribute('href', d.url);
                    }
                    els.forEach(function (el) { if (el.hidden) { el.hidden = false; } });
                    root.setAttribute('data-app-download-loaded', platform);
                })
                .catch(function () {});
        });
    }
    // —— PWA：注册 service worker + 「安装到桌面」（Chrome beforeinstallprompt）——
    var deferredPrompt = null;
    function pwaButtons(root) {
        return Array.prototype.slice.call(
            (root || document).querySelectorAll('[data-pwa-install]')
        );
    }
    function registerPwa(root) {
        if (!window.__welinePwaBound) {
            window.__welinePwaBound = true;
            window.addEventListener('beforeinstallprompt', function (e) {
                e.preventDefault();
                deferredPrompt = e;
                pwaButtons(document).forEach(function (b) { b.hidden = false; });
            });
            document.addEventListener('click', function (e) {
                var el = e.target instanceof Element ? e.target.closest('[data-pwa-install]') : null;
                if (!el || !deferredPrompt) { return; }
                e.preventDefault();
                deferredPrompt.prompt();
                deferredPrompt.userChoice.then(function () {
                    deferredPrompt = null;
                    pwaButtons(document).forEach(function (b) { b.hidden = true; });
                });
            });
            if ('serviceWorker' in navigator) {
                var reg = function () {
                    navigator.serviceWorker.register('/sw.js').catch(function () {});
                };
                if (window.requestIdleCallback) window.requestIdleCallback(reg, { timeout: 3000 });
                else setTimeout(reg, 1500);
            }
        }
        if (deferredPrompt) { pwaButtons(root).forEach(function (b) { b.hidden = false; }); }
    }

    // —— 二维码弹窗：点「下载 App」不跳转，开弹窗；主出口是下载中心 ——
    var lastFocus = null;
    function bind(root) {
        if (root.getAttribute('data-app-download-bound') === '1') { return; }
        root.setAttribute('data-app-download-bound', '1');
        root.addEventListener('click', function (e) {
            var el = e.target instanceof Element ? e.target : null;
            if (!el) { return; }
            var opener = el.closest('[data-app-download-open]');
            if (opener) {
                e.preventDefault();
                open(root, opener);
                return;
            }
            if (el.closest('[data-app-download-close]')) {
                e.preventDefault();
                close(root);
            }
        });
        root.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') { close(root); return; }
            if (e.key === 'Tab') { trap(root, e); }
        });
    }
    function modalOf(root) {
        return root.querySelector('[data-app-download-modal]');
    }
    function open(root, opener) {
        var modal = modalOf(root);
        if (!modal) { return; }
        lastFocus = opener;
        modal.hidden = false;
        modal.setAttribute('data-open', '1');
        document.documentElement.style.overflow = 'hidden';
        var closeBtn = modal.querySelector('.app-download__close');
        if (closeBtn) { closeBtn.focus(); }
    }
    function close(root) {
        var modal = modalOf(root);
        if (!modal || modal.hidden) { return; }
        modal.hidden = true;
        modal.removeAttribute('data-open');
        document.documentElement.style.overflow = '';
        if (lastFocus && lastFocus.isConnected) { lastFocus.focus(); }
        lastFocus = null;
    }
    function trap(root, e) {
        var modal = modalOf(root);
        if (!modal || modal.hidden) { return; }
        var focusables = modal.querySelectorAll('a[href], button:not([disabled])');
        if (!focusables.length) { return; }
        var first = focusables[0];
        var last = focusables[focusables.length - 1];
        if (e.shiftKey && document.activeElement === first) {
            e.preventDefault();
            last.focus();
        } else if (!e.shiftKey && document.activeElement === last) {
            e.preventDefault();
            first.focus();
        }
    }
})(window, document);
