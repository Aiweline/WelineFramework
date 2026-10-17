<?php

declare(strict_types=1);

namespace Weline\Compare\Test\Unit\View;

use PHPUnit\Framework\TestCase;


/**
 * Aggregated contract suite (merged from 3 *ContractTest files).
 * Observe one overall pass/fail via testAllCompareViewContractsPass().
 */
final class CompareViewContractsTest extends TestCase
{
    public function testAllCompareViewContractsPass(): void
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
            'ComparePageHydrate::testComparePageTemplateUsesClientHydrationShell' => function () {
            $this->assert_ComparePageHydrate_testComparePageTemplateUsesClientHydrationShell();
        },
            'ComparePageHydrate::testComparePageScriptDelegatesRefreshToShopper' => function () {
            $this->assert_ComparePageHydrate_testComparePageScriptDelegatesRefreshToShopper();
        },
            'ComparePageHydrate::testProductCardActionsRefreshesComparePageWithoutReload' => function () {
            $this->assert_ComparePageHydrate_testProductCardActionsRefreshesComparePageWithoutReload();
        },
            'ComparePageHydrate::testCompareBarThumbsExposeRemoveControl' => function () {
            $this->assert_ComparePageHydrate_testCompareBarThumbsExposeRemoveControl();
        },
            'ComparePageHydrate::testQuickViewMountsPurchasePanelProductInfo' => function () {
            $this->assert_ComparePageHydrate_testQuickViewMountsPurchasePanelProductInfo();
        },
            'ComparePageHydrate::testUpdateWishlistCountOnlyTargetsBadgeNodes' => function () {
            $this->assert_ComparePageHydrate_testUpdateWishlistCountOnlyTargetsBadgeNodes();
        },
            'CompareShopperChromeTailSlot::testWidgetRegistersTailSlotAndBodyEndIsEmpty' => function () {
            $this->assert_CompareShopperChromeTailSlot_testWidgetRegistersTailSlotAndBodyEndIsEmpty();
        },
            'EavManagerAttributeField::testThemeManagerExposesSearchableAndCompareMode' => function () {
            $this->assert_EavManagerAttributeField_testThemeManagerExposesSearchableAndCompareMode();
        },
            'EavManagerAttributeField::testNativeManagerExposesSearchableAndCompareMode' => function () {
            $this->assert_EavManagerAttributeField_testNativeManagerExposesSearchableAndCompareMode();
        },
            'EavManagerAttributeField::testLegacyManagerExposesSearchableAndCompareMode' => function () {
            $this->assert_EavManagerAttributeField_testLegacyManagerExposesSearchableAndCompareMode();
        },
            'EavManagerAttributeField::testAttributeFormTemplateExposesCompareMode' => function () {
            $this->assert_EavManagerAttributeField_testAttributeFormTemplateExposesCompareMode();
        },
        ];
    }

private function assert_ComparePageHydrate_testComparePageTemplateUsesClientHydrationShell(): void
{
    $source = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Compare' . '/view/templates/frontend/compare/index.phtml');
    self::assertStringContainsString('data-compare-page', $source);
    self::assertStringContainsString('data-compare-page-content', $source);
    self::assertStringContainsString('data-weline-load="comparePage,compareShopper"', $source);
    self::assertStringContainsString('weline-compare-page-labels', $source);
    self::assertStringContainsString("__('已选 %{1} / %{2} 件', ['%{1}', '%{2}'])", $source);
    self::assertStringNotContainsString('ComparePagePresenter', $source);
    self::assertStringNotContainsString('window.Weline.Api.resource', $source);
}

private function assert_ComparePageHydrate_testComparePageScriptDelegatesRefreshToShopper(): void
{
    $source = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Compare' . '/view/statics/js/compare-page.js');
    self::assertStringContainsString('refreshCompareState', $source);
    self::assertStringContainsString('renderFromPayload', $source);
    self::assertStringContainsString('data-compare-remove', $source);
    self::assertStringContainsString('WelineComparePage', $source);
    self::assertStringNotContainsString('apiResource(', $source);
    self::assertStringNotContainsString('window.location.reload', $source);
}

private function assert_ComparePageHydrate_testProductCardActionsRefreshesComparePageWithoutReload(): void
{
    $source = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Compare' . '/view/statics/js/product-card-actions.js');
    self::assertStringContainsString('refreshCompareState', $source);
    self::assertStringContainsString('renderFromPayload', $source);
    self::assertStringContainsString('silent: true', $source);
    self::assertStringContainsString('WelineComparePage.refresh', $source);
    self::assertStringNotContainsString('window.location.reload', $source);
}

private function assert_ComparePageHydrate_testCompareBarThumbsExposeRemoveControl(): void
{
    $js = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Compare' . '/view/statics/js/product-card-actions.js');
    $css = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Compare' . '/view/statics/css/product-shopper-chrome.css');
    $hook = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Compare' . '/view/hooks/Weline_Theme/frontend/layouts/base/body-end.phtml');
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

private function assert_ComparePageHydrate_testQuickViewMountsPurchasePanelProductInfo(): void
{
    $js = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Compare' . '/view/statics/js/product-card-actions.js');
    $css = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Compare' . '/view/statics/css/product-shopper-chrome.css');
    $modules = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Compare' . '/view/statics/frontend/weline.modules.js');
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

private function assert_ComparePageHydrate_testUpdateWishlistCountOnlyTargetsBadgeNodes(): void
{
    $js = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Compare' . '/view/statics/js/product-card-actions.js');
    self::assertStringContainsString("querySelectorAll('.wishlist-count')", $js);
    self::assertStringContainsString("querySelectorAll('[data-w-wishlist-icon]')", $js);
    self::assertStringContainsString('data-wishlist-total', $js);
    self::assertStringNotContainsString("querySelectorAll('.wishlist-count, [data-wishlist-count]')", $js);
}

private function assert_CompareShopperChromeTailSlot_testWidgetRegistersTailSlotAndBodyEndIsEmpty(): void
{
    $root = '/Users/weline/Project/Official/框架/app/code/Weline/Compare';
    $widget = (string) file_get_contents($root . '/view/templates/frontend/widgets/compare-shopper-chrome.phtml');
    self::assertStringContainsString('@widget.code {compare-shopper-chrome}', $widget);
    self::assertStringContainsString('@widget.slot {storefront-tail-chrome}', $widget);
    self::assertStringContainsString('data-compare-bar', $widget);
    self::assertStringNotContainsString('emitModuleLayoutCssHtml', $widget);
    $bodyEnd = (string) file_get_contents($root . '/view/hooks/Weline_Theme/frontend/layouts/base/body-end.phtml');
    self::assertStringNotContainsString('weline-product-quickview-dialog', $bodyEnd);
    self::assertStringNotContainsString('<dialog', $bodyEnd);
    self::assertStringNotContainsString('data-compare-bar', $bodyEnd);
    $registry = (string) file_get_contents($root . '/extends/module/Weline_Widget/Weline_Compare/widget.php');
    self::assertStringContainsString('compare-shopper-chrome.phtml', $registry);
}

private function assert_EavManagerAttributeField_testThemeManagerExposesSearchableAndCompareMode(): void
{
    $source = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline' . '/Theme/view/statics/ui/pages/weline-eav-manager.js');
    self::assertStringContainsString('frontend_is_searchable', $source);
    self::assertStringContainsString('compare_mode', $source);
    self::assertStringContainsString('compareModeField', $source);
}

private function assert_EavManagerAttributeField_testNativeManagerExposesSearchableAndCompareMode(): void
{
    $source = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline' . '/Eav/view/statics/js/eav-manager-native.js');
    self::assertStringContainsString('frontend_is_searchable', $source);
    self::assertStringContainsString('compare_mode', $source);
}

private function assert_EavManagerAttributeField_testLegacyManagerExposesSearchableAndCompareMode(): void
{
    $source = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline' . '/Eav/view/statics/js/eav-manager/eav-manager.js');
    self::assertStringContainsString('frontend_is_searchable', $source);
    self::assertStringContainsString('compare_mode', $source);
}

private function assert_EavManagerAttributeField_testAttributeFormTemplateExposesCompareMode(): void
{
    $source = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline' . '/Eav/view/templates/backend/Attribute/form.phtml');
    self::assertStringContainsString('compare_mode', $source);
    self::assertStringContainsString('frontend_is_searchable', $source);
    self::assertStringContainsString('frontend_is_filterable', $source);
}
}
