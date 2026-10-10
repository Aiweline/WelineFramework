<?php

declare(strict_types=1);

namespace Weline\Checkout\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class ProductBuyNowWidgetContractTest extends TestCase
{
    public function testWidgetRegistrationPinsPurchaseActionsSlot(): void
    {
        $widgetPhp = dirname(__DIR__, 3) . '/extends/module/Weline_Widget/Weline_Checkout/widget.php';
        $tpl = 'Weline_Checkout::templates/frontend/widgets/product-buy-now.phtml';
        self::assertTrue(\Weline\Widget\Test\Support\SlimWidgetPhpListing::listsTemplate($widgetPhp, $tpl));
        $src = (string) file_get_contents(dirname(__DIR__, 3) . '/view/templates/frontend/widgets/product-buy-now.phtml');
        self::assertStringContainsString('@widget.code {product-buy-now}', $src);
        self::assertStringContainsString('@widget.slot {product-purchase-actions}', $src);
        self::assertStringContainsString('"layout_type":"product"', $src);
        self::assertStringContainsString('"slot":"product-purchase-actions"', $src);
        self::assertStringContainsString('"required":true', $src);
    }

    public function testWidgetTemplateRedirectsToCheckoutAfterCartAdd(): void
    {
        $template = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/templates/frontend/widgets/product-buy-now.phtml',
        );
        self::assertStringContainsString('data-testid="product-buy-now"', $template);
        self::assertStringContainsString('data-action="buy-now"', $template);
        self::assertStringContainsString("getUrl('checkout')", $template);
        self::assertStringContainsString('@static(Weline_Cart::js/widgets/product-purchase-actions.js)', $template);
        self::assertStringContainsString('data-purchase-loading', $template);
        self::assertStringContainsString('data-checkout-url', $template);
    }
}
