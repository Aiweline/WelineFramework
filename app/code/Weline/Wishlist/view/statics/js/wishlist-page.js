/**
 * Wishlist storefront page interactions (module: wishlist).
 */
(function (global) {
    'use strict';

    function toast(message, tone) {
        if (global.Weline && global.Weline.UI && global.Weline.UI.toast && typeof global.Weline.UI.toast.show === 'function') {
            global.Weline.UI.toast.show(message, { tone: tone || 'info' });
        }
    }

    function pageRoot() {
        return document.querySelector('[data-testid="storefront-wishlist-page"]');
    }

    function i18n(key, fallback) {
        const root = pageRoot();
        if (!root) {
            return fallback;
        }
        const value = root.getAttribute(key);
        return value && value !== '' ? value : fallback;
    }

    function boot() {
        const root = pageRoot();
        if (!root || root.getAttribute('data-wishlist-booted') === '1') {
            return;
        }
        root.setAttribute('data-wishlist-booted', '1');

        root.querySelectorAll('.btn-wishlist').forEach(function (button) {
            button.classList.add('is-active');
            button.setAttribute('aria-pressed', 'true');
        });

        document.addEventListener('click', function (event) {
            const heart = event.target && event.target.closest
                ? event.target.closest('.storefront-wishlist .btn-wishlist.is-active')
                : null;
            if (!heart) {
                return;
            }
            event.preventDefault();
            event.stopImmediatePropagation();
            const heartId = Number(heart.getAttribute('data-product-id') || 0);
            if (heartId <= 0 || !global.Weline || !global.Weline.Api) {
                return;
            }
            global.Weline.Api.resource('wishlist').then(function (api) {
                return api.remove({ product_id: heartId });
            }).then(function () {
                global.location.reload();
            }).catch(function () {
                toast(i18n('data-i18n-remove-failed', 'Remove failed'), 'error');
            });
        }, true);

        document.addEventListener('click', function (event) {
            const removeButton = event.target && event.target.closest
                ? event.target.closest('[data-wishlist-remove]')
                : null;
            if (removeButton) {
                event.preventDefault();
                const removeId = Number(removeButton.getAttribute('data-product-id') || 0);
                if (removeId <= 0 || !global.Weline || !global.Weline.Api) {
                    return;
                }
                global.Weline.Api.resource('wishlist').then(function (api) {
                    return api.remove({ product_id: removeId });
                }).then(function () {
                    global.location.reload();
                }).catch(function () {
                    toast(i18n('data-i18n-remove-failed', 'Remove failed'), 'error');
                });
                return;
            }

            const cartButton = event.target && event.target.closest
                ? event.target.closest('[data-wishlist-add-cart]')
                : null;
            if (!cartButton) {
                return;
            }
            event.preventDefault();
            const productId = Number(cartButton.getAttribute('data-product-id') || 0);
            if (productId <= 0 || !global.Weline || !global.Weline.Api) {
                return;
            }
            global.Weline.Api.resource('cart').then(function (api) {
                return api.add({ product_id: productId, qty: 1 }, { silent: true });
            }).then(function () {
                toast(i18n('data-i18n-added-cart', 'Added to cart'), 'success');
            }).catch(function () {});
        });
    }

    global.WelineWishlistModule = {
        __full: true,
        boot: boot,
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})(window);
