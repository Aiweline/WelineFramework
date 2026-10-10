<?php

declare(strict_types=1);

namespace Weline\Compare\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class ComparePageHydrateContractTest extends TestCase
{
    public function testComparePageTemplateUsesClientHydrationShell(): void
    {
        $source = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/templates/frontend/compare/index.phtml',
        );

        self::assertStringContainsString('data-compare-page', $source);
        self::assertStringContainsString('data-compare-page-content', $source);
        self::assertStringContainsString('compare-page.js', $source);
        self::assertStringContainsString('weline-compare-page-labels', $source);
        self::assertStringContainsString("__('已选 %{1} / %{2} 件', ['%{1}', '%{2}'])", $source);
        self::assertStringNotContainsString('ComparePagePresenter', $source);
        self::assertStringNotContainsString('window.Weline.Api.resource', $source);
    }

    public function testComparePageScriptDelegatesRefreshToShopper(): void
    {
        $source = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/statics/js/compare-page.js',
        );

        self::assertStringContainsString('refreshCompareState', $source);
        self::assertStringContainsString('renderFromPayload', $source);
        self::assertStringContainsString('data-compare-remove', $source);
        self::assertStringContainsString('WelineComparePage', $source);
        self::assertStringNotContainsString('apiResource(', $source);
        self::assertStringNotContainsString('window.location.reload', $source);
    }

    public function testProductCardActionsRefreshesComparePageWithoutReload(): void
    {
        $source = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/statics/js/product-card-actions.js',
        );

        self::assertStringContainsString('refreshCompareState', $source);
        self::assertStringContainsString('renderFromPayload', $source);
        self::assertStringContainsString('silent: true', $source);
        self::assertStringContainsString('WelineComparePage.refresh', $source);
        self::assertStringNotContainsString('window.location.reload', $source);
    }

    public function testCompareBarThumbsExposeRemoveControl(): void
    {
        $js = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/statics/js/product-card-actions.js',
        );
        $css = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/statics/css/product-shopper-chrome.css',
        );
        $hook = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/hooks/Weline_Theme/frontend/layouts/base/body-end.phtml',
        );

        self::assertStringContainsString('w-compare-bar__thumb-remove', $js);
        self::assertStringContainsString('w-compare-bar__thumb-item', $js);
        self::assertStringContainsString('data-compare-remove', $js);
        self::assertStringContainsString('.w-compare-bar__thumb-remove', $css);
        self::assertStringContainsString('data-compare-bar-remove-label', $hook);
        self::assertStringContainsString('data-compare-clear>', $hook);
        self::assertStringContainsString('data-compare-bar-label>', $hook);
        self::assertStringNotContainsString('data-compare-clear">', $hook);
        self::assertStringNotContainsString('data-compare-bar-label">', $hook);
        self::assertStringContainsString('product-actions--amz', $css);
        self::assertStringContainsString('border: 0 !important', $css);
        self::assertStringContainsString('color: #c45500', $css);
        self::assertStringContainsString('20261010-qv-product-info1', $hook);
        self::assertStringContainsString('showCompareAddedNotice', $js);
        self::assertStringContainsString('ShopperNotice', $js);
        self::assertStringContainsString('data-i18n-compare-label', $hook);
    }

    public function testQuickViewMountsPurchasePanelProductInfo(): void
    {
        $js = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/statics/js/product-card-actions.js',
        );
        $css = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/statics/css/product-shopper-chrome.css',
        );
        $modules = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/statics/frontend/weline.modules.js',
        );

        self::assertStringContainsString('mountPurchasePanelInto', $js);
        self::assertStringContainsString('w-product-quickview__extra-actions', $js);
        self::assertStringContainsString('ensureCartPurchaseActions', $js);
        self::assertStringContainsString("Weline.load('cart')", $js);
        self::assertStringNotContainsString('w-product-quickview__grid', $js);
        self::assertStringNotContainsString('data-quickview-add-cart', $js);
        self::assertStringNotContainsString('api.quickView(', $js);
        self::assertStringContainsString('.w-product-quickview__extra-actions', $css);
        self::assertStringContainsString('.w-product-quickview__body', $css);
        self::assertStringContainsString('product-card-actions.js?v=20261010-qv-product-info1', $modules);
    }

    public function testUpdateWishlistCountOnlyTargetsBadgeNodes(): void
    {
        $js = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/statics/js/product-card-actions.js',
        );

        self::assertStringContainsString("querySelectorAll('.wishlist-count')", $js);
        self::assertStringContainsString("querySelectorAll('[data-w-wishlist-icon]')", $js);
        self::assertStringContainsString('data-wishlist-total', $js);
        self::assertStringNotContainsString(
            "querySelectorAll('.wishlist-count, [data-wishlist-count]')",
            $js,
        );
    }
}
