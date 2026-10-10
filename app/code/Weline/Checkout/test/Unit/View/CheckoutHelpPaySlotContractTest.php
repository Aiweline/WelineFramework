<?php

declare(strict_types=1);

namespace Weline\Checkout\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class CheckoutHelpPaySlotContractTest extends TestCase
{
    public function testCheckoutDeclaresHelpPaySlotWithoutHardcodedWidget(): void
    {
        $widgetPhp = dirname(__DIR__, 3) . '/extends/module/Weline_Widget/Weline_Checkout/widget.php';
        $tpl = 'Weline_Checkout::templates/frontend/widgets/checkout-storefront-slots.phtml';
        self::assertTrue(\Weline\Widget\Test\Support\SlimWidgetPhpListing::listsTemplate($widgetPhp, $tpl));
        $catalog = (string)file_get_contents(dirname(__DIR__, 3) . '/view/templates/frontend/widgets/checkout-storefront-slots.phtml');
        self::assertStringContainsString('"checkout-summary-help-pay"', $catalog);

        $tplPage = (string) file_get_contents(
            dirname(__DIR__, 3) . '/view/frontend/checkout/index.phtml'
        );
        self::assertStringContainsString('id="checkout-summary-help-pay"', $tplPage);
        self::assertStringNotContainsString('Weline_HelpPay::', $tplPage);
        // 帮我付在提交订单下方，不在 extras 页签区
        $extrasPos = strpos($tplPage, 'weline-checkout__extras');
        $submitPos = strpos($tplPage, 'data-submit');
        $helpPos = strpos($tplPage, 'id="checkout-summary-help-pay"');
        self::assertNotFalse($extrasPos);
        self::assertNotFalse($submitPos);
        self::assertNotFalse($helpPos);
        self::assertGreaterThan($submitPos, $helpPos, 'help-pay slot must follow submit button');
        $creditInExtras = strpos($tplPage, 'checkout-summary-credit');
        self::assertNotFalse($creditInExtras);
        self::assertLessThan($helpPos, $creditInExtras);
    }
}
