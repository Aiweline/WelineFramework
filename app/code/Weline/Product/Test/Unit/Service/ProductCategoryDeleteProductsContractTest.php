<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

final class ProductCategoryDeleteProductsContractTest extends TestCase
{
    public function testDeletePipelineUsesAnyStoreRecomputeAndTransaction(): void
    {
        $admin = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/ProductCategoryAdminService.php',
        );
        $physical = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/ProductPhysicalDeleteService.php',
        );
        $provider = (string)file_get_contents(
            dirname(__DIR__, 3) . '/extends/module/Weline_Catalog/Space/ProductCatalogSpaceProvider.php',
        );

        self::assertStringContainsString('listByCategoryIdsAnyStore', $admin);
        self::assertStringContainsString('listByProductIdsAnyStore', $admin);
        self::assertStringContainsString('ProductCategoryExclusiveClassifier::intersectSelected', $admin);
        self::assertStringContainsString('ProductCategoryExclusiveClassifier::classify', $admin);
        self::assertStringContainsString('withWebsiteTransaction', $admin);
        self::assertStringContainsString('getQuery()', $admin);
        self::assertStringContainsString('beginTransaction', $admin);
        self::assertStringContainsString('ProductPhysicalDeleteService', $admin);
        self::assertStringContainsString('DELETE_PRODUCT_LIST_LIMIT = 200', $admin);

        self::assertStringContainsString("purgeOfferIds", $physical);
        self::assertStringContainsString('purgeCatalogOffers', $physical);
        self::assertStringContainsString('purgeProductIds', $physical);
        self::assertStringContainsString('deleteByProductIds', $physical);
        self::assertStringContainsString('inventoryModuleEnabled', $physical);
        self::assertStringContainsString('product_physical_delete_inventory_unavailable', $physical);

        self::assertStringContainsString('listProductsForDelete', $provider);
        self::assertStringContainsString("options['product_ids']", $provider);
    }
}
