<?php

declare(strict_types=1);

namespace Weline\Wishlist\Test\Unit\View;

use PHPUnit\Framework\TestCase;


/**
 * Aggregated contract suite (merged from 1 *ContractTest files).
 * Observe one overall pass/fail via testAllWishlistViewContractsPass().
 */
final class WishlistViewContractsTest extends TestCase
{
    public function testAllWishlistViewContractsPass(): void
    {
        $failures = [];
        foreach ($this->contractChecks() as $name => $fn) {
            try {
                $fn();
            } catch (\Throwable $e) {
                $failures[] = $name . ": " . $e->getMessage();
            }
        }
        self::assertSame([], $failures, implode("\n", $failures));
    }

    /** @return array<string, callable(): void> */
    private function contractChecks(): array
    {
        return [
            'WishlistPageTemplate::testHeaderWishlistSurfacesUseConfiguredCopyI18nBoundary' => function () {
            $this->assert_WishlistPageTemplate_testHeaderWishlistSurfacesUseConfiguredCopyI18nBoundary();
        },
            'WishlistPageTemplate::testWishlistPageUsesUnifiedProductCardTag' => function () {
            $this->assert_WishlistPageTemplate_testWishlistPageUsesUnifiedProductCardTag();
        },
        ];
    }

private function assert_WishlistPageTemplate_testHeaderWishlistSurfacesUseConfiguredCopyI18nBoundary(): void
{
    $widgetFile = '/Users/weline/Project/Official/框架/app/code/Weline/Wishlist' . '/view/theme/frontend/widgets/header/wishlist-icon/default.phtml';
    $hookFile = '/Users/weline/Project/Official/框架/app/code/Weline/Wishlist' . '/view/hooks/header-account-links.phtml';
    self::assertFileExists($widgetFile);
    self::assertFileExists($hookFile);
    $widget = (string) file_get_contents($widgetFile);
    $hook = (string) file_get_contents($hookFile);
    self::assertStringContainsString("WidgetI18n::label('我的收藏')", $widget);
    self::assertStringContainsString("WidgetI18n::label('收藏')", $widget);
    self::assertStringContainsString("WidgetI18n::label('我的收藏')", $hook);
    self::assertStringNotContainsString("__('我的收藏')", $widget . $hook);
    self::assertStringNotContainsString("__('收藏')", $widget);
}

private function assert_WishlistPageTemplate_testWishlistPageUsesUnifiedProductCardTag(): void
{
    $templateFile = '/Users/weline/Project/Official/框架/app/code/Weline/Wishlist' . '/view/templates/frontend/wishlist/index.phtml';
    $cssFile = '/Users/weline/Project/Official/框架/app/code/Weline/Wishlist' . '/view/statics/css/wishlist-account.css';
    self::assertFileExists($templateFile);
    self::assertFileExists($cssFile);
    $source = (string) file_get_contents($templateFile);
    $css = (string) file_get_contents($cssFile);
    self::assertStringContainsString('ProductCardRenderer::projectHtmlByProductId', $source);
    self::assertStringNotContainsString('<w:product:card', $source);
    self::assertStringContainsString('ProductCardRenderer', $source);
    self::assertStringContainsString('emitStylesheetLinkOnce()', $source);
    self::assertStringContainsString("'show_wishlist' => true", $source);
    self::assertStringContainsString("'show_compare' => true", $source);
    self::assertStringContainsString("'show_quickview' => true", $source);
    self::assertStringContainsString("'wishlist_pixel' => true", $source);
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
