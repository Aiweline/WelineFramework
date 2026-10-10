<?php

declare(strict_types=1);

namespace Weline\Marketing\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class CheckoutCouponWidgetContractTest extends TestCase
{
    public function testWidgetRegistrationPinsThreeDefaultInjections(): void
    {
        $widgetPhp = dirname(__DIR__, 3) . '/extends/module/Weline_Widget/Weline_Marketing/widget.php';
        $tpl = 'Weline_Marketing::templates/frontend/widgets/checkout-coupon.phtml';
        self::assertTrue(\Weline\Widget\Test\Support\SlimWidgetPhpListing::listsTemplate($widgetPhp, $tpl));
        self::assertFalse(\Weline\Widget\Test\Support\SlimWidgetPhpListing::listsTemplate(
            $widgetPhp,
            'Weline_Marketing::templates/frontend/widgets/cart-coupon.phtml',
        ));
        self::assertFalse(\Weline\Widget\Test\Support\SlimWidgetPhpListing::listsTemplate(
            $widgetPhp,
            'Weline_Marketing::templates/frontend/widgets/mini-cart-coupon.phtml',
        ));

        $src = (string) file_get_contents(dirname(__DIR__, 3) . '/view/templates/frontend/widgets/checkout-coupon.phtml');
        self::assertStringContainsString('@widget.code {checkout-coupon}', $src);
        self::assertStringContainsString('@widget.type {content}', $src);
        self::assertStringContainsString('@widget.page_layouts {["checkout","cart","mini-cart"]}', $src);
        self::assertStringContainsString('"layout_type":"checkout"', $src);
        self::assertStringContainsString('"slot":"checkout-summary-discount"', $src);
        self::assertStringContainsString('"layout_type":"cart"', $src);
        self::assertStringContainsString('"slot":"cart-summary-discount"', $src);
        self::assertStringContainsString('"layout_type":"mini-cart"', $src);
        self::assertStringContainsString('"slot":"footer-extras"', $src);
        self::assertStringContainsString('"compact":true', $src);
        self::assertStringContainsString('"required":true', $src);
    }

    public function testCheckoutCouponWidgetUsesAmazonLayoutClasses(): void
    {
        $template = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/templates/frontend/widgets/checkout-coupon.phtml',
        );
        self::assertStringContainsString('data-testid="checkout-coupon"', $template);
        self::assertStringContainsString('data-widget-code="checkout-coupon"', $template);
        self::assertStringContainsString('data-marketing-checkout-coupon', $template);
        self::assertStringContainsString('w-marketing-checkout-coupon__controls', $template);
        self::assertStringContainsString('w-marketing-checkout-coupon--mini-cart', $template);
        self::assertStringContainsString('@widget.default_injections', $template);
        self::assertStringContainsString('checkout-summary-discount', $template);
        self::assertStringNotContainsString('w-marketing-checkout-coupon__header', $template);
        self::assertStringContainsString('data-marketing-coupon-tags', $template);
        self::assertStringContainsString('data-marketing-coupon-entry', $template);
        self::assertStringContainsString('checkout-coupon.css', $template);
        self::assertStringContainsString('mini-cart-coupon.css', $template);
        self::assertStringContainsString('data-weline-load="checkoutCoupon"', $template);
        self::assertStringContainsString('data-i18n-invalid-limit=', $template);
        self::assertStringContainsString('data-i18n-enter-code=', $template);
        self::assertStringContainsString('data-i18n-loading=', $template);
        self::assertStringContainsString('data-mini-cart-tab-label-source', $template);
        self::assertFileDoesNotExist(dirname(__DIR__, 3) . '/view/templates/frontend/widgets/cart-coupon.phtml');
        self::assertFileDoesNotExist(dirname(__DIR__, 3) . '/view/templates/frontend/widgets/mini-cart-coupon.phtml');
    }
}
