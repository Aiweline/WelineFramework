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
        return {
            url: scope.dataset.cartUrl || '',
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

    function notifyCartUpdated(result) {
        const summary = normalizeCartSummary(unwrapCartPayload(result));
        const count = summary
            ? Number(summary.cart_count != null ? summary.cart_count : summary.item_count)
            : null;

        global.dispatchEvent(new CustomEvent('weline:cart-updated', {
            detail: summary || {},
        }));

        const detail = {
            count: count != null && !Number.isNaN(count) ? count : undefined,
            cart_count: count != null && !Number.isNaN(count) ? count : undefined,
            summary: summary || {},
        };
        global.dispatchEvent(new CustomEvent('weline:cart:update', { detail: detail }));
        global.dispatchEvent(new CustomEvent('weshop:cart:updated', { detail: detail }));
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

    async function ensureGuestToken() {
        if (global.WelineCart && typeof global.WelineCart.getGuestSession === 'function') {
            const existing = global.WelineCart.getGuestSession();
            if (existing && existing.token) {
                if (typeof global.WelineCart.renewGuestSession === 'function') {
                    global.WelineCart.renewGuestSession().catch(function () {});
                }
                return existing.token;
            }
        }

        let guestToken = '';
        try {
            guestToken = String(global.sessionStorage.getItem(GUEST_TOKEN_STORAGE_KEY) || '').trim();
        } catch (error) {
            // Storage can be unavailable in privacy-restricted browser contexts.
        }
        if (guestToken) {
            if (global.WelineCart && typeof global.WelineCart.rememberGuestSession === 'function') {
                global.WelineCart.rememberGuestSession(guestToken);
            }
            return guestToken;
        }

        if (!guestTokenPromise) {
            guestTokenPromise = (async function () {
                const result = await (await waitForCartApi()).issueGuestToken({}, { silent: true });
                const payload = result && result.data && typeof result.data === 'object' ? result.data : result;
                const issued = String((payload && payload.guest_token) || (result && result.guest_token) || '').trim();
                if (!issued) {
                    throw new Error('guest_token_unavailable');
                }
                const expiresAt = Number((payload && payload.expires_at_ms) || (Date.now() + 7 * 24 * 3600 * 1000));
                if (global.WelineCart && typeof global.WelineCart.rememberGuestSession === 'function') {
                    global.WelineCart.rememberGuestSession(issued, expiresAt);
                } else {
                    try {
                        global.sessionStorage.setItem(GUEST_TOKEN_STORAGE_KEY, issued);
                    } catch (error) {
                        // The active mutation can still use this request-owned token.
                    }
                }
                return issued;
            })().finally(function () {
                guestTokenPromise = null;
            });
        }

        return guestTokenPromise;
    }

    async function addOfferFromButton(button) {
        const result = await (await waitForCartApi()).addV2({
            provider_code: button.dataset.providerCode || 'product',
            global_offer_uuid: button.dataset.globalOfferUuid || '',
            legacy_product_id: Number(button.dataset.productId || 0),
            guest_token: await ensureGuestToken(),
            qty: Math.max(1, Number(button.dataset.qty || 1) || 1),
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
        host.style.width = 'min(22rem, calc(100dvw - 2rem))';

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
        const right = Math.max(16, global.innerWidth - rect.right);
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

    function readOptions(button) {
        const scope = widgetRoot(button);
        const options = {
            loadingText: scope ? (scope.dataset.purchaseLoading || '') : '',
            successText: scope ? (scope.dataset.purchaseSuccess || '') : '',
            errorText: scope ? (scope.dataset.purchaseError || '') : '',
        };
        if ((button.dataset.action || '') === 'buy-now') {
            options.onSuccess = function (activeButton) {
                const checkoutUrl = activeButton.dataset.checkoutUrl || '';
                if (checkoutUrl) {
                    global.location.href = checkoutUrl;
                }
            };
        }
        return options;
    }

    function bindPurchaseButton(button, options) {
        if (!button || button.dataset.purchaseBound === '1') {
            return;
        }
        button.dataset.purchaseBound = '1';
        const resolvedOptions = options || readOptions(button);

        button.addEventListener('click', async function () {
            if (button.disabled) {
                return;
            }

            const feedback = purchaseFeedback(button);
            const message = feedback.message;
            const isAddToCart = button.dataset.action === 'add-v2';
            showPurchaseLoading(button, message, resolvedOptions.loadingText || '');

            try {
                const result = await addOfferFromButton(button);
                button.classList.remove('is-loading');
                button.disabled = false;
                if (isAddToCart) {
                    notifyCartUpdated(result);
                    const payload = unwrapCartPayload(result);
                    const cartSummary = normalizeCartSummary(payload);
                    const successText = String(
                        resolvedOptions.successText || (payload && payload.message) || '',
                    ).trim();
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
                } else if (message) {
                    message.classList.remove('is-error', 'is-loading');
                    message.textContent = resolvedOptions.successText || '';
                }
                if (typeof resolvedOptions.onSuccess === 'function') {
                    resolvedOptions.onSuccess(button, detailRoot(button));
                }
            } catch (error) {
                button.classList.remove('is-loading');
                button.disabled = false;
                const errorText = error && error.message && error.message !== 'add_failed'
                    ? error.message
                    : (resolvedOptions.errorText || '');
                if (message) {
                    message.classList.remove('is-success', 'is-loading');
                    message.classList.add('is-error');
                    message.textContent = errorText;
                }
                if (isAddToCart && errorText !== '') {
                    showFloatingToast(errorText, 'error');
                }
            }
        });
    }

    function bindPurchaseButtons(scope) {
        const root = scope && scope.querySelector ? scope : document;
        root.querySelectorAll('[data-action="add-v2"], [data-action="buy-now"]').forEach(function (button) {
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
