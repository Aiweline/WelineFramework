<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\Extends\Module\Weline_Search;

use PHPUnit\Framework\TestCase;

/**
 * Storefront product search must not hydrate the full hit set before pagination.
 */
final class ProductSearchProviderPerfContractTest extends TestCase
{
    public function testExecutePaginatesBeforePresenterAndSkipsEmptyQuery(): void
    {
        $source = (string)file_get_contents(
            dirname(__DIR__, 5)
            . '/extends/module/Weline_Search/Searcher/ProductSearchProvider.php',
        );

        self::assertStringContainsString("trim(\$request->q) === ''", $source);
        self::assertStringContainsString('skipped_empty_query', $source);
        self::assertStringContainsString('dedupeRowsByProductId', $source);
        self::assertStringContainsString('array_slice($deduped, $offset, $limit)', $source);
        self::assertStringContainsString('$this->hitPresenter->prepareRows($pageRows)', $source);
        self::assertStringNotContainsString(
            '$rows = $this->hitPresenter->prepareRows($rows);',
            $source,
        );
    }

    public function testProjectionSnapshotUsesBulkSelectionAndProcessCache(): void
    {
        $source = (string)file_get_contents(
            dirname(__DIR__, 5) . '/Service/ProductSearchProjectionService.php',
        );
        $repo = (string)file_get_contents(
            dirname(__DIR__, 5) . '/Repository/StoreProductRepository.php',
        );

        self::assertStringContainsString('SNAPSHOT_PROCESS_CACHE_MAX', $source);
        self::assertStringContainsString('$snapshotProcessCache', $source);
        self::assertStringContainsString('resolveOffersByUuids', $source);
        self::assertStringContainsString('resolveProductsByUuids', $source);
        self::assertStringContainsString('selectionMap($websiteId, $storeId, $productIds)', $source);
        self::assertStringContainsString('selectionMap($websiteId, $storeId, $allOfferIds)', $source);
        self::assertStringNotContainsString(
            'storeProducts->isSelected($websiteId, $storeId, $productId)',
            $source,
        );
        self::assertStringContainsString('function selectionMap(', $repo);
    }
}
