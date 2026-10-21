/**
 * Payment success pixel (Weline_Payment).
 * Replaces the two inline pixel scripts in checkout/payment-success.phtml and
 * checkout/return.phtml. Payload comes from the non-executable JSON block
 * #payment-pixel-config (built server-side).
 * Registered as module `paymentPixel`; page root declares data-weline-load.
 */
(function () {
    'use strict';
    var el = document.getElementById('payment-pixel-config');
    if (!el) {
        return;
    }
    var cfg = {};
    try { cfg = JSON.parse(el.textContent || '{}') || {}; } catch (e) { cfg = {}; }
    if (cfg.enabled === false) {
        return;
    }
    var payload = cfg.payload && typeof cfg.payload === 'object' ? cfg.payload : null;
    if (!payload) {
        return;
    }
    var attempts = 0;
    var maxAttempts = 80;
    function fire() {
        attempts += 1;
        if (typeof window.WelinePixel === 'undefined' || typeof window.WelinePixel.track !== 'function') {
            if (attempts < maxAttempts) {
                window.setTimeout(fire, 50);
            }
            return;
        }
        window.WelinePixel.track(cfg.event || 'payment_success', payload);
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', fire);
    } else {
        fire();
    }
}());
