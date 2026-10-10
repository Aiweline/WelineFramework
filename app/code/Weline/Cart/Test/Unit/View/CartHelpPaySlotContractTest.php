<?php

declare(strict_types=1);

namespace Weline\Cart\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class CartHelpPaySlotContractTest extends TestCase
{
    public function testCartDeclaresHelpPaySlotWithoutHardcodedWidget(): void
    {
        $widgetPhp = dirname(__DIR__, 3) . '/extends/module/Weline_Widget/Weline_Cart/widget.php';
        $catalogTpl = 'Weline_Cart::templates/frontend/widgets/cart-storefront-slots.phtml';
        self::assertTrue(\Weline\Widget\Test\Support\SlimWidgetPhpListing::listsTemplate($widgetPhp, $catalogTpl));
        $catalog = (string)file_get_contents(dirname(__DIR__, 3) . '/view/templates/frontend/widgets/cart-storefront-slots.phtml');
        self::assertStringContainsString('"cart-summary-help-pay"', $catalog);

        $tpl = (string) file_get_contents(
            dirname(__DIR__, 3) . '/view/templates/frontend/cart/index.phtml'
        );
        self::assertStringContainsString('id="cart-summary-help-pay"', $tpl);
        self::assertStringNotContainsString('Weline_HelpPay::', $tpl);
        self::assertStringNotContainsString('<w:widget', $tpl);
        $checkoutPos = strpos($tpl, 'data-cart-checkout');
        $helpPos = strpos($tpl, 'id="cart-summary-help-pay"');
        self::assertNotFalse($checkoutPos);
        self::assertNotFalse($helpPos);
        self::assertGreaterThan($checkoutPos, $helpPos, 'help-pay slot must follow 去结算');
    }
}
