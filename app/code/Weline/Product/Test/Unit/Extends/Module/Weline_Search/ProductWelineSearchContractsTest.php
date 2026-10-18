<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\Extends\Module\Weline_Search;

use PHPUnit\Framework\TestCase;


/**
 * Aggregated contract suite (merged from 2 *ContractTest files).
 * Observe one overall pass/fail via testAllProductWeline_SearchContractsPass().
 */
final class ProductWelineSearchContractsTest extends TestCase
{
    public function testAllProductWeline_SearchContractsPass(): void
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
            'ProductSearchHitTemplate::testProductSearchProviderDeclaresProductOwnedHitTemplate' => function () {
            $this->assert_ProductSearchHitTemplate_testProductSearchProviderDeclaresProductOwnedHitTemplate();
        },
            'ProductSearchProviderPerf::testExecutePaginatesBeforePresenterAndSkipsEmptyQuery' => function () {
            $this->assert_ProductSearchProviderPerf_testExecutePaginatesBeforePresenterAndSkipsEmptyQuery();
        },
            'ProductSearchProviderPerf::testProjectionSnapshotUsesBulkSelectionAndProcessCache' => function () {
            $this->assert_ProductSearchProviderPerf_testProjectionSnapshotUsesBulkSelectionAndProcessCache();
        },
        ];
    }

private function assert_ProductSearchHitTemplate_testProductSearchProviderDeclaresProductOwnedHitTemplate(): void
{
    $provider = (string) file_get_contents(BP . 'app/code/Weline/Product/extends/module/Weline_Search/Searcher/ProductSearchProvider.php');
    $hitTemplate = (string) file_get_contents(BP . 'app/code/Weline/Product/view/templates/frontend/search/hit.phtml');
    $offerCard = (string) file_get_contents(BP . 'app/code/Weline/Product/view/templates/frontend/partials/storefront-offer-card.phtml');
    self::assertStringContainsString("return 'Weline_Product::templates/frontend/search/hit.phtml';", $provider);
    self::assertStringContainsString('ProductSearchHitPresenter', $provider);
    self::assertStringContainsString('storefront-offer-card.phtml', $hitTemplate);
    self::assertStringNotContainsString('hit-card.phtml', $hitTemplate);
    self::assertStringContainsString('search_offer_stub', $hitTemplate);
    $presenter = (string) file_get_contents(BP . 'app/code/Weline/Product/Service/ProductSearchHitPresenter.php');
    self::assertStringContainsString('publishedOffer($productId)', $presenter);
    self::assertStringContainsString('stubOfferFromSearchRow', $presenter);
    self::assertStringContainsString('StorefrontImagePlaceholder::url', $presenter);
    self::assertStringContainsString('ProductCardRenderer::render', $offerCard);
    self::assertStringContainsString('ProductCardRenderer::fromStorefrontOffer', $offerCard);
    self::assertStringContainsString("'show_sku' => true", $offerCard);
    self::assertStringNotContainsString('<w:product:card', $offerCard);
    self::assertStringNotContainsString('product-storefront__card product-card', $offerCard);
}

private function assert_ProductSearchProviderPerf_testExecutePaginatesBeforePresenterAndSkipsEmptyQuery(): void
{
    $source = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Product' . '/extends/module/Weline_Search/Searcher/ProductSearchProvider.php');
    self::assertStringContainsString("trim(\$request->q) === ''", $source);
    self::assertStringContainsString('skipped_empty_query', $source);
    self::assertStringContainsString('dedupeRowsByProductId', $source);
    self::assertStringContainsString('array_slice($deduped, $offset, $limit)', $source);
    self::assertStringContainsString('$this->hitPresenter->prepareRows($pageRows)', $source);
    self::assertStringNotContainsString('$rows = $this->hitPresenter->prepareRows($rows);', $source);
}

private function assert_ProductSearchProviderPerf_testProjectionSnapshotUsesBulkSelectionAndProcessCache(): void
{
    $source = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Product' . '/Service/ProductSearchProjectionService.php');
    $repo = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Product' . '/Repository/StoreProductRepository.php');
    $coordinator = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Product' . '/Service/StorefrontCatalogCacheCoordinator.php');
    self::assertStringContainsString('SNAPSHOT_PROCESS_CACHE_MAX', $source);
    self::assertStringContainsString('$snapshotProcessCache', $source);
    self::assertStringContainsString('searchProjectionScopePolicy', $source);
    self::assertStringContainsString('rememberPolicy', $source);
    self::assertStringContainsString('resolveOffersByUuids', $source);
    self::assertStringContainsString('resolveProductsByUuids', $source);
    self::assertStringContainsString('selectionMap($websiteId, $storeId, $productIds)', $source);
    self::assertStringContainsString('selectionMap($websiteId, $storeId, $allOfferIds)', $source);
    self::assertStringNotContainsString('storeProducts->isSelected($websiteId, $storeId, $productId)', $source);
    self::assertStringContainsString('function selectionMap(', $repo);
    self::assertStringContainsString('searchProjectionScopePolicy', $coordinator);
    self::assertStringContainsString('product.search_projection_scope', $coordinator);
    $policy = \Weline\Product\Service\StorefrontCatalogCacheCoordinator::searchProjectionScopePolicy();
    // Rebuild is multi-second; short wait causes concurrent uncontended rebuilds.
    self::assertGreaterThanOrEqual(15000, $policy->singleFlightWaitMs);
}
}
