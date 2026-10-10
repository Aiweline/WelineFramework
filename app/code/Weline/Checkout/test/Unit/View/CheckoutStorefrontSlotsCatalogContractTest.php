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
        $widgetPhp = dirname(__DIR__, 3) . '/extends/module/Weline_Widget/Weline_Checkout/widget.php';
        $tpl = 'Weline_Checkout::templates/frontend/widgets/checkout-storefront-slots.phtml';
        self::assertTrue(\Weline\Widget\Test\Support\SlimWidgetPhpListing::listsTemplate($widgetPhp, $tpl));

        $template = dirname(__DIR__, 3) . '/view/templates/frontend/widgets/checkout-storefront-slots.phtml';
        self::assertFileExists($template);
        $src = (string)file_get_contents($template);
        self::assertStringContainsString('discovery-only', $src);
        self::assertStringContainsString('@widget.code {checkout-storefront-slots}', $src);
        self::assertStringContainsString('@widget.type {container}', $src);
        self::assertStringContainsString('@widget.is_container {true}', $src);
        self::assertStringContainsString('"checkout-express-payment"', $src);
        self::assertStringContainsString('"checkout-shipping-address"', $src);
        self::assertStringContainsString('"checkout-tax-identity"', $src);
        self::assertStringContainsString('"checkout-summary-discount"', $src);
        self::assertStringContainsString('"checkout-summary-note"', $src);
        self::assertStringContainsString('"checkout-summary-credit"', $src);
        self::assertStringContainsString('express-checkout', $src);
        self::assertStringContainsString('checkout-coupon', $src);
        self::assertStringContainsString('order-notice', $src);
        self::assertStringContainsString('b2b-checkout-credit', $src);
    }
}
