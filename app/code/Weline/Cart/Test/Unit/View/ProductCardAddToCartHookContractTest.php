<?php

declare(strict_types=1);

namespace Weline\Cart\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class ProductCardAddToCartHookContractTest extends TestCase
{
    public function testThemePartialUsesProductCardPurchaseHooks(): void
    {
        $partial = dirname(__DIR__, 4)
            . '/Theme/view/theme/frontend/partials/product/add-to-cart.phtml';
        self::assertFileExists($partial);
        $content = (string)file_get_contents($partial);
        self::assertStringContainsString(
            "getHook('Weline_Theme::frontend::partials::product-card::add-to-cart')",
            $content,
        );
        self::assertStringContainsString(
            "getHook('Weline_Theme::frontend::partials::product-card::buy-now')",
            $content,
        );
        self::assertStringNotContainsString(
            'Weline_Cart::templates/frontend/widgets/product-card-add-to-cart.phtml',
            $content,
        );
        self::assertStringNotContainsString(
            'Weline_Checkout::templates/frontend/widgets/product-card-buy-now.phtml',
            $content,
        );
        self::assertStringNotContainsString('product-purchase-actions.js', $content);
        self::assertStringContainsString('data-weline-load="cart"', $content);
    }

    public function testCartHookRendersCardWidgetTemplate(): void
    {
        $hook = dirname(__DIR__, 3)
            . '/view/hooks/Weline_Theme/frontend/partials/product-card/add-to-cart.phtml';
        self::assertFileExists($hook);
        $content = (string)file_get_contents($hook);
        self::assertStringContainsString(
            'Weline_Cart::templates/frontend/widgets/product-card-add-to-cart.phtml',
            $content,
        );
        self::assertStringNotContainsString('product-purchase-actions.js', $content);
    }

    public function testCardWidgetUsesCartAddAction(): void
    {
        $template = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/templates/frontend/widgets/product-card-add-to-cart.phtml',
        );
        self::assertStringContainsString('data-testid="product-card-add-to-cart"', $template);
        self::assertStringContainsString('data-action="add"', $template);
        self::assertStringContainsString('data-weline-load="cart"', $template);
        self::assertStringContainsString('weline-cart-product-card-add-to-cart', $template);
        self::assertStringContainsString('StorefrontOfferResolver::resolve', $template);
        self::assertStringContainsString('btn-add-to-cart', $template);
    }

    public function testThemeHookRegistryPublishesProductCardAddToCartSlot(): void
    {
        $hook = dirname(__DIR__, 4) . '/Theme/hook.php';
        self::assertFileExists($hook);
        $content = (string)file_get_contents($hook);
        self::assertStringContainsString(
            'Weline_Theme::frontend::partials::product-card::add-to-cart',
            $content,
        );
    }

    public function testPurchaseActionsScriptBindsCardWidgetRoot(): void
    {
        $script = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/statics/js/widgets/product-purchase-actions.js',
        );
        self::assertStringContainsString('weline-cart-product-card-add-to-cart', $script);
        self::assertStringContainsString('[data-action="add"]', $script);
        self::assertStringNotContainsString('[data-action="add-v2"]', $script);
        self::assertStringContainsString('markCardButtonAdded', $script);
        self::assertStringContainsString('showFloatingToast', $script);
        self::assertStringContainsString('showAmazonCartAddedNotice', $script);
        self::assertStringNotContainsString('openMiniCartDrawer', $script);
        self::assertStringContainsString('showCartAddedNotice', $script);
        self::assertStringContainsString('w-amz-cart-added', $script);
        self::assertStringContainsString('guestTokenPromise', $script);
        self::assertStringContainsString('silent: true', $script);
        self::assertStringContainsString('formatStorefrontMoney', $script);
        self::assertStringContainsString('Weline.UI.toast', $script);
        self::assertStringContainsString('data-card-add-label', (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/templates/frontend/widgets/product-card-add-to-cart.phtml',
        ));
        self::assertStringContainsString('storefront-category-catalog', $script);
        self::assertStringContainsString('data-mini-cart-trigger', $script);

        $registrySrc = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/CartItemSnapshotProviderRegistry.php',
        );
        self::assertStringContainsString('relative_path', $registrySrc);
        self::assertStringContainsString("extends/module/", $registrySrc);
        self::assertStringNotContainsString('$extension[\'file\']', $registrySrc);
    }
}
