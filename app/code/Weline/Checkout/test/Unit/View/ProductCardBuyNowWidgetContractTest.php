<?php

declare(strict_types=1);

namespace Weline\Checkout\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class ProductCardBuyNowWidgetContractTest extends TestCase
{
    public function testWidgetRegistrationExposesCardBuyNowTemplate(): void
    {
        $path = dirname(__DIR__, 3) . '/extends/module/Weline_Widget/Weline_Checkout/widget.php';
        self::assertFileExists($path);
        /** @var array<string, mixed> $widgets */
        $widgets = include $path;
        self::assertArrayHasKey('product-card-buy-now', $widgets);
        $widget = $widgets['product-card-buy-now'];
        self::assertSame(
            'Weline_Checkout::templates/frontend/widgets/product-card-buy-now.phtml',
            $widget['template'] ?? null,
        );
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
