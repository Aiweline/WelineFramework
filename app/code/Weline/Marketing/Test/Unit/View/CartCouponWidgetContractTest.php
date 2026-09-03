<?php

declare(strict_types=1);

namespace Weline\Marketing\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class CartCouponWidgetContractTest extends TestCase
{
    public function testWidgetRegistrationPinsCartSummaryDiscountSlot(): void
    {
        $path = dirname(__DIR__, 3) . '/extends/module/Weline_Widget/Weline_Marketing/widget.php';
        self::assertFileExists($path);
        /** @var array<string, mixed> $widgets */
        $widgets = include $path;
        self::assertArrayHasKey('cart-coupon', $widgets);
        $widget = $widgets['cart-coupon'];
        self::assertSame('cart-summary-discount', $widget['slot'] ?? null);
        self::assertSame('cart', $widget['page_layouts'][0] ?? null);
        $injection = $widget['default_injections'][0] ?? [];
        self::assertSame('cart', $injection['layout_type'] ?? null);
        self::assertSame('cart-summary-discount', $injection['slot'] ?? null);
        self::assertTrue((bool)($injection['required'] ?? false));
    }
}
