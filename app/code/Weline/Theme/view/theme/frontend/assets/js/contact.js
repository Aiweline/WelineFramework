(function () {
    'use strict';

    document.addEventListener('click', function (event) {
        var trigger = event.target.closest('[data-customer-contact-chat]');
        var widgetButton;

        if (!trigger) {
            return;
        }

        event.preventDefault();

        if (typeof CustomerServiceWidget !== 'undefined'
            && CustomerServiceWidget
            && typeof CustomerServiceWidget.toggleChat === 'function'
        ) {
            CustomerServiceWidget.toggleChat();
            return;
        }

        widgetButton = document.getElementById('cs-chat-button');
        if (widgetButton) {
            widgetButton.click();
        }
    });
}());
