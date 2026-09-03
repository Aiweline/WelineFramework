<?php

declare(strict_types=1);

namespace Weline\Customer\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class CheckoutSuccessGuestConvertWidgetContractTest extends TestCase
{
    public function testWidgetRegistersDefaultInjectionIntoCheckoutSuccessSlot(): void
    {
        $widget = require dirname(__DIR__, 3) . '/extends/module/Weline_Widget/Weline_Customer/widget.php';
        self::assertArrayHasKey('checkout-success-guest-convert', $widget);
        $item = $widget['checkout-success-guest-convert'];
        self::assertSame('checkout-success-guest-account', $item['slot']);
        self::assertSame('checkout', $item['default_injections'][0]['layout_type']);
        self::assertTrue($item['default_injections'][0]['required']);
    }

    public function testWidgetHidesWhenOrderAlreadyBound(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/templates/frontend/widgets/checkout-success-guest-convert.phtml'
        );
        self::assertStringContainsString("already_bound", $src);
        self::assertStringContainsString('data-testid="checkout-success-guest-convert"', $src);
        self::assertStringContainsString('data-testid="checkout-success-guest-convert-empty"', $src);
        self::assertStringContainsString('hidden', $src);
        self::assertStringContainsString('WidgetHtmlHealthInspector', $src);
        self::assertStringContainsString('convertGuestCheckout', $src);
        self::assertStringContainsString('GuestCheckoutConvertService', $src);
    }

    public function testCheckoutSuccessExposesGuestAccountSlotWithoutCustomerBusiness(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 4) . '/Checkout/view/frontend/checkout/success.phtml'
        );
        self::assertStringContainsString('<w:slot id="checkout-success-guest-account"', $src);
        self::assertStringContainsString('weline-code="checkout.success.guest_account"', $src);
        self::assertStringContainsString('name="checkout-success-guest-convert"', $src);
        self::assertStringNotContainsString('GuestCheckoutConvertService', $src);
        self::assertStringNotContainsString('登录并保存订单', $src);
    }
}
