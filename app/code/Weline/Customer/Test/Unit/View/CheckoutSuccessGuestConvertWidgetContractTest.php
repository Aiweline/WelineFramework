<?php

declare(strict_types=1);

namespace Weline\Customer\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class CheckoutSuccessGuestConvertWidgetContractTest extends TestCase
{
    public function testWidgetRegistersDefaultInjectionIntoCheckoutSuccessSlot(): void
    {
        $widgetPhp = dirname(__DIR__, 3) . '/extends/module/Weline_Widget/Weline_Customer/widget.php';
        $tpl = 'Weline_Customer::templates/frontend/widgets/checkout-success-guest-convert.phtml';
        self::assertTrue(\Weline\Widget\Test\Support\SlimWidgetPhpListing::listsTemplate($widgetPhp, $tpl));
        $src = (string) file_get_contents(dirname(__DIR__, 3) . '/view/templates/frontend/widgets/checkout-success-guest-convert.phtml');
        self::assertStringContainsString('@widget.code {checkout-success-guest-convert}', $src);
        self::assertStringContainsString('@widget.slot {checkout-success-guest-account}', $src);
        self::assertStringContainsString('"layout_type":"checkout"', $src);
        self::assertStringContainsString('"slot":"checkout-success-guest-account"', $src);
        self::assertStringContainsString('"required":true', $src);
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
        self::assertStringNotContainsString('name="checkout-success-guest-convert"', $src);
        self::assertStringNotContainsString('<w:widget type="content" name="checkout-success-guest-convert"', $src);
        self::assertStringContainsString('Do not nest <w:widget>', $src);
        self::assertStringNotContainsString('GuestCheckoutConvertService', $src);
        self::assertStringNotContainsString('登录并保存订单', $src);
    }
}
