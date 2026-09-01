<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class StorefrontShopperToastContractTest extends TestCase
{
    public function testThemeCssOwnsShopperToastStyles(): void
    {
        $themeCss = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/theme/frontend/assets/css/theme.css',
        );
        self::assertStringContainsString('Amazon storefront shopper notices', $themeCss);
        self::assertStringContainsString('.w-amz-shopper-toast', $themeCss);
        self::assertStringContainsString('.w-amz-cart-added', $themeCss);
        self::assertStringContainsString('.w-amz-cart-added__btn--checkout', $themeCss);
        self::assertStringContainsString('#067d62', $themeCss);
    }

    public function testFrontendHeadLoadsThemeCss(): void
    {
        foreach (['default.phtml', 'minimal.phtml'] as $head) {
            $source = (string)file_get_contents(
                dirname(__DIR__, 3) . '/view/theme/frontend/partials/head/' . $head,
            );
            self::assertStringContainsString(
                'Weline_Theme::theme/frontend/assets/css/theme.css',
                $source,
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
        self::assertStringNotContainsString('storefront-shopper-toast-amazon.css', $source);
        self::assertStringContainsString('storefrontImageFallback', $source);
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

    public function testStandaloneToastCssFileIsDeprecatedPointer(): void
    {
        $css = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/statics/css/widgets/storefront-shopper-toast-amazon.css',
        );
        self::assertStringContainsString('DEPRECATED', $css);
        self::assertStringContainsString('assets/css/theme.css', $css);
        self::assertStringNotContainsString('.w-amz-shopper-toast {', $css);
    }
}
