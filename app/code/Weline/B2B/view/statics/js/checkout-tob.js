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
        // 顶栏批发订单说明：仅批发结账显示。
        var note = root.querySelector('[data-b2b-deposit-note]');
        if (note) {
            note.hidden = type !== 'tob';
            if (type === 'tob') {
                note.removeAttribute('hidden');
            }
            note.setAttribute('data-cart-type', type);
        }
        // 摘要页签「批发信用」：常显；零售灰化，批发可用。
        var creditRoot = root.querySelector('[data-b2b-checkout-credit]');
        if (creditRoot) {
            creditRoot.hidden = false;
            creditRoot.removeAttribute('hidden');
            creditRoot.classList.toggle('is-toc-unavailable', type !== 'tob');
            creditRoot.setAttribute('aria-disabled', type !== 'tob' ? 'true' : 'false');
            creditRoot.setAttribute('data-cart-type', type);
            var reasonEl = creditRoot.querySelector('[data-b2b-credit-reason]');
            var switchCart = creditRoot.querySelector('[data-b2b-credit-switch-cart]');
            if (reasonEl) {
                if (type !== 'tob') {
                    reasonEl.textContent = creditRoot.getAttribute('data-i18n-toc-unavailable')
                        || '当前是零售结账，批发信用只能在「批发车」使用。请先到购物车切换到批发车，再回来结账。';
                    reasonEl.hidden = false;
                    reasonEl.removeAttribute('hidden');
                }
                // tob：原因留给 syncCreditUi 按报价写入；勿在此清空（可能尚未拉到 quote）。
            }
            if (switchCart) {
                if (type !== 'tob') {
                    switchCart.hidden = false;
                    switchCart.removeAttribute('hidden');
                } else {
                    switchCart.hidden = true;
                }
            }
        }
        var credit = root.querySelector('[data-b2b-credit-panel]');
        if (credit) {
            credit.hidden = false;
            credit.removeAttribute('hidden');
            credit.querySelectorAll('[data-b2b-credit-toggle], [data-b2b-credit-input]').forEach(function (el) {
                if ('disabled' in el) {
                    el.disabled = type !== 'tob';
                }
            });
            if (type === 'tob') {
                bindCreditControls(root);
            } else {
                var toggleOff = credit.querySelector('[data-b2b-credit-toggle]');
                if (toggleOff) {
                    toggleOff.checked = false;
                }
            }
        }
        var creditSlot = root.querySelector('.weline-checkout__credit-slot');
        if (creditSlot) {
            creditSlot.hidden = false;
            creditSlot.removeAttribute('hidden');
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
        // toc 就地原因/切换链：apply 后立刻 sync，避免等 freeze 事件。
        if (type !== 'tob') {
            syncCreditUi(root);
        }
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

    var creditState = {
        quote: null,
        applyMinor: 0,
        cashMinor: null,
        currency: 'CNY'
    };

    function creditPanel(root) {
        return root ? root.querySelector('[data-b2b-credit-panel]') : null;
    }

    function creditUnavailableMessage(root, quote) {
        var creditRoot = root ? root.querySelector('[data-b2b-checkout-credit]') : null;
        var attr = function (name, fallback) {
            if (!creditRoot) {
                return fallback;
            }
            return creditRoot.getAttribute(name) || fallback;
        };
        if (quote && quote.hint_short) {
            return String(quote.hint_short);
        }
        var reason = quote && quote.reason ? String(quote.reason) : '';
        if (reason === 'b2b_credit_disabled') {
            return attr('data-i18n-reason-disabled', '站点未开启批发信用额度');
        }
        if (reason === 'no_balance') {
            return attr('data-i18n-reason-no-balance', '额度不够：暂无可用批发信用余额');
        }
        if (reason === 'fx_unavailable') {
            return attr('data-i18n-reason-fx', '暂无汇率，无法使用批发信用');
        }
        if (reason === 'not_logged_in') {
            return attr('data-i18n-reason-login', '请先登录后再使用批发信用');
        }
        if (reason === 'not_applicable') {
            return attr('data-i18n-reason-na', '当前订单无定金，无法用批发信用抵扣');
        }
        if (reason === 'zero_cap') {
            return attr('data-i18n-reason-zero', '额度不够：本单可抵扣额度为 0');
        }
        if (reason === 'quote_failed') {
            return attr('data-i18n-reason-quote-failed', '暂时无法获取批发信用报价，请刷新后重试');
        }
        if (!quote) {
            return attr('data-i18n-quote-missing', '暂时无法获取批发信用报价，请刷新后重试');
        }
        // Never show hollow generic unavailable copy — always name a next step.
        return attr('data-i18n-quote-missing', '暂时无法获取批发信用报价，请刷新后重试');
    }

    function showCreditReason(el, text) {
        if (!el) {
            return;
        }
        var msg = String(text || '').trim();
        if (!msg) {
            el.textContent = '';
            el.hidden = true;
            return;
        }
        el.textContent = msg;
        el.hidden = false;
        el.removeAttribute('hidden');
    }

    function syncCreditUi(root) {
        var panel = creditPanel(root);
        if (!panel) {
            return;
        }
        var creditRoot = root ? root.querySelector('[data-b2b-checkout-credit]') : null;
        var quote = creditState.quote;
        var toggle = panel.querySelector('[data-b2b-credit-toggle]');
        var input = panel.querySelector('[data-b2b-credit-input]');
        var hint = panel.querySelector('[data-b2b-credit-hint]');
        var help = panel.querySelector('[data-b2b-credit-help]');
        var cashEl = panel.querySelector('[data-b2b-credit-cash]');
        var reasonEl = (creditRoot || panel).querySelector('[data-b2b-credit-reason]');
        // Prefer widget data-cart-type (set by applyCartType); fall back to cookie/mode.
        var cartType = String(
            (creditRoot && creditRoot.getAttribute('data-cart-type')) || readMode() || ''
        ).toLowerCase();
        var isToc = cartType !== 'tob';
        if (isToc) {
            if (creditRoot) {
                creditRoot.classList.add('is-toc-unavailable');
                creditRoot.setAttribute('data-cart-type', 'toc');
                creditRoot.setAttribute('aria-disabled', 'true');
            }
            showCreditReason(
                reasonEl,
                (creditRoot && creditRoot.getAttribute('data-i18n-toc-unavailable'))
                    || '当前是零售结账，批发信用只能在「批发车」使用。请先到购物车切换到批发车，再回来结账。'
            );
            var switchCart = (creditRoot || panel).querySelector('[data-b2b-credit-switch-cart]');
            if (switchCart) {
                switchCart.hidden = false;
                switchCart.removeAttribute('hidden');
            }
            if (toggle) {
                toggle.checked = false;
                toggle.disabled = true;
            }
            if (input) {
                input.disabled = true;
            }
            if (hint) {
                hint.hidden = true;
                hint.textContent = '';
            }
            if (cashEl) {
                cashEl.hidden = true;
                cashEl.textContent = '';
            }
            creditState.applyMinor = 0;
            return;
        }
        if (!quote || !quote.enabled) {
            panel.hidden = false;
            showCreditReason(reasonEl, creditUnavailableMessage(root, quote));
            if (toggle) {
                toggle.checked = false;
                toggle.disabled = true;
            }
            if (input) {
                input.disabled = true;
            }
            if (hint) {
                hint.hidden = true;
                hint.textContent = '';
            }
            if (cashEl) {
                cashEl.hidden = true;
                cashEl.textContent = '';
            }
            if (help && quote) {
                help.setAttribute('data-w-tooltip', String(quote.hint_detail || quote.hint_short || ''));
                help.setAttribute('title', String(quote.hint_detail || quote.hint_short || ''));
            }
            creditState.applyMinor = 0;
            creditState.cashMinor = quote && quote.deposit_amount_minor != null
                ? Number(quote.deposit_amount_minor)
                : null;
            return;
        }
        panel.hidden = false;
        showCreditReason(reasonEl, '');
        if (hint) {
            hint.hidden = false;
            hint.removeAttribute('hidden');
            hint.textContent = String(quote.hint_short || '');
        }
        if (help) {
            help.setAttribute('data-w-tooltip', String(quote.hint_detail || quote.hint_short || ''));
            help.setAttribute('title', String(quote.hint_detail || ''));
        }
        var maxApply = Math.max(0, Number(quote.max_apply_checkout_minor) || 0);
        var deposit = Math.max(0, Number(quote.deposit_amount_minor) || 0);
        creditState.currency = String(quote.checkout_currency || creditState.currency || 'CNY');
        if (toggle) {
            toggle.disabled = false;
        }
        if (input) {
            input.disabled = !(toggle && toggle.checked);
            input.max = (maxApply / 100).toFixed(2);
            if (toggle && toggle.checked) {
                var major = Number(input.value);
                if (!isFinite(major) || major < 0) {
                    major = maxApply / 100;
                    input.value = major.toFixed(2);
                }
                var apply = Math.round(major * 100);
                apply = Math.max(0, Math.min(apply, maxApply, deposit));
                creditState.applyMinor = apply;
                input.value = (apply / 100).toFixed(2);
            } else {
                creditState.applyMinor = 0;
            }
        } else {
            creditState.applyMinor = (toggle && toggle.checked) ? maxApply : 0;
        }
        creditState.cashMinor = Math.max(0, deposit - creditState.applyMinor);
        if (cashEl) {
            cashEl.hidden = false;
            cashEl.removeAttribute('hidden');
            cashEl.textContent = '现金定金：' + formatMinor(creditState.cashMinor, creditState.currency)
                + '（全额定金 ' + formatMinor(deposit, creditState.currency) + '）';
        }
        // Zero cash: soft-disable payment radios (still allow submit via fake_card).
        root.querySelectorAll('input[name="payment_method"]').forEach(function (el) {
            if (creditState.cashMinor === 0 && toggle && toggle.checked) {
                el.setAttribute('data-b2b-zero-cash', '1');
            } else {
                el.removeAttribute('data-b2b-zero-cash');
            }
        });
    }

    function syncFromFrozen(frozen) {
        var payload = frozen && frozen.data && typeof frozen.data === 'object' ? frozen.data : frozen;
        var quote = payload && payload.b2b_credit && typeof payload.b2b_credit === 'object'
            ? payload.b2b_credit
            : null;
        creditState.quote = quote;
        // Do NOT include bare `form` — address quick-add forms appear earlier in DOM
        // and would steal querySelector before .weline-checkout.
        var root = document.querySelector('[data-weline-checkout], [data-checkout], .weline-checkout');
        if (root) {
            if (readMode() === 'tob') {
                var panel = creditPanel(root);
                if (panel) {
                    panel.hidden = false;
                }
            }
            // toc / tob 都同步：不可用原因必须就地出现在勾选下方。
            syncCreditUi(root);
        }
    }

    function readApplyMinor() {
        return Math.max(0, Number(creditState.applyMinor) || 0);
    }

    function cashDepositMinor() {
        if (creditState.cashMinor === null || creditState.cashMinor === undefined) {
            return null;
        }
        return Math.max(0, Number(creditState.cashMinor) || 0);
    }

    function bindCreditControls(root) {
        var panel = creditPanel(root);
        if (!panel || panel.getAttribute('data-b2b-credit-bound') === '1') {
            return;
        }
        panel.setAttribute('data-b2b-credit-bound', '1');
        var toggle = panel.querySelector('[data-b2b-credit-toggle]');
        var input = panel.querySelector('[data-b2b-credit-input]');
        if (toggle) {
            toggle.addEventListener('change', function () {
                syncCreditUi(root);
            });
        }
        if (input) {
            input.addEventListener('input', function () {
                syncCreditUi(root);
            });
            input.addEventListener('change', function () {
                syncCreditUi(root);
            });
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
            hangLoginUrl: hangLoginUrl,
            syncFromFrozen: syncFromFrozen,
            readApplyMinor: readApplyMinor,
            cashDepositMinor: cashDepositMinor
        };
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', bindCheckout);
    } else {
        bindCheckout();
    }
})(window);
