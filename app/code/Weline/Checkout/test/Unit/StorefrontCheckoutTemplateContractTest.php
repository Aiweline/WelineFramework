<?php

declare(strict_types=1);

namespace Weline\Checkout\Test\Unit;

use PHPUnit\Framework\TestCase;

final class StorefrontCheckoutTemplateContractTest extends TestCase
{
    public function testCheckoutUsesLocaleAwareSuccessUrl(): void
    {
        $template = $this->read('app/code/Weline/Checkout/view/frontend/checkout/index.phtml');

        self::assertStringContainsString("\$this->getUrl('checkout/success-page')", $template);
        self::assertStringContainsString('const successPageUrl =', $template);
        self::assertStringNotContainsString("window.location.href = '/checkout/success-page", $template);
        self::assertStringContainsString("successUrl.searchParams.set('checkout_token', checkoutToken);", $template);
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
        self::assertStringContainsString("form.querySelector('[name=\"' + field + '\"]')", $template);
        self::assertStringContainsString('applyDefaultShippingAddress(data.default_shipping_address);', $template);
    }

    public function testEmptyCartHidesCheckoutFormAndShowsRecoveryActions(): void
    {
        $template = $this->read('app/code/Weline/Checkout/view/frontend/checkout/index.phtml');

        self::assertStringContainsString("\$this->getUrl('products')", $template);
        self::assertStringContainsString('data-checkout-empty hidden', $template);
        self::assertStringContainsString('weline-code="checkout.checkout.empty.section_1"', $template);
        self::assertStringContainsString('data-checkout-form hidden', $template);
        self::assertStringContainsString("const emptyState = root.querySelector('[data-checkout-empty]');", $template);
        self::assertStringContainsString('const cartIsEmpty = Boolean(checkoutState.cart.is_empty);', $template);
        self::assertStringContainsString('form.hidden = cartIsEmpty;', $template);
        self::assertStringContainsString('emptyState.hidden = !cartIsEmpty;', $template);
        self::assertMatchesRegularExpression(
            '/\.weline-checkout__empty-state h2\s*\{[^}]*color:\s*var\(--weline-layout-text-primary/s',
            $template,
        );
        self::assertMatchesRegularExpression(
            '/\.weline-checkout__empty-state > p:not\(\.weline-checkout__eyebrow\)\s*\{[^}]*color:\s*var\(--weline-layout-text-primary[^}]*opacity:/s',
            $template,
        );
    }

    public function testSuccessTemplateRendersV2OrderEvidenceAndActions(): void
    {
        $template = $this->read('app/code/Weline/Checkout/view/frontend/checkout/success.phtml');

        self::assertStringContainsString('data-testid="checkout-success"', $template);
        self::assertStringContainsString('<section class="checkout-success-page"', $template);
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
        self::assertStringContainsString("number_format(((int)\$money['grand_total_minor']) / 100, 2, '.', ',')", $template);
        self::assertStringContainsString("\$this->getUrl('customer/account/index')", $template);
        self::assertStringContainsString(
            "\$this->getUrl('customer/account/index', ['order_uuid' => \$requestOrderUuid]) . '#orders'",
            $template,
        );
        self::assertStringNotContainsString("'#orders?order_uuid='", $template);
        self::assertStringNotContainsString("\$this->getUrl('checkout/frontend/order/view'", $template);
        self::assertStringContainsString("\$this->getUrl('products')", $template);
        self::assertStringNotContainsString('weline_checkout/frontend/order', $template);
        self::assertStringContainsString('$escape = static fn', $template);
        self::assertStringNotContainsString('$this->escapeHtml(', $template);
        self::assertStringContainsString('background: var(--weline-layout-surface-primary', $template);
        self::assertStringContainsString('color: var(--weline-layout-text-primary', $template);

        $controller = $this->read('app/code/Weline/Checkout/Controller/SuccessPage.php');
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
        $success = $this->read('app/code/Weline/Checkout/Controller/SuccessPage.php');
        $legacyCheckout = $this->read('app/code/Weline/Checkout/Controller/Frontend/Checkout.php');
        $orders = $this->read('app/code/Weline/Checkout/Controller/Frontend/Order.php');
        $orderList = $this->read('app/code/Weline/Checkout/view/frontend/order/list.phtml');
        $orderView = $this->read('app/code/Weline/Checkout/view/frontend/order/view.phtml');

        self::assertStringContainsString("'/customer/account/index#orders'", $success);
        self::assertStringContainsString("'/checkout/frontend/order/list'", $legacyCheckout);
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
