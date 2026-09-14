(function () {
    'use strict';

    var guestTokenStorageKey = 'weline.cart.guest_token';

    function formatAmount(minor, precision) {
        var scale = Math.pow(10, precision || 2);
        return (minor / scale).toFixed(precision || 2);
    }

    function currencySymbol(currency) {
        currency = String(currency || 'CNY').toUpperCase();
        if (currency === 'USD') {
            return '$';
        }
        if (currency === 'EUR') {
            return '€';
        }
        if (currency === 'GBP') {
            return '£';
        }
        return '¥';
    }

    function formatDiscountLabel(payload) {
        if (!payload || Number(payload.amount_minor || 0) <= 0) {
            return '';
        }
        var code = String(payload.currency || 'CNY').toUpperCase();
        return '-' + code + ' ' + formatAmount(
            Number(payload.amount_minor || 0),
            payload.currency_precision || 2,
        );
    }

    function guestToken() {
        try {
            return String(window.sessionStorage.getItem(guestTokenStorageKey) || '').trim();
        } catch (e) {
            return '';
        }
    }

    function miniCartBusyDelta(delta) {
        window.dispatchEvent(new CustomEvent('weshop:mini-cart:busy', {
            detail: { delta: Number(delta) || 0 },
        }));
    }

    async function waitForMarketingApi() {
        if (window.Weline && window.Weline.Api && typeof window.Weline.Api.resource === 'function') {
            return window.Weline.Api.resource('marketing');
        }
        if (window.Weline && typeof window.Weline.use === 'function') {
            await window.Weline.use('api');
        }
        if (!window.Weline || !window.Weline.Api || typeof window.Weline.Api.resource !== 'function') {
            throw new Error('Weline.Api unavailable');
        }
        return window.Weline.Api.resource('marketing');
    }

    async function waitForCartApi() {
        if (window.Weline && window.Weline.Api && typeof window.Weline.Api.resource === 'function') {
            return window.Weline.Api.resource('cart');
        }
        if (window.Weline && typeof window.Weline.use === 'function') {
            await window.Weline.use('api');
        }
        if (!window.Weline || !window.Weline.Api || typeof window.Weline.Api.resource !== 'function') {
            throw new Error('Weline.Api unavailable');
        }
        return window.Weline.Api.resource('cart');
    }

    function cartItemsFromResponse(cartResponse) {
        if (!cartResponse || typeof cartResponse !== 'object') {
            return [];
        }
        if (Array.isArray(cartResponse.items)) {
            return cartResponse.items;
        }
        if (cartResponse.data && Array.isArray(cartResponse.data.items)) {
            return cartResponse.data.items;
        }
        if (cartResponse.summary && Array.isArray(cartResponse.summary.items)) {
            return cartResponse.summary.items;
        }
        if (cartResponse.data && cartResponse.data.summary && Array.isArray(cartResponse.data.summary.items)) {
            return cartResponse.data.summary.items;
        }
        return [];
    }

    function resolveCartType(root, forcedType) {
        if (forcedType === 'tob' || forcedType === 'toc') {
            return forcedType;
        }
        var host = root && (root.closest('[data-cart-type]') || root.closest('[data-w-mini-cart="1"]') || root.closest('[data-selling-mode]'));
        if (!host) {
            host = document.querySelector('[data-w-mini-cart="1"]')
                || document.querySelector('.weline-checkout[data-cart-type]')
                || document.documentElement;
        }
        var fromAttr = String(
            (host && (host.getAttribute('data-cart-type') || host.getAttribute('data-selling-mode')))
            || document.documentElement.getAttribute('data-selling-mode')
            || 'toc'
        ).toLowerCase();
        return fromAttr === 'tob' ? 'tob' : (fromAttr || 'toc');
    }

    function withCartType(root, payload, forcedType) {
        var next = payload && typeof payload === 'object' ? Object.assign({}, payload) : {};
        next.cart_type = resolveCartType(root, forcedType);
        next.selling_mode = next.cart_type;
        return next;
    }

    async function buildQuotePayload(root, code, forcedType) {
        var payload = withCartType(root, {
            coupon_code: code,
            currency: 'CNY',
            currency_precision: 2,
            lines: [],
        }, forcedType);
        try {
            var cartClient = await waitForCartApi();
            var params = withCartType(root, {}, forcedType);
            var token = guestToken();
            if (token) {
                params.guest_token = token;
                payload.guest_token = token;
            }
            var cartResponse = await cartClient.getCart(params);
            payload.lines = cartItemsFromResponse(cartResponse).map(function (item) {
                var qty = Number(item.qty || item.quantity || 1);
                var unitMinor = item.unit_price_minor != null
                    ? Number(item.unit_price_minor)
                    : Math.round(Number(item.price || 0) * 100);
                return {
                    qty_minor: Math.max(1, Math.round(qty)),
                    unit_price_minor: Math.max(0, unitMinor),
                    sku: String(item.sku || '').trim(),
                    product_id: Number(item.product_id || 0) || 0,
                };
            });
            if (cartResponse && cartResponse.currency) {
                payload.currency = String(cartResponse.currency);
            } else if (cartResponse && cartResponse.summary && cartResponse.summary.currency) {
                payload.currency = String(cartResponse.summary.currency);
            } else if (cartResponse && cartResponse.data && cartResponse.data.currency) {
                payload.currency = String(cartResponse.data.currency);
            }
            if (forcedType === 'tob' || forcedType === 'toc') {
                payload.cart_type = forcedType;
                payload.selling_mode = forcedType;
            } else if (cartResponse && cartResponse.cart_type) {
                payload.cart_type = String(cartResponse.cart_type).toLowerCase() === 'tob' ? 'tob' : 'toc';
                payload.selling_mode = payload.cart_type;
            } else if (cartResponse && cartResponse.data && cartResponse.data.cart_type) {
                payload.cart_type = String(cartResponse.data.cart_type).toLowerCase() === 'tob' ? 'tob' : 'toc';
                payload.selling_mode = payload.cart_type;
            }
        } catch (e) {
            // Session apply can still succeed without quote lines (server resolves cart).
        }
        return payload;
    }

    async function init(root) {
        if (!root || root.dataset.marketingCouponReady === '1' || root.dataset.marketingCouponBinding === '1') {
            return;
        }

        var input = root.querySelector('[data-marketing-coupon-input]');
        var applyBtn = root.querySelector('[data-marketing-coupon-apply]');
        var entryEl = root.querySelector('[data-marketing-coupon-entry]');
        var tagsEl = root.querySelector('[data-marketing-coupon-tags]');
        var messageEl = root.querySelector('[data-marketing-coupon-message]');
        if (!input || !applyBtn || !entryEl || !tagsEl) {
            return;
        }

        root.dataset.marketingCouponBinding = '1';

        var client;
        try {
            client = await waitForMarketingApi();
        } catch (e) {
            delete root.dataset.marketingCouponBinding;
            return;
        }

        root.dataset.marketingCouponReady = '1';
        delete root.dataset.marketingCouponBinding;

        var applyDefaultLabel = String(applyBtn.textContent || '').trim();

        function i18n(attr, fallback) {
            var value = String(root.getAttribute(attr) || '').trim();
            return value || fallback;
        }

        function setApplyLoading(loading) {
            applyBtn.disabled = loading;
            applyBtn.classList.toggle('is-loading', loading);
            applyBtn.setAttribute('aria-busy', loading ? 'true' : 'false');
            if (loading) {
                var loadingLabel = String(applyBtn.getAttribute('data-i18n-loading') || '').trim();
                applyBtn.textContent = loadingLabel || applyDefaultLabel;
            } else {
                applyBtn.textContent = applyDefaultLabel;
            }
        }

        function setRemoving(loading) {
            tagsEl.querySelectorAll('[data-marketing-coupon-tag]').forEach(function (tag) {
                tag.disabled = loading;
            });
        }

        function setMessage(text, isError) {
            if (!messageEl) {
                return;
            }
            messageEl.textContent = text || '';
            messageEl.classList.toggle('is-error', Boolean(isError));
            if (!isError) {
                messageEl.style.color = '';
            }
        }

        function discountPreviewFromQuote(code, discount) {
            if (!discount || Number(discount.amount_minor || 0) <= 0) {
                return null;
            }
            var couponCode = String(code || discount.coupon_code || '').trim().toUpperCase();
            var precision = Number(discount.currency_precision == null ? 2 : discount.currency_precision);
            var amountMinor = Number(discount.amount_minor || 0);
            var items = [];
            if (Array.isArray(discount.lines)) {
                discount.lines.forEach(function (line) {
                    if (!line || typeof line !== 'object') {
                        return;
                    }
                    var major = Number(line.discount_amount || 0);
                    if (major <= 0) {
                        return;
                    }
                    var lineCode = String(line.coupon_code || couponCode || '').trim().toUpperCase();
                    items.push({
                        label: lineCode || String(line.rule_name || discount.label || '').trim(),
                        source: String(line.source || 'coupon'),
                        coupon_code: lineCode,
                        amount_minor: Math.round(major * Math.pow(10, precision)),
                        stackable: false,
                        kind: String(line.source || '') === 'coupon' ? 'coupon' : 'automatic',
                    });
                });
            }
            if (!items.length && amountMinor > 0) {
                items.push({
                    label: couponCode || String(discount.label || '').trim(),
                    source: 'coupon',
                    coupon_code: couponCode,
                    amount_minor: amountMinor,
                    stackable: false,
                    kind: 'coupon',
                });
            }
            return {
                label: String(discount.label || '预计优惠'),
                amount_minor: amountMinor,
                currency: String(discount.currency || 'CNY').toUpperCase(),
                currency_precision: precision,
                coupon_code: couponCode,
                items: items,
                read_only: true,
            };
        }

        function notifyCartDiscountChanged(extra) {
            var detail = Object.assign({ refresh: true, forceNetwork: true }, extra && typeof extra === 'object' ? extra : {});
            window.dispatchEvent(new CustomEvent('weline:cart-updated', {
                detail: detail,
            }));
        }

        function renderTags(coupons) {
            tagsEl.innerHTML = '';
            var list = Array.isArray(coupons) ? coupons : [];
            if (!list.length) {
                tagsEl.hidden = true;
                root.classList.remove('is-applied');
                return;
            }

            list.forEach(function (item) {
                var code = String(item.code || '').trim();
                if (!code) {
                    return;
                }
                var tag = document.createElement('button');
                tag.type = 'button';
                tag.className = 'w-marketing-checkout-coupon__tag';
                tag.setAttribute('data-marketing-coupon-tag', code);
                tag.setAttribute('aria-label', '移除优惠券 ' + code);

                var codeSpan = document.createElement('span');
                codeSpan.className = 'w-marketing-checkout-coupon__tag-code';
                codeSpan.textContent = code;
                tag.appendChild(codeSpan);

                var amountLabel = formatDiscountLabel(item.discount || null);
                if (amountLabel) {
                    var amountSpan = document.createElement('span');
                    amountSpan.className = 'w-marketing-checkout-coupon__tag-amount';
                    amountSpan.textContent = amountLabel;
                    tag.appendChild(amountSpan);
                }

                var closeSpan = document.createElement('span');
                closeSpan.className = 'w-marketing-checkout-coupon__tag-close';
                closeSpan.setAttribute('aria-hidden', 'true');
                closeSpan.textContent = '×';
                tag.appendChild(closeSpan);

                tag.addEventListener('click', function () {
                    removeCouponCode();
                });
                tagsEl.appendChild(tag);
            });

            tagsEl.hidden = tagsEl.childElementCount === 0;
            root.classList.toggle('is-applied', tagsEl.childElementCount > 0);
        }

        function syncAppliedState(code, discountPayload) {
            var normalized = String(code || '').trim();
            if (!normalized) {
                renderTags([]);
                return;
            }
            renderTags([{ code: normalized, discount: discountPayload || null }]);
            input.value = '';
        }

        function clearAppliedState() {
            input.value = '';
            renderTags([]);
        }

        function quoteSavedCoupon(code, forcedType, opts) {
            opts = opts && typeof opts === 'object' ? opts : {};
            if (!code) {
                return Promise.resolve(null);
            }
            var type = resolveCartType(root, forcedType);
            if (type !== 'toc') {
                clearAppliedState();
                return Promise.resolve(null);
            }
            return buildQuotePayload(root, code, type).then(function (payload) {
                return client.quoteDiscount(payload);
            }).then(function (quoteResponse) {
                var discount = quoteResponse && quoteResponse.success ? quoteResponse.discount : null;
                if (discount && Number(discount.amount_minor || 0) > 0) {
                    syncAppliedState(code, discount);
                    // Soft notify: update checkout totals without re-entering hydrate storms.
                    notifyCartDiscountChanged({
                        refresh: false,
                        coupon_code: String(code || '').trim().toUpperCase(),
                        discount: discount,
                        discount_preview: discountPreviewFromQuote(code, discount),
                    });
                    return quoteResponse;
                }
                // Zero-amount ghost coupons confuse checkout (tag without 优惠行). Clear them.
                clearAppliedState();
                if (resolveCartType(root, type) === 'toc') {
                    return client.removeCoupon(withCartType(root, {}, type), { silent: true }).then(function () {
                        notifyCartDiscountChanged({
                            refresh: false,
                            coupon_code: '',
                            clear_discount: true,
                            discount_preview: null,
                        });
                        return quoteResponse;
                    }).catch(function () {
                        return quoteResponse;
                    });
                }
                return quoteResponse;
            });
        }

        function applyCouponCode(code) {
            var normalized = String(code || '').trim().toUpperCase();
            if (!normalized) {
                setMessage(i18n('data-i18n-enter-code', '请输入优惠券代码'), true);
                return Promise.resolve(null);
            }
            if (resolveCartType(root) !== 'toc' || root.classList.contains('is-tob-unavailable')) {
                clearAppliedState();
                setMessage(i18n('data-i18n-coupon-tob-unavailable', '批发不可用'), true);
                return Promise.resolve(null);
            }
            var existingTags = tagsEl.querySelectorAll('[data-marketing-coupon-tag]');
            for (var i = 0; i < existingTags.length; i++) {
                if (String(existingTags[i].getAttribute('data-marketing-coupon-tag') || '').toUpperCase() === normalized) {
                    setMessage(i18n('data-i18n-already-applied', '该优惠券已应用'), true);
                    input.value = '';
                    return Promise.resolve(null);
                }
            }
            applyBtn.disabled = true;
            setApplyLoading(true);
            miniCartBusyDelta(1);
            // Persist with cart lines so invalid/zero-amount codes are rejected before session write.
            return buildQuotePayload(root, normalized, 'toc').then(function (payload) {
                return client.applyCoupon(payload, { silent: true });
            }).then(function (response) {
                if (!response || !response.success) {
                    setMessage((response && response.message) || i18n('data-i18n-unavailable', '优惠券不可用'), true);
                    return null;
                }
                var appliedCode = String(response.coupon_code || normalized);
                setMessage('', false);
                input.value = '';
                return quoteSavedCoupon(appliedCode, 'toc', { restoreOnly: false }).then(function (quoteResponse) {
                    var discount = quoteResponse && quoteResponse.success ? quoteResponse.discount : null;
                    if (!discount || Number(discount.amount_minor || 0) <= 0) {
                        setMessage(i18n('data-i18n-invalid-limit', '优惠券无效、不可用或已达使用上限'), true);
                        notifyCartDiscountChanged({
                            coupon_code: '',
                            clear_discount: true,
                            discount_preview: null,
                        });
                        return quoteResponse;
                    }
                    notifyCartDiscountChanged({
                        coupon_code: appliedCode,
                        discount: discount,
                        discount_preview: discountPreviewFromQuote(appliedCode, discount),
                    });
                    return quoteResponse;
                });
            }).catch(function (error) {
                var apiMessage = '';
                try {
                    apiMessage = String(
                        (error && error.response && error.response.data && error.response.data.data && error.response.data.data.message)
                        || (error && error.response && error.response.data && error.response.data.message)
                        || (error && error.message)
                        || ''
                    ).trim();
                } catch (e) {}
                if (apiMessage && apiMessage.indexOf('WelineApi') === -1 && apiMessage.indexOf('handleWorkerMessage') === -1) {
                    setMessage(apiMessage, true);
                } else {
                    setMessage(i18n('data-i18n-service-unavailable', '营销服务暂时不可用'), true);
                }
                return null;
            }).finally(function () {
                miniCartBusyDelta(-1);
                setApplyLoading(false);
            });
        }

        function removeCouponCode() {
            setRemoving(true);
            miniCartBusyDelta(1);
            return client.removeCoupon(withCartType(root, {}), { silent: true }).then(function (response) {
                clearAppliedState();
                setMessage((response && response.message) || i18n('data-i18n-removed', '已移除优惠券'), false);
                notifyCartDiscountChanged({
                    coupon_code: '',
                    clear_discount: true,
                    discount_preview: null,
                });
            }).catch(function () {
                setMessage(i18n('data-i18n-service-unavailable', '营销服务暂时不可用'), true);
            }).finally(function () {
                miniCartBusyDelta(-1);
                setRemoving(false);
            });
        }

        function hydrateForCurrentType(forcedType) {
            var type = resolveCartType(root, forcedType);
            if (type !== 'toc') {
                clearAppliedState();
                return Promise.resolve(null);
            }
            // DOM may still say tob until syncMiniCartChrome runs; trust event-forced toc.
            root.classList.remove('is-tob-unavailable');
            root.setAttribute('data-b2b-coupon-unavailable', '0');
            root.querySelectorAll('[data-marketing-coupon-input], [data-marketing-coupon-apply]').forEach(function (el) {
                if ('disabled' in el) {
                    el.disabled = false;
                }
                el.removeAttribute('tabindex');
            });
            if (messageEl && messageEl.getAttribute('data-b2b-coupon-msg') === '1') {
                messageEl.textContent = '';
                messageEl.removeAttribute('data-b2b-coupon-msg');
            }
            return client.getCoupon(withCartType(root, {}, 'toc')).then(function (response) {
                var savedCode = response && response.success ? String(response.coupon_code || '').trim() : '';
                if (!savedCode || response.coupons_allowed === false) {
                    clearAppliedState();
                    return null;
                }
                return quoteSavedCoupon(savedCode, 'toc', { restoreOnly: true });
            }).catch(function () {
                // ignore preload failures
            });
        }

        applyBtn.addEventListener('click', function () {
            applyCouponCode(String(input.value || '').trim());
        });

        hydrateForCurrentType();
        window.addEventListener('weline:selling-mode-changed', function (event) {
            var detail = event && event.detail && typeof event.detail === 'object' ? event.detail : {};
            var mode = String(detail.selling_mode || detail.cart_type || '').toLowerCase();
            hydrateForCurrentType(mode === 'tob' || mode === 'toc' ? mode : null);
        });
        // After mini-cart network refresh finishes switching type, restore tags again.
        window.addEventListener('weline:cart-updated', function (event) {
            var detail = event && event.detail && typeof event.detail === 'object' ? event.detail : {};
            if (detail && detail.refresh === false) {
                return;
            }
            if (resolveCartType(root) === 'toc') {
                hydrateForCurrentType('toc');
            }
        });
    }

    function boot() {
        document.querySelectorAll('.w-marketing-checkout-coupon').forEach(function (root) {
            init(root);
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }

    window.addEventListener('weshop:mini-cart:open', boot);
    window.addEventListener('weshop:mini-cart:extras-ready', boot);
})();
