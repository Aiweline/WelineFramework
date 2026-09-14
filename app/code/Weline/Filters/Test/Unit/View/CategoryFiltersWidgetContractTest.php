<?php

declare(strict_types=1);

namespace Weline\Filters\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class CategoryFiltersWidgetContractTest extends TestCase
{
    public function testWidgetDeclaresDefaultInjectionsIntoCategoryFiltersSlot(): void
    {
        $widget = require dirname(__DIR__, 3) . '/extends/module/Weline_Widget/Weline_Filters/widget.php';
        $cfg = $widget['category-filters'] ?? [];

        self::assertSame('category-filters', $cfg['code'] ?? null);
        self::assertSame('Weline_Filters::templates/frontend/widgets/category-filters.phtml', $cfg['template'] ?? null);
        $injections = $cfg['default_injections'] ?? [];
        self::assertNotEmpty($injections);
        $slots = array_column($injections, 'slot');
        self::assertContains('category-filters', $slots);
        self::assertTrue((bool)($injections[0]['required'] ?? false));
    }

    public function testWidgetTemplateExposesAttributeAndPriceContracts(): void
    {
        $template = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/templates/frontend/widgets/category-filters.phtml',
        );

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

    public function testPanelServiceExpandsDepartmentNavAlongActivePath(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/StorefrontFilterPanelService.php',
        );
        self::assertStringContainsString('function buildDepartmentNav(', $src);
        self::assertStringContainsString('$pathSet[$cursor] = true;', $src);
        self::assertStringContainsString('$byId = is_array($treeIndex[\'by_id\'] ?? null)', $src);
        self::assertStringContainsString('storefront-filters-dept-current', (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/templates/frontend/widgets/category-filters.phtml',
        ));
    }

    public function testDepartmentNavUsesOneRequestTreeSnapshotForRecursiveWalk(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/StorefrontFilterPanelService.php',
        );

        self::assertStringContainsString('$treeIndex = $this->tree->forWebsite($websiteId);', $src);
        self::assertStringContainsString("\$byParent = is_array(\$treeIndex['by_parent'] ?? null)", $src);
        self::assertStringNotContainsString('foreach ($this->tree->childrenOf($websiteId, $parentId) as $child)', $src);
        self::assertStringContainsString('rememberForRequest', $src);
    }

    public function testDepartmentNavKeepsPrebuiltCategoryUrlsWithoutRelocalizingEachRow(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/StorefrontFilterPanelService.php',
        );

        self::assertStringContainsString('$pathOnly = parse_url($url, PHP_URL_PATH);', $src);
        self::assertStringNotContainsString(
            "$url = $this->localizeStorefrontPath(ltrim($pathOnly, '/'));",
            $src,
        );
    }

    public function testWidgetTemplateUsesProgressiveDisclosureForLongFacetRails(): void
    {
        $template = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/templates/frontend/widgets/category-filters.phtml',
        );

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

    public function testWidgetTemplateResolvesFilterQueryFromRequestParams(): void
    {
        $template = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/templates/frontend/widgets/category-filters.phtml',
        );

        self::assertStringContainsString('getQueryParams', $template);
        self::assertStringContainsString('QUERY_STRING', $template);
        self::assertStringContainsString('WELINE_ORIGIN_REQUEST_URI', $template);
    }

    public function testAttributeListingFilterBuildsUrlsViaFrameworkFrontendUrl(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/StorefrontAttributeListingFilter.php',
        );
        self::assertStringContainsString('getFrontendUrl', $src);
        self::assertStringContainsString('Weline\\Framework\\Http\\Url', $src);
    }

    public function testPanelServiceTranslatesFacetsViaFiltersDictionary(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/StorefrontFilterPanelService.php',
        );
        self::assertStringContainsString('StorefrontFacetTranslator', $src);
        self::assertStringContainsString('facetTranslator->translate', $src);
        self::assertFileExists(dirname(__DIR__, 3) . '/Service/StorefrontFacetTranslator.php');
        self::assertStringContainsString('场合,Occasion', (string)file_get_contents(
            dirname(__DIR__, 3) . '/i18n/en_US.csv',
        ));
        self::assertStringContainsString('涤纶,Polyester', (string)file_get_contents(
            dirname(__DIR__, 3) . '/i18n/en_US.csv',
        ));
    }

    public function testPanelServiceUsesScopedCacheWithStableQueryDimensions(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/StorefrontFilterPanelService.php',
        );

        self::assertStringContainsString('rememberPolicy', $src);
        self::assertStringContainsString('filterPanelPolicy', $src);
        self::assertStringContainsString('normalizePanelQuery', $src);
        self::assertStringContainsString("str_starts_with(\$key, 'af_')", $src);
    }

    public function testListingFallbackUsesSummaryProjection(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/StorefrontFilterPanelService.php',
        );

        self::assertStringContainsString(
            'publishedOffersForProductIds($productIds, 120, false)',
            $src,
        );
        self::assertStringContainsString(
            'publishedOffers(120, false)',
            $src,
        );
    }

    public function testFiltersObservesStorefrontOffersFilterEvent(): void
    {
        $eventXml = (string)file_get_contents(dirname(__DIR__, 3) . '/etc/event.xml');
        self::assertStringContainsString('Weline_Product::storefront_offers_filter', $eventXml);
        self::assertStringContainsString(
            'Weline\\Filters\\Observer\\StorefrontOffersAttributeFilterObserver',
            $eventXml,
        );
    }

    public function testStorefrontFilterChromeHasEnglishAndArabicTranslations(): void
    {
        $module = dirname(__DIR__, 3);
        $english = (string)file_get_contents($module . '/i18n/en_US.csv');
        $arabicPath = $module . '/i18n/ar_SA.csv';

        self::assertStringContainsString('部门,Department', $english);
        self::assertStringContainsString('全部类目,"All categories"', $english);
        self::assertStringContainsString('查看更多,"Show more"', $english);
        self::assertFileExists($arabicPath);

        $arabic = (string)file_get_contents($arabicPath);
        self::assertStringContainsString('部门,القسم', $arabic);
        self::assertStringContainsString('全部类目,"كل الفئات"', $arabic);
        self::assertStringContainsString('查看更多,"عرض المزيد"', $arabic);
    }
}
