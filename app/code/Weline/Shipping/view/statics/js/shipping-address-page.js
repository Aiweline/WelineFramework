/**
 * 发货地址列表页交互 (Weline_Shipping).
 * Moved from the inline <script> in templates/frontend/shipping-address/index.phtml.
 * Dialog messages come from the non-executable JSON block #shipping-address-config.
 * Registered as module `shippingAddressPage`; page root declares data-weline-load.
 */
(function () {
    'use strict';
    var CONFIG = (function () {
        var el = document.getElementById('shipping-address-config');
        if (!el) { return { messages: {} }; }
        try { return JSON.parse(el.textContent || '{}'); } catch (e) { return { messages: {} }; }
    }());

document.addEventListener('DOMContentLoaded', function() {
    var shippingAddressApiPromise = null;
    const shippingAddressMessages = CONFIG.messages;

    function getShippingAddressApi() {
        if (!shippingAddressApiPromise) {
            shippingAddressApiPromise = window.Weline && window.Weline.Api
                ? window.Weline.Api.resource('shippingAddress')
                : Promise.reject(new Error('Weline.Api is unavailable.'));
        }
        return shippingAddressApiPromise;
    }

    function showOperationError(message) {
        Weline.UI.dialog.request({
            tone: 'danger',
            title: shippingAddressMessages.operationFailed,
            message: message,
            confirmLabel: shippingAddressMessages.confirmButton
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
            title.textContent = shippingAddressMessages.confirmTitle;
            title.style.cssText = 'margin:0 0 12px;font-size:18px;';

            const body = document.createElement('p');
            body.textContent = message;
            body.style.cssText = 'margin:0 0 20px;color:#475569;';

            const actions = document.createElement('div');
            actions.style.cssText = 'display:flex;justify-content:flex-end;gap:10px;';

            const cancelButton = document.createElement('button');
            cancelButton.type = 'button';
            cancelButton.className = 'btn btn-secondary';
            cancelButton.textContent = shippingAddressMessages.cancelButton;

            const confirmButton = document.createElement('button');
            confirmButton.type = 'button';
            confirmButton.className = 'btn btn-primary';
            confirmButton.textContent = shippingAddressMessages.confirmButton;

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

    function showShippingAddressChoice(message) {
        return Weline.UI.dialog.request({
            tone: 'warning',
            title: shippingAddressMessages.confirmTitle,
            message: message,
            cancelable: true,
            confirmLabel: shippingAddressMessages.confirmButton,
            cancelLabel: shippingAddressMessages.cancelButton
        }).then(function(result) {
            return !!(result && result.confirmed);
        });
    }

    function shippingAction(operation, id) {
        return getShippingAddressApi().then(function (ShippingAddressApi) {
            return ShippingAddressApi[operation]({ id: id }, {
                onError: function (_status, error) {
                    showOperationError(error && error.message ? error.message : shippingAddressMessages.operationFailed);
                }
            });
        }).then(function (data) {
            if (data.success) {
                location.reload();
                return;
            }
            showOperationError(data.message || shippingAddressMessages.operationFailed);
        }).catch(function (error) {
            showOperationError(error && error.message ? error.message : shippingAddressMessages.operationFailed);
        });
    }

    // 设置默认地址
    document.querySelectorAll('.set-default-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
            showShippingAddressChoice(shippingAddressMessages.setDefault).then(function(confirmed) {
                if (!confirmed) {
                    return;
                }
                const id = btn.getAttribute('data-id');
                shippingAction('setDefault', id);
            });
        });
    });

    // 删除地址
    document.querySelectorAll('.delete-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
            showShippingAddressChoice(shippingAddressMessages.deleteAddress).then(function(confirmed) {
                if (!confirmed) {
                    return;
                }
                const id = btn.getAttribute('data-id');
                shippingAction('delete', id);
            });
        });
    });
});
}());
