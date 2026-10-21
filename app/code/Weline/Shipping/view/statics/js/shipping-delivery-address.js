/**
 * 收货地址列表页交互 (Weline_Shipping).
 * Moved from the inline <script> in templates/frontend/delivery-address/index.phtml.
 * Dialog messages come from the non-executable JSON block #shipping-delivery-address-config.
 * Registered as module `shippingDeliveryAddressPage`; page root declares data-weline-load.
 */
(function () {
    'use strict';
    var CONFIG = (function () {
        var el = document.getElementById('shipping-delivery-address-config');
        if (!el) { return { messages: {} }; }
        try { return JSON.parse(el.textContent || '{}'); } catch (e) { return { messages: {} }; }
    }());

document.addEventListener('DOMContentLoaded', function() {
    let deliveryAddressApiPromise = null;
    const deliveryAddressMessages = CONFIG.messages;

    function getDeliveryAddressApi() {
        if (!deliveryAddressApiPromise) {
            deliveryAddressApiPromise = (async function() {
                if (window.Weline && typeof window.Weline.load === 'function') {
                    await window.Weline.load('api');
                }
                if (!window.Weline || !window.Weline.Api || typeof window.Weline.Api.resource !== 'function') {
                    throw new Error('Weline.Api.resource is unavailable.');
                }
                return window.Weline.Api.resource('deliveryAddress');
            })();
        }
        return deliveryAddressApiPromise;
    }

    function showAddressError(message) {
        Weline.UI.dialog.request({
            tone: 'danger',
            title: deliveryAddressMessages.operationFailed,
            message: message || deliveryAddressMessages.operationFailed,
            confirmLabel: deliveryAddressMessages.confirmButton
        });
    }

    function showInlineChoice(message) {
        return new Promise(function(resolve) {
            const overlay = document.createElement('div');
            overlay.style.cssText = 'position:fixed;inset:0;z-index:1055;background:rgba(15,23,42,.45);display:flex;align-items:center;justify-content:center;padding:20px;';

            const dialog = document.createElement('div');
            dialog.setAttribute('role', 'dialog');
            dialog.setAttribute('aria-modal', 'true');
            dialog.style.cssText = 'width:min(420px,100%);background:#fff;border-radius:8px;box-shadow:0 20px 60px rgba(15,23,42,.25);padding:24px;';

            const title = document.createElement('h4');
            title.textContent = deliveryAddressMessages.confirmTitle;
            title.style.cssText = 'margin:0 0 12px;font-size:18px;';

            const body = document.createElement('p');
            body.textContent = message;
            body.style.cssText = 'margin:0 0 20px;color:#475569;';

            const actions = document.createElement('div');
            actions.style.cssText = 'display:flex;justify-content:flex-end;gap:10px;';

            const cancelButton = document.createElement('button');
            cancelButton.type = 'button';
            cancelButton.className = 'btn btn-secondary';
            cancelButton.textContent = deliveryAddressMessages.cancelButton;

            const confirmButton = document.createElement('button');
            confirmButton.type = 'button';
            confirmButton.className = 'btn btn-primary';
            confirmButton.textContent = deliveryAddressMessages.confirmButton;

            function close(value) {
                document.removeEventListener('keydown', onKeydown);
                overlay.remove();
                resolve(value);
            }

            function onKeydown(event) {
                if (event.key === 'Escape') {
                    close(false);
                }
            }

            cancelButton.addEventListener('click', function() { close(false); });
            confirmButton.addEventListener('click', function() { close(true); });
            overlay.addEventListener('click', function(event) {
                if (event.target === overlay) {
                    close(false);
                }
            });

            actions.append(cancelButton, confirmButton);
            dialog.append(title, body, actions);
            overlay.appendChild(dialog);
            document.body.appendChild(overlay);
            document.addEventListener('keydown', onKeydown);
            confirmButton.focus();
        });
    }

    function showDeliveryAddressChoice(message) {
        return Weline.UI.dialog.request({
            tone: 'warning',
            title: deliveryAddressMessages.confirmTitle,
            message: message,
            cancelable: true,
            confirmLabel: deliveryAddressMessages.confirmButton,
            cancelLabel: deliveryAddressMessages.cancelButton
        }).then(function(result) {
            return !!(result && result.confirmed);
        });
    }

    // 设置默认地址
    document.querySelectorAll('.set-default-btn').forEach(function(btn) {
        btn.addEventListener('click', async function() {
            const confirmed = await showDeliveryAddressChoice(deliveryAddressMessages.setDefault);
            if (!confirmed) {
                return;
            }
            const id = Number(this.getAttribute('data-id'));
            try {
                const api = await getDeliveryAddressApi();
                const data = await api.setDefault({ id: id }, { silent: true });
                if (data.success) {
                    location.reload();
                } else {
                    showAddressError(data.message);
                }
            } catch (error) {
                showAddressError(error && error.message ? error.message : String(error));
            }
        });
    });

    // 删除地址
    document.querySelectorAll('.delete-btn').forEach(function(btn) {
        btn.addEventListener('click', async function() {
            const confirmed = await showDeliveryAddressChoice(deliveryAddressMessages.deleteAddress);
            if (!confirmed) {
                return;
            }
            const id = Number(this.getAttribute('data-id'));
            try {
                const api = await getDeliveryAddressApi();
                const data = await api.delete({ id: id }, { silent: true });
                if (data.success) {
                    location.reload();
                } else {
                    showAddressError(data.message);
                }
            } catch (error) {
                showAddressError(error && error.message ? error.message : String(error));
            }
        });
    });
});
}());
