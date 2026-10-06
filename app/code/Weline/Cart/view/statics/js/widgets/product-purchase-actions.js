(function (global) {
    'use strict';

    const GUEST_TOKEN_STORAGE_KEY = 'weline.cart.guest_token';
    let guestTokenPromise = null;

    function detailRoot(button) {
        if (!button) {
            return null;
        }
        return button.closest('[data-testid="storefront-product-detail"]');
    }

    function widgetRoot(button) {
        if (!button) {
            return null;
        }
        return button.closest('.weline-cart-product-add-to-cart, .weline-cart-product-card-add-to-cart, .weline-checkout-product-buy-now, .weline-checkout-product-card-buy-now');
    }

    function detailMessage(root) {
        if (!root) {
            return null;
        }
        return root.querySelector('[data-testid="detail-message"]');
    }

    function purchaseFeedback(button) {
        const detail = detailRoot(button);
        if (detail) {
            return {
                message: detailMessage(detail),
                cartLink: null,
            };
        }

        const storefront = button.closest('[data-testid="storefront-product-catalog"]');
        if (storefront) {
            return {
                message: storefront.querySelector('[data-testid="catalog-message"]'),
                cartLink: storefront.querySelector('[data-testid="view-cart"]'),
            };
        }

        const category = button.closest('[data-testid="storefront-category-catalog"]');
        if (category) {
            return {
                message: category.querySelector('[data-testid="category-message"]'),
                cartLink: null,
            };
        }

        return {
            message: null,
            cartLink: null,
        };
    }

    function cardButtonLabelNode(button) {
        if (!button) {
            return null;
        }
        return button.querySelector('[data-card-add-label], span.weline-pixel\\:\\:add_to_cart, span');
    }

    function markCardButtonAdded(button, successText) {
        const scope = widgetRoot(button);
        if (!scope || !scope.classList.contains('weline-cart-product-card-add-to-cart')) {
            return;
        }

        const label = successText || scope.dataset.purchaseMarkAdded || scope.dataset.purchaseSuccess || '';
        const defaultLabel = button.dataset.defaultLabel || '';
        if (label === '') {
            return;
        }

        const labelNode = cardButtonLabelNode(button);
        button.classList.add('is-added');
        button.disabled = false;

        if (labelNode) {
            const previousLabel = labelNode.textContent || defaultLabel;
            labelNode.textContent = label;
            global.setTimeout(function () {
                labelNode.textContent = defaultLabel || previousLabel;
                button.classList.remove('is-added');
                button.disabled = false;
            }, 1500);
            return;
        }

        if (button.querySelector('svg, .w-icon')) {
            global.setTimeout(function () {
                button.classList.remove('is-added');
                button.disabled = false;
            }, 1500);
            return;
        }

        const previousText = button.textContent || defaultLabel;
        button.textContent = label;
        global.setTimeout(function () {
            button.textContent = defaultLabel || previousText;
            button.classList.remove('is-added');
            button.disabled = false;
        }, 1500);
    }

    function readCartLinkMeta(button) {
        const scope = widgetRoot(button);
        if (!scope) {
            return { url: '', text: '' };
        }
        const resolved = resolveSameOriginUrl(scope.dataset.cartUrl || '', '/cart');
        return {
            url: resolved ? resolved.toString() : (scope.dataset.cartUrl || ''),
            text: scope.dataset.cartLinkText || '',
        };
    }

    function unwrapCartPayload(result) {
        if (!result || typeof result !== 'object') {
            return null;
        }
        const nested = result.data && typeof result.data === 'object' ? result.data : null;
        if (nested) {
            return Object.assign({}, nested, result);
        }
        return result;
    }

    function normalizeCartSummary(summary) {
        if (!summary || typeof summary !== 'object') {
            return null;
        }
        const normalized = Object.assign({}, summary);
        if (normalized.subtotal_minor != null) {
            normalized.subtotal = Number(normalized.subtotal_minor) / 100;
        }
        if (normalized.grand_total_minor != null) {
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

    function notifyCartUpdated(result, opts) {
        const options = opts && typeof opts === 'object' ? opts : {};
        const summary = normalizeCartSummary(unwrapCartPayload(result));
        const count = summary
            ? Number(summary.cart_count != null ? summary.cart_count : summary.item_count)
            : null;
        const retailSiblingPreview = options.retailSiblingPreview === true;

        if (summary && global.WelineCart && typeof global.WelineCart.rememberSummary === 'function') {
            global.WelineCart.rememberSummary(summary);
        }

        global.dispatchEvent(new CustomEvent('weline:cart-updated', {
            detail: summary
                ? Object.assign({}, summary, retailSiblingPreview ? { source: 'retail-only-add-preview' } : {})
                : {},
        }));

        const detail = {
            count: count != null && !Number.isNaN(count) ? count : undefined,
            cart_count: count != null && !Number.isNaN(count) ? count : undefined,
            summary: summary || {},
        };
        global.dispatchEvent(new CustomEvent('weline:cart:update', { detail: detail }));
        global.dispatchEvent(new CustomEvent('weshop:cart:updated', { detail: detail }));

        // Ensure mini-cart listeners exist even when the module loads after add-to-cart.
        if (global.Weline && typeof global.Weline.load === 'function') {
            Promise.resolve(global.Weline.load('miniCartIcon')).then(function () {
                if (retailSiblingPreview && summary) {
                    previewRetailSiblingMiniCart(summary);
                    return;
                }
                if (global.Weline && global.Weline.MiniCart
                    && typeof global.Weline.MiniCart.applyCachedSummary === 'function') {
                    global.Weline.MiniCart.applyCachedSummary();
                }
            }).catch(function () {
                // Mini-cart chrome can still hydrate on next open via local cache.
            });
        }
    }

    async function waitForCartApi() {
        if (global.Weline && global.Weline.Api && typeof global.Weline.Api.resource === 'function') {
            return global.Weline.Api.resource('cart');
        }
        if (global.Weline && typeof global.Weline.use === 'function') {
            await global.Weline.use('api');
        }
        if (!global.Weline || !global.Weline.Api || typeof global.Weline.Api.resource !== 'function') {
            throw new Error('Weline.Api unavailable');
        }
        return global.Weline.Api.resource('cart');
    }

    async function waitForProductApi() {
        if (global.Weline && global.Weline.Api && typeof global.Weline.Api.resource === 'function') {
            return global.Weline.Api.resource('product');
        }
        if (global.Weline && typeof global.Weline.use === 'function') {
            await global.Weline.use('api');
        }
        if (!global.Weline || !global.Weline.Api || typeof global.Weline.Api.resource !== 'function') {
            throw new Error('Weline.Api unavailable');
        }
        return global.Weline.Api.resource('product');
    }

    function unwrapPurchasePanelPayload(result) {
        if (!result || typeof result !== 'object') {
            return null;
        }
        const nested = result.data && typeof result.data === 'object' ? result.data : null;
        if (nested && (nested.html != null || nested.success != null)) {
            return nested;
        }
        return result;
    }

    function isUnknownGuestTokenWorkerParam(errorOrResult) {
        const msg = String(
            (errorOrResult && errorOrResult.message)
            || (errorOrResult && errorOrResult.error)
            || (errorOrResult && errorOrResult.code)
            || ''
        );
        return /Unknown frontend worker param:\s*guest_token/i.test(msg);
    }

    /**
     * Always adopt via issueGuestToken (Cookie authority).
     * Strategy:
     * - Has JS token + WelineCart → force renew first (writes Cookie).
     * - Renew OK → issueGuestToken({}) only (adopt Cookie; never send guest_token).
     * - Renew failed / skipped (no WelineCart) + JS token → issueGuestToken({guest_token});
     *   on 422 Unknown frontend worker param: guest_token → degrade to issueGuestToken({}).
     * - No JS token → issueGuestToken({}) (mint / adopt existing Cookie).
     * No WelineCart: still issues via Api; sessionStorage token uses guest_token path above.
     * Never return a JS-only token without issue; never silent-fake success on empty adopt.
     */
    async function ensureGuestToken() {
        if (!guestTokenPromise) {
            guestTokenPromise = (async function () {
                let existingToken = '';
                if (global.WelineCart && typeof global.WelineCart.getGuestSession === 'function') {
                    const existing = global.WelineCart.getGuestSession();
                    if (existing && existing.token) {
                        existingToken = String(existing.token).trim();
                    }
                }
                if (!existingToken) {
                    try {
                        existingToken = String(global.sessionStorage.getItem(GUEST_TOKEN_STORAGE_KEY) || '').trim();
                    } catch (error) {
                        // Storage can be unavailable in privacy-restricted browser contexts.
                    }
                }

                let cookieSyncedByRenew = false;
                if (existingToken && global.WelineCart && typeof global.WelineCart.renewGuestSession === 'function') {
                    try {
                        await global.WelineCart.renewGuestSession({ force: true });
                        cookieSyncedByRenew = true;
                    } catch (error) {
                        cookieSyncedByRenew = false;
                    }
                }

                const cartApi = await waitForCartApi();
                let result;
                if (cookieSyncedByRenew || !existingToken) {
                    // Cookie should already hold the identity (or no JS token to pass).
                    result = await cartApi.issueGuestToken({}, { silent: true });
                } else {
                    // Renew skipped (no WelineCart) or failed — Cookie may still be empty.
                    try {
                        result = await cartApi.issueGuestToken({ guest_token: existingToken }, { silent: true });
                        if (result && result.success === false && isUnknownGuestTokenWorkerParam(result)) {
                            result = await cartApi.issueGuestToken({}, { silent: true });
                        } else if (result && result.success === false) {
                            throw new Error(String(result.message || 'issueGuestToken_failed'));
                        }
                    } catch (error) {
                        if (isUnknownGuestTokenWorkerParam(error)) {
                            result = await cartApi.issueGuestToken({}, { silent: true });
                        } else {
                            throw error;
                        }
                    }
                }

                if (result && result.success === false) {
                    throw new Error(String(result.message || 'issueGuestToken_failed'));
                }
                const payload = result && result.data && typeof result.data === 'object' ? result.data : result;
                const adopted = String((payload && payload.guest_token) || (result && result.guest_token) || '').trim();
                if (!adopted) {
                    throw new Error('guest_token_unavailable');
                }
                const expiresAt = Number((payload && payload.expires_at_ms) || (Date.now() + 15 * 24 * 3600 * 1000));
                if (global.WelineCart && typeof global.WelineCart.rememberGuestSession === 'function') {
                    global.WelineCart.rememberGuestSession(adopted, expiresAt);
                } else {
                    try {
                        global.sessionStorage.setItem(GUEST_TOKEN_STORAGE_KEY, adopted);
                    } catch (error) {
                        // The active mutation can still use this request-owned token.
                    }
                }
                return adopted;
            })().finally(function () {
                guestTokenPromise = null;
            });
        }

        return guestTokenPromise;
    }

    function readEavSelection(button) {
        const root = detailRoot(button);
        if (!root) {
            return {};
        }

        const selection = {};
        root.querySelectorAll('[data-variant-option].is-selected').forEach(function (option) {
            const axisCode = String(option.dataset.variantAxis || '').trim();
            const axisValue = String(option.dataset.variantValue || '').trim();
            if (axisCode !== '' && axisValue !== '') {
                selection[axisCode] = axisValue;
            }
        });
        const themeId = String(
            button.dataset.promotionThemeId
            || (root && root.getAttribute('data-promotion-theme-id'))
            || ''
        ).trim();
        if (themeId !== '' && themeId !== '0') {
            selection.promotion_theme_id = themeId;
        }

        return Object.keys(selection).sort().reduce(function (normalized, axisCode) {
            normalized[axisCode] = selection[axisCode];
            return normalized;
        }, {});
    }

    /**
     * True when the current product surface allows wholesale add (tob cart).
     * PDP without B2B selling-mode switcher = retail-only → false.
     * Listing cards without an explicit stamp defer to the server (true).
     */
    function productAllowsWholesaleAdd(button) {
        if (button && button.dataset && button.dataset.wholesaleEligible != null
            && String(button.dataset.wholesaleEligible) !== '') {
            return String(button.dataset.wholesaleEligible) === '1';
        }
        var detail = detailRoot(button);
        if (detail) {
            var host = detail.closest('[data-testid="storefront-product-detail"]') || detail;
            var switcher = host.querySelector('[data-b2b-selling-mode="1"]');
            if (!switcher) {
                return false;
            }
            return String(switcher.getAttribute('data-tob-enabled') || '1') !== '0';
        }
        var card = widgetRoot(button);
        if (card) {
            var flag = card.getAttribute('data-wholesale-eligible');
            if (flag !== null && flag !== '') {
                return String(flag) === '1';
            }
            var btnFlag = button && button.getAttribute
                ? button.getAttribute('data-wholesale-eligible')
                : null;
            if (btnFlag !== null && btnFlag !== '') {
                return String(btnFlag) === '1';
            }
        }
        return true;
    }

    /**
     * Resolve add/buy cart_type. Cookie/session preferredMode and button stamps
     * beat FPC SSR html[data-selling-mode] (cached HTML often still says toc).
     * Retail-only products always resolve to toc even when preferredMode is tob.
     */
    function resolveAddCartType(button) {
        if (!productAllowsWholesaleAdd(button)) {
            return 'toc';
        }
        var preferred = '';
        if (window.WelineB2BSellingMode && typeof window.WelineB2BSellingMode.preferredMode === 'function') {
            preferred = String(window.WelineB2BSellingMode.preferredMode() || '').toLowerCase();
        }
        if (preferred !== 'toc' && preferred !== 'tob') {
            try {
                preferred = String(window.sessionStorage.getItem('weline_cart_type_explicit') || '').toLowerCase();
            } catch (eExplicit) {
                preferred = '';
            }
        }
        var fromButton = String(
            (button && (button.dataset.sellingMode || button.dataset.cartType)) || ''
        ).toLowerCase();
        var fromHtml = String(document.documentElement.getAttribute('data-selling-mode') || '').toLowerCase();
        var mode = preferred || fromButton || fromHtml || 'toc';
        return mode === 'tob' ? 'tob' : 'toc';
    }

    function preferredSellingModeHint() {
        var preferred = '';
        if (window.WelineB2BSellingMode && typeof window.WelineB2BSellingMode.preferredMode === 'function') {
            preferred = String(window.WelineB2BSellingMode.preferredMode() || '').toLowerCase();
        }
        if (preferred === 'toc' || preferred === 'tob') {
            return preferred;
        }
        try {
            preferred = String(window.sessionStorage.getItem('weline_cart_type_explicit') || '').toLowerCase();
        } catch (eExplicit) {
            preferred = '';
        }
        if (preferred === 'toc' || preferred === 'tob') {
            return preferred;
        }
        return String(document.documentElement.getAttribute('data-selling-mode') || 'toc').toLowerCase() === 'tob'
            ? 'tob'
            : 'toc';
    }

    function syncChromeAfterRetailOnlyAdd(button, cartType, requestedType) {
        var actual = String(cartType || '').toLowerCase() === 'tob' ? 'tob' : 'toc';
        if (actual !== 'toc') {
            return;
        }
        if (preferredSellingModeHint() !== 'tob') {
            return;
        }
        var requested = String(requestedType || '').toLowerCase() === 'tob' ? 'tob' : 'toc';
        // Retail-only PDP (FE forced toc) or server remapped tob→toc on listing.
        if (productAllowsWholesaleAdd(button) && requested !== 'tob') {
            return;
        }
        // Keep tob preference. Requesting toc here permanently kicked shoppers out of
        // wholesale mode after a retail-only sibling add ("跑到零售车").
        // toc summary is already typed-cached via notifyCartUpdated / rememberSummary.
        void button;
        void requestedType;
    }

    function retailSiblingAddMessage(fallback) {
        var lang = String(
            document.documentElement.lang
            || document.documentElement.getAttribute('data-lang')
            || '',
        ).toLowerCase();
        if (lang.startsWith('zh')) {
            return '该商品不支持批发，已加入零售车。';
        }
        return fallback || 'This item is retail-only and was added to the retail cart.';
    }

    async function addOfferFromButton(button) {
        const sellingMode = resolveAddCartType(button);
        const qtySelect = (detailRoot(button) || document).querySelector('[data-testid="product-qty"]');
        const qtyFromSelect = qtySelect ? Math.max(1, Number(qtySelect.value || 1) || 1) : 0;
        const result = await (await waitForCartApi()).add({
            provider_code: button.dataset.providerCode || 'product',
            global_offer_uuid: button.dataset.globalOfferUuid || '',
            legacy_product_id: Number(button.dataset.productId || 0),
            selection: readEavSelection(button),
            guest_token: await ensureGuestToken(),
            qty: qtyFromSelect > 0 ? qtyFromSelect : Math.max(1, Number(button.dataset.qty || 1) || 1),
            selling_mode: sellingMode,
            cart_type: sellingMode,
        }, { silent: true });
        if (!result || result.success === false) {
            throw new Error(result && result.message ? result.message : 'add_failed');
        }
        return result;
    }

    function showPurchaseLoading(button, message, loadingText) {
        if (message) {
            message.classList.remove('is-error', 'is-success');
            message.classList.add('is-loading');
            message.textContent = loadingText || '';
        }
        button.classList.add('is-loading');
        button.disabled = true;
    }

    function formatStorefrontMoney(amount, currency) {
        const code = String(currency || 'CNY').toUpperCase();
        const value = Number(amount || 0);
        if (!Number.isFinite(value)) {
            return code + ' 0.00';
        }
        return code + ' ' + value.toFixed(2);
    }

    function readCartNoticeLabels() {
        const miniCart = document.querySelector('[data-w-mini-cart="1"]');
        const readAttr = function (name, fallback) {
            if (!miniCart) {
                return fallback;
            }
            const value = String(miniCart.getAttribute(name) || '').trim();
            return value || fallback;
        };
        const lang = String(
            document.documentElement.lang
            || document.documentElement.getAttribute('data-lang')
            || '',
        ).toLowerCase();
        const isZh = lang.startsWith('zh');
        return {
            subtotal: readAttr('data-i18n-subtotal', isZh ? '小计' : 'Subtotal'),
            checkout: readAttr('data-i18n-checkout', isZh ? '去结算' : 'Proceed to checkout'),
            viewCart: readAttr('data-i18n-view-cart', isZh ? '查看购物车' : 'Go to Cart'),
            close: isZh ? '关闭' : 'Close',
        };
    }

    function formatCartItemCountLabel(count, subtotalLabel) {
        const itemCount = Math.max(0, Number(count) || 0);
        const lang = String(
            document.documentElement.lang
            || document.documentElement.getAttribute('data-lang')
            || '',
        ).toLowerCase();
        if (lang.startsWith('zh')) {
            return subtotalLabel + '（' + itemCount + ' 件商品）';
        }
        const itemWord = itemCount === 1 ? 'item' : 'items';
        return subtotalLabel + ' (' + itemCount + ' ' + itemWord + ')';
    }

    function positionCartNoticeHost(host) {
        if (!(host instanceof HTMLElement)) {
            return;
        }
        if (global.Weline
            && global.Weline.ShopperNotice
            && typeof global.Weline.ShopperNotice.positionRegion === 'function') {
            global.Weline.ShopperNotice.positionRegion(host);
            return;
        }
        host.style.position = 'fixed';
        host.style.insetBlockStart = '';
        host.style.insetInlineEnd = '';
        host.style.insetInlineStart = '';
        const noticeWidth = Math.min(22 * 16, Math.max(240, global.innerWidth - 32));
        host.style.width = noticeWidth + 'px';

        const trigger = document.querySelector('[data-mini-cart-trigger]');
        if (!(trigger instanceof HTMLElement)) {
            host.style.top = 'max(1rem, env(safe-area-inset-top))';
            host.style.right = 'max(1rem, env(safe-area-inset-right))';
            host.style.left = 'auto';
            return;
        }

        const rect = trigger.getBoundingClientRect();
        const gap = 8;
        const top = Math.max(8, rect.bottom + gap);
        let right = Math.max(16, global.innerWidth - rect.right);
        if (right + noticeWidth > global.innerWidth - 16) {
            right = Math.max(16, global.innerWidth - 16 - noticeWidth);
        }
        host.style.top = top + 'px';
        host.style.right = right + 'px';
        host.style.left = 'auto';
    }

    function ensureCartNoticeHost() {
        if (global.Weline
            && global.Weline.ShopperNotice
            && typeof global.Weline.ShopperNotice.ensureRegion === 'function') {
            return global.Weline.ShopperNotice.ensureRegion();
        }
        let host = document.querySelector('.w-amz-shopper-notice-region, .w-amz-cart-added-region');
        if (host) {
            return host;
        }
        host = document.createElement('div');
        host.className = 'w-amz-shopper-notice-region w-amz-cart-added-region';
        host.setAttribute('aria-live', 'polite');
        host.setAttribute('aria-atomic', 'false');
        document.body.appendChild(host);
        return host;
    }

    function dismissAmazonCartAddedNotice(panel) {
        if (!panel || !panel.isConnected) {
            return;
        }
        panel.classList.remove('is-visible');
        global.setTimeout(function () {
            if (panel.isConnected) {
                panel.remove();
            }
        }, 220);
    }

    function showAmazonCartAddedNotice(successText, summary, cartMeta) {
        const text = String(successText || '').trim();
        const subtotal = resolveCartSubtotal(summary);
        if (text === '' || !subtotal) {
            return;
        }

        const labels = readCartNoticeLabels();
        const count = Number(
            summary.cart_count != null ? summary.cart_count : summary.item_count,
        ) || 0;
        const cartUrl = cartMeta && cartMeta.url ? cartMeta.url : '/cart';
        const checkoutUrl = '/checkout';
        const host = ensureCartNoticeHost();

        host.querySelectorAll('.w-amz-cart-added').forEach(function (existing) {
            dismissAmazonCartAddedNotice(existing);
        });

        const panel = document.createElement('aside');
        panel.className = 'w-amz-cart-added';
        panel.setAttribute('role', 'status');

        const header = document.createElement('div');
        header.className = 'w-amz-cart-added__header';

        const titleWrap = document.createElement('div');
        titleWrap.className = 'w-amz-cart-added__title-wrap';

        const check = document.createElement('span');
        check.className = 'w-amz-cart-added__check';
        check.setAttribute('aria-hidden', 'true');
        check.textContent = '✓';

        const title = document.createElement('strong');
        title.className = 'w-amz-cart-added__title';
        title.textContent = text;

        titleWrap.append(check, title);

        const close = document.createElement('button');
        close.type = 'button';
        close.className = 'w-amz-cart-added__close';
        close.setAttribute('aria-label', labels.close);
        close.textContent = '×';

        header.append(titleWrap, close);

        const subtotalRow = document.createElement('div');
        subtotalRow.className = 'w-amz-cart-added__subtotal';

        const subtotalLabel = document.createElement('span');
        subtotalLabel.className = 'w-amz-cart-added__subtotal-label';
        subtotalLabel.textContent = formatCartItemCountLabel(count, labels.subtotal) + ':';

        const subtotalAmount = document.createElement('strong');
        subtotalAmount.className = 'w-amz-cart-added__subtotal-amount';
        subtotalAmount.textContent = formatStorefrontMoney(subtotal.amount, subtotal.currency);

        subtotalRow.append(subtotalLabel, subtotalAmount);

        const actions = document.createElement('div');
        actions.className = 'w-amz-cart-added__actions';

        const checkout = document.createElement('a');
        checkout.className = 'w-amz-cart-added__btn w-amz-cart-added__btn--checkout';
        checkout.href = checkoutUrl;
        checkout.textContent = labels.checkout;

        const viewCart = document.createElement('a');
        viewCart.className = 'w-amz-cart-added__btn w-amz-cart-added__btn--cart';
        viewCart.href = cartUrl;
        viewCart.textContent = labels.viewCart;

        actions.append(checkout, viewCart);
        panel.append(header, subtotalRow, actions);
        host.append(panel);
        positionCartNoticeHost(host);

        const dismiss = function () {
            dismissAmazonCartAddedNotice(panel);
        };
        close.addEventListener('click', dismiss);
        global.setTimeout(dismiss, 6200);
        global.requestAnimationFrame(function () {
            positionCartNoticeHost(host);
            panel.classList.add('is-visible');
        });
    }

    function showFloatingToast(message, tone, summary, cartMeta) {
        const text = String(message || '').trim();
        if (text === '') {
            return;
        }
        if (tone === 'success' && summary) {
            showAmazonCartAddedNotice(text, summary, cartMeta);
            return;
        }
        const toast = global.Weline && global.Weline.UI && global.Weline.UI.toast
            ? global.Weline.UI.toast
            : null;
        if (!toast) {
            return;
        }
        const resolvedTone = tone || 'info';
        try {
            if ((resolvedTone === 'error' || resolvedTone === 'danger') && typeof toast.error === 'function') {
                toast.error(text);
                return;
            }
            if (typeof toast.show === 'function') {
                toast.show(text, {
                    tone: resolvedTone === 'error' ? 'danger' : resolvedTone,
                });
            }
        } catch (error) {
            // Theme toast is optional alongside in-place purchase feedback.
        }
    }

    function resolveCartSubtotal(summary) {
        if (!summary || typeof summary !== 'object') {
            return null;
        }
        const currency = String(summary.currency || 'CNY');
        if (summary.subtotal_minor != null) {
            return {
                amount: Number(summary.subtotal_minor) / 100,
                currency: currency,
            };
        }
        if (summary.grand_total_minor != null) {
            return {
                amount: Number(summary.grand_total_minor) / 100,
                currency: currency,
            };
        }
        if (summary.subtotal != null) {
            return {
                amount: Number(summary.subtotal),
                currency: currency,
            };
        }
        if (summary.grand_total != null) {
            return {
                amount: Number(summary.grand_total),
                currency: currency,
            };
        }
        return null;
    }

    function showAddToCartSuccess(message, successText, cartMeta) {
        if (!message) {
            return;
        }
        message.classList.remove('is-error', 'is-loading');
        message.classList.add('is-success');
        message.replaceChildren();
        message.appendChild(document.createTextNode(successText || ''));

        const url = cartMeta && cartMeta.url ? cartMeta.url : '';
        const linkText = cartMeta && cartMeta.text ? cartMeta.text : '';
        if (url === '' || linkText === '') {
            return;
        }

        message.appendChild(document.createTextNode(' '));
        const link = document.createElement('a');
        link.href = url;
        link.className = 'product-native-detail__message-cart-link';
        link.setAttribute('data-testid', 'view-cart');
        link.textContent = linkText;
        message.appendChild(link);
    }

    function appendCheckoutCartTypeHandoff(url, cartType) {
        const next = String(cartType || '').toLowerCase() === 'tob' ? 'tob' : 'toc';
        const raw = String(url || '').trim();
        if (raw === '') {
            return raw;
        }
        try {
            const resolved = new URL(raw, global.location.origin);
            resolved.searchParams.set('cart_type', next);
            return resolved.pathname + resolved.search + resolved.hash;
        } catch (eUrl) {
            const join = raw.indexOf('?') >= 0 ? '&' : '?';
            return raw + join + 'cart_type=' + encodeURIComponent(next);
        }
    }

    function previewRetailSiblingMiniCart(summary) {
        const payload = summary && typeof summary === 'object'
            ? Object.assign({}, summary, { cart_type: 'toc', selling_mode: 'toc', source: 'retail-only-add-preview' })
            : { cart_type: 'toc', selling_mode: 'toc', source: 'retail-only-add-preview' };
        try {
            if (global.WelineB2BSellingMode && typeof global.WelineB2BSellingMode.syncMiniCartChrome === 'function') {
                global.WelineB2BSellingMode.syncMiniCartChrome('toc');
            }
        } catch (eChrome) {}
        global.dispatchEvent(new CustomEvent('weline:cart-updated', {
            detail: payload,
        }));
        global.dispatchEvent(new CustomEvent('weline:cart-type-changed', {
            detail: {
                cart_type: 'toc',
                selling_mode: 'toc',
                source: 'retail-only-add-preview',
                forceNetwork: false,
            },
        }));
        if (global.Weline && typeof global.Weline.load === 'function') {
            Promise.resolve(global.Weline.load('miniCartIcon')).then(function () {
                // Re-paint toc summary after module boot; skip preferred tob cache wipe.
                global.dispatchEvent(new CustomEvent('weline:cart-updated', {
                    detail: payload,
                }));
            }).catch(function () {});
        }
    }

    function readOptions(button) {
        const scope = widgetRoot(button);
        const options = {
            loadingText: scope ? (scope.dataset.purchaseLoading || '') : '',
            successText: scope ? (scope.dataset.purchaseSuccess || '') : '',
            errorText: scope ? (scope.dataset.purchaseError || '') : '',
        };
        if ((button.dataset.action || '') === 'buy-now') {
            options.onSuccess = function (activeButton, _detail, meta) {
                let checkoutUrl = activeButton.dataset.checkoutUrl || '';
                if (!checkoutUrl) {
                    return;
                }
                const handoff = meta && meta.checkoutCartTypeHandoff
                    ? String(meta.checkoutCartTypeHandoff).toLowerCase()
                    : '';
                if (handoff === 'toc' || handoff === 'tob') {
                    checkoutUrl = appendCheckoutCartTypeHandoff(checkoutUrl, handoff);
                }
                global.location.href = checkoutUrl;
            };
        }
        return options;
    }

    /**
     * Purchase-panel HTML is injected after DOMContentLoaded, so Weline's one-shot
     * data-weline-load scan never sees HelpPay CTAs. Load those modules and
     * re-boot helpPayShare so quiet-link CSS + delegated clicks apply.
     */
    function loadInjectedAttributeModules(root) {
        if (!root || !global.Weline || typeof global.Weline.load !== 'function') {
            return Promise.resolve();
        }
        const names = [];
        const seen = Object.create(null);
        root.querySelectorAll('[data-weline-load]').forEach(function (el) {
            String(el.getAttribute('data-weline-load') || '')
                .split(',')
                .map(function (n) { return n.trim(); })
                .filter(Boolean)
                .forEach(function (name) {
                    if (seen[name]) {
                        return;
                    }
                    seen[name] = true;
                    names.push(name);
                });
        });
        if (!names.length) {
            return Promise.resolve();
        }
        return Promise.all(names.map(function (name) {
            return Promise.resolve(global.Weline.load(name)).catch(function () {
                return null;
            });
        })).then(function () {
            try {
                const hp = global.WelineModules && global.WelineModules.helpPayShare;
                if (hp && typeof hp.ensureShareCss === 'function') {
                    hp.ensureShareCss();
                }
                if (hp && typeof hp.boot === 'function') {
                    hp.boot();
                }
            } catch (e) {}
            try {
                // Affiliate boot is one-shot; re-scan panels injected into the dialog.
                const af = global.WelineAffiliateProductShare;
                if (af && typeof af.scan === 'function') {
                    af.scan(root);
                }
            } catch (e) {}
        });
    }

    function purchasePanelIsZh() {
        return String(document.documentElement.lang || '').toLowerCase().startsWith('zh');
    }

    /**
     * Listing pages do not bake product-info @widget.source. Purchase-panel HTML is
     * AJAX-injected, so product-native-detail.css (hero-grid / gallery / buybox) is
     * missing unless we ensure it here — same pattern as HelpPay ensureShareCss.
     */
    const PRODUCT_INFO_ASSET_VER = '20261006-retail-only-hint1';
    const PRODUCT_INFO_CSS = [
        'Weline_Product::css/widgets/product-native-detail.css?v=' + PRODUCT_INFO_ASSET_VER,
        'Weline_Theme::css/widgets/widget-instance-styles.css?v=' + PRODUCT_INFO_ASSET_VER,
    ];
    const PRODUCT_INFO_JS = [
        'Weline_Theme::js/widgets/widget-assets-runtime.js?v=' + PRODUCT_INFO_ASSET_VER,
        'Weline_Product::js/widgets/widget-product-info-0.js?v=' + PRODUCT_INFO_ASSET_VER,
        'Weline_Product::js/widgets/widget-product-info-1.js?v=' + PRODUCT_INFO_ASSET_VER,
        'Weline_Theme::js/widgets/widget-instance-styles.js?v=' + PRODUCT_INFO_ASSET_VER,
    ];

    function resolvePurchasePanelAssetHref(modulePath) {
        const loader = global.Weline && global.Weline.loader;
        if (loader && typeof loader.resolveStaticPath === 'function') {
            const resolved = loader.resolveStaticPath(modulePath);
            if (resolved) {
                return resolved;
            }
        }
        const bare = String(modulePath || '').replace(/^Weline_([^:]+)::/, '/static/Weline/$1/');
        return bare.charAt(0) === '/' ? bare : ('/' + bare);
    }

    function ensurePurchasePanelStylesheet(modulePath) {
        const href = resolvePurchasePanelAssetHref(modulePath);
        if (!href) {
            return Promise.resolve();
        }
        const key = modulePath.split('?')[0];
        const marker = 'data-purchase-panel-css';
        const existing = Array.prototype.find.call(
            document.querySelectorAll('link[' + marker + ']'),
            function (el) { return el.getAttribute(marker) === key; },
        );
        if (existing) {
            return Promise.resolve();
        }
        return new Promise(function (resolve) {
            const link = document.createElement('link');
            link.rel = 'stylesheet';
            link.href = href;
            link.setAttribute(marker, key);
            link.addEventListener('load', function () { resolve(); }, { once: true });
            link.addEventListener('error', function () { resolve(); }, { once: true });
            document.head.appendChild(link);
            // Cap wait so a hung CSS request cannot block the panel forever.
            global.setTimeout(resolve, 2500);
        });
    }

    function ensurePurchasePanelScript(modulePath) {
        const href = resolvePurchasePanelAssetHref(modulePath);
        if (!href) {
            return Promise.resolve();
        }
        const key = modulePath.split('?')[0];
        const marker = 'data-purchase-panel-js';
        const existing = Array.prototype.find.call(
            document.querySelectorAll('script[' + marker + ']'),
            function (el) { return el.getAttribute(marker) === key; },
        );
        if (existing) {
            return Promise.resolve();
        }
        // Already baked on PDP (or another host): skip duplicate fetch.
        const fileHint = key.replace(/^Weline_[^:]+::/, '');
        if (fileHint && document.querySelector('script[src*="' + fileHint.replace(/"/g, '') + '"]')) {
            return Promise.resolve();
        }
        return new Promise(function (resolve) {
            const script = document.createElement('script');
            script.src = href;
            script.async = false;
            script.setAttribute(marker, key);
            script.addEventListener('load', function () { resolve(); }, { once: true });
            script.addEventListener('error', function () { resolve(); }, { once: true });
            document.head.appendChild(script);
            global.setTimeout(resolve, 2500);
        });
    }

    function ensurePurchasePanelProductInfoAssets() {
        // Styles can load in parallel; widget JS must stay ordered (runtime → info-0 → …).
        const cssReady = Promise.all(PRODUCT_INFO_CSS.map(ensurePurchasePanelStylesheet));
        let jsChain = Promise.resolve();
        PRODUCT_INFO_JS.forEach(function (path) {
            jsChain = jsChain.then(function () {
                return ensurePurchasePanelScript(path);
            });
        });
        return Promise.all([cssReady, jsChain]);
    }

    /**
     * Rewrite absolute panel/cart URLs onto the current page origin.
     * Product-card HTML may be reused from Worker :19655 into public :9555 pages;
     * fetching the Worker absolute URL from HTTPS causes Failed to fetch / CSP blocks.
     */
    function resolveSameOriginUrl(raw, fallbackPath) {
        const fallback = String(fallbackPath || '/').trim() || '/';
        const input = String(raw || '').trim() || fallback;
        try {
            const parsed = new URL(input, global.location.origin);
            // Always keep path+query+hash; never keep a foreign host/port from SSR.
            return new URL(parsed.pathname + parsed.search + parsed.hash, global.location.origin);
        } catch (e) {
            try {
                return new URL(fallback, global.location.origin);
            } catch (e2) {
                return null;
            }
        }
    }

    function humanizePurchaseError(error, fallback) {
        const isZh = purchasePanelIsZh();
        const raw = String((error && error.message) || fallback || '').trim();
        const code = String((error && error.code) || '').trim();
        const networkish = /failed to fetch|networkerror|load failed|network request failed|internet connection appears to be offline|fetch aborted|aborted/i
            .test(raw);
        const workerTimeout = code === 'worker_timeout'
            || /worker request timed out/i.test(raw);
        if (networkish || workerTimeout || raw === '') {
            return isZh
                ? '网络异常，无法打开加购面板，请稍后重试'
                : 'Network error. Could not open options. Please try again.';
        }
        if (raw === 'purchase_panel_failed' || raw === 'product_id_required' || raw === 'add_failed') {
            return fallback || (isZh ? '无法打开加购面板' : 'Could not open options.');
        }
        // Hide Theme/runtime internal codes from shoppers (e.g. theme_runtime_consumer_context_required).
        if (/^theme_[a-z0-9_]+$/i.test(raw) || /^theme_[a-z0-9_]+$/i.test(code)) {
            return fallback || (isZh ? '暂时无法打开加购面板，请稍后重试' : 'Options are temporarily unavailable. Please try again.');
        }
        return raw;
    }

    function showPurchasePanelError(body, message) {
        if (!body) {
            return;
        }
        const isZh = purchasePanelIsZh();
        body.textContent = '';
        const wrap = document.createElement('div');
        wrap.className = 'w-product-purchase-panel__error';
        wrap.setAttribute('role', 'alert');
        const p = document.createElement('p');
        p.className = 'w-product-purchase-panel__error-text';
        p.textContent = message || (isZh ? '无法打开加购面板' : 'Could not open options.');
        wrap.appendChild(p);
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'w-button';
        btn.setAttribute('data-variant', 'secondary');
        btn.setAttribute('data-size', 'sm');
        btn.setAttribute('data-purchase-panel-close', '');
        btn.textContent = isZh ? '关闭' : 'Close';
        btn.addEventListener('click', function () {
            closePurchasePanel(document.getElementById('weline-product-purchase-panel-dialog'));
        });
        wrap.appendChild(btn);
        body.appendChild(wrap);
    }

    function purchasePanelUiDialog() {
        return global.Weline && global.Weline.UI && global.Weline.UI.dialog
            ? global.Weline.UI.dialog
            : null;
    }

    /**
     * Close the shared listing purchase panel.
     * Prefer Weline.UI.dialog so native <dialog> close also clears `hidden`
     * (UI sets hidden on close; a bare dialog.close() alone is not enough for reopen).
     */
    function closePurchasePanel(dialog) {
        if (!dialog) {
            return;
        }
        const uiDialog = purchasePanelUiDialog();
        if (uiDialog && typeof uiDialog.close === 'function') {
            uiDialog.close(dialog);
            return;
        }
        if (typeof dialog.close === 'function' && dialog.open) {
            dialog.close();
        }
        dialog.setAttribute('data-state', 'closed');
        dialog.hidden = true;
        dialog.setAttribute('hidden', '');
    }

    /**
     * Reveal the shared purchase panel without leaving a sticky `hidden` attribute.
     * Weline.UI.dialog close() sets hidden on native dialogs; showModal() alone does
     * not clear it, so the second open looks like a no-op (invisible modal + inert page).
     */
    function revealPurchasePanel(dialog) {
        if (!dialog) {
            return;
        }
        // Native <dialog hidden> keeps display:none even after showModal().
        dialog.hidden = false;
        dialog.removeAttribute('hidden');
        dialog.setAttribute('data-state', 'open');
        const uiDialog = purchasePanelUiDialog();
        if (uiDialog && typeof uiDialog.open === 'function' && !dialog.open) {
            uiDialog.open(dialog);
            // UI.open already cleared hidden + showModal; re-assert visibility.
            dialog.hidden = false;
            dialog.removeAttribute('hidden');
            dialog.setAttribute('data-state', 'open');
            return;
        }
        if (typeof dialog.showModal === 'function') {
            if (!dialog.open) {
                dialog.showModal();
            }
        } else {
            dialog.setAttribute('open', 'open');
        }
    }

    async function openPurchasePanel(button) {
        const productId = Number(button.dataset.productId || 0);
        if (productId <= 0) {
            throw new Error('product_id_required');
        }
        let dialog = document.getElementById('weline-product-purchase-panel-dialog');
        if (!dialog) {
            dialog = document.createElement('dialog');
            dialog.id = 'weline-product-purchase-panel-dialog';
            dialog.className = 'w-dialog w-product-purchase-panel';
            dialog.setAttribute('data-w-component', 'dialog');
            dialog.setAttribute('data-state', 'closed');
            dialog.setAttribute('data-size', 'lg');
            dialog.setAttribute('data-w-closable', 'true');
            dialog.setAttribute('data-w-backdrop', 'dismissible');
            dialog.setAttribute('aria-labelledby', 'weline-product-purchase-panel-title');
            dialog.innerHTML = ''
                + '<header class="w-dialog__header">'
                + '<h2 id="weline-product-purchase-panel-title" class="w-dialog__title"></h2>'
                + '<button type="button" class="w-button" data-variant="ghost" data-size="sm" data-purchase-panel-close aria-label="×">×</button>'
                + '</header>'
                + '<div class="w-dialog__body w-product-purchase-panel__body" data-purchase-panel-body></div>';
            document.body.appendChild(dialog);
            dialog.querySelector('[data-purchase-panel-close]')?.addEventListener('click', function () {
                closePurchasePanel(dialog);
            });
            dialog.addEventListener('click', function (event) {
                if (event.target === dialog) {
                    closePurchasePanel(dialog);
                }
            });
            if (global.Weline && global.Weline.UI && typeof global.Weline.UI.mount === 'function') {
                try {
                    global.Weline.UI.mount(dialog);
                } catch (e) {
                    // Fallback reveal/close still work without the UI component.
                }
            }
        }
        const title = dialog.querySelector('#weline-product-purchase-panel-title');
        const body = dialog.querySelector('[data-purchase-panel-body]');
        const isZh = purchasePanelIsZh();
        if (title) {
            title.textContent = isZh ? '选择规格并加购' : 'Choose options';
        }
        if (body) {
            body.innerHTML = '<div class="w-product-purchase-panel__loading">'
                + (isZh ? '加载中…' : 'Loading…')
                + '</div>';
        }
        revealPurchasePanel(dialog);

        const panelParams = { product_id: productId };
        const offerUuid = String(button.dataset.globalOfferUuid || '').trim();
        if (offerUuid) {
            panelParams.offer = offerUuid;
        }

        // Kick CSS/JS ensure in parallel with BinQuery so listing hosts get product-info styles.
        const assetsReady = ensurePurchasePanelProductInfoAssets();

        let payload;
        try {
            const productApi = await waitForProductApi();
            const result = await productApi.getPurchasePanel(panelParams, { silent: true });
            payload = unwrapPurchasePanelPayload(result);
        } catch (networkError) {
            const msg = humanizePurchaseError(
                networkError,
                isZh ? '无法打开加购面板' : 'Could not open options.',
            );
            showPurchasePanelError(body, msg);
            throw new Error(msg);
        }

        if (!payload || payload.success === false || !payload.html) {
            const msg = humanizePurchaseError(
                { message: (payload && payload.message) || '' },
                isZh ? '无法打开加购面板' : 'Could not open options.',
            );
            showPurchasePanelError(body, msg);
            throw new Error(msg);
        }
        try {
            await assetsReady;
        } catch (e) {
            // Styles/scripts are best-effort; panel HTML still usable.
        }
        if (body) {
            body.innerHTML = String(payload.html);
            body.querySelectorAll('script').forEach(function (oldScript) {
                const next = document.createElement('script');
                // Preserve type/nonce so application/json catalogs stay inert.
                Array.from(oldScript.attributes || []).forEach(function (attr) {
                    if (!attr || !attr.name) {
                        return;
                    }
                    try {
                        next.setAttribute(attr.name, attr.value);
                    } catch (e) {}
                });
                const scriptType = String(oldScript.getAttribute('type') || '').toLowerCase();
                const isExecutable = scriptType === ''
                    || scriptType === 'text/javascript'
                    || scriptType === 'application/javascript'
                    || scriptType === 'module';
                if (oldScript.src) {
                    next.src = oldScript.src;
                } else {
                    next.textContent = oldScript.textContent || '';
                }
                oldScript.parentNode.replaceChild(next, oldScript);
                if (!isExecutable) {
                    // Non-JS payloads (e.g. variant catalog JSON) must not run.
                    return;
                }
            });
            bindPurchaseButtons(body);
            await loadInjectedAttributeModules(body);
            try {
                if (global.WelineB2BSellingMode && typeof global.WelineB2BSellingMode.applyIdentity === 'function'
                    && payload.identity && typeof payload.identity === 'object') {
                    global.WelineB2BSellingMode.applyIdentity(payload.identity, body);
                }
            } catch (e) {}
            try {
                if (global.WelineB2BSellingMode && typeof global.WelineB2BSellingMode.bindAll === 'function') {
                    global.WelineB2BSellingMode.bindAll();
                }
            } catch (e) {}
            try {
                if (global.WelineB2BSellingMode && typeof global.WelineB2BSellingMode.applyIdentity === 'function'
                    && payload.identity && typeof payload.identity === 'object') {
                    // Re-apply after bind so syncApplyPanels sees final attrs.
                    global.WelineB2BSellingMode.applyIdentity(payload.identity, body);
                }
            } catch (e) {}
            try {
                global.dispatchEvent(new CustomEvent('weline:selling-mode-changed', {
                    detail: {
                        selling_mode: global.WelineB2BSellingMode && typeof global.WelineB2BSellingMode.preferredMode === 'function'
                            ? global.WelineB2BSellingMode.preferredMode()
                            : 'toc',
                    },
                }));
            } catch (e) {}
        }
        return payload;
    }

    function shouldOpenPurchasePanel(button) {
        if (!button) {
            return false;
        }
        if (String(button.dataset.openPurchasePanel || '') === '1') {
            return true;
        }
        if (String(button.dataset.needsSelection || '') === '1') {
            return true;
        }
        const sellingMode = resolveAddCartType(button);
        // Listing card + wholesale: always open panel so MOQ / ladder prices are explicit.
        return sellingMode === 'tob' && !!widgetRoot(button) && !detailRoot(button);
    }

    function bindPurchaseButton(button, options) {
        if (!button || button.dataset.purchaseBound === '1') {
            return;
        }
        button.dataset.purchaseBound = '1';
        const resolvedOptions = options || readOptions(button);

        button.addEventListener('click', async function (event) {
            const isAddToCart = button.dataset.action === 'add';

            if (button.disabled) {
                return;
            }

            if (shouldOpenPurchasePanel(button) && !detailRoot(button)) {
                if (event) {
                    event.preventDefault();
                    event.stopPropagation();
                    if (typeof event.stopImmediatePropagation === 'function') {
                        event.stopImmediatePropagation();
                    }
                }
                const feedback = purchaseFeedback(button);
                const message = feedback.message;
                showPurchaseLoading(button, message, resolvedOptions.loadingText || '');
                try {
                    await openPurchasePanel(button);
                    button.classList.remove('is-loading');
                    button.disabled = false;
                    if (message) {
                        message.classList.remove('is-error', 'is-loading');
                        message.textContent = '';
                    }
                } catch (error) {
                    button.classList.remove('is-loading');
                    button.disabled = false;
                    const errorText = humanizePurchaseError(
                        error,
                        resolvedOptions.errorText || '',
                    );
                    if (message) {
                        message.classList.remove('is-success', 'is-loading');
                        message.classList.add('is-error');
                        message.textContent = errorText;
                    }
                    if (errorText) {
                        showFloatingToast(errorText, 'error');
                    }
                }
                return;
            }

            const feedback = purchaseFeedback(button);
            const message = feedback.message;

            showPurchaseLoading(button, message, resolvedOptions.loadingText || '');

            try {
                const requestedMode = resolveAddCartType(button);
                const preferredBeforeAdd = preferredSellingModeHint();
                const result = await addOfferFromButton(button);
                button.classList.remove('is-loading');
                button.disabled = false;
                const payload = unwrapCartPayload(result);
                const cartSummary = normalizeCartSummary(payload);
                const actualMode = String(
                    (cartSummary && (cartSummary.cart_type || cartSummary.selling_mode))
                    || '',
                ).toLowerCase() === 'tob' ? 'tob' : 'toc';
                const retailSiblingRemap = preferredBeforeAdd === 'tob' && actualMode === 'toc';
                if (isAddToCart) {
                    notifyCartUpdated(result, { retailSiblingPreview: retailSiblingRemap });
                    syncChromeAfterRetailOnlyAdd(
                        button,
                        actualMode,
                        requestedMode,
                    );
                    let successText = String(
                        (payload && payload.message)
                        || resolvedOptions.successText
                        || '',
                    ).trim();
                    // Wholesale preference + landed in toc: retail-only / remapped sibling.
                    if (retailSiblingRemap) {
                        successText = String(
                            (payload && payload.cart_type_remapped && payload.message)
                            || retailSiblingAddMessage(successText),
                        ).trim();
                    }
                    showAddToCartSuccess(
                        message,
                        successText,
                        readCartLinkMeta(button),
                    );
                    if (feedback.cartLink) {
                        feedback.cartLink.hidden = false;
                    }
                    markCardButtonAdded(button, successText);
                    showFloatingToast(
                        successText,
                        'success',
                        cartSummary,
                        readCartLinkMeta(button),
                    );
                    closePurchasePanel(document.getElementById('weline-product-purchase-panel-dialog'));
                } else if (message) {
                    message.classList.remove('is-error', 'is-loading');
                    message.textContent = resolvedOptions.successText || '';
                }
                if (typeof resolvedOptions.onSuccess === 'function') {
                    const successMeta = {};
                    // Buy-now into toc while preferred tob → hand off checkout cart_type=toc (avoid empty tob).
                    if (!isAddToCart && retailSiblingRemap) {
                        successMeta.checkoutCartTypeHandoff = 'toc';
                    } else if (!isAddToCart && preferredBeforeAdd === 'tob' && !productAllowsWholesaleAdd(button)) {
                        successMeta.checkoutCartTypeHandoff = 'toc';
                    }
                    resolvedOptions.onSuccess(button, detailRoot(button), successMeta);
                }
            } catch (error) {
                button.classList.remove('is-loading');
                button.disabled = false;
                const errorText = humanizePurchaseError(
                    error && error.message && error.message !== 'add_failed'
                        ? error
                        : { message: '' },
                    resolvedOptions.errorText || '',
                );
                if (message) {
                    message.classList.remove('is-success', 'is-loading');
                    message.classList.add('is-error');
                    message.textContent = errorText;
                }
                if (isAddToCart && errorText !== '') {
                    showFloatingToast(errorText, 'error');
                }
            }
        }, true);
    }

    function bindPurchaseButtons(scope) {
        const root = scope && scope.querySelector ? scope : document;
        root.querySelectorAll('[data-action="add"], [data-action="buy-now"]').forEach(function (button) {
            if (!detailRoot(button) && !widgetRoot(button)) {
                return;
            }
            bindPurchaseButton(button);
        });
    }

    function boot() {
        bindPurchaseButtons(document);
        const detail = document.querySelector('[data-testid="storefront-product-detail"]');
        if (!detail || typeof MutationObserver === 'undefined') {
            return;
        }
        const actionsSlot = detail.querySelector('.product-native-detail__actions');
        if (!actionsSlot) {
            return;
        }
        new MutationObserver(function () {
            bindPurchaseButtons(actionsSlot);
        }).observe(actionsSlot, { childList: true, subtree: true });
    }

    global.WelineCartPurchaseActions = {
        bindPurchaseButton: bindPurchaseButton,
        addOfferFromButton: addOfferFromButton,
        bindPurchaseButtons: bindPurchaseButtons,
        showCartAddedNotice: showAmazonCartAddedNotice,
        boot: boot,
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})(window);
