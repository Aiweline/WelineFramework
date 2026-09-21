<?php

declare(strict_types=1);

namespace Weline\Checkout\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class CheckoutTaxIdentitySlotContractTest extends TestCase
{
    public function testIndexAndExpressReviewDeclareTaxIdentitySlot(): void
    {
        $index = (string)file_get_contents(dirname(__DIR__, 3) . '/view/frontend/checkout/index.phtml');
        $express = (string)file_get_contents(dirname(__DIR__, 3) . '/view/frontend/checkout/express-review.phtml');
        self::assertStringContainsString('id="checkout-tax-identity"', $index);
        self::assertStringContainsString('id="checkout-tax-identity"', $express);
        self::assertStringContainsString('accept="checkout-tax-identity,tax-identity,buyer-tax,tax"', $index);
        self::assertStringContainsString('accept="checkout-tax-identity,tax-identity,buyer-tax,tax"', $express);
        self::assertStringContainsString('exclusive="true"', $index);
        self::assertStringContainsString('exclusive="true"', $express);
        self::assertStringContainsString('data-tax-identity-host', $index);
        self::assertStringContainsString('data-tax-identity-host', $express);
        self::assertStringContainsString('weline-checkout__tax-identity-host', $index);
        // Tax host must not use checkout panel chrome (empty bordered card when non-EU).
        self::assertDoesNotMatchRegularExpression(
            '/class="weline-checkout__panel"[^>]*section_tax_identity|section_tax_identity[^>]*weline-checkout__panel/',
            $index
        );
    }

    public function testCatalogListsTaxIdentitySlot(): void
    {
        $widgets = require dirname(__DIR__, 3) . '/extends/module/Weline_Widget/Weline_Checkout/widget.php';
        $slots = $widgets['checkout-storefront-slots']['slots'] ?? [];
        self::assertArrayHasKey('checkout-tax-identity', $slots);
        self::assertContains('tax-identity', $slots['checkout-tax-identity']['accepts'] ?? []);
    }

    public function testCheckoutPagesForbidSoftFallbackFetchOfShippingAndTaxWidgets(): void
    {
        $index = (string)file_get_contents(dirname(__DIR__, 3) . '/view/frontend/checkout/index.phtml');
        $express = (string)file_get_contents(dirname(__DIR__, 3) . '/view/frontend/checkout/express-review.phtml');
        foreach ([$index, $express] as $src) {
            self::assertStringNotContainsString('Soft fallback', $src);
            self::assertStringNotContainsString('SlotRenderer replaces this', $src);
            self::assertStringNotContainsString(
                "fetch('Weline_Shipping::templates/frontend/widgets/checkout-shipping-address.phtml')",
                $src
            );
            self::assertStringNotContainsString(
                "fetch('Weline_Tax::templates/frontend/widgets/checkout-tax-identity.phtml')",
                $src
            );
            self::assertStringNotContainsString(
                'app/code/Weline/Shipping/view/templates/frontend/widgets/checkout-shipping-address.phtml',
                $src
            );
            self::assertStringNotContainsString(
                'app/code/Weline/Tax/view/templates/frontend/widgets/checkout-tax-identity.phtml',
                $src
            );
        }
    }
}
