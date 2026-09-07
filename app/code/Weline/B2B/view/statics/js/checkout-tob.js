(function (global) {
    'use strict';

    function readMode() {
        if (global.WelineB2BSellingMode && typeof global.WelineB2BSellingMode.preferredMode === 'function') {
            return global.WelineB2BSellingMode.preferredMode();
        }
        var match = document.cookie.match(/(?:^|; )weline_selling_mode=([^;]*)/);
        var value = match ? decodeURIComponent(match[1]).toLowerCase() : '';
        return value === 'tob' ? 'tob' : 'toc';
    }

    function applyCartType(root, cartType) {
        if (!root) {
            return;
        }
        var type = String(cartType || readMode() || 'toc').toLowerCase() === 'tob' ? 'tob' : 'toc';
        root.setAttribute('data-cart-type', type);
        var note = root.querySelector('[data-b2b-deposit-note]');
        if (note) {
            note.hidden = type !== 'tob';
        }
        var couponSlot = root.querySelector('.weline-checkout__coupon-slot, [data-wslot-name]');
        var extras = root.querySelector('.weline-checkout__extras');
        if (type === 'tob') {
            if (couponSlot) {
                couponSlot.hidden = true;
            }
            if (extras) {
                extras.classList.add('is-tob-discounts-banned');
            }
        } else {
            if (couponSlot) {
                couponSlot.hidden = false;
            }
            if (extras) {
                extras.classList.remove('is-tob-discounts-banned');
            }
        }
    }

    function bindCheckout() {
        var root = document.querySelector('[data-weline-checkout]');
        if (!root) {
            return;
        }
        applyCartType(root, readMode());
        global.addEventListener('weline:selling-mode-changed', function (event) {
            var mode = event && event.detail ? event.detail.cart_type || event.detail.selling_mode : readMode();
            applyCartType(root, mode);
        });
        global.addEventListener('weline:cart-updated', function (event) {
            var summary = event && event.detail ? event.detail : null;
            if (summary && summary.cart_type) {
                applyCartType(root, summary.cart_type);
            }
        });
        global.WelineB2BCheckoutTob = { applyCartType: applyCartType, readMode: readMode };
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', bindCheckout);
    } else {
        bindCheckout();
    }
})(window);
