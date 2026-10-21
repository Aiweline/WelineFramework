/**
 * Dev Relay 配置页初始化：读取 #payment-dev-relay-config 并调用 dev-relay.js 的 initIndex。
 * 由 data-weline-load="paymentDevRelayInit" 声明加载。
 */
(function () {
    'use strict';

    function start() {
        var configEl = document.getElementById('payment-dev-relay-config');
        if (!configEl) {
            return;
        }
        var config = {};
        try {
            config = JSON.parse(configEl.textContent || '{}');
        } catch (e) {
            config = {};
        }
        var run = function () {
            if (window.WelinePaymentDevRelay && typeof window.WelinePaymentDevRelay.initIndex === 'function') {
                window.WelinePaymentDevRelay.initIndex(config);
            }
        };
        if (window.WelinePaymentDevRelay) {
            run();
            return;
        }
        if (window.Weline && typeof window.Weline.use === 'function') {
            window.Weline.use('paymentDevRelay').then(run).catch(function () {});
        } else {
            run();
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start);
    } else {
        start();
    }
})();
