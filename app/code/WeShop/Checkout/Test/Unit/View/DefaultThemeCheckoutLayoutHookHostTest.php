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

    public function testCheckoutPageHostsDynamicPaymentHooks(): void
    {
        $template = file_get_contents(__DIR__ . '/../../../../../../design/WeShop/default/frontend/pages/checkout/index.phtml');
        $this->assertIsString($template);
        $this->assertStringContainsString('WeShop_Shipping::frontend::layouts::checkout::methods', $template);
        $this->assertStringContainsString('WeShop_Checkout::frontend::partials::checkout::shipping-methods', $template);
        $this->assertStringContainsString('WeShop_Checkout::frontend::partials::checkout::payment-methods', $template);
        $this->assertStringContainsString('WeShop_Checkout::frontend::partials::checkout::payment-details', $template);
    }
}
