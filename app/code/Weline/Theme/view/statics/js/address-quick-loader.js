(function (window, document) {
    'use strict';

    var moduleName = 'themeAddressQuick';
    var modulePath = 'Weline_Theme::js/address-quick.js';
    var fallbackUrl = '/Weline/Theme/view/statics/js/address-quick.js?v=20260911-aq1';

    (function inheritLoaderVersion() {
        var cur = document.currentScript && document.currentScript.src;
        if (!cur) return;
        var qPos = cur.indexOf('?');
        if (qPos === -1) return;
        var q = cur.slice(qPos);
        if (fallbackUrl.indexOf('?') === -1) {
            fallbackUrl += q;
        }
    })();

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
        var s = document.createElement('script');
        s.src = fallbackUrl;
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
