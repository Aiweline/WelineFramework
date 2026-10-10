<?php

declare(strict_types=1);

namespace Weline\Marketing\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class CartCouponWidgetContractTest extends TestCase
{
    public function testWidgetRegistrationPinsCartSummaryDiscountSlot(): void
    {
        $widgetPhp = dirname(__DIR__, 3) . '/extends/module/Weline_Widget/Weline_Marketing/widget.php';
        $tpl = 'Weline_Marketing::templates/frontend/widgets/cart-coupon.phtml';
        self::assertTrue(\Weline\Widget\Test\Support\SlimWidgetPhpListing::listsTemplate($widgetPhp, $tpl));
        $src = (string) file_get_contents(dirname(__DIR__, 3) . '/view/templates/frontend/widgets/cart-coupon.phtml');
        self::assertStringContainsString('@widget.code {cart-coupon}', $src);
        self::assertStringContainsString('@widget.slot {cart-summary-discount}', $src);
        self::assertStringContainsString('@widget.page_layouts {["cart"]}', $src);
        self::assertStringContainsString('"slot":"cart-summary-discount"', $src);
        self::assertStringContainsString('"layout_type":"cart"', $src);
        self::assertStringContainsString('"required":true', $src);
    }
}
