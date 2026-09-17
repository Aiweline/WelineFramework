<?php

declare(strict_types=1);

namespace Weline\Wishlist\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class WishlistPageTemplateContractTest extends TestCase
{
    public function testHeaderWishlistSurfacesUseConfiguredCopyI18nBoundary(): void
    {
        $widgetFile = dirname(__DIR__, 3) . '/view/theme/frontend/widgets/header/wishlist-icon/default.phtml';
        $hookFile = dirname(__DIR__, 3) . '/view/hooks/header-account-links.phtml';
        self::assertFileExists($widgetFile);
        self::assertFileExists($hookFile);

        $widget = (string)file_get_contents($widgetFile);
        $hook = (string)file_get_contents($hookFile);
        self::assertStringContainsString("WidgetI18n::label('我的收藏')", $widget);
        self::assertStringContainsString("WidgetI18n::label('收藏')", $widget);
        self::assertStringContainsString("WidgetI18n::label('我的收藏')", $hook);
        self::assertStringNotContainsString("__('我的收藏')", $widget . $hook);
        self::assertStringNotContainsString("__('收藏')", $widget);
    }

    public function testWishlistPageUsesUnifiedProductCardTag(): void
    {
        $templateFile = dirname(__DIR__, 3) . '/view/templates/frontend/wishlist/index.phtml';
        $cssFile = dirname(__DIR__, 3) . '/view/statics/css/wishlist-account.css';
        self::assertFileExists($templateFile);
        self::assertFileExists($cssFile);
        $source = (string)file_get_contents($templateFile);
        $css = (string)file_get_contents($cssFile);

        self::assertStringContainsString('<w:product:card', $source);
        self::assertStringContainsString('ProductCardRenderer', $source);
        self::assertStringContainsString('emitStylesheetLinkOnce()', $source);
        self::assertStringContainsString('show-wishlist="true"', $source);
        self::assertStringContainsString('show-compare="true"', $source);
        self::assertStringContainsString('show-quickview="true"', $source);
        self::assertStringContainsString('wishlist-pixel="true"', $source);
        self::assertStringContainsString('data-wishlist-remove', $source);
        self::assertStringContainsString('storefront-wishlist__slot', $source);
        self::assertStringContainsString('account-card', $source);
        self::assertStringContainsString('account-card__header', $source);
        self::assertStringContainsString('account-card__body', $source);
        self::assertStringContainsString("fetchTagSource", $source);
        self::assertStringContainsString('Weline_Wishlist::css/wishlist-account.css', $source);
        self::assertStringContainsString('<link rel="stylesheet"', $source);
        self::assertStringNotContainsString('<css>', $source);
        self::assertStringContainsString('data-weline-load="wishlist,api"', $source);
        self::assertStringNotContainsString('partials/product/shopper-actions.phtml', $source);
        self::assertStringNotContainsString('data-wishlist-add-cart', $source);
        self::assertStringNotContainsString('<script>', $source);
        self::assertStringNotContainsString('<style>', $source);
        self::assertStringNotContainsString('100vw', $source);
        self::assertStringNotContainsString('calc(50% - 50vw)', $source);

        self::assertStringContainsString('.product-actions', $css);
        self::assertStringContainsString('btn-wishlist.is-active', $css);
        self::assertStringContainsString('--color-primary', $css);
        self::assertStringContainsString('--color-border-light', $css);
        self::assertStringContainsString('storefront-wishlist__slot', $css);
        self::assertStringNotContainsString('100vw', $css);
        self::assertStringNotContainsString('calc(50% - 50vw)', $css);
    }
}
