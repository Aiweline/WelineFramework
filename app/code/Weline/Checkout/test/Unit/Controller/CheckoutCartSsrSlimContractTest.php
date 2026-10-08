<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Checkout first paint SSR: items / shipping / payment via CheckoutStorefrontSsrService.
 */
final class CheckoutCartSsrSlimContractTest extends TestCase
{
    public function testCheckoutIndexAssignsStorefrontSsrPayload(): void
    {
        $root = dirname(__DIR__, 3) . '/Controller';
        foreach (['/Index.php', '/Frontend/Checkout.php'] as $rel) {
            $src = (string)file_get_contents($root . $rel);
            self::assertStringContainsString('CheckoutStorefrontSsrService', $src, $rel);
            self::assertStringContainsString('checkout_shipping_methods_html', $src, $rel);
            self::assertStringContainsString('checkout_payment_methods_html', $src, $rel);
            self::assertStringContainsString('checkout_ssr_ready', $src, $rel);
            self::assertStringContainsString("'showHeader' => true", $src, $rel);
            self::assertStringContainsString("'showFooter' => true", $src, $rel);
            self::assertStringNotContainsString('SSR empty shell', $src, $rel);
            self::assertStringNotContainsString('Empty SSR payload', $src, $rel);
        }
    }

    public function testCheckoutLayoutKeepsEmptyTrustAndBottomSlotMarkers(): void
    {
        $layout = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/theme/frontend/layouts/checkout/default.phtml'
        );
        self::assertStringContainsString('id="checkout-trust"', $layout);
        self::assertStringContainsString('id="checkout-bottom"', $layout);
        self::assertStringContainsString('id="checkout-content"', $layout);
        self::assertStringContainsString('Empty-shell slots kept', $layout);
        self::assertStringNotContainsString('<w:widget', $layout);
    }

    public function testCurrentCartSkipsLegacySummaryOnGetCartSuccess(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/CheckoutPageViewModel.php'
        );
        self::assertStringContainsString('skip legacy summary', $src);
        self::assertMatchesRegularExpression(
            '/w_query\(\'cart\',\s*\'getCart\'[\s\S]*?return \$this->fromQueryResult\(\$v2Result\);/',
            $src
        );
    }

    public function testStorefrontSsrServiceCallsCheckoutGetData(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/CheckoutStorefrontSsrService.php'
        );
        self::assertStringContainsString("w_query('checkout', 'getData'", $src);
        self::assertStringContainsString('fromCartOnly', $src);
        self::assertStringContainsString('shipping_methods_html', $src);
        self::assertStringContainsString('payment_methods_html', $src);
        self::assertStringContainsString("'tax_estimate'", $src);
    }

    public function testCheckoutTemplatePaintsSsrMethodsAndSkipsBootHydrate(): void
    {
        $template = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/frontend/checkout/index.phtml'
        );
        self::assertStringContainsString('checkout_shipping_methods_html', $template);
        self::assertStringContainsString('checkout_payment_methods_html', $template);
        self::assertStringContainsString('data-checkout-ssr-ready', $template);
        self::assertStringContainsString("ssrReady = !!(root && root.getAttribute('data-checkout-ssr-ready') === '1')", $template);
        self::assertStringContainsString('checkout_ssr_goods_text', $template);
        self::assertStringContainsString('$ssrExpressVisible', $template);
        self::assertStringContainsString('syncExpressHostVisibility', $template);
        self::assertStringContainsString('syncExpressHostVisibility({ cartIsEmpty: false })', $template);
        self::assertStringContainsString('data-checkout-ssr-boot', $template);
        self::assertStringContainsString('hydrateCheckoutStateFromSsr', $template);
        self::assertStringContainsString('normalizeCartMoney', $template);
        self::assertStringContainsString('checkout_ssr_tax_estimate', $template);
        self::assertStringContainsString('boot.tax_estimate', $template);
        // SSR ready 跳过 getData：boot 必须带支付/配送扁字段，否则激励徽章有、应付不扣。
        self::assertStringContainsString('checkout_ssr_payment_methods', $template);
        self::assertStringContainsString('checkout_ssr_shipping_methods', $template);
        self::assertStringContainsString("'payment_methods' => \$ssrPaymentMethods", $template);
        self::assertStringContainsString('boot.payment_methods', $template);
        self::assertStringContainsString('boot.shipping_methods', $template);
        self::assertStringContainsString('data-incentive-savings-minor', $template);
    }

    public function testStorefrontSsrPayloadIncludesPaymentMethodsForSkipHydrate(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/CheckoutStorefrontSsrService.php'
        );
        self::assertStringContainsString("'payment_methods' => \$paymentMethods", $src);
        self::assertStringContainsString("'shipping_methods' => \$shippingMethods", $src);
        self::assertStringContainsString('function defaultPaymentMoneyDelta', $src);
        self::assertStringContainsString('incentive_savings_minor', $src);
        self::assertStringContainsString('array_merge($cart', $src);
        self::assertStringNotContainsString("'cart' => \$cart + [", $src);

        foreach (['/Index.php', '/Frontend/Checkout.php'] as $rel) {
            $controller = (string)file_get_contents(dirname(__DIR__, 3) . '/Controller' . $rel);
            self::assertStringContainsString('checkout_ssr_payment_methods', $controller, $rel);
            self::assertStringContainsString('checkout_ssr_shipping_methods', $controller, $rel);
        }
    }

    public function testStorefrontSsrServiceNormalizesMinorMoney(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/CheckoutStorefrontSsrService.php'
        );
        self::assertStringContainsString('function majorAmount', $src);
        self::assertStringContainsString('subtotal_minor', $src);
        self::assertStringContainsString('amount_minor', $src);
    }
}
