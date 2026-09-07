/**
 * Weline Cart frontend core (万能购物车):
 * - pending browser coupon (7d) + apply via event
 * - guest cart session (7d) with near-expiry auto renew
 */
(function (global) {
    'use strict';

    var WEEK_MS = 7 * 24 * 3600 * 1000;
    var RENEW_WITHIN_MS = 24 * 3600 * 1000; // last day
    var PENDING_COUPON_KEY = 'weline.cart.pending_coupon';
    var GUEST_TOKEN_KEY = 'weline.cart.guest_token';
    var GUEST_SESSION_KEY = 'weline.cart.guest_session';
    var SUMMARY_CACHE_KEY = 'weline.cart.summary_cache';
    var APPLY_EVENT = 'weline:cart:apply-coupon';
    var APPLIED_EVENT = 'weline:cart:coupon-applied';
    var renewTimer = 0;
    var applyInFlight = null;

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

    function rememberSummary(summary, meta) {
        if (!isUsableSummary(summary)) {
            return null;
        }
        var token = currentGuestToken();
        var payload = {
            guest_token: token,
            scope_key: String((summary && summary.scope_key) || (meta && meta.scope_key) || ''),
            saved_at: nowMs(),
            summary: summary,
        };
        writeJson(SUMMARY_CACHE_KEY, payload);
        return payload;
    }

    function getCachedSummary(options) {
        options = options || {};
        var data = readJson(SUMMARY_CACHE_KEY);
        if (!data || !isUsableSummary(data.summary)) {
            return null;
        }
        var token = currentGuestToken();
        var cachedToken = String(data.guest_token || '').trim();
        // Bound cache to the active guest token when either side has one.
        if (token && cachedToken && token !== cachedToken) {
            return null;
        }
        if (token && !cachedToken && options.requireTokenMatch) {
            return null;
        }
        if (!token && cachedToken && options.requireTokenMatch) {
            return null;
        }
        return data.summary;
    }

    function clearCachedSummary() {
        writeJson(SUMMARY_CACHE_KEY, null);
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
                expires_at: weekFrom(),
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
            expires_at: Number(expiresAt || weekFrom()),
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
                    return rememberGuestSession(session.token, weekFrom());
                }
                return client.renewGuestSession({ guest_token: session.token }, { silent: true }).then(function (response) {
                    var payload = response && response.data && typeof response.data === 'object' ? response.data : response;
                    var token = String((payload && payload.guest_token) || session.token).trim();
                    var expiresAt = Number((payload && payload.expires_at_ms) || weekFrom());
                    return rememberGuestSession(token, expiresAt);
                });
            });
        }).catch(function () {
            return rememberGuestSession(session.token, weekFrom());
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
        getPendingCoupon(); // purge expired
        getGuestSession();
        applyPendingCoupon('boot');
        renewGuestSession();
        scheduleGuestRenewWatch();
    }

    global.addEventListener(APPLY_EVENT, onApplyCouponEvent);
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
        PENDING_COUPON_KEY: PENDING_COUPON_KEY,
        GUEST_SESSION_KEY: GUEST_SESSION_KEY,
        SUMMARY_CACHE_KEY: SUMMARY_CACHE_KEY,
        APPLY_EVENT: APPLY_EVENT,
        APPLIED_EVENT: APPLIED_EVENT,
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
