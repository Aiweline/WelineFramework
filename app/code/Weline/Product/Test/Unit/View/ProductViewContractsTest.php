<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\View;

use PHPUnit\Framework\TestCase;

/**
 * Aggregated contract suite (merged from 23 *ContractTest files).
 * Observe one overall pass/fail via testAllProductViewContractsPass().
 */
final class ProductViewContractsTest extends TestCase
{
    public function testAllProductViewContractsPass(): void
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
            'CategoryLayoutMobileFilterGrid::testMobileMediaQueryResetsWithFiltersSpecificity' => function () {
            $this->assert_CategoryLayoutMobileFilterGrid_testMobileMediaQueryResetsWithFiltersSpecificity();
        },
            'CrossSellCartIntegration::testCrossSellTemplateExposesCartFeedbackMessages' => function () {
            $this->assert_CrossSellCartIntegration_testCrossSellTemplateExposesCartFeedbackMessages();
        },
            'CrossSellCartIntegration::testCrossSellScriptUsesCartBinQueryAndDispatchesCartUpdated' => function () {
            $this->assert_CrossSellCartIntegration_testCrossSellScriptUsesCartBinQueryAndDispatchesCartUpdated();
        },
            'CrossSellCartIntegration::testCrossSellScriptDoesNotFakeSuccessWithoutCartMutation' => function () {
            $this->assert_CrossSellCartIntegration_testCrossSellScriptDoesNotFakeSuccessWithoutCartMutation();
        },
            'CrossSellEmptyShell::testCrossSellEmitsHiddenEmptyShellInsteadOfBlankHtml' => function () {
            $this->assert_CrossSellEmptyShell_testCrossSellEmitsHiddenEmptyShellInsteadOfBlankHtml();
        },
            'PdpSecureTrustSpacing::testBuyboxUsesColumnGapAndKeepsZeroChildMargin' => function () {
            $this->assert_PdpSecureTrustSpacing_testBuyboxUsesColumnGapAndKeepsZeroChildMargin();
        },
            'PdpSecureTrustSpacing::testPurchaseActionsStackFullWidthWithBreathingGap' => function () {
            $this->assert_PdpSecureTrustSpacing_testPurchaseActionsStackFullWidthWithBreathingGap();
        },
            'PdpVerseVerticalLanguage::testDesktopCjkKeepsVerticalWriting' => function () {
            $this->assert_PdpVerseVerticalLanguage_testDesktopCjkKeepsVerticalWriting();
        },
            'PdpVerseVerticalLanguage::testNonCjkFallsBackToHorizontalAndWidensPoemAside' => function () {
            $this->assert_PdpVerseVerticalLanguage_testNonCjkFallsBackToHorizontalAndWidensPoemAside();
        },
            'PdpVerseVerticalLanguage::testMobileDropsPoeticPackagingToHorizontalLightweightLead' => function () {
            $this->assert_PdpVerseVerticalLanguage_testMobileDropsPoeticPackagingToHorizontalLightweightLead();
        },
            'ProductAccountQuotesSignal::testModelExposesCustomerReplySeenFieldsAndStableCodes' => function () {
            $this->assert_ProductAccountQuotesSignal_testModelExposesCustomerReplySeenFieldsAndStableCodes();
        },
            'ProductAccountQuotesSignal::testServiceMarksReplyAndSupportsCustomerLifecycle' => function () {
            $this->assert_ProductAccountQuotesSignal_testServiceMarksReplyAndSupportsCustomerLifecycle();
        },
            'ProductAccountQuotesSignal::testSignalProviderRegistersProductQuotesCode' => function () {
            $this->assert_ProductAccountQuotesSignal_testSignalProviderRegistersProductQuotesCode();
        },
            'ProductAccountQuotesSignal::testAccountHooksUseEmptySignalShellsAndStableSection' => function () {
            $this->assert_ProductAccountQuotesSignal_testAccountHooksUseEmptySignalShellsAndStableSection();
        },
            'ProductAccountQuotesSignal::testCustomerMenuSignalsAndJsPaintRemainSsrFree' => function () {
            $this->assert_ProductAccountQuotesSignal_testCustomerMenuSignalsAndJsPaintRemainSsrFree();
        },
            'ProductAccountQuotesSignal::testModuleVersionAndOptionalCustomer' => function () {
            $this->assert_ProductAccountQuotesSignal_testModuleVersionAndOptionalCustomer();
        },
            'ProductBrandAdmin::testBrandShardEntityIsDeclared' => function () {
            $this->assert_ProductBrandAdmin_testBrandShardEntityIsDeclared();
        },
            'ProductBrandAdmin::testBrandAdminSurfaceExposesCrudAndMenu' => function () {
            $this->assert_ProductBrandAdmin_testBrandAdminSurfaceExposesCrudAndMenu();
        },
            'ProductBrandAdmin::testSaveBrandCsrfFieldAlignsWithWformAuto' => function () {
            $this->assert_ProductBrandAdmin_testSaveBrandCsrfFieldAlignsWithWformAuto();
        },
            'ProductBrandAdmin::testCreateWizardSelectsBrandFromCatalog' => function () {
            $this->assert_ProductBrandAdmin_testCreateWizardSelectsBrandFromCatalog();
        },
            'ProductCardLabelWrap::testProductCardCssScopesBadgeLayoutToLabelsContainerOnly' => function () {
            $this->assert_ProductCardLabelWrap_testProductCardCssScopesBadgeLayoutToLabelsContainerOnly();
        },
            'ProductCardLabelWrap::testProductCardPassesWpcBadgesOnlyAsLabelsContainerClass' => function () {
            $this->assert_ProductCardLabelWrap_testProductCardPassesWpcBadgesOnlyAsLabelsContainerClass();
        },
            'ProductCardVerticalRhythm::testCategoryPlpDoesNotLeakPagerMarginOntoPurchaseButtons' => function () {
            $this->assert_ProductCardVerticalRhythm_testCategoryPlpDoesNotLeakPagerMarginOntoPurchaseButtons();
        },
            'ProductCardVerticalRhythm::testProductCardCssUsesSingleCtaAutoMargin' => function () {
            $this->assert_ProductCardVerticalRhythm_testProductCardCssUsesSingleCtaAutoMargin();
        },
            'ProductCardVerticalRhythm::testProductCardPartialOwnsPurchaseCssFlag' => function () {
            $this->assert_ProductCardVerticalRhythm_testProductCardPartialOwnsPurchaseCssFlag();
        },
            'ProductCreateVariantFlow::testCreateConfigurableSubmitUsesCollectedVariantAxesNotManualJson' => function () {
            $this->assert_ProductCreateVariantFlow_testCreateConfigurableSubmitUsesCollectedVariantAxesNotManualJson();
        },
            'ProductCreateVariantFlow::testEditPageUsesEavControlsWithoutVisibleAdvancedJsonEditors' => function () {
            $this->assert_ProductCreateVariantFlow_testEditPageUsesEavControlsWithoutVisibleAdvancedJsonEditors();
        },
            'ProductCreateVariantFlow::testCreateAndEditShareVariantCombinationEngine' => function () {
            $this->assert_ProductCreateVariantFlow_testCreateAndEditShareVariantCombinationEngine();
        },
            'ProductCreateVariantFlow::testVariantAxisGroupUsesChipPickerOnlyForConfigurableType' => function () {
            $this->assert_ProductCreateVariantFlow_testVariantAxisGroupUsesChipPickerOnlyForConfigurableType();
        },
            'ProductCreateVariantFlow::testProductLevelAttributesExcludeConfigurableVariantAxes' => function () {
            $this->assert_ProductCreateVariantFlow_testProductLevelAttributesExcludeConfigurableVariantAxes();
        },
            'ProductCreateVariantFlow::testWarrantyMonthsUsesPresetSelectOptionsInBootstrap' => function () {
            $this->assert_ProductCreateVariantFlow_testWarrantyMonthsUsesPresetSelectOptionsInBootstrap();
        },
            'ProductCreateVariantFlow::testCreateTemplateExposesVariantPreviewPanelNotAxesJsonEditor' => function () {
            $this->assert_ProductCreateVariantFlow_testCreateTemplateExposesVariantPreviewPanelNotAxesJsonEditor();
        },
            'ProductCreateVariantFlow::testCreateFlowSupportsVariantValueImagesAndFullPreview' => function () {
            $this->assert_ProductCreateVariantFlow_testCreateFlowSupportsVariantValueImagesAndFullPreview();
        },
            'ProductCreateVariantFlow::testCreateWizardStepsExposeNextButtons' => function () {
            $this->assert_ProductCreateVariantFlow_testCreateWizardStepsExposeNextButtons();
        },
            'ProductCreateVariantFlow::testCatalogCreatePageExposesPanelFocusControls' => function () {
            $this->assert_ProductCreateVariantFlow_testCatalogCreatePageExposesPanelFocusControls();
        },
            'ProductCreateVariantFlow::testHanfuSchemaDefinesVariantAxesAsSelectAttributes' => function () {
            $this->assert_ProductCreateVariantFlow_testHanfuSchemaDefinesVariantAxesAsSelectAttributes();
        },
            'ProductCreateVariantFlow::testCreateWizardOrdersProductAttributesBeforeVariantAxes' => function () {
            $this->assert_ProductCreateVariantFlow_testCreateWizardOrdersProductAttributesBeforeVariantAxes();
        },
            'ProductCreateVariantFlow::testCreateWizardUsesScopePersistenceForDraftAutosave' => function () {
            $this->assert_ProductCreateVariantFlow_testCreateWizardUsesScopePersistenceForDraftAutosave();
        },
            'ProductCreateVariantFlow::testCreateAttributeWriteBindsEntityIdZeroToProduct' => function () {
            $this->assert_ProductCreateVariantFlow_testCreateAttributeWriteBindsEntityIdZeroToProduct();
        },
            'ProductCreateVariantFlow::testCreateEavRenderFallsBackToSnapshotCatalogAndGatesRequiredAttrs' => function () {
            $this->assert_ProductCreateVariantFlow_testCreateEavRenderFallsBackToSnapshotCatalogAndGatesRequiredAttrs();
        },
            'ProductDeliveryModeSlot::testProductInfoExposesEmptyDeliveryModeSlotWithoutHardcodedBadge' => function () {
            $this->assert_ProductDeliveryModeSlot_testProductInfoExposesEmptyDeliveryModeSlotWithoutHardcodedBadge();
        },
            'ProductDetailRevealLazyHydrate::testRevealScriptHydratesDescImagesOnArmShowAndWrapperIo' => function () {
            $this->assert_ProductDetailRevealLazyHydrate_testRevealScriptHydratesDescImagesOnArmShowAndWrapperIo();
        },
            'ProductInfoPlacement::testNativeRecommendationsKeepConfigOnlyOnForeignInjectionSlots' => function () {
            $this->assert_ProductInfoPlacement_testNativeRecommendationsKeepConfigOnlyOnForeignInjectionSlots();
        },
            'ProductInfoPlacement::testNativeInfoRetainsLayoutAndForeignDesignHasDedicatedInjection' => function () {
            $this->assert_ProductInfoPlacement_testNativeInfoRetainsLayoutAndForeignDesignHasDedicatedInjection();
        },
            'ProductInfoPurchaseFailsafe::testFailsafeCreatesActionsSlotWhenMissing' => function () {
            $this->assert_ProductInfoPurchaseFailsafe_testFailsafeCreatesActionsSlotWhenMissing();
        },
            'ProductLayoutAdminPanel::testProductEditPanelExists' => function () {
            $this->assert_ProductLayoutAdminPanel_testProductEditPanelExists();
        },
            'ProductLayoutAdminPanel::testCategoryDefaultLayoutPanelExists' => function () {
            $this->assert_ProductLayoutAdminPanel_testCategoryDefaultLayoutPanelExists();
        },
            'ProductLayoutAdminPanel::testProductAdminJsBindsLayoutPanel' => function () {
            $this->assert_ProductLayoutAdminPanel_testProductAdminJsBindsLayoutPanel();
        },
            'ProductLayoutAdminPanel::testDetailResolvesEffectiveLayoutOption' => function () {
            $this->assert_ProductLayoutAdminPanel_testDetailResolvesEffectiveLayoutOption();
        },
            'ProductLayoutAdminPanel::testProductAdminJsSeedsCloneFromOptions' => function () {
            $this->assert_ProductLayoutAdminPanel_testProductAdminJsSeedsCloneFromOptions();
        },
            'ProductLayoutAdminPanel::testProductLayoutCreateUsesButtonNotNestedForm' => function () {
            $this->assert_ProductLayoutAdminPanel_testProductLayoutCreateUsesButtonNotNestedForm();
        },
            'ProductLayoutAdminPanel::testProductAdminQueryExposesLayoutOperations' => function () {
            $this->assert_ProductLayoutAdminPanel_testProductAdminQueryExposesLayoutOperations();
        },
            'ProductNativeDetailStageFrameMobile::testMobileGalleryStretchesStageWrapForWFrame' => function () {
            $this->assert_ProductNativeDetailStageFrameMobile_testMobileGalleryStretchesStageWrapForWFrame();
        },
            'ProductStickyPurchase::testProductInfoDeclaresStickyPurchaseDockAndModuleLoad' => function () {
            $this->assert_ProductStickyPurchase_testProductInfoDeclaresStickyPurchaseDockAndModuleLoad();
        },
            'ProductStockLabel::testRenderStockKeepsPlaceholderSentinel' => function () {
            $this->assert_ProductStockLabel_testRenderStockKeepsPlaceholderSentinel();
        },
            'ProductStockLabel::testBuyboxFailsafeAndAvailabilitySync' => function () {
            $this->assert_ProductStockLabel_testBuyboxFailsafeAndAvailabilitySync();
        },
            'ProductSupplierAdmin::testSupplierShardEntitiesAreDeclared' => function () {
            $this->assert_ProductSupplierAdmin_testSupplierShardEntitiesAreDeclared();
        },
            'ProductSupplierAdmin::testSupplierAdminSurfaceExposesCrudAndMenu' => function () {
            $this->assert_ProductSupplierAdmin_testSupplierAdminSurfaceExposesCrudAndMenu();
        },
            'ProductSupplierAdmin::testCreateWizardSelectsSupplierFromCatalog' => function () {
            $this->assert_ProductSupplierAdmin_testCreateWizardSelectsSupplierFromCatalog();
        },
            'RecommendedProductsEmptyShell::testRecommendedProductsEmitsHiddenEmptyShellInsteadOfBlankHtml' => function () {
            $this->assert_RecommendedProductsEmptyShell_testRecommendedProductsEmitsHiddenEmptyShellInsteadOfBlankHtml();
        },
            'RecommendedProductsEmptyShell::testRelatedProductsEmitsHiddenEmptyShellInsteadOfBlankHtml' => function () {
            $this->assert_RecommendedProductsEmptyShell_testRelatedProductsEmitsHiddenEmptyShellInsteadOfBlankHtml();
        },
            'RecommendedProductsMobileMediaQuery::testShelfCssUsesLiteralMobileBreakpointsNotCssVars' => function () {
            $this->assert_RecommendedProductsMobileMediaQuery_testShelfCssUsesLiteralMobileBreakpointsNotCssVars();
        },
            'YouMayLikeWidget::testRegistrationPinsProductOwnedDefaultInjectionAndListingCard' => function () {
            $this->assert_YouMayLikeWidget_testRegistrationPinsProductOwnedDefaultInjectionAndListingCard();
        },
        ];
    }

private function assert_CategoryLayoutMobileFilterGrid_testMobileMediaQueryResetsWithFiltersSpecificity(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Product' . '/view/theme/frontend/layouts/category/default.phtml';
    self::assertFileExists($path);
    $src = (string) file_get_contents($path);
    self::assertNotSame('', $src);
    self::assertMatchesRegularExpression('/\.category-layout__container--with-filters\s*,\s*' . '\.category-layout__container:has\(\s*>\s*\.category-layout__sidebar\s*\)\s*\{' . '[\s\S]*?grid-template-columns:\s*220px\s+minmax\(0,\s*1fr\)/i', $src, 'Desktop filter layout must keep sidebar + main columns');
    self::assertMatchesRegularExpression('/@media\s+screen\s+and\s*\(\s*max-width:\s*900px\s*\)\s*\{[\s\S]*?' . '\.category-layout__container--with-filters\s*,[\s\S]*?' . '\.category-layout__container:has\(\s*>\s*\.category-layout__sidebar\s*\)\s*\{[\s\S]*?' . 'grid-template-columns:\s*minmax\(0,\s*1fr\)/i', $src, 'Mobile must override --with-filters/:has(sidebar) at equal-or-higher specificity');
}

private function assert_CrossSellCartIntegration_testCrossSellTemplateExposesCartFeedbackMessages(): void
{
    $template = $this->template_CrossSellCartIntegration();
    self::assertStringContainsString('data-msg-loading', $template);
    self::assertStringContainsString('data-msg-error', $template);
    self::assertStringContainsString('data-msg-success', $template);
    self::assertStringContainsString('data-cart-url', $template);
    self::assertStringContainsString('data-qty="1"', $template);
    self::assertStringContainsString('data-global-offer-uuid', $template);
}

private function assert_CrossSellCartIntegration_testCrossSellScriptUsesCartBinQueryAndDispatchesCartUpdated(): void
{
    $script = $this->script_CrossSellCartIntegration();
    self::assertStringContainsString("resource('cart')", $script);
    self::assertStringContainsString('add', $script);
    self::assertStringContainsString('issueGuestToken', $script);
    self::assertStringContainsString('weline:cart-updated', $script);
    self::assertStringContainsString('weline:cart:update', $script);
    self::assertStringContainsString('weshop:cart:updated', $script);
    self::assertStringContainsString('global_offer_uuid', $script);
    self::assertStringContainsString('legacy_product_id', $script);
    self::assertStringContainsString('showCartAddedSuccess', $script);
    self::assertStringContainsString('showCartAddedNotice', $script);
}

private function assert_CrossSellCartIntegration_testCrossSellScriptDoesNotFakeSuccessWithoutCartMutation(): void
{
    $script = $this->script_CrossSellCartIntegration();
    self::assertStringContainsString('addSelectedItems', $script);
    self::assertStringContainsString('notifyCartUpdated', $script);
    self::assertStringNotContainsString("notice(msgAdded, 'success');\n        addAll.setAttribute('data-state', 'saved');", $script);
}

private function template_CrossSellCartIntegration(): string
{
    return (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Product' . '/view/templates/frontend/widgets/cross-sell.phtml');
}

private function script_CrossSellCartIntegration(): string
{
    return (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Product' . '/view/statics/js/widgets/cross-sell.js');
}

private function assert_CrossSellEmptyShell_testCrossSellEmitsHiddenEmptyShellInsteadOfBlankHtml(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Product' . '/view/templates/frontend/widgets/cross-sell.phtml';
    self::assertFileExists($path);
    $source = (string) file_get_contents($path);
    self::assertStringContainsString('if (count($products) < 2) {', $source);
    self::assertStringContainsString('data-testid="cross-sell-empty"', $source);
    self::assertStringContainsString('hidden', $source);
    self::assertStringContainsString('aria-hidden="true"', $source);
    self::assertStringContainsString('data-testid="cross-sell-deferred"', $source);
    self::assertStringContainsString('StorefrontPdpShelfDeferral::shouldDeferCardAssembly', $source);
    self::assertStringNotContainsString("if (count(\$products) < 2) {\n    return;\n}", $source);
}

private function assert_PdpSecureTrustSpacing_testBuyboxUsesColumnGapAndKeepsZeroChildMargin(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Product' . '/view/statics/css/widgets/product-native-detail.css';
    self::assertFileExists($path);
    $source = (string) file_get_contents($path);
    self::assertStringContainsString('.product-native-detail__buybox > * { margin: 0; }', $source);
    self::assertStringContainsString('gap: var(--weline-space-4', $source);
    self::assertMatchesRegularExpression('/\.product-native-detail__buybox\s*\{[^}]*display:\s*flex[^}]*flex-direction:\s*column/s', $source);
    self::assertMatchesRegularExpression('/\.product-native-detail__buybox\s*>\s*\.product-native-detail__secure\s*\{[^}]*padding-top:\s*var\(--weline-space-2/s', $source);
}

private function assert_PdpSecureTrustSpacing_testPurchaseActionsStackFullWidthWithBreathingGap(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Product' . '/view/statics/css/widgets/product-native-detail.css';
    $source = (string) file_get_contents($path);
    self::assertStringContainsString('.product-native-detail__actions', $source);
    self::assertStringContainsString('inline-size: 100%', $source);
    self::assertStringContainsString('gap: var(--weline-space-4', $source);
    self::assertStringContainsString('.product-native-detail__buybox > .weline-cart-product-add-to-cart', $source);
    self::assertStringNotContainsString('/* Primary CTAs: content-width flow', $source);
}

private const CSS_PdpVerseVerticalLanguage = '/view/statics/css/widgets/product-native-detail.css';

private function css_PdpVerseVerticalLanguage(): string
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Product' . self::CSS_PdpVerseVerticalLanguage;
    self::assertFileExists($path);
    return (string) file_get_contents($path);
}

private function assert_PdpVerseVerticalLanguage_testDesktopCjkKeepsVerticalWriting(): void
{
    $source = $this->css_PdpVerseVerticalLanguage();
    self::assertStringContainsString('writing-mode: vertical-rl;', $source);
    self::assertStringContainsString('.weline-detail-prose--verse-vertical:not([lang^="zh" i], [lang^="ja" i], [lang^="ko" i])', $source);
    self::assertStringContainsString(':not(:lang(zh), :lang(ja), :lang(ko))', $source);
}

private function assert_PdpVerseVerticalLanguage_testNonCjkFallsBackToHorizontalAndWidensPoemAside(): void
{
    $source = $this->css_PdpVerseVerticalLanguage();
    self::assertMatchesRegularExpression('/\.weline-detail-prose--verse-vertical' . ':not\(\[lang\^="zh" i\],\s*\[lang\^="ja" i\],\s*\[lang\^="ko" i\]\)' . ':not\(:lang\(zh\),\s*:lang\(ja\),\s*:lang\(ko\)\)' . '[^{]*\{[^}]*writing-mode:\s*horizontal-tb/s', $source);
    self::assertMatchesRegularExpression('/\.weline-detail-prose--verse-vertical' . ':not\(\[lang\^="zh" i\],\s*\[lang\^="ja" i\],\s*\[lang\^="ko" i\]\)' . '[^{]*\{[^}]*max-height:\s*none/s', $source);
    // 等宽两栏。压缩器吞空白的缺陷已于 2026-10-02 修复，1fr 1fr 仅保留为已验证版式。
    self::assertStringContainsString('.weline-detail-feature--poem-aside:has(.weline-detail-prose--verse-vertical:not([lang^="zh" i], [lang^="ja" i], [lang^="ko" i])', $source);
    self::assertMatchesRegularExpression('/weline-detail-feature--poem-aside:has\([\s\S]*?grid-template-columns:\s*1fr\s+1fr/', $source);
}

private function assert_PdpVerseVerticalLanguage_testMobileDropsPoeticPackagingToHorizontalLightweightLead(): void
{
    $source = $this->css_PdpVerseVerticalLanguage();
    // 锚定「手机：诗意包装降级」注释块，避免匹配到其它 max-width:640 媒体查询
    self::assertMatchesRegularExpression('/手机：诗意包装降级[\s\S]*?@media\s*\(max-width:\s*640px\)\s*\{[\s\S]*?' . '\.weline-detail-prose--verse-vertical\s*\{[\s\S]*?writing-mode:\s*horizontal-tb/', $source);
    self::assertMatchesRegularExpression('/手机：诗意包装降级[\s\S]*?@media\s*\(max-width:\s*640px\)\s*\{[\s\S]*?' . '\.weline-detail-feature--poem-aside\s+\.weline-detail-feature__copy\s*\{[\s\S]*?' . 'border:\s*none/', $source);
    self::assertMatchesRegularExpression('/手机：诗意包装降级[\s\S]*?@media\s*\(max-width:\s*640px\)\s*\{[\s\S]*?' . '\.weline-detail-feature--poem-aside\s+\.weline-detail-feature__copy\s*\{[\s\S]*?' . 'background:\s*var\(--color-bg-secondary/', $source);
}

private function assert_ProductAccountQuotesSignal_testModelExposesCustomerReplySeenFieldsAndStableCodes(): void
{
    $src = (string) file_get_contents(BP . 'app/code/Weline/Product/Model/ProductQuoteRequest.php');
    self::assertStringContainsString("schema_fields_CUSTOMER_ID = 'customer_id'", $src);
    self::assertStringContainsString("schema_fields_ADMIN_REPLY_AT = 'admin_reply_at'", $src);
    self::assertStringContainsString("schema_fields_CUSTOMER_LAST_SEEN_AT = 'customer_last_seen_at'", $src);
    self::assertStringContainsString("MENU_SIGNAL_CODE = 'product.quotes'", $src);
    self::assertStringContainsString("ACCOUNT_SECTION = 'product-quotes'", $src);
}

private function assert_ProductAccountQuotesSignal_testServiceMarksReplyAndSupportsCustomerLifecycle(): void
{
    $src = (string) file_get_contents(BP . 'app/code/Weline/Product/Service/ProductQuoteRequestService.php');
    self::assertStringContainsString('schema_fields_CUSTOMER_ID', $src);
    self::assertStringContainsString('schema_fields_ADMIN_REPLY_AT', $src);
    self::assertStringContainsString('function listForCustomer', $src);
    self::assertStringContainsString('function countUnreadForCustomer', $src);
    self::assertStringContainsString('function markSeenForCustomer', $src);
    self::assertStringContainsString('function claimOrphanQuotesForCustomer', $src);
    self::assertStringNotContainsString('Weline\Inquiry', $src);
}

private function assert_ProductAccountQuotesSignal_testSignalProviderRegistersProductQuotesCode(): void
{
    $path = BP . 'app/code/Weline/Product/extends/module/Weline_Customer/AccountMenuSignalProvider/ProductQuoteMenuSignalProvider.php';
    self::assertFileExists($path);
    $src = (string) file_get_contents($path);
    self::assertStringContainsString('AccountMenuSignalProviderInterface', $src);
    self::assertStringContainsString('MENU_SIGNAL_CODE', $src);
    self::assertStringContainsString('countUnreadForCustomer', $src);
}

private function assert_ProductAccountQuotesSignal_testAccountHooksUseEmptySignalShellsAndStableSection(): void
{
    $commerce = (string) file_get_contents(BP . 'app/code/Weline/Product/view/hooks/account.sidebar.group.commerce.phtml');
    $content = (string) file_get_contents(BP . 'app/code/Weline/Product/view/hooks/account.sidebar.content.phtml');
    $header = (string) file_get_contents(BP . 'app/code/Weline/Product/view/hooks/header-account-links.phtml');
    foreach ([$commerce, $header] as $src) {
        self::assertStringContainsString('data-account-menu-signal', $src);
        self::assertStringContainsString('MENU_SIGNAL_CODE', $src);
        self::assertStringContainsString('account-menu-signal-badge', $src);
        self::assertStringContainsString('hidden', $src);
        self::assertDoesNotMatchRegularExpression('/account-menu-signal-badge[^>]*>\s*\d+/', $src);
    }
    self::assertStringContainsString('ACCOUNT_SECTION', $commerce);
    self::assertStringContainsString('data-account-nav-link', $commerce);
    self::assertStringContainsString('data-account-section', $content);
    self::assertStringContainsString('markSeenForCustomer', $content);
    self::assertStringContainsString('listForCustomer', $content);
    self::assertStringContainsString('data-account-menu-auth="signed-in"', $header);
    self::assertStringNotContainsString('createFrontendSession', $header);
}

private function assert_ProductAccountQuotesSignal_testCustomerMenuSignalsAndJsPaintRemainSsrFree(): void
{
    $query = (string) file_get_contents(BP . 'app/code/Weline/Customer/extends/module/Weline_Framework/Query/AccountQueryProvider.php');
    $js = (string) file_get_contents(BP . 'app/code/Weline/Frontend/view/statics/js/weline-api-account.js');
    $header = (string) file_get_contents(BP . 'app/code/Weline/Theme/view/theme/frontend/widgets/header/account/default.phtml');
    $accountJs = (string) file_get_contents(BP . 'app/code/Weline/Customer/view/statics/js/account-index.js');
    self::assertStringContainsString("'menuSignals'", $query);
    self::assertStringContainsString('AccountMenuSignalAggregator', $query);
    self::assertStringContainsString('paintAccountMenuSignals', $js);
    self::assertStringContainsString('refreshAccountMenuSignals', $js);
    self::assertStringContainsString('data-account-menu-signal-total', $header);
    self::assertDoesNotMatchRegularExpression('/data-account-menu-signal-total[^>]*>\s*\d+/', $header);
    self::assertStringContainsString('refreshAccountMenuSignals', $accountJs);
}

private function assert_ProductAccountQuotesSignal_testModuleVersionAndOptionalCustomer(): void
{
    $module = include BP . 'app/code/Weline/Product/etc/module.php';
    self::assertSame('1.0.137', $module['version'] ?? null);
    self::assertSame('*', $module['optional']['Weline_Customer'] ?? null);
}

private function read_ProductBrandAdmin(string $relative): string
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Product' . '/' . ltrim($relative, '/');
    $content = file_get_contents($path);
    self::assertNotFalse($content, 'Missing file: ' . $relative);
    return $content;
}

private function assert_ProductBrandAdmin_testBrandShardEntityIsDeclared(): void
{
    $key = $this->read_ProductBrandAdmin('Model/ProductShardKey.php');
    $schema = $this->read_ProductBrandAdmin('Service/ProductShardSchemaCatalog.php');
    $model = $this->read_ProductBrandAdmin('Model/Shard/Brand.php');
    self::assertStringContainsString("'brand'", $key);
    self::assertStringContainsString("SCHEMA_VERSION = '4.13.0'", $schema);
    self::assertStringContainsString("'brand' => new TableSchema", $schema);
    self::assertStringContainsString("return 'brand';", $model);
    self::assertStringContainsString('schema_fields_CODE', $model);
    self::assertStringContainsString('STATUS_ACTIVE', $model);
}

private function assert_ProductBrandAdmin_testBrandAdminSurfaceExposesCrudAndMenu(): void
{
    $menu = $this->read_ProductBrandAdmin('etc/backend/menu.xml');
    $controller = $this->read_ProductBrandAdmin('Controller/Backend/Catalog.php');
    $template = $this->read_ProductBrandAdmin('view/templates/backend/catalog/brands.phtml');
    $service = $this->read_ProductBrandAdmin('Service/ProductBrandAdminService.php');
    self::assertStringContainsString('commerce:catalog:brands', $menu);
    self::assertStringContainsString('backend/catalog/brands', $menu);
    self::assertStringContainsString('function brands()', $controller);
    self::assertStringContainsString('function postSaveBrand()', $controller);
    self::assertStringContainsString('function postDisableBrand()', $controller);
    self::assertStringContainsString("action=\"@backend-url('*/backend/catalog/save-brand')\"", $template);
    self::assertStringContainsString("action=\"@backend-url('*/backend/catalog/disable-brand')\"", $template);
    self::assertStringContainsString('csrf="auto"', $template);
    self::assertStringContainsString("@backend-url('*/backend/catalog/brands')", $template);
    self::assertStringNotContainsString('@backend-url(*/backend/catalog/', $template);
    self::assertStringContainsString('<w:d-table', $template);
    self::assertStringContainsString('mode="local"', $template);
    self::assertStringContainsString('local-data-el="#product-brand-local-rows"', $template);
    self::assertStringContainsString('model="Weline\Product\Model\Shard\Brand"', $template);
    self::assertStringContainsString('name="logo_url" type="image"', $template);
    self::assertStringContainsString('product-brand-disable-form', $template);
    self::assertStringContainsString('weline:datatable:row-action', $template);
    self::assertStringNotContainsString('<table class="w-table" data-testid="product-brand-table">', $template);
    self::assertStringContainsString('catalogOptions', $service);
    self::assertStringContainsString('resolveForProduct', $service);
    self::assertStringContainsString('name="supplier_ids"', $template);
    self::assertStringContainsString('w:product:catalog:select', $template);
    self::assertStringContainsString('multiple="true"', $template);
    self::assertStringContainsString('data-testid="product-brand-suppliers"', $template);
    self::assertStringContainsString("'image' => (string)(\$option['image_url'] ?? '')", $template);
    self::assertStringNotContainsString('name="supplier_ids[]"', $template);
    self::assertStringContainsString('data-testid="product-brand-logo"', $template);
    self::assertStringContainsString('data-media-identity-root="product_brand"', $template);
    self::assertStringContainsString('data-media-identity-scope=', $template);
    self::assertStringContainsString('identity_root=product_brand', $template);
    self::assertStringContainsString('media-identity-picker.js', $template);
    self::assertStringContainsString('replaceSuppliersForBrand', $this->read_ProductBrandAdmin('Repository/SupplierBrandRepository.php'));
    $taglib = $this->read_ProductBrandAdmin('Taglib/CatalogEntitySelect.php');
    self::assertStringContainsString('weline-product-catalog-select-chip-thumb', $taglib);
    self::assertStringContainsString('displayableImageUrl', $taglib);
}

private function assert_ProductBrandAdmin_testSaveBrandCsrfFieldAlignsWithWformAuto(): void
{
    $controller = $this->read_ProductBrandAdmin('Controller/Backend/Catalog.php');
    $template = $this->read_ProductBrandAdmin('view/templates/backend/catalog/brands.phtml');
    self::assertMatchesRegularExpression('/protected function csrf\(\): string\s*\{\s*return \'csrf\';/s', $controller);
    self::assertStringNotContainsString('FormKey::key_name', $controller);
    self::assertStringNotContainsString("return 'form_key';", $controller);
    self::assertStringContainsString('csrf="auto"', $template);
    self::assertStringContainsString("'Weline_Product::commerce:catalog:brands:save'", $controller);
    self::assertStringContainsString("'Weline_Product::commerce:catalog:brands:disable'", $controller);
}

private function assert_ProductBrandAdmin_testCreateWizardSelectsBrandFromCatalog(): void
{
    $index = $this->read_ProductBrandAdmin('view/templates/backend/catalog/index.phtml');
    $script = $this->read_ProductBrandAdmin('view/statics/js/backend/product-admin.js');
    $reader = $this->read_ProductBrandAdmin('Service/ProductAdminReadService.php');
    $command = $this->read_ProductBrandAdmin('Service/ProductAdminCommandService.php');
    self::assertStringContainsString("id=\"product-create-brand\"", $index);
    self::assertStringContainsString('name="brand_id"', $index);
    self::assertStringContainsString('$createBrands', $index);
    self::assertStringContainsString("'brands' => \$this->brandAdmin->catalogOptions", $reader);
    self::assertStringContainsString('payload.brand_id = brandId', $script);
    self::assertStringContainsString('payloadWithResolvedBrand', $command);
    self::assertStringContainsString("'brand_code'", $command);
    self::assertStringContainsString('brand: true', $script);
    self::assertStringContainsString('brand_code: true', $script);
    self::assertMatchesRegularExpression('/CREATE_EAV_SKIP_CODES\s*=\s*\{[^}]*\bbrand\s*:/', $script);
    $bootstrap = $this->read_ProductBrandAdmin('Service/ProductCatalogEavBootstrap.php');
    self::assertStringContainsString('DEDICATED_IDENTITY_ATTRIBUTE_CODES', $bootstrap);
    self::assertStringContainsString('detachDedicatedIdentityAttributesFromSets', $bootstrap);
    self::assertStringNotContainsString("['code' => 'brand', 'name' => '品牌']", $bootstrap);
}

private function assert_ProductCardLabelWrap_testProductCardCssScopesBadgeLayoutToLabelsContainerOnly(): void
{
    $css = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Product' . '/view/statics/css/frontend/product-card.css');
    self::assertNotSame('', $css);
    self::assertStringContainsString('.weline-product-card .w-product-labels {', $css);
    self::assertStringContainsString('.weline-product-card .w-product-label {', $css);
    self::assertStringContainsString('padding-inline: var(--weline-space-3)', $css);
    self::assertStringContainsString('letter-spacing: 0', $css);
    self::assertStringNotContainsString(".weline-product-card .wpc-badges,\n.weline-product-card .w-product-labels", $css);
}

private function assert_ProductCardLabelWrap_testProductCardPassesWpcBadgesOnlyAsLabelsContainerClass(): void
{
    $tpl = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Product' . '/view/templates/frontend/partials/product-card.phtml');
    self::assertNotSame('', $tpl);
    self::assertStringContainsString("'class' => 'wpc-badges'", $tpl);
}

private function assert_ProductCardVerticalRhythm_testCategoryPlpDoesNotLeakPagerMarginOntoPurchaseButtons(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Product' . '/view/templates/frontend/category/index.phtml');
    self::assertNotSame('', $src);
    self::assertStringContainsString('.amz-plp__pager {', $src);
    // CTA 布局/色由 .weline-product-card .wpc-cta 拥有；PLP 不得再写 purchase-actions 方言
    self::assertStringNotContainsString('.amz-plp .product-card-purchase-actions {', $src);
    self::assertStringNotContainsString(".amz-plp .amz-card__buy-now:disabled,\n.amz-plp__pager {", $src);
    self::assertStringNotContainsString('margin: 18px 0 0;', $src);
    self::assertStringContainsString('.weline-product-card .wpc-cta', $src);
}

private function assert_ProductCardVerticalRhythm_testProductCardCssUsesSingleCtaAutoMargin(): void
{
    $css = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Product' . '/view/statics/css/frontend/product-card.css');
    self::assertNotSame('', $css);
    self::assertMatchesRegularExpression('/\.weline-product-card \.wpc-cta \{[^}]*margin-top:\s*auto;/s', $css);
    self::assertDoesNotMatchRegularExpression('/\.weline-product-card \.wpc-price \{[^}]*margin-top:\s*auto;/s', $css);
    self::assertStringContainsString('padding-top: 100%', $css);
    self::assertStringContainsString('height: 100%', $css);
    self::assertStringContainsString('object-fit: cover', $css);
    self::assertStringContainsString('padding-inline: var(--weline-space-4', $css);
    // 卡内 CTA 自包含：禁用态保持品牌色透明度，禁止灰底第二方言
    self::assertStringContainsString('.wpc-cta .btn-buy-now:disabled', $css);
    self::assertStringContainsString('opacity: 0.72', $css);
    self::assertStringContainsString('appearance: none', $css);
    self::assertStringContainsString('gap: var(--weline-space-2', $css);
    self::assertDoesNotMatchRegularExpression('/\.wpc-cta [^\{]*:disabled[^\}]*surface-muted/s', $css);
}

private function assert_ProductCardVerticalRhythm_testProductCardPartialOwnsPurchaseCssFlag(): void
{
    $tpl = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Product' . '/view/templates/frontend/partials/product-card.phtml');
    self::assertStringContainsString("'css_owned_by_card' => true", $tpl);
    // 无评也必须渲染评分行，否则网格价签/CTA 上下错位
    self::assertStringContainsString('if ($showRating):', $tpl);
    self::assertStringNotContainsString('if ($showRating && $rating > 0):', $tpl);
    self::assertStringContainsString('$reviewCountSafe', $tpl);
    $helper = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline' . '/Theme/Helper/ProductCardAddToCartParams.php');
    self::assertStringContainsString('card_css_owned_by_product_card', $helper);
    self::assertStringContainsString('if (!$cssOwnedByProductCard)', $helper);
    self::assertStringContainsString('ProductCardAddToCartParams::renderSlot', $tpl);
}

private function moduleRoot_ProductCreateVariantFlow(): string
{
    return '/Users/weline/Project/Official/框架/app/code/Weline/Product';
}

private function read_ProductCreateVariantFlow(string $relativePath): string
{
    $path = $this->moduleRoot_ProductCreateVariantFlow() . '/' . ltrim($relativePath, '/');
    self::assertFileExists($path, 'Missing fixture: ' . $relativePath);
    $content = file_get_contents($path);
    self::assertIsString($content);
    return $content;
}

private function assert_ProductCreateVariantFlow_testCreateConfigurableSubmitUsesCollectedVariantAxesNotManualJson(): void
{
    $script = $this->read_ProductCreateVariantFlow('view/statics/js/backend/product-admin.js');
    foreach (['collectCreateVariantAxes', 'validateCreateVariantAxes', 'renderCreateVariantPreview', 'buildCreateVariantAxisFieldHtml', 'payload.axes = variantAxes.map', 'payload.sku_prefix = payload.sku'] as $marker) {
        self::assertStringContainsString($marker, $script, 'Create flow marker missing: ' . $marker);
    }
    self::assertStringNotContainsString("parseJsonField(\n                            document.getElementById('product-create-axes')", $script);
    self::assertStringNotContainsString("parseJsonField(document.getElementById('product-create-axes')", $script);
}

private function assert_ProductCreateVariantFlow_testEditPageUsesEavControlsWithoutVisibleAdvancedJsonEditors(): void
{
    $edit = $this->read_ProductCreateVariantFlow('view/templates/backend/catalog/edit.phtml');
    self::assertStringNotContainsString('高级：完整属性行 JSON', $edit);
    self::assertStringNotContainsString('高级：扩展字段兼容区', $edit);
    self::assertMatchesRegularExpression('/<textarea[^>]+id="product-edit-attributes"[^>]+hidden/s', $edit);
    self::assertMatchesRegularExpression('/<textarea[^>]+data-provider-unknown[^>]+hidden/s', $edit);
}

private function assert_ProductCreateVariantFlow_testCreateAndEditShareVariantCombinationEngine(): void
{
    $script = $this->read_ProductCreateVariantFlow('view/statics/js/backend/product-admin.js');
    $command = $this->read_ProductCreateVariantFlow('Service/ProductAdminCommandService.php');
    foreach (['function buildVariantCombinations(axes)', 'function generatedVariantSku(prefix, combination, axes)', 'buildVariantCombinations(axes)'] as $marker) {
        self::assertStringContainsString($marker, $script);
    }
    self::assertStringContainsString('variantMatrix->generate(', $command);
    self::assertStringContainsString("if (\$productType === 'configurable')", $command);
    self::assertStringContainsString("\$axes = \$payload['axes'] ?? [];", $command);
}

private function assert_ProductCreateVariantFlow_testVariantAxisGroupUsesChipPickerOnlyForConfigurableType(): void
{
    $script = $this->read_ProductCreateVariantFlow('view/statics/js/backend/product-admin.js');
    $css = $this->read_ProductCreateVariantFlow('view/statics/css/backend/product-admin.css');
    foreach (["code === 'hanfu_variants'", "code.endsWith('_variants')", 'createProductTypeSupportsVariants()', 'buildCreateVariantAxisFieldHtml', 'data-create-variant-axis', 'data-create-variant-option', 'renderCreateEavFields(setId)'] as $marker) {
        self::assertStringContainsString($marker, $script);
    }
    foreach (['w-product-create__axis-chip', 'w-product-create__axis-chip-grid', 'w-product-create__variant-panel', 'w-product-variant__combo-chip', 'w-product-variant__axis-options', 'w-product-variant__axis-attr'] as $marker) {
        self::assertStringContainsString($marker, $css);
    }
    self::assertStringContainsString('data-variant-axis-option', $script);
    self::assertStringContainsString('buildVariantCombinationChips', $script);
    self::assertStringContainsString('listCatalogVariantAxisChoices', $script);
}

private function assert_ProductCreateVariantFlow_testProductLevelAttributesExcludeConfigurableVariantAxes(): void
{
    $script = $this->read_ProductCreateVariantFlow('view/statics/js/backend/product-admin.js');
    self::assertStringContainsString("createProductTypeSupportsVariants() && field.hasAttribute('data-create-variant-axis')", $script);
    self::assertStringContainsString("data-value-type=\"variant-axis\"", $script);
    self::assertStringContainsString("valueType === 'variant-axis'", $script);
}

private function assert_ProductCreateVariantFlow_testWarrantyMonthsUsesPresetSelectOptionsInBootstrap(): void
{
    $bootstrap = $this->read_ProductCreateVariantFlow('Service/ProductCatalogEavBootstrap.php');
    $script = $this->read_ProductCreateVariantFlow('view/statics/js/backend/product-admin.js');
    foreach (['WARRANTY_MONTHS_OPTIONS', 'ensureWarrantyMonthsSelect', "'code' => '12', 'label' => '12 个月'", "'code' => '24', 'label' => '24 个月'", "'code' => '36', 'label' => '36 个月'"] as $marker) {
        self::assertStringContainsString($marker, $bootstrap);
    }
    self::assertStringContainsString("code === 'warranty_months'", $script);
    self::assertStringContainsString("defaultSelected = '12'", $script);
}

private function assert_ProductCreateVariantFlow_testCreateTemplateExposesVariantPreviewPanelNotAxesJsonEditor(): void
{
    $index = $this->read_ProductCreateVariantFlow('view/templates/backend/catalog/index.phtml');
    foreach (['id="product-create-wizard"', 'data-testid="product-create-wizard"', 'data-create-step="variant-preview"', 'id="product-create-variant-preview"', 'data-testid="product-create-variant-preview"', 'id="product-create-variant-values"', 'id="product-create-variant-axes"'] as $marker) {
        self::assertStringContainsString($marker, $index, 'Create template marker missing: ' . $marker);
    }
    $css = $this->read_ProductCreateVariantFlow('view/statics/css/backend/product-admin.css');
    self::assertStringContainsString('w-product-create__variant-preview-scroll', $css);
    self::assertStringContainsString('<textarea id="product-create-axes" name="axes_json" hidden aria-hidden="true"></textarea>', $index);
    self::assertStringNotContainsString('规格轴 JSON', $index);
    self::assertStringNotContainsString('"options":["red","blue"]', $index);
}

private function assert_ProductCreateVariantFlow_testCreateFlowSupportsVariantValueImagesAndFullPreview(): void
{
    $script = $this->read_ProductCreateVariantFlow('view/statics/js/backend/product-admin.js');
    foreach ([
        'renderCreateVariantValueConfig',
        'initializeCreateWizard',
        'maybeAdvanceCreateWizard',
        'openCreateWizardStep',
        'initializeCreateVariantImagePicker',
        'resolveMediaPickerFileUrl',
        'getFirstIncompletePreviousCreateWizardStepId',
        'requiresCreateStepManualConfirm',
        'isCreateStepContentReady',
        'data-create-variant-option-image',
        'w-product-create__variant-preview-scroll',
        'resolveCombinationPreview',
        'swatch_image',
        // Selecting a sample image must refresh preview but never auto-advance.
        "Selecting a preview image must stay on this step",
    ] as $marker) {
        self::assertStringContainsString($marker, $script, 'Create flow marker missing: ' . $marker);
    }
    self::assertStringNotContainsString("maybeAdvanceCreateWizard('variant-values', true);\n            closePicker();", $script, '规格值预览图选图后不得自动下一步');
    self::assertStringNotContainsString("if (isCreateStepContentReady(stepId)) {\n                return true;\n            }\n            var continueButton = getCreateWizardStepContinueButton('variant-values');", $script, 'variant-values 不得因已上传图片就视为步骤完成');
}

private function assert_ProductCreateVariantFlow_testCreateWizardStepsExposeNextButtons(): void
{
    $index = $this->read_ProductCreateVariantFlow('view/templates/backend/catalog/index.phtml');
    foreach (['data-create-step-continue="basics"', 'data-create-step-continue="attribute-set"', 'data-create-step-continue="variant-axes"', 'data-create-step-continue="variant-values"', 'data-create-step-continue="product-attributes"', 'data-create-step-continue="variant-preview"', 'w-product-create__step-actions'] as $marker) {
        self::assertStringContainsString($marker, $index, 'Wizard next button marker missing: ' . $marker);
    }
}

private function assert_ProductCreateVariantFlow_testCatalogCreatePageExposesPanelFocusControls(): void
{
    $index = $this->read_ProductCreateVariantFlow('view/templates/backend/catalog/index.phtml');
    $script = $this->read_ProductCreateVariantFlow('view/statics/js/backend/product-admin.js');
    $css = $this->read_ProductCreateVariantFlow('view/statics/css/backend/product-admin.css');
    foreach (['data-product-list-panel', 'data-product-panel-focus="create"', 'data-product-panel-focus="list"', 'data-testid="product-create-panel-focus"', 'data-testid="product-list-panel-focus"'] as $marker) {
        self::assertStringContainsString($marker, $index, 'Panel focus template marker missing: ' . $marker);
    }
    foreach (['setCatalogPanelMode', 'is-list-focused', 'is-list-pinned', 'data-product-panel-focus', 'PRODUCT_CATALOG_PANEL_MODE_STORAGE_KEY', 'weline.product.catalog.panel_mode', 'readCatalogPanelMode', 'writeCatalogPanelMode', 'readCatalogPanelMode()'] as $marker) {
        self::assertStringContainsString($marker, $script, 'Panel focus script marker missing: ' . $marker);
    }
    foreach (['is-list-focused', 'w-product-panel__header'] as $marker) {
        self::assertStringContainsString($marker, $css, 'Panel focus css marker missing: ' . $marker);
    }
}

private function assert_ProductCreateVariantFlow_testHanfuSchemaDefinesVariantAxesAsSelectAttributes(): void
{
    $bootstrap = $this->read_ProductCreateVariantFlow('Service/ProductCatalogEavBootstrap.php');
    foreach (["'hanfu_variants', '规格维度'", "'code' => 'color'", "'code' => 'size'", "'code' => 'style_type'", 'ensureSelectAttribute('] as $marker) {
        self::assertStringContainsString($marker, $bootstrap);
    }
}

private function assert_ProductCreateVariantFlow_testCreateWizardOrdersProductAttributesBeforeVariantAxes(): void
{
    $index = $this->read_ProductCreateVariantFlow('view/templates/backend/catalog/index.phtml');
    $setFlowStart = strpos($index, 'id="product-create-set-flow"');
    self::assertNotFalse($setFlowStart);
    $setFlow = substr($index, $setFlowStart);
    $productPos = strpos($setFlow, 'data-create-step="product-attributes"');
    $axesPos = strpos($setFlow, 'data-create-step="variant-axes"');
    $valuesPos = strpos($setFlow, 'data-create-step="variant-values"');
    $previewPos = strpos($setFlow, 'data-create-step="variant-preview"');
    self::assertNotFalse($productPos);
    self::assertNotFalse($axesPos);
    self::assertNotFalse($valuesPos);
    self::assertNotFalse($previewPos);
    self::assertLessThan($axesPos, $productPos, '商品属性步必须紧接属性集之后、规格维度之前');
    self::assertLessThan($valuesPos, $axesPos);
    self::assertLessThan($previewPos, $valuesPos);
    self::assertStringContainsString('data-create-step-number="product"', $index);
    self::assertStringContainsString('id="product-create-product-attributes"', $index);
}

private function assert_ProductCreateVariantFlow_testCreateWizardUsesScopePersistenceForDraftAutosave(): void
{
    $index = $this->read_ProductCreateVariantFlow('view/templates/backend/catalog/index.phtml');
    $script = $this->read_ProductCreateVariantFlow('view/statics/js/backend/product-admin.js');
    self::assertStringContainsString("<w:scope url=\"@backend-url('taglib/backend/scope')\" container-id=\"product-create-form\"", $index);
    foreach (['scope="product_create_draft"', 'data-basics-section="identity"', 'data-basics-section="commerce"', 'data-basics-section="shipping"', 'data-basics-section="content"', 'data-basics-section="seo"', 'data-basics-section="categories"', 'data-basics-section="media"', 'w-product-create__basics-grid', 'data-cols="4"', 'id="product-create-type"', 'id="product-create-name"', 'id="product-create-sku"', 'id="product-create-spu"', 'id="product-create-brand"', 'id="product-create-barcode"', 'id="product-create-status"', 'id="product-create-visibility"', 'id="product-create-price"', 'data-label-draft', 'data-label-published', '创建并立即发布', 'id="product-create-cost"', 'id="product-create-currency"', 'id="product-create-stock"', 'id="product-create-weight"', 'id="product-create-length"', 'id="product-create-width"', 'id="product-create-height"', 'id="product-create-short-description"', 'id="product-create-description"', 'id="product-create-slug"', 'data-product-create-slug-status', 'id="product-create-meta-name"', 'id="product-create-meta-description"', 'id="product-create-meta-keywords"', 'id="product-create-media-json"', 'data-product-create-media-picker-open', 'data-w-component="dialog"', 'data-product-create-media-picker-dialog', 'data-media-identity-root="product"', 'data-media-identity-scope=', 'data-product-create-category-assignments', 'w:catalog:category:select', 'id="product-create-categories"', 'allow-create="true"', 'website-id="productCreateCategoryWebsiteId"', '不是属性集里的扩展属性'] as $marker) {
        self::assertStringContainsString($marker, $index);
    }
    self::assertStringContainsString('appendCreatePickerIdentity', $script);
    self::assertStringNotContainsString('data-product-create-category-id', $index);
    self::assertStringNotContainsString('w-product-create__category-list', $index);
    foreach (['name="spu"', 'name="brand_id"', 'name="barcode"', 'name="status"', 'name="visibility"', 'name="stock"', 'name="length"', 'name="width"', 'name="height"', 'name="price"', 'name="cost"', 'name="short_description"', 'name="description"', 'name="slug"', 'name="meta_name"', 'name="meta_keywords"', 'name="media_assignments_json"', 'name="create_eav_attributes_json"', 'name="create_variant_axes_json"', 'name="create_variant_option_images_json"'] as $marker) {
        self::assertStringContainsString($marker, $index);
    }
    self::assertMatchesRegularExpression('/id="product-create-name"[^>]*scope="product_create_draft"|scope="product_create_draft"[^>]*id="product-create-name"/', $index);
    self::assertStringContainsString('event="change"', $index, '创建草稿 scope 不得用 input/click 监听（易在填写时触发主线程风暴）');
    self::assertStringNotContainsString('event="input change click"', $index);
    foreach (["PRODUCT_CREATE_DRAFT_SCOPE = 'product_create_draft'", 'clearProductCreateDraftLocal', 'clearProductCreateDraftRemote', 'hydrateProductCreateDraftUi', 'bindCreateSlugAutoGenerate', 'slugifyProductHandle', 'slugifyProductHandleSegment', 'resolveCreateSlugFromBasics', 'syncCreateSeoTitleFromBasics', 'syncCreateSlugFromBasics', 'hydrateCreateSeoFromDraft', 'scheduleCreateSlugAvailabilityCheck', 'checkCreateSlugAvailability', 'ensureCreateSlugAvailableForSubmit', 'resource.checkSlug', 'payload.price_minor = Math.round(priceYuan * 100)', 'payload.cost = costYuan', 'payload.stock = parseInt(stockRaw, 10)', "payload.media_assignments = createMedia", 'payload.category_assignments = categoryIds.map', "window.WelineCatalogCategorySelect", "product-create-categories", "createStatusIntent === 'published'", 'initializeCreateMedia', 'hydrateCreateMediaFromJson', 'forceHydrateAttributeSetFromDraft', 'persistCreateEavDraft', 'applyCreateEavDraft', 'shouldApplyCreateEavDraft', 'createEavDraftHasValues', '__welineCreateEavUserEdited', 'bindCreateEavDraftPersistence', "pagehide", "#product-create-product-attributes [data-eav-field]", 'delete row.entity_id', 'Never include #product-create-eav-editor', 'last-write-wins in persistCreateEavDraft', 'Keep legacy editor node empty', 'flushProductCreateDraftRemoteNow', '__welineCreateEavDraftDocBound', 'bindCreateEavDraftFieldNodes', 'onCreateEavDraftDomEvent', 'Capture on document so dynamically rendered attribute fields always hit', 'readProductCreateDraftData', 'patchProductCreateDraftLocal', 'openProductMediaDialog', 'closeProductMediaDialog', 'createWizardAdvancing', 'createMediaHydrating', 'createWizardToggleGuard', 'syncCreateMediaJson({silent: true})', 'field input/change must NOT auto-advance', 'No input/change auto-advance', "PRODUCT_CREATE_WIZARD_STEP_STORAGE_KEY = 'weline.product.create.wizard_step'", 'function resumeCreateWizardStep(', 'function rememberCreateWizardStep(', 'function unlockCreateWizardStepsBefore(', 'forceHydrateCreateBasicsFromDraft', 'forceHydrateCategoriesFromDraft', "product-create-categories_chips", 'clearCreateWizardStep()', 'remember: false', 'forceConfirmPreviousStepsForResume', 'Never rewrite remembered step to basics while waiting', 'isHydrateableFormControl', 'HTMLSelectElement', "forceHydrateScopedHiddenFromDraft('product-create-status', 'status')", "forceHydrateScopedHiddenFromDraft('product-create-price', 'price')", 'syncCreateSubmitButtonLabel', 'diagnosticsMessage', 'Clear draft only after create(+publish) fully succeeds', '选择「创建后立即发布」时请填写售价'] as $marker) {
        self::assertStringContainsString($marker, $script);
    }
    self::assertStringContainsString('forceConfirmPreviousStepsForResume', $script, '刷新恢复须重建上一步「下一步」确认，避免弹回 basics');
    self::assertStringContainsString('Never rewrite remembered step to basics while waiting', $script, '步骤尚未可见时不得把记忆覆盖成 basics');
    self::assertStringContainsString('force: true', $script, 'resume 须 force 打开上次步骤，不被中间门禁打回');
    self::assertStringContainsString('warranty_months defaultSelected must not block backfill', $script, '草稿回填不得被质保默认值挡住');
    self::assertStringNotContainsString("openCreateWizardStep('basics');\n            } else if (pinnedMode === 'list'", $script, '进入创建面板不得写死打开 basics，应 resume 上次步骤');
}

private function assert_ProductCreateVariantFlow_testCreateAttributeWriteBindsEntityIdZeroToProduct(): void
{
    $service = $this->read_ProductCreateVariantFlow('Service/ProductAdminCommandService.php');
    self::assertStringContainsString('$this->writeAttributes($command->websiteId, $productId, [', $service, 'create 属性写入须把新建 productId 传给属性写入边界');
    self::assertStringContainsString('if ($entityId <= 0) {', $service);
    self::assertStringContainsString('$entityId = $productId;', $service);
}

private function assert_ProductCreateVariantFlow_testCreateEavRenderFallsBackToSnapshotCatalogAndGatesRequiredAttrs(): void
{
    $script = $this->read_ProductCreateVariantFlow('view/statics/js/backend/product-admin.js');
    foreach (['function resolveCreateAttributeCatalog()', 'state.creation_context.attribute_catalog', 'state.snapshot.attribute_catalog', 'function countExpectedCreateProductAttributeFields(setId)', 'function getCreateSelectedAttributeSetId()', 'product-create-product-attributes-empty', "'product-attributes': '请先按属性组填写必填商品属性。'", 'expectedProductAttrs > 0 && renderedProductFields.length === 0'] as $marker) {
        self::assertStringContainsString($marker, $script, 'Create attribute gate marker missing: ' . $marker);
    }
    self::assertStringNotContainsString("|| stepId === 'product-attributes'\n            || stepId === 'variant-preview'", $script, '商品属性步不得再作为 optional continue 跳过必填');
    self::assertStringContainsString("if (stepId === 'product-attributes') {\n            return isCreateStepContentReady(stepId);\n        }", $script);
}

private function assert_ProductDeliveryModeSlot_testProductInfoExposesEmptyDeliveryModeSlotWithoutHardcodedBadge(): void
{
    $template = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Product' . '/view/templates/frontend/widgets/product-info.phtml');
    $registry = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Product' . '/extends/module/Weline_Widget/Weline_Product/widget.php');
    self::assertStringContainsString('id="product-delivery-mode"', $template);
    self::assertStringContainsString('product-delivery-mode', $template);
    self::assertStringNotContainsString("Weline_Shipping::templates/frontend/widgets/product-delivery-mode.phtml", $template);
    self::assertStringNotContainsString("showPrimeBadge", $template);
    self::assertStringNotContainsString("__('跨境配送')", $template);
    self::assertStringNotContainsString('product-native-detail__prime-badge', $template);
    self::assertStringContainsString("'product-delivery-mode'", $registry);
    self::assertStringContainsString('layout-product-delivery-mode', $registry);
}

private function assert_ProductDetailRevealLazyHydrate_testRevealScriptHydratesDescImagesOnArmShowAndWrapperIo(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Product' . '/view/statics/js/widgets/product-detail-reveal.js';
    self::assertFileExists($path);
    $js = (string) \file_get_contents($path);
    self::assertStringContainsString('function hydrateDescImagesIn(root)', $js);
    self::assertStringContainsString('function observeDescImages(body)', $js);
    self::assertStringContainsString('MEDIA_WRAP_SEL', $js);
    self::assertStringContainsString('hydrateDescImagesIn(el);', $js);
    self::assertStringContainsString('threshold: 0.01', $js);
    self::assertStringContainsString('function hasBeenReached(el)', $js);
    self::assertStringContainsString('if (hasBeenReached(el))', $js);
    // Preload armIo callback must not add is-armed (opacity:0 stuck blank).
    $armIoPos = \strpos($js, 'var armIo = new IntersectionObserver');
    $showIoPos = \strpos($js, 'var showIo = new IntersectionObserver');
    self::assertNotFalse($armIoPos);
    self::assertNotFalse($showIoPos);
    self::assertLessThan($showIoPos, $armIoPos);
    $armIoBlock = \substr($js, $armIoPos, $showIoPos - $armIoPos);
    self::assertStringNotContainsString("classList.add('is-armed')", $armIoBlock, 'armIo must preload only — never add is-armed');
    // Image hydrate is armed before floor pending/animation branches.
    $observePos = \strpos($js, 'function observe(body, list)');
    self::assertNotFalse($observePos);
    $hydratePos = \strpos($js, 'observeDescImages(body);', $observePos);
    $pendingPos = \strpos($js, 'if (!pending.length)', $observePos);
    self::assertNotFalse($hydratePos);
    self::assertNotFalse($pendingPos);
    self::assertLessThan($pendingPos, $hydratePos, 'observeDescImages must run before pending-empty early return');
    // Scrolled-past imgs must hydrate (rect.top check only — no bottom > -280 gate).
    self::assertMatchesRegularExpression('/if\s*\(\s*rect\.top\s*<\s*vh\s*\+\s*280\s*\)\s*\{/', $js, 'desc-image near/passed hydrate must use rect.top < vh+280 only');
}

private function assert_ProductInfoPlacement_testNativeRecommendationsKeepConfigOnlyOnForeignInjectionSlots(): void
{
    $widgetPhp = '/Users/weline/Project/Official/框架/app/code/Weline/Product' . '/extends/module/Weline_Widget/Weline_Product/widget.php';
    $expected = ['related-products' => ['design-product-related-products'], 'you-may-like' => ['design-product-you-may-like'], 'cross-sell' => ['design-product-cross-sell', 'cart-recommendations'], 'recommended-products' => ['design-category-recommendations', 'design-list-recommendations', 'not-found-recommendations']];
    foreach ($expected as $code => $slots) {
        $tpl = 'Weline_Product::templates/frontend/widgets/' . $code . '.phtml';
        self::assertTrue(\Weline\Widget\Test\Support\SlimWidgetPhpListing::listsTemplate($widgetPhp, $tpl), $code);
        $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Product' . '/view/templates/frontend/widgets/' . $code . '.phtml');
        self::assertStringContainsString('@widget.placement {layout}', $src, $code);
        foreach ($slots as $slot) {
            self::assertStringContainsString('"slot":"' . $slot . '"', $src, $code . ':' . $slot);
        }
        self::assertStringContainsString('"placement":"injection"', $src, $code);
        self::assertStringContainsString('"required":true', $src, $code);
        self::assertStringContainsString('"config":', $src, $code);
    }
}

private function assert_ProductInfoPlacement_testNativeInfoRetainsLayoutAndForeignDesignHasDedicatedInjection(): void
{
    $widgetPhp = '/Users/weline/Project/Official/框架/app/code/Weline/Product' . '/extends/module/Weline_Widget/Weline_Product/widget.php';
    $tpl = 'Weline_Product::templates/frontend/widgets/product-info.phtml';
    self::assertTrue(\Weline\Widget\Test\Support\SlimWidgetPhpListing::listsTemplate($widgetPhp, $tpl));
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Product' . '/view/templates/frontend/widgets/product-info.phtml');
    self::assertStringContainsString('@widget.placement {layout}', $src);
    self::assertStringContainsString('"slot":"design-product-main"', $src);
    self::assertStringContainsString('"layout_type":"product"', $src);
    self::assertStringContainsString('"required":true', $src);
    self::assertStringContainsString('"show_brand":true', $src);
    self::assertStringContainsString('"show_supplier":true', $src);
}

private function assert_ProductInfoPurchaseFailsafe_testFailsafeCreatesActionsSlotWhenMissing(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Product' . '/view/templates/frontend/widgets/product-info.phtml');
    self::assertStringContainsString('data-testid="product-add-to-cart"', $src);
    self::assertStringContainsString('ensurePurchaseActionsFromFailsafe', $src);
    self::assertStringContainsString('coalesceBuyboxPurchaseActions', $src);
    self::assertStringContainsString('data-purchase-failsafe', $src);
    // Failsafe bag must not count as a live CTA (HelpPay-only slot used to delete add/buy).
    self::assertStringContainsString('isOutsideFailsafe', $src);
    self::assertStringContainsString("!el.closest('[data-purchase-failsafe]')", $src);
    self::assertStringNotContainsString("fetch('Weline_Cart::templates/frontend/widgets/product-add-to-cart.phtml')", $src);
    self::assertStringNotContainsString("fetch('Weline_Checkout::templates/frontend/widgets/product-buy-now.phtml')", $src);
    self::assertStringContainsString('Do NOT fetch product-add-to-cart', $src);
    self::assertStringContainsString("createElement('div')", $src);
    self::assertStringContainsString('product-native-detail__actions', $src);
    self::assertStringContainsString('data-testid', $src);
    self::assertStringContainsString('product-purchase-actions', $src);
}

private function assert_ProductLayoutAdminPanel_testProductEditPanelExists(): void
{
    $tpl = (string) \file_get_contents(BP . 'app/code/Weline/Product/view/templates/backend/catalog/edit.phtml');
    self::assertStringContainsString('data-product-layout-panel', $tpl);
    self::assertStringContainsString('data-product-layout-create-dialog', $tpl);
    self::assertStringContainsString('data-product-layout-schedule-dialog', $tpl);
    self::assertStringContainsString('theme/backend/theme-editor', $tpl);
}

private function assert_ProductLayoutAdminPanel_testCategoryDefaultLayoutPanelExists(): void
{
    $tpl = (string) \file_get_contents(BP . 'app/code/Weline/Product/view/templates/backend/catalog/categories.phtml');
    self::assertStringContainsString('data-category-product-layout-panel', $tpl);
    self::assertStringContainsString('category_product_default', $tpl);
    self::assertStringContainsString('默认产品布局', $tpl);
    self::assertStringContainsString('Ops tag only', $tpl);
    self::assertStringNotContainsString('leafCode', $tpl);
}

private function assert_ProductLayoutAdminPanel_testProductAdminJsBindsLayoutPanel(): void
{
    $js = (string) \file_get_contents(BP . 'app/code/Weline/Product/view/statics/js/backend/product-admin.js');
    self::assertStringContainsString('initializeProductLayoutPanel', $js);
    self::assertStringContainsString('createProductLayout', $js);
    self::assertStringContainsString('saveProductLayoutSelection', $js);
    self::assertStringContainsString('product_layout_mode', $js);
    self::assertStringNotContainsString('theme_virtual_layout', $js);
}

private function assert_ProductLayoutAdminPanel_testDetailResolvesEffectiveLayoutOption(): void
{
    $source = (string) \file_get_contents(BP . 'app/code/Weline/Product/Controller/Frontend/Detail.php');
    self::assertStringContainsString('ProductLayoutResolveService', $source);
    self::assertStringContainsString('resolveProductLayoutOption', $source);
    self::assertStringContainsString('bustIfScheduleMembershipChanged', $source);
    self::assertStringContainsString("layoutType = 'product.'", $source);
}

private function assert_ProductLayoutAdminPanel_testProductAdminJsSeedsCloneFromOptions(): void
{
    $js = (string) \file_get_contents(BP . 'app/code/Weline/Product/view/statics/js/backend/product-admin.js');
    self::assertStringContainsString('fallbackOptions', $js);
    self::assertStringContainsString('normalizeLayoutOptions', $js);
    self::assertStringContainsString('data-product-layout-create', $js);
    $tpl = (string) \file_get_contents(BP . 'app/code/Weline/Product/view/templates/backend/catalog/edit.phtml');
    self::assertStringContainsString('data-product-layout-clone-from', $tpl);
    self::assertStringContainsString('value="default"', $tpl);
    self::assertStringContainsString('value="festival"', $tpl);
    self::assertStringContainsString('data-product-layout-create-submit', $tpl);
    self::assertStringNotContainsString('method="dialog"', $tpl);
}

private function assert_ProductLayoutAdminPanel_testProductLayoutCreateUsesButtonNotNestedForm(): void
{
    $js = (string) \file_get_contents(BP . 'app/code/Weline/Product/view/statics/js/backend/product-admin.js');
    self::assertStringContainsString('data-product-layout-create-submit', $js);
    self::assertStringContainsString('data-product-layout-schedule-submit', $js);
    self::assertStringNotContainsString("createDialog?.querySelector('form')", $js);
}

private function assert_ProductLayoutAdminPanel_testProductAdminQueryExposesLayoutOperations(): void
{
    $source = (string) \file_get_contents(BP . 'app/code/Weline/Product/extends/module/Weline_Framework/Query/ProductAdminQueryProvider.php');
    foreach (['listProductLayouts', 'createProductLayout', 'saveProductLayoutSelection', 'saveProductLayoutSchedule', 'deleteProductLayoutSchedule'] as $op) {
        self::assertStringContainsString("'" . $op . "'", $source);
    }
}

private function assert_ProductNativeDetailStageFrameMobile_testMobileGalleryStretchesStageWrapForWFrame(): void
{
    $cssPath = BP . 'app/code/Weline/Product/view/statics/css/widgets/product-native-detail.css';
    self::assertFileExists($cssPath);
    $css = (string) file_get_contents($cssPath);
    self::assertMatchesRegularExpression('/@media\s*\(\s*max-width:\s*640px\s*\)\s*\{[\s\S]*?\.product-native-detail__gallery[\s\S]*?align-items:\s*stretch/i', $css, 'Mobile gallery must align-items:stretch so w-frame stage gets width');
    self::assertMatchesRegularExpression('/@media\s*\(\s*max-width:\s*640px\s*\)\s*\{[\s\S]*?\.product-native-detail__stage-wrap\s*\{[\s\S]*?width:\s*100%/i', $css, 'Mobile stage-wrap must be width:100%');
}

private function assert_ProductStickyPurchase_testProductInfoDeclaresStickyPurchaseDockAndModuleLoad(): void
{
    $info = '/Users/weline/Project/Official/框架/app/code/Weline/Product' . '/view/templates/frontend/widgets/product-info.phtml';
    $modules = '/Users/weline/Project/Official/框架/app/code/Weline/Product' . '/view/statics/frontend/weline.modules.js';
    $js = '/Users/weline/Project/Official/框架/app/code/Weline/Product' . '/view/statics/js/widgets/product-sticky-purchase.js';
    self::assertFileExists($info);
    self::assertFileExists($modules);
    self::assertFileExists($js);
    $infoSrc = (string) file_get_contents($info);
    $modulesSrc = (string) file_get_contents($modules);
    $jsSrc = (string) file_get_contents($js);
    self::assertStringContainsString('data-testid="product-sticky-purchase"', $infoSrc);
    self::assertStringContainsString('data-sticky-purchase="1"', $infoSrc);
    self::assertStringContainsString('data-weline-load="productStickyPurchase"', $infoSrc);
    self::assertStringContainsString('data-testid="product-sticky-add-to-cart"', $infoSrc);
    self::assertStringContainsString('data-testid="product-sticky-buy-now"', $infoSrc);
    self::assertStringContainsString('data-sticky-proxy="add"', $infoSrc);
    self::assertStringContainsString('data-sticky-proxy="buy-now"', $infoSrc);
    self::assertStringContainsString('data-testid="product-sticky-specs"', $infoSrc);
    self::assertStringContainsString('data-sticky-specs', $infoSrc);
    self::assertStringContainsString('data-testid="product-sticky-jump-variants"', $infoSrc);
    self::assertStringContainsString('data-sticky-jump-variants', $infoSrc);
    self::assertStringContainsString('data-testid="product-sticky-primary-image"', $infoSrc);
    self::assertStringContainsString('data-sticky-primary-image', $infoSrc);
    // Layout contract: media + title/price on the left; specs + jump on the right.
    $mediaPos = strpos($infoSrc, 'data-testid="product-sticky-media"');
    $metaPos = strpos($infoSrc, 'product-native-detail__sticky-purchase-meta');
    $specsPos = strpos($infoSrc, 'data-testid="product-sticky-specs"');
    $jumpPos = strpos($infoSrc, 'data-testid="product-sticky-jump-variants"');
    self::assertNotFalse($mediaPos);
    self::assertNotFalse($metaPos);
    self::assertNotFalse($specsPos);
    self::assertNotFalse($jumpPos);
    self::assertLessThan($metaPos, $mediaPos, 'primary media must precede title/price meta');
    self::assertLessThan($specsPos, $metaPos, 'title/price meta must precede specs');
    self::assertLessThan($jumpPos, $specsPos, 'specs must precede jump-to-variants');
    self::assertStringContainsString('margin-inline-start: auto', $infoSrc);
    self::assertStringContainsString('!$quickAdd && !$quoteOnly && !$isPreviewMode', $infoSrc);
    self::assertStringContainsString('product-native-detail__sticky-purchase', $infoSrc);
    self::assertStringContainsString('--weline-z-sticky-header', $infoSrc);
    self::assertStringContainsString('env(safe-area-inset-bottom', $infoSrc);
    self::assertStringContainsString('--weline-product-sticky-purchase-clearance', $infoSrc);
    self::assertStringContainsString('has-product-sticky-purchase', $infoSrc);
    self::assertStringContainsString('productStickyPurchase', $modulesSrc);
    self::assertStringContainsString('product-sticky-purchase.js', $modulesSrc);
    self::assertStringContainsString('IntersectionObserver', $jsSrc);
    self::assertStringContainsString('product-add-to-cart', $jsSrc);
    self::assertStringContainsString('product-buy-now', $jsSrc);
    self::assertStringContainsString('data-sticky-proxy', $jsSrc);
    self::assertStringContainsString('data-sticky-jump-variants', $jsSrc);
    self::assertStringContainsString('product-variant-axes', $jsSrc);
    self::assertStringContainsString('scrollIntoView', $jsSrc);
    self::assertStringContainsString('data-sticky-specs', $jsSrc);
    self::assertStringContainsString('data-sticky-primary-image', $jsSrc);
    self::assertStringContainsString('data-quick-add', $jsSrc);
    self::assertStringNotContainsString('Api.resource(', $jsSrc);
    self::assertStringNotContainsString('floating.attach', $jsSrc);
}

private function assert_ProductStockLabel_testRenderStockKeepsPlaceholderSentinel(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Product' . '/view/templates/frontend/widgets/product-info.phtml';
    $jsPath = '/Users/weline/Project/Official/框架/app/code/Weline/Product' . '/view/statics/js/widgets/widget-product-info-0.js';
    self::assertFileExists($path);
    self::assertFileExists($jsPath);
    $src = (string) file_get_contents($path);
    $js = (string) file_get_contents($jsPath);
    self::assertStringContainsString("__('仅剩 %{1} 件', ['%{1}'])", $src);
    self::assertStringContainsString(".replace('%{1}', String(stock))", $js);
    self::assertStringNotContainsString("json_encode((string)__('仅剩 %{1} 件'), JSON_UNESCAPED_UNICODE)", $src);
}

private function assert_ProductStockLabel_testBuyboxFailsafeAndAvailabilitySync(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Product' . '/view/templates/frontend/widgets/product-info.phtml';
    $src = (string) file_get_contents($path);
    self::assertStringContainsString('data-purchase-failsafe', $src);
    self::assertStringContainsString("product-native-detail__qty-row\"<?= \$sellable ? '' : ' hidden' ?>", $src);
    self::assertStringContainsString('product-native-detail__wishlist btn-wishlist', $src);
    self::assertStringContainsString('data-testid="product-add-wishlist"', $src);
    self::assertStringContainsString('data-weline-load="compareShopper,storefrontShopperToast"', $src);
    self::assertStringNotContainsString('if (!$quoteOnly && $sellable):', $src);
    self::assertStringContainsString("__('当前规格暂不可售')", $src);
}

private function read_ProductSupplierAdmin(string $relative): string
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Product' . '/' . ltrim($relative, '/');
    $content = file_get_contents($path);
    self::assertNotFalse($content, 'Missing file: ' . $relative);
    return $content;
}

private function assert_ProductSupplierAdmin_testSupplierShardEntitiesAreDeclared(): void
{
    $key = $this->read_ProductSupplierAdmin('Model/ProductShardKey.php');
    $schema = $this->read_ProductSupplierAdmin('Service/ProductShardSchemaCatalog.php');
    $supplier = $this->read_ProductSupplierAdmin('Model/Shard/Supplier.php');
    $link = $this->read_ProductSupplierAdmin('Model/Shard/ProductSupplier.php');
    self::assertStringContainsString("'supplier'", $key);
    self::assertStringContainsString("'product_supplier'", $key);
    self::assertStringContainsString("SCHEMA_VERSION = '4.13.0'", $schema);
    self::assertStringContainsString("'supplier' => new TableSchema", $schema);
    self::assertStringContainsString("'product_supplier' => new TableSchema", $schema);
    self::assertStringContainsString("'supplier_brand' => new TableSchema", $schema);
    self::assertStringContainsString('store_url', $schema);
    self::assertStringContainsString('supplier_product_url', $schema);
    self::assertStringContainsString('unit_price_minor', $schema);
    self::assertStringContainsString("return 'supplier';", $supplier);
    self::assertStringContainsString("return 'product_supplier';", $link);
}

private function assert_ProductSupplierAdmin_testSupplierAdminSurfaceExposesCrudAndMenu(): void
{
    $menu = $this->read_ProductSupplierAdmin('etc/backend/menu.xml');
    $controller = $this->read_ProductSupplierAdmin('Controller/Backend/Catalog.php');
    $template = $this->read_ProductSupplierAdmin('view/templates/backend/catalog/suppliers.phtml');
    $service = $this->read_ProductSupplierAdmin('Service/ProductSupplierAdminService.php');
    self::assertStringContainsString('commerce:catalog:suppliers', $menu);
    self::assertStringContainsString('backend/catalog/suppliers', $menu);
    self::assertStringContainsString('function suppliers()', $controller);
    self::assertStringContainsString('function postSaveSupplier()', $controller);
    self::assertStringContainsString('function postDisableSupplier()', $controller);
    self::assertStringContainsString("action=\"@backend-url('*/backend/catalog/save-supplier')\"", $template);
    self::assertStringContainsString("action=\"@backend-url('*/backend/catalog/disable-supplier')\"", $template);
    self::assertStringContainsString('csrf="auto"', $template);
    self::assertStringContainsString('name="store_url"', $template);
    self::assertStringContainsString('<w:d-table', $template);
    self::assertStringContainsString('mode="local"', $template);
    self::assertStringContainsString('local-data-el="#product-supplier-local-rows"', $template);
    self::assertStringContainsString('model="Weline\Product\Model\Shard\Supplier"', $template);
    self::assertStringContainsString('name="image_url" type="image"', $template);
    self::assertStringContainsString('product-supplier-disable-form', $template);
    self::assertStringContainsString('weline:datatable:row-action', $template);
    self::assertStringNotContainsString('<table class="w-table" data-testid="product-supplier-table">', $template);
    self::assertStringContainsString('catalogOptions', $service);
    self::assertStringContainsString('upsertPrimaryFromPayload', $service);
    self::assertStringContainsString('replaceBrandsForSupplier', $this->read_ProductSupplierAdmin('Repository/SupplierBrandRepository.php'));
    self::assertStringContainsString('name="brand_ids"', $template);
    self::assertStringContainsString('w:product:catalog:select', $template);
    self::assertStringContainsString('multiple="true"', $template);
    self::assertStringContainsString('data-testid="product-supplier-brands"', $template);
    self::assertStringContainsString("'image' => (string)(\$option['logo_url'] ?? '')", $template);
    self::assertStringContainsString("'image_url' => (string)(\$row['image_url'] ?? '')", $service);
    self::assertStringNotContainsString('name="brand_ids[]"', $template);
    self::assertStringContainsString('data-testid="product-supplier-image"', $template);
    self::assertStringContainsString('data-media-identity-root="product_supplier"', $template);
    self::assertStringContainsString('data-media-identity-scope=', $template);
    self::assertStringContainsString('identity_root=product_supplier', $template);
    self::assertStringContainsString('media-identity-picker.js', $template);
}

private function assert_ProductSupplierAdmin_testCreateWizardSelectsSupplierFromCatalog(): void
{
    $index = $this->read_ProductSupplierAdmin('view/templates/backend/catalog/index.phtml');
    $script = $this->read_ProductSupplierAdmin('view/statics/js/backend/product-admin.js');
    $reader = $this->read_ProductSupplierAdmin('Service/ProductAdminReadService.php');
    $command = $this->read_ProductSupplierAdmin('Service/ProductAdminCommandService.php');
    self::assertStringContainsString('id="product-create-supplier"', $index);
    self::assertStringContainsString('name="supplier_id"', $index);
    self::assertStringContainsString('id="product-create-brand"', $index);
    self::assertStringContainsString('name="brand_id"', $index);
    self::assertStringContainsString('w:product:catalog:select', $index);
    self::assertStringContainsString('product-create-supplier-brand-map', $index);
    self::assertStringContainsString("'image' => (string)(\$supplierOption['image_url'] ?? '')", $index);
    self::assertStringContainsString('syncCreateSupplierBrandCascade', $script);
    self::assertStringContainsString('product-create-supplier-offer', $index);
    self::assertStringContainsString('name="supplier_product_url"', $index);
    self::assertStringContainsString('$createSuppliers', $index);
    self::assertStringContainsString("'suppliers' => \$this->supplierAdmin->catalogOptions", $reader);
    self::assertStringContainsString('payload.supplier_id = supplierId', $script);
    self::assertStringContainsString('bindCreateSupplierOfferPanel', $script);
    self::assertStringContainsString('upsertPrimaryFromPayload', $command);
}

private function assert_RecommendedProductsEmptyShell_testRecommendedProductsEmitsHiddenEmptyShellInsteadOfBlankHtml(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Product' . '/view/templates/frontend/widgets/recommended-products.phtml';
    self::assertFileExists($path);
    $source = (string) file_get_contents($path);
    self::assertStringContainsString('if ($products === []) {', $source);
    self::assertStringContainsString('data-testid="recommended-products-empty"', $source);
    self::assertStringContainsString('hidden', $source);
    self::assertStringContainsString('aria-hidden="true"', $source);
    self::assertStringNotContainsString("if (\$products === []) {\n    return;\n}", $source);
}

private function assert_RecommendedProductsEmptyShell_testRelatedProductsEmitsHiddenEmptyShellInsteadOfBlankHtml(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Product' . '/view/templates/frontend/widgets/related-products.phtml';
    self::assertFileExists($path);
    $source = (string) file_get_contents($path);
    self::assertStringContainsString('data-testid="related-products-empty"', $source);
    self::assertStringContainsString('hidden', $source);
    self::assertStringContainsString('aria-hidden="true"', $source);
}

/**
 * @return list<string>
 */
private function shelfCssPaths_RecommendedProductsMobileMediaQuery(): array
{
    $root = '/Users/weline/Project/Official/框架/app/code/Weline/Product' . '/view/statics/css/widgets';
    return [$root . '/recommended-products.css', $root . '/you-may-like.css', $root . '/related-products.css'];
}

private function assert_RecommendedProductsMobileMediaQuery_testShelfCssUsesLiteralMobileBreakpointsNotCssVars(): void
{
    foreach ($this->shelfCssPaths_RecommendedProductsMobileMediaQuery() as $path) {
        self::assertFileExists($path, $path);
        $css = (string) file_get_contents($path);
        self::assertNotSame('', $css, $path);
        self::assertDoesNotMatchRegularExpression('/@media\s*\(\s*max-width:\s*var\(/i', $css, $path . ' must not use var() inside max-width media queries');
        self::assertMatchesRegularExpression('/@media\s*\(\s*max-width:\s*767px\s*\)/i', $css, $path . ' must declare a literal 767px mobile breakpoint');
        self::assertMatchesRegularExpression('/@media\s*\(\s*max-width:\s*480px\s*\)/i', $css, $path . ' must declare a literal 480px single-column breakpoint');
    }
}

private function assert_YouMayLikeWidget_testRegistrationPinsProductOwnedDefaultInjectionAndListingCard(): void
{
    $widgetPhp = '/Users/weline/Project/Official/框架/app/code/Weline/Product' . '/extends/module/Weline_Widget/Weline_Product/widget.php';
    $tpl = 'Weline_Product::templates/frontend/widgets/you-may-like.phtml';
    self::assertTrue(\Weline\Widget\Test\Support\SlimWidgetPhpListing::listsTemplate($widgetPhp, $tpl));
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Product' . '/view/templates/frontend/widgets/you-may-like.phtml');
    self::assertStringContainsString('@widget.code {you-may-like}', $src);
    self::assertStringContainsString('@widget.placement {layout}', $src);
    self::assertStringContainsString('"slot":"design-product-you-may-like"', $src);
    self::assertStringContainsString('"placement":"injection"', $src);
    self::assertStringContainsString('"required":true', $src);
}
}
