<?php
declare(strict_types=1);

namespace Weline\Server\Test\Unit\DynamicWarmup;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\DynamicWarmup\HotPathDiscoveryService;

final class HotPathDiscoveryServiceTest extends TestCase
{
    public function testNormalizeRankAndLimitKeepsFrontendGetPagesOnly(): void
    {
        $service = new HotPathDiscoveryService();

        $paths = $service->normalizeRankAndLimit([
            '/api/catalog/products',
            'POST /catalog/category/sports',
            '/static/app.css',
            '/en_US/product/demo-category-81-sports',
            '/',
            '/en_US/catalog/category/sports',
            '/en_US/catalog/category/sports?utm_source=test',
            '/admin/catalog',
            '/pagebuilder/backend/preview',
        ], 4);

        self::assertSame([
            '/',
            '/en_US/catalog/category/sports',
            '/en_US/product/demo-category-81-sports',
        ], $paths);
    }

    public function testDiscoverPinsCriticalClothingCategoryPathsBeforeGeneralSeeds(): void
    {
        $service = new HotPathDiscoveryService();

        $paths = $service->discover(10, [
            '/en_US/catalog/category/sports',
            '/en_US/product/demo-category-81-sports',
        ]);

        self::assertSame('/', $paths[0] ?? null);
        self::assertSame('/catalog/category/clothing', $paths[1] ?? null);
        self::assertSame('/en_US/catalog/category/clothing', $paths[2] ?? null);
        self::assertContains('/USD/en_US/catalog/category/clothing', $paths);
        self::assertContains('/product/demo-category-81-sports', $paths);
        self::assertContains('/en_US/product/demo-category-45-clothing', $paths);
    }

    public function testPreviewAndEditorQueriesAreExcluded(): void
    {
        $service = new HotPathDiscoveryService();

        self::assertNull($service->normalizeFrontendPagePath('/catalog/category/sports?preview=1'));
        self::assertNull($service->normalizeFrontendPagePath('/product/demo?editor=1'));
        self::assertSame('/catalog/category/sports', $service->normalizeFrontendPagePath('/catalog/category/sports?utm_campaign=demo'));
    }

    public function testDeepLongTailCategoriesAreExcludedFromAutoHotPathsByDefault(): void
    {
        $service = new HotPathDiscoveryService();

        self::assertSame('/catalog/category/clothing/men', $service->normalizeFrontendPagePath('/catalog/category/clothing/men'));
        self::assertNull($service->normalizeFrontendPagePath('/catalog/category/clothing/men/t-shirts'));
    }

    public function testMalformedAuthorityUrlIsExcluded(): void
    {
        $service = new HotPathDiscoveryService();

        self::assertNull($service->normalizeFrontendPagePath('https://127.0.0.1]/catalog/category/sports'));
        self::assertSame('/catalog/category/sports', $service->normalizeFrontendPagePath('https://127.0.0.1/catalog/category/sports'));
    }
}
