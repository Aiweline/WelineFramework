<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

final class StorefrontListingShellPathOnlyContractTest extends TestCase
{
    public function testShellOwnsSharedFilterPipelineAndIgnoresVisitorQueryOnNeutralApi(): void
    {
        $shell = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/StorefrontListingShell.php',
        );

        self::assertStringContainsString('function projectNeutral(', $shell);
        self::assertStringContainsString('function projectFiltered(', $shell);
        self::assertStringContainsString('Weline_Product::storefront_offers_filter', $shell);
        self::assertStringContainsString('normalizeQuery(', $shell);
        self::assertStringContainsString("str_starts_with(\$key, 'af_')", $shell);
        // Soft PLP: page bag presentation-complete before projectFromOffers;
        // releaseCallerCatalog may stay true while hydrateMedia is true.
        self::assertStringContainsString('hydrateListingCardPresentation', $shell);
        self::assertStringContainsString('?bool $releaseCallerCatalog = null', $shell);
        self::assertStringContainsString(
            'releaseCallerCatalog: $releaseCallerCatalog ?? !$hydrateMedia',
            $shell,
        );
    }

    public function testCatalogPresentationHydrateMergesSummaryNamesAfterMedia(): void
    {
        $catalog = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/StorefrontCatalogViewService.php',
        );

        self::assertStringContainsString('function hydrateListingCardPresentation(', $catalog);
        self::assertStringContainsString('hydrateListingMedia($offers)', $catalog);
        self::assertStringContainsString('publishedOffersForProductIds(', $catalog);
        self::assertStringContainsString('product.catalog.listing_card_names', $catalog);
        // Must not invent a second FE card renderer — shell still uses ProductCardRenderer.
        $shell = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/StorefrontListingShell.php',
        );
        self::assertStringContainsString('ProductCardRenderer::projectFromOffers', $shell);
    }

    public function testCatalogAndCategoryControllersDelegateToShellNeutral(): void
    {
        $catalog = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Controller/Frontend/Catalog.php',
        );
        $category = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Controller/Frontend/Category.php',
        );

        self::assertStringContainsString('StorefrontListingShell', $catalog);
        self::assertStringContainsString('projectNeutral(', $catalog);
        self::assertStringContainsString('StorefrontListingShell', $category);
        self::assertStringContainsString('projectNeutral(', $category);
        self::assertStringNotContainsString("str_starts_with(\$queryKey, 'af_')", $catalog);
        self::assertStringNotContainsString("str_starts_with(\$queryKey, 'af_')", $category);
    }

    public function testListingTemplatesBindJsModuleWithoutFullPageSortNav(): void
    {
        $catalog = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/templates/frontend/catalog/index.phtml',
        );
        $modules = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/statics/frontend/weline.modules.js',
        );
        $js = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/statics/js/storefront-listing.js',
        );

        self::assertStringContainsString('data-weline-load="storefrontListing"', $catalog);
        self::assertStringContainsString('storefrontListing', $modules);
        self::assertStringContainsString('filteredListingPage', $js);
        self::assertStringContainsString('product_storefront', $js);
        self::assertStringContainsString('DEBOUNCE_MS', $js);
        self::assertStringContainsString('scheduleRefresh', $js);
        self::assertStringContainsString('is-listing-loading', $js);
        self::assertStringContainsString('data-listing-loading', $js);
        self::assertStringContainsString('committed', $js);
        self::assertStringContainsString('revertToCommitted', $js);
        self::assertStringContainsString('failStreak', $js);
        self::assertStringContainsString('paintFacetCounts', $js);
        self::assertStringContainsString('facet_counts', $js);
        self::assertStringContainsString('hasSoftListingQuery', $js);
        self::assertStringContainsString('hydrateStateFromLocation', $js);
        self::assertStringContainsString('data-listing-loading-label', $catalog);
        self::assertStringNotContainsString('window.location.href', $catalog);
    }

    public function testListingJsPaintsFacetCountsWithoutTouchingWelineApi(): void
    {
        $js = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/statics/js/storefront-listing.js',
        );
        $api = (string)file_get_contents(
            dirname(__DIR__, 4) . '/Frontend/view/statics/js/weline-api.js',
        );

        self::assertStringContainsString('function paintFacetCounts', $js);
        self::assertStringContainsString('payload.facet_counts', $js);
        self::assertStringContainsString('[data-listing-filter="price"][data-filter-value]', $js);
        self::assertStringContainsString('[data-listing-filter="attr"][data-filter-code][data-filter-value]', $js);
        self::assertStringContainsString('.w-filters__count', $js);
        self::assertStringContainsString('row.hidden = count === 0 && !selected', $js);
        // FE-1 R3: omitted slim options → count 0 (never early-return leaving SSR residuals).
        self::assertSame(2, substr_count($js, 'item ? item.count : 0'));
        self::assertStringContainsString('Array.isArray(attrs)', $js);
        self::assertStringContainsString('committedFacetCounts', $js);
        self::assertStringContainsString('resetFacetVisibilitySsrNeutral', $js);
        self::assertStringContainsString('paintFacetCounts(state.committedFacetCounts, state)', $js);
        self::assertStringContainsString('compactVisibleAttributeFacets', $js);
        self::assertStringContainsString('FACET_PRIMARY_LIMIT = 6', $js);
        self::assertStringContainsString('w-filters__list--more', $js);
        self::assertStringContainsString('w-filters__more', $js);
        self::assertStringNotContainsString("if (!item) {\n                    return;\n                }", $js);
        self::assertStringNotContainsString("if (!item) {\n                        return;\n                    }", $js);
        self::assertStringNotContainsString('facet_counts', $api);
        self::assertStringNotContainsString('paintFacetCounts', $api);
        self::assertStringNotContainsString('refinement', $api);
    }
}
