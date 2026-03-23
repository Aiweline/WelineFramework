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
            $this->assertStringContainsString('WeShop_Shipping::checkout::methods', $template);
            $this->assertStringContainsString('WeShop_Checkout::frontend::layouts::checkout::payment-content', $template);
        }
    }
}
