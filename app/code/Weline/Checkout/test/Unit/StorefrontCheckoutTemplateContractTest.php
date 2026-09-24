<?php

declare(strict_types=1);

namespace Weline\Checkout\Test\Unit;

use PHPUnit\Framework\TestCase;

final class StorefrontCheckoutTemplateContractTest extends TestCase
{
    public function testCheckoutUsesLocaleAwareSuccessUrl(): void
    {
        $template = $this->read('app/code/Weline/Checkout/view/frontend/checkout/index.phtml');

        self::assertStringContainsString("@url{'checkout/success'}", $template);
        self::assertStringContainsString('const successPageUrl =', $template);
        self::assertStringNotContainsString("window.location.href = '/checkout/success", $template);
        self::assertStringContainsString("successUrl.searchParams.set('checkout_token', checkoutToken);", $template);
        self::assertStringContainsString('weline_checkout_quote_token_w', $template);
        self::assertStringContainsString('quote_token: readStoredQuoteToken()', $template);
        self::assertStringContainsString('adoptQuoteTokenFromUrl', $template);
        self::assertStringContainsString("params.get('quote_token')", $template);
        self::assertStringContainsString('weline:checkout:success', $template);
        self::assertStringNotContainsString('checkout/success-page', $template);
        $controllerRoot = dirname(__DIR__, 6) . '/app/code/Weline/Checkout/Controller';
        self::assertFileExists($controllerRoot . '/Success.php');
        self::assertFileDoesNotExist($controllerRoot . '/SuccessPage.php');
        self::assertStringNotContainsString('function successPage', $this->read('app/code/Weline/Checkout/Controller/Frontend/Checkout.php'));

        $successController = $this->read('app/code/Weline/Checkout/Controller/Success.php');
        self::assertStringContainsString('checkout_payment_cancelled', $successController);
        self::assertStringContainsString('isCancelOutcome', $successController);
        $successTpl = $this->read('app/code/Weline/Checkout/view/frontend/checkout/success.phtml');
        self::assertStringContainsString('paymentCancelled', $successTpl);
        self::assertStringContainsString('支付已取消', $successTpl);
        self::assertStringContainsString('本次支付未完成，订单尚未付款。可继续支付或稍后再试。', $successTpl);
        self::assertStringContainsString('data-testid="checkout-continue-pay"', $successTpl);
        self::assertStringContainsString('继续支付', $successTpl);
        self::assertStringNotContainsString("href=\"@url{'checkout'}\"", $successTpl);
        self::assertStringNotContainsString('已取消成功', $successTpl);
        self::assertStringContainsString('data-payment-outcome="cancel"', $successTpl);
        self::assertStringContainsString('cancel_state', $successTpl);
    }

    public function testCheckoutRendersDurablePaymentRecoveryStateThroughBinQuery(): void
    {
        $template = $this->read('app/code/Weline/Checkout/view/frontend/checkout/index.phtml');

        self::assertStringContainsString('data-checkout-payment-recovery hidden', $template);
        self::assertStringContainsString('data-payment-retry', $template);
        self::assertStringContainsString("api.resumePaymentV2({", $template);
        self::assertStringContainsString("payment.outcome", $template);
        self::assertStringContainsString('state.recoverable = payment.recoverable !== false;', $template);
        self::assertStringContainsString(
            'paymentRetryButton.hidden = pending ? !canContinue : !state.recoverable;',
            $template,
        );
        self::assertStringContainsString("const value = typeof state[key] === 'boolean'", $template);
        self::assertStringContainsString("params.get('recoverable') !== '0'", $template);
        self::assertStringContainsString("'#payment-recovery?'", $template);
        self::assertStringContainsString('window.history.replaceState', $template);
        self::assertStringNotContainsString("fetch('/", $template);
        self::assertStringNotContainsString('fetch("/', $template);
        self::assertStringNotContainsString('XMLHttpRequest', $template);
        self::assertStringNotContainsString('axios', $template);
    }

    public function testCheckoutTotalsUseCompilerSafeDataAttributesAndFallbackValues(): void
    {
        $template = $this->read('app/code/Weline/Checkout/view/frontend/checkout/index.phtml');

        self::assertStringContainsString('<div class="weline-checkout__totals" role="list">', $template);
        self::assertStringContainsString('<strong data-subtotal="">0.00</strong>', $template);
        self::assertStringContainsString('<strong data-shipping-amount="">0.00</strong>', $template);
        self::assertStringContainsString('<strong data-grand-total="">0.00</strong>', $template);
        self::assertStringNotContainsString('<dd', $template);
        self::assertStringContainsString('new Intl.NumberFormat(undefined, {', $template);
        self::assertStringContainsString('minimumFractionDigits: 2', $template);
        self::assertStringContainsString('maximumFractionDigits: 2', $template);
        self::assertStringNotContainsString("Number(amount || 0).toFixed(2)", $template);
    }

    public function testAuthenticatedCheckoutPrefillsThePublishedDefaultDeliveryAddressOnce(): void
    {
        $template = $this->read('app/code/Weline/Checkout/view/frontend/checkout/index.phtml');

        self::assertStringContainsString('let customerAddressPrefilled = false;', $template);
        self::assertStringContainsString('function applyDefaultShippingAddress(address)', $template);
        self::assertStringContainsString('const deliveryAddress = data && data.delivery && typeof data.delivery === \'object\'', $template);
        self::assertStringContainsString("form.querySelector('[name=\"' + field + '\"]')", $template);
        self::assertStringContainsString('applyDefaultShippingAddress(data.default_shipping_address);', $template);
    }

    public function testEmptyCartHidesCheckoutFormAndShowsRecoveryActions(): void
    {
        $template = $this->read('app/code/Weline/Checkout/view/frontend/checkout/index.phtml');

        self::assertStringContainsString("@url{'products'}", $template);
        self::assertStringContainsString('data-checkout-empty hidden', $template);
        self::assertStringContainsString('weline-code="checkout.checkout.empty.section_1"', $template);
        self::assertStringContainsString('data-checkout-form-host hidden', $template);
        self::assertStringContainsString('data-checkout-form hidden', $template);
        self::assertStringContainsString("const emptyState = root.querySelector('[data-checkout-empty]');", $template);
        self::assertStringContainsString('function showCheckoutShell(mode)', $template);
        self::assertStringContainsString('function setFormVisible(visible)', $template);
        self::assertStringContainsString('const hangPurpose = (function () {', $template);
        self::assertStringContainsString("params.get('purpose')", $template);
        self::assertStringContainsString("params.get('order_uuid')", $template);
        self::assertStringContainsString('const cartIsEmpty = Boolean(checkoutState.cart.is_empty);', $template);
        self::assertStringContainsString("showCheckoutShell('empty')", $template);
        self::assertStringContainsString("showCheckoutShell('ready')", $template);
        self::assertStringContainsString('guest_cart_mismatch', $template);
        self::assertStringContainsString('localCartClaimsItems', $template);
        self::assertStringContainsString('guestTokenAligned', $template);
        self::assertStringContainsString('--checkout-text: var(--color-text-primary);', $template);
        self::assertStringContainsString('--checkout-link: var(--color-link);', $template);
        self::assertStringContainsString('--checkout-cta-bg: var(--color-primary);', $template);
        self::assertStringNotContainsString('#2563eb', $template);
        self::assertMatchesRegularExpression(
            '/\.weline-checkout__empty-state h2\s*\{[^}]*color:\s*var\(--checkout-text\)/s',
            $template,
        );
        self::assertMatchesRegularExpression(
            '/\.weline-checkout__empty-state > p:not\(\.weline-checkout__eyebrow\)\s*\{[^}]*color:\s*var\(--checkout-text-secondary\)/s',
            $template,
        );
        self::assertMatchesRegularExpression(
            '/\.weline-checkout__submit\s*\{[^}]*background:\s*var\(--checkout-cta-bg\)/s',
            $template,
        );
    }

    public function testCheckoutAdoptsTheSharedGuestSessionBeforeLoadingCartData(): void
    {
        $template = $this->read('app/code/Weline/Checkout/view/frontend/checkout/index.phtml');
        $ensureGuestToken = strpos($template, 'async function ensureGuestToken()');
        $loadCartModule = strpos($template, "await window.Weline.load('cart')", $ensureGuestToken ?: 0);
        $rereadGuestToken = strpos($template, 'let token = guestToken();', $loadCartModule ?: 0);
        $issueGuestToken = strpos($template, '.issueGuestToken(', $rereadGuestToken ?: 0);
        $rememberSession = strpos($template, 'rememberGuestSession', $issueGuestToken ?: 0);
        $loadCheckoutWithToken = strpos($template, 'guest_token: await ensureGuestToken()', $issueGuestToken ?: 0);

        self::assertStringContainsString('data-weline-load="cart,b2bCheckoutTob,checkoutLifecycle,paymentLifecycle"', $template);
        self::assertIsInt($ensureGuestToken);
        self::assertIsInt($loadCartModule, 'Checkout must load the shared Cart browser session first.');
        self::assertIsInt($rereadGuestToken, 'Checkout must re-read the token after Cart initializes.');
        self::assertIsInt($issueGuestToken, 'Checkout must always adopt the HttpOnly cookie via issueGuestToken.');
        self::assertIsInt($rememberSession, 'Adopted cookie token must be written back to the shared Cart session.');
        self::assertIsInt($loadCheckoutWithToken, 'checkout.getData must receive the recovered guest token.');
        self::assertLessThan($rereadGuestToken, $loadCartModule);
        self::assertLessThan($issueGuestToken, $rereadGuestToken);
        self::assertLessThan($rememberSession, $issueGuestToken);
        self::assertLessThan($loadCheckoutWithToken, $issueGuestToken);
        // Must not short-circuit on a stale sessionStorage token before adopting the cookie.
        self::assertStringContainsString('Cookie is the server-owned guest cart authority', $template);
        self::assertStringNotContainsString(
            "let token = guestToken();\n        if (token) {\n            return token;\n        }",
            $template,
        );
    }

    public function testLoadCartSummaryFallsBackToCookieWhenClientTokenIsEmpty(): void
    {
        $src = $this->read(
            'app/code/Weline/Checkout/extends/module/Weline_Framework/Query/CheckoutQueryProvider.php'
        );
        self::assertStringContainsString('Cookie::get(CartService::GUEST_TOKEN_COOKIE)', $src);
        self::assertStringContainsString('!hash_equals($cookieToken, $guestToken)', $src);
        self::assertStringContainsString('orphan token while the HttpOnly cookie', $src);
    }

    public function testCheckoutShippingAddressUsesSlotInsteadOfNakedRegionInputs(): void
    {
        $template = $this->read('app/code/Weline/Checkout/view/frontend/checkout/index.phtml');

        self::assertStringContainsString('id="checkout-shipping-address"', $template);
        self::assertStringContainsString('class="weline-checkout__shipping-address-slot"', $template);
        self::assertStringContainsString('.weline-checkout__shipping-address-slot', $template);
        self::assertMatchesRegularExpression(
            '/\.weline-checkout__shipping-address-slot\s*\{[^}]*margin-top:\s*16px/s',
            $template,
            'Shipping address slot must match options panel title spacing',
        );
        self::assertStringContainsString("accept=\"checkout-shipping-address,shipping-address,delivery-address,address\"", $template);
        self::assertStringNotContainsString('<input name="country_code"', $template);
        self::assertStringNotContainsString('<input name="province"', $template);
        self::assertStringNotContainsString('<input name="city"', $template);
        self::assertStringNotContainsString('<input name="address1"', $template);
        self::assertStringContainsString("WelineThemeAddress.applyValues('checkout-shipping-address'", $template);
        self::assertStringContainsString("district: text(data.get('district')).trim(),", $template);
    }

    public function testCheckoutSummaryUsesCouponWidgetSlotInsteadOfHookFetch(): void
    {
        $template = $this->read('app/code/Weline/Checkout/view/frontend/checkout/index.phtml');

        self::assertStringContainsString('id="checkout-summary-discount"', $template);
        self::assertStringContainsString('class="weline-checkout__coupon-slot"', $template);
        self::assertStringContainsString('id="checkout-summary-note"', $template);
        self::assertStringContainsString('class="weline-checkout__note-slot"', $template);
        self::assertStringContainsString('weline-checkout__extras', $template);
        self::assertStringContainsString('mini-cart-drawer__extras', $template);
        self::assertStringContainsString('data-weline-load="miniCartExtras"', $template);
        self::assertStringContainsString('discountAmountMajor', $template);
        self::assertStringContainsString('data-checkout-discount-row', $template);
        self::assertStringContainsString('data-checkout-deposit-row', $template);
        self::assertStringContainsString('data-checkout-credit-row', $template);
        self::assertStringContainsString('data-checkout-tax-row', $template);
        self::assertStringContainsString('selectedTaxAmount', $template);
        self::assertStringContainsString('data-grand-total-label', $template);
        self::assertStringContainsString('weline:b2b-credit-changed', $template);
        self::assertStringContainsString('WelineB2BCheckoutTob', $template);
        self::assertStringContainsString("weline:checkout:address-updated", $template);
        self::assertStringContainsString("scheduleReload({ hardOnFailure: false, busyShipping: true })", $template);
        self::assertStringContainsString('setShippingMethodsBusy', $template);
        self::assertStringContainsString('Weline.UI.setBusy', $template);
        self::assertStringContainsString('loading_shipping', $template);
        self::assertStringNotContainsString('<w:widget', $template);
        self::assertMatchesRegularExpression(
            '/\.weline-checkout__totals > \[role="listitem"\]/s',
            $template,
        );
        self::assertStringNotContainsString(
            'Weline_Marketing::templates/frontend/widgets/checkout-coupon.phtml',
            $template,
        );
    }

    public function testCheckoutExpressPaymentSlotSitsInLeftMainBeforeShipping(): void
    {
        $template = $this->read('app/code/Weline/Checkout/view/frontend/checkout/index.phtml');

        self::assertStringContainsString('data-checkout-express-host', $template);
        self::assertStringContainsString('id="checkout-express-payment"', $template);
        self::assertStringContainsString('class="weline-checkout__express-slot"', $template);
        self::assertStringContainsString('weline:checkout:express-pay', $template);
        self::assertStringContainsString('submitCheckoutPayment', $template);
        self::assertStringContainsString('setShellHidden(', $template);
        self::assertStringContainsString('isContinuePayMode()', $template);
        self::assertStringContainsString('function checkoutCartType()', $template);
        self::assertStringContainsString('cart_type: checkoutCartType()', $template);
        self::assertStringNotContainsString('Weline_Payment::templates/Frontend/widgets/checkout-express-payment.phtml', $template);

        $formPos = strpos($template, 'data-checkout-form');
        $mainPos = strpos($template, 'class="weline-checkout__main"');
        $expressPos = strpos($template, 'id="checkout-express-payment"');
        $shippingPos = strpos($template, 'id="checkout-shipping-address"');
        self::assertNotFalse($formPos);
        self::assertNotFalse($mainPos);
        self::assertNotFalse($expressPos);
        self::assertNotFalse($shippingPos);
        self::assertGreaterThan($formPos, $expressPos);
        self::assertGreaterThan($mainPos, $expressPos);
        self::assertGreaterThan($expressPos, $shippingPos);
    }

    public function testSuccessTemplateRendersV2OrderEvidenceAndActions(): void
    {
        $template = $this->read('app/code/Weline/Checkout/view/frontend/checkout/success.phtml');

        self::assertStringContainsString('data-testid="checkout-success"', $template);
        self::assertStringContainsString('class="amz-order-confirm amz-order-confirm--', $template);
        self::assertStringContainsString('checkout-success-page', $template);
        self::assertStringContainsString('amz-order-confirm__banner', $template);
        self::assertStringContainsString('amz-order-confirm__btn--primary', $template);
        self::assertStringContainsString('--amz-btn-primary-bg: var(--color-primary, #b84a3c)', $template);
        self::assertStringContainsString('--amz-success: var(--color-success, #067d62)', $template);
        self::assertStringContainsString('data-order-uuid="<?= $escape($requestOrderUuid) ?>"', $template);
        self::assertStringContainsString(
            'data-checkout-group-uuid="<?= $escape($requestCheckoutGroupUuid) ?>"',
            $template,
        );
        self::assertStringContainsString('weline-code="checkout.checkout.success.section_1"', $template);
        self::assertStringContainsString('weline-pixel::view_order', $template);
        self::assertStringContainsString('weline-pixel::view_orders', $template);
        self::assertStringContainsString('weline-pixel::continue_shopping', $template);
        self::assertStringContainsString('order_v2_display_number', $template);
        self::assertStringContainsString('order_v2_total_label', $template);
        self::assertStringContainsString("'paid' => (string) __('已支付')", $template);
        self::assertStringContainsString("number_format(\$minor / 100, 2, '.', ',')", $template);
        self::assertStringContainsString("@url{'customer/account/index'}", $template);
        self::assertStringContainsString("@url{'customer/account/index'|['order_uuid' => \$requestOrderUuid]}#orders", $template);
        self::assertStringNotContainsString("'#orders?order_uuid='", $template);
        self::assertStringNotContainsString("checkout/frontend/order/view", $template);
        self::assertStringContainsString("@url{'products'}", $template);
        self::assertStringNotContainsString('weline_checkout/frontend/order', $template);
        self::assertStringContainsString('$escape = static fn', $template);
        self::assertStringNotContainsString('$this->escapeHtml(', $template);
        self::assertStringContainsString("__('感谢您的订购！')", $template);
        self::assertStringContainsString("__('您的订单已支付成功，我们正在为您准备发货。')", $template);
        self::assertStringContainsString("__('配送地址')", $template);
        self::assertStringContainsString("__('结账地址')", $template);
        self::assertStringContainsString("__('与配送地址相同')", $template);
        self::assertStringContainsString('data-testid="checkout-success-billing-address"', $template);
        self::assertStringContainsString('billing_address', $template);
        self::assertStringContainsString('amz-order-confirm__meta-grid--with-billing', $template);
        self::assertStringContainsString("__('确认信息将发送至')", $template);
        self::assertStringContainsString('<w:slot id="checkout-success-guest-account"', $template);
        self::assertStringContainsString('weline-code="checkout.success.guest_account"', $template);
        self::assertStringNotContainsString('name="checkout-success-guest-convert"', $template);
        self::assertStringNotContainsString('GuestCheckoutConvertService', $template);
        self::assertStringContainsString(
            'width: min(100%, var(--weline-layout-content-max-width));',
            $template,
        );
        self::assertStringContainsString(
            'padding: var(--weline-space-5, 24px) var(--weline-layout-content-padding-inline) var(--weline-space-8, 48px);',
            $template,
        );
        self::assertStringContainsString('--amz-btn-primary-fg: var(--color-on-primary, #fff)', $template);
        self::assertStringContainsString('weline-pixel::continue_shopping', $template);
        self::assertStringContainsString("aria-label=\"<?= \$escape(__('订单操作')) ?>\"", $template);
        self::assertStringNotContainsString('max-width: 980px', $template);
        self::assertStringNotContainsString(
            'var(--weline-layout-content-max-width, 1040px)',
            $template,
        );
        self::assertStringNotContainsString(
            'var(--weline-layout-content-padding-inline, 16px)',
            $template,
        );

        $controller = $this->read('app/code/Weline/Checkout/Controller/Success.php');
        self::assertStringContainsString("number_format(((int)(\$order->money['grand_total_minor'] ?? 0)) / 100, 2, '.', ',')", $controller);
        self::assertStringContainsString('CheckoutSessionAccessService', $controller);
        self::assertStringContainsString('CheckoutSuccessPresentationService', $controller);
        self::assertStringContainsString('order_v2_items_display', $controller);
        self::assertStringContainsString('shipping_method_label', $controller);
        self::assertStringContainsString("getParam('checkout_token'", $controller);
        self::assertStringContainsString('canAccess(', $controller);
        self::assertStringContainsString('canReadOrder(', $controller);
        self::assertStringContainsString(
            "setHeader('Cache-Control', 'private, no-store, max-age=0, must-revalidate')",
            $controller,
        );

        self::assertStringContainsString('order_v2_items_display', $template);
        self::assertStringContainsString("getData('shipping_method_label')", $template);
        self::assertStringContainsString("\$shipping['method_label']", $template);
        self::assertStringContainsString('__($shippingMethod)', $template);
        self::assertStringNotContainsString(
            "\$shipping['method'] ?? \$shipping['method_label']",
            $template,
        );
    }

    public function testCheckoutOrderControllersUseRealRouterPaths(): void
    {
        $success = $this->read('app/code/Weline/Checkout/Controller/Success.php');
        $legacyCheckout = $this->read('app/code/Weline/Checkout/Controller/Frontend/Checkout.php');
        $orders = $this->read('app/code/Weline/Checkout/Controller/Frontend/Order.php');
        $orderList = $this->read('app/code/Weline/Checkout/view/frontend/order/list.phtml');
        $orderView = $this->read('app/code/Weline/Checkout/view/frontend/order/view.phtml');

        self::assertStringContainsString("'/customer/account/index#orders'", $success);
        self::assertStringContainsString("'/cart'", $success);
        self::assertStringContainsString('isLoggedIn() ? self::ORDER_LIST_PATH : self::CART_PATH', $success);
        self::assertStringNotContainsString('function successPage', $legacyCheckout);
        self::assertStringNotContainsString('checkout/success-page', $legacyCheckout);
        self::assertStringNotContainsString("'/checkout/frontend/order/list'", $orders);
        self::assertStringNotContainsString("'/checkout/frontend/order/view'", $orders);
        self::assertStringContainsString("'checkout/frontend/order/view'", $orderList);
        self::assertStringContainsString("'customer/account/index'", $orderView);
        self::assertStringContainsString("'order_uuid'", $orders);
        self::assertStringNotContainsString('OrderFacadeInterface', $orders);
        self::assertStringNotContainsString('->getItems()', $orderView);
        self::assertStringNotContainsString(
            "weline_checkout/frontend/order/",
            $success . $legacyCheckout . $orders . $orderList . $orderView,
        );
    }

    public function testOrderPagesOwnResponsiveCorePresentation(): void
    {
        $orderList = $this->read('app/code/Weline/Checkout/view/frontend/order/list.phtml');
        $orderView = $this->read('app/code/Weline/Checkout/view/frontend/order/view.phtml');

        self::assertStringContainsString('data-checkout-order-list', $orderList);
        self::assertStringContainsString('.order-list-page {', $orderList);
        self::assertStringContainsString('--checkout-order-text: #111827;', $orderList);
        self::assertStringContainsString('color: var(--checkout-order-text);', $orderList);
        self::assertMatchesRegularExpression('/\.order-list-page h1\s*\{[^}]*color:\s*var\(--checkout-order-text\)/s', $orderList);
        self::assertStringContainsString('@media (max-width: 720px)', $orderList);
        self::assertStringContainsString('data-checkout-order-view', $orderView);
        self::assertStringContainsString('weline-code="checkout.order.view.info"', $orderView);
        self::assertStringContainsString('weline-code="checkout.order.view.items"', $orderView);
        self::assertStringContainsString('weline-code="checkout.order.view.totals"', $orderView);
        self::assertStringContainsString('data-order-table-scroll', $orderView);
        self::assertStringContainsString('.order-view-page {', $orderView);
        self::assertStringContainsString('--checkout-order-text: #111827;', $orderView);
        self::assertStringContainsString('color: var(--checkout-order-text);', $orderView);
        self::assertMatchesRegularExpression('/\.order-view-page h1\s*\{[^}]*color:\s*var\(--checkout-order-text\)/s', $orderView);
        self::assertStringContainsString('@media (max-width: 720px)', $orderView);
    }

    public function testCheckoutHeaderSubtitleIsConfigurableAndDoesNotExposeInternalModuleNames(): void
    {
        $template = $this->read('app/code/Weline/Checkout/view/frontend/checkout/index.phtml');
        $controller = $this->read('app/code/Weline/Checkout/Controller/Frontend/Checkout.php');

        self::assertStringContainsString('$checkoutPageSubtitle = (string)($this->getData(\'checkout_page_subtitle\') ?? \'\');', $template);
        self::assertStringContainsString('checkout_page_subtitle', $controller);
        self::assertStringContainsString('确认收货地址、配送方式和支付信息后即可提交订单。', $controller);
        self::assertStringNotContainsString('Weline 核心模块', $template);
        self::assertStringNotContainsString('Weline 核心模块', $controller);
    }

    public function testCheckoutReloadsSummaryWhenMiniCartMutatesCart(): void
    {
        $template = $this->read('app/code/Weline/Checkout/view/frontend/checkout/index.phtml');

        self::assertStringContainsString("window.addEventListener('weline:cart-updated'", $template);
        self::assertStringContainsString('scheduleReload({ hardOnFailure: true })', $template);
        self::assertStringContainsString("source === 'checkout-empty'", $template);
        self::assertStringContainsString('emptyCartInvalidateDone', $template);
        self::assertStringContainsString("reason: 'checkout-empty'", $template);
        self::assertStringContainsString('window.location.reload()', $template);
        self::assertStringContainsString('pendingDiscountPreview', $template);
        self::assertStringContainsString('rememberPendingDiscount', $template);
        self::assertStringContainsString('applyPendingDiscountToCart', $template);
        self::assertStringContainsString("detail.refresh === false", $template);
        self::assertStringContainsString('getDataParams.coupon_code', $template);
        self::assertStringNotContainsString("loadCheckout().catch(function () {\n            /* keep current totals if refresh fails */", $template);
    }

    public function testCheckoutSurfacesShippingPaymentUnavailableAsBlockingAlert(): void
    {
        $template = $this->read('app/code/Weline/Checkout/view/frontend/checkout/index.phtml');
        $renderer = $this->read('app/code/Weline/Checkout/Service/CheckoutHtmlRenderer.php');

        self::assertStringContainsString('renderMethodEmptyAlert', $renderer);
        self::assertStringContainsString('data-checkout-method-empty', $renderer);
        self::assertStringContainsString('data-tone="warning"', $renderer);
        self::assertStringContainsString('暂无可用配送方式', $renderer);
        self::assertStringContainsString('syncMethodBlockPanels', $template);
        self::assertStringContainsString('readMethodBlockMessage', $template);
        self::assertStringContainsString('is-method-blocked', $template);
        self::assertStringContainsString('weline-checkout__method-alert', $template);
    }

    public function testCheckoutAwaitsShippingValidationIncludingEmbargo(): void
    {
        $template = $this->read('app/code/Weline/Checkout/view/frontend/checkout/index.phtml');

        self::assertStringContainsString('async function validateCheckoutShippingFields', $template);
        self::assertStringContainsString('await widget.validateShippingFields', $template);
        self::assertStringContainsString('skipRequired: !requireFormValidity', $template);
        self::assertStringContainsString("await validateCheckoutShippingFields(address, {", $template);
        self::assertStringContainsString("Prior bug: validateShippingFields is async", $template);
        self::assertStringContainsString("result.reason !== 'shipping_fields'", $template);
        self::assertStringContainsString('Validate shipping/embargo BEFORE flipping the button', $template);
        self::assertStringContainsString('skipRequired: isContinuePayMode()', $template);
    }

    public function testCheckoutEmitsOrderCreatedAndPaymentBridgeOnRecovery(): void
    {
        $template = $this->read('app/code/Weline/Checkout/view/frontend/checkout/index.phtml');
        $success = $this->read('app/code/Weline/Checkout/view/frontend/checkout/success.phtml');
        $moduleRoot = dirname(__DIR__, 2);
        $lifecycle = (string) file_get_contents($moduleRoot . '/view/statics/js/checkout-lifecycle.js');
        $modules = (string) file_get_contents($moduleRoot . '/view/statics/frontend/weline.modules.js');

        self::assertStringContainsString('announceCheckoutOrderCreated', $template);
        self::assertStringContainsString('syncLifecycleDomFields', $template);
        self::assertStringContainsString('data-lifecycle-fields-sig', $template);
        self::assertStringContainsString('weline:checkout:fields-ready', $template);
        self::assertStringContainsString('creditTotalsBusy', $template);
        self::assertStringContainsString('weline:checkout:order-created', $template);
        self::assertStringContainsString('weline:payment:outcome', $template);
        self::assertStringContainsString('checkoutLifecycle,paymentLifecycle', $template);
        self::assertStringContainsString('emitPaymentBridge', $template);
        self::assertStringContainsString('data-checkout-lifecycle', $success);
        self::assertStringContainsString('data-weline-load="cart,checkoutLifecycle,paymentLifecycle"', $success);
        self::assertStringContainsString('weline:checkout:order-created', $lifecycle);
        self::assertStringContainsString('console.info', $lifecycle);
        self::assertStringContainsString('[WelineCheckout]', $lifecycle);
        self::assertStringContainsString('validatePayload', $lifecycle);
        self::assertStringContainsString('weline:checkout:anomaly', $lifecycle);
        self::assertStringContainsString('checkout-lifecycle.js', $modules);
    }

    private function read(string $relativePath): string
    {
        $path = dirname(__DIR__, 6) . '/' . $relativePath;
        self::assertFileExists($path);

        return (string)file_get_contents($path);
    }
}
