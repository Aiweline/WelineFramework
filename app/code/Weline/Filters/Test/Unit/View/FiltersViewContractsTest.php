<?php

declare(strict_types=1);

namespace Weline\Filters\Test\Unit\View;

use PHPUnit\Framework\TestCase;


/**
 * Aggregated contract suite (merged from 1 *ContractTest files).
 * Observe one overall pass/fail via testAllFiltersViewContractsPass().
 */
final class FiltersViewContractsTest extends TestCase
{
    public function testAllFiltersViewContractsPass(): void
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
            'CategoryFiltersWidget::testWidgetDeclaresDefaultInjectionsIntoCategoryFiltersSlot' => function () {
            $this->assert_CategoryFiltersWidget_testWidgetDeclaresDefaultInjectionsIntoCategoryFiltersSlot();
        },
            'CategoryFiltersWidget::testWidgetCssPadsFilterPanelAwayFromSidebarEdge' => function () {
            $this->assert_CategoryFiltersWidget_testWidgetCssPadsFilterPanelAwayFromSidebarEdge();
        },
            'CategoryFiltersWidget::testWidgetTemplateExposesAttributeAndPriceContracts' => function () {
            $this->assert_CategoryFiltersWidget_testWidgetTemplateExposesAttributeAndPriceContracts();
        },
            'CategoryFiltersWidget::testPanelServiceExpandsDepartmentNavAlongActivePath' => function () {
            $this->assert_CategoryFiltersWidget_testPanelServiceExpandsDepartmentNavAlongActivePath();
        },
            'CategoryFiltersWidget::testDepartmentNavUsesOneRequestTreeSnapshotForRecursiveWalk' => function () {
            $this->assert_CategoryFiltersWidget_testDepartmentNavUsesOneRequestTreeSnapshotForRecursiveWalk();
        },
            'CategoryFiltersWidget::testDepartmentNavKeepsPrebuiltCategoryUrlsWithoutRelocalizingEachRow' => function () {
            $this->assert_CategoryFiltersWidget_testDepartmentNavKeepsPrebuiltCategoryUrlsWithoutRelocalizingEachRow();
        },
            'CategoryFiltersWidget::testWidgetTemplateUsesProgressiveDisclosureForLongFacetRails' => function () {
            $this->assert_CategoryFiltersWidget_testWidgetTemplateUsesProgressiveDisclosureForLongFacetRails();
        },
            'CategoryFiltersWidget::testWidgetTemplateSortsAttributeOptionsBySelectedCountThenLabel' => function () {
            $this->assert_CategoryFiltersWidget_testWidgetTemplateSortsAttributeOptionsBySelectedCountThenLabel();
        },
            'CategoryFiltersWidget::testWidgetTemplateUsesPathOnlyNeutralPanelWithoutQueryHref' => function () {
            $this->assert_CategoryFiltersWidget_testWidgetTemplateUsesPathOnlyNeutralPanelWithoutQueryHref();
        },
            'CategoryFiltersWidget::testAttributeListingFilterBuildsUrlsViaFrameworkFrontendUrl' => function () {
            $this->assert_CategoryFiltersWidget_testAttributeListingFilterBuildsUrlsViaFrameworkFrontendUrl();
        },
            'CategoryFiltersWidget::testPanelServiceTranslatesFacetsViaFiltersDictionary' => function () {
            $this->assert_CategoryFiltersWidget_testPanelServiceTranslatesFacetsViaFiltersDictionary();
        },
            'CategoryFiltersWidget::testPanelServiceUsesScopedCacheWithStableQueryDimensions' => function () {
            $this->assert_CategoryFiltersWidget_testPanelServiceUsesScopedCacheWithStableQueryDimensions();
        },
            'CategoryFiltersWidget::testListingFallbackUsesCandidatesOnlyOnListingLikePaths' => function () {
            $this->assert_CategoryFiltersWidget_testListingFallbackUsesCandidatesOnlyOnListingLikePaths();
        },
            'CategoryFiltersWidget::testFiltersObservesStorefrontOffersFilterEvent' => function () {
            $this->assert_CategoryFiltersWidget_testFiltersObservesStorefrontOffersFilterEvent();
        },
            'CategoryFiltersWidget::testStorefrontFilterChromeHasEnglishAndArabicTranslations' => function () {
            $this->assert_CategoryFiltersWidget_testStorefrontFilterChromeHasEnglishAndArabicTranslations();
        },
        ];
    }

private function assert_CategoryFiltersWidget_testWidgetDeclaresDefaultInjectionsIntoCategoryFiltersSlot(): void
{
    $widgetPhp = '/Users/weline/Project/Official/框架/app/code/Weline/Filters' . '/extends/module/Weline_Widget/Weline_Filters/widget.php';
    $tpl = 'Weline_Filters::templates/frontend/widgets/category-filters.phtml';
    self::assertTrue(\Weline\Widget\Test\Support\SlimWidgetPhpListing::listsTemplate($widgetPhp, $tpl));
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Filters' . '/view/templates/frontend/widgets/category-filters.phtml');
    self::assertStringContainsString('@widget.code {category-filters}', $src);
    self::assertStringContainsString('@widget.default_injections', $src);
    self::assertStringContainsString('"slot":"category-filters"', $src);
    self::assertStringContainsString('"required":true', $src);
}

private function assert_CategoryFiltersWidget_testWidgetCssPadsFilterPanelAwayFromSidebarEdge(): void
{
    $css = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Filters' . '/view/statics/css/widgets/widget-category-filters.css');
    self::assertStringContainsString('padding-inline: var(--spacing-component-padding', $css);
    self::assertStringContainsString('padding-block: var(--spacing-md', $css);
    self::assertStringContainsString('box-sizing: border-box', $css);
}

private function assert_CategoryFiltersWidget_testWidgetTemplateExposesAttributeAndPriceContracts(): void
{
    $template = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Filters' . '/view/templates/frontend/widgets/category-filters.phtml');
    self::assertStringContainsString('storefront-filters-panel', $template);
    self::assertStringContainsString('storefront-filters-price', $template);
    self::assertStringContainsString('storefront-filters-attribute', $template);
    self::assertStringContainsString('StorefrontFilterPanelService', $template);
    self::assertStringContainsString('resolveListingOffers', $template);
    self::assertStringContainsString('wc-theme_widget_category_filters', $template);
    self::assertStringContainsString('@widget.default_injections', $template);
    self::assertStringContainsString('storefront-filters-dept-current', $template);
    self::assertStringContainsString('data-depth', $template);
    self::assertStringContainsString('w-filters__dept', $template);
}

private function assert_CategoryFiltersWidget_testPanelServiceExpandsDepartmentNavAlongActivePath(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Filters' . '/Service/StorefrontFilterPanelService.php');
    self::assertStringContainsString('function buildDepartmentNav(', $src);
    self::assertStringContainsString('$pathSet[$cursor] = true;', $src);
    self::assertStringContainsString('$byId = is_array($treeIndex[\'by_id\'] ?? null)', $src);
    self::assertStringContainsString('storefront-filters-dept-current', (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Filters' . '/view/templates/frontend/widgets/category-filters.phtml'));
}

private function assert_CategoryFiltersWidget_testDepartmentNavUsesOneRequestTreeSnapshotForRecursiveWalk(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Filters' . '/Service/StorefrontFilterPanelService.php');
    self::assertStringContainsString('$treeIndex = $this->tree->forWebsite($websiteId);', $src);
    self::assertStringContainsString("\$byParent = is_array(\$treeIndex['by_parent'] ?? null)", $src);
    self::assertStringNotContainsString('foreach ($this->tree->childrenOf($websiteId, $parentId) as $child)', $src);
    self::assertStringContainsString('rememberForRequest', $src);
}

private function assert_CategoryFiltersWidget_testDepartmentNavKeepsPrebuiltCategoryUrlsWithoutRelocalizingEachRow(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Filters' . '/Service/StorefrontFilterPanelService.php');
    self::assertStringContainsString('$pathOnly = parse_url($url, PHP_URL_PATH);', $src);
    self::assertStringNotContainsString("{$url} = {$this->localizeStorefrontPath}(ltrim({$pathOnly}, '/'));", $src);
}

private function assert_CategoryFiltersWidget_testWidgetTemplateUsesProgressiveDisclosureForLongFacetRails(): void
{
    $template = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Filters' . '/view/templates/frontend/widgets/category-filters.phtml');
    self::assertStringContainsString('w-filters__disclosure', $template);
    self::assertStringContainsString('w-filters__summary', $template);
    self::assertStringContainsString('w-filters__more', $template);
    self::assertStringContainsString('$visibleOptionLimit = 6', $template);
    self::assertStringContainsString('<lang>查看更多</lang>', $template);
    self::assertStringContainsString('$escape((string)($group[\'name\'] ?? \'\'))', $template);
    self::assertStringContainsString('$escape((string)($opt[\'label\'] ?? \'\'))', $template);
    self::assertStringNotContainsString('__($group[\'name\'])', $template);
    self::assertStringNotContainsString('__($opt[\'label\'])', $template);
}

private function assert_CategoryFiltersWidget_testWidgetTemplateSortsAttributeOptionsBySelectedCountThenLabel(): void
{
    $template = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Filters' . '/view/templates/frontend/widgets/category-filters.phtml');
    self::assertStringContainsString('selected → count DESC → label', $template);
    self::assertStringContainsString('(int)($right[\'count\'] ?? 0) <=> (int)($left[\'count\'] ?? 0)', $template);
    self::assertStringContainsString('$visibleOptionLimit = 6', $template);
    self::assertStringContainsString('w-filters__list--more', $template);
}

private function assert_CategoryFiltersWidget_testWidgetTemplateUsesPathOnlyNeutralPanelWithoutQueryHref(): void
{
    $template = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Filters' . '/view/templates/frontend/widgets/category-filters.phtml');
    self::assertStringContainsString('Path-only FPC', $template);
    self::assertStringContainsString('$query = [];', $template);
    self::assertStringContainsString('data-listing-filter="price"', $template);
    self::assertStringContainsString('data-listing-filter="attr"', $template);
    self::assertStringContainsString('data-listing-filter-clear', $template);
    self::assertStringNotContainsString('href="<?= $escape($opt[\'url\']) ?>"', $template);
    self::assertStringNotContainsString('getQueryParams', $template);
}

private function assert_CategoryFiltersWidget_testAttributeListingFilterBuildsUrlsViaFrameworkFrontendUrl(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Filters' . '/Service/StorefrontAttributeListingFilter.php');
    self::assertStringContainsString('getFrontendUrl', $src);
    self::assertStringContainsString('Weline\Framework\Http\Url', $src);
}

private function assert_CategoryFiltersWidget_testPanelServiceTranslatesFacetsViaFiltersDictionary(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Filters' . '/Service/StorefrontFilterPanelService.php');
    self::assertStringContainsString('StorefrontFacetTranslator', $src);
    self::assertStringContainsString('facetTranslator->translate', $src);
    self::assertFileExists('/Users/weline/Project/Official/框架/app/code/Weline/Filters' . '/Service/StorefrontFacetTranslator.php');
    self::assertStringContainsString('场合,Occasion', (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Filters' . '/i18n/en_US.csv'));
    self::assertStringContainsString('涤纶,Polyester', (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Filters' . '/i18n/en_US.csv'));
}

private function assert_CategoryFiltersWidget_testPanelServiceUsesScopedCacheWithStableQueryDimensions(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Filters' . '/Service/StorefrontFilterPanelService.php');
    self::assertStringContainsString('rememberPolicy', $src);
    self::assertStringContainsString('filterPanelPolicy', $src);
    self::assertStringContainsString('normalizePanelQuery', $src);
    self::assertStringContainsString("str_starts_with(\$key, 'af_')", $src);
}

private function assert_CategoryFiltersWidget_testListingFallbackUsesCandidatesOnlyOnListingLikePaths(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Filters' . '/Service/StorefrontFilterPanelService.php');
    $template = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Filters' . '/view/templates/frontend/widgets/category-filters.phtml');
    self::assertStringContainsString('function isListingLikePath(', $src);
    self::assertStringContainsString('publishedOffersForProductIds($productIds, 120, false)', $src);
    self::assertStringContainsString('publishedListingCandidates(120, false)', $src);
    self::assertStringNotContainsString('publishedOffers(120, false)', $src);
    self::assertStringContainsString('isListingLikePath($requestPath)', $template);
}

private function assert_CategoryFiltersWidget_testFiltersObservesStorefrontOffersFilterEvent(): void
{
    $eventXml = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Filters' . '/etc/event.xml');
    self::assertStringContainsString('Weline_Product::storefront_offers_filter', $eventXml);
    self::assertStringContainsString('Weline\Filters\Observer\StorefrontOffersAttributeFilterObserver', $eventXml);
}

private function assert_CategoryFiltersWidget_testStorefrontFilterChromeHasEnglishAndArabicTranslations(): void
{
    $module = '/Users/weline/Project/Official/框架/app/code/Weline/Filters';
    $english = (string) file_get_contents($module . '/i18n/en_US.csv');
    $arabicPath = $module . '/i18n/ar_SA.csv';
    self::assertStringContainsString('部门,Department', $english);
    self::assertStringContainsString('全部类目,"All categories"', $english);
    self::assertStringContainsString('查看更多,"Show more"', $english);
    self::assertFileExists($arabicPath);
    $arabic = (string) file_get_contents($arabicPath);
    self::assertStringContainsString('部门,القسم', $arabic);
    self::assertStringContainsString('全部类目,"كل الفئات"', $arabic);
    self::assertStringContainsString('查看更多,"عرض المزيد"', $arabic);
}
}
