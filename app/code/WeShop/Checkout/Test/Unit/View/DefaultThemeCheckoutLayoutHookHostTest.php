<?php

declare(strict_types=1);

namespace WeShop\Checkout\Test\Unit\View;

use PHPUnit\Framework\TestCase;

class DefaultThemeCheckoutLayoutHookHostTest extends TestCase
{
    public function testCheckoutLayoutVariantsHostShippingAndPaymentHooks(): void
    {
        foreach ([1, 2, 3, 4] as $variant) {
            $template = file_get_contents(__DIR__ . "/../../../../../../design/WeShop/default/frontend/layouts/checkout/checkout_page_{$variant}.phtml");
            $this->assertIsString($template);

            $this->assertStringContainsString('WeShop_Checkout::checkout::shipping_before', $template);
            $this->assertStringContainsString('WeShop_Shipping::frontend::layouts::checkout::methods', $template);
            $this->assertStringContainsString('WeShop_Checkout::frontend::layouts::checkout::payment-content', $template);
            $this->assertStringContainsString('{{content}}', $template);
            $this->assertStringContainsString('{{meta.content}}', $template);
            $this->assertStringContainsString("getUrl('weshop/customer/account/login')", $template);
            $this->assertStringNotContainsString("getUrl('customer/account/login')", $template);
            $this->assertStringContainsString('Checkout (%{1} item)', $template);
            $this->assertStringContainsString('Items (%{1}):', $template);
            $this->assertStringContainsString('By placing your order, you agree to %{1}\\\'s', $template);
            $this->assertStringNotContainsString('Checkout (%1 item)', $template);
            $this->assertStringNotContainsString('Items (%1):', $template);
            $this->assertStringNotContainsString('By placing your order, you agree to %1\\\'s', $template);

            $paymentHostPos = strpos($template, 'WeShop_Checkout::frontend::layouts::checkout::payment-content');
            $contentPos = strpos($template, '{{content}}');
            $metaContentPos = strpos($template, '{{meta.content}}');

            $this->assertIsInt($paymentHostPos);
            $this->assertIsInt($contentPos);
            $this->assertIsInt($metaContentPos);
            $this->assertGreaterThan($contentPos, $paymentHostPos);
            $this->assertGreaterThan($metaContentPos, $paymentHostPos);
        }
    }

    public function testCheckoutSuccessLayoutVariantsLinkBackToCanonicalAccountRoute(): void
    {
        foreach ([1, 2, 3, 4] as $variant) {
            $template = file_get_contents(__DIR__ . "/../../../../../../design/WeShop/default/frontend/layouts/checkout_success/order_confirmation_page_{$variant}.phtml");
            $this->assertIsString($template);
            $this->assertStringContainsString("getUrl('weshop/customer/account/index')", $template);
            $this->assertStringNotContainsString("getUrl('customer/account')", $template);
            $this->assertStringContainsString('Sold by: %{1}', $template);
            $this->assertStringContainsString('Order #%{1}', $template);
            $this->assertStringContainsString('Arriving %{1}', $template);
            $this->assertStringContainsString('Color: %{1}', $template);
            $this->assertStringContainsString('ending in %{1}', $template);
            $this->assertStringContainsString('ENT_QUOTES', $template);
            $this->assertStringNotContainsString('Sold by: %1', $template);
            $this->assertStringNotContainsString('Order #%1', $template);
            $this->assertStringNotContainsString('Arriving %1', $template);
            $this->assertStringNotContainsString('Color: %1', $template);
            $this->assertStringNotContainsString('ending in %1', $template);
        }
    }

    public function testCheckoutPageHostsDynamicPaymentHooks(): void
    {
        $template = file_get_contents(__DIR__ . '/../../../../../../design/WeShop/default/frontend/pages/checkout/index.phtml');
        $this->assertIsString($template);
        $this->assertStringContainsString('Weline_Checkout::frontend::layouts::checkout::shipping-methods-before', $template);
        $this->assertStringContainsString('Weline_Checkout::frontend::partials::checkout::shipping-methods', $template);
        $this->assertStringContainsString('Weline_Checkout::frontend::partials::checkout::payment-methods', $template);
        $this->assertStringContainsString('Weline_Checkout::frontend::partials::checkout::payment-details', $template);
        $this->assertStringContainsString('weshop-checkout-form', $template);
        $this->assertStringContainsString('checkout/success', $template);
        $this->assertStringContainsString('checkout/methods', $template);
        $this->assertStringContainsString('redirect_url', $template);
        $this->assertStringContainsString("name=\"order_id\"", $template);
        $this->assertStringContainsString('selected_shipping_address_id', $template);
        $this->assertStringContainsString('$addressId === $selectedShippingAddressId', $template);
        $this->assertStringContainsString('data-weshop-shipping-method-host', $template);
        $this->assertStringContainsString('data-weshop-payment-method-host', $template);
        $this->assertStringContainsString('data-weshop-payment-detail-host', $template);
        $this->assertStringContainsString('data-weshop-summary-host', $template);
        $this->assertStringContainsString('data-weshop-summary-subtotal', $template);
        $this->assertStringContainsString('data-weshop-summary-shipping', $template);
        $this->assertStringContainsString('data-weshop-summary-tax', $template);
        $this->assertStringContainsString('data-weshop-summary-grand-total', $template);
        $this->assertStringContainsString('data.cart_summary', $template);
        $this->assertStringContainsString('Retry Payment', $template);
        $this->assertStringContainsString('Weline_Checkout::frontend::layouts::checkout::summary-rows-before', $template);
        $this->assertStringContainsString('Weline_Checkout::frontend::layouts::checkout::summary-shipping-before', $template);
        $this->assertStringContainsString('Weline_Checkout::frontend::layouts::checkout::summary-tax-after', $template);
        $this->assertStringContainsString('Weline_Checkout::frontend::layouts::checkout::summary-grand-total-after', $template);
        $this->assertStringContainsString('Qty: %{1}', $template);
        $this->assertStringContainsString('Payment flow: %{1}', $template);
        $this->assertStringNotContainsString('Qty: %1', $template);
        $this->assertStringNotContainsString('Payment flow: %1', $template);
    }

    public function testCheckoutSuccessPageUsesWelineI18nPlaceholders(): void
    {
        $template = file_get_contents(__DIR__ . '/../../../../../../design/WeShop/default/frontend/pages/checkout/success.phtml');
        $this->assertIsString($template);

        $this->assertStringContainsString('Qty: %{1}', $template);
        $this->assertStringContainsString('Card ending in %{1}', $template);
        $this->assertStringNotContainsString('Qty: %1', $template);
        $this->assertStringNotContainsString('Card ending in %1', $template);
    }
}
