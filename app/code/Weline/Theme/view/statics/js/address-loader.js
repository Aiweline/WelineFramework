(function (window, document) {
    'use strict';

    var moduleName = 'themeAddress';
    var modulePath = 'Weline_Theme::js/address.js?v=20260513-address-module-5';
    var fallbackUrl = '/Weline/Theme/view/statics/js/address.js?v=20260513-address-module-5';

    function bootLoadedModule() {
        if (window.WelineThemeAddress && typeof window.WelineThemeAddress.boot === 'function') {
            window.WelineThemeAddress.boot();
        }
    }

    function directLoad() {
        if (window.WelineThemeAddressLoading) {
            return;
        }
        window.WelineThemeAddressLoading = true;
        var script = document.createElement('script');
        script.src = fallbackUrl;
        script.async = true;
        script.onload = bootLoadedModule;
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
        return;
    }
    window.WelineThemeAddressDeclared = true;

    var attempts = 0;
    (function waitForThemeLoader() {
        if (declareModule()) {
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
