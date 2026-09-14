(function () {
    'use strict';

    var guestTokenStorageKey = 'weline.cart.guest_token';
    var openClass = 'is-drawer-open';
    var cartRefreshTimer = null;

    function drawerBusyCount(root) {
        return Math.max(0, Number(root.getAttribute('data-mini-cart-busy-count') || 0));
    }

    function isDrawerBusy(root) {
        return drawerBusyCount(root) > 0;
    }

    function updateDrawerBusyUi(root) {
        if (!root) {
            return;
        }
        var busy = isDrawerBusy(root);
        root.classList.toggle('is-drawer-busy', busy);
        root.setAttribute('aria-busy', busy ? 'true' : 'false');
        var drawer = root.querySelector('[data-mini-cart-drawer]');
        if (!drawer) {
            return;
        }
        var overlay = drawer.querySelector('[data-mini-cart-busy-overlay]');
        if (!overlay) {
            overlay = document.createElement('div');
            overlay.className = 'mini-cart-drawer__busy-overlay';
            overlay.setAttribute('data-mini-cart-busy-overlay', '1');
            overlay.setAttribute('role', 'status');
            overlay.hidden = true;
            var spinner = document.createElement('span');
            spinner.className = 'mini-cart-drawer__spinner';
            spinner.setAttribute('aria-hidden', 'true');
            var label = document.createElement('span');
            label.className = 'mini-cart-drawer__busy-label visually-hidden';
            label.textContent = attr(root, 'data-i18n-updating', attr(root, 'data-i18n-loading', '正在更新购物车...'));
            overlay.append(spinner, label);
            drawer.appendChild(overlay);
        }
        overlay.hidden = !busy;
    }

    function beginDrawerBusy(root) {
        if (!root) {
            return;
        }
        root.setAttribute('data-mini-cart-busy-count', String(drawerBusyCount(root) + 1));
        updateDrawerBusyUi(root);
    }

    function endDrawerBusy(root) {
        if (!root) {
            return;
        }
        root.setAttribute('data-mini-cart-busy-count', String(Math.max(0, drawerBusyCount(root) - 1)));
        updateDrawerBusyUi(root);
    }

    function resetDrawerBusy(root) {
        if (!root) {
            return;
        }
        root.setAttribute('data-mini-cart-busy-count', '0');
        updateDrawerBusyUi(root);
    }

    async function runWithDrawerBusy(root, task) {
        if (!root || typeof task !== 'function') {
            return undefined;
        }
        beginDrawerBusy(root);
        try {
            return await task();
        } finally {
            endDrawerBusy(root);
        }
    }

    function applyMiniCartBusyDelta(delta) {
        var step = Number(delta || 0);
        if (!step) {
            return;
        }
        document.querySelectorAll('[data-w-mini-cart="1"]').forEach(function (root) {
            if (!isDrawerOpen(root)) {
                return;
            }
            if (step > 0) {
                beginDrawerBusy(root);
                return;
            }
            endDrawerBusy(root);
        });
    }

    function resetAllDrawerBusy() {
        document.querySelectorAll('[data-w-mini-cart="1"]').forEach(resetDrawerBusy);
    }

    var summaryCacheKeyPrefix = 'weline.cart.summary_cache';
    /** Pending coupon code from Marketing cart-updated — passed into getCart for discount_preview. */
    var pendingCouponCode = '';
    var pendingDiscountPreview = null;

    /**
     * Opaque cart_type SPI code (Cart core default CODE_TOC = 'toc').
     * Theme never maps wholesale/retail labels — only passes preference strings.
     */
    function normalizeCartType(value) {
        var mode = String(value || '').trim().toLowerCase();
        return mode || 'toc';
    }

    /**
     * Preferred cart_type from optional providers, then summary, else Cart SPI default 'toc'.
     * Does not hardcode B2B dual-cart UI; Theme stays retail-only without those helpers.
     */
    function preferredCartType(summary) {
        try {
            if (window.WelineB2BSellingMode && typeof window.WelineB2BSellingMode.preferredMode === 'function') {
                var fromB2b = String(window.WelineB2BSellingMode.preferredMode() || '').trim().toLowerCase();
                if (fromB2b) {
                    return fromB2b;
                }
            }
        } catch (e0) {}
        try {
            if (window.Weline && window.Weline.CartTypePreference && typeof window.Weline.CartTypePreference.get === 'function') {
                var fromPref = String(window.Weline.CartTypePreference.get() || '').trim().toLowerCase();
                if (fromPref) {
                    return fromPref;
                }
            }
        } catch (e1) {}
        if (summary && typeof summary === 'object') {
            var fromSummary = String(summary.cart_type || summary.selling_mode || '').trim().toLowerCase();
            if (fromSummary) {
                return fromSummary;
            }
        }
        return 'toc';
    }

    function summaryCacheStorageKey(cartType) {
        return summaryCacheKeyPrefix + '.' + normalizeCartType(cartType || preferredCartType());
    }

    function emptySummaryForType(cartType, extras) {
        var mode = normalizeCartType(cartType || preferredCartType());
        var base = {
            success: true,
            cart_count: 0,
            item_count: 0,
            is_empty: true,
            items: [],
            subtotal: 0,
            currency: 'CNY',
            cart_type: mode,
            selling_mode: mode,
        };
        if (extras && typeof extras === 'object') {
            return Object.assign(base, extras);
        }
        return base;
    }

    function extractCartPayload(result) {
        return result && result.data && typeof result.data === 'object' ? result.data : result;
    }

    function isCartTypeGateError(payload) {
        var code = String(
            (payload && (payload.error_code || payload.code)) || ''
        ).toLowerCase();
        return code === 'cart_commerce_type_login_required'
            || code === 'cart_commerce_type_membership_required'
            || code === 'cart_commerce_type_unregistered';
    }

    function gateReasonFromPayload(payload) {
        var code = String((payload && payload.error_code) || '').toLowerCase();
        if (code === 'cart_commerce_type_login_required') {
            return 'login';
        }
        if (code === 'cart_commerce_type_membership_required') {
            return 'membership';
        }
        return '';
    }

    function currentGuestTokenForCache() {
        if (window.WelineCart && typeof window.WelineCart.getGuestSession === 'function') {
            var session = window.WelineCart.getGuestSession();
            if (session && session.token) {
                return String(session.token).trim();
            }
        }
        try {
            var raw = window.localStorage.getItem('weline.cart.guest_session');
            if (raw) {
                var parsed = JSON.parse(raw);
                if (parsed && parsed.token) {
                    return String(parsed.token).trim();
                }
            }
        } catch (e) {
            // privacy modes
        }
        return guestToken();
    }

    function rememberSummaryCache(summary) {
        if (!summary || typeof summary !== 'object' || summary.success === false) {
            return;
        }
        var mode = normalizeCartType(summary.cart_type || summary.selling_mode || preferredCartType());
        summary.cart_type = mode;
        summary.selling_mode = mode;
        var currency = String(summary.currency || '').trim().toUpperCase();
        if (!/^[A-Z]{3}$/.test(currency)
            && window.WelineCart && typeof window.WelineCart.currentDisplayCurrency === 'function') {
            currency = String(window.WelineCart.currentDisplayCurrency() || 'CNY').toUpperCase();
        }
        if (!/^[A-Z]{3}$/.test(currency)) {
            currency = 'CNY';
        }
        summary.currency = currency;
        var locale = '';
        if (window.WelineCart && typeof window.WelineCart.currentDisplayLocale === 'function') {
            locale = String(window.WelineCart.currentDisplayLocale() || '');
        }
        if (window.WelineCart && typeof window.WelineCart.rememberSummary === 'function') {
            try {
                window.WelineCart.rememberSummary(summary, { cart_type: mode, currency: currency, locale: locale });
            } catch (e0) {}
        }
        try {
            window.localStorage.setItem(summaryCacheStorageKey(mode), JSON.stringify({
                guest_token: currentGuestTokenForCache(),
                scope_key: String(summary.scope_key || ''),
                cart_type: mode,
                currency: currency,
                locale: locale,
                saved_at: Date.now(),
                summary: summary,
            }));
        } catch (e) {
            // privacy modes
        }
    }

    function summaryCacheHasLineItems(summary) {
        if (!summary || typeof summary !== 'object' || summary.is_empty === true) {
            return false;
        }
        if (Number(summary.cart_count || summary.item_count || 0) > 0) {
            return true;
        }
        return Array.isArray(summary.items) && summary.items.length > 0;
    }

    function clearSummaryCacheBucket(mode) {
        var next = normalizeCartType(mode);
        try {
            window.localStorage.removeItem(summaryCacheStorageKey(next));
        } catch (e0) {}
        if (window.WelineCart && typeof window.WelineCart.clearCachedSummary === 'function') {
            try {
                window.WelineCart.clearCachedSummary({ cartType: next });
            } catch (e1) {}
        }
    }

    // Sibling Persistence counts win over a stale empty Presentation bucket.
    function invalidateEmptyCachesClaimedBySiblings(summary) {
        var rows = summary && Array.isArray(summary.sibling_carts) ? summary.sibling_carts : [];
        rows.forEach(function (row) {
            if (!row || Number(row.item_count || row.cart_count || 0) <= 0) {
                return;
            }
            var type = normalizeCartType(row.cart_type || 'toc');
            var cached = normalizeSummary(readSummaryCache(type));
            if (cached && !summaryCacheHasLineItems(cached)) {
                clearSummaryCacheBucket(type);
            }
        });
    }

    function currentStorefrontCurrency() {
        if (window.WelineCart && typeof window.WelineCart.currentDisplayCurrency === 'function') {
            try {
                var fromCart = String(window.WelineCart.currentDisplayCurrency() || '').toUpperCase();
                if (/^[A-Z]{3}$/.test(fromCart)) {
                    return fromCart;
                }
            } catch (e0) {}
        }
        try {
            var cfgNode = document.getElementById('weline-frontend-runtime-config');
            if (cfgNode && cfgNode.textContent) {
                var cfg = JSON.parse(cfgNode.textContent);
                var fromCfg = String(cfg.currentCurrency || cfg.currency || '').toUpperCase();
                if (/^[A-Z]{3}$/.test(fromCfg)) {
                    return fromCfg;
                }
            }
        } catch (e1) {}
        return 'CNY';
    }

    function currentStorefrontLocale() {
        if (window.WelineCart && typeof window.WelineCart.currentDisplayLocale === 'function') {
            try {
                return String(window.WelineCart.currentDisplayLocale() || '');
            } catch (e0) {}
        }
        return String((document.documentElement && document.documentElement.getAttribute('lang')) || '')
            .trim().replace(/-/g, '_');
    }

    function cacheMatchesStorefront(data) {
        if (!data || !data.summary || data.summary.success === false) {
            return false;
        }
        var wantCurrency = currentStorefrontCurrency();
        var cachedCurrency = String(data.currency || data.summary.currency || '').trim().toUpperCase();
        if (!cachedCurrency || cachedCurrency !== wantCurrency) {
            return false;
        }
        var wantLocale = currentStorefrontLocale();
        var cachedLocale = String(data.locale || '').trim().replace(/-/g, '_');
        if (wantLocale && cachedLocale && wantLocale.toLowerCase() !== cachedLocale.toLowerCase()) {
            return false;
        }
        return true;
    }

    function cartQueryParams(extra) {
        var params = Object.assign({}, extra || {});
        var mode = preferredCartType();
        params.cart_type = mode;
        params.selling_mode = mode;
        if (pendingCouponCode) {
            params.coupon_code = pendingCouponCode;
        }
        return params;
    }

    function mergeDiscountPreviewIntoSummary(summary, preview, clearDiscount) {
        if (!summary || typeof summary !== 'object') {
            return summary;
        }
        var next = Object.assign({}, summary);
        if (clearDiscount) {
            delete next.discount_preview;
            return next;
        }
        if (preview && typeof preview === 'object' && Number(preview.amount_minor || 0) > 0) {
            next.discount_preview = preview;
        }
        return next;
    }

    function resolveEventDiscountPreview(detail) {
        if (!detail || typeof detail !== 'object') {
            return null;
        }
        if (detail.clear_discount === true) {
            return null;
        }
        if (detail.discount_preview && typeof detail.discount_preview === 'object'
            && Number(detail.discount_preview.amount_minor || 0) > 0) {
            return detail.discount_preview;
        }
        return null;
    }

    /** Set opaque data-cart-type only; Theme does not rewrite titles/badges. */
    function applyCartTypeAttr(root, summary) {
        if (!root) {
            return;
        }
        var cartType = normalizeCartType(
            (summary && (summary.cart_type || summary.selling_mode)) || preferredCartType(summary)
        );
        root.setAttribute('data-cart-type', cartType);
    }

    function readSummaryCache(preferredType) {
        var mode = normalizeCartType(preferredType || preferredCartType());
        try {
            var raw = window.localStorage.getItem(summaryCacheStorageKey(mode));
            if (raw) {
                var data = JSON.parse(raw);
                if (cacheMatchesStorefront(data)) {
                    var token = currentGuestTokenForCache();
                    var cachedToken = String(data.guest_token || '').trim();
                    // Ghost-cart gate: no matching guest_token → never paint local summary as has-items.
                    if (!token || !cachedToken || token !== cachedToken) {
                        // miss
                    } else {
                        var typed = Object.assign({}, data.summary);
                        typed.cart_type = mode;
                        typed.selling_mode = mode;
                        return typed;
                    }
                }
            }
        } catch (e) {}
        if (window.WelineCart && typeof window.WelineCart.getCachedSummary === 'function') {
            try {
                var shared = window.WelineCart.getCachedSummary({ cartType: mode, requireTokenMatch: true });
                if (shared && shared.success !== false) {
                    var sharedType = normalizeCartType(shared.cart_type || shared.selling_mode || 'toc');
                    var sharedCurrency = String(shared.currency || '').toUpperCase();
                    if (sharedType === mode
                        && (!sharedCurrency || sharedCurrency === currentStorefrontCurrency())) {
                        return shared;
                    }
                }
            } catch (e2) {}
        }
        // Legacy untyped key: only accept for toc to avoid sticking wholesale to retail lines.
        if (mode === 'toc') {
            try {
                var legacyRaw = window.localStorage.getItem(summaryCacheKeyPrefix);
                if (!legacyRaw) {
                    return null;
                }
                var legacy = JSON.parse(legacyRaw);
                if (!cacheMatchesStorefront(legacy)) {
                    return null;
                }
                var legacyType = normalizeCartType(legacy.cart_type || legacy.summary.cart_type || 'toc');
                if (legacyType !== 'toc') {
                    return null;
                }
                var legacyToken = currentGuestTokenForCache();
                var legacyCachedToken = String(legacy.guest_token || '').trim();
                // Ghost-cart gate (legacy untyped key).
                if (!legacyToken || !legacyCachedToken || legacyToken !== legacyCachedToken) {
                    return null;
                }
                return Object.assign({}, legacy.summary, { cart_type: 'toc', selling_mode: 'toc' });
            } catch (e3) {
                return null;
            }
        }
        return null;
    }

    function applyCachedSummaryToRoots() {
        if (window.WelineCart && typeof window.WelineCart.needsOriginRefresh === 'function'
            && window.WelineCart.needsOriginRefresh()) {
            return false;
        }
        if (window.Weline && window.Weline.MiniCart && window.Weline.MiniCart.__painting) {
            return !!window.Weline.MiniCart.__lastPaintHit;
        }
        var cached = normalizeSummary(readSummaryCache());
        if (!cached || cached.success === false) {
            return false;
        }
        if (cached.cart_count == null && !Array.isArray(cached.items)) {
            return false;
        }
        if (window.Weline && window.Weline.MiniCart) {
            window.Weline.MiniCart.__painting = true;
        }
        try {
            document.querySelectorAll('[data-w-mini-cart="1"]').forEach(function (root) {
                if (isDemoChromeOnly(root)) {
                    return;
                }
                applySummary(root, cached);
            });
            if (window.Weline && window.Weline.MiniCart) {
                window.Weline.MiniCart.__lastPaintHit = true;
            }
            return true;
        } finally {
            if (window.Weline && window.Weline.MiniCart) {
                window.Weline.MiniCart.__painting = false;
            }
        }
    }

    function formatMoney(amount, currency) {
        var symbol = '¥';
        currency = String(currency || 'CNY').toUpperCase();
        if (currency === 'USD') symbol = '$';
        else if (currency === 'EUR') symbol = '€';
        else if (currency === 'GBP') symbol = '£';
        var n = Number(amount || 0);
        return symbol + n.toFixed(2);
    }

    function text(el, value) {
        if (el) el.textContent = value == null ? '' : String(value);
    }

    function attr(root, name, fallback) {
        return String(root.getAttribute(name) || fallback || '').trim();
    }

    function guestToken() {
        if (window.WelineCart && typeof window.WelineCart.getGuestSession === 'function') {
            var session = window.WelineCart.getGuestSession();
            var sessionToken = session && session.token ? String(session.token).trim() : '';
            if (sessionToken) {
                return sessionToken;
            }
        }
        try {
            return String(window.sessionStorage.getItem(guestTokenStorageKey) || '').trim();
        } catch (e) {
            return '';
        }
    }

    async function waitForCartApi() {
        if (window.Weline && window.Weline.Api && typeof window.Weline.Api.resource === 'function') {
            return window.Weline.Api.resource('cart');
        }
        if (window.Weline && typeof window.Weline.use === 'function') {
            await window.Weline.use('api');
        }
        return cartApi();
    }

    async function cartApi() {
        if (!window.Weline || !window.Weline.Api || typeof window.Weline.Api.resource !== 'function') {
            throw new Error('Weline.Api unavailable');
        }
        return window.Weline.Api.resource('cart');
    }

    function normalizeSummary(summary) {
        if (!summary || typeof summary !== 'object') {
            return null;
        }
        var normalized = Object.assign({}, summary);
        if (normalized.subtotal == null && normalized.subtotal_minor != null) {
            normalized.subtotal = Number(normalized.subtotal_minor) / 100;
        }
        if (normalized.grand_total == null && normalized.grand_total_minor != null) {
            normalized.grand_total = Number(normalized.grand_total_minor) / 100;
        }
        if (Array.isArray(normalized.items)) {
            normalized.items = normalized.items.map(function (item) {
                if (!item || typeof item !== 'object' || item.price != null || item.unit_price_minor == null) {
                    return item;
                }
                return Object.assign({}, item, {
                    price: Number(item.unit_price_minor) / 100,
                });
            });
        }
        return normalized;
    }

    function variantLabel(item) {
        if (!item || typeof item !== 'object') {
            return '';
        }
        var options = Array.isArray(item.options) ? item.options : [];
        if (options.length) {
            var parts = [];
            options.forEach(function (option) {
                if (!option || typeof option !== 'object') {
                    return;
                }
                var label = String(option.label || option.code || '').trim();
                var value = String(option.value_label || option.value || '').trim();
                if (label && value) {
                    parts.push(label + ': ' + value);
                }
            });
            if (parts.length) {
                return parts.join(' · ');
            }
        }
        var direct = String(item.variant_label || '').trim();
        if (direct) {
            return direct;
        }
        var selected = item.selected_options;
        if (!selected || typeof selected !== 'object') {
            var sku = String(item.sku || '').trim();
            return sku;
        }
        var selectedParts = [];
        Object.keys(selected).forEach(function (key) {
            var value = selected[key];
            if (typeof value === 'string' || typeof value === 'number') {
                selectedParts.push(String(value));
                return;
            }
            if (value && typeof value === 'object') {
                var selectedLabel = String(value.label || value.value || '').trim();
                if (selectedLabel) {
                    selectedParts.push(selectedLabel);
                }
            }
        });
        return selectedParts.join(' / ');
    }

    function appendMiniCartOptions(root, details, item) {
        var options = item && Array.isArray(item.options) ? item.options : [];
        if (options.length) {
            var list = document.createElement('ul');
            list.className = 'mini-cart-drawer__line-options';
            options.forEach(function (option) {
                if (!option || typeof option !== 'object') {
                    return;
                }
                var label = String(option.label || option.code || '').trim();
                var value = String(option.value_label || option.value || '').trim();
                if (!label || !value) {
                    return;
                }
                var li = document.createElement('li');
                li.className = 'mini-cart-drawer__line-option';
                var swatchImage = String(option.swatch_image || '').trim();
                var swatchColor = String(option.swatch_color || '').trim();
                if (isDisplayableImageUrl(swatchImage)) {
                    var btn = document.createElement('button');
                    btn.type = 'button';
                    btn.className = 'mini-cart-drawer__line-option-swatch-btn';
                    btn.setAttribute('data-mini-cart-swatch-trigger', '1');
                    btn.setAttribute('data-mini-cart-swatch-src', swatchImage);
                    btn.setAttribute('aria-label', attr(root, 'data-i18n-swatch-preview', '查看规格图'));
                    var img = document.createElement('img');
                    img.className = 'mini-cart-drawer__line-option-swatch';
                    img.src = swatchImage;
                    img.alt = '';
                    btn.appendChild(img);
                    li.appendChild(btn);
                } else if (swatchColor) {
                    var swatch = document.createElement('span');
                    swatch.className = 'mini-cart-drawer__line-option-swatch mini-cart-drawer__line-option-swatch--color';
                    swatch.style.backgroundColor = swatchColor;
                    swatch.setAttribute('aria-hidden', 'true');
                    li.appendChild(swatch);
                }
                var copy = document.createElement('span');
                copy.className = 'mini-cart-drawer__line-option-text';
                copy.textContent = label + ': ' + value;
                li.appendChild(copy);
                list.appendChild(li);
            });
            if (list.childNodes.length) {
                details.appendChild(list);
                return;
            }
        }
        var variant = variantLabel(item);
        if (!variant) {
            return;
        }
        var variantEl = document.createElement('p');
        variantEl.className = 'mini-cart-drawer__line-variant';
        variantEl.textContent = variant;
        details.appendChild(variantEl);
    }

    function lineTotal(item) {
        var qty = Number(item.qty || item.quantity || 1);
        var price = Number(item.price || 0);
        var rowTotal = item.row_total != null ? Number(item.row_total) : (price * qty);
        return rowTotal;
    }

    function isDisplayableImageUrl(value) {
        var image = String(value || '').trim();
        if (!image) {
            return false;
        }
        // FileManager internal refs and other non-http(s) schemes must never hit img.src.
        if (/^asset:\/\//i.test(image)) {
            return false;
        }
        if (/^[a-z][a-z0-9+.-]*:/i.test(image) && !/^(https?:)?\/\//i.test(image)) {
            return false;
        }
        return true;
    }

    function buildLine(root, item, currency) {
        var row = document.createElement('article');
        row.className = 'mini-cart-drawer__line';
        row.setAttribute('data-mini-cart-line', '1');

        var itemId = String(item.item_id || '').trim();
        if (itemId) {
            row.setAttribute('data-item-id', itemId);
        }

        var image = String(item.image || attr(root, 'data-placeholder-image', '')).trim();
        if (!isDisplayableImageUrl(image)) {
            image = attr(root, 'data-placeholder-image', '');
            if (!isDisplayableImageUrl(image)) {
                image = '';
            }
        }
        var url = String(item.url || '/cart');
        var name = String(item.name || '');
        var qty = Math.max(1, Number(item.qty || item.quantity || 1));

        var media = document.createElement('a');
        media.className = 'mini-cart-drawer__line-media';
        media.href = url;
        media.tabIndex = -1;
        media.setAttribute('aria-hidden', 'true');
        if (image) {
            var img = document.createElement('img');
            img.src = image;
            img.alt = '';
            media.appendChild(img);
        }

        var details = document.createElement('div');
        details.className = 'mini-cart-drawer__line-details';

        var title = document.createElement('a');
        title.className = 'mini-cart-drawer__line-title';
        title.href = url;
        title.textContent = name;
        details.appendChild(title);

        appendMiniCartOptions(root, details, item);

        var meta = document.createElement('div');
        meta.className = 'mini-cart-drawer__line-meta';

        var qtyWrap = document.createElement('div');
        qtyWrap.className = 'mini-cart-drawer__qty';
        qtyWrap.setAttribute('data-qty-control', '1');

        var decrease = document.createElement('button');
        decrease.type = 'button';
        decrease.setAttribute('data-qty-decrease', '1');
        decrease.setAttribute('aria-label', attr(root, 'data-i18n-decrease', '减少数量'));
        decrease.textContent = '−';

        var input = document.createElement('input');
        input.type = 'number';
        input.className = 'mini-cart-drawer__qty-input';
        input.min = '1';
        input.max = '999';
        input.value = String(qty);
        input.inputMode = 'numeric';
        input.setAttribute('data-qty-input', '1');
        input.setAttribute('aria-label', attr(root, 'data-i18n-qty', '数量'));

        var increase = document.createElement('button');
        increase.type = 'button';
        increase.setAttribute('data-qty-increase', '1');
        increase.setAttribute('aria-label', attr(root, 'data-i18n-increase', '增加数量'));
        increase.textContent = '+';

        qtyWrap.appendChild(decrease);
        qtyWrap.appendChild(input);
        qtyWrap.appendChild(increase);

        var removeBtn = document.createElement('button');
        removeBtn.type = 'button';
        removeBtn.className = 'mini-cart-drawer__remove';
        removeBtn.setAttribute('data-remove-item', '1');
        removeBtn.textContent = attr(root, 'data-i18n-remove', '移除');

        meta.appendChild(qtyWrap);
        meta.appendChild(removeBtn);
        details.appendChild(meta);

        var priceEl = document.createElement('div');
        priceEl.className = 'mini-cart-drawer__line-price';
        priceEl.setAttribute('data-line-total', '1');

        var priceRow = document.createElement('span');
        priceRow.className = 'mini-cart-drawer__line-price-row';
        priceRow.setAttribute('data-line-price-row', '1');

        var nowEl = document.createElement('span');
        nowEl.className = 'mini-cart-drawer__line-price-now';
        nowEl.setAttribute('data-line-price-now', '1');
        nowEl.textContent = formatMoney(lineTotal(item), currency);
        priceRow.appendChild(nowEl);

        var compareAtMinor = Math.max(0, Number(item && item.compare_at_minor) || 0);
        var unitMinor = Math.max(0, Number(item && item.unit_price_minor) || 0);
        var originalMajor = Number(item && item.original_price);
        if (!(originalMajor > 0) && compareAtMinor > 0) {
            originalMajor = compareAtMinor / 100;
        }
        var hasDeal = Boolean(item && item.has_deal)
            || (compareAtMinor > unitMinor && unitMinor > 0)
            || (originalMajor > Number(item && item.price) && Number(item && item.price) > 0);
        if (hasDeal && originalMajor > 0) {
            var wasEl = document.createElement('span');
            wasEl.className = 'mini-cart-drawer__line-price-was';
            wasEl.setAttribute('data-line-price-was', '1');
            wasEl.textContent = formatMoney(originalMajor * qty, currency);
            priceRow.appendChild(wasEl);
        }
        priceEl.appendChild(priceRow);

        if (hasDeal && originalMajor > 0) {
            var campaignLabel = String(item && item.campaign_label || '').trim();
            var campaignUrl = String(item && item.campaign_url || '').trim();
            if (campaignLabel) {
                var campaignEl = campaignUrl
                    ? document.createElement('a')
                    : document.createElement('span');
                campaignEl.className = 'mini-cart-drawer__line-price-campaign';
                campaignEl.setAttribute('data-line-price-campaign', '1');
                campaignEl.textContent = campaignLabel;
                if (campaignUrl) {
                    campaignEl.href = campaignUrl;
                }
                priceEl.appendChild(campaignEl);
            }
        }

        row.appendChild(media);
        row.appendChild(details);
        row.appendChild(priceEl);
        return row;
    }

    function renderItems(root, items, currency) {
        var box = root.querySelector('[data-mini-cart-items]');
        var footer = root.querySelector('[data-mini-cart-footer]');
        if (!box) return;

        while (box.firstChild) box.removeChild(box.firstChild);

        if (!items || !items.length) {
            var empty = document.createElement('div');
            empty.className = 'mini-cart-drawer__empty';
            empty.setAttribute('data-mini-cart-empty', '1');

            var p = document.createElement('p');
            var emptyMessage = attr(root, 'data-empty-message', '');
            p.textContent = emptyMessage || attr(root, 'data-i18n-empty', '购物车是空的');
            empty.appendChild(p);

            var siblings = (root.__welineLastSummary && Array.isArray(root.__welineLastSummary.sibling_carts))
                ? root.__welineLastSummary.sibling_carts
                : [];
            siblings.forEach(function (row) {
                if (!row || Number(row.item_count || row.cart_count || 0) <= 0) {
                    return;
                }
                var type = String(row.cart_type || '').toLowerCase() === 'tob' ? 'tob' : 'toc';
                var label = String(row.label || type).trim() || type;
                var count = Number(row.item_count || row.cart_count || 0);
                if (row.switchable === false) {
                    return;
                }
                var btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'mini-cart-drawer__btn mini-cart-drawer__btn--secondary';
                btn.setAttribute('data-mini-cart-sibling', type);
                btn.setAttribute('data-mini-cart-sibling-switch', type);
                btn.textContent = '浏览' + label + ' ' + count + '件商品';
                btn.addEventListener('click', function (event) {
                    if (event && typeof event.preventDefault === 'function') {
                        event.preventDefault();
                    }
                    if (event && typeof event.stopPropagation === 'function') {
                        event.stopPropagation();
                    }
                    if (window.WelineCart && typeof window.WelineCart.requestCartType === 'function') {
                        window.WelineCart.requestCartType(type, {
                            source: 'mini-sibling-cta',
                            forceNetwork: true,
                        });
                    } else {
                        try {
                            window.sessionStorage.setItem('weline_cart_type_explicit', type);
                        } catch (e0) {}
                        window.dispatchEvent(new CustomEvent('weline:cart-type-changed', {
                            detail: {
                                cart_type: type,
                                selling_mode: type,
                                source: 'mini-sibling-cta',
                                forceNetwork: true,
                            },
                        }));
                    }
                    if (window.Weline && window.Weline.MiniCart && typeof window.Weline.MiniCart.refresh === 'function') {
                        window.Weline.MiniCart.refresh({ forceNetwork: true });
                    }
                });
                empty.appendChild(btn);
            });

            var shop = document.createElement('a');
            shop.href = '/';
            shop.className = 'mini-cart-drawer__btn mini-cart-drawer__btn--secondary';
            shop.textContent = attr(root, 'data-i18n-continue', '继续购物');
            empty.appendChild(shop);

            box.appendChild(empty);
            if (footer) footer.hidden = true;
            root.classList.add('is-empty');
            return;
        }

        root.classList.remove('is-empty');
        if (footer) footer.hidden = false;

        items.forEach(function (item) {
            if (!item || typeof item !== 'object') return;
            box.appendChild(buildLine(root, item, currency));
        });
    }

    function discountPreview(summary) {
        if (!summary || typeof summary !== 'object' || !summary.discount_preview) {
            return null;
        }
        return summary.discount_preview;
    }

    function discountAmountMajor(summary) {
        var preview = discountPreview(summary);
        if (!preview) {
            return 0;
        }
        var minor = Number(preview.amount_minor || 0);
        if (minor <= 0) {
            return 0;
        }
        var precision = Number(preview.currency_precision == null ? 2 : preview.currency_precision);
        return minor / Math.pow(10, precision);
    }

    function discountKindLabel(root, kind) {
        if (kind === 'stack') {
            return attr(root, 'data-i18n-discount-stack', '叠加优惠');
        }
        if (kind === 'coupon') {
            return attr(root, 'data-i18n-discount-coupon', '优惠券');
        }
        return attr(root, 'data-i18n-discount-automatic', '自动优惠');
    }

    function renderDiscountBreakdown(root, summary, currency) {
        var breakdown = root.querySelector('[data-mini-cart-discount-breakdown]');
        var goodsEl = root.querySelector('[data-cart-goods-subtotal]');
        var linesEl = root.querySelector('[data-mini-cart-discount-lines]');
        if (!breakdown || !linesEl) {
            return;
        }

        var preview = discountPreview(summary);
        var items = preview && Array.isArray(preview.items) ? preview.items : [];
        var subtotal = Number(summary.subtotal || summary.grand_total || 0);
        var precision = Number(preview && preview.currency_precision != null ? preview.currency_precision : 2);

        linesEl.innerHTML = '';
        // Quote may return amount_minor without per-line items; still show one coupon row.
        if (!items.length && preview && Number(preview.amount_minor || 0) > 0) {
            items = [{
                label: String(preview.coupon_code || preview.label || '').trim(),
                coupon_code: String(preview.coupon_code || '').trim(),
                source: preview.coupon_code ? 'coupon' : 'automatic',
                kind: preview.coupon_code ? 'coupon' : 'automatic',
                amount_minor: Number(preview.amount_minor || 0),
                stackable: false,
            }];
        }
        if (!items.length) {
            breakdown.hidden = true;
            // Keep goods text current for credit/deposit readers even while the row is hidden.
            if (goodsEl) {
                text(goodsEl, formatMoney(subtotal, currency));
            }
            return;
        }

        breakdown.hidden = false;
        if (goodsEl) {
            text(goodsEl, formatMoney(subtotal, currency));
        }

        items.forEach(function (item) {
            if (!item || typeof item !== 'object') {
                return;
            }
            var minor = Number(item.amount_minor || 0);
            if (minor <= 0) {
                return;
            }
            var row = document.createElement('div');
            row.className = 'mini-cart-drawer__discount-line';
            if (item.stackable || item.kind === 'stack') {
                row.classList.add('mini-cart-drawer__discount-line--stack');
            }

            var label = document.createElement('span');
            var kind = String(item.kind || (item.source === 'coupon' ? 'coupon' : 'automatic'));
            var name = String(item.label || item.coupon_code || '').trim();
            label.textContent = discountKindLabel(root, kind) + (name ? ' ' + name : '');

            var amount = document.createElement('strong');
            amount.textContent = '-' + formatMoney(minor / Math.pow(10, precision), currency);

            row.appendChild(label);
            row.appendChild(amount);
            linesEl.appendChild(row);
        });
    }

    function applySummary(root, summary) {
        if (!summary || typeof summary !== 'object') return;
        if (!summaryCacheHasLineItems(summary)) {
            invalidateEmptyCachesClaimedBySiblings(summary);
        }
        root.__welineLastSummary = summary;
        var count = Number(summary.cart_count || summary.item_count || 0);
        var currency = String(summary.currency || 'CNY');
        var subtotal = Number(summary.subtotal || summary.grand_total || 0);
        var discountMajor = discountAmountMajor(summary);
        var payable = Math.max(0, subtotal - discountMajor);
        var formatted = formatMoney(payable, currency);
        var goodsFormatted = formatMoney(subtotal, currency);
        var items = Array.isArray(summary.items) ? summary.items : [];
        var cartType = normalizeCartType(summary.cart_type || summary.selling_mode || preferredCartType());
        var gate = String(summary.gate_reason || '').toLowerCase();

        root.setAttribute('data-cart-count', String(count));
        root.setAttribute('data-cart-subtotal', formatted);
        // Authoritative goods base (major) for B2B deposit/credit — must stay in sync even when
        // the discount breakdown row is hidden (otherwise credit keeps a stale larger total).
        root.setAttribute('data-cart-goods-subtotal-major', String(isFinite(subtotal) && subtotal > 0 ? subtotal : 0));
        root.setAttribute('data-cart-type', cartType);
        if (gate === 'login' || gate === 'membership') {
            root.setAttribute('data-cart-gate', gate);
        } else {
            root.removeAttribute('data-cart-gate');
        }
        root.classList.toggle('is-empty', count <= 0 || !!summary.is_empty);
        root.classList.toggle('is-demo', !!summary.is_demo);
        applyCartTypeAttr(root, summary);

        var badge = root.querySelector('[data-cart-count-badge]');
        if (badge) {
            badge.hidden = count <= 0;
            badge.textContent = count > 99 ? '99+' : String(count);
        }
        text(root.querySelector('[data-cart-subtotal-text]'), formatted);
        text(root.querySelector('[data-cart-item-count]'), String(count));
        text(root.querySelector('[data-cart-total-amount]'), formatted);
        // Always refresh goods node text — do not wait for discount lines to appear.
        text(root.querySelector('[data-cart-goods-subtotal]'), goodsFormatted);
        renderDiscountBreakdown(root, summary, currency);
        renderItems(root, items.slice(0, 20), currency);
    }

    function drawerElements(root) {
        return {
            drawer: root.querySelector('[data-mini-cart-drawer]'),
            overlay: root.querySelector('[data-mini-cart-overlay]'),
            trigger: root.querySelector('[data-mini-cart-trigger]'),
        };
    }

    function setDrawerOpen(root, open) {
        if (open) {
            ensureDrawerCss();
        }
        var els = drawerElements(root);
        root.classList.toggle(openClass, open);
        if (els.drawer) {
            els.drawer.classList.toggle('is-open', open);
            els.drawer.setAttribute('aria-hidden', open ? 'false' : 'true');
        }
        if (els.overlay) {
            els.overlay.hidden = !open;
        }
        if (els.trigger) {
            els.trigger.setAttribute('aria-expanded', open ? 'true' : 'false');
        }
        document.body.style.overflow = open ? 'hidden' : '';
        var eventName = open ? 'weshop:mini-cart:open' : 'weshop:mini-cart:close';
        window.dispatchEvent(new CustomEvent(eventName));
        if (open) {
            loadDrawer(root, { forceNetwork: false });
            return;
        }
        resetDrawerBusy(root);
    }

    function isDrawerOpen(root) {
        return root.classList.contains(openClass);
    }

    function bindDrawer(root) {
        var els = drawerElements(root);
        if (!els.drawer || !els.trigger) {
            return;
        }

        els.trigger.addEventListener('click', function (event) {
            event.preventDefault();
            setDrawerOpen(root, !isDrawerOpen(root));
        });

        root.querySelectorAll('[data-mini-cart-close]').forEach(function (button) {
            button.addEventListener('click', function () {
                setDrawerOpen(root, false);
            });
        });

        if (els.overlay) {
            els.overlay.addEventListener('click', function () {
                setDrawerOpen(root, false);
            });
        }

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && isDrawerOpen(root)) {
                setDrawerOpen(root, false);
            }
        });
    }

    function isDemoChromeOnly(root) {
        // Dedicated mini-cart layout canvas only — theme editor / homepage chrome stays live.
        if (!root) {
            return false;
        }
        if (root.getAttribute('data-demo-chrome') === '1') {
            return true;
        }
        try {
            return document.body.classList.contains('mini-cart-layout-preview');
        } catch (e) {
            return false;
        }
    }

    function canMutate(root) {
        return !isDemoChromeOnly(root) && !root.classList.contains('is-demo');
    }

    function withTimeout(promise, ms, label) {
        var timeoutMs = Math.max(1000, Number(ms) || 8000);
        return new Promise(function (resolve, reject) {
            var settled = false;
            var timer = window.setTimeout(function () {
                if (settled) {
                    return;
                }
                settled = true;
                reject(new Error(label || ('timeout after ' + timeoutMs + 'ms')));
            }, timeoutMs);
            Promise.resolve(promise).then(function (value) {
                if (settled) {
                    return;
                }
                settled = true;
                window.clearTimeout(timer);
                resolve(value);
            }, function (err) {
                if (settled) {
                    return;
                }
                settled = true;
                window.clearTimeout(timer);
                reject(err);
            });
        });
    }

    async function syncCartState(root, options) {
        if (!root || isDemoChromeOnly(root)) {
            return;
        }
        options = options || {};
        if (!options.forceNetwork
            && window.WelineCart && typeof window.WelineCart.needsOriginRefresh === 'function'
            && window.WelineCart.needsOriginRefresh()) {
            options.forceNetwork = true;
        }
        var mode = preferredCartType();
        // Empty typed cache cannot short-circuit: sibling may claim the other cart still has lines.
        if (!options.forceNetwork) {
            var cached = normalizeSummary(readSummaryCache(mode));
            if (cached && cached.success !== false
                && normalizeCartType(cached.cart_type || cached.selling_mode) === mode
                && summaryCacheHasLineItems(cached)) {
                applySummary(root, cached);
                return;
            }
        }
        var task = async function () {
            var api = await withTimeout(waitForCartApi(), 8000, 'cart api wait timeout');
            var token = guestToken();
            var result;
            var baseParams = cartQueryParams(token ? { guest_token: token } : {});
            if (typeof api.getCart === 'function') {
                try {
                    result = await withTimeout(
                        api.getCart(baseParams, { silent: true }),
                        8000,
                        'getCart timeout'
                    );
                } catch (getCartErr) {
                    var msg = String((getCartErr && getCartErr.message) || '');
                    if (baseParams.coupon_code && /coupon_code|Unknown frontend worker param/i.test(msg)) {
                        var retryParams = Object.assign({}, baseParams);
                        delete retryParams.coupon_code;
                        result = await withTimeout(
                            api.getCart(retryParams, { silent: true }),
                            8000,
                            'getCart retry timeout'
                        );
                    } else {
                        throw getCartErr;
                    }
                }
            } else if (typeof api.summary === 'function') {
                result = await withTimeout(api.summary(cartQueryParams({}), { silent: true }), 8000, 'summary timeout');
            } else if (typeof api.count === 'function') {
                result = await withTimeout(api.count({}, { silent: true }), 8000, 'count timeout');
            } else {
                return;
            }
            var payload = extractCartPayload(result);
            if (!payload || payload.success === false) {
                // Non-default cart_type (or gate): show empty for preferred type; B2B may set data-empty-message.
                if (isCartTypeGateError(payload) || (mode && mode !== 'toc')) {
                    applySummary(root, emptySummaryForType(mode, {
                        gate_reason: gateReasonFromPayload(payload),
                        message: payload && payload.message ? payload.message : '',
                    }));
                }
                if (options.forceNetwork && window.WelineCart
                    && typeof window.WelineCart.consumeNeedsOriginRefresh === 'function') {
                    window.WelineCart.consumeNeedsOriginRefresh();
                }
                return;
            }
            var normalized = normalizeSummary(payload) || payload;
            normalized.cart_type = normalizeCartType(normalized.cart_type || normalized.selling_mode || mode);
            normalized.selling_mode = normalized.cart_type;
            if ((!normalized.discount_preview || Number(normalized.discount_preview.amount_minor || 0) <= 0)
                && pendingDiscountPreview) {
                normalized = mergeDiscountPreviewIntoSummary(normalized, pendingDiscountPreview, false);
            } else if (normalized.discount_preview && Number(normalized.discount_preview.amount_minor || 0) > 0) {
                pendingDiscountPreview = normalized.discount_preview;
                if (normalized.discount_preview.coupon_code) {
                    pendingCouponCode = String(normalized.discount_preview.coupon_code).trim().toUpperCase();
                }
            }
            applySummary(root, normalized);
            rememberSummaryCache(normalized);
            if (options.forceNetwork && window.WelineCart
                && typeof window.WelineCart.consumeNeedsOriginRefresh === 'function') {
                window.WelineCart.consumeNeedsOriginRefresh();
            }
            var count = Number(normalized.cart_count || normalized.item_count || 0);
            if (count > 0 && window.WelineCart && typeof window.WelineCart.markCartActive === 'function') {
                window.WelineCart.markCartActive();
            }
        };
        if (options.drawerBusy && isDrawerOpen(root)) {
            return runWithDrawerBusy(root, task);
        }
        try {
            return await task();
        } catch (e) {
            // Keep server-rendered badge when cart API is unavailable.
        }
    }

    async function mutateLine(root, line, itemId, qty, remove) {
        if (!canMutate(root) || !itemId || !line || isDrawerBusy(root)) {
            return;
        }
        return runWithDrawerBusy(root, async function () {
            var api = await waitForCartApi();
            var token = guestToken();
            var params = cartQueryParams({ item_id: itemId });
            if (token) {
                params.guest_token = token;
            }
            var requestOptions = { silent: true };
            var result;
            if (remove) {
                if (typeof api.remove === 'function') {
                    result = await api.remove(params, requestOptions);
                } else {
                    return;
                }
            } else if (typeof api.update === 'function') {
                result = await api.update(Object.assign({}, params, { qty: qty }), requestOptions);
            } else {
                return;
            }
            var payload = extractCartPayload(result);
            if (!payload || payload.success === false) {
                return;
            }
            var summary = normalizeSummary(payload) || payload;
            summary.cart_type = normalizeCartType(summary.cart_type || summary.selling_mode || preferredCartType());
            summary.selling_mode = summary.cart_type;
            applySummary(root, summary);
            rememberSummaryCache(summary);
            window.dispatchEvent(new CustomEvent('weline:cart-updated', {
                detail: summary,
            }));
            // On checkout, close the drawer so the refreshed order summary is visible
            // and the two surfaces do not appear out of sync.
            if (isCheckoutPath()) {
                setDrawerOpen(root, false);
            }
        });
    }

    function isCheckoutPath() {
        try {
            return /\/checkout\/?$/.test(window.location.pathname || '');
        } catch (e) {
            return false;
        }
    }

    function isCheckoutHref(href) {
        var value = String(href || '').trim();
        if (!value || value === '#') {
            return false;
        }
        try {
            return /\/checkout\/?$/.test(new URL(value, window.location.origin).pathname);
        } catch (e) {
            return /\/checkout\/?(?:$|[?#])/.test(value.split('#')[0]);
        }
    }

    function findMiniCartCheckoutLink(target) {
        if (!(target instanceof Element)) {
            return null;
        }
        var explicit = target.closest('[data-mini-cart-checkout]');
        if (explicit) {
            return explicit;
        }
        var primary = target.closest('.mini-cart-drawer__actions .mini-cart-drawer__btn--primary, [data-mini-cart-footer] .mini-cart-drawer__btn--primary');
        if (!primary) {
            return null;
        }
        return isCheckoutHref(primary.getAttribute('href')) ? primary : null;
    }

    function setCheckoutButtonLoading(button, root, loading) {
        if (!button) {
            return;
        }
        var defaultLabel = attr(root, 'data-i18n-checkout', '去结算');
        var loadingLabel = attr(root, 'data-i18n-checkout-loading', attr(root, 'data-i18n-loading', '正在加载...'));
        button.classList.toggle('is-loading', loading);
        button.setAttribute('aria-busy', loading ? 'true' : 'false');
        if (loading) {
            if (!button.dataset.checkoutDefaultLabel) {
                button.dataset.checkoutDefaultLabel = String(button.textContent || '').trim() || defaultLabel;
            }
            button.textContent = loadingLabel;
            return;
        }
        if (button.dataset.checkoutDefaultLabel) {
            button.textContent = button.dataset.checkoutDefaultLabel;
        }
    }

    var globalNavigationBound = false;
    var checkoutNavigationPending = false;

    function clearCheckoutNavigationState(root) {
        if (!root) {
            return;
        }
        resetDrawerBusy(root);
        root.querySelectorAll('[data-mini-cart-checkout], .mini-cart-drawer__actions .mini-cart-drawer__btn--primary, [data-mini-cart-footer] .mini-cart-drawer__btn--primary').forEach(function (button) {
            if (!(button instanceof Element)) {
                return;
            }
            if (!button.classList.contains('is-loading') && button.getAttribute('aria-busy') !== 'true') {
                return;
            }
            setCheckoutButtonLoading(button, root, false);
        });
    }

    function clearAllCheckoutNavigationState() {
        checkoutNavigationPending = false;
        document.querySelectorAll('[data-w-mini-cart="1"]').forEach(clearCheckoutNavigationState);
    }

    function hasCheckoutNavigationLoading() {
        return checkoutNavigationPending
            || !!document.querySelector('[data-w-mini-cart="1"] .mini-cart-drawer__btn.is-loading, [data-w-mini-cart="1"] [data-mini-cart-checkout][aria-busy="true"]');
    }

    function beginCheckoutNavigation(root, checkoutLink, href) {
        checkoutNavigationPending = true;
        beginDrawerBusy(root);
        setCheckoutButtonLoading(checkoutLink, root, true);
        var overlay = root.querySelector('[data-mini-cart-busy-overlay]');
        if (overlay) {
            var label = overlay.querySelector('.mini-cart-drawer__busy-label');
            if (label) {
                label.textContent = attr(root, 'data-i18n-checkout-loading', attr(root, 'data-i18n-loading', '正在加载...'));
            }
        }
        window.location.assign(href);
    }

    function bindCheckoutNavigationLifecycle() {
        // Back/forward bfcache restores the pre-navigation DOM (spinner still on).
        window.addEventListener('pageshow', function (event) {
            if (event.persisted || hasCheckoutNavigationLoading()) {
                clearAllCheckoutNavigationState();
            }
        });
        window.addEventListener('focus', function () {
            if (hasCheckoutNavigationLoading()) {
                clearAllCheckoutNavigationState();
            }
        });
    }

    function bindGlobalNavigationActions() {
        if (globalNavigationBound) {
            return;
        }
        globalNavigationBound = true;
        bindCheckoutNavigationLifecycle();
        document.addEventListener('click', function (event) {
            var target = event.target;
            if (!(target instanceof Element)) {
                return;
            }
            var checkoutLink = findMiniCartCheckoutLink(target);
            if (!checkoutLink) {
                return;
            }
            var root = checkoutLink.closest('[data-w-mini-cart="1"]');
            if (!root) {
                return;
            }
            if (isDrawerBusy(root)) {
                event.preventDefault();
                return;
            }
            if (isDemoChromeOnly(root) || root.classList.contains('is-demo')) {
                return;
            }
            if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
                return;
            }
            var href = String(checkoutLink.getAttribute('href') || '').trim();
            if (!isCheckoutHref(href)) {
                event.preventDefault();
                return;
            }
            event.preventDefault();
            beginCheckoutNavigation(root, checkoutLink, href);
        });
    }

    function ensureMiniCartSwatchPreview(root) {
        var dialog = root.querySelector('[data-mini-cart-swatch-preview]');
        if (dialog) {
            return dialog;
        }
        dialog = document.createElement('dialog');
        dialog.className = 'mini-cart-drawer__swatch-preview';
        dialog.setAttribute('data-mini-cart-swatch-preview', '1');
        var panel = document.createElement('div');
        panel.className = 'mini-cart-drawer__swatch-preview-panel';
        var img = document.createElement('img');
        img.className = 'mini-cart-drawer__swatch-preview-img';
        img.setAttribute('data-mini-cart-swatch-preview-img', '1');
        img.alt = '';
        var form = document.createElement('form');
        form.method = 'dialog';
        var closeBtn = document.createElement('button');
        closeBtn.type = 'submit';
        closeBtn.className = 'mini-cart-drawer__swatch-preview-close';
        closeBtn.textContent = attr(root, 'data-i18n-swatch-preview-close', '关闭');
        form.appendChild(closeBtn);
        panel.appendChild(img);
        panel.appendChild(form);
        dialog.appendChild(panel);
        dialog.addEventListener('click', function (event) {
            if (event.target === dialog && typeof dialog.close === 'function') {
                dialog.close();
            }
        });
        root.appendChild(dialog);
        return dialog;
    }

    function openMiniCartSwatchPreview(root, trigger) {
        var src = String(trigger.getAttribute('data-mini-cart-swatch-src') || '').trim();
        if (!src) {
            return;
        }
        var dialog = ensureMiniCartSwatchPreview(root);
        var img = dialog.querySelector('[data-mini-cart-swatch-preview-img]');
        if (!img) {
            return;
        }
        img.setAttribute('src', src);
        img.setAttribute('alt', attr(root, 'data-i18n-swatch-preview', '查看规格图'));
        if (typeof dialog.showModal === 'function') {
            dialog.showModal();
        } else {
            dialog.setAttribute('open', '');
        }
    }

    function bindLineActions(root) {
        root.addEventListener('click', function (event) {
            var target = event.target;
            if (!(target instanceof Element)) {
                return;
            }
            var swatchTrigger = target.closest('[data-mini-cart-swatch-trigger]');
            if (swatchTrigger && root.contains(swatchTrigger)) {
                event.preventDefault();
                openMiniCartSwatchPreview(root, swatchTrigger);
                return;
            }
            if (isDrawerBusy(root)) {
                event.preventDefault();
                return;
            }
            var line = target.closest('[data-mini-cart-line]');
            if (!line || !root.contains(line)) {
                return;
            }
            var itemId = String(line.getAttribute('data-item-id') || '').trim();
            var input = line.querySelector('[data-qty-input]');
            var currentQty = Math.max(1, Number(input && input.value || 1));

            if (target.closest('[data-remove-item]')) {
                event.preventDefault();
                mutateLine(root, line, itemId, 0, true);
                return;
            }
            if (target.closest('[data-qty-decrease]')) {
                event.preventDefault();
                var nextQty = Math.max(1, currentQty - 1);
                if (nextQty === currentQty) {
                    return;
                }
                mutateLine(root, line, itemId, nextQty, false);
                return;
            }
            if (target.closest('[data-qty-increase]')) {
                event.preventDefault();
                mutateLine(root, line, itemId, Math.min(999, currentQty + 1), false);
            }
        });

        root.addEventListener('change', function (event) {
            if (isDrawerBusy(root)) {
                return;
            }
            var target = event.target;
            if (!(target instanceof HTMLInputElement) || !target.matches('[data-qty-input]')) {
                return;
            }
            var line = target.closest('[data-mini-cart-line]');
            if (!line || !root.contains(line)) {
                return;
            }
            var itemId = String(line.getAttribute('data-item-id') || '').trim();
            var qty = Math.max(1, Math.min(999, Number(target.value || 1)));
            target.value = String(qty);
            mutateLine(root, line, itemId, qty, false);
        });
    }

    async function loadDrawer(root, options) {
        if (!root || isDemoChromeOnly(root)) {
            return;
        }
        options = options || {};
        if (!options.forceNetwork
            && window.WelineCart && typeof window.WelineCart.needsOriginRefresh === 'function'
            && window.WelineCart.needsOriginRefresh()) {
            options.forceNetwork = true;
        }
        var mode = preferredCartType();
        // Empty typed cache cannot short-circuit: sibling may claim the other cart still has lines.
        if (!options.forceNetwork) {
            var cached = normalizeSummary(readSummaryCache(mode));
            if (cached && cached.success !== false
                && normalizeCartType(cached.cart_type || cached.selling_mode) === mode
                && summaryCacheHasLineItems(cached)) {
                applySummary(root, cached);
                return;
            }
        }
        return runWithDrawerBusy(root, async function () {
            try {
                var api = await withTimeout(waitForCartApi(), 8000, 'cart api wait timeout');
                var token = guestToken();
                var result;
                var baseParams = cartQueryParams(token ? { guest_token: token } : {});
                if (typeof api.getCart === 'function') {
                    try {
                        result = await withTimeout(
                            api.getCart(baseParams, { silent: true }),
                            8000,
                            'getCart timeout'
                        );
                    } catch (getCartErr) {
                        var msg = String((getCartErr && getCartErr.message) || '');
                        if (baseParams.coupon_code && /coupon_code|Unknown frontend worker param/i.test(msg)) {
                            var retryParams = Object.assign({}, baseParams);
                            delete retryParams.coupon_code;
                            result = await withTimeout(
                                api.getCart(retryParams, { silent: true }),
                                8000,
                                'getCart retry timeout'
                            );
                        } else {
                            throw getCartErr;
                        }
                    }
                } else if (typeof api.miniItems === 'function') {
                    result = await withTimeout(api.miniItems({ limit: 20 }, { silent: true }), 8000, 'miniItems timeout');
                } else if (typeof api.summary === 'function') {
                    result = await withTimeout(api.summary(cartQueryParams({}), { silent: true }), 8000, 'summary timeout');
                } else {
                    applySummary(root, emptySummaryForType(mode));
                    return;
                }
                var payload = extractCartPayload(result);
                if (!payload || payload.success === false) {
                    applySummary(root, emptySummaryForType(mode, {
                        gate_reason: gateReasonFromPayload(payload),
                        message: payload && payload.message ? payload.message : '',
                    }));
                    if (options.forceNetwork && window.WelineCart
                        && typeof window.WelineCart.consumeNeedsOriginRefresh === 'function') {
                        window.WelineCart.consumeNeedsOriginRefresh();
                    }
                    return;
                }
                var normalized = normalizeSummary(payload) || payload;
                normalized.cart_type = normalizeCartType(normalized.cart_type || normalized.selling_mode || mode);
                normalized.selling_mode = normalized.cart_type;
                if ((!normalized.discount_preview || Number(normalized.discount_preview.amount_minor || 0) <= 0)
                    && pendingDiscountPreview) {
                    normalized = mergeDiscountPreviewIntoSummary(normalized, pendingDiscountPreview, false);
                } else if (normalized.discount_preview && Number(normalized.discount_preview.amount_minor || 0) > 0) {
                    pendingDiscountPreview = normalized.discount_preview;
                    if (normalized.discount_preview.coupon_code) {
                        pendingCouponCode = String(normalized.discount_preview.coupon_code).trim().toUpperCase();
                    }
                }
                applySummary(root, normalized);
                rememberSummaryCache(normalized);
                if (options.forceNetwork && window.WelineCart
                    && typeof window.WelineCart.consumeNeedsOriginRefresh === 'function') {
                    window.WelineCart.consumeNeedsOriginRefresh();
                }
            } catch (e) {
                applySummary(root, emptySummaryForType(mode));
            }
        });
    }

    async function refreshBadge(root) {
        return syncCartState(root, { drawerBusy: isDrawerOpen(root) });
    }

    function scheduleCartSync(root) {
        var forceNetwork = !!(window.WelineCart
            && typeof window.WelineCart.needsOriginRefresh === 'function'
            && window.WelineCart.needsOriginRefresh());
        syncCartState(root, forceNetwork ? { forceNetwork: true } : {}).catch(function () {
            // Keep server-rendered badge when cart API is unavailable.
        });
    }

    function scheduleCartRefreshFromEvent(summary) {
        if (cartRefreshTimer) {
            clearTimeout(cartRefreshTimer);
        }
        cartRefreshTimer = window.setTimeout(function () {
            cartRefreshTimer = null;
            // Coupon/Marketing may emit { refresh: true } without a cart payload.
            // Never short-circuit on stale summary_cache — that hides discount_preview lines.
            var forceRefresh = !!(summary && typeof summary === 'object'
                && (summary.refresh === true || summary.forceNetwork === true));
            if (summary && typeof summary === 'object') {
                if (summary.clear_discount === true) {
                    pendingCouponCode = '';
                    pendingDiscountPreview = null;
                } else {
                    var code = String(summary.coupon_code || '').trim().toUpperCase();
                    if (code) {
                        pendingCouponCode = code;
                    }
                    var preview = resolveEventDiscountPreview(summary);
                    if (preview) {
                        pendingDiscountPreview = preview;
                    }
                }
            }
            var normalized = normalizeSummary(summary);
            var hasCartPayload = !!(normalized
                && normalized.success !== false
                && (normalized.cart_count != null || Array.isArray(normalized.items)));
            var roots = document.querySelectorAll('[data-w-mini-cart="1"]');
            // Optimistic: paint chip quote onto current summary before network returns.
            if (forceRefresh && (pendingDiscountPreview || summary && summary.clear_discount === true)) {
                roots.forEach(function (root) {
                    var base = root.__welineLastSummary
                        || normalizeSummary(readSummaryCache(preferredCartType()))
                        || null;
                    if (!base) {
                        return;
                    }
                    applySummary(root, mergeDiscountPreviewIntoSummary(
                        base,
                        pendingDiscountPreview,
                        !!(summary && summary.clear_discount === true)
                    ));
                });
            }
            roots.forEach(function (root) {
                if (!forceRefresh && hasCartPayload) {
                    applySummary(root, normalized);
                    rememberSummaryCache(normalized);
                }
            });
            if (!forceRefresh && hasCartPayload) {
                rememberSummaryCache(normalized);
                return;
            }
            if (!forceRefresh && applyCachedSummaryToRoots()) {
                return;
            }
            roots.forEach(function (root) {
                if (isDrawerOpen(root)) {
                    loadDrawer(root, { forceNetwork: true }).catch(function () {
                        // Keep last rendered drawer when refresh fails.
                    });
                    return;
                }
                syncCartState(root, { forceNetwork: true }).catch(function () {
                    // Keep server-rendered badge when cart API is unavailable.
                });
            });
        }, 60);
    }

    window.Weline = window.Weline || {};
    window.Weline.MiniCart = window.Weline.MiniCart || {};
    window.Weline.MiniCart.beginBusy = function () {
        applyMiniCartBusyDelta(1);
    };
    window.Weline.MiniCart.endBusy = function () {
        applyMiniCartBusyDelta(-1);
    };
    window.Weline.MiniCart.resetBusy = resetAllDrawerBusy;
    window.Weline.MiniCart.applyCachedSummary = applyCachedSummaryToRoots;
    window.Weline.MiniCart.open = function () {
        document.querySelectorAll('[data-w-mini-cart="1"]').forEach(function (root) {
            if (isDemoChromeOnly(root)) {
                return;
            }
            setDrawerOpen(root, true);
        });
    };
    window.Weline.MiniCart.close = function () {
        document.querySelectorAll('[data-w-mini-cart="1"]').forEach(function (root) {
            setDrawerOpen(root, false);
        });
    };
    window.Weline.MiniCart.refresh = function (opts) {
        opts = opts || {};
        // Prefer typed local summary_cache; only forceNetwork when caller opts in (mutations / miss).
        var forceNetwork = opts.forceNetwork === true;
        document.querySelectorAll('[data-w-mini-cart="1"]').forEach(function (root) {
            if (isDemoChromeOnly(root)) {
                return;
            }
            syncCartState(root, { forceNetwork: forceNetwork, drawerBusy: isDrawerOpen(root) }).then(function () {
                if (isDrawerOpen(root)) {
                    return loadDrawer(root, { forceNetwork: forceNetwork });
                }
                return null;
            }).catch(function () {});
        });
    };

    function ensureDrawerCss() {
        var assetVersion = '';
        try {
            var cfgNode = document.getElementById('weline-frontend-runtime-config');
            if (cfgNode && cfgNode.textContent) {
                var cfg = JSON.parse(cfgNode.textContent);
                assetVersion = String((cfg && cfg.assetVersion) || '');
            }
        } catch (err) {
            assetVersion = '';
        }
        var cssStamp = '20260908-theme-minicart-generic';
        var href = '/Weline/Theme/view/statics/css/widgets/mini-cart-drawer.css?v=' + cssStamp;
        if (assetVersion) {
            href += '&_weline_dev=' + encodeURIComponent(assetVersion);
        }
        // Drop stale drawer sheets (old &v=) so the current Theme generic sheet wins.
        document.querySelectorAll('link[rel="stylesheet"][href*="mini-cart-drawer.css"]').forEach(function (node) {
            var current = String(node.getAttribute('href') || '');
            if (current.indexOf(cssStamp) === -1) {
                if (node.parentNode) {
                    node.parentNode.removeChild(node);
                }
            }
        });
        if (document.querySelector('link[data-weline-mini-cart-drawer-live="1"], link[href*="' + cssStamp + '"]')) {
            return;
        }
        var link = document.createElement('link');
        link.rel = 'stylesheet';
        link.href = href;
        link.setAttribute('data-weline-mini-cart-drawer-live', '1');
        document.head.appendChild(link);
    }

    /**
     * @param {{paintCache?: boolean}} options
     * paintCache=true（默认）：首启/事件路径可刷缓存摘要。
     * paintCache=false：MutationObserver 仅发现新根并绑定；禁止重绘摘要。
     * 否则 applySummary 的 childList 变更会再次触发 observer → 同步死循环卡死页面。
     */
    function bootMiniCartRoots(options) {
        options = options || {};
        var paintCache = options.paintCache !== false;
        if (paintCache) {
            applyCachedSummaryToRoots();
        }
        document.querySelectorAll('[data-w-mini-cart="1"]').forEach(function (root) {
            if (root.getAttribute('data-w-mini-cart-init') === '1') {
                return;
            }
            root.setAttribute('data-w-mini-cart-init', '1');
            bindDrawer(root);
            bindLineActions(root);
            if (paintCache) {
                // Soft paint may hydrate, but always revalidate against Cookie/DB authority.
                scheduleCartSync(root);
                return;
            }
            // Observer 路径：新根只做一次单根缓存画，避免全树重绘风暴。
            var cached = normalizeSummary(readSummaryCache());
            if (cached && cached.success !== false
                && (cached.cart_count != null || Array.isArray(cached.items))
                && !isDemoChromeOnly(root)) {
                if (window.Weline && window.Weline.MiniCart) {
                    window.Weline.MiniCart.__painting = true;
                }
                try {
                    applySummary(root, cached);
                } finally {
                    if (window.Weline && window.Weline.MiniCart) {
                        window.Weline.MiniCart.__painting = false;
                    }
                }
            } else {
                scheduleCartSync(root);
            }
        });
    }

    function observeMiniCartRoots() {
        if (window.Weline.MiniCart.__observer || typeof MutationObserver !== 'function') {
            return;
        }
        var queued = false;
        var observer = new MutationObserver(function () {
            if (window.Weline.MiniCart.__painting) {
                return;
            }
            if (queued) {
                return;
            }
            queued = true;
            Promise.resolve().then(function () {
                queued = false;
                if (window.Weline.MiniCart.__painting) {
                    return;
                }
                // 只发现新插入的迷你购物车根；禁止在此重绘（防 childList 反馈死循环）。
                bootMiniCartRoots({ paintCache: false });
            });
        });
        observer.observe(document.documentElement, { childList: true, subtree: true });
        window.Weline.MiniCart.__observer = observer;
    }

    function boot() {
        if (window.Weline.MiniCart.__booted) {
            bootMiniCartRoots({ paintCache: true });
            return;
        }
        window.Weline.MiniCart.__booted = true;
        clearAllCheckoutNavigationState();
        bootMiniCartRoots({ paintCache: true });
        bindGlobalNavigationActions();
        observeMiniCartRoots();

        window.addEventListener('weline:api:auto-enabled', function () {
            document.querySelectorAll('[data-w-mini-cart="1"]').forEach(function (root) {
                if (!applyCachedSummaryToRoots()) {
                    scheduleCartSync(root);
                }
            });
        });

        window.addEventListener('storage', function (event) {
            if (!event || String(event.key || '').indexOf('weline.cart.summary_cache') !== 0) {
                return;
            }
            applyCachedSummaryToRoots();
        });

        window.addEventListener('weline:cart-updated', function (event) {
            var summary = event && event.detail && typeof event.detail === 'object' ? event.detail : null;
            scheduleCartRefreshFromEvent(summary);
        });

        window.addEventListener('weline:cart-type-changed', function (event) {
            var detail = event && event.detail && typeof event.detail === 'object' ? event.detail : {};
            var mode = normalizeCartType(detail.cart_type || detail.selling_mode || preferredCartType());
            var forceNetwork = detail.forceNetwork === true;
            document.querySelectorAll('[data-w-mini-cart="1"]').forEach(function (root) {
                if (isDemoChromeOnly(root)) {
                    return;
                }
                applyCartTypeAttr(root, { cart_type: mode });
                syncCartState(root, { forceNetwork: forceNetwork, drawerBusy: isDrawerOpen(root) }).then(function () {
                    if (isDrawerOpen(root)) {
                        return loadDrawer(root, { forceNetwork: forceNetwork });
                    }
                    return null;
                }).catch(function () {});
            });
        });

        // Legacy B2B event: ignore echoes already mirrored as cart-type-changed.
        window.addEventListener('weline:selling-mode-changed', function (event) {
            var detail = event && event.detail && typeof event.detail === 'object' ? event.detail : {};
            if (detail.source === 'b2b-setMode') {
                return;
            }
            var mode = normalizeCartType(detail.selling_mode || detail.cart_type || preferredCartType());
            var forceNetwork = detail.forceNetwork === true;
            document.querySelectorAll('[data-w-mini-cart="1"]').forEach(function (root) {
                if (isDemoChromeOnly(root)) {
                    return;
                }
                applyCartTypeAttr(root, { cart_type: mode });
                syncCartState(root, { forceNetwork: forceNetwork, drawerBusy: isDrawerOpen(root) }).then(function () {
                    if (isDrawerOpen(root)) {
                        return loadDrawer(root, { forceNetwork: forceNetwork });
                    }
                    return null;
                }).catch(function () {});
            });
        });

        window.addEventListener('weshop:mini-cart:open-request', function () {
            window.Weline.MiniCart.open();
        });

        window.addEventListener('weshop:mini-cart:busy', function (event) {
            var detail = event && event.detail && typeof event.detail === 'object' ? event.detail : {};
            if (detail.reset === true) {
                resetAllDrawerBusy();
                return;
            }
            if (detail.busy === true) {
                document.querySelectorAll('[data-w-mini-cart="1"]').forEach(function (root) {
                    if (isDrawerOpen(root)) {
                        beginDrawerBusy(root);
                    }
                });
                return;
            }
            if (detail.busy === false) {
                resetAllDrawerBusy();
                return;
            }
            if (detail.delta) {
                applyMiniCartBusyDelta(detail.delta);
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }

    window.WelineMiniCartIcon = {
        boot: boot
    };
})();
