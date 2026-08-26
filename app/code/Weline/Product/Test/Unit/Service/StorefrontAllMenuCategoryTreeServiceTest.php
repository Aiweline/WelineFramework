<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Product\Service\StorefrontAllMenuCategoryTreeService;

final class StorefrontAllMenuCategoryTreeServiceTest extends TestCase
{
    public function testLogicalCacheKeyIsWebsiteScoped(): void
    {
        self::assertSame(
            'product.all_menu_category_tree.3',
            StorefrontAllMenuCategoryTreeService::logicalCacheKey(3),
        );
        self::assertSame(
            'weline_product_storefront_category_tree',
            StorefrontAllMenuCategoryTreeService::cachePool(),
        );
    }

    public function testNavTreeBuildsFromCatalogQueryConsumer(): void
    {
        $source = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/StorefrontAllMenuCategoryTreeService.php',
        );
        self::assertStringContainsString('ProductCatalogQueryConsumer', $source);
        self::assertStringContainsString('flatRows', $source);
        self::assertStringNotContainsString('StorefrontCategoryTreeIndex', $source);
    }
}
