<?php

declare(strict_types=1);

namespace Weline\Checkout\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class ProductBuyNowWidgetContractTest extends TestCase
{
    public function testWidgetRegistrationPinsPurchaseActionsSlot(): void
    {
        $path = dirname(__DIR__, 3) . '/extends/module/Weline_Widget/Weline_Checkout/widget.php';
        self::assertFileExists($path);
        /** @var array<string, mixed> $widgets */
        $widgets = include $path;
        self::assertArrayHasKey('product-buy-now', $widgets);
        $widget = $widgets['product-buy-now'];
        self::assertSame('product-purchase-actions', $widget['slot'] ?? null);
        self::assertSame(
            'Weline_Checkout::templates/frontend/widgets/product-buy-now.phtml',
            $widget['template'] ?? null,
        );
        $injection = $widget['default_injections'][0] ?? [];
        self::assertSame('product-purchase-actions', $injection['slot'] ?? null);
        self::assertSame('product', $injection['layout_type'] ?? null);
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
