<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Product\Service\ProductSearchProjectionMutationCoordinator;
use Weline\Product\Service\ProductSitemapUrlService;

final class ProductPublicUrlChangeTest extends TestCase
{
    public function testCoordinatorEmitsProjectionImpactContract(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 3) . '/Service/ProductSearchProjectionMutationCoordinator.php');
        self::assertStringContainsString("'namespaces' => \$namespaces", $src);
        self::assertStringContainsString("'urls' => \$urlLocs", $src);
        self::assertStringContainsString("'previous_urls' => \$previousLocs", $src);
        self::assertStringContainsString('url_matrix_ready', $src);
        self::assertStringContainsString("'website_ids' => \$websiteIds", $src);
        self::assertStringContainsString('TARGET_STORE_PRODUCT', $src);
        self::assertStringContainsString('getUrlsForGlobalProduct', $src);
        self::assertStringContainsString('\\w_changed($change)', $src);
        self::assertSame('product_search_projection', ProductSearchProjectionMutationCoordinator::RESOURCE_TYPE);
    }

    public function testCoordinatorDoesNotBypassFpcViaBusinessInvalidator(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 3) . '/Service/ProductSearchProjectionMutationCoordinator.php');
        self::assertStringContainsString('\\w_changed($change)', $src);
        self::assertStringNotContainsString('ProductStorefrontCacheInvalidator', $src);
        self::assertStringNotContainsString('product_storefront_fpc_', $src);
    }

    public function testSitemapServiceExposesCrossWebsiteGlobalProductUrls(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 3) . '/Service/ProductSitemapUrlService.php');
        self::assertStringContainsString('function getUrlsForGlobalProduct', $src);
        self::assertStringContainsString('function listWebsiteIdsForGlobalProduct', $src);
        self::assertStringContainsString('function getCatalogListingUrlsForGlobalProduct', $src);
        self::assertStringContainsString('findByGlobalUuid', $src);
        self::assertTrue(method_exists(ProductSitemapUrlService::class, 'getUrlsForGlobalProduct'));
        self::assertTrue(method_exists(ProductSitemapUrlService::class, 'listWebsiteIdsForGlobalProduct'));
    }

    public function testChangedPipelineSkipsReExpandWhenUrlMatrixReady(): void
    {
        $src = (string) file_get_contents(
            dirname(__DIR__, 4) . '/Framework/Event/Changed/ChangedPipeline.php'
        );
        self::assertStringContainsString("url_matrix_ready", $src);
        self::assertStringContainsString('pre-expand', $src);
    }
}
