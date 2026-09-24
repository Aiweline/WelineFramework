(function (window, document) {
    'use strict';

    var moduleName = 'themeAddress';
    // Bust token lives on Module:: path so PROD resolveStaticPath / flat publish picks it up.
    var modulePath = 'Weline_Theme::js/address.js?v=20260924-no-dev-fallback1';

    function resolveAddressUrl() {
        var loader = window.Weline && window.Weline.loader;
        if (loader && typeof loader.resolveStaticPath === 'function') {
            var resolved = loader.resolveStaticPath(modulePath);
            if (resolved) {
                return resolved;
            }
        }
        // Sibling of this loader script (same published tree) — never DEV /Weline/*/view/statics/.
        var cur = document.currentScript && document.currentScript.src;
        if (cur) {
            var sibling = cur.replace(/\/address-loader\.js(\?.*)?$/i, '/address.js$1');
            if (sibling !== cur) {
                return sibling;
            }
        }
        return '';
    }

    function bootLoadedModule() {
        if (window.WelineThemeAddress && typeof window.WelineThemeAddress.boot === 'function') {
            window.WelineThemeAddress.boot();
        }
    }

    function directLoad() {
        if (window.WelineThemeAddress) {
            bootLoadedModule();
            return;
        }
        if (window.WelineThemeAddressLoading) {
            return;
        }
        var url = resolveAddressUrl();
        if (!url) {
            return;
        }
        window.WelineThemeAddressLoading = true;
        var script = document.createElement('script');
        script.src = url;
        script.async = true;
        script.onload = bootLoadedModule;
        script.onerror = function () {
            window.WelineThemeAddressLoading = false;
        };
        document.head.appendChild(script);
    }

    function declareModule() {
        if (!window.Weline || typeof window.Weline.declare !== 'function') {
            return false;
        }
        window.Weline.declare(moduleName, true, modulePath, bootLoadedModule);
        return true;
    }

    if (window.WelineThemeAddress && typeof window.WelineThemeAddress.boot === 'function') {
        bootLoadedModule();
        return;
    }

    if (window.WelineThemeAddressDeclared) {
        // Previous loader may have declared but failed to materialize the module (common on backend).
        setTimeout(function () {
            if (!window.WelineThemeAddress) {
                directLoad();
            }
        }, 300);
        return;
    }
    window.WelineThemeAddressDeclared = true;

    var attempts = 0;
    (function waitForThemeLoader() {
        if (declareModule()) {
            // Weline.declare can acknowledge without ever loading the file on some backends.
            setTimeout(function () {
                if (!window.WelineThemeAddress) {
                    directLoad();
                }
            }, 1200);
            return;
        }
        attempts += 1;
        if (attempts > 80) {
            directLoad();
            return;
        }
        setTimeout(waitForThemeLoader, 25);
    })();
})(window, document);
