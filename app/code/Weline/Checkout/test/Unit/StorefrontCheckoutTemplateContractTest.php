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
        self::assertStringContainsString('已取消成功', $successTpl);
        self::assertStringContainsString('已取消', $successTpl);
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
        self::assertStringContainsString('data-checkout-form hidden', $template);
        self::assertStringContainsString("const emptyState = root.querySelector('[data-checkout-empty]');", $template);
        self::assertStringContainsString('const cartIsEmpty = Boolean(checkoutState.cart.is_empty);', $template);
        self::assertStringContainsString('form.hidden = cartIsEmpty;', $template);
        self::assertStringContainsString('emptyState.hidden = !cartIsEmpty;', $template);
        self::assertStringContainsString('--checkout-text: var(--color-text-primary, #0f1111);', $template);
        self::assertStringContainsString('--checkout-link: var(--color-link, #007185);', $template);
        self::assertStringContainsString('--checkout-cta-bg: #ffd814;', $template);
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

    public function testCheckoutShippingAddressUsesSlotInsteadOfNakedRegionInputs(): void
    {
        $template = $this->read('app/code/Weline/Checkout/view/frontend/checkout/index.phtml');

        self::assertStringContainsString('id="checkout-shipping-address"', $template);
        self::assertStringContainsString('class="weline-checkout__shipping-address-slot"', $template);
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
        self::assertStringContainsString('expressHost.hidden = cartIsEmpty', $template);
        self::assertStringNotContainsString('Weline_Payment::templates/frontend/widgets/checkout-express-payment.phtml', $template);

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
        self::assertStringContainsString('--amz-btn-primary-bg: #ffd814', $template);
        self::assertStringContainsString('--amz-success: #067d62', $template);
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
        self::assertStringContainsString('<w:slot id="checkout-success-guest-account"', $template);
        self::assertStringContainsString('weline-code="checkout.success.guest_account"', $template);
        self::assertStringContainsString('name="checkout-success-guest-convert"', $template);
        self::assertStringNotContainsString('GuestCheckoutConvertService', $template);
        self::assertStringContainsString(
            'width: min(100%, var(--weline-layout-content-max-width));',
            $template,
        );
        self::assertStringContainsString(
            'padding: 24px var(--weline-layout-content-padding-inline) 48px;',
            $template,
        );
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
        self::assertStringContainsString("getParam('checkout_token'", $controller);
        self::assertStringContainsString('canAccess(', $controller);
        self::assertStringContainsString('canReadOrder(', $controller);
        self::assertStringContainsString(
            "setHeader('Cache-Control', 'private, no-store, max-age=0, must-revalidate')",
            $controller,
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

    private function read(string $relativePath): string
    {
        $path = dirname(__DIR__, 6) . '/' . $relativePath;
        self::assertFileExists($path);

        return (string)file_get_contents($path);
    }
}
