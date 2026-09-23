window.WelineWidgetAssets.register('customerservice-footer-contact-service-link-0', function (widgetScript) {
(function () {
    'use strict';
    var ns = JSON.parse(widgetScript.dataset.v0 || 'null');
    if (window[ns] && window[ns].bound) {
        return;
    }
    window[ns] = window[ns] || {};
    window[ns].bound = true;

    function openCustomerServiceChat(event) {
        var trigger = event.target && event.target.closest
            ? event.target.closest('[data-cs-footer-open-chat]')
            : null;
        if (!trigger) {
            return;
        }
        event.preventDefault();
        var widget = document.getElementById('customer-service-widget');
        if (widget && widget.classList.contains('is-open')) {
            return;
        }
        if (typeof window.__WelineLoadCustomerServiceWidget === 'function') {
            window.__WelineLoadCustomerServiceWidget(true);
            return;
        }
        if (typeof CustomerServiceWidget !== 'undefined'
            && typeof CustomerServiceWidget.toggleChat === 'function') {
            CustomerServiceWidget.toggleChat();
        }
    }

    document.addEventListener('click', openCustomerServiceChat);
})();
});
