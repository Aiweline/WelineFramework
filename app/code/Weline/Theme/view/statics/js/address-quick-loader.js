(function (window, document) {
    'use strict';

    var moduleName = 'themeAddressQuick';
    var modulePath = 'Weline_Theme::js/address-quick.js?v=20260924-no-dev-fallback1';

    function resolveQuickUrl() {
        var loader = window.Weline && window.Weline.loader;
        if (loader && typeof loader.resolveStaticPath === 'function') {
            var resolved = loader.resolveStaticPath(modulePath);
            if (resolved) {
                return resolved;
            }
        }
        var cur = document.currentScript && document.currentScript.src;
        if (cur) {
            var sibling = cur.replace(/\/address-quick-loader\.js(\?.*)?$/i, '/address-quick.js$1');
            if (sibling !== cur) {
                return sibling;
            }
        }
        return '';
    }

    function bootLoadedModule() {
        if (window.WelineThemeAddressQuick && typeof window.WelineThemeAddressQuick.boot === 'function') {
            window.WelineThemeAddressQuick.boot();
        }
    }

    function directLoad() {
        if (window.WelineThemeAddressQuick) {
            bootLoadedModule();
            return;
        }
        var existing = document.querySelector('script[data-w-address-quick-src]');
        if (existing) {
            existing.addEventListener('load', bootLoadedModule);
            return;
        }
        var url = resolveQuickUrl();
        if (!url) {
            return;
        }
        var s = document.createElement('script');
        s.src = url;
        s.defer = true;
        s.setAttribute('data-w-address-quick-src', '1');
        s.addEventListener('load', bootLoadedModule);
        document.head.appendChild(s);
    }

    function viaWeline() {
        if (!window.Weline || typeof window.Weline.require !== 'function') {
            directLoad();
            return;
        }
        try {
            window.Weline.require([modulePath], function () {
                bootLoadedModule();
            }, function () {
                directLoad();
            });
        } catch (e) {
            directLoad();
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', viaWeline);
    } else {
        viaWeline();
    }
})(window, document);
