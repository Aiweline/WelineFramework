<?php

declare(strict_types=1);

namespace Weline\Checkout\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class ProductCardBuyNowWidgetContractTest extends TestCase
{
    public function testWidgetRegistrationExposesCardBuyNowTemplate(): void
    {
        $widgetPhp = dirname(__DIR__, 3) . '/extends/module/Weline_Widget/Weline_Checkout/widget.php';
        $tpl = 'Weline_Checkout::templates/frontend/widgets/product-card-buy-now.phtml';
        self::assertTrue(\Weline\Widget\Test\Support\SlimWidgetPhpListing::listsTemplate($widgetPhp, $tpl));
        $src = (string) file_get_contents(dirname(__DIR__, 3) . '/view/templates/frontend/widgets/product-card-buy-now.phtml');
        self::assertStringContainsString('@widget.code {product-card-buy-now}', $src);
        self::assertStringContainsString('@widget.slot {product-card-purchase-actions}', $src);
        self::assertStringContainsString('@widget.page_layouts {["*"]}', $src);
    }

    public function testCardBuyNowWidgetUsesBuyNowActionAndAmazonClasses(): void
    {
        $template = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/templates/frontend/widgets/product-card-buy-now.phtml',
        );
        self::assertStringContainsString('data-testid="product-card-buy-now"', $template);
        self::assertStringContainsString('data-action="buy-now"', $template);
        self::assertStringContainsString('amz-card__buy-now', $template);
        self::assertStringContainsString('weline-checkout-product-card-buy-now', $template);
        self::assertStringContainsString('WidgetI18n::label($labelOverride, \'立即购买\')', $template);
        self::assertStringContainsString('$localize(\'正在前往结账...\')', $template);
    }
}
