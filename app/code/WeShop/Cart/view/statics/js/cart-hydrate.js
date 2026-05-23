/**
 * WeShop 购物车按需 hydration
 *
 * 仅在以下情况请求 cart.count API：
 * 1. 用户已登录
 * 2. 访客存在 weline_cart_item_count Cookie 且数量 > 0
 *
 * 首次进站、无购物车 Cookie 的陌生人不会发起任何 cart API 请求。
 */
(function (window, document) {
    'use strict';

    const DEFAULT_COOKIE_KEY = 'weline_cart_item_count';
    const COOKIE_MAX_AGE = 3600 * 24 * 365;

    function getCookieKey() {
        const config = window.WelineApiConfig || {};
        return config.cartCountCookieKey || DEFAULT_COOKIE_KEY;
    }

    function parseCartCookie() {
        const key = getCookieKey();
        if (!key || !document.cookie) {
            return { exists: false, count: 0 };
        }

        const match = document.cookie.split('; ').find(function (row) {
            return row.startsWith(key + '=');
        });
        if (!match) {
            return { exists: false, count: 0 };
        }

        const value = parseInt(match.split('=')[1] || '0', 10);
        return {
            exists: true,
            count: Number.isNaN(value) ? 0 : Math.max(0, value),
        };
    }

    function isCustomerLoggedIn() {
        const root = document.querySelector('[data-weshop-cart-hydrate]');
        if (root && root.dataset.customerLoggedIn === 'true') {
            return true;
        }

        return !!document.getElementById('header-account-link');
    }

    function shouldHydrateCart() {
        if (isCustomerLoggedIn()) {
            return true;
        }

        const cookie = parseCartCookie();
        return cookie.exists && cookie.count > 0;
    }

    function normalizeApiPayload(payload) {
        if (!payload || typeof payload !== 'object') {
            return payload || {};
        }

        if (!Object.prototype.hasOwnProperty.call(payload, 'code')
            || typeof payload.data !== 'object'
            || !payload.data) {
            return payload;
        }

        const code = Number(payload.code || 0);
        return Object.assign({
            code: code,
            message: payload.msg || payload.data.message || '',
            success: code >= 200 && code < 300 && payload.data.success !== false,
        }, payload.data);
    }

    function writeCartCookie(count) {
        const key = getCookieKey();
        const normalized = Math.max(0, parseInt(String(count || 0), 10) || 0);
        document.cookie = key + '=' + normalized + '; path=/; max-age=' + COOKIE_MAX_AGE + '; SameSite=Lax';
    }

    function updateCartCountUi(count) {
        const normalized = Math.max(0, parseInt(String(count || 0), 10) || 0);
        document.querySelectorAll('[data-cart-count]').forEach(function (el) {
            el.textContent = normalized > 99 ? '99+' : String(normalized);
            el.style.display = normalized > 0 ? '' : 'none';
            el.classList.toggle('has-items', normalized > 0);
        });

        window.dispatchEvent(new CustomEvent('weline:cart:update', {
            detail: { count: normalized },
        }));
    }

    function syncCartStateFlags(count) {
        const normalized = Math.max(0, parseInt(String(count || 0), 10) || 0);
        if (window.Weline && window.Weline.Api) {
            if (normalized > 0 && typeof window.Weline.Api.markCartActive === 'function') {
                window.Weline.Api.markCartActive();
            } else if (normalized === 0 && typeof window.Weline.Api.markCartEmpty === 'function') {
                window.Weline.Api.markCartEmpty();
            }
            return;
        }

        if (window.WelineApiModule) {
            if (normalized > 0 && typeof window.WelineApiModule.markCartActive === 'function') {
                window.WelineApiModule.markCartActive();
            } else if (normalized === 0 && typeof window.WelineApiModule.markCartEmpty === 'function') {
                window.WelineApiModule.markCartEmpty();
            }
        }
    }

    function resolveCartApi() {
        if (window.Weline && typeof window.Weline.Api?.resource === 'function') {
            return Promise.resolve(window.Weline.Api.resource('cart'));
        }
        if (window.WelineApiModule?.__full === true && typeof window.WelineApiModule.resource === 'function') {
            return Promise.resolve(window.WelineApiModule.resource('cart'));
        }
        return Promise.reject(new Error('Weline.Api.resource is not available'));
    }

    function hydrateCartFromApi() {
        return resolveCartApi()
            .then(function (CartApi) {
                if (!CartApi || typeof CartApi.count !== 'function') {
                    throw new Error('cart.count is not available');
                }
                return CartApi.count({}, { silent: true });
            })
            .then(function (raw) {
                const response = normalizeApiPayload(raw);
                if (!response || response.success === false) {
                    return null;
                }

                const count = Math.max(0, parseInt(String(response.count ?? 0), 10) || 0);
                writeCartCookie(count);
                updateCartCountUi(count);
                syncCartStateFlags(count);

                if (window.MiniCart && typeof window.MiniCart.updateCount === 'function') {
                    window.MiniCart.updateCount(count);
                }

                document.dispatchEvent(new CustomEvent('weshop:cart:updated', {
                    detail: {
                        count: count,
                        cart_count: count,
                    },
                }));

                return count;
            });
    }

    function scheduleHydrate() {
        if (!shouldHydrateCart()) {
            return;
        }

        const run = function () {
            hydrateCartFromApi().catch(function () {
                /* 静默失败，避免打扰首次访客 */
            });
        };

        if (typeof window.requestIdleCallback === 'function') {
            window.requestIdleCallback(run, { timeout: 2000 });
            return;
        }

        window.setTimeout(run, 300);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', scheduleHydrate);
    } else {
        scheduleHydrate();
    }

    window.WeShopCartHydrate = {
        shouldHydrateCart: shouldHydrateCart,
        hydrateCartFromApi: hydrateCartFromApi,
        parseCartCookie: parseCartCookie,
        updateCartCountUi: updateCartCountUi,
        writeCartCookie: writeCartCookie,
    };
})(window, document);
