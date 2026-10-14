<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\View;

use PHPUnit\Framework\TestCase;

/**
 * Shopper toast Amazon CSS is a STYLE leaf (backend style selection → generated CSS).
 * Theme layout/head MUST NOT hard-@static it. theme.css MUST NOT own .w-amz-* rules.
 */
final class StorefrontShopperToastContractTest extends TestCase
{
    private const TOAST_CSS = 'css/widgets/storefront-shopper-toast-amazon.css';

    public function testStyleCssOwnsShopperToastStyles(): void
    {
        $toastCss = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/statics/' . self::TOAST_CSS,
        );
        self::assertStringContainsString('Amazon 店面购物者通知', $toastCss);
        self::assertStringContainsString('.w-amz-shopper-toast', $toastCss);
        self::assertStringContainsString('.w-amz-cart-added', $toastCss);
        self::assertStringContainsString('.w-amz-cart-added__btn--checkout', $toastCss);
        self::assertStringContainsString('var(--color-success)', $toastCss);
        self::assertStringContainsString('var(--color-primary)', $toastCss);
        self::assertStringContainsString('.w-amz-shopper-toast {', $toastCss);
        self::assertStringNotContainsString('DEPRECATED', $toastCss);
    }

    public function testThemeCssDoesNotOwnAmazonShopperToastStyles(): void
    {
        $themeCss = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/theme/frontend/assets/css/theme.css',
        );
        self::assertStringNotContainsString('.w-amz-shopper-toast', $themeCss);
        self::assertStringNotContainsString('.w-amz-cart-added', $themeCss);
        self::assertStringContainsString('do NOT register .amazon-* here', $themeCss);
    }

    public function testThemeHeadsDoNotHardReferenceToastStyleLeaf(): void
    {
        foreach (['default.phtml', 'minimal.phtml', 'assets-prefix.phtml', 'assets-suffix.phtml'] as $head) {
            $source = (string)file_get_contents(
                dirname(__DIR__, 3) . '/view/theme/frontend/partials/head/' . $head,
            );
            self::assertStringNotContainsString(
                'storefront-shopper-toast-amazon.css',
                $source,
                $head . ' must not hard-@static style leaf (backend style selection generates CSS)',
            );
        }
    }

    public function testDesignThemeHeadsDoNotHardReferenceToastStyleLeaf(): void
    {
        $designRoot = dirname(__DIR__, 6) . '/design';
        self::assertDirectoryExists($designRoot);

        $heads = [];
        foreach (['default.phtml', 'minimal.phtml', 'assets-prefix.phtml', 'assets-suffix.phtml'] as $name) {
            $matches = glob($designRoot . '/*/*/frontend/partials/head/' . $name) ?: [];
            $heads = array_merge($heads, $matches);
        }
        self::assertNotEmpty($heads, 'expected at least one design theme head override');

        foreach ($heads as $path) {
            $source = (string)file_get_contents($path);
            self::assertStringNotContainsString(
                'storefront-shopper-toast-amazon.css',
                $source,
                $path . ' must not hard-@static style leaf',
            );
        }
    }

    public function testPartialsDoNotEmitToastStylesheet(): void
    {
        $addToCart = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/theme/frontend/partials/product/add-to-cart.phtml',
        );
        $shopper = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/theme/frontend/partials/product/shopper-actions.phtml',
        );

        self::assertStringNotContainsString('storefront-shopper-toast-amazon.css', $addToCart);
        self::assertStringNotContainsString('claimShopperToastStylesheet', $addToCart);
        self::assertStringContainsString('data-weline-load="cart,storefrontShopperToast"', $addToCart);
        self::assertStringNotContainsString('storefront-shopper-toast-amazon.css', $shopper);
        self::assertStringContainsString('storefrontShopperToast', $shopper);
    }

    public function testBodyEndHookDoesNotLoadToastCss(): void
    {
        $hook = dirname(__DIR__, 3)
            . '/view/hooks/Weline_Theme/frontend/layouts/base/body-end.phtml';
        $source = (string)file_get_contents($hook);
        self::assertStringContainsString('storefrontImageFallback', $source);
        self::assertDoesNotMatchRegularExpression(
            '/<(?:link|theme:css)[^>]*(?:storefront-shopper-toast-amazon\.css)/i',
            $source,
        );
    }

    public function testShopperToastScriptPatchesFrontendToast(): void
    {
        $script = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/statics/js/storefront-shopper-toast.js',
        );
        self::assertStringContainsString('data-w-area', $script);
        self::assertStringContainsString('w-amz-shopper-toast', $script);
        self::assertStringContainsString('__shopperAmazonPatched', $script);
        self::assertStringContainsString('data-mini-cart-trigger', $script);
        self::assertStringContainsString('Weline.ShopperNotice', $script);
        self::assertStringContainsString('showCompareAdded', $script);
    }
}
