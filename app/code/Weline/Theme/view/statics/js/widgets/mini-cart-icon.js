(function () {
    'use strict';

    var guestTokenStorageKey = 'weline.cart.guest_token';

    function formatMoney(amount, currency) {
        var symbol = '¥';
        currency = String(currency || 'CNY').toUpperCase();
        if (currency === 'USD') symbol = '$';
        else if (currency === 'EUR') symbol = '€';
        else if (currency === 'GBP') symbol = '£';
        var n = Number(amount || 0);
        return symbol + n.toFixed(2);
    }

    function text(el, value) {
        if (el) el.textContent = value == null ? '' : String(value);
    }

    function guestToken() {
        try {
            return String(window.sessionStorage.getItem(guestTokenStorageKey) || '').trim();
        } catch (e) {
            return '';
        }
    }

    async function cartApi() {
        if (!window.Weline || !window.Weline.Api || typeof window.Weline.Api.resource !== 'function') {
            throw new Error('Weline.Api unavailable');
        }
        return window.Weline.Api.resource('cart');
    }

    function renderItems(root, items, currency) {
        var box = root.querySelector('[data-mini-cart-items]');
        var footer = root.querySelector('[data-mini-cart-footer]');
        if (!box) return;

        while (box.firstChild) box.removeChild(box.firstChild);

        if (!items || !items.length) {
            var empty = document.createElement('div');
            empty.className = 'mini-cart-empty';
            empty.setAttribute('data-mini-cart-empty', '1');
            var p = document.createElement('p');
            p.textContent = root.getAttribute('data-i18n-empty') || '购物车是空的';
            empty.appendChild(p);
            box.appendChild(empty);
            if (footer) footer.hidden = true;
            root.classList.add('is-empty');
            return;
        }

        root.classList.remove('is-empty');
        if (footer) footer.hidden = false;

        items.forEach(function (item) {
            if (!item || typeof item !== 'object') return;
            var row = document.createElement('div');
            row.className = 'mini-cart-item';

            var image = String(item.image || '').trim();
            if (image) {
                var img = document.createElement('img');
                img.className = 'item-image';
                img.src = image;
                img.alt = String(item.name || '');
                row.appendChild(img);
            }

            var info = document.createElement('div');
            info.className = 'item-info';
            var name = document.createElement('h4');
            name.className = 'item-name';
            name.textContent = String(item.name || '');
            var price = document.createElement('span');
            price.className = 'item-price';
            var qty = Number(item.qty || item.quantity || 1);
            price.textContent = formatMoney(item.price || 0, currency) + ' × ' + qty;
            info.appendChild(name);
            info.appendChild(price);
            row.appendChild(info);
            box.appendChild(row);
        });
    }

    function applySummary(root, summary) {
        if (!summary || typeof summary !== 'object') return;
        var count = Number(summary.cart_count || summary.item_count || 0);
        var currency = String(summary.currency || 'CNY');
        var subtotal = Number(summary.subtotal || summary.grand_total || 0);
        var formatted = formatMoney(subtotal, currency);
        var items = Array.isArray(summary.items) ? summary.items : [];

        root.setAttribute('data-cart-count', String(count));
        root.setAttribute('data-cart-subtotal', formatted);
        root.classList.toggle('is-empty', count <= 0 || !!summary.is_empty);
        root.classList.remove('is-demo');

        var badge = root.querySelector('[data-cart-count-badge]');
        if (badge) {
            badge.hidden = count <= 0;
            badge.textContent = count > 99 ? '99+' : String(count);
        }
        text(root.querySelector('[data-cart-subtotal-text]'), formatted);
        text(root.querySelector('[data-cart-item-count]'), String(count));
        text(root.querySelector('[data-cart-total-amount]'), formatted);
        renderItems(root, items.slice(0, 5), currency);
    }

    async function refreshRoot(root) {
        if (!root || root.getAttribute('data-preview-mode') === '1') {
            return;
        }
        try {
            var api = await cartApi();
            var token = guestToken();
            var result;
            if (typeof api.getV2Cart === 'function' && token) {
                result = await api.getV2Cart({ guest_token: token });
            } else if (typeof api.summary === 'function') {
                result = await api.summary({});
            } else if (typeof api.miniItems === 'function') {
                result = await api.miniItems({ limit: 5 });
            } else {
                return;
            }
            var payload = result && result.data && typeof result.data === 'object' ? result.data : result;
            if (!payload || payload.success === false) {
                return;
            }
            applySummary(root, payload);
        } catch (e) {
            // Keep server-rendered state when cart API is unavailable.
        }
    }

    function boot() {
        var roots = document.querySelectorAll('[data-w-mini-cart="1"]');
        roots.forEach(function (root) {
            if (root.getAttribute('data-w-mini-cart-init') === '1') return;
            root.setAttribute('data-w-mini-cart-init', '1');
            refreshRoot(root);
        });

        window.addEventListener('weline:cart-updated', function () {
            document.querySelectorAll('[data-w-mini-cart="1"]').forEach(refreshRoot);
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
