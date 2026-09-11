/**
 * Weline Payment — storefront payment lifecycle events (module-owned).
 * Cart / Theme listen; pages and Checkout call emitOutcome / bootFromDom.
 * DEV: missing order identity / amount / currency → anomaly log, no dispatch.
 */
(function (global) {
    'use strict';

    var EVENT_OUTCOME = 'weline:payment:outcome';
    var EVENT_PAID = 'weline:payment:paid';
    var EVENT_PENDING = 'weline:payment:pending';
    var EVENT_FAILED = 'weline:payment:failed';
    var EVENT_CANCELLED = 'weline:payment:cancelled';
    var EVENT_ANOMALY = 'weline:payment:anomaly';

    var OUTCOME_EVENTS = {
        paid: EVENT_PAID,
        pending: EVENT_PENDING,
        failed: EVENT_FAILED,
        cancelled: EVENT_CANCELLED,
        cancel: EVENT_CANCELLED,
    };

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
            console.info('[WelinePayment]', eventName, detail || {});
        } catch (eLog) {}
    }

    function anomaly(reason, detail, missing) {
        var payload = Object.assign({}, detail && typeof detail === 'object' ? detail : {}, {
            reason: text(reason) || 'invalid-lifecycle-payload',
            missing: Array.isArray(missing) ? missing.slice() : [],
        });
        try {
            if (isDevMode()) {
                console.warn('[WelinePayment][anomaly] 跳过派发：缺少订单字段', payload);
            }
            global.dispatchEvent(new CustomEvent(EVENT_ANOMALY, { detail: payload }));
        } catch (eAnomaly) {}
        return false;
    }

    function normalizeOutcome(raw) {
        var outcome = text(raw).trim().toLowerCase();
        if (outcome === 'success' || outcome === 'completed' || outcome === 'complete') {
            return 'paid';
        }
        if (outcome === 'cancel') {
            return 'cancelled';
        }
        if (OUTCOME_EVENTS[outcome]) {
            return outcome === 'cancel' ? 'cancelled' : outcome;
        }
        return '';
    }

    function enrichFromDom(detail) {
        var payload = detail && typeof detail === 'object' ? Object.assign({}, detail) : {};
        try {
            var root = global.document && global.document.querySelector
                ? (global.document.querySelector('[data-payment-lifecycle], [data-testid="payment-return"], [data-testid="payment-success"], [data-testid="payment-handoff"], [data-checkout-payment-recovery]')
                    || global.document.querySelector('main.amz-order-confirm'))
                : null;
            if (!root) {
                return payload;
            }
            if (!payload.order_uuid) {
                payload.order_uuid = text(root.getAttribute('data-order-uuid') || '').trim();
            }
            if (!payload.transaction_id) {
                payload.transaction_id = text(root.getAttribute('data-transaction-id') || '').trim();
            }
            if (!payload.checkout_session_code) {
                payload.checkout_session_code = text(root.getAttribute('data-checkout-session-code') || '').trim();
            }
            if (!payload.amount) {
                payload.amount = text(root.getAttribute('data-pixel-value')
                    || root.getAttribute('data-amount')
                    || root.getAttribute('data-order-amount')
                    || '').trim();
            }
            if (!payload.currency) {
                payload.currency = text(root.getAttribute('data-pixel-currency')
                    || root.getAttribute('data-currency')
                    || '').trim();
            }
            if (!payload.order_number) {
                var orderNo = root.querySelector('.amz-order-confirm__order-no strong, [data-order-number]');
                if (orderNo) {
                    payload.order_number = text(orderNo.textContent).trim();
                }
            }
        } catch (eDom) {}
        return payload;
    }

    /**
     * Require order identity + amount + currency before any payment lifecycle dispatch.
     */
    function validatePayload(detail) {
        var payload = enrichFromDom(detail);
        var missing = [];
        var hasIdentity = !!(text(payload.order_uuid).trim()
            || text(payload.order_number).trim()
            || text(payload.transaction_id).trim()
            || text(payload.checkout_session_code).trim()
            || text(payload.checkout_group_uuid).trim());
        if (!hasIdentity) {
            missing.push('order_uuid|order_number|transaction_id|checkout_session_code');
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

    function emitOutcome(outcome, detail) {
        var normalized = normalizeOutcome(outcome);
        if (!normalized) {
            return anomaly('unknown-outcome', detail, ['outcome']);
        }
        var checked = validatePayload(detail);
        if (!checked.ok) {
            return anomaly('missing-order-fields', Object.assign({}, checked.payload, {
                outcome: normalized,
            }), checked.missing);
        }
        var payload = checked.payload;
        payload.outcome = normalized;
        payload.source = text(payload.source || 'payment-lifecycle').trim() || 'payment-lifecycle';
        try {
            global.dispatchEvent(new CustomEvent(EVENT_OUTCOME, { detail: payload }));
            global.dispatchEvent(new CustomEvent(OUTCOME_EVENTS[normalized], { detail: payload }));
            devLog(EVENT_OUTCOME + ' + ' + OUTCOME_EVENTS[normalized], payload);
        } catch (eDispatch) {
            return false;
        }
        return true;
    }

    function outcomeFromLocation() {
        try {
            var path = text(global.location && global.location.pathname);
            var q = new URLSearchParams((global.location && global.location.search) || '');
            var outcome = normalizeOutcome(q.get('outcome') || q.get('status') || '');
            if (/\/payment\/success(?:\.html)?\/?$/i.test(path)) {
                return outcome || 'paid';
            }
            if (/\/payment\/handoff(?:\.html)?\/?$/i.test(path)) {
                return outcome || 'pending';
            }
            if (/\/payment\/(?:frontend\/)?(?:callback\/)?(?:browser-)?return/i.test(path)
                || /\/payment\/(?:checkout\/)?return/i.test(path)) {
                return outcome || '';
            }
        } catch (eLoc) {}
        return '';
    }

    function bootFromDom(options) {
        var opts = options && typeof options === 'object' ? options : {};
        var root = opts.root
            || (global.document && global.document.querySelector
                ? (global.document.querySelector('[data-payment-lifecycle]')
                    || global.document.querySelector('[data-testid="payment-success"]')
                    || global.document.querySelector('[data-testid="payment-return"]')
                    || global.document.querySelector('[data-testid="payment-handoff"]'))
                : null);
        var outcome = '';
        var detail = {
            source: text(opts.source || 'payment-page-boot').trim() || 'payment-page-boot',
        };
        if (root) {
            outcome = normalizeOutcome(root.getAttribute('data-payment-outcome')
                || root.getAttribute('data-payment-lifecycle')
                || '');
            detail.order_uuid = text(root.getAttribute('data-order-uuid') || '').trim();
            detail.transaction_id = text(root.getAttribute('data-transaction-id') || '').trim();
            detail.checkout_session_code = text(root.getAttribute('data-checkout-session-code') || '').trim();
            detail.amount = text(root.getAttribute('data-pixel-value') || root.getAttribute('data-amount') || '').trim();
            detail.currency = text(root.getAttribute('data-pixel-currency') || root.getAttribute('data-currency') || '').trim();
            if (!detail.source || detail.source === 'payment-page-boot') {
                detail.source = text(root.getAttribute('data-payment-event-source') || detail.source);
            }
        }
        if (!outcome) {
            outcome = outcomeFromLocation();
        }
        if (!outcome) {
            // On lifecycle surfaces without outcome attrs: still report anomaly in DEV.
            try {
                var path = text(global.location && global.location.pathname);
                if (/\/payment\/(?:success|handoff)/i.test(path) || (root && root.getAttribute('data-weline-load'))) {
                    return anomaly('missing-outcome-on-payment-surface', enrichFromDom(detail), ['outcome']);
                }
            } catch (ePath) {}
            return false;
        }
        return emitOutcome(outcome, detail);
    }

    function boot() {
        if (global.document && global.document.readyState === 'loading') {
            global.document.addEventListener('DOMContentLoaded', function () {
                bootFromDom({ source: 'payment-lifecycle-dom' });
            });
            return;
        }
        bootFromDom({ source: 'payment-lifecycle-dom' });
    }

    var api = {
        EVENT_OUTCOME: EVENT_OUTCOME,
        EVENT_PAID: EVENT_PAID,
        EVENT_PENDING: EVENT_PENDING,
        EVENT_FAILED: EVENT_FAILED,
        EVENT_CANCELLED: EVENT_CANCELLED,
        EVENT_ANOMALY: EVENT_ANOMALY,
        emitOutcome: emitOutcome,
        bootFromDom: bootFromDom,
        normalizeOutcome: normalizeOutcome,
        validatePayload: validatePayload,
    };

    global.WelinePayment = Object.assign(global.WelinePayment || {}, api);
    boot();
})(typeof window !== 'undefined' ? window : this);
