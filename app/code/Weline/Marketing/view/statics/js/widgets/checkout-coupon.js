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
        return '-' + currencySymbol(payload.currency) + formatAmount(
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
        if (cartResponse.summary && Array.isArray(cartResponse.summary.items)) {
            return cartResponse.summary.items;
        }
        return [];
    }

    async function buildQuotePayload(code) {
        var payload = {
            coupon_code: code,
            currency: 'CNY',
            currency_precision: 2,
            lines: [],
        };
        try {
            var cartClient = await waitForCartApi();
            var params = {};
            var token = guestToken();
            if (token) {
                params.guest_token = token;
            }
            var cartResponse = await cartClient.getV2Cart(params);
            payload.lines = cartItemsFromResponse(cartResponse).map(function (item) {
                var qty = Number(item.qty || item.quantity || 1);
                var unitMinor = item.unit_price_minor != null
                    ? Number(item.unit_price_minor)
                    : Math.round(Number(item.price || 0) * 100);
                return {
                    qty_minor: Math.max(1, Math.round(qty)),
                    unit_price_minor: Math.max(0, unitMinor),
                };
            });
            if (cartResponse && cartResponse.currency) {
                payload.currency = String(cartResponse.currency);
            } else if (cartResponse && cartResponse.summary && cartResponse.summary.currency) {
                payload.currency = String(cartResponse.summary.currency);
            }
        } catch (e) {
            // Session apply can still succeed without quote lines.
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

        function notifyCartDiscountChanged() {
            window.dispatchEvent(new CustomEvent('weline:cart-updated', {
                detail: { refresh: true },
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

        function quoteSavedCoupon(code) {
            if (!code) {
                return Promise.resolve(null);
            }
            return buildQuotePayload(code).then(function (payload) {
                return client.quoteDiscount(payload);
            }).then(function (quoteResponse) {
                var discount = quoteResponse && quoteResponse.success ? quoteResponse.discount : null;
                if (discount) {
                    syncAppliedState(code, discount);
                }
                return quoteResponse;
            });
        }

        function applyCouponCode(code) {
            var normalized = String(code || '').trim().toUpperCase();
            if (!normalized) {
                setMessage('请输入优惠券代码', true);
                return Promise.resolve(null);
            }
            var existingTags = tagsEl.querySelectorAll('[data-marketing-coupon-tag]');
            for (var i = 0; i < existingTags.length; i++) {
                if (String(existingTags[i].getAttribute('data-marketing-coupon-tag') || '').toUpperCase() === normalized) {
                    setMessage('该优惠券已应用', true);
                    input.value = '';
                    return Promise.resolve(null);
                }
            }
            applyBtn.disabled = true;
            setApplyLoading(true);
            miniCartBusyDelta(1);
            return client.applyCoupon({ coupon_code: normalized }, { silent: true }).then(function (response) {
                if (!response || !response.success) {
                    setMessage((response && response.message) || '优惠券不可用', true);
                    return null;
                }
                var appliedCode = String(response.coupon_code || normalized);
                setMessage('', false);
                input.value = '';
                return quoteSavedCoupon(appliedCode).then(function (quoteResponse) {
                    notifyCartDiscountChanged();
                    return quoteResponse;
                });
            }).catch(function () {
                setMessage('营销服务暂时不可用', true);
                return null;
            }).finally(function () {
                miniCartBusyDelta(-1);
                setApplyLoading(false);
            });
        }

        function removeCouponCode() {
            setRemoving(true);
            miniCartBusyDelta(1);
            return client.removeCoupon({}, { silent: true }).then(function (response) {
                clearAppliedState();
                setMessage((response && response.message) || '已移除优惠券', false);
                notifyCartDiscountChanged();
            }).catch(function () {
                setMessage('营销服务暂时不可用', true);
            }).finally(function () {
                miniCartBusyDelta(-1);
                setRemoving(false);
            });
        }

        applyBtn.addEventListener('click', function () {
            applyCouponCode(String(input.value || '').trim());
        });

        client.getCoupon({}).then(function (response) {
            var savedCode = response && response.success ? String(response.coupon_code || '').trim() : '';
            if (!savedCode) {
                return null;
            }
            return quoteSavedCoupon(savedCode);
        }).catch(function () {
            // ignore preload failures
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
