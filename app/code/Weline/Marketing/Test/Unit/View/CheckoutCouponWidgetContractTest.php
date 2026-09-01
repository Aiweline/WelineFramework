<?php

declare(strict_types=1);

namespace Weline\Marketing\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class CheckoutCouponWidgetContractTest extends TestCase
{
    public function testWidgetRegistrationPinsCheckoutSummaryDiscountSlot(): void
    {
        $path = dirname(__DIR__, 3) . '/extends/module/Weline_Widget/Weline_Marketing/widget.php';
        self::assertFileExists($path);
        /** @var array<string, mixed> $widgets */
        $widgets = include $path;
        self::assertArrayHasKey('checkout-coupon', $widgets);
        $widget = $widgets['checkout-coupon'];
        self::assertSame('content', $widget['type'] ?? null);
        self::assertSame('checkout-summary-discount', $widget['slot'] ?? null);
        self::assertSame(
            'Weline_Marketing::templates/frontend/widgets/checkout-coupon.phtml',
            $widget['template'] ?? null,
        );
        $injection = $widget['default_injections'][0] ?? [];
        self::assertSame('checkout-summary-discount', $injection['slot'] ?? null);
        self::assertSame('checkout', $injection['layout_type'] ?? null);
    }

    public function testCheckoutCouponWidgetUsesAmazonLayoutClasses(): void
    {
        $template = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/templates/frontend/widgets/checkout-coupon.phtml',
        );
        self::assertStringContainsString('data-testid="marketing-checkout-coupon"', $template);
        self::assertStringContainsString('w-marketing-checkout-coupon__controls', $template);
        self::assertStringContainsString('@widget.default_injections', $template);
        self::assertStringContainsString('checkout-summary-discount', $template);
        self::assertStringNotContainsString('w-marketing-checkout-coupon__header', $template);
        self::assertStringContainsString('data-marketing-coupon-tags', $template);
        self::assertStringContainsString('data-marketing-coupon-entry', $template);
        self::assertStringContainsString('checkout-coupon.js)?v=20260827-coupon-tags2', $template);
    }
}
