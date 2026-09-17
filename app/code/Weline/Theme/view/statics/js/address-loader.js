(function (window, document) {
    'use strict';

    var moduleName = 'themeAddress';
    var modulePath = 'Weline_Theme::js/address.js';
    // Keep an explicit bust token so country-only/global fixes are not stuck behind a sticky inherited query.
    var fallbackUrl = '/Weline/Theme/view/statics/js/address.js?v=20260917-open-load1';

    (function inheritLoaderVersion() {
        var cur = document.currentScript && document.currentScript.src;
        if (!cur) return;
        var qPos = cur.indexOf('?');
        if (qPos === -1) return;
        var q = cur.slice(qPos);
        // Prefer the newer explicit bust above; only inherit when fallback has no query yet.
        if (fallbackUrl.indexOf('?') === -1) {
            fallbackUrl += q;
        }
    })();


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
        window.WelineThemeAddressLoading = true;
        var script = document.createElement('script');
        script.src = fallbackUrl;
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
    // 禁运打标修复期：强制走带 bust 的直链，避免 sticky _weline_dev 长期命中旧闭包。
    directLoad();
    return;

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
