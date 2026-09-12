<?php

declare(strict_types=1);

namespace Weline\Cart\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class CartHelpPaySlotContractTest extends TestCase
{
    public function testCartDeclaresHelpPaySlotWithoutHardcodedWidget(): void
    {
        $widgetFile = dirname(__DIR__, 3) . '/extends/module/Weline_Widget/Weline_Cart/widget.php';
        $widgets = require $widgetFile;
        self::assertArrayHasKey('cart-summary-help-pay', $widgets['cart-storefront-slots']['slots']);

        $tpl = (string) file_get_contents(
            dirname(__DIR__, 3) . '/view/templates/frontend/cart/index.phtml'
        );
        self::assertStringContainsString('id="cart-summary-help-pay"', $tpl);
        self::assertStringNotContainsString('Weline_HelpPay::', $tpl);
        self::assertStringNotContainsString('<w:widget', $tpl);
    }
}
