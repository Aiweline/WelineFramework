/**
 * Weline Checkout — storefront checkout lifecycle events (module-owned).
 * Success / cancel surfaces boot here; checkout index also dispatches inline.
 * DEV: missing order identity / amount / currency → anomaly log, no dispatch.
 */
(function (global) {
    'use strict';

    var EVENT_SUCCESS = 'weline:checkout:success';
    var EVENT_ORDER_CREATED = 'weline:checkout:order-created';
    var EVENT_CANCELLED = 'weline:checkout:cancelled';
    var EVENT_ANOMALY = 'weline:checkout:anomaly';

    function text(value) {
        return value == null ? '' : String(value);
    }

    function isDevMode() {
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

    function devLog(eventName, detail) {
        if (!isDevMode()) {
            return;
        }
        try {
            console.info('[WelineCheckout]', eventName, detail || {});
        } catch (eLog) {}
    }

    function anomaly(reason, detail, missing) {
        var payload = Object.assign({}, detail && typeof detail === 'object' ? detail : {}, {
            reason: text(reason) || 'invalid-lifecycle-payload',
            missing: Array.isArray(missing) ? missing.slice() : [],
        });
        try {
            if (isDevMode()) {
                console.warn('[WelineCheckout][anomaly] 跳过派发：缺少订单字段', payload);
            }
            global.dispatchEvent(new CustomEvent(EVENT_ANOMALY, { detail: payload }));
        } catch (eAnomaly) {}
        return false;
    }

    function enrichFromDom(detail) {
        var payload = detail && typeof detail === 'object' ? Object.assign({}, detail) : {};
        try {
            var root = global.document && global.document.querySelector
                ? (global.document.querySelector('[data-checkout-lifecycle], [data-weline-checkout], [data-checkout-payment-recovery], [data-testid="checkout-success"]')
                    || global.document.querySelector('.checkout-success-page'))
                : null;
            if (root) {
                if (!payload.order_uuid) {
                    payload.order_uuid = text(root.getAttribute('data-order-uuid') || '').trim();
                }
                if (!payload.checkout_group_uuid) {
                    payload.checkout_group_uuid = text(root.getAttribute('data-checkout-group-uuid') || '').trim();
                }
                if (!payload.amount) {
                    payload.amount = text(root.getAttribute('data-order-amount')
                        || root.getAttribute('data-grand-total')
                        || '').trim();
                }
                if (!payload.currency) {
                    payload.currency = text(root.getAttribute('data-currency') || '').trim();
                }
            }
            if (!payload.amount) {
                var grand = global.document && global.document.querySelector
                    ? global.document.querySelector('[data-grand-total]')
                    : null;
                if (grand) {
                    var raw = text(grand.getAttribute('data-amount') || grand.textContent).replace(/[^\d.,-]/g, '').trim();
                    if (raw) {
                        payload.amount = raw;
                    }
                }
            }
            if (!payload.currency && global.document) {
                var curNode = global.document.querySelector('[data-currency], [data-checkout-currency]');
                if (curNode) {
                    payload.currency = text(curNode.getAttribute('data-currency') || curNode.textContent).trim();
                }
            }
        } catch (eDom) {}
        return payload;
    }

    function validatePayload(detail) {
        var payload = enrichFromDom(detail);
        var missing = [];
        var hasIdentity = !!(text(payload.order_uuid).trim()
            || text(payload.order_number).trim()
            || text(payload.checkout_group_uuid).trim());
        if (!hasIdentity) {
            missing.push('order_uuid|order_number|checkout_group_uuid');
        }
        if (!text(payload.amount).trim()) {
            missing.push('amount');
        }
        if (!text(payload.currency).trim()) {
            missing.push('currency');
        }
        return {
            ok: missing.length === 0,
            missing: missing,
            payload: payload,
        };
    }

    function emit(name, detail) {
        var checked = validatePayload(detail);
        if (!checked.ok) {
            return anomaly('missing-order-fields', Object.assign({}, checked.payload, {
                event: name,
            }), checked.missing);
        }
        var payload = checked.payload;
        payload.source = text(payload.source || 'checkout-lifecycle').trim() || 'checkout-lifecycle';
        try {
            global.dispatchEvent(new CustomEvent(name, { detail: payload }));
            devLog(name, payload);
            return true;
        } catch (eDispatch) {
            return false;
        }
    }

    function emitOrderCreated(detail) {
        return emit(EVENT_ORDER_CREATED, detail);
    }

    function emitSuccess(detail) {
        var ok = emit(EVENT_SUCCESS, detail);
        if (ok) {
            emitOrderCreated(Object.assign({}, detail || {}, { via: 'checkout-success' }));
        }
        return ok;
    }

    function emitCancelled(detail) {
        // Cancel still needs order identity; amount/currency optional for cancel-only.
        var payload = enrichFromDom(detail);
        var hasIdentity = !!(text(payload.order_uuid).trim()
            || text(payload.order_number).trim()
            || text(payload.checkout_group_uuid).trim());
        if (!hasIdentity) {
            return anomaly('missing-order-fields', Object.assign({}, payload, {
                event: EVENT_CANCELLED,
            }), ['order_uuid|order_number|checkout_group_uuid']);
        }
        payload.source = text(payload.source || 'checkout-lifecycle').trim() || 'checkout-lifecycle';
        try {
            global.dispatchEvent(new CustomEvent(EVENT_CANCELLED, { detail: payload }));
            devLog(EVENT_CANCELLED, payload);
            return true;
        } catch (eDispatch) {
            return false;
        }
    }

    function emitPaymentBridge(outcome, detail) {
        if (global.WelinePayment && typeof global.WelinePayment.emitOutcome === 'function') {
            return global.WelinePayment.emitOutcome(outcome, detail);
        }
        var map = {
            paid: 'weline:payment:paid',
            pending: 'weline:payment:pending',
            failed: 'weline:payment:failed',
            cancelled: 'weline:payment:cancelled',
        };
        var name = map[text(outcome).toLowerCase()];
        if (!name) {
            return false;
        }
        return emit(name, detail);
    }

    function bootFromDom(options) {
        var opts = options && typeof options === 'object' ? options : {};
        var root = opts.root
            || (global.document && global.document.querySelector
                ? global.document.querySelector('[data-checkout-lifecycle], .checkout-success-page, [data-testid="checkout-success"]')
                : null);
        if (!root) {
            try {
                var path = text(global.location && global.location.pathname);
                if (!/\/checkout\/success(?:\.html)?\/?$/i.test(path)) {
                    return false;
                }
            } catch (ePath) {
                return false;
            }
        }
        var outcome = text(
            (root && (root.getAttribute('data-payment-outcome') || root.getAttribute('data-checkout-lifecycle'))) || ''
        ).toLowerCase();
        var detail = {
            source: text(opts.source || 'checkout-success-page').trim() || 'checkout-success-page',
            order_uuid: text(root && root.getAttribute('data-order-uuid')).trim(),
            checkout_group_uuid: text(root && root.getAttribute('data-checkout-group-uuid')).trim(),
            amount: text(root && (root.getAttribute('data-order-amount') || root.getAttribute('data-pixel-value'))).trim(),
            currency: text(root && (root.getAttribute('data-currency') || root.getAttribute('data-pixel-currency'))).trim(),
        };
        if (outcome === 'cancel' || outcome === 'cancelled') {
            emitCancelled(detail);
            emitPaymentBridge('cancelled', detail);
            return true;
        }
        emitSuccess(detail);
        emitPaymentBridge('paid', detail);
        return true;
    }

    function boot() {
        if (global.document && global.document.readyState === 'loading') {
            global.document.addEventListener('DOMContentLoaded', function () {
                bootFromDom({ source: 'checkout-lifecycle-dom' });
            });
            return;
        }
        bootFromDom({ source: 'checkout-lifecycle-dom' });
    }

    var api = {
        EVENT_SUCCESS: EVENT_SUCCESS,
        EVENT_ORDER_CREATED: EVENT_ORDER_CREATED,
        EVENT_CANCELLED: EVENT_CANCELLED,
        EVENT_ANOMALY: EVENT_ANOMALY,
        emitOrderCreated: emitOrderCreated,
        emitSuccess: emitSuccess,
        emitCancelled: emitCancelled,
        emitPaymentBridge: emitPaymentBridge,
        bootFromDom: bootFromDom,
        validatePayload: validatePayload,
    };

    global.WelineCheckout = Object.assign(global.WelineCheckout || {}, api);
    boot();
})(typeof window !== 'undefined' ? window : this);
