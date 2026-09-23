window.WelineWidgetAssets.register('payment-checkout-express-payment-0', function (widgetScript) {
(function () {
    // Prefer data-payment-express so inline JS does not embed presence markers
    // that presence counters (or strip failures) could misread as a second copy.
    const section = document.querySelector('[data-payment-express]');
    if (!section || section.dataset.expressBound === '1') {
        return;
    }
    section.dataset.expressBound = '1';
    section.addEventListener('click', function (event) {
        const button = event.target && event.target.closest
            ? event.target.closest('[data-express-pay]')
            : null;
        if (!button || button.disabled) {
            return;
        }
        event.preventDefault();
        const methodCode = (button.getAttribute('data-method-code')
            || section.getAttribute('data-method-code')
            || 'paypal').trim() || 'paypal';
        const methodLabel = (button.getAttribute('data-method-label')
            || button.getAttribute('aria-label')
            || ('使用 ' + methodCode + ' 快捷支付')).trim();
        try {
            const toast = window.Weline && window.Weline.UI && window.Weline.UI.toast;
            if (toast && typeof toast.show === 'function') {
                toast.show(methodLabel, { tone: 'info', duration: 2800 });
            } else if (toast && typeof toast.info === 'function') {
                toast.info(methodLabel);
            }
        } catch (e) {}
        try {
            if (window.WelinePixel && typeof window.WelinePixel.track === 'function') {
                window.WelinePixel.track('express_pay', {
                    payment_method: methodCode,
                    payment_type: methodCode,
                    method_label: methodLabel,
                    source: 'checkout_express_pay',
                    trigger: 'click',
                }, { element: button, keepalive: true });
            }
        } catch (e2) {}
        const checkoutRoot = section.closest('[data-weline-checkout]') || document.querySelector('[data-weline-checkout]');
        if (!checkoutRoot) {
            return;
        }
        checkoutRoot.dispatchEvent(new CustomEvent('weline:checkout:express-pay', {
            bubbles: true,
            detail: {
                payment_method: methodCode,
                method_label: methodLabel,
                button: button,
            },
        }));
    });
})();
});
