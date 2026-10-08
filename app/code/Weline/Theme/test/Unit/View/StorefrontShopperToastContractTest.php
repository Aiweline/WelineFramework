<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\View;

use PHPUnit\Framework\TestCase;

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

    public function testFrontendHeadLoadsToastStyleCss(): void
    {
        foreach (['default.phtml', 'minimal.phtml', 'assets-suffix.phtml'] as $head) {
            $source = (string)file_get_contents(
                dirname(__DIR__, 3) . '/view/theme/frontend/partials/head/' . $head,
            );
            self::assertStringContainsString(
                'Weline_Theme::theme/frontend/assets/css/theme.css',
                $source,
            );
            self::assertStringContainsString(
                'Weline_Theme::css/widgets/storefront-shopper-toast-amazon.css',
                $source,
            );
        }
    }

    public function testDesignThemeHeadOverridesKeepToastStyleCss(): void
    {
        $designRoot = dirname(__DIR__, 6) . '/design';
        self::assertDirectoryExists($designRoot);

        $heads = [];
        foreach (['default.phtml', 'minimal.phtml', 'assets-suffix.phtml'] as $name) {
            // app/design/{Vendor}/{theme}/frontend/partials/head/{name}
            $matches = glob($designRoot . '/*/*/frontend/partials/head/' . $name) ?: [];
            $heads = array_merge($heads, $matches);
        }
        self::assertNotEmpty($heads, 'expected at least one design theme head override');

        foreach ($heads as $path) {
            $source = (string)file_get_contents($path);
            if (!str_contains($source, 'theme/frontend/assets/css/theme.css')) {
                continue;
            }
            self::assertStringContainsString(
                'Weline_Theme::css/widgets/storefront-shopper-toast-amazon.css',
                $source,
                $path . ' overrides theme.css chain but omitted shopper-toast style CSS',
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
        // Comment may mention the style file; assert no live <link>/<theme:css> load.
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
        self::assertStringContainsString('ui.toast.success', $script);
        self::assertStringContainsString('ui.toast.error', $script);
        self::assertStringContainsString('weline:ui:ready', $script);
    }
}
