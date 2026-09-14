<?php

declare(strict_types=1);

namespace Weline\Checkout\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class CheckoutHelpPaySlotContractTest extends TestCase
{
    public function testCheckoutDeclaresHelpPaySlotWithoutHardcodedWidget(): void
    {
        $widgetFile = dirname(__DIR__, 3) . '/extends/module/Weline_Widget/Weline_Checkout/widget.php';
        $widgets = require $widgetFile;
        self::assertArrayHasKey(
            'checkout-summary-help-pay',
            $widgets['checkout-storefront-slots']['slots']
        );

        $tpl = (string) file_get_contents(
            dirname(__DIR__, 3) . '/view/frontend/checkout/index.phtml'
        );
        self::assertStringContainsString('id="checkout-summary-help-pay"', $tpl);
        self::assertStringNotContainsString('Weline_HelpPay::', $tpl);
        // 帮我付在提交订单下方，不在 extras 页签区
        $extrasPos = strpos($tpl, 'weline-checkout__extras');
        $submitPos = strpos($tpl, 'data-submit');
        $helpPos = strpos($tpl, 'id="checkout-summary-help-pay"');
        self::assertNotFalse($extrasPos);
        self::assertNotFalse($submitPos);
        self::assertNotFalse($helpPos);
        self::assertGreaterThan($submitPos, $helpPos, 'help-pay slot must follow submit button');
        $extrasEnd = strpos($tpl, '</div>', $extrasPos);
        // slot must not sit inside the first extras block that still contains credit slot
        $creditInExtras = strpos($tpl, 'checkout-summary-credit');
        self::assertNotFalse($creditInExtras);
        self::assertLessThan($helpPos, $creditInExtras);
    }
}
