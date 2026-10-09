(function () {
    'use strict';

    var guestTokenStorageKey = 'weline.cart.guest_token';
    var openClass = 'is-drawer-open';
    var cartRefreshTimer = null;
    // One-shot CSS ensure — never rip baked mini-cart-drawer.css on every open (FOUC).
    var drawerCssReady = false;
    // Ignore backdrop closes briefly after line mutate — DOM refresh / busy pointer-events
    // can retarget the same gesture onto the full-screen overlay and collapse the drawer.
    var suppressBackdropCloseUntil = 0;

    function noteDrawerLineInteraction() {
        suppressBackdropCloseUntil = Date.now() + 700;
    }

    function shouldSuppressBackdropClose(root) {
        return isDrawerBusy(root) || Date.now() < suppressBackdropCloseUntil;
    }

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
     * Opaque cart_type from this page's cart/checkout view (URL / handoff).
     * Wins over leftover selling-mode cookie so ?cart_type=toc handoff keeps mini-cart on toc.
     */
    function cartTypeFromPageView() {
        try {
            var page = document.querySelector('[data-weline-cart], [data-weline-checkout]');
            if (page) {
                var handoff = String(page.getAttribute('data-cart-type-handoff') || '').trim().toLowerCase();
                if (handoff) {
                    return handoff;
                }
                var painted = String(page.getAttribute('data-cart-type') || '').trim().toLowerCase();
                if (painted) {
                    return painted;
                }
            }
            var path = String(window.location.pathname || '');
            if (path.indexOf('/cart') === -1 && path.indexOf('/checkout') === -1) {
                return '';
            }
            var params = new URLSearchParams(window.location.search || '');
            var query = String(
                params.get('cart_type') || params.get('type') || params.get('selling_mode') || ''
            ).trim().toLowerCase();
            if (query) {
                return query;
            }
            if (window.WelineB2BSellingMode && typeof window.WelineB2BSellingMode.pageViewCartType === 'function') {
                var fromPage = String(window.WelineB2BSellingMode.pageViewCartType() || '').trim().toLowerCase();
                if (fromPage) {
                    return fromPage;
                }
            }
        } catch (ePage) {}
        return '';
    }

    /**
     * Preferred cart_type: page-view handoff first, then optional providers, then summary, else toc.
     * Does not hardcode B2B dual-cart UI labels; Theme stays retail-only without those helpers.
     */
    function preferredCartType(summary) {
        var fromView = cartTypeFromPageView();
        if (fromView) {
            return fromView;
        }
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

    function promptEnsureLogin() {
        try {
            // Trust browser signed-in snapshot; do not force account.current on every cart click.
            if (window.Weline && window.Weline.Account && typeof window.Weline.Account.ensureLogin === 'function') {
                return Promise.resolve(window.Weline.Account.ensureLogin()).catch(function () {
                    return null;
                });
            }
            if (window.WelineAccountModule && typeof window.WelineAccountModule.ensureLogin === 'function') {
                return Promise.resolve(window.WelineAccountModule.ensureLogin()).catch(function () {
                    return null;
                });
            }
        } catch (_e) { /* ignore */ }
        return Promise.resolve(null);
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
                applySummary(root, cached, { skipItems: drawerContentIsFresh(root, cached) });
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
        // Same symbol-first path as cart + storefront-money-summary.
        var api = window.WelineStorefrontMoneySummary;
        if (api && typeof api.formatMoney === 'function') {
            return api.formatMoney(amount, currency, 'symbol');
        }
        var symbol = '¥';
        currency = String(currency || 'CNY').toUpperCase();
        if (currency === 'USD') symbol = '$';
        else if (currency === 'EUR') symbol = '€';
        else if (currency === 'GBP') symbol = '£';
        var n = Number(amount || 0);
        return symbol + n.toFixed(2);
    }

    function resolveFreeShippingProgress(summary) {
        var fromApi = summary && summary.free_shipping_progress;
        // 仅展示后台实际启用的免邮规则，不在前端自行推算活动。
        return fromApi && typeof fromApi === 'object' && fromApi.enabled === true
            ? fromApi
            : null;
    }

    function renderFreeShippingProgress(root, summary) {
        var box = root.querySelector('[data-mini-cart-fs-progress]');
        if (!box) {
            return;
        }
        var count = Number((summary && (summary.cart_count || summary.item_count)) || 0);
        if (!summary || summary.is_empty || count <= 0) {
            box.hidden = true;
            box.classList.remove('is-qualified');
            return;
        }
        var progress = resolveFreeShippingProgress(summary, root);
        if (!progress || progress.enabled === false) {
            box.hidden = true;
            return;
        }
        box.hidden = false;
        box.classList.toggle('is-qualified', !!progress.qualified);
        var bar = box.querySelector('[data-mini-cart-fs-bar]');
        if (bar) {
            var pct = Math.max(0, Math.min(100, Number(progress.progress_percent || 0)));
            bar.style.width = pct + '%';
        }
        var textEl = box.querySelector('[data-mini-cart-fs-text]');
        if (textEl) {
            textEl.textContent = progress.qualified
                ? String(progress.message_qualified || progress.message || attr(root, 'data-i18n-fs-qualified', '已享包邮'))
                : String(progress.message || '');
        }
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

    /** True when a cart line url is the old /cart fallback — never treat as PDP. */
    function isCartChromeFallbackUrl(value) {
        var raw = String(value || '').trim();
        if (!raw) {
            return true;
        }
        var path = raw;
        try {
            if (/^https?:\/\//i.test(raw)) {
                path = new URL(raw, window.location.origin).pathname || '';
            }
        } catch (e) {
            path = raw;
        }
        path = path.split('?')[0].split('#')[0].replace(/\/+$/, '') || '/';
        return path === '/cart' || path === 'cart';
    }

    /**
     * PDP path for mini-cart title. Prefer item.url, else /product/{slug|id}.
     * Never fall back to /cart — that sent shoppers to the cart page by mistake.
     */
    function resolveLineProductUrl(item) {
        if (!item || typeof item !== 'object') {
            return '';
        }
        var raw = String(item.url || '').trim();
        if (raw && !isCartChromeFallbackUrl(raw)) {
            return raw;
        }
        var slug = String(item.slug || '').trim().toLowerCase();
        if (slug) {
            return '/product/' + encodeURIComponent(slug);
        }
        var productId = Number(item.product_id || item.legacy_product_id || 0);
        if (productId > 0) {
            return '/product/' + String(productId);
        }
        return '';
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
        var url = resolveLineProductUrl(item);
        var name = String(item.name || '');
        var qty = Math.max(1, Number(item.qty || item.quantity || 1));

        // Thumbnail zooms in-drawer (reuse swatch preview); must not navigate to /cart.
        var media = document.createElement('button');
        media.type = 'button';
        media.className = 'mini-cart-drawer__line-media';
        if (image) {
            media.setAttribute('data-mini-cart-swatch-trigger', '1');
            media.setAttribute('data-mini-cart-swatch-src', image);
            media.setAttribute(
                'aria-label',
                attr(root, 'data-i18n-image-preview', attr(root, 'data-i18n-swatch-preview', '查看商品图'))
            );
            var frame = document.createElement('span');
            frame.className = 'w-frame';
            frame.setAttribute('data-ratio', '1');
            frame.setAttribute('data-fit', 'cover');
            var img = document.createElement('img');
            img.src = image;
            img.alt = '';
            img.width = 72;
            img.height = 72;
            frame.appendChild(img);
            media.appendChild(frame);
        } else {
            media.disabled = true;
            media.setAttribute('aria-hidden', 'true');
            media.tabIndex = -1;
        }

        var details = document.createElement('div');
        details.className = 'mini-cart-drawer__line-details';

        var title;
        if (url) {
            title = document.createElement('a');
            title.href = url;
        } else {
            title = document.createElement('span');
        }
        title.className = 'mini-cart-drawer__line-title';
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
                // Prefer SSR i18n attrs — API sibling.label often stays Chinese for non-zh/en.
                var label = type === 'tob'
                    ? attr(root, 'data-i18n-tob-cart', '批发车')
                    : attr(root, 'data-i18n-toc-cart', '零售车');
                var count = Number(row.item_count || row.cart_count || 0);
                if (row.switchable === false) {
                    return;
                }
                var btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'mini-cart-drawer__btn mini-cart-drawer__btn--secondary';
                btn.setAttribute('data-mini-cart-sibling', type);
                btn.setAttribute('data-mini-cart-sibling-switch', type);
                var siblingTpl = attr(root, 'data-i18n-sibling-browse', '浏览%1 %2件商品');
                btn.textContent = String(siblingTpl)
                    .replace(/%\{1\}|%1/g, label)
                    .replace(/%\{2\}|%2/g, String(count));
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

    function taxAmountMajor(summary) {
        if (!summary || typeof summary !== 'object') {
            return 0;
        }
        var minor = Number(summary.tax_amount_minor);
        if (isFinite(minor) && minor > 0) {
            return minor / 100;
        }
        var major = Number(summary.tax_amount);
        return isFinite(major) && major > 0 ? major : 0;
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

    function isTobCreditSurface(root, dto) {
        var attr = normalizeCartType(root && root.getAttribute('data-cart-type'));
        if (attr === 'tob') {
            return true;
        }
        if (normalizeCartType(dto && dto.cart_type) === 'tob') {
            return true;
        }
        return false;
    }

    function mergeTobCreditIntoMoneyDto(root, dto) {
        var base = Object.assign({}, dto || {});
        if (!isTobCreditSurface(root, base)) {
            base.deposit_minor = 0;
            base.credit_minor = 0;
            base.cart_type = normalizeCartType(base.cart_type)
                || normalizeCartType(root && root.getAttribute('data-cart-type'))
                || 'toc';
            base.commerce_deposit_allowed = false;
            return base;
        }
        base.cart_type = 'tob';
        base.commerce_deposit_allowed = true;
        var tobApi = window.WelineB2BCheckoutTob;
        if (!tobApi || typeof tobApi.readApplyMinor !== 'function') {
            return base;
        }
        var displayCurrency = String(base.currency || '').trim().toUpperCase() || 'CNY';
        var creditCurrency = typeof tobApi.readCreditCurrency === 'function'
            ? String(tobApi.readCreditCurrency() || '').trim().toUpperCase()
            : '';
        // Live apply only — persist feeds hydrate of controls, never paints summary alone.
        var applyMinor = Math.max(0, Number(tobApi.readApplyMinor()) || 0);
        if (applyMinor <= 0) {
            base.deposit_minor = 0;
            base.credit_minor = 0;
            return base;
        }
        var goodsMajor = (Number(base.goods_subtotal_minor) || 0) / 100;
        // Cross-currency: never paint credit minors 1:1 under display $. Re-quote + FX or fail-closed.
        if (creditCurrency && creditCurrency !== displayCurrency) {
            try {
                if (typeof tobApi.ensureCreditQuote === 'function') {
                    tobApi.ensureCreditQuote(root, {
                        cart_type: 'tob',
                        currency: displayCurrency,
                        subtotal: goodsMajor,
                        force: true,
                    });
                }
            } catch (eRequote) {}
            if (typeof tobApi.convertCreditMinorToDisplay === 'function') {
                var convertedApply = tobApi.convertCreditMinorToDisplay(applyMinor, displayCurrency);
                if (convertedApply === null || convertedApply === undefined) {
                    base.deposit_minor = 0;
                    base.credit_minor = 0;
                    return base;
                }
                applyMinor = Math.max(0, Number(convertedApply) || 0);
            } else {
                base.deposit_minor = 0;
                base.credit_minor = 0;
                return base;
            }
        }
        var depositMinor = 0;
        if (typeof tobApi.estimateDepositMinor === 'function') {
            depositMinor = Math.max(0, Number(tobApi.estimateDepositMinor({
                cart_type: 'tob',
                subtotal: goodsMajor,
                currency: displayCurrency,
            })) || 0);
        }
        var cashMinor = null;
        // Only trust cashDepositMinor when quote currency already matches display.
        if ((!creditCurrency || creditCurrency === displayCurrency)
            && typeof tobApi.cashDepositMinor === 'function') {
            var cash = tobApi.cashDepositMinor();
            if (cash !== null && cash !== undefined) {
                cashMinor = Math.max(0, Number(cash) || 0);
            }
        }
        if (cashMinor !== null) {
            depositMinor = cashMinor + applyMinor;
        } else if (depositMinor > 0) {
            cashMinor = Math.max(0, depositMinor - applyMinor);
        } else {
            cashMinor = 0;
            depositMinor = applyMinor;
        }
        base.deposit_minor = depositMinor;
        base.credit_minor = applyMinor;
        base.payable_minor = cashMinor;
        base.payable_label = attr(root, 'data-i18n-deposit-payable', '本次应付定金');
        return base;
    }

    /**
     * Collapsed footer compact must mirror expanded money-summary payable.
     * When credit/coupon savings apply, show strikethrough "was" (deposit or goods).
     */
    function syncFooterCompactFromMoneyDto(root, dto) {
        if (!root) {
            return;
        }
        var money = dto && typeof dto === 'object' ? dto : {};
        var currency = String(money.currency || root.getAttribute('data-cart-currency') || 'CNY');
        var payableMinor = Math.max(0, Number(money.payable_minor) || 0);
        var depositMinor = Math.max(0, Number(money.deposit_minor) || 0);
        var creditMinor = Math.max(0, Number(money.credit_minor) || 0);
        var discountMinor = Math.max(0, Number(money.discount_minor) || 0);
        var incentiveMinor = Math.max(0, Number(money.payment_incentive_minor) || 0);
        var goodsMinor = Math.max(0, Number(money.goods_subtotal_minor) || 0);
        var taxMinor = Math.max(
            0,
            Number(money.sales_tax_minor != null ? money.sales_tax_minor : money.tax_minor) || 0
        );
        var empty = payableMinor <= 0
            && goodsMinor <= 0
            && depositMinor <= 0
            && creditMinor <= 0
            && discountMinor <= 0;

        var labelEl = root.querySelector('[data-mini-cart-footer-compact-label]');
        var wasEl = root.querySelector('[data-mini-cart-footer-compact-was]');
        var amountEl = root.querySelector('[data-mini-cart-footer-compact-amount]');
        var payableLabel = String(money.payable_label || '').trim();
        var defaultLabel = attr(root, 'data-i18n-subtotal', '小计');
        if (labelEl) {
            labelEl.textContent = payableLabel || defaultLabel;
        }

        var nowText = empty ? '' : formatMoney(payableMinor / 100, currency);
        text(amountEl, nowText);

        var wasMinor = 0;
        if (creditMinor > 0 && depositMinor > payableMinor) {
            wasMinor = depositMinor;
        } else if ((discountMinor > 0 || incentiveMinor > 0) && goodsMinor > payableMinor) {
            wasMinor = goodsMinor + taxMinor;
        }

        if (wasEl) {
            if (!empty && wasMinor > payableMinor) {
                wasEl.hidden = false;
                wasEl.removeAttribute('hidden');
                text(wasEl, formatMoney(wasMinor / 100, currency));
            } else {
                wasEl.hidden = true;
                wasEl.setAttribute('hidden', '');
                text(wasEl, '');
            }
        }
    }

    function paintMiniCartMoneySummary(root, dto) {
        var api = window.WelineStorefrontMoneySummary;
        if (!api || typeof api.paint !== 'function') {
            return false;
        }
        var host = root.querySelector('[data-money-summary]');
        if (!host && typeof api.ensure === 'function') {
            var slot = root.querySelector('.mini-cart-drawer__money-summary-slot, [data-wslot="money-summary"], #money-summary')
                || root.querySelector('.mini-cart-drawer__footer');
            if (slot) {
                host = api.ensure(slot, { mode: 'mini-cart' });
            }
        }
        if (!host) {
            return false;
        }
        var baseDto = Object.assign({ mode: 'mini-cart', shipping_pending: true }, dto || {});
        root.__welineLastMoneyDtoBase = Object.assign({}, baseDto);
        // Align credit.quote to the money-summary display currency before paint.
        try {
            var tobApi = window.WelineB2BCheckoutTob;
            var paintCartType = normalizeCartType(
                (root && root.getAttribute('data-cart-type'))
                || baseDto.cart_type
                || preferredCartType()
            );
            if (tobApi && typeof tobApi.ensureCreditQuote === 'function' && isTobCreditSurface(root, baseDto)) {
                tobApi.ensureCreditQuote(root, {
                    cart_type: 'tob',
                    currency: baseDto.currency,
                    subtotal: (Number(baseDto.goods_subtotal_minor) || 0) / 100,
                });
            }
        } catch (eEnsure) {}
        var merged = mergeTobCreditIntoMoneyDto(root, baseDto);
        api.paint(host, merged);
        // Collapsed bar must use the same payable as the expanded summary (incl. Tob credit).
        syncFooterCompactFromMoneyDto(root, merged);
        return true;
    }

    function refreshMiniCartMoneyFromCredit(root) {
        if (!root || !isDrawerOpen(root)) {
            return;
        }
        var base = root.__welineLastMoneyDtoBase;
        var summary = root.__welineLastSummary;
        if (base && typeof base === 'object') {
            paintMiniCartMoneySummary(root, base);
            return;
        }
        if (summary && typeof summary === 'object') {
            applySummary(root, summary, { skipItems: true });
        }
    }

    function renderDiscountBreakdown(root, summary, currency) {
        var breakdown = root.querySelector('[data-mini-cart-discount-breakdown], [data-money-summary-row="goods"]');
        var goodsEl = root.querySelector('[data-cart-goods-subtotal]');
        var linesEl = root.querySelector('[data-mini-cart-discount-lines], [data-money-summary-discount-lines]');
        if (!linesEl) {
            if (goodsEl) {
                text(goodsEl, formatMoney(Number(summary.subtotal || summary.grand_total || 0), currency));
            }
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
        if (breakdown && breakdown.hasAttribute && breakdown.hasAttribute('data-mini-cart-discount-breakdown')) {
            breakdown.hidden = false;
            breakdown.removeAttribute('hidden');
        }
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

    function renderTaxRow(root, summary, currency) {
        if (root.querySelector('[data-money-summary]') && window.WelineStorefrontMoneySummary) {
            return;
        }
        var row = root.querySelector('[data-mini-cart-tax-row]');
        var amountEl = root.querySelector('[data-cart-tax-amount]');
        var labelEl = root.querySelector('[data-mini-cart-tax-label]');
        var note = root.querySelector('[data-mini-cart-note]');
        var tax = taxAmountMajor(summary);
        if (labelEl) {
            labelEl.textContent = attr(root, 'data-i18n-tax', '税费');
        }
        if (row) {
            if (tax > 0) {
                row.hidden = false;
                if (amountEl) {
                    text(amountEl, formatMoney(tax, currency));
                }
            } else {
                row.hidden = true;
                if (amountEl) {
                    text(amountEl, '');
                }
            }
        }
        if (note) {
            note.textContent = tax > 0
                ? attr(root, 'data-i18n-note-shipping', '运费将在结算时计算')
                : attr(root, 'data-i18n-note', '税费与运费将在结算时计算');
        }
    }

    /**
     * Stable signature of cart lines (id+qty). Used so soft open / background sync
     * can skip wipe/rebuild when the list is already correct — including qty edits.
     */
    function lineItemsSignature(items) {
        if (!Array.isArray(items) || !items.length) {
            return '';
        }
        var parts = [];
        var limit = Math.min(items.length, 20);
        for (var i = 0; i < limit; i++) {
            var item = items[i] || {};
            var id = String(item.item_id || item.id || '').trim();
            var qty = Math.max(1, Number(item.qty || item.quantity || 1));
            parts.push(id + ':' + qty);
        }
        return parts.join('|');
    }

    function domLineItemsSignature(root) {
        if (!root) {
            return '';
        }
        var lines = root.querySelectorAll('[data-mini-cart-line]');
        if (!lines.length) {
            return '';
        }
        var parts = [];
        for (var i = 0; i < lines.length; i++) {
            var line = lines[i];
            var id = String(line.getAttribute('data-item-id') || '').trim();
            var input = line.querySelector('[data-qty-input]');
            var qty = Math.max(1, Number(input && input.value ? input.value : 1));
            parts.push(id + ':' + qty);
        }
        return parts.join('|');
    }

    /**
     * True when the drawer already shows this summary's lines — open/sync must not
     * wipe/rebuild the item list (looks like “刷了一下购物车”).
     */
    function drawerContentIsFresh(root, summary) {
        if (!root || !summary || typeof summary !== 'object') {
            return false;
        }
        var count = Number(summary.cart_count || summary.item_count || 0);
        var mode = normalizeCartType(summary.cart_type || summary.selling_mode || preferredCartType());
        var shownCount = Number(root.getAttribute('data-cart-count') || 0);
        var shownType = normalizeCartType(root.getAttribute('data-cart-type') || '');
        var lines = root.querySelectorAll('[data-mini-cart-line]').length;
        var emptyNode = root.querySelector('[data-mini-cart-empty]');
        if (count <= 0) {
            return !!(emptyNode && shownCount === 0 && lines === 0);
        }
        if (shownCount !== count || shownType !== mode || lines <= 0) {
            return false;
        }
        var items = Array.isArray(summary.items) ? summary.items : [];
        if (!items.length) {
            // Count matches but payload has no lines — cannot prove DOM is current.
            return false;
        }
        var want = lineItemsSignature(items);
        var have = domLineItemsSignature(root);
        return !!(want && have && want === have);
    }

    function applySummary(root, summary, options) {
        if (!summary || typeof summary !== 'object') return;
        options = options || {};
        var skipItems = options.skipItems === true;
        if (!summaryCacheHasLineItems(summary)) {
            invalidateEmptyCachesClaimedBySiblings(summary);
        }
        root.__welineLastSummary = summary;
        var count = Number(summary.cart_count || summary.item_count || 0);
        var currency = String(summary.currency || 'CNY');
        var subtotal = Number(summary.subtotal || 0);
        if (!isFinite(subtotal) || subtotal <= 0) {
            subtotal = Number(summary.grand_total || 0);
        }
        var discountMajor = discountAmountMajor(summary);
        var taxMajor = taxAmountMajor(summary);
        var payable = Math.max(0, subtotal - discountMajor + taxMajor);
        var formatted = formatMoney(payable, currency);
        var goodsFormatted = formatMoney(subtotal, currency);
        // WO-BUILD-OPS-02-HOME：空车不展示 $0.00 价签噪声
        var emptyCart = count <= 0 || !!summary.is_empty;
        var visibleFormatted = emptyCart ? '' : formatted;
        var visibleGoodsFormatted = emptyCart ? '' : goodsFormatted;
        var items = Array.isArray(summary.items) ? summary.items : [];
        var cartType = normalizeCartType(summary.cart_type || summary.selling_mode || preferredCartType());
        var gate = String(summary.gate_reason || '').toLowerCase();

        root.setAttribute('data-cart-count', String(count));
        root.setAttribute('data-cart-subtotal', emptyCart ? '' : formatted);
        // Authoritative goods base (major) for B2B deposit/credit — must stay in sync even when
        // the discount breakdown row is hidden (otherwise credit keeps a stale larger total).
        root.setAttribute('data-cart-goods-subtotal-major', String(isFinite(subtotal) && subtotal > 0 ? subtotal : 0));
        root.setAttribute('data-cart-type', cartType);
        if (gate === 'login' || gate === 'membership') {
            root.setAttribute('data-cart-gate', gate);
        } else {
            root.removeAttribute('data-cart-gate');
        }
        root.classList.toggle('is-empty', emptyCart);
        root.classList.toggle('is-demo', !!summary.is_demo);
        applyCartTypeAttr(root, summary);

        var badge = root.querySelector('[data-cart-count-badge]');
        if (badge) {
            badge.hidden = count <= 0;
            badge.textContent = count > 99 ? '99+' : String(count);
        }
        text(root.querySelector('[data-cart-subtotal-text]'), visibleFormatted);
        text(root.querySelector('[data-cart-item-count]'), String(count));
        var moneyDto = {
            currency: currency,
            goods_subtotal_minor: Math.round(Number(subtotal || 0) * 100),
            discount_minor: Math.round(Number(discountMajor || 0) * 100),
            sales_tax_minor: Math.round(Number(taxMajor || 0) * 100),
            tax_minor: Math.round(Number(taxMajor || 0) * 100),
            payable_minor: Math.round(Number(payable || 0) * 100),
            note: taxMajor > 0
                ? attr(root, 'data-i18n-note-shipping', '运费将在结算时计算')
                : attr(root, 'data-i18n-note', '税费与运费将在结算时计算'),
            sales_tax_label: attr(root, 'data-i18n-sales-tax', attr(root, 'data-i18n-tax', '销售税')),
            tax_label: attr(root, 'data-i18n-sales-tax', attr(root, 'data-i18n-tax', '销售税')),
        };
        if (!emptyCart) {
            // paintMiniCartMoneySummary syncs compact from Tob-merged payable.
            // Do NOT overwrite [data-cart-total-amount] with retail visibleFormatted afterward.
            if (!paintMiniCartMoneySummary(root, moneyDto)) {
                syncFooterCompactFromMoneyDto(root, mergeTobCreditIntoMoneyDto(root, moneyDto));
            }
        } else {
            syncFooterCompactFromMoneyDto(root, {
                currency: currency,
                goods_subtotal_minor: 0,
                discount_minor: 0,
                payable_minor: 0,
            });
        }
        // Always refresh goods node text — do not wait for discount lines to appear.
        text(root.querySelector('[data-cart-goods-subtotal]'), visibleGoodsFormatted);
        renderDiscountBreakdown(root, emptyCart ? { subtotal: 0 } : summary, currency);
        renderTaxRow(root, emptyCart ? null : summary, currency);
        renderFreeShippingProgress(root, summary);
        if (skipItems) {
            var footer = root.querySelector('[data-mini-cart-footer]');
            if (footer) {
                footer.hidden = emptyCart;
            }
            return;
        }
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
            if (String(root.getAttribute('data-cart-gate') || '') === 'login') {
                promptEnsureLogin();
            }
            loadDrawer(root, { forceNetwork: false });
            return;
        }
        resetDrawerBusy(root);
    }

    function isDrawerOpen(root) {
        return root.classList.contains(openClass);
    }

    function isFooterCollapsed(root) {
        return !!(root && root.classList.contains('is-footer-collapsed'));
    }

    function setFooterCollapsed(root, collapsed) {
        if (!root) {
            return;
        }
        var next = !!collapsed;
        root.classList.toggle('is-footer-collapsed', next);
        var toggle = root.querySelector('[data-mini-cart-footer-toggle]');
        var details = root.querySelector('[data-mini-cart-footer-details]');
        var compact = root.querySelector('[data-mini-cart-footer-compact]');
        var label = root.querySelector('[data-mini-cart-footer-toggle-label]');
        if (toggle) {
            toggle.setAttribute('aria-expanded', next ? 'false' : 'true');
        }
        if (details) {
            // Prefer aria-hidden + CSS max-height (not [hidden]) so the sheet can
            // animate upward instead of vanishing with display:none.
            details.hidden = false;
            details.removeAttribute('hidden');
            details.setAttribute('aria-hidden', next ? 'true' : 'false');
        }
        if (compact) {
            compact.hidden = !next;
        }
        if (label) {
            label.textContent = next
                ? attr(root, 'data-i18n-footer-expand', '展开明细')
                : attr(root, 'data-i18n-footer-collapse', '收起明细');
            // Collapsed bar shows compact total; keep label text for SR via toggle aria.
            label.hidden = next;
        }
    }

    function bindFooterToggle(root) {
        var toggle = root.querySelector('[data-mini-cart-footer-toggle]');
        if (!toggle || toggle.getAttribute('data-bound') === '1') {
            return;
        }
        toggle.setAttribute('data-bound', '1');
        // Default: expanded (all coupons / money lines). User may collapse to free line height.
        setFooterCollapsed(root, false);
        toggle.addEventListener('click', function (event) {
            if (event && typeof event.preventDefault === 'function') {
                event.preventDefault();
            }
            if (event && typeof event.stopPropagation === 'function') {
                event.stopPropagation();
            }
            setFooterCollapsed(root, !isFooterCollapsed(root));
        });
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
                // Line remove/qty can retarget onto the backdrop; keep drawer open and refresh.
                if (shouldSuppressBackdropClose(root)) {
                    return;
                }
                setDrawerOpen(root, false);
            });
        }

        bindFooterToggle(root);

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
                applySummary(root, cached, { skipItems: drawerContentIsFresh(root, cached) });
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
                    if (gateReasonFromPayload(payload) === 'login') {
                        promptEnsureLogin();
                    }
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
            applySummary(root, normalized, {
                // Keep money/badge updates; never wipe lines that already match.
                skipItems: drawerContentIsFresh(root, normalized),
            });
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
        // Open drawer: never put a busy overlay on a background badge/sync — that flash
        // looks like “刷了一下购物车”. Mutations use runWithDrawerBusy separately.
        if (options.drawerBusy && isDrawerOpen(root) && options.forceItems === true) {
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
        var keepDrawerOpen = isDrawerOpen(root) && !isCheckoutPath();
        noteDrawerLineInteraction();
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
            // Already painted from the mutation response — mark refresh:false so Marketing
            // hydrate / scheduleCartRefreshFromEvent cannot kick a second getCart wipe.
            window.dispatchEvent(new CustomEvent('weline:cart-updated', {
                detail: Object.assign({}, summary, {
                    source: 'mini-cart-mutate',
                    refresh: false,
                }),
            }));
            // On checkout, close the drawer so the refreshed order summary is visible
            // and the two surfaces do not appear out of sync.
            if (isCheckoutPath()) {
                setDrawerOpen(root, false);
                return;
            }
            // Storefront: remove/qty must refresh in place — never collapse the drawer.
            noteDrawerLineInteraction();
            if (keepDrawerOpen && !isDrawerOpen(root)) {
                setDrawerOpen(root, true);
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
                event.stopPropagation();
                noteDrawerLineInteraction();
                mutateLine(root, line, itemId, 0, true);
                return;
            }
            if (target.closest('[data-qty-decrease]')) {
                event.preventDefault();
                event.stopPropagation();
                noteDrawerLineInteraction();
                var nextQty = Math.max(1, currentQty - 1);
                if (nextQty === currentQty) {
                    return;
                }
                mutateLine(root, line, itemId, nextQty, false);
                return;
            }
            if (target.closest('[data-qty-increase]')) {
                event.preventDefault();
                event.stopPropagation();
                noteDrawerLineInteraction();
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
        var mode = preferredCartType();
        var needsOrigin = !!(window.WelineCart
            && typeof window.WelineCart.needsOriginRefresh === 'function'
            && window.WelineCart.needsOriginRefresh());
        // Soft open paint from typed cache first — avoid wipe/rebuild flash.
        if (!options.forceNetwork) {
            var cachedSoft = normalizeSummary(readSummaryCache(mode));
            if (cachedSoft && cachedSoft.success !== false
                && normalizeCartType(cachedSoft.cart_type || cachedSoft.selling_mode) === mode
                && summaryCacheHasLineItems(cachedSoft)) {
                applySummary(root, cachedSoft, { skipItems: drawerContentIsFresh(root, cachedSoft) });
                if (!needsOrigin) {
                    return;
                }
                // Stale-origin flag: revalidate in background without busy overlay wipe.
                options.forceNetwork = true;
                options.softRevalidate = true;
            } else if (needsOrigin) {
                options.forceNetwork = true;
            }
        }
        var run = async function () {
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
                applySummary(root, normalized, {
                    skipItems: !!(options.softRevalidate && drawerContentIsFresh(root, normalized)),
                });
                rememberSummaryCache(normalized);
                if (options.forceNetwork && window.WelineCart
                    && typeof window.WelineCart.consumeNeedsOriginRefresh === 'function') {
                    window.WelineCart.consumeNeedsOriginRefresh();
                }
            } catch (e) {
                if (!options.softRevalidate) {
                    applySummary(root, emptySummaryForType(mode));
                }
            }
        };
        if (options.softRevalidate) {
            return run().catch(function () {});
        }
        return runWithDrawerBusy(root, run);
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
            var softOnly = !!(summary && typeof summary === 'object' && summary.refresh === false);
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
            var clearDiscount = !!(summary && summary.clear_discount === true);
            var hasDiscountHint = !!(pendingDiscountPreview || clearDiscount
                || (summary && String(summary.coupon_code || '').trim()));

            function paintDiscountOntoRoots() {
                roots.forEach(function (root) {
                    if (isDemoChromeOnly(root)) {
                        return;
                    }
                    var base = root.__welineLastSummary
                        || normalizeSummary(readSummaryCache(preferredCartType()))
                        || null;
                    if (!base) {
                        return;
                    }
                    var merged = mergeDiscountPreviewIntoSummary(
                        base,
                        pendingDiscountPreview,
                        clearDiscount
                    );
                    applySummary(root, merged, {
                        skipItems: drawerContentIsFresh(root, merged),
                    });
                });
            }

            // Soft discount / mutate echo: merge totals only — never fall through to getCart.
            if (softOnly) {
                if (hasCartPayload) {
                    roots.forEach(function (root) {
                        if (isDemoChromeOnly(root)) {
                            return;
                        }
                        var painted = clearDiscount || pendingDiscountPreview
                            ? mergeDiscountPreviewIntoSummary(normalized, pendingDiscountPreview, clearDiscount)
                            : normalized;
                        applySummary(root, painted, {
                            skipItems: drawerContentIsFresh(root, painted),
                        });
                        rememberSummaryCache(painted);
                    });
                    return;
                }
                if (hasDiscountHint) {
                    paintDiscountOntoRoots();
                }
                return;
            }

            // Optimistic: paint chip quote onto current summary before network returns.
            if (forceRefresh && hasDiscountHint) {
                paintDiscountOntoRoots();
            }
            roots.forEach(function (root) {
                if (!forceRefresh && hasCartPayload) {
                    applySummary(root, normalized, {
                        skipItems: drawerContentIsFresh(root, normalized),
                    });
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
            // Only explicit forceRefresh may hit the network. Soft/empty echoes must not
            // “过一会儿又 reload 一次” via loadDrawer(forceNetwork).
            if (!forceRefresh) {
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

    function appendStylesheet(href, liveAttr) {
        var link = document.createElement('link');
        link.rel = 'stylesheet';
        link.href = href;
        if (liveAttr) {
            link.setAttribute(liveAttr, '1');
        }
        document.head.appendChild(link);
        return link;
    }

    function ensureCartQtyHitCss(assetVersion) {
        // Cart-owned qty hit-target gate — Theme only ensures the sheet is present.
        var stamp = '20261007-cart-qty-hit-v3';
        var href = '/Weline/Cart/view/statics/css/mini-cart-drawer-qty-hit.css?v=' + stamp;
        if (assetVersion) {
            href += '&_weline_dev=' + encodeURIComponent(assetVersion);
        }
        var existing = document.querySelector(
            'link[data-weline-cart-mini-cart-qty-hit="1"], link[rel="stylesheet"][href*="mini-cart-drawer-qty-hit.css"]'
        );
        if (existing) {
            if (String(existing.getAttribute('href') || '').indexOf(stamp) === -1) {
                existing.setAttribute('href', href);
                existing.setAttribute('data-weline-cart-mini-cart-qty-hit', '1');
            }
            return;
        }
        appendStylesheet(href, 'data-weline-cart-mini-cart-qty-hit');
    }

    function ensureDrawerCss() {
        // Opening the drawer used to remove baked layout_source sheets and re-inject —
        // that FOUC looked like the cart “刷了一下”. Ensure at most once per page.
        if (drawerCssReady) {
            return;
        }
        drawerCssReady = true;
        var cssStamp = '20261010-minicart-paper-ink-v24';
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
        var href = '/Weline/Theme/view/statics/css/widgets/mini-cart-drawer.css?v=' + cssStamp;
        if (assetVersion) {
            href += '&_weline_dev=' + encodeURIComponent(assetVersion);
        }
        var existing = document.querySelector(
            'link[data-weline-mini-cart-drawer-live="1"], link[rel="stylesheet"][href*="mini-cart-drawer.css"]'
        );
        if (existing) {
            if (String(existing.getAttribute('href') || '').indexOf(cssStamp) === -1) {
                existing.setAttribute('href', href);
                existing.setAttribute('data-weline-mini-cart-drawer-live', '1');
            }
        } else {
            appendStylesheet(href, 'data-weline-mini-cart-drawer-live');
        }
        ensureCartQtyHitCss(assetVersion);
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
        var runDiscover = function () {
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
        };
        var observeApi = (window.Weline && window.Weline.dom && typeof window.Weline.dom.observe === 'function')
            ? window.Weline.dom.observe.bind(window.Weline.dom)
            : null;
        if (observeApi) {
            window.Weline.MiniCart.__observer = observeApi({
                target: document.documentElement,
                options: { childList: true, subtree: true },
                idleTimeoutMs: 100,
                label: 'mini-cart-icon',
                onFlush: runDiscover,
            });
            return;
        }
        /* ARCH_MO_FALLBACK_START */
        var observer = new MutationObserver(function () {
            runDiscover();
        });
        observer.observe(document.documentElement, { childList: true, subtree: true });
        window.Weline.MiniCart.__observer = observer;
        /* ARCH_MO_FALLBACK_END */
    }

    function boot() {
        // Closed drawer must load the current sheet on first paint — waiting until
        // open leaves stale translateX(100%)/100vw CSS expanding the page gutter.
        ensureDrawerCss();
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

        // 批发信用勾选/确认抵扣后，立即刷新迷你车金额行（定金/信用抵扣/本次应付定金）。
        window.addEventListener('weline:b2b-credit-changed', function () {
            document.querySelectorAll('[data-w-mini-cart="1"]').forEach(function (root) {
                refreshMiniCartMoneyFromCredit(root);
            });
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
                if (isDrawerOpen(root)) {
                    loadDrawer(root, { forceNetwork: forceNetwork }).catch(function () {});
                    return;
                }
                syncCartState(root, { forceNetwork: forceNetwork }).catch(function () {});
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
                if (isDrawerOpen(root)) {
                    loadDrawer(root, { forceNetwork: forceNetwork }).catch(function () {});
                    return;
                }
                syncCartState(root, { forceNetwork: forceNetwork }).catch(function () {});
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
