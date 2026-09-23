window.WelineWidgetAssets.register('customer-checkout-success-guest-convert-0', function (widgetScript) {
(function () {
    var root = widgetScript && widgetScript.previousElementSibling;
    if (!root || !root.matches || !root.matches('[data-testid="checkout-success-guest-convert"]')) {
        root = document.querySelector(('[data-testid="checkout-success-guest-convert"][data-uid="' + widgetScript.dataset.v0 + '"]'));
    }
    if (!root) return;
    var button = root.querySelector('[data-guest-convert-submit]');
    var status = root.querySelector('[data-guest-convert-status]');
    if (!button) return;
    button.addEventListener('click', async function () {
        button.disabled = true;
        if (status) { status.hidden = true; status.textContent = ''; }
        try {
            if ((!window.Weline || !window.Weline.Api) && window.Weline && typeof window.Weline.load === 'function') {
                await window.Weline.load('api');
            }
            if (!window.Weline || !window.Weline.Api || typeof window.Weline.Api.resource !== 'function') {
                throw new Error(JSON.parse(widgetScript.dataset.v1 || 'null'));
            }
            var api = await window.Weline.Api.resource('account');
            if (!api || typeof api.convertGuestCheckout !== 'function') {
                throw new Error(JSON.parse(widgetScript.dataset.v2 || 'null'));
            }
            var result = await api.convertGuestCheckout({
                checkout_token: root.getAttribute('data-checkout-token') || '',
                order_uuid: root.getAttribute('data-order-uuid') || ''
            }, {silent: true});
            var outcome = (result && (result.outcome || (result.data && result.data.outcome))) || '';
            var redirect = (result && (result.redirect || (result.data && result.data.redirect))) || '';
            if (redirect && (outcome === 'converted' || outcome === 'login_required' || outcome === 'password_required')) {
                window.location.href = redirect;
                return;
            }
            throw new Error((result && result.message) || JSON.parse(widgetScript.dataset.v3 || 'null'));
        } catch (error) {
            if (status) {
                status.hidden = false;
                status.textContent = (error && error.message) ? error.message : JSON.parse(widgetScript.dataset.v4 || 'null');
            }
            button.disabled = false;
        }
    });
})();
});
