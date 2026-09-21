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
            '.w-marketing-checkout-coupon, [data-testid="checkout-coupon"], [data-testid="marketing-checkout-coupon"], [data-marketing-checkout-coupon]'
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

    function applyCartType(root, cartType, opts) {
        if (!root) {
            return;
        }
        opts = opts && typeof opts === 'object' ? opts : {};
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
        // 摘要页签「批发信用」：仅批发结账显示；零售完全隐藏（不灰化占位）。
        var showCredit = type === 'tob';
        var creditRoot = root.querySelector('[data-b2b-checkout-credit]');
        if (creditRoot) {
            creditRoot.hidden = !showCredit;
            if (showCredit) {
                creditRoot.removeAttribute('hidden');
            } else {
                creditRoot.setAttribute('hidden', '');
            }
            creditRoot.classList.remove('is-toc-unavailable');
            creditRoot.setAttribute('aria-disabled', showCredit ? 'false' : 'true');
            creditRoot.setAttribute('data-cart-type', type);
            var switchCart = creditRoot.querySelector('[data-b2b-credit-switch-cart]');
            if (switchCart) {
                switchCart.hidden = true;
            }
        }
        var credit = root.querySelector('[data-b2b-credit-panel]');
        if (credit) {
            credit.hidden = !showCredit;
            if (showCredit) {
                credit.removeAttribute('hidden');
                credit.querySelectorAll('[data-b2b-credit-toggle], [data-b2b-credit-input]').forEach(function (el) {
                    if ('disabled' in el) {
                        el.disabled = false;
                    }
                });
                bindCreditControls(root);
            } else {
                credit.setAttribute('hidden', '');
                var toggleOff = credit.querySelector('[data-b2b-credit-toggle]');
                if (toggleOff) {
                    toggleOff.checked = false;
                    toggleOff.disabled = true;
                }
                var inputOff = credit.querySelector('[data-b2b-credit-input]');
                if (inputOff) {
                    inputOff.disabled = true;
                }
            }
        }
        var creditSlot = root.querySelector(
            '.weline-checkout__credit-slot, .weline-cart-shell__credit-slot,'
            + ' [data-wslot="checkout-summary-credit"], [data-wslot="cart-summary-credit"]'
        );
        if (creditSlot) {
            creditSlot.hidden = type !== 'tob';
            if (showCredit) {
                creditSlot.removeAttribute('hidden');
            } else {
                creditSlot.setAttribute('hidden', '');
            }
        }
        syncCreditExtrasTabVisibility(root, showCredit);
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
            return;
        }
        // tob：仅在显式请求时预取。MutationObserver/勾选变更不得经 applyCartType 再打 credit.quote。
        if (opts.ensureQuote === true) {
            ensureCreditQuote(root, Object.assign({ cart_type: 'tob' }, opts.quoteOpts || {}));
        } else {
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
        var back = document.querySelector('.weline-checkout__back');
        if (back) {
            back.setAttribute('href', '/customer/account/index#b2b-identity');
            back.textContent = '返回批发身份';
        }
    }

    function renderHangOrderSummary(panel, summary, currency) {
        var host = panel.querySelector('[data-b2b-hang-order]');
        if (!host) {
            return;
        }
        summary = summary && typeof summary === 'object' ? summary : {};
        var lines = Array.isArray(summary.lines) ? summary.lines : [];
        var display = summary.display_number ? String(summary.display_number) : '';
        var uuid = summary.order_uuid ? String(summary.order_uuid) : '';
        var statusLabel = summary.hang_status_label
            ? String(summary.hang_status_label)
            : String(summary.hang_status || '');

        var meta = document.createElement('div');
        meta.className = 'b2b-hang-payment__meta';
        meta.setAttribute('data-testid', 'b2b-hang-order-meta');
        var metaLeft = document.createElement('div');
        metaLeft.className = 'b2b-hang-payment__meta-main';
        var orderLabel = document.createElement('span');
        orderLabel.className = 'b2b-hang-payment__meta-label';
        orderLabel.textContent = '订单';
        var orderValue = document.createElement('strong');
        orderValue.className = 'b2b-hang-payment__meta-value';
        orderValue.setAttribute('data-testid', 'b2b-hang-order-number');
        orderValue.textContent = display !== '' ? display : (uuid !== '' ? uuid.slice(0, 8) + '…' : '—');
        metaLeft.appendChild(orderLabel);
        metaLeft.appendChild(orderValue);
        if (uuid !== '' && display !== '') {
            var uuidHint = document.createElement('code');
            uuidHint.className = 'b2b-hang-payment__meta-uuid';
            uuidHint.textContent = uuid.length > 12 ? uuid.slice(0, 8) + '…' : uuid;
            metaLeft.appendChild(uuidHint);
        }
        meta.appendChild(metaLeft);
        if (statusLabel) {
            var badge = document.createElement('span');
            badge.className = 'w-badge';
            var hangStatus = String(summary.hang_status || '');
            var tone = 'warning';
            if (hangStatus === 'completed') {
                tone = 'success';
            } else if (hangStatus === 'awaiting_balance' || hangStatus === 'awaiting_deposit') {
                tone = 'warning';
            }
            badge.setAttribute('data-tone', tone);
            badge.setAttribute('data-testid', 'b2b-hang-order-status');
            badge.textContent = statusLabel;
            meta.appendChild(badge);
        }

        var list = document.createElement('ul');
        list.className = 'b2b-hang-payment__lines';
        list.setAttribute('data-testid', 'b2b-hang-order-lines');
        if (lines.length === 0) {
            var emptyLi = document.createElement('li');
            emptyLi.className = 'b2b-hang-payment__line b2b-hang-payment__line--empty';
            emptyLi.setAttribute('data-testid', 'b2b-hang-order-lines-empty');
            emptyLi.textContent = summary.loading
                ? '正在加载订单明细…'
                : '暂无商品行，请从「批发身份」重新进入挂单';
            list.appendChild(emptyLi);
        } else {
            lines.forEach(function (line) {
                if (!line || typeof line !== 'object') {
                    return;
                }
                var li = document.createElement('li');
                li.className = 'b2b-hang-payment__line';
                li.setAttribute('data-testid', 'b2b-hang-order-line');

                var thumb = document.createElement('div');
                thumb.className = 'b2b-hang-payment__line-thumb';
                thumb.setAttribute('data-testid', 'b2b-hang-order-thumb');
                var imageUrl = String(line.image_url || line.image || line.thumbnail || '').trim();
                if (imageUrl && !/^asset:\/\//i.test(imageUrl)) {
                    var img = document.createElement('img');
                    img.src = imageUrl;
                    img.alt = String(line.name || line.sku || '商品');
                    img.loading = 'lazy';
                    img.width = 56;
                    img.height = 56;
                    img.setAttribute('data-testid', 'b2b-hang-order-thumb-img');
                    img.addEventListener('error', function () {
                        thumb.classList.add('is-empty');
                        if (img.parentNode) {
                            img.parentNode.removeChild(img);
                        }
                    });
                    thumb.appendChild(img);
                } else {
                    thumb.classList.add('is-empty');
                }

                var main = document.createElement('div');
                main.className = 'b2b-hang-payment__line-main';
                var nameEl = document.createElement('span');
                nameEl.className = 'b2b-hang-payment__line-name';
                nameEl.textContent = String(line.name || line.sku || '商品');
                main.appendChild(nameEl);
                if (line.sku) {
                    var skuEl = document.createElement('span');
                    skuEl.className = 'b2b-hang-payment__line-sku';
                    skuEl.textContent = String(line.sku);
                    main.appendChild(skuEl);
                }
                var side = document.createElement('div');
                side.className = 'b2b-hang-payment__line-side';
                var qtyEl = document.createElement('span');
                qtyEl.className = 'b2b-hang-payment__line-qty';
                qtyEl.textContent = '×' + String(Number(line.qty_minor) || 0);
                var rowEl = document.createElement('span');
                rowEl.className = 'b2b-hang-payment__line-total';
                rowEl.textContent = formatMinor(line.row_total_minor, currency);
                side.appendChild(qtyEl);
                side.appendChild(rowEl);
                li.appendChild(thumb);
                li.appendChild(main);
                li.appendChild(side);
                list.appendChild(li);
            });
        }

        var totals = document.createElement('dl');
        totals.className = 'b2b-hang-payment__totals';
        totals.setAttribute('data-testid', 'b2b-hang-order-totals');
        function addTotalRow(label, amount, opts) {
            opts = opts || {};
            var dt = document.createElement('dt');
            dt.textContent = label;
            var dd = document.createElement('dd');
            if (opts.testid) {
                dd.setAttribute('data-testid', opts.testid);
            }
            if (opts.emphasis) {
                dd.className = 'b2b-hang-payment__totals-payable';
            }
            dd.textContent = (opts.prefix || '') + formatMinor(amount, currency);
            totals.appendChild(dt);
            totals.appendChild(dd);
        }
        addTotalRow('商品合计', summary.goods_subtotal_minor, { testid: 'b2b-hang-goods-subtotal' });
        addTotalRow('已付定金', summary.deposit_amount_minor, { testid: 'b2b-hang-deposit-paid', prefix: '−' });
        var balanceLabel = String(summary.hang_status || '') === 'completed' ? '已付尾款' : '应付尾款';
        addTotalRow(
            balanceLabel,
            summary.payable_minor != null ? summary.payable_minor : summary.balance_amount_minor,
            { testid: 'b2b-hang-payable', emphasis: true }
        );

        host.innerHTML = '';
        host.appendChild(meta);
        host.appendChild(list);
        host.appendChild(totals);
        host.hidden = false;
    }

    function selectedPaymentMethod(root) {
        var scope = root && root.querySelector
            ? (root.querySelector('[data-b2b-hang-payment]') || root)
            : root;
        var checked = scope.querySelector('input[name="payment_method"]:checked');
        if (checked && checked.value) {
            return String(checked.value).trim();
        }
        var first = scope.querySelector('input[name="payment_method"]');
        return first && first.value ? String(first.value).trim() : '';
    }

    function renderHangPaymentMethods(panel, methods) {
        var host = panel.querySelector('[data-b2b-hang-methods]');
        if (!host) {
            return 0;
        }
        host.innerHTML = '';
        var list = Array.isArray(methods) ? methods : [];
        var count = 0;
        list.forEach(function (method, index) {
            var code = method && method.code ? String(method.code).trim() : '';
            if (!code) {
                return;
            }
            var title = method && method.title ? String(method.title) : code;
            var id = 'b2b-hang-pay-' + code.replace(/[^a-z0-9_-]/gi, '_');
            var label = document.createElement('label');
            label.className = 'b2b-hang-payment__method';
            label.setAttribute('data-testid', 'b2b-hang-payment-method');
            var input = document.createElement('input');
            input.type = 'radio';
            input.name = 'payment_method';
            input.value = code;
            input.id = id;
            if (count === 0) {
                input.checked = true;
            }
            var span = document.createElement('span');
            span.textContent = title;
            label.appendChild(input);
            label.appendChild(span);
            host.appendChild(label);
            count += 1;
        });
        host.hidden = count === 0;
        return count;
    }

    function hangRedirectUrl(payment) {
        payment = payment || {};
        if (payment.redirect_url) {
            return String(payment.redirect_url);
        }
        var txs = Array.isArray(payment.transactions) ? payment.transactions : [];
        for (var i = 0; i < txs.length; i += 1) {
            var response = txs[i] && txs[i].response ? txs[i].response : {};
            if (response.redirect_url) {
                return String(response.redirect_url);
            }
        }
        return '';
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

    function formatMajorPlain(amountMinor) {
        return ((Number(amountMinor) || 0) / 100).toFixed(2);
    }

    /**
     * Explicit FX copy: base currency + available (+ checkout equivalent) + max apply.
     * Optionally append min-cash reserve once — never duplicate into hint_short UI.
     */
    function buildCreditFxSummary(quote) {
        if (!quote || !quote.enabled) {
            return '';
        }
        var base = String(quote.base_currency || '').toUpperCase();
        var checkout = String(quote.checkout_currency || '').toUpperCase();
        var availableBase = Math.max(0, Number(quote.available_base_minor) || 0);
        var availableCheckout = Math.max(0, Number(quote.available_checkout_minor) || 0);
        var maxApply = Math.max(0, Number(quote.max_apply_checkout_minor) || 0);
        var minCash = Math.max(0, Number(quote.min_cash_deposit_minor) || 0);
        if (!base || !checkout) {
            return '';
        }
        var baseAmt = formatMajorPlain(availableBase);
        var checkoutAmt = formatMajorPlain(availableCheckout);
        var maxAmt = formatMajorPlain(maxApply);
        var text;
        if (base === checkout) {
            text = '基准货币 ' + base + ' 可用 ' + baseAmt
                + '；本单最多可抵 ' + maxAmt + ' ' + checkout;
        } else {
            text = '基准货币 ' + base + ' 可用 ' + baseAmt
                + '，折合 ' + checkout + ' ' + checkoutAmt
                + '；本单最多可抵 ' + maxAmt + ' ' + checkout;
        }
        if (minCash > 0) {
            text += '。定金至少保留现金 ' + formatMajorPlain(minCash) + ' ' + checkout;
        }
        return text;
    }

    /** Parse typing buffer without forcing complete decimals mid-keystroke. */
    function parseCreditInputMajor(raw) {
        var text = String(raw || '').trim().replace(/,/g, '');
        if (text === '' || text === '.' || text === '-') {
            return { empty: true, major: 0, incomplete: true };
        }
        // Allow trailing "." or ".0" while typing.
        if (!/^\d+(\.\d*)?$/.test(text)) {
            return { empty: false, major: NaN, incomplete: true };
        }
        if (text.indexOf('.') === text.length - 1) {
            return { empty: false, major: parseFloat(text + '0'), incomplete: true };
        }
        var major = parseFloat(text);
        return {
            empty: false,
            major: isFinite(major) ? major : NaN,
            incomplete: /\.\d{0,1}$/.test(text) && text.split('.')[1] && text.split('.')[1].length < 2
                ? false
                : false
        };
    }

    function setCreditFxEl(fxEl, text) {
        if (!fxEl) {
            return;
        }
        if (!text) {
            fxEl.hidden = true;
            fxEl.setAttribute('hidden', '');
            fxEl.textContent = '';
            return;
        }
        fxEl.hidden = false;
        fxEl.removeAttribute('hidden');
        fxEl.textContent = text;
    }

    var creditState = {
        quote: null,
        applyMinor: 0,
        cashMinor: null,
        currency: 'CNY',
        quoteLoading: false,
        quoteSeq: 0,
        pendingQuoteOpts: null,
        // Last deposit we actually sent to credit.quote — do NOT compare against
        // server-echoed deposit_amount_minor (mismatch would storm refetch).
        lastRequestedDepositMinor: null
    };

    var DEPOSIT_RATIO_BPS = 3000;

    function parseMoneyMajor(text) {
        // Strip currency letters/symbols; drop thousand separators; keep one decimal point.
        var cleaned = String(text || '')
            .replace(/,/g, '')
            .replace(/[^\d.-]/g, '');
        // Guard against "1.590.00" style leftovers: keep first dot only.
        var firstDot = cleaned.indexOf('.');
        if (firstDot >= 0) {
            cleaned = cleaned.slice(0, firstDot + 1) + cleaned.slice(firstDot + 1).replace(/\./g, '');
        }
        var parsed = parseFloat(cleaned);
        return isFinite(parsed) && parsed > 0 ? parsed : NaN;
    }

    function pickLargestMoneyMajor(nodes) {
        var best = NaN;
        for (var i = 0; i < nodes.length; i += 1) {
            var parsed = parseMoneyMajor(nodes[i] && nodes[i].textContent);
            if (isFinite(parsed) && (!isFinite(best) || parsed > best)) {
                best = parsed;
            }
        }
        return best;
    }

    function isNodeEffectivelyHidden(node) {
        if (!node || !node.closest) {
            return false;
        }
        if (node.hidden || node.getAttribute('hidden') !== null) {
            return true;
        }
        try {
            var host = node.closest('[hidden], [aria-hidden="true"]');
            if (!host) {
                return false;
            }
            // Discount breakdown may be hidden while its goods node is still the live base —
            // only treat it as stale when an ancestor other than the breakdown itself hides it
            // AND a sibling payable amount exists. Prefer attribute reads instead.
            return true;
        } catch (e) {
            return false;
        }
    }

    function readGoodsMajorFromAttrHosts(hosts) {
        var best = NaN;
        for (var i = 0; i < hosts.length; i += 1) {
            var host = hosts[i];
            if (!host) {
                continue;
            }
            var fromGoodsAttr = parseMoneyMajor(host.getAttribute('data-cart-goods-subtotal-major'));
            if (isFinite(fromGoodsAttr) && fromGoodsAttr > 0) {
                // Prefer goods attr over payable attr when both exist on the same host.
                if (!isFinite(best) || fromGoodsAttr > best) {
                    best = fromGoodsAttr;
                }
                continue;
            }
            var fromPayableAttr = parseMoneyMajor(host.getAttribute('data-cart-subtotal'));
            if (isFinite(fromPayableAttr) && (!isFinite(best) || fromPayableAttr > best)) {
                best = fromPayableAttr;
            }
        }
        return best;
    }

    /**
     * Goods subtotal (major) for tob deposit base.
     * Prefer live cart/checkout summary attrs; never keep a stale hidden goods row
     * (e.g. previous toc cart total still sitting under a hidden discount breakdown).
     */
    function readGoodsSubtotalMajor(opts) {
        opts = opts || {};
        var fromOpts = Number(opts.subtotal != null ? opts.subtotal : opts.cart_subtotal);
        if (isFinite(fromOpts) && fromOpts > 0) {
            return fromOpts;
        }
        try {
            // 1) Cart page: authoritative when present.
            var cartHosts = document.querySelectorAll('[data-weline-cart]');
            if (cartHosts.length) {
                var fromCartAttr = readGoodsMajorFromAttrHosts(cartHosts);
                if (isFinite(fromCartAttr) && fromCartAttr > 0) {
                    return fromCartAttr;
                }
                var cartGoods = document.querySelectorAll('[data-weline-cart] [data-cart-goods-subtotal]');
                var cartGoodsMajor = pickLargestMoneyMajor(cartGoods);
                if (isFinite(cartGoodsMajor) && cartGoodsMajor > 0) {
                    return cartGoodsMajor;
                }
            }

            // 2) Checkout summary.
            var checkoutNodes = document.querySelectorAll(
                '[data-weline-checkout] [data-checkout-subtotal], [data-checkout] [data-checkout-subtotal], [data-testid="checkout-subtotal"], .weline-checkout [data-checkout-subtotal]'
            );
            var checkoutMajor = pickLargestMoneyMajor(checkoutNodes);
            if (isFinite(checkoutMajor) && checkoutMajor > 0) {
                return checkoutMajor;
            }

            // 3) Mini-cart: prefer numeric goods attr on the open/active root (synced every applySummary).
            var miniRoots = document.querySelectorAll('[data-w-mini-cart="1"]');
            var preferredMini = [];
            var fallbackMini = [];
            for (var m = 0; m < miniRoots.length; m += 1) {
                var mini = miniRoots[m];
                var open = mini.classList.contains('is-drawer-open')
                    || (mini.querySelector && mini.querySelector('.mini-cart-drawer.is-open'));
                if (open) {
                    preferredMini.push(mini);
                } else {
                    fallbackMini.push(mini);
                }
            }
            var miniAttr = readGoodsMajorFromAttrHosts(preferredMini.length ? preferredMini : fallbackMini);
            if (isFinite(miniAttr) && miniAttr > 0) {
                return miniAttr;
            }

            // 4) Visible payable in open drawer (tob has no marketing discount → equals goods).
            var payableSelectors = preferredMini.length
                ? '[data-w-mini-cart="1"].is-drawer-open [data-cart-total-amount], [data-w-mini-cart="1"] .mini-cart-drawer.is-open [data-cart-total-amount]'
                : '[data-w-mini-cart="1"] [data-cart-total-amount]';
            var payableMajor = pickLargestMoneyMajor(document.querySelectorAll(payableSelectors));
            if (isFinite(payableMajor) && payableMajor > 0) {
                return payableMajor;
            }

            // 5) Last resort: any goods node text that is not inside a hidden ancestor.
            var goodsNodes = document.querySelectorAll('[data-cart-goods-subtotal]');
            var visibleGoods = [];
            for (var g = 0; g < goodsNodes.length; g += 1) {
                if (!isNodeEffectivelyHidden(goodsNodes[g])) {
                    visibleGoods.push(goodsNodes[g]);
                }
            }
            var goodsMajor = pickLargestMoneyMajor(visibleGoods.length ? visibleGoods : goodsNodes);
            if (isFinite(goodsMajor) && goodsMajor > 0) {
                return goodsMajor;
            }

            return readGoodsMajorFromAttrHosts(document.querySelectorAll(
                '[data-weline-cart], [data-w-mini-cart="1"], [data-cart-subtotal], [data-cart-goods-subtotal-major]'
            ));
        } catch (e) {
            return NaN;
        }
    }

    /**
     * Resolve tob cash payable for this period (= hang deposit base: goods × 30%).
     * Cart / mini-cart must estimate from goods subtotal — not wait for a hang deposit row.
     */
    function estimateDepositMinor(opts) {
        opts = opts || {};
        var explicit = Number(opts.deposit_amount_minor);
        // 0 means "unknown" when a cart subtotal is also available — do not pin deposit at 0.
        if (isFinite(explicit) && explicit > 0) {
            return Math.max(0, Math.floor(explicit));
        }
        var subtotalMajor = readGoodsSubtotalMajor(opts);
        if (!isFinite(subtotalMajor) || subtotalMajor <= 0) {
            return isFinite(explicit) ? Math.max(0, Math.floor(explicit)) : 0;
        }
        var goodsMinor = Math.max(0, Math.round(subtotalMajor * 100));
        return Math.floor((goodsMinor * DEPOSIT_RATIO_BPS) / 10000);
    }

    function resolveWebsiteId(opts) {
        opts = opts || {};
        var fromOpts = Number(opts.website_id);
        if (isFinite(fromOpts) && fromOpts > 0) {
            return Math.floor(fromOpts);
        }
        var site = global.site || {};
        var fromSite = Number(site.website_id || site.websiteId || 0);
        return isFinite(fromSite) && fromSite > 0 ? Math.floor(fromSite) : 0;
    }

    /**
     * Resolve checkout/display currency for credit.quote.
     * Never silently default to CNY when the cart/page already has another currency —
     * that causes base-wallet numbers to be applied 1:1 against foreign deposits.
     */
    function resolveCheckoutCurrency(opts) {
        opts = opts || {};
        var candidates = [
            opts.currency,
            creditState.pendingQuoteOpts && creditState.pendingQuoteOpts.currency,
            creditState.currency,
            global.checkoutState && global.checkoutState.currency,
        ];
        try {
            var root = document.querySelector('[data-weline-checkout], [data-checkout], .weline-checkout');
            if (root) {
                candidates.push(root.getAttribute('data-currency'));
                candidates.push(root.getAttribute('data-checkout-currency'));
            }
            var curNode = document.querySelector('[data-checkout-currency], [data-currency], [data-pixel-currency]');
            if (curNode) {
                candidates.push(curNode.getAttribute('data-checkout-currency'));
                candidates.push(curNode.getAttribute('data-currency'));
                candidates.push(curNode.getAttribute('data-pixel-currency'));
            }
        } catch (e) {}
        for (var i = 0; i < candidates.length; i += 1) {
            var code = String(candidates[i] || '').trim().toUpperCase();
            if (/^[A-Z]{3}$/.test(code)) {
                return code;
            }
        }
        return 'CNY';
    }

    function rememberQuoteOpts(opts) {
        opts = opts || {};
        creditState.pendingQuoteOpts = Object.assign({}, creditState.pendingQuoteOpts || {}, opts);
        if (!creditState.pendingQuoteOpts.currency) {
            creditState.pendingQuoteOpts.currency = resolveCheckoutCurrency(creditState.pendingQuoteOpts);
        }
        return creditState.pendingQuoteOpts;
    }

    /**
     * Ensure tob credit quote is fetched at least once. Idempotent while in-flight.
     * Does NOT endlessly retry quote_failed (prevents MutationObserver request storms).
     * Pass opts.force=true to retry after user action / deposit change.
     */
    function ensureCreditQuote(root, opts) {
        opts = rememberQuoteOpts(opts || {});
        var cartType = String(opts.cart_type || readMode() || '').toLowerCase();
        if (cartType !== 'tob') {
            return Promise.resolve(null);
        }
        if (creditState.quoteLoading) {
            return Promise.resolve(creditState.quote);
        }
        var nextDeposit = estimateDepositMinor(opts);
        var nextCurrency = resolveCheckoutCurrency(opts);
        var existing = creditState.quote;
        var force = opts.force === true;
        if (existing) {
            var prevRequested = creditState.lastRequestedDepositMinor;
            var prevCurrency = String(
                (existing.checkout_currency || creditState.currency || '')
            ).toUpperCase();
            var depositChanged = nextDeposit > 0
                && prevRequested != null
                && nextDeposit !== Math.max(0, Number(prevRequested) || 0);
            var currencyChanged = nextCurrency !== ''
                && prevCurrency !== ''
                && nextCurrency !== prevCurrency;
            if (!force && !depositChanged && !currencyChanged) {
                // Keep any settled quote (including quote_failed) unless deposit/currency/force says otherwise.
                syncCreditUi(root || document.querySelector('[data-weline-checkout], [data-checkout], .weline-checkout'));
                return Promise.resolve(existing);
            }
        }
        return refreshCreditQuote(root, opts);
    }

    async function refreshCreditQuote(root, opts) {
        opts = rememberQuoteOpts(opts || {});
        var cartType = String(opts.cart_type || readMode() || '').toLowerCase();
        if (cartType !== 'tob') {
            return null;
        }
        if (!root) {
            root = document.querySelector('[data-weline-checkout], [data-checkout], .weline-checkout');
        }
        if (!root || !creditPanel(root)) {
            return null;
        }
        var deposit = estimateDepositMinor(opts);
        var currency = resolveCheckoutCurrency(opts);
        creditState.currency = currency;
        var websiteId = resolveWebsiteId(opts);
        var seq = ++creditState.quoteSeq;
        creditState.lastRequestedDepositMinor = deposit;
        creditState.quoteLoading = true;
        var creditRoot = root.querySelector('[data-b2b-checkout-credit]');
        if (creditRoot) {
            creditRoot.setAttribute('data-cart-type', 'tob');
            creditRoot.classList.remove('is-toc-unavailable');
            creditRoot.setAttribute('aria-disabled', 'false');
        }
        var reasonEl = (creditRoot || creditPanel(root)).querySelector('[data-b2b-credit-reason]');
        showCreditReason(
            reasonEl,
            (creditRoot && creditRoot.getAttribute('data-i18n-quote-loading'))
                || '正在获取批发信用报价…'
        );
        // Disable toggle during in-flight fetch so checkbox cannot re-enter sync storms.
        syncCreditUi(root);
        if (!global.Weline || !global.Weline.Api || typeof global.Weline.Api.resource !== 'function') {
            creditState.quoteLoading = false;
            creditState.quote = {
                enabled: false,
                reason: 'quote_failed',
                hint_short: (creditRoot && creditRoot.getAttribute('data-i18n-reason-quote-failed'))
                    || '暂时无法获取批发信用报价，请刷新后重试'
            };
            syncCreditUi(root);
            return creditState.quote;
        }
        try {
            var api = await global.Weline.Api.resource('b2b');
            var res = await api['credit.quote']({
                deposit_amount_minor: deposit,
                currency: currency,
                website_id: websiteId
            }, { silent: true });
            if (seq !== creditState.quoteSeq) {
                return creditState.quote;
            }
            var quote = null;
            if (res && res.b2b_credit && typeof res.b2b_credit === 'object') {
                quote = res.b2b_credit;
            } else if (res && res.data && res.data.b2b_credit && typeof res.data.b2b_credit === 'object') {
                quote = res.data.b2b_credit;
            }
            creditState.quote = quote || {
                enabled: false,
                reason: 'quote_failed',
                deposit_amount_minor: deposit,
                hint_short: (creditRoot && creditRoot.getAttribute('data-i18n-reason-quote-failed'))
                    || '暂时无法获取批发信用报价，请刷新后重试'
            };
        } catch (err) {
            if (seq !== creditState.quoteSeq) {
                return creditState.quote;
            }
            creditState.quote = {
                enabled: false,
                reason: 'quote_failed',
                deposit_amount_minor: deposit,
                hint_short: (creditRoot && creditRoot.getAttribute('data-i18n-reason-quote-failed'))
                    || '暂时无法获取批发信用报价，请刷新后重试'
            };
        }
        creditState.quoteLoading = false;
        syncCreditUi(root);
        return creditState.quote;
    }

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
            return attr('data-i18n-reason-na', '暂无法估算本期定金，无法用批发信用抵扣');
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

    /**
     * 批发信用槽已迁入 extras 页签后：隐藏槽位时同步隐藏对应 tab，避免零售仍见「批发信用」按钮。
     * 若 tabs 在零售态已构建（无信用槽），切到批发时 rebuild 以纳入信用页签。
     */
    function syncCreditExtrasTabVisibility(root, visible) {
        if (!root) {
            return;
        }
        var creditSlot = root.querySelector(
            '.weline-checkout__credit-slot, .weline-cart-shell__credit-slot,'
            + ' [data-wslot="checkout-summary-credit"], [data-wslot="cart-summary-credit"]'
        );
        var creditRoot = root.querySelector('[data-b2b-checkout-credit]');
        var anchor = creditSlot || creditRoot;
        if (!anchor) {
            return;
        }
        var extras = root.querySelector('.weline-checkout__extras, [data-cart-summary-extras="1"], .mini-cart-drawer__extras')
            || (anchor.closest && anchor.closest('.weline-checkout__extras, [data-cart-summary-extras="1"], .mini-cart-drawer__extras'));
        var panel = anchor.closest('[data-mini-cart-extras-panel]');
        var shell = anchor.closest('[data-mini-cart-extras-tabs]')
            || (extras && extras.querySelector('[data-mini-cart-extras-tabs]'));

        if (visible && extras && shell && !panel
            && global.WelineMiniCartExtras
            && typeof global.WelineMiniCartExtras.rebuild === 'function') {
            try {
                global.WelineMiniCartExtras.rebuild(extras);
            } catch (eRebuild) {}
            panel = anchor.closest('[data-mini-cart-extras-panel]');
            shell = anchor.closest('[data-mini-cart-extras-tabs]')
                || extras.querySelector('[data-mini-cart-extras-tabs]');
        }

        if (!shell || !panel) {
            return;
        }
        var panels = Array.prototype.slice.call(shell.querySelectorAll('[data-mini-cart-extras-panel]'));
        var tabs = Array.prototype.slice.call(shell.querySelectorAll('[data-mini-cart-extras-tab]'));
        var idx = panels.indexOf(panel);
        if (idx < 0 || !tabs[idx]) {
            return;
        }
        var tab = tabs[idx];
        tab.hidden = !visible;
        if (visible) {
            tab.removeAttribute('hidden');
            tab.removeAttribute('aria-hidden');
        } else {
            tab.setAttribute('hidden', '');
            tab.setAttribute('aria-hidden', 'true');
            if (tab.classList.contains('is-active')) {
                var fallback = -1;
                for (var i = 0; i < tabs.length; i += 1) {
                    if (i === idx) {
                        continue;
                    }
                    if (tabs[i] && !tabs[i].hidden) {
                        fallback = i;
                        break;
                    }
                }
                if (fallback >= 0) {
                    tabs.forEach(function (t, ti) {
                        var active = ti === fallback;
                        t.classList.toggle('is-active', active);
                        t.setAttribute('aria-selected', active ? 'true' : 'false');
                        t.tabIndex = active ? 0 : -1;
                    });
                    panels.forEach(function (p, pi) {
                        var active = pi === fallback;
                        p.classList.toggle('is-active', active);
                        p.hidden = !active;
                    });
                    shell.setAttribute('data-active-tab', String(fallback));
                }
            }
        }
    }

    function notifyCreditChanged(root) {
        var detail = {
            apply_minor: readApplyMinor(),
            cash_minor: cashDepositMinor(),
            cart_type: readMode(),
            root: root || null,
        };
        var sig = [
            String(detail.apply_minor || 0),
            detail.cash_minor === null || detail.cash_minor === undefined ? '' : String(detail.cash_minor),
            String(detail.cart_type || '')
        ].join('|');
        if (creditState._notifySig === sig) {
            return;
        }
        creditState._notifySig = sig;
        try {
            window.dispatchEvent(new CustomEvent('weline:b2b-credit-changed', {
                detail: detail,
            }));
        } catch (eNotify) {}
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
        var fxEl = panel.querySelector('[data-b2b-credit-fx]');
        var reasonEl = (creditRoot || panel).querySelector('[data-b2b-credit-reason]');
        var opts = arguments[1] && typeof arguments[1] === 'object' ? arguments[1] : {};
        var formatInput = opts.formatInput === true;
        // Prefer widget data-cart-type (set by applyCartType); fall back to cookie/mode.
        var cartType = String(
            (creditRoot && creditRoot.getAttribute('data-cart-type')) || readMode() || ''
        ).toLowerCase();
        var isToc = cartType !== 'tob';
        if (isToc) {
            // 零售：整槽隐藏，不写「去切换批发车」就地文案。
            if (creditRoot) {
                creditRoot.hidden = true;
                creditRoot.setAttribute('hidden', '');
                creditRoot.classList.remove('is-toc-unavailable');
                creditRoot.setAttribute('data-cart-type', 'toc');
                creditRoot.setAttribute('aria-disabled', 'true');
            }
            panel.hidden = true;
            panel.setAttribute('hidden', '');
            var creditSlotToc = root.querySelector(
                '.weline-checkout__credit-slot, .weline-cart-shell__credit-slot,'
                + ' [data-wslot="checkout-summary-credit"], [data-wslot="cart-summary-credit"]'
            );
            if (creditSlotToc) {
                creditSlotToc.hidden = true;
                creditSlotToc.setAttribute('hidden', '');
            }
            syncCreditExtrasTabVisibility(root, false);
            showCreditReason(reasonEl, '');
            var switchCart = (creditRoot || panel).querySelector('[data-b2b-credit-switch-cart]');
            if (switchCart) {
                switchCart.hidden = true;
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
            setCreditFxEl(fxEl, '');
            creditState.applyMinor = 0;
            notifyCreditChanged(root);
            return;
        }
        // tob：确保槽位可见（页签按钮由 syncCreditExtrasTabVisibility 恢复）。
        if (creditRoot) {
            creditRoot.hidden = false;
            creditRoot.removeAttribute('hidden');
            creditRoot.classList.remove('is-toc-unavailable');
        }
        panel.hidden = false;
        panel.removeAttribute('hidden');
        var creditSlotTob = root.querySelector(
            '.weline-checkout__credit-slot, .weline-cart-shell__credit-slot,'
            + ' [data-wslot="checkout-summary-credit"], [data-wslot="cart-summary-credit"]'
        );
        if (creditSlotTob) {
            creditSlotTob.hidden = false;
            creditSlotTob.removeAttribute('hidden');
        }
        syncCreditExtrasTabVisibility(root, true);
        if (!quote || !quote.enabled) {
            panel.hidden = false;
            if (!quote) {
                // Do not fetch from syncCreditUi — that re-enters via DOM mutations.
                showCreditReason(
                    reasonEl,
                    creditState.quoteLoading
                        ? ((creditRoot && creditRoot.getAttribute('data-i18n-quote-loading'))
                            || '正在获取批发信用报价…')
                        : ((creditRoot && creditRoot.getAttribute('data-i18n-quote-missing'))
                            || '暂时无法获取批发信用报价，请刷新后重试')
                );
            } else {
                showCreditReason(reasonEl, creditUnavailableMessage(root, quote));
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
            setCreditFxEl(fxEl, '');
            if (help && quote) {
                help.setAttribute('data-w-tooltip', String(quote.hint_detail || quote.hint_short || ''));
                help.setAttribute('title', String(quote.hint_detail || quote.hint_short || ''));
            }
            creditState.applyMinor = 0;
            creditState.cashMinor = quote && quote.deposit_amount_minor != null
                ? Number(quote.deposit_amount_minor)
                : null;
            notifyCreditChanged(root);
            return;
        }
        panel.hidden = false;
        showCreditReason(reasonEl, '');
        var fxText = buildCreditFxSummary(quote);
        setCreditFxEl(fxEl, fxText);
        if (hint) {
            // FX 行已含基准币/可用/上限（及最低现金）；勿再整段回写 hint_short，避免重复。
            hint.hidden = true;
            hint.setAttribute('hidden', '');
            hint.textContent = '';
        }
        if (help) {
            help.setAttribute('data-w-tooltip', String(quote.hint_detail || quote.hint_short || ''));
            help.setAttribute('title', String(quote.hint_detail || ''));
        }
        var maxApply = Math.max(0, Number(quote.max_apply_checkout_minor) || 0);
        var quotedDeposit = Math.max(0, Number(quote.deposit_amount_minor) || 0);
        // Live goods/deposit for copy — must match footer subtotal; never keep a stale quoted base.
        var liveGoodsMajor = readGoodsSubtotalMajor(creditState.pendingQuoteOpts || {});
        var liveDeposit = estimateDepositMinor(creditState.pendingQuoteOpts || {});
        var deposit = liveDeposit > 0 ? liveDeposit : quotedDeposit;
        if (liveDeposit > 0 && quotedDeposit > 0 && liveDeposit !== quotedDeposit) {
            // Quote was for a different cart total — clamp apply until ensureCreditQuote refetches.
            maxApply = Math.min(maxApply, deposit);
        }
        // Authoritative checkout currency from quote — never use available_base_minor as UI max.
        creditState.currency = String(quote.checkout_currency || creditState.currency || 'CNY').toUpperCase() || 'CNY';
        var currencyLabel = panel.querySelector('[data-b2b-credit-currency]');
        if (currencyLabel) {
            currencyLabel.textContent = creditState.currency;
            currencyLabel.hidden = false;
            currencyLabel.removeAttribute('hidden');
        }
        if (toggle) {
            toggle.disabled = false;
        }
        if (input) {
            input.disabled = !(toggle && toggle.checked);
            input.removeAttribute('max');
            input.removeAttribute('min');
            input.removeAttribute('step');
            input.setAttribute('data-currency', creditState.currency);
            input.setAttribute('inputmode', 'decimal');
            if (toggle && toggle.checked) {
                var parsed = parseCreditInputMajor(input.value);
                var apply;
                if (!formatInput) {
                    // Live typing / programmatic sync: keep raw buffer; never toFixed mid-keystroke.
                    if (parsed.empty || !isFinite(parsed.major) || parsed.major < 0) {
                        apply = 0;
                    } else {
                        apply = Math.round(parsed.major * 100);
                        apply = Math.max(0, Math.min(apply, maxApply, deposit));
                    }
                    creditState.applyMinor = apply;
                } else {
                    var major;
                    if (parsed.empty || !isFinite(parsed.major) || parsed.major < 0) {
                        major = maxApply / 100;
                    } else {
                        major = parsed.major;
                    }
                    apply = Math.round(major * 100);
                    apply = Math.max(0, Math.min(apply, maxApply, deposit));
                    creditState.applyMinor = apply;
                    input.value = (apply / 100).toFixed(2);
                }
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
            var goodsMajor = isFinite(liveGoodsMajor) && liveGoodsMajor > 0
                ? liveGoodsMajor
                : (deposit > 0 ? (deposit * 10000 / DEPOSIT_RATIO_BPS) / 100 : NaN);
            var depositLabel = formatMinor(deposit, creditState.currency);
            var cashLabel = formatMinor(creditState.cashMinor, creditState.currency);
            var goodsLabel = isFinite(goodsMajor) && goodsMajor > 0
                ? formatMinor(Math.round(goodsMajor * 100), creditState.currency)
                : '';
            // 明示：本期定金=商品小计×30%；信用只抵定金；现金行可带最低现金占比。
            var minCashPct = 0;
            try {
                minCashPct = parseInt(String((creditRoot && creditRoot.getAttribute('data-b2b-min-cash-percent')) || '0'), 10) || 0;
            } catch (eMin) {
                minCashPct = 0;
            }
            var minCashNote = (minCashPct > 0 && minCashPct < 100)
                ? ('；现金至少保留定金的 ' + minCashPct + '%')
                : '';
            if (goodsLabel) {
                if (creditState.applyMinor > 0) {
                    cashEl.textContent = '本期定金 ' + depositLabel
                        + '（商品小计 ' + goodsLabel + ' × 30%）'
                        + '；抵扣后现金 ' + cashLabel
                        + minCashNote
                        + '；尾款另付';
                } else {
                    cashEl.textContent = '本期定金 ' + depositLabel
                        + '（商品小计 ' + goodsLabel + ' × 30%；尾款结账时再付）'
                        + minCashNote;
                }
            } else if (creditState.applyMinor > 0) {
                cashEl.textContent = '本期定金 ' + depositLabel
                    + '；抵扣后现金 ' + cashLabel
                    + minCashNote
                    + '；尾款另付';
            } else {
                cashEl.textContent = '本期定金 ' + depositLabel
                    + '（批发首期 30%；尾款结账时再付）'
                    + minCashNote;
            }
        }
        // Zero cash: soft-disable payment radios (still allow submit via fake_card).
        root.querySelectorAll('input[name="payment_method"]').forEach(function (el) {
            if (creditState.cashMinor === 0 && toggle && toggle.checked) {
                el.setAttribute('data-b2b-zero-cash', '1');
            } else {
                el.removeAttribute('data-b2b-zero-cash');
            }
        });
        notifyCreditChanged(root);
    }

    function syncFromFrozen(frozen) {
        var payload = frozen && frozen.data && typeof frozen.data === 'object' ? frozen.data : frozen;
        var quote = payload && payload.b2b_credit && typeof payload.b2b_credit === 'object'
            ? payload.b2b_credit
            : null;
        // Do NOT include bare `form` — address quick-add forms appear earlier in DOM
        // and would steal querySelector before .weline-checkout.
        var root = document.querySelector('[data-weline-checkout], [data-checkout], .weline-checkout');
        var panel = creditPanel(root);
        var toggle = panel ? panel.querySelector('[data-b2b-credit-toggle]') : null;
        var input = panel ? panel.querySelector('[data-b2b-credit-input]') : null;
        var keepChecked = !!(toggle && toggle.checked);
        var keepInput = input ? String(input.value || '') : '';
        var keepApply = Math.max(0, Number(creditState.applyMinor) || 0);
        creditState.quote = quote;
        creditState.quoteLoading = false;
        if (root) {
            if (readMode() === 'tob') {
                if (panel) {
                    panel.hidden = false;
                }
            }
            // toc / tob 都同步：不可用原因必须就地出现在勾选下方。
            syncCreditUi(root, { formatInput: true });
            // freeze 权威报价到达后保留用户已选抵扣，避免 submit 瞬间 apply 恒为 0。
            if (quote && quote.enabled && panel) {
                if (toggle && keepChecked) {
                    toggle.checked = true;
                }
                if (input && keepChecked) {
                    if (keepInput !== '') {
                        input.value = keepInput;
                    } else if (keepApply > 0) {
                        input.value = (keepApply / 100).toFixed(2);
                    }
                }
                syncCreditUi(root, { formatInput: true });
            }
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
                syncCreditUi(root, { formatInput: true });
            });
        }
        if (input) {
            input.addEventListener('input', function () {
                // Mid-keystroke: update applyMinor / cash only; do not force toFixed.
                syncCreditUi(root, { formatInput: false });
            });
            input.addEventListener('change', function () {
                syncCreditUi(root, { formatInput: true });
            });
            input.addEventListener('blur', function () {
                syncCreditUi(root, { formatInput: true });
            });
        }
    }

    function ensureHangPanel(root) {
        var existing = root.querySelector('[data-b2b-hang-payment]');
        if (existing && !existing.querySelector('[data-b2b-hang-columns]')) {
            if (existing.parentNode) {
                existing.parentNode.removeChild(existing);
            }
            existing = null;
        }
        if (existing) {
            return existing;
        }
        var panel = document.createElement('section');
        panel.className = 'b2b-hang-payment';
        panel.setAttribute('data-b2b-hang-payment', '1');
        panel.setAttribute('data-testid', 'b2b-hang-payment');
        panel.innerHTML = ''
            + '<header class="b2b-hang-payment__header">'
            + '<h2 class="b2b-hang-payment__title" data-b2b-hang-title></h2>'
            + '<p class="b2b-hang-payment__amount" data-b2b-hang-amount data-testid="b2b-hang-amount"></p>'
            + '</header>'
            + '<div class="b2b-hang-payment__columns" data-b2b-hang-columns>'
            + '<div class="b2b-hang-payment__order" data-b2b-hang-order data-testid="b2b-hang-order" hidden></div>'
            + '<div class="b2b-hang-payment__pay-block">'
            + '<h3 class="b2b-hang-payment__pay-title">支付方式</h3>'
            + '<div class="b2b-hang-payment__methods" data-b2b-hang-methods data-testid="b2b-hang-methods" hidden></div>'
            + '<p class="b2b-hang-payment__status" data-b2b-hang-status hidden></p>'
            + '<button type="button" class="w-button" data-variant="primary" data-b2b-hang-pay data-testid="b2b-hang-pay">'
            + '</button>'
            + '</div>'
            + '</div>';
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

    function applyHangNonPayableUi(panel, ctx, hang) {
        var titleEl = panel.querySelector('[data-b2b-hang-title]');
        var amountEl = panel.querySelector('[data-b2b-hang-amount]');
        var statusEl = panel.querySelector('[data-b2b-hang-status]');
        var payBtn = panel.querySelector('[data-b2b-hang-pay]');
        var payTitle = panel.querySelector('.b2b-hang-payment__pay-title');
        var methods = panel.querySelector('[data-b2b-hang-methods]');
        var viewState = String((ctx && ctx.view_state) || '');
        var hangStatus = String((ctx && ctx.hang_status) || '');
        if (!viewState && hangStatus === 'completed') {
            viewState = 'completed';
        }
        panel.classList.toggle('is-hang-completed', viewState === 'completed');
        panel.setAttribute('data-hang-view-state', viewState || 'blocked');
        if (titleEl) {
            titleEl.textContent = String((ctx && ctx.label) || (hang.purpose === 'deposit' ? '定金已结清' : '挂单已完成'));
        }
        if (amountEl) {
            var amount = formatMinor(ctx && ctx.amount_minor, ctx && ctx.currency);
            if (viewState === 'completed') {
                amountEl.textContent = (hang.purpose === 'deposit' ? '已付定金 ' : '已付尾款 ') + amount;
            } else {
                amountEl.textContent = amount;
            }
        }
        if (payTitle) {
            payTitle.textContent = viewState === 'completed' ? '支付状态' : '支付方式';
        }
        if (methods) {
            methods.hidden = true;
            methods.innerHTML = '';
        }
        if (statusEl) {
            statusEl.hidden = false;
            statusEl.textContent = String(
                (ctx && ctx.message)
                || (viewState === 'completed'
                    ? (hang.purpose === 'deposit' ? '本单定金已付清，无需再支付' : '本单尾款已结清，无需再支付')
                    : '当前挂单状态无法继续支付')
            );
            statusEl.setAttribute('data-tone', viewState === 'completed' ? 'success' : 'warning');
        }
        if (payBtn) {
            if (viewState === 'completed') {
                payBtn.disabled = false;
                payBtn.textContent = '返回批发身份';
                payBtn.setAttribute('data-b2b-hang-done', '1');
                payBtn.onclick = function () {
                    global.location.assign('/customer/account/index#b2b-identity');
                };
            } else {
                payBtn.disabled = true;
                payBtn.textContent = hang.purpose === 'deposit' ? '支付定金' : '支付尾款';
                payBtn.removeAttribute('data-b2b-hang-done');
                payBtn.onclick = null;
            }
        }
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
        renderHangOrderSummary(panel, {
            display_number: '',
            order_uuid: hang.orderUuid,
            hang_status: '',
            hang_status_label: hang.purpose === 'deposit' ? '待付定金' : '待付尾款',
            lines: [],
            goods_subtotal_minor: 0,
            deposit_amount_minor: 0,
            balance_amount_minor: 0,
            payable_minor: 0,
            loading: true
        }, 'CNY');
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
            renderHangOrderSummary(panel, {
                display_number: '',
                order_uuid: hang.orderUuid,
                hang_status: '',
                hang_status_label: '',
                lines: [],
                goods_subtotal_minor: 0,
                deposit_amount_minor: 0,
                balance_amount_minor: 0,
                payable_minor: 0,
                loading: false
            }, 'CNY');
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
            if (ctx && ctx.order_summary) {
                renderHangOrderSummary(panel, ctx.order_summary, ctx.currency || 'CNY');
            } else {
                renderHangOrderSummary(panel, {
                    display_number: '',
                    order_uuid: hang.orderUuid,
                    hang_status: '',
                    hang_status_label: '',
                    lines: [],
                    goods_subtotal_minor: 0,
                    deposit_amount_minor: 0,
                    balance_amount_minor: 0,
                    payable_minor: 0,
                    loading: false
                }, 'CNY');
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
        renderHangOrderSummary(panel, ctx.order_summary, ctx.currency);
        var canPay = ctx.can_pay !== false;
        if (!canPay) {
            applyHangNonPayableUi(panel, ctx, hang);
            return;
        }
        panel.classList.remove('is-hang-completed');
        panel.setAttribute('data-hang-view-state', 'payable');
        var methodCount = renderHangPaymentMethods(panel, ctx.payment_methods);
        if (methodCount <= 0) {
            if (statusEl) {
                statusEl.hidden = false;
                statusEl.textContent = '暂无可用支付方式';
            }
            if (payBtn) {
                payBtn.disabled = true;
            }
            return;
        }
        if (payBtn) {
            payBtn.textContent = String(ctx.label || payBtn.textContent);
            payBtn.addEventListener('click', async function () {
                var method = selectedPaymentMethod(panel);
                if (!method) {
                    if (statusEl) {
                        statusEl.hidden = false;
                        statusEl.textContent = '请选择支付方式';
                    }
                    return;
                }
                payBtn.disabled = true;
                if (statusEl) {
                    statusEl.hidden = false;
                    statusEl.textContent = '正在发起支付…';
                }
                try {
                    var result = await api['hang.startPayment']({
                        order_uuid: hang.orderUuid,
                        purpose: hang.purpose,
                        payment_method: method,
                        payment_idempotency_key: 'hang_' + hang.purpose + '_' + hang.orderUuid
                    }, { silent: true });
                    if (!result || result.success === false || result.ok === false) {
                        if (isHangAuthError(null, result)) {
                            sendToLogin(statusEl, payBtn);
                            return;
                        }
                        throw new Error((result && result.message) || '支付失败');
                    }
                    var payment = result.payment || {};
                    var redirect = hangRedirectUrl(payment);
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

    function creditSurfaceRoots() {
        var roots = [];
        var seen = typeof WeakSet !== 'undefined' ? new WeakSet() : null;
        function push(node) {
            if (!node || (seen && seen.has(node))) {
                return;
            }
            if (seen) {
                seen.add(node);
            }
            roots.push(node);
        }
        document.querySelectorAll('[data-weline-checkout], .weline-checkout').forEach(push);
        document.querySelectorAll('[data-w-mini-cart="1"]').forEach(push);
        document.querySelectorAll('[data-weline-cart], .weline-cart-shell').forEach(push);
        // Orphan credit widgets (e.g. layout preview): still sync nearest extras host.
        document.querySelectorAll('[data-b2b-checkout-credit]').forEach(function (credit) {
            var host = credit.closest(
                '[data-weline-checkout], .weline-checkout, [data-w-mini-cart="1"],'
                + ' [data-weline-cart], .weline-cart-shell, .mini-cart-drawer, [data-cart-summary-extras="1"]'
            ) || credit.parentElement;
            push(host);
        });
        return roots;
    }

    function applyCartTypeAll(cartType, opts) {
        creditSurfaceRoots().forEach(function (root) {
            applyCartType(root, cartType, opts);
        });
    }

    function bindCheckout() {
        applyCartTypeAll(readMode(), { ensureQuote: true });
        document.querySelectorAll('[data-weline-checkout]').forEach(function (root) {
            bindHangPayment(root);
        });
        global.addEventListener('weline:selling-mode-changed', function (event) {
            var mode = event && event.detail ? event.detail.cart_type || event.detail.selling_mode : readMode();
            applyCartTypeAll(mode, { ensureQuote: true });
        });
        global.addEventListener('weline:cart-updated', function (event) {
            var summary = event && event.detail ? event.detail : null;
            var type = summary && summary.cart_type ? summary.cart_type : readMode();
            // Sync chrome only — deposit-change refetch is handled inside ensureCreditQuote.
            applyCartTypeAll(type);
            if (String(type || '').toLowerCase() === 'tob') {
                var quoteOpts = { cart_type: 'tob' };
                if (summary) {
                    var sub = Number(summary.subtotal != null ? summary.subtotal : summary.subtotal_minor != null
                        ? Number(summary.subtotal_minor) / 100
                        : NaN);
                    if (isFinite(sub) && sub > 0) {
                        quoteOpts.subtotal = sub;
                    }
                    if (summary.currency) {
                        quoteOpts.currency = String(summary.currency).toUpperCase();
                    }
                }
                creditSurfaceRoots().forEach(function (root) {
                    if (root.querySelector('[data-b2b-checkout-credit]')) {
                        ensureCreditQuote(root, quoteOpts);
                    }
                });
            }
            document.querySelectorAll('[data-weline-checkout]').forEach(function (root) {
                if (root.getAttribute('data-b2b-hang-mode')) {
                    suppressRetailEmptyChrome(root);
                }
            });
        });
        global.addEventListener('weshop:mini-cart:open', function () {
            if (readMode() === 'tob') {
                applyCartTypeAll('tob', { ensureQuote: true });
            } else {
                applyCartTypeAll('toc');
            }
        });
        // 优惠券部件可能晚于本脚本挂到 slot，观察后仅补同步「批发不可用」，禁止再走 ensureCreditQuote。
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
                    document.querySelectorAll('[data-weline-checkout], .weline-checkout').forEach(function (root) {
                        var coupon = root.querySelector('[data-marketing-checkout-coupon], .w-marketing-checkout-coupon');
                        if (!coupon) {
                            return;
                        }
                        if (coupon.getAttribute('data-b2b-coupon-unavailable') === '1') {
                            return;
                        }
                        syncCheckoutCouponAvailability(root, 'tob');
                    });
                }, 50);
            });
            document.querySelectorAll('[data-weline-checkout], .weline-checkout').forEach(function (root) {
                observer.observe(root, { childList: true, subtree: true });
            });
        }
        global.WelineB2BCheckoutTob = {
            applyCartType: applyCartType,
            applyCartTypeAll: applyCartTypeAll,
            readMode: readMode,
            hangPurposeFromLocation: hangPurposeFromLocation,
            hangLoginUrl: hangLoginUrl,
            bindHangPayment: bindHangPayment,
            renderHangOrderSummary: renderHangOrderSummary,
            syncFromFrozen: syncFromFrozen,
            refreshCreditQuote: refreshCreditQuote,
            ensureCreditQuote: ensureCreditQuote,
            rememberQuoteOpts: rememberQuoteOpts,
            estimateDepositMinor: estimateDepositMinor,
            readGoodsSubtotalMajor: readGoodsSubtotalMajor,
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
