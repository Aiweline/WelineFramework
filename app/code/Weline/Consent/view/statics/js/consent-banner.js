/**
 * Cookie consent banner (Weline_Consent).
 * Registered as module `consentBanner` in view/statics/frontend/weline.modules.js;
 * host element declares data-weline-load="consentBanner".
 */
(function () {
    'use strict';
    var root = document.getElementById('weline-consent-banner');
    if (!root) {
        return;
    }
    function hide() { root.hidden = true; }
    function show() { root.hidden = false; }
    function runWithApi(run, done) {
        var failed = function () {
            root.setAttribute('data-consent-error', '1');
            show();
        };
        var resolveApi = function () {
            if (window.Weline && typeof Weline.load === 'function') {
                return Weline.load('api');
            }
            if (window.Weline && Weline.Api && Weline.Api.__fallback !== true && typeof Weline.Api.resource === 'function') {
                return Promise.resolve(Weline.Api);
            }
            return Promise.reject(new Error('Weline.Api unavailable'));
        };
        resolveApi().then(function (api) {
            return Promise.resolve(api.resource('consent')).then(run);
        }).then(done).catch(failed);
    }
    root.querySelector('[data-consent-dismiss]')?.addEventListener('click', hide);
    root.querySelector('[data-consent-accept]')?.addEventListener('click', function () {
        runWithApi(function (consent) {
            return consent.accept({ categories: ['analytics', 'marketing'] });
        }, function (result) {
            if (result && result.success === true && result.show_banner === false) {
                hide();
                return;
            }
            show();
        });
    });
    runWithApi(function (consent) {
        return consent.status({});
    }, function (result) {
        if (result && result.recording_enabled !== false && result.show_banner === true) {
            show();
            return;
        }
        hide();
    });
    window.WelineConsentBanner = window.WelineConsentBanner || { ready: true };
}());
