(function (global) {
    'use strict';

    function readMode() {
        if (global.WelineB2BSellingMode && typeof global.WelineB2BSellingMode.preferredMode === 'function') {
            return global.WelineB2BSellingMode.preferredMode();
        }
        if (global.WelineB2BSellingMode && typeof global.WelineB2BSellingMode.readCookie === 'function') {
            var fromApi = String(global.WelineB2BSellingMode.readCookie() || '').toLowerCase();
            return fromApi === 'tob' ? 'tob' : 'toc';
        }
        var names = [];
        var site = global.site || {};
        var websiteId = String(site.website_id || site.websiteId || '').trim();
        if (websiteId !== '' && /^\d+$/.test(websiteId)) {
            names.push('weline_selling_mode_w' + websiteId);
        }
        String(document.cookie || '').split(';').forEach(function (part) {
            var key = String(part.split('=')[0] || '').trim();
            if (/^weline_selling_mode_w\d+$/.test(key) && names.indexOf(key) === -1) {
                names.push(key);
            }
        });
        names.push('weline_selling_mode');
        for (var i = 0; i < names.length; i += 1) {
            var match = document.cookie.match(new RegExp('(?:^|; )' + names[i].replace(/([.$?*|{}()[\]\\/+^])/g, '\\$1') + '=([^;]*)'));
            var value = match ? decodeURIComponent(match[1]).toLowerCase() : '';
            if (value === 'tob' || value === 'toc') {
                return value === 'tob' ? 'tob' : 'toc';
            }
        }
        return 'toc';
    }

    function syncCheckoutCouponAvailability(root, mode) {
        if (!root) {
            return;
        }
        var unavailable = mode === 'tob';
        var message = '批发不可用';
        if (global.WelineB2BSellingMode && typeof global.WelineB2BSellingMode.i18n === 'function') {
            message = global.WelineB2BSellingMode.i18n('data-i18n-coupon-tob-unavailable', message);
        } else {
            var boot = document.querySelector('[data-b2b-mini-cart-type="1"]');
            if (boot) {
                message = String(boot.getAttribute('data-i18n-coupon-tob-unavailable') || message);
            }
        }
        if (global.WelineB2BSellingMode && typeof global.WelineB2BSellingMode.syncCouponAvailability === 'function') {
            global.WelineB2BSellingMode.syncCouponAvailability(root, mode);
            return;
        }
        root.querySelectorAll(
            '.w-marketing-checkout-coupon, [data-testid="marketing-checkout-coupon"], [data-marketing-checkout-coupon]'
        ).forEach(function (coupon) {
            coupon.hidden = false;
            coupon.removeAttribute('hidden');
            coupon.classList.toggle('is-tob-unavailable', unavailable);
            coupon.setAttribute('aria-disabled', unavailable ? 'true' : 'false');
            coupon.setAttribute('data-b2b-coupon-unavailable', unavailable ? '1' : '0');
            coupon.querySelectorAll('[data-marketing-coupon-input], [data-marketing-coupon-apply]').forEach(function (el) {
                if ('disabled' in el) {
                    el.disabled = unavailable;
                }
                if (unavailable) {
                    el.setAttribute('tabindex', '-1');
                } else {
                    el.removeAttribute('tabindex');
                }
            });
            if (unavailable) {
                coupon.querySelectorAll('[data-marketing-coupon-tag]').forEach(function (tag) {
                    tag.remove();
                });
                var tagsEl = coupon.querySelector('[data-marketing-coupon-tags]');
                if (tagsEl) {
                    tagsEl.innerHTML = '';
                    tagsEl.hidden = true;
                }
                coupon.classList.remove('is-applied');
            }
            var msg = coupon.querySelector('[data-marketing-coupon-message]');
            if (msg) {
                if (unavailable) {
                    msg.textContent = message;
                    msg.setAttribute('data-b2b-coupon-msg', '1');
                    msg.hidden = false;
                    msg.removeAttribute('hidden');
                } else if (msg.getAttribute('data-b2b-coupon-msg') === '1') {
                    msg.textContent = '';
                    msg.removeAttribute('data-b2b-coupon-msg');
                }
            }
        });
    }

    function applyCartType(root, cartType) {
        if (!root) {
            return;
        }
        var type = String(cartType || readMode() || 'toc').toLowerCase() === 'tob' ? 'tob' : 'toc';
        root.setAttribute('data-cart-type', type);
        root.classList.toggle('is-cart-type-tob', type === 'tob');
        var note = root.querySelector('[data-b2b-deposit-note]');
        if (note) {
            note.hidden = type !== 'tob';
        }
        // 与迷你车同构：保留优惠券页签，灰化禁用并提示「批发不可用」，禁止整槽隐藏。
        var couponSlot = root.querySelector('.weline-checkout__coupon-slot');
        if (couponSlot) {
            couponSlot.hidden = false;
            couponSlot.removeAttribute('hidden');
            if (couponSlot.style && couponSlot.style.display === 'none') {
                couponSlot.style.removeProperty('display');
            }
        }
        var extras = root.querySelector('.weline-checkout__extras');
        if (extras) {
            extras.hidden = false;
            extras.removeAttribute('hidden');
            extras.classList.remove('is-tob-discounts-banned');
        }
        syncCheckoutCouponAvailability(root, type);
    }

    function hangPurposeFromLocation() {
        try {
            var params = new URLSearchParams(global.location.search || '');
            var purpose = String(params.get('purpose') || '').toLowerCase();
            var orderUuid = String(params.get('order_uuid') || '').trim();
            if ((purpose === 'deposit' || purpose === 'balance') && orderUuid) {
                return { purpose: purpose, orderUuid: orderUuid };
            }
        } catch (e) {}
        return null;
    }

    /** Path+query for CustomerAuthReturnUrlService (no leading slash). */
    function hangReturnTarget() {
        try {
            var path = String(global.location.pathname || '/').replace(/^\//, '');
            var search = String(global.location.search || '');
            return path + search;
        } catch (e) {
            return 'checkout';
        }
    }

    function hangLoginUrl() {
        return '/customer/account/login?redirect_url=' + encodeURIComponent(hangReturnTarget());
    }

    function isHangAuthError(err, ctx) {
        var msg = String(
            (err && (err.message || err.msg))
            || (ctx && (ctx.message || ctx.error || ctx.detail))
            || ''
        );
        var code = String(
            (err && (err.code || err.error_code))
            || (ctx && (ctx.code || ctx.error_code || ctx.error))
            || ''
        ).toLowerCase();
        return code.indexOf('auth') !== -1
            || msg.indexOf('请先登录') !== -1
            || msg.indexOf('b2b_hang_payment_auth') !== -1
            || /授权/.test(msg);
    }

    function suppressRetailEmptyChrome(root) {
        if (!root) {
            return;
        }
        var empty = root.querySelector('[data-checkout-empty]');
        if (empty) {
            empty.hidden = true;
        }
        var recovery = root.querySelector('[data-checkout-payment-recovery]');
        if (recovery) {
            recovery.hidden = true;
        }
        var notice = root.querySelector('[data-checkout-message]');
        if (notice) {
            notice.hidden = true;
        }
        var form = root.querySelector('[data-checkout-form], form');
        if (form) {
            form.hidden = true;
        }
        var express = root.querySelector('[data-checkout-express-host]');
        if (express) {
            express.hidden = true;
        }
    }

    function selectedPaymentMethod(root) {
        var checked = root.querySelector('input[name="payment_method"]:checked');
        if (checked && checked.value) {
            return String(checked.value);
        }
        var first = root.querySelector('input[name="payment_method"]');
        return first && first.value ? String(first.value) : 'fake_card';
    }

    function formatMinor(amountMinor, currency) {
        var major = (Number(amountMinor) || 0) / 100;
        try {
            return new Intl.NumberFormat(undefined, {
                style: 'currency',
                currency: currency || 'CNY'
            }).format(major);
        } catch (e) {
            return major.toFixed(2) + ' ' + (currency || 'CNY');
        }
    }

    function ensureHangPanel(root) {
        var existing = root.querySelector('[data-b2b-hang-payment]');
        if (existing) {
            return existing;
        }
        var panel = document.createElement('section');
        panel.className = 'b2b-hang-payment';
        panel.setAttribute('data-b2b-hang-payment', '1');
        panel.setAttribute('data-testid', 'b2b-hang-payment');
        panel.innerHTML = ''
            + '<h2 class="b2b-hang-payment__title" data-b2b-hang-title></h2>'
            + '<p class="b2b-hang-payment__amount" data-b2b-hang-amount></p>'
            + '<p class="b2b-hang-payment__status" data-b2b-hang-status hidden></p>'
            + '<button type="button" class="w-button" data-variant="primary" data-b2b-hang-pay data-testid="b2b-hang-pay">'
            + '</button>';
        var form = root.querySelector('form') || root;
        form.parentNode.insertBefore(panel, form);
        return panel;
    }

    function sendToLogin(statusEl, payBtn) {
        if (statusEl) {
            statusEl.hidden = false;
            statusEl.textContent = '请先登录后再继续支付，登录后将自动返回此页。';
        }
        if (payBtn) {
            payBtn.disabled = false;
            payBtn.textContent = '去登录';
            payBtn.setAttribute('data-b2b-hang-login', '1');
            payBtn.onclick = function () {
                global.location.assign(hangLoginUrl());
            };
        }
        global.location.assign(hangLoginUrl());
    }

    async function bindHangPayment(root) {
        var hang = hangPurposeFromLocation();
        if (!hang || !root) {
            return;
        }
        root.setAttribute('data-b2b-hang-mode', hang.purpose);
        suppressRetailEmptyChrome(root);
        var panel = ensureHangPanel(root);
        var titleEl = panel.querySelector('[data-b2b-hang-title]');
        var amountEl = panel.querySelector('[data-b2b-hang-amount]');
        var statusEl = panel.querySelector('[data-b2b-hang-status]');
        var payBtn = panel.querySelector('[data-b2b-hang-pay]');
        if (titleEl) {
            titleEl.textContent = hang.purpose === 'deposit' ? '支付定金' : '支付尾款';
        }
        if (payBtn) {
            payBtn.textContent = hang.purpose === 'deposit' ? '支付定金' : '支付尾款';
        }
        if (!global.Weline || !global.Weline.Api || typeof global.Weline.Api.resource !== 'function') {
            if (statusEl) {
                statusEl.hidden = false;
                statusEl.textContent = '支付接口不可用';
            }
            return;
        }
        var api = global.Weline.Api.resource('b2b');
        var ctx = null;
        try {
            ctx = await api['hang.paymentContext']({
                order_uuid: hang.orderUuid,
                purpose: hang.purpose
            }, { silent: true });
        } catch (err) {
            suppressRetailEmptyChrome(root);
            if (isHangAuthError(err, null)) {
                sendToLogin(statusEl, payBtn);
                return;
            }
            if (statusEl) {
                statusEl.hidden = false;
                statusEl.textContent = (err && err.message) || '无法加载挂单支付信息';
            }
            if (payBtn) {
                payBtn.disabled = true;
            }
            return;
        }
        if (!ctx || ctx.success === false || ctx.ok === false) {
            suppressRetailEmptyChrome(root);
            if (isHangAuthError(null, ctx)) {
                sendToLogin(statusEl, payBtn);
                return;
            }
            if (statusEl) {
                statusEl.hidden = false;
                statusEl.textContent = (ctx && ctx.message) || '无法加载挂单支付信息';
            }
            if (payBtn) {
                payBtn.disabled = true;
            }
            return;
        }
        if (titleEl && ctx.label) {
            titleEl.textContent = String(ctx.label);
        }
        if (amountEl) {
            amountEl.textContent = formatMinor(ctx.amount_minor, ctx.currency);
        }
        if (payBtn) {
            payBtn.textContent = String(ctx.label || payBtn.textContent);
            payBtn.addEventListener('click', async function () {
                payBtn.disabled = true;
                if (statusEl) {
                    statusEl.hidden = false;
                    statusEl.textContent = '正在发起支付…';
                }
                try {
                    var result = await api['hang.startPayment']({
                        order_uuid: hang.orderUuid,
                        purpose: hang.purpose,
                        payment_method: selectedPaymentMethod(root),
                        payment_idempotency_key: 'hang_' + hang.purpose + '_' + Date.now()
                    }, { silent: true });
                    if (!result || result.success === false || result.ok === false) {
                        if (isHangAuthError(null, result)) {
                            sendToLogin(statusEl, payBtn);
                            return;
                        }
                        throw new Error((result && result.message) || '支付失败');
                    }
                    var payment = result.payment || {};
                    var redirect = payment.redirect_url;
                    if (redirect) {
                        global.location.assign(redirect);
                        return;
                    }
                    if (statusEl) {
                        statusEl.textContent = hang.purpose === 'deposit'
                            ? '定金已支付，等待商家审批'
                            : '尾款已支付';
                    }
                    global.location.assign('/customer/account/index?order_uuid='
                        + encodeURIComponent(hang.orderUuid) + '#orders');
                } catch (err) {
                    if (isHangAuthError(err, null)) {
                        sendToLogin(statusEl, payBtn);
                        return;
                    }
                    if (statusEl) {
                        statusEl.textContent = (err && err.message) || '支付失败';
                    }
                    payBtn.disabled = false;
                }
            });
        }
    }

    function bindCheckout() {
        var root = document.querySelector('[data-weline-checkout]');
        if (!root) {
            return;
        }
        applyCartType(root, readMode());
        bindHangPayment(root);
        global.addEventListener('weline:selling-mode-changed', function (event) {
            var mode = event && event.detail ? event.detail.cart_type || event.detail.selling_mode : readMode();
            applyCartType(root, mode);
        });
        global.addEventListener('weline:cart-updated', function (event) {
            var summary = event && event.detail ? event.detail : null;
            if (summary && summary.cart_type) {
                applyCartType(root, summary.cart_type);
            } else {
                applyCartType(root, readMode());
            }
            if (root.getAttribute('data-b2b-hang-mode')) {
                suppressRetailEmptyChrome(root);
            }
        });
        // 优惠券部件可能晚于本脚本挂到 slot，观察后补同步「批发不可用」。
        if (global.MutationObserver) {
            var pending = null;
            var observer = new MutationObserver(function () {
                if (pending) {
                    return;
                }
                pending = global.setTimeout(function () {
                    pending = null;
                    if (readMode() !== 'tob') {
                        return;
                    }
                    var coupon = root.querySelector('[data-marketing-checkout-coupon], .w-marketing-checkout-coupon');
                    if (!coupon) {
                        return;
                    }
                    // 仅补齐尚未标记的批发禁用态，避免与零售 preferredMode 互相覆盖。
                    if (coupon.getAttribute('data-b2b-coupon-unavailable') === '1') {
                        return;
                    }
                    applyCartType(root, 'tob');
                }, 50);
            });
            observer.observe(root, { childList: true, subtree: true });
        }
        global.WelineB2BCheckoutTob = {
            applyCartType: applyCartType,
            readMode: readMode,
            hangPurposeFromLocation: hangPurposeFromLocation,
            hangLoginUrl: hangLoginUrl
        };
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', bindCheckout);
    } else {
        bindCheckout();
    }
})(window);
