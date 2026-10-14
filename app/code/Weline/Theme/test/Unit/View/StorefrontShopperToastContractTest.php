<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\View;

use PHPUnit\Framework\TestCase;

/**
 * Shopper toast is Theme architecture widget CSS (all designs inherit).
 * Loaded via storefront-shopper-toast.js ensure — NOT theme.css / PACK_STOREFRONT / hard-@static.
 * Amazon filename remains a thin @import shim for legacy style-selection paths.
 */
final class StorefrontShopperToastContractTest extends TestCase
{
    private const TOAST_CSS = 'css/widgets/storefront-shopper-toast.css';
    private const AMAZON_SHIM = 'css/widgets/storefront-shopper-toast-amazon.css';

    public function testArchitectureCssOwnsShopperToastStyles(): void
    {
        $toastCss = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/statics/' . self::TOAST_CSS,
        );
        self::assertStringContainsString('Theme 架构部件', $toastCss);
        self::assertStringContainsString('.w-shopper-toast', $toastCss);
        self::assertStringContainsString('.w-shopper-cart-added', $toastCss);
        self::assertStringContainsString('.w-shopper-cart-added__btn--checkout', $toastCss);
        self::assertStringContainsString('var(--color-success', $toastCss);
        self::assertStringContainsString('var(--color-primary', $toastCss);
        self::assertStringContainsString('--weline-chrome-body-text: #0f1111', $toastCss);
        self::assertStringContainsString('@media (max-width: 767px)', $toastCss);
        self::assertStringNotContainsString('@media (max-width: var(--token-bp-767px))', $toastCss);
    }

    public function testAmazonFileIsImportShimOnly(): void
    {
        $shim = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/statics/' . self::AMAZON_SHIM,
        );
        self::assertStringContainsString('@import url("./storefront-shopper-toast.css")', $shim);
        self::assertStringNotContainsString('.w-amz-shopper-toast {', $shim);
    }

    public function testThemeCssDoesNotOwnShopperToastStyles(): void
    {
        $themeCss = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/theme/frontend/assets/css/theme.css',
        );
        self::assertStringNotContainsString('.w-shopper-toast', $themeCss);
        self::assertStringNotContainsString('.w-shopper-cart-added', $themeCss);
        self::assertStringNotContainsString('.w-amz-shopper-toast', $themeCss);
        self::assertStringNotContainsString('.w-amz-cart-added', $themeCss);
        self::assertStringContainsString('do NOT register .amazon-* here', $themeCss);
    }

    public function testThemeHeadsDoNotHardReferenceToastCss(): void
    {
        foreach (['default.phtml', 'minimal.phtml', 'assets-prefix.phtml', 'assets-suffix.phtml'] as $head) {
            $source = (string)file_get_contents(
                dirname(__DIR__, 3) . '/view/theme/frontend/partials/head/' . $head,
            );
            self::assertStringNotContainsString(
                'storefront-shopper-toast',
                $source,
                $head . ' must not hard-@static shopper toast (JS ensure owns load)',
            );
        }
    }

    public function testDesignThemeHeadsDoNotHardReferenceToastCss(): void
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
                'storefront-shopper-toast',
                $source,
                $path . ' must not hard-@static shopper toast',
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

        self::assertDoesNotMatchRegularExpression(
            '/@(?:static|css)\([^)]*storefront-shopper-toast/i',
            $addToCart,
        );
        self::assertStringNotContainsString('claimShopperToastStylesheet', $addToCart);
        self::assertStringContainsString('data-weline-load="cart,storefrontShopperToast"', $addToCart);
        self::assertDoesNotMatchRegularExpression(
            '/@(?:static|css)\([^)]*storefront-shopper-toast/i',
            $shopper,
        );
        self::assertStringContainsString('storefrontShopperToast', $shopper);
    }

    public function testBodyEndHookDoesNotLoadToastCss(): void
    {
        $hook = dirname(__DIR__, 3)
            . '/view/hooks/Weline_Theme/frontend/layouts/base/body-end.phtml';
        $source = (string)file_get_contents($hook);
        self::assertStringContainsString('storefrontImageFallback', $source);
        self::assertDoesNotMatchRegularExpression(
            '/<(?:link|theme:css)[^>]*(?:storefront-shopper-toast)/i',
            $source,
        );
    }

    public function testShopperToastScriptIsArchitectureComponent(): void
    {
        $script = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/statics/js/storefront-shopper-toast.js',
        );
        self::assertStringContainsString('data-w-area', $script);
        self::assertStringContainsString('w-shopper-toast', $script);
        self::assertStringContainsString('w-shopper-cart-added', $script);
        self::assertStringContainsString('ensureStylesheet', $script);
        self::assertStringContainsString('storefront-shopper-toast.css', $script);
        self::assertStringContainsString('unwrapW18nDisplay', $script);
        self::assertStringContainsString('__shopperToastPatched', $script);
        self::assertStringContainsString('data-mini-cart-trigger', $script);
        self::assertStringContainsString('Weline.ShopperNotice', $script);
        self::assertStringContainsString('showCompareAdded', $script);
    }
}
