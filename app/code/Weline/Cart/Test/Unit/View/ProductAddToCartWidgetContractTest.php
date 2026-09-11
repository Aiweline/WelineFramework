<?php

declare(strict_types=1);

namespace Weline\Cart\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class ProductAddToCartWidgetContractTest extends TestCase
{
    public function testWidgetRegistrationPinsPurchaseActionsSlot(): void
    {
        $path = dirname(__DIR__, 3) . '/extends/module/Weline_Widget/Weline_Cart/widget.php';
        self::assertFileExists($path);
        /** @var array<string, mixed> $widgets */
        $widgets = include $path;
        self::assertArrayHasKey('product-add-to-cart', $widgets);
        $widget = $widgets['product-add-to-cart'];
        self::assertSame('product-purchase-actions', $widget['slot'] ?? null);
        self::assertSame(
            'Weline_Cart::templates/frontend/widgets/product-add-to-cart.phtml',
            $widget['template'] ?? null,
        );
        $injection = $widget['default_injections'][0] ?? [];
        self::assertSame('product-purchase-actions', $injection['slot'] ?? null);
        self::assertSame('product', $injection['layout_type'] ?? null);
    }

    public function testWidgetTemplateUsesCartPurchaseActionsScript(): void
    {
        $template = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/templates/frontend/widgets/product-add-to-cart.phtml',
        );
        self::assertStringContainsString('data-testid="product-add-to-cart"', $template);
        self::assertStringContainsString('data-action="add"', $template);
        self::assertStringContainsString('!$quoteOnly', $template);
        self::assertStringContainsString('data-quote-only', $template);
        self::assertStringContainsString('data-weline-load="cart"', $template);
        self::assertStringNotContainsString('@static(Weline_Cart::js/widgets/product-purchase-actions.js)', $template);
        self::assertStringContainsString('data-purchase-loading', $template);
        self::assertStringContainsString('data-purchase-success', $template);
        self::assertStringContainsString('data-cart-url', $template);
        self::assertStringContainsString('data-cart-link-text', $template);
        self::assertStringNotContainsString('product-native-detail__cart', $template);
        self::assertStringNotContainsString('data-purchase-mark-added', $template);
        self::assertStringContainsString('StorefrontOfferResolver::resolve', $template);
    }

    public function testPurchaseActionsScriptDispatchesCartUpdatedEvent(): void
    {
        $script = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/statics/js/widgets/product-purchase-actions.js',
        );
        self::assertStringContainsString('notifyCartUpdated', $script);
        self::assertStringContainsString('weline:cart-updated', $script);
        self::assertStringContainsString('weline:cart:update', $script);
        self::assertStringContainsString('rememberSummary', $script);
        self::assertStringContainsString("load('miniCartIcon')", $script);
        self::assertStringContainsString('applyCachedSummary', $script);
    }

    public function testPurchaseActionsSubmitSelectedEavVariantValues(): void
    {
        $script = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/statics/js/widgets/product-purchase-actions.js',
        );

        self::assertStringContainsString('function readEavSelection(button)', $script);
        self::assertStringContainsString("'[data-variant-option].is-selected'", $script);
        self::assertStringContainsString('selection: readEavSelection(button)', $script);
    }

    public function testAddOfferResolvesCartTypeFromPreferredModeBeforeSsrHtml(): void
    {
        $script = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/statics/js/widgets/product-purchase-actions.js',
        );

        self::assertStringContainsString('function resolveAddCartType(button)', $script);
        self::assertStringContainsString('WelineB2BSellingMode.preferredMode', $script);
        self::assertStringContainsString('weline_cart_type_explicit', $script);
        self::assertStringContainsString('cart_type: sellingMode', $script);
        self::assertStringContainsString('var mode = preferred || fromButton || fromHtml || \'toc\'', $script);
        // Old FPC-first chain must stay gone.
        self::assertStringNotContainsString(
            "button.dataset.sellingMode\n            || button.dataset.cartType\n            || (document.documentElement.getAttribute('data-selling-mode') || '')\n            || (window.WelineB2BSellingMode",
            $script,
        );
    }
}
