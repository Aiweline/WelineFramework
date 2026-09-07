<?php

declare(strict_types=1);

namespace Weline\Marketing\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class MiniCartCouponWidgetContractTest extends TestCase
{
    public function testWidgetRegistrationPinsMiniCartFooterExtrasSlot(): void
    {
        $path = dirname(__DIR__, 3) . '/extends/module/Weline_Widget/Weline_Marketing/widget.php';
        self::assertFileExists($path);
        /** @var array<string, mixed> $widgets */
        $widgets = include $path;
        self::assertArrayHasKey('mini-cart-coupon', $widgets);
        $widget = $widgets['mini-cart-coupon'];
        self::assertSame('footer-extras', $widget['slot'] ?? null);
        self::assertSame('mini-cart', $widget['page_layouts'][0] ?? null);
        $injection = $widget['default_injections'][0] ?? [];
        self::assertSame('mini-cart', $injection['layout_type'] ?? null);
        self::assertTrue((bool)($injection['required'] ?? false));
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
