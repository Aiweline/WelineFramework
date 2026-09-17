<?php

declare(strict_types=1);

namespace Weline\Checkout\Test\Unit\View;

use PHPUnit\Framework\TestCase;

/**
 * 结账模块页槽必须进入部件槽目录，否则 Theme default_injections 会被 layout_slot_missing 拦截。
 */
final class CheckoutStorefrontSlotsCatalogContractTest extends TestCase
{
    public function testCheckoutDeclaresStorefrontSlotCatalogForInjectionDiscovery(): void
    {
        $widgets = require dirname(__DIR__, 3) . '/extends/module/Weline_Widget/Weline_Checkout/widget.php';
        self::assertIsArray($widgets);
        self::assertArrayHasKey('checkout-storefront-slots', $widgets);

        $catalog = $widgets['checkout-storefront-slots'];
        self::assertTrue((bool)($catalog['is_container'] ?? false));
        self::assertSame('container', $catalog['type'] ?? null);
        self::assertSame(
            'Weline_Checkout::templates/frontend/widgets/checkout-storefront-slots.phtml',
            $catalog['template'] ?? null
        );

        $slots = $catalog['slots'] ?? [];
        self::assertIsArray($slots);
        self::assertArrayHasKey('checkout-express-payment', $slots);
        self::assertArrayHasKey('checkout-shipping-address', $slots);
        self::assertArrayHasKey('checkout-tax-identity', $slots);
        self::assertArrayHasKey('checkout-summary-discount', $slots);
        self::assertArrayHasKey('checkout-summary-note', $slots);
        self::assertArrayHasKey('checkout-summary-credit', $slots);
        self::assertContains('checkout-express-payment', $slots['checkout-express-payment']['accepts'] ?? []);
        self::assertContains('express-checkout', $slots['checkout-express-payment']['accepts'] ?? []);
        self::assertContains('checkout-shipping-address', $slots['checkout-shipping-address']['accepts'] ?? []);
        self::assertContains('checkout-coupon', $slots['checkout-summary-discount']['accepts'] ?? []);
        self::assertContains('order-notice', $slots['checkout-summary-note']['accepts'] ?? []);
        self::assertContains('b2b-checkout-credit', $slots['checkout-summary-credit']['accepts'] ?? []);

        $template = dirname(__DIR__, 3) . '/view/templates/frontend/widgets/checkout-storefront-slots.phtml';
        self::assertFileExists($template);
        self::assertStringContainsString('discovery-only', (string)file_get_contents($template));
    }
}
