/**
 * Weline.Api fail-closed placeholder.
 *
 * The full module replaces this object after loading. Until the worker-backed
 * implementation is available, browser business API requests must fail instead
 * of falling back to direct fetch.
 */
(function (window) {
    'use strict';
    if (window.WelineApiModule && window.WelineApiModule.__full) return;

    function disabled() {
        return Promise.reject(new Error('[Weline.Api] worker unavailable; direct frontend API requests are disabled.'));
    }

    window.WelineApiModule = {
        __full: false,
        __fallback: true,
        request: disabled,
        get: disabled,
        post: disabled,
        call: disabled,
        graph: disabled,
        stream: disabled,
        upload: disabled,
        resource: disabled,
        markCartActive: function () {},
        markCartEmpty: function () {},
        enableAutoRequests: function () {},
        disableAutoRequests: function () {},
        getClient: function () { return null; }
    };

    window.Weline = window.Weline || {};
    window.Weline.Api = window.Weline.Api || window.WelineApiModule;
})(window);
