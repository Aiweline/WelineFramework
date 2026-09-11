/**
 * Header wishlist badge hydration (module: wishlistHeader).
 * SSR always emits guest empty count; paint real total via wishlist.count.
 */
(function (global) {
    'use strict';

    var booted = false;

    function paintCount(count) {
        if (count === undefined || count === null) {
            return;
        }
        var n = Number(count) || 0;
        var label = n > 99 ? '99+' : String(n);
        document.querySelectorAll('.wishlist-count').forEach(function (node) {
            node.textContent = label;
            node.hidden = n <= 0;
        });
        document.querySelectorAll('[data-w-wishlist-icon]').forEach(function (root) {
            root.setAttribute('data-wishlist-total', String(n));
            root.classList.toggle('is-empty', n <= 0);
        });
    }

    function ensureApi() {
        if (global.Weline && global.Weline.Api && typeof global.Weline.Api.resource === 'function') {
            return Promise.resolve(global.Weline.Api);
        }
        if (global.Weline && typeof global.Weline.use === 'function') {
            return global.Weline.use('api').then(function () {
                return global.Weline.Api;
            });
        }
        return Promise.reject(new Error('Weline.Api unavailable'));
    }

    function hydrate() {
        if (!document.querySelector('[data-w-wishlist-icon="1"]')) {
            return Promise.resolve({ hydrated: false, reason: 'no_roots' });
        }
        return ensureApi().then(function (Api) {
            return Api.resource('wishlist');
        }).then(function (api) {
            return api.count();
        }).then(function (payload) {
            var data = payload && typeof payload === 'object'
                ? (payload.data && typeof payload.data === 'object' ? payload.data : payload)
                : {};
            var count = data.wishlist_count;
            if (count === undefined && data.data && typeof data.data === 'object') {
                count = data.data.wishlist_count;
            }
            paintCount(count);
            return { hydrated: true, wishlist_count: Number(count) || 0 };
        }).catch(function () {
            return { hydrated: false, reason: 'count_failed' };
        });
    }

    function boot() {
        if (booted) {
            return;
        }
        booted = true;
        hydrate();
    }

    var WishlistHeaderModule = {
        hydrate: hydrate,
        paintCount: paintCount,
    };

    global.WelineWishlistHeaderModule = WishlistHeaderModule;

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot, { once: true });
    } else {
        boot();
    }
})(window);
