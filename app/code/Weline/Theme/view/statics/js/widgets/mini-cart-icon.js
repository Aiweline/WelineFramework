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

    function rememberSummaryCache(summary) {
        if (!summary || typeof summary !== 'object' || summary.success === false) {
            return;
        }
        if (window.WelineCart && typeof window.WelineCart.rememberSummary === 'function') {
            window.WelineCart.rememberSummary(summary);
        }
    }

    function sellingModeFromCookie() {
        try {
            if (window.WelineB2BSellingMode && typeof window.WelineB2BSellingMode.preferredMode === 'function') {
                return window.WelineB2BSellingMode.preferredMode();
            }
            var match = document.cookie.match(/(?:^|; )weline_selling_mode=([^;]*)/);
            var value = match ? decodeURIComponent(match[1]).toLowerCase() : '';
            return value === 'tob' ? 'tob' : 'toc';
        } catch (e) {
            return 'toc';
        }
    }

    function cartQueryParams(extra) {
        var params = Object.assign({}, extra || {});
        var mode = sellingModeFromCookie();
        params.cart_type = mode;
        params.selling_mode = mode;
        return params;
    }

    function applyCartTypeBadge(root, summary) {
        if (!root) {
            return;
        }
        var cartType = String(
            (summary && (summary.cart_type || summary.selling_mode)) || sellingModeFromCookie() || 'toc'
        ).toLowerCase();
        if (cartType !== 'tob') {
            cartType = 'toc';
        }
        root.setAttribute('data-cart-type', cartType);
        var badge = root.querySelector('[data-cart-type-badge]');
        if (!badge) {
            return;
        }
        badge.hidden = false;
        badge.setAttribute('data-tone', cartType === 'tob' ? 'warning' : 'secondary');
        badge.setAttribute('data-cart-type', cartType);
        var label = cartType === 'tob'
            ? attr(root, 'data-i18n-cart-type-tob', '批发')
            : attr(root, 'data-i18n-cart-type-toc', '零售');
        text(badge, label);
    }

    function readSummaryCache() {
        if (window.WelineCart && typeof window.WelineCart.getCachedSummary === 'function') {
            return window.WelineCart.getCachedSummary();
        }
        return null;
    }

    function applyCachedSummaryToRoots() {
        var cached = normalizeSummary(readSummaryCache());
        if (!cached || cached.success === false) {
            return false;
        }
        if (cached.cart_count == null && !Array.isArray(cached.items)) {
            return false;
        }
        document.querySelectorAll('[data-w-mini-cart="1"]').forEach(function (root) {
            if (isDemoChromeOnly(root)) {
                return;
            }
            applySummary(root, cached);
        });
        return true;
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

    function appendMiniCartOptions(details, item) {
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
                    var img = document.createElement('img');
                    img.className = 'mini-cart-drawer__line-option-swatch';
                    img.src = swatchImage;
                    img.alt = '';
                    li.appendChild(img);
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

        appendMiniCartOptions(details, item);

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
            p.textContent = attr(root, 'data-i18n-empty', '购物车是空的');
            empty.appendChild(p);

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
        if (!items.length) {
            breakdown.hidden = true;
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
        var count = Number(summary.cart_count || summary.item_count || 0);
        var currency = String(summary.currency || 'CNY');
        var subtotal = Number(summary.subtotal || summary.grand_total || 0);
        var discountMajor = discountAmountMajor(summary);
        var payable = Math.max(0, subtotal - discountMajor);
        var formatted = formatMoney(payable, currency);
        var items = Array.isArray(summary.items) ? summary.items : [];

        root.setAttribute('data-cart-count', String(count));
        root.setAttribute('data-cart-subtotal', formatted);
        root.classList.toggle('is-empty', count <= 0 || !!summary.is_empty);
        root.classList.toggle('is-demo', !!summary.is_demo);
        applyCartTypeBadge(root, summary);

        var badge = root.querySelector('[data-cart-count-badge]');
        if (badge) {
            badge.hidden = count <= 0;
            badge.textContent = count > 99 ? '99+' : String(count);
        }
        text(root.querySelector('[data-cart-subtotal-text]'), formatted);
        text(root.querySelector('[data-cart-item-count]'), String(count));
        text(root.querySelector('[data-cart-total-amount]'), formatted);
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
        if (!options.forceNetwork) {
            var cached = normalizeSummary(readSummaryCache());
            if (cached && cached.success !== false
                && (cached.cart_count != null || Array.isArray(cached.items))) {
                applySummary(root, cached);
                return;
            }
        }
        var task = async function () {
            var api = await withTimeout(waitForCartApi(), 8000, 'cart api wait timeout');
            var token = guestToken();
            var result;
            if (typeof api.getCart === 'function') {
                result = await withTimeout(
                    api.getCart(cartQueryParams(token ? { guest_token: token } : {}), { silent: true }),
                    8000,
                    'getCart timeout'
                );
            } else if (typeof api.summary === 'function') {
                result = await withTimeout(api.summary(cartQueryParams({}), { silent: true }), 8000, 'summary timeout');
            } else if (typeof api.count === 'function') {
                result = await withTimeout(api.count({}, { silent: true }), 8000, 'count timeout');
            } else {
                return;
            }
            var payload = result && result.data && typeof result.data === 'object' ? result.data : result;
            if (!payload || payload.success === false) {
                return;
            }
            var normalized = normalizeSummary(payload) || payload;
            applySummary(root, normalized);
            rememberSummaryCache(normalized);
            var count = Number(normalized.cart_count || normalized.item_count || 0);
            if (count > 0 && window.Weline && window.Weline.Api && typeof window.Weline.Api.markCartActive === 'function') {
                window.Weline.Api.markCartActive();
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
            var params = { item_id: itemId };
            if (token) {
                params.guest_token = token;
            }
            var requestOptions = { silent: true };
            var result;
            if (remove) {
                if (typeof api.remove === 'function') {
                    result = await api.remove(params, requestOptions);
                } else if (typeof api.remove === 'function') {
                    result = await api.remove(params, requestOptions);
                } else {
                    return;
                }
            } else if (typeof api.update === 'function') {
                result = await api.update(Object.assign({}, params, { qty: qty }), requestOptions);
            } else if (typeof api.update === 'function') {
                result = await api.update(Object.assign({}, params, { qty: qty }), requestOptions);
            } else {
                return;
            }
            var payload = result && result.data && typeof result.data === 'object' ? result.data : result;
            if (!payload || payload.success === false) {
                return;
            }
            var summary = normalizeSummary(payload) || payload;
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

    function bindLineActions(root) {
        root.addEventListener('click', function (event) {
            if (isDrawerBusy(root)) {
                event.preventDefault();
                return;
            }
            var target = event.target;
            if (!(target instanceof Element)) {
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
        if (!options.forceNetwork) {
            var cached = normalizeSummary(readSummaryCache());
            if (cached && cached.success !== false
                && (cached.cart_count != null || Array.isArray(cached.items))) {
                applySummary(root, cached);
                return;
            }
        }
        return runWithDrawerBusy(root, async function () {
            try {
                var api = await withTimeout(waitForCartApi(), 8000, 'cart api wait timeout');
                var token = guestToken();
                var result;
                if (typeof api.getCart === 'function') {
                    result = await withTimeout(
                        api.getCart(cartQueryParams(token ? { guest_token: token } : {}), { silent: true }),
                        8000,
                        'getCart timeout'
                    );
                } else if (typeof api.miniItems === 'function') {
                    result = await withTimeout(api.miniItems({ limit: 20 }, { silent: true }), 8000, 'miniItems timeout');
                } else if (typeof api.summary === 'function') {
                    result = await withTimeout(api.summary(cartQueryParams({}), { silent: true }), 8000, 'summary timeout');
                } else {
                    applySummary(root, { success: true, cart_count: 0, item_count: 0, is_empty: true, items: [], subtotal: 0, currency: 'CNY' });
                    return;
                }
                var payload = result && result.data && typeof result.data === 'object' ? result.data : result;
                if (!payload || payload.success === false) {
                    applySummary(root, { success: true, cart_count: 0, item_count: 0, is_empty: true, items: [], subtotal: 0, currency: 'CNY' });
                    return;
                }
                applySummary(root, normalizeSummary(payload) || payload);
                rememberSummaryCache(normalizeSummary(payload) || payload);
            } catch (e) {
                applySummary(root, { success: true, cart_count: 0, item_count: 0, is_empty: true, items: [], subtotal: 0, currency: 'CNY' });
            }
        });
    }

    async function refreshBadge(root) {
        return syncCartState(root, { drawerBusy: isDrawerOpen(root) });
    }

    function scheduleCartSync(root) {
        syncCartState(root).catch(function () {
            // Keep server-rendered badge when cart API is unavailable.
        });
    }

    function scheduleCartRefreshFromEvent(summary) {
        if (cartRefreshTimer) {
            clearTimeout(cartRefreshTimer);
        }
        cartRefreshTimer = window.setTimeout(function () {
            cartRefreshTimer = null;
            var normalized = normalizeSummary(summary);
            var roots = document.querySelectorAll('[data-w-mini-cart="1"]');
            var drawerOpen = false;
            roots.forEach(function (root) {
                if (normalized && normalized.success !== false && (normalized.cart_count != null || normalized.items)) {
                    applySummary(root, normalized);
                    rememberSummaryCache(normalized);
                    return;
                }
                drawerOpen = drawerOpen || isDrawerOpen(root);
            });
            if (normalized && normalized.success !== false && (normalized.cart_count != null || normalized.items)) {
                rememberSummaryCache(normalized);
                return;
            }
            if (applyCachedSummaryToRoots()) {
                return;
            }
            roots.forEach(function (root) {
                if (isDrawerOpen(root)) {
                    refreshBadge(root).catch(function () {
                        // Keep last rendered drawer when refresh fails.
                    });
                    return;
                }
                syncCartState(root, { forceNetwork: true }).catch(function () {
                    // Keep server-rendered badge when cart API is unavailable.
                });
            });
            if (drawerOpen) {
                roots.forEach(function (root) {
                    if (isDrawerOpen(root)) {
                        loadDrawer(root, { forceNetwork: true }).catch(function () {
                            // Keep last rendered drawer when async load fails.
                        });
                    }
                });
            }
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

    function ensureDrawerCss() {
        if (document.querySelector('link[data-weline-mini-cart-drawer-live="1"]')) {
            return;
        }
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
        var href = '/Weline/Theme/view/statics/css/widgets/mini-cart-drawer.css';
        if (assetVersion) {
            href += (href.indexOf('?') >= 0 ? '&' : '?') + '_weline_dev=' + encodeURIComponent(assetVersion);
        } else {
            href += (href.indexOf('?') >= 0 ? '&' : '?') + '_weline_dev=' + String(Date.now());
        }
        var link = document.createElement('link');
        link.rel = 'stylesheet';
        link.href = href;
        link.setAttribute('data-weline-mini-cart-drawer-live', '1');
        document.head.appendChild(link);
    }

    function bootMiniCartRoots() {
        var hydratedFromCache = applyCachedSummaryToRoots();
        document.querySelectorAll('[data-w-mini-cart="1"]').forEach(function (root) {
            if (root.getAttribute('data-w-mini-cart-init') === '1') {
                return;
            }
            root.setAttribute('data-w-mini-cart-init', '1');
            bindDrawer(root);
            bindLineActions(root);
            if (!hydratedFromCache) {
                scheduleCartSync(root);
            }
        });
    }

    function observeMiniCartRoots() {
        if (window.Weline.MiniCart.__observer || typeof MutationObserver !== 'function') {
            return;
        }
        var observer = new MutationObserver(function () {
            bootMiniCartRoots();
        });
        observer.observe(document.documentElement, { childList: true, subtree: true });
        window.Weline.MiniCart.__observer = observer;
    }

    function boot() {
        if (window.Weline.MiniCart.__booted) {
            bootMiniCartRoots();
            return;
        }
        window.Weline.MiniCart.__booted = true;
        clearAllCheckoutNavigationState();
        bootMiniCartRoots();
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
            if (!event || event.key !== 'weline.cart.summary_cache') {
                return;
            }
            applyCachedSummaryToRoots();
        });

        window.addEventListener('weline:cart-updated', function (event) {
            var summary = event && event.detail && typeof event.detail === 'object' ? event.detail : null;
            scheduleCartRefreshFromEvent(summary);
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
