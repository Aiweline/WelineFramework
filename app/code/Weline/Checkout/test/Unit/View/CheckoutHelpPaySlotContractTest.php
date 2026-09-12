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
    }
}
