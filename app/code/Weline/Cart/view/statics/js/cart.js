/**
 * Weline Cart frontend core (万能购物车):
 * - pending browser coupon (7d) + apply via event
 * - guest cart session (15d / half month) with near-expiry auto renew
 */
(function (global) {
    'use strict';

    var WEEK_MS = 7 * 24 * 3600 * 1000; // pending coupon only
    var GUEST_SESSION_MS = 15 * 24 * 3600 * 1000; // half month
    var RENEW_WITHIN_MS = 24 * 3600 * 1000; // last day
    var PENDING_COUPON_KEY = 'weline.cart.pending_coupon';
    var GUEST_TOKEN_KEY = 'weline.cart.guest_token';
    var GUEST_SESSION_KEY = 'weline.cart.guest_session';
    var SUMMARY_CACHE_KEY = 'weline.cart.summary_cache';
    /** After checkout success: consumers must forceNetwork once, then consume. */
    var SUMMARY_NEEDS_ORIGIN_KEY = 'weline.cart.summary_needs_origin';
    var CART_FLAG_KEY = 'weline_cart_has_items';
    var CART_COUNT_COOKIE = 'weline_cart_item_count';
    var CART_TRIGGER_SELECTOR = '[data-weline-cart-trigger]';
    var APPLY_EVENT = 'weline:cart:apply-coupon';
    var APPLIED_EVENT = 'weline:cart:coupon-applied';
    var WAIT_GIFT_REDEEMED_EVENT = 'weline:maintenance:wait-gift-redeemed';
    var CHECKOUT_SUCCESS_EVENT = 'weline:checkout:success';
    var CHECKOUT_ORDER_CREATED_EVENT = 'weline:checkout:order-created';
    var PAYMENT_OUTCOME_EVENT = 'weline:payment:outcome';
    var PAYMENT_PAID_EVENT = 'weline:payment:paid';
    var PAYMENT_PENDING_EVENT = 'weline:payment:pending';
    /** Cart-owned storefront Event: cart_type switch (B2B adapts; Theme must not bind B2B DOM). */
    var CART_TYPE_CHANGED_EVENT = 'weline:cart-type-changed';
    var CART_TYPE_EXPLICIT_KEY = 'weline_cart_type_explicit';
    var renewTimer = 0;
    var applyInFlight = null;
    var cartStatusBound = false;

    function isLifecycleDevMode() {
        try {
            if (global.DEV === true || global.WELINE_ENV === 'DEV') {
                return true;
            }
            if (global.WELINE_LIFECYCLE_DEBUG === true) {
                return true;
            }
            if (global.localStorage && global.localStorage.getItem('weline_lifecycle_debug') === '1') {
                return true;
            }
        } catch (eDev) {}
        return false;
    }

    function logLifecycleReceive(eventName, detail, action) {
        if (!isLifecycleDevMode()) {
            return;
        }
        try {
            console.info('[WelineCart]', 'listen', eventName, action || 'invalidateAfterCheckout', detail || {});
        } catch (eLog) {}
    }

    function callApiAuto(method) {
        try {
            var api = global.Weline && global.Weline.Api;
            if (api && typeof api[method] === 'function') {
                api[method]();
            }
        } catch (e) {
            // Api may not be loaded yet
        }
    }

    function hasCartCookieToken() {
        if (!global.document || !global.document.cookie) {
            return false;
        }
        return global.document.cookie.split('; ').some(function (row) {
            return row.indexOf(CART_COUNT_COOKIE + '=') === 0;
        });
    }

    function readCartCookieFlag() {
        if (!global.document || !global.document.cookie) {
            return null;
        }
        var match = global.document.cookie.split('; ').find(function (row) {
            return row.indexOf(CART_COUNT_COOKIE + '=') === 0;
        });
        if (!match) {
            return null;
        }
        var value = parseInt(match.split('=')[1] || '0', 10);
        if (Number.isNaN(value)) {
            return null;
        }
        return value > 0 ? true : value === 0 ? false : null;
    }

    function markCartActive() {
        try {
            global.localStorage.setItem(CART_FLAG_KEY, 'true');
        } catch (e) {
            // privacy modes
        }
        callApiAuto('enableAutoRequests');
    }

    function markCartEmpty() {
        try {
            global.localStorage.removeItem(CART_FLAG_KEY);
        } catch (e) {
            // privacy modes
        }
        callApiAuto('disableAutoRequests');
    }

    function restoreCartState() {
        try {
            var cookieFlag = readCartCookieFlag();
            if (cookieFlag === true) {
                markCartActive();
            } else if (cookieFlag === false) {
                markCartEmpty();
            } else {
                var storedFlag = global.localStorage.getItem(CART_FLAG_KEY);
                if (storedFlag === 'true' && hasCartCookieToken()) {
                    callApiAuto('enableAutoRequests');
                }
            }
        } catch (e) {
            // storage may be unavailable
        }
    }

    function bindCartStatusListeners() {
        if (cartStatusBound || !global.document) {
            return;
        }
        cartStatusBound = true;
        global.document.addEventListener('click', function (event) {
            var target = event && event.target;
            if (!target || typeof target.closest !== 'function') {
                return;
            }
            if (target.closest(CART_TRIGGER_SELECTOR)) {
                markCartActive();
            }
        }, { passive: true });

        var onCount = function (event) {
            var detail = (event && event.detail) || {};
            var count = typeof detail.count === 'number'
                ? detail.count
                : (typeof detail.cart_count === 'number' ? detail.cart_count : null);
            if (count === null) {
                return;
            }
            if (count > 0) {
                markCartActive();
            } else {
                markCartEmpty();
            }
        };
        global.addEventListener('weline:cart:update', onCount);
        global.addEventListener('weshop:cart:updated', onCount);
    }

    function installCartStatus() {
        // Cart owns status keys + auto-request signals — never bake into weline-api / weline.js.
        restoreCartState();
        bindCartStatusListeners();
    }

    function onWaitGiftRedeemed(event) {
        var detail = (event && event.detail) || {};
        var code = String(detail.coupon_code || detail.code || '').trim();
        if (!code) {
            return;
        }
        storePendingCoupon(code, {
            source: detail.source || 'maintenance_wait_gift',
            expires_at: detail.expires_at || weekFrom(nowMs()),
        });
        applyPendingCoupon('wait-gift');
    }

    function nowMs() {
        return Date.now();
    }

    function readJson(key) {
        try {
            var raw = global.localStorage.getItem(key);
            if (!raw) {
                return null;
            }
            var data = JSON.parse(raw);
            return data && typeof data === 'object' ? data : null;
        } catch (e) {
            return null;
        }
    }

    function writeJson(key, value) {
        try {
            if (!value) {
                global.localStorage.removeItem(key);
                return;
            }
            global.localStorage.setItem(key, JSON.stringify(value));
        } catch (e) {
            // privacy modes
        }
    }

    function readGuestTokenLegacy() {
        try {
            return String(global.sessionStorage.getItem(GUEST_TOKEN_KEY) || '').trim();
        } catch (e) {
            return '';
        }
    }

    function writeGuestTokenLegacy(token) {
        try {
            if (token) {
                global.sessionStorage.setItem(GUEST_TOKEN_KEY, token);
            } else {
                global.sessionStorage.removeItem(GUEST_TOKEN_KEY);
            }
        } catch (e) {
            // ignore
        }
    }

    function weekFrom(ts) {
        return Number(ts || nowMs()) + WEEK_MS;
    }

    function guestSessionFrom(ts) {
        return Number(ts || nowMs()) + GUEST_SESSION_MS;
    }

    function getPendingCoupon() {
        var data = readJson(PENDING_COUPON_KEY);
        if (!data || !data.coupon_code) {
            return null;
        }
        var expiresAt = Number(data.expires_at || 0);
        if (expiresAt > 0 && expiresAt <= nowMs()) {
            clearPendingCoupon();
            return null;
        }
        return {
            coupon_code: String(data.coupon_code).toUpperCase(),
            expires_at: expiresAt || weekFrom(data.issued_at),
            source: String(data.source || ''),
            issued_at: Number(data.issued_at || 0),
        };
    }

    function storePendingCoupon(code, meta) {
        var normalized = String(code || '').trim().toUpperCase();
        if (!normalized) {
            clearPendingCoupon();
            return null;
        }
        var issuedAt = nowMs();
        var payload = {
            coupon_code: normalized,
            source: String((meta && meta.source) || ''),
            issued_at: issuedAt,
            expires_at: Number((meta && meta.expires_at) || weekFrom(issuedAt)),
        };
        writeJson(PENDING_COUPON_KEY, payload);
        return payload;
    }

    function clearPendingCoupon() {
        writeJson(PENDING_COUPON_KEY, null);
    }

    function currentGuestToken() {
        var session = getGuestSession();
        if (session && session.token) {
            return String(session.token).trim();
        }
        return readGuestTokenLegacy();
    }

    function isUsableSummary(summary) {
        return !!(summary && typeof summary === 'object' && summary.success !== false);
    }

    /** Opaque cart_type SPI code; default toc. Keeps toc/tob local caches separate. */
    function normalizeSummaryCartType(value) {
        var mode = String(value || '').trim().toLowerCase();
        return mode || 'toc';
    }

    function summaryCacheKeyFor(cartType) {
        return SUMMARY_CACHE_KEY + '.' + normalizeSummaryCartType(cartType);
    }

    /** Active storefront display currency: path → query → runtime config → preference. */
    function currentDisplayCurrency() {
        try {
            var parts = String((global.location && global.location.pathname) || '').split('/').filter(Boolean);
            for (var i = 0; i < parts.length; i++) {
                var pathCode = String(parts[i] || '').trim().toUpperCase();
                if (/^[A-Z]{3}$/.test(pathCode)) {
                    return pathCode;
                }
            }
        } catch (ePath) {}
        try {
            var q = String(new URLSearchParams((global.location && global.location.search) || '').get('currency') || '')
                .trim().toUpperCase();
            if (/^[A-Z]{3}$/.test(q)) {
                return q;
            }
        } catch (eQuery) {}
        try {
            var cfgNode = global.document && global.document.getElementById('weline-frontend-runtime-config');
            if (cfgNode && cfgNode.textContent) {
                var cfg = JSON.parse(cfgNode.textContent);
                var fromCfg = String(
                    (cfg && (cfg.currentCurrency || cfg.currency
                        || (cfg.api && cfg.api.currency)
                        || (cfg.site && (cfg.site.currency || cfg.site.currentCurrency)))) || ''
                ).trim().toUpperCase();
                if (/^[A-Z]{3}$/.test(fromCfg)) {
                    return fromCfg;
                }
            }
        } catch (e0) {}
        try {
            var fromLs = String(global.localStorage.getItem('weline_user_currency') || '').trim().toUpperCase();
            if (/^[A-Z]{3}$/.test(fromLs)) {
                return fromLs;
            }
        } catch (e1) {}
        return 'CNY';
    }

    /** Active storefront locale (html lang / path). */
    function currentDisplayLocale() {
        try {
            var htmlLang = String(
                (global.document && global.document.documentElement && global.document.documentElement.getAttribute('lang')) || ''
            ).trim().replace(/-/g, '_');
            if (htmlLang) {
                return htmlLang;
            }
        } catch (e0) {}
        try {
            var parts = String((global.location && global.location.pathname) || '').split('/').filter(Boolean);
            for (var i = 0; i < parts.length; i++) {
                if (/^[a-z]{2}(_[A-Za-z0-9]+)?$/i.test(parts[i]) && parts[i].indexOf('_') !== -1) {
                    return parts[i];
                }
            }
        } catch (e1) {}
        return '';
    }

    function summaryLocaleCurrencyMatches(data, options) {
        options = options || {};
        if (!data) {
            return false;
        }
        var wantCurrency = String(options.currency || currentDisplayCurrency()).trim().toUpperCase() || 'CNY';
        var cachedCurrency = String(
            data.currency || (data.summary && data.summary.currency) || ''
        ).trim().toUpperCase();
        // Missing stamp = pre-vary cache; treat as miss so currency switch re-fetches.
        if (!cachedCurrency || cachedCurrency !== wantCurrency) {
            return false;
        }
        var wantLocale = String(options.locale || currentDisplayLocale() || '').trim().replace(/-/g, '_');
        var cachedLocale = String(data.locale || '').trim().replace(/-/g, '_');
        if (wantLocale && cachedLocale && wantLocale.toLowerCase() !== cachedLocale.toLowerCase()) {
            return false;
        }
        return true;
    }

    function summaryHasLineItems(summary) {
        if (!summary || typeof summary !== 'object') {
            return false;
        }
        if (summary.is_empty === true) {
            return false;
        }
        var count = Number(summary.cart_count != null ? summary.cart_count : (summary.item_count || 0));
        if (count > 0) {
            return true;
        }
        return Array.isArray(summary.items) && summary.items.length > 0;
    }

    function summaryTokenMatches(data, options) {
        options = options || {};
        if (!data || !isUsableSummary(data.summary)) {
            return false;
        }
        if (!summaryLocaleCurrencyMatches(data, options)) {
            return false;
        }
        var expiresAt = Number(data.expires_at_ms || 0);
        if (expiresAt > 0 && expiresAt <= nowMs()) {
            return false;
        }
        var token = currentGuestToken();
        var cachedToken = String(data.guest_token || '').trim();
        // Ghost-cart gate: non-empty local summaries require an exact guest_token match.
        var requireMatch = options.requireTokenMatch === true
            || (options.requireTokenMatch !== false && summaryHasLineItems(data.summary));
        if (requireMatch) {
            if (!token || !cachedToken || token !== cachedToken) {
                return false;
            }
            return true;
        }
        if (token && cachedToken && token !== cachedToken) {
            return false;
        }
        return true;
    }

    function rememberSummary(summary, meta) {
        if (!isUsableSummary(summary)) {
            return null;
        }
        meta = meta || {};
        var mode = normalizeSummaryCartType(
            summary.cart_type || summary.selling_mode || meta.cart_type || meta.cartType || 'toc'
        );
        summary.cart_type = mode;
        summary.selling_mode = mode;
        var currency = String(summary.currency || meta.currency || currentDisplayCurrency()).trim().toUpperCase() || 'CNY';
        summary.currency = currency;
        var locale = String(meta.locale || currentDisplayLocale() || '').trim().replace(/-/g, '_');
        var token = currentGuestToken();
        var payload = {
            guest_token: token,
            scope_key: String((summary && summary.scope_key) || meta.scope_key || ''),
            cart_type: mode,
            currency: currency,
            locale: locale,
            saved_at: nowMs(),
            expires_at_ms: Number(summary.expires_at_ms || meta.expires_at_ms || 0) || guestSessionFrom(),
            summary: summary,
        };
        // Typed buckets: toc and tob each keep a browser-local copy; never clobber the other.
        writeJson(summaryCacheKeyFor(mode), payload);
        // Legacy untyped key: only mirror toc so old readers stay retail-safe.
        if (mode === 'toc') {
            writeJson(SUMMARY_CACHE_KEY, payload);
        }
        return payload;
    }

    function getCachedSummary(options) {
        options = options || {};
        var modeHint = options.cartType || options.cart_type || options.selling_mode || '';
        if (!modeHint) {
            try {
                if (global.WelineB2BSellingMode && typeof global.WelineB2BSellingMode.preferredMode === 'function') {
                    modeHint = global.WelineB2BSellingMode.preferredMode() || '';
                }
            } catch (e) {}
        }
        var mode = normalizeSummaryCartType(modeHint);
        var data = readJson(summaryCacheKeyFor(mode));
        if (summaryTokenMatches(data, options)) {
            var typed = Object.assign({}, data.summary);
            typed.cart_type = mode;
            typed.selling_mode = mode;
            return typed;
        }
        // Legacy untyped key only for toc (pre-typed-cache installs).
        if (mode === 'toc') {
            data = readJson(SUMMARY_CACHE_KEY);
            if (!summaryTokenMatches(data, options)) {
                return null;
            }
            var legacyType = normalizeSummaryCartType(
                data.cart_type || (data.summary && (data.summary.cart_type || data.summary.selling_mode)) || 'toc'
            );
            if (legacyType !== 'toc') {
                return null;
            }
            return Object.assign({}, data.summary, { cart_type: 'toc', selling_mode: 'toc' });
        }
        return null;
    }

    function clearCachedSummary(options) {
        options = options || {};
        if (options.cartType || options.cart_type) {
            var mode = normalizeSummaryCartType(options.cartType || options.cart_type);
            writeJson(summaryCacheKeyFor(mode), null);
            if (mode === 'toc') {
                writeJson(SUMMARY_CACHE_KEY, null);
            }
            return;
        }
        writeJson(SUMMARY_CACHE_KEY, null);
        writeJson(summaryCacheKeyFor('toc'), null);
        writeJson(summaryCacheKeyFor('tob'), null);
    }

    function markNeedsOriginRefresh(reason) {
        try {
            global.localStorage.setItem(SUMMARY_NEEDS_ORIGIN_KEY, JSON.stringify({
                reason: String(reason || 'manual'),
                at: nowMs(),
            }));
        } catch (e) {
            // privacy modes
        }
    }

    function needsOriginRefresh() {
        try {
            var raw = global.localStorage.getItem(SUMMARY_NEEDS_ORIGIN_KEY);
            return !!(raw && String(raw).trim());
        } catch (e) {
            return false;
        }
    }

    function consumeNeedsOriginRefresh() {
        var had = needsOriginRefresh();
        try {
            global.localStorage.removeItem(SUMMARY_NEEDS_ORIGIN_KEY);
        } catch (e) {
            // privacy modes
        }
        return had;
    }

    /** Success / paid / order-created recovery surfaces where local summary_cache must not paint stale lines. */
    function isCheckoutSuccessSurface() {
        try {
            var path = String((global.location && global.location.pathname) || '');
            var hash = String((global.location && global.location.hash) || '');
            if (/\/checkout\/success(?:\.html)?\/?$/i.test(path)) {
                return true;
            }
            if (/\/payment\/success(?:\.html)?\/?$/i.test(path)) {
                return true;
            }
            if (/\/payment\/handoff(?:\.html)?\/?$/i.test(path)) {
                return true;
            }
            // Checkout payment-recovery hash: order already created (pending/failed payment).
            if (/\/checkout(?:\/index)?(?:\.html)?\/?$/i.test(path)
                && hash.indexOf('#payment-recovery') === 0) {
                return true;
            }
            if (global.document && global.document.querySelector) {
                var recovery = global.document.querySelector('[data-checkout-payment-recovery]');
                if (recovery && !recovery.hidden) {
                    return true;
                }
            }
            if (/\/payment\/(?:checkout\/)?return/i.test(path)) {
                var q = new URLSearchParams((global.location && global.location.search) || '');
                var outcome = String(q.get('outcome') || q.get('status') || '').toLowerCase();
                if (outcome === 'paid' || outcome === 'success' || outcome === 'pending') {
                    return true;
                }
            }
        } catch (e) {}
        return false;
    }

    /**
     * Drop typed summary_cache + flag origin refresh so mini-cart cannot preferCache
     * paint lines after the server cart was emptied by checkout.
     */
    function invalidateAfterCheckout(options) {
        options = options || {};
        var reason = String(options.reason || options.source || 'checkout-success').trim() || 'checkout-success';
        if (options.cartType || options.cart_type) {
            clearCachedSummary({ cartType: options.cartType || options.cart_type });
        } else {
            clearCachedSummary({});
        }
        markCartEmpty();
        markNeedsOriginRefresh(reason);
        try {
            var detail = {
                count: 0,
                cart_count: 0,
                source: reason,
                refresh: true,
                forceNetwork: true,
            };
            global.dispatchEvent(new CustomEvent('weline:cart:update', { detail: detail }));
            global.dispatchEvent(new CustomEvent('weline:cart-updated', { detail: detail }));
            global.dispatchEvent(new CustomEvent('weshop:cart:updated', { detail: detail }));
        } catch (eDispatch) {}
        return true;
    }

    function getGuestSession() {
        var data = readJson(GUEST_SESSION_KEY);
        var token = data && data.token ? String(data.token).trim() : '';
        if (!token) {
            token = readGuestTokenLegacy();
            if (!token) {
                return null;
            }
            data = {
                token: token,
                expires_at: guestSessionFrom(),
                renewed_at: nowMs(),
            };
            writeJson(GUEST_SESSION_KEY, data);
            return data;
        }
        var expiresAt = Number(data.expires_at || 0);
        if (expiresAt > 0 && expiresAt <= nowMs()) {
            writeJson(GUEST_SESSION_KEY, null);
            writeGuestTokenLegacy('');
            return null;
        }
        return {
            token: token,
            expires_at: expiresAt,
            renewed_at: Number(data.renewed_at || 0),
        };
    }

    function rememberGuestSession(token, expiresAt) {
        var normalized = String(token || '').trim();
        if (!normalized) {
            return null;
        }
        var payload = {
            token: normalized,
            expires_at: Number(expiresAt || guestSessionFrom()),
            renewed_at: nowMs(),
        };
        writeJson(GUEST_SESSION_KEY, payload);
        writeGuestTokenLegacy(normalized);
        return payload;
    }

    function waitForApi() {
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

    function applyPendingCoupon(reason) {
        var pending = getPendingCoupon();
        if (!pending) {
            return Promise.resolve(null);
        }
        if (applyInFlight) {
            return applyInFlight;
        }
        applyInFlight = waitForApi().then(function (api) {
            return api.resource('marketing').then(function (client) {
                if (!client || typeof client.applyCoupon !== 'function') {
                    throw new Error('marketing.applyCoupon unavailable');
                }
                return client.applyCoupon({ coupon_code: pending.coupon_code }, { silent: true });
            });
        }).then(function (response) {
            var ok = !!(response && (response.success || response.ok || response.coupon_code));
            if (ok) {
                clearPendingCoupon();
                global.dispatchEvent(new CustomEvent(APPLIED_EVENT, {
                    detail: {
                        coupon_code: pending.coupon_code,
                        source: pending.source,
                        reason: reason || 'applied',
                        response: response || {},
                    },
                }));
            }
            return response || null;
        }).catch(function () {
            return null;
        }).finally(function () {
            applyInFlight = null;
        });
        return applyInFlight;
    }

    function onApplyCouponEvent(event) {
        var detail = (event && event.detail) || {};
        var code = String(detail.coupon_code || detail.code || '').trim();
        if (!code) {
            return;
        }
        storePendingCoupon(code, {
            source: detail.source || '',
            expires_at: detail.expires_at || weekFrom(),
        });
        applyPendingCoupon('event');
    }

    function needsGuestRenew(session) {
        if (!session || !session.token) {
            return false;
        }
        var expiresAt = Number(session.expires_at || 0);
        if (!expiresAt) {
            return true;
        }
        return (expiresAt - nowMs()) <= RENEW_WITHIN_MS;
    }

    function renewGuestSession() {
        var session = getGuestSession();
        if (!session || !session.token) {
            return Promise.resolve(null);
        }
        if (!needsGuestRenew(session)) {
            return Promise.resolve(session);
        }
        return waitForApi().then(function (api) {
            return api.resource('cart').then(function (client) {
                if (!client || typeof client.renewGuestSession !== 'function') {
                    // Fallback: extend browser window only when API missing.
                    return rememberGuestSession(session.token, guestSessionFrom());
                }
                return client.renewGuestSession({ guest_token: session.token }, { silent: true }).then(function (response) {
                    var payload = response && response.data && typeof response.data === 'object' ? response.data : response;
                    var token = String((payload && payload.guest_token) || session.token).trim();
                    var expiresAt = Number((payload && payload.expires_at_ms) || guestSessionFrom());
                    return rememberGuestSession(token, expiresAt);
                });
            });
        }).catch(function () {
            return rememberGuestSession(session.token, guestSessionFrom());
        });
    }

    function scheduleGuestRenewWatch() {
        global.clearTimeout(renewTimer);
        renewTimer = global.setTimeout(function () {
            renewTimer = 0;
            renewGuestSession().finally(function () {
                scheduleGuestRenewWatch();
            });
        }, 60 * 60 * 1000);
    }

    function boot() {
        installCartStatus();
        getPendingCoupon(); // purge expired
        getGuestSession();
        if (isCheckoutSuccessSurface()) {
            invalidateAfterCheckout({ reason: 'checkout-success-surface' });
        }
        applyPendingCoupon('boot');
        renewGuestSession();
        scheduleGuestRenewWatch();
    }

    /**
     * Request a cart_type switch. Owns the Event name and explicit session key.
     * Optional Cart-owned chrome: [data-cart-type-option="toc|tob"] (B2B may mirror).
     */
    function requestCartType(type, opts) {
        var next = String(type || '').toLowerCase() === 'tob' ? 'tob' : 'toc';
        var options = opts && typeof opts === 'object' ? opts : {};
        var source = String(options.source || 'cart-request').trim() || 'cart-request';
        var forceNetwork = options.forceNetwork === true;
        try {
            global.sessionStorage.setItem(CART_TYPE_EXPLICIT_KEY, next);
        } catch (eExplicit) {}
        // Soft tab switch may click Cart-owned chrome. forceNetwork must emit Event
        // (never chrome-only) so cart/mini-cart listeners keep the network reload and
        // preferCache cannot paint a stale empty sibling cart.
        var clicked = false;
        if (!forceNetwork && options.clickChrome !== false) {
            try {
                var seg = global.document && global.document.querySelector
                    ? global.document.querySelector('[data-cart-type-option="' + next + '"]')
                    : null;
                if (seg && typeof seg.click === 'function') {
                    seg.click();
                    clicked = true;
                }
            } catch (eClick) {}
        }
        if (!clicked) {
            global.dispatchEvent(new CustomEvent(CART_TYPE_CHANGED_EVENT, {
                detail: {
                    cart_type: next,
                    selling_mode: next,
                    source: source,
                    forceNetwork: forceNetwork,
                },
            }));
        }
        return next;
    }

    global.addEventListener(APPLY_EVENT, onApplyCouponEvent);
    global.addEventListener(WAIT_GIFT_REDEEMED_EVENT, onWaitGiftRedeemed);
    global.addEventListener(CHECKOUT_SUCCESS_EVENT, function (event) {
        var detail = (event && event.detail) || {};
        logLifecycleReceive(CHECKOUT_SUCCESS_EVENT, detail, 'invalidateAfterCheckout');
        invalidateAfterCheckout({
            reason: detail.source || 'checkout-success-event',
            cartType: detail.cart_type || detail.cartType || '',
        });
    });
    global.addEventListener(CHECKOUT_ORDER_CREATED_EVENT, function (event) {
        var detail = (event && event.detail) || {};
        logLifecycleReceive(CHECKOUT_ORDER_CREATED_EVENT, detail, 'invalidateAfterCheckout');
        invalidateAfterCheckout({
            reason: detail.source || 'checkout-order-created',
            cartType: detail.cart_type || detail.cartType || '',
        });
    });
    function onPaymentLifecycleEvent(event) {
        var detail = (event && event.detail) || {};
        var outcome = String(detail.outcome || '').toLowerCase();
        var type = String((event && event.type) || '').toLowerCase();
        // Order committed (paid or pending payment): drop stale mini-cart cache.
        if (type === PAYMENT_PAID_EVENT || type === PAYMENT_PENDING_EVENT
            || outcome === 'paid' || outcome === 'pending') {
            logLifecycleReceive(type || PAYMENT_OUTCOME_EVENT, detail, 'invalidateAfterCheckout');
            invalidateAfterCheckout({
                reason: detail.source || type || 'payment-lifecycle',
                cartType: detail.cart_type || detail.cartType || '',
            });
        } else if (isLifecycleDevMode()) {
            logLifecycleReceive(type || PAYMENT_OUTCOME_EVENT, detail, 'ignored');
        }
    }
    global.addEventListener(PAYMENT_OUTCOME_EVENT, onPaymentLifecycleEvent);
    global.addEventListener(PAYMENT_PAID_EVENT, onPaymentLifecycleEvent);
    global.addEventListener(PAYMENT_PENDING_EVENT, onPaymentLifecycleEvent);
    global.addEventListener('weline:cart-updated', function () {
        applyPendingCoupon('cart-updated');
    });
    global.addEventListener('weline:cart:update', function () {
        applyPendingCoupon('cart-update');
    });
    global.addEventListener('visibilitychange', function () {
        if (!global.document.hidden) {
            renewGuestSession();
            applyPendingCoupon('visible');
        }
    });
    global.addEventListener('focus', function () {
        renewGuestSession();
    });

    var api = {
        WEEK_MS: WEEK_MS,
        GUEST_SESSION_MS: GUEST_SESSION_MS,
        PENDING_COUPON_KEY: PENDING_COUPON_KEY,
        GUEST_SESSION_KEY: GUEST_SESSION_KEY,
        SUMMARY_CACHE_KEY: SUMMARY_CACHE_KEY,
        SUMMARY_NEEDS_ORIGIN_KEY: SUMMARY_NEEDS_ORIGIN_KEY,
        CART_FLAG_KEY: CART_FLAG_KEY,
        CART_COUNT_COOKIE: CART_COUNT_COOKIE,
        APPLY_EVENT: APPLY_EVENT,
        APPLIED_EVENT: APPLIED_EVENT,
        WAIT_GIFT_REDEEMED_EVENT: WAIT_GIFT_REDEEMED_EVENT,
        CHECKOUT_SUCCESS_EVENT: CHECKOUT_SUCCESS_EVENT,
        CART_TYPE_CHANGED_EVENT: CART_TYPE_CHANGED_EVENT,
        requestCartType: requestCartType,
        markCartActive: markCartActive,
        markCartEmpty: markCartEmpty,
        restoreCartState: restoreCartState,
        storePendingCoupon: storePendingCoupon,
        getPendingCoupon: getPendingCoupon,
        clearPendingCoupon: clearPendingCoupon,
        applyPendingCoupon: applyPendingCoupon,
        getGuestSession: getGuestSession,
        rememberGuestSession: rememberGuestSession,
        renewGuestSession: renewGuestSession,
        rememberSummary: rememberSummary,
        getCachedSummary: getCachedSummary,
        clearCachedSummary: clearCachedSummary,
        markNeedsOriginRefresh: markNeedsOriginRefresh,
        needsOriginRefresh: needsOriginRefresh,
        consumeNeedsOriginRefresh: consumeNeedsOriginRefresh,
        invalidateAfterCheckout: invalidateAfterCheckout,
        isCheckoutSuccessSurface: isCheckoutSuccessSurface,
        currentDisplayCurrency: currentDisplayCurrency,
        currentDisplayLocale: currentDisplayLocale,
        dispatchApplyCoupon: function (code, meta) {
            var payload = storePendingCoupon(code, meta || {});
            global.dispatchEvent(new CustomEvent(APPLY_EVENT, {
                detail: payload || { coupon_code: code },
            }));
            return payload;
        },
    };

    global.WelineCart = api;

    if (global.document.readyState === 'loading') {
        global.document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})(typeof window !== 'undefined' ? window : this);
