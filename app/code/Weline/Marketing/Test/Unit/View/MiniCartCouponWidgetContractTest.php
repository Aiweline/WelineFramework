<?php

declare(strict_types=1);

namespace Weline\Marketing\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class MiniCartCouponWidgetContractTest extends TestCase
{
    public function testWidgetRegistrationPinsMiniCartFooterExtrasSlot(): void
    {
        $widgetPhp = dirname(__DIR__, 3) . '/extends/module/Weline_Widget/Weline_Marketing/widget.php';
        $tpl = 'Weline_Marketing::templates/frontend/widgets/mini-cart-coupon.phtml';
        self::assertTrue(\Weline\Widget\Test\Support\SlimWidgetPhpListing::listsTemplate($widgetPhp, $tpl));
        $src = (string) file_get_contents(dirname(__DIR__, 3) . '/view/templates/frontend/widgets/mini-cart-coupon.phtml');
        self::assertStringContainsString('@widget.code {mini-cart-coupon}', $src);
        self::assertStringContainsString('@widget.slot {footer-extras}', $src);
        self::assertStringContainsString('@widget.page_layouts {["mini-cart"]}', $src);
        self::assertStringContainsString('"layout_type":"mini-cart"', $src);
        self::assertStringContainsString('"slot":"footer-extras"', $src);
        self::assertStringContainsString('"required":true', $src);
    }

    public function testMiniCartCouponTemplateExists(): void
    {
        $path = dirname(__DIR__, 3) . '/view/templates/frontend/widgets/mini-cart-coupon.phtml';
        self::assertFileExists($path);
        $source = (string)file_get_contents($path);
        self::assertStringContainsString('w-marketing-checkout-coupon--mini-cart', $source);
        self::assertStringContainsString('data-marketing-coupon-input', $source);
        self::assertStringContainsString('data-mini-cart-tab-label-source', $source);
        self::assertStringContainsString('data-i18n-invalid-limit=', $source);
        self::assertStringContainsString('data-i18n-enter-code=', $source);
    }
}
