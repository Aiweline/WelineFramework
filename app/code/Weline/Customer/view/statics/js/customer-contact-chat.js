/**
 * Contact page "chat with us" trigger (Weline_Customer).
 * Registered as module `customerContactChat`; triggers declare
 * data-weline-load="customerContactChat" and data-customer-contact-chat.
 *
 * Delegated on document so dynamically injected triggers work; guarded against
 * double binding when the module is loaded more than once.
 */
(function () {
    'use strict';
    if (window.WelineCustomerContactChat && window.WelineCustomerContactChat.bound === true) {
        return;
    }
    document.addEventListener('click', function (event) {
        var trigger = event.target && event.target.closest ? event.target.closest('[data-customer-contact-chat]') : null;
        if (!trigger) {
            return;
        }
        event.preventDefault();
        if (typeof CustomerServiceWidget !== 'undefined' && typeof CustomerServiceWidget.toggleChat === 'function') {
            CustomerServiceWidget.toggleChat();
            return;
        }
        var widgetButton = document.getElementById('cs-chat-button');
        if (widgetButton) {
            widgetButton.click();
        }
    });
    window.WelineCustomerContactChat = { ready: true, bound: true };
}());
