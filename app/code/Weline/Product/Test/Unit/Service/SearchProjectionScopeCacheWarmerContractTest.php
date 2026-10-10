<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Product\Service\SearchProjectionScopeCacheWarmer;
use Weline\Product\Service\StorefrontCatalogViewService;
use Weline\Product\Service\StorefrontHotCacheBagSeeder;

/** Guard: Clear warmUp product pool + Product provides search projection scope warmer. */
final class SearchProjectionScopeCacheWarmerContractTest extends TestCase
{
    public function testModuleProvidesSearchProjectionScopeWarmer(): void
    {
        $module = require dirname(__DIR__, 3) . '/etc/module.php';
        $provides = $module['provides'] ?? [];
        self::assertSame(
            SearchProjectionScopeCacheWarmer::class,
            $provides['cache.warmer.product.search_projection_scope'] ?? null,
        );
    }

    public function testWarmerTargetsProductPool(): void
    {
        $warmer = new SearchProjectionScopeCacheWarmer();
        self::assertSame(StorefrontCatalogViewService::cachePool(), $warmer->getTargetPool());
        self::assertSame('product.search_projection_scope', $warmer->getName());
    }

    public function testClearWarmsProductPoolWithoutProductFqcn(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 4) . '/Framework/Cache/Console/Cache/Clear.php'
        );
        self::assertStringContainsString("warmUp('product')", $src);
        self::assertStringNotContainsString('SearchProjectionScopeCacheWarmer', $src);
    }

    public function testBagSeederPrimesSearchProjectionScope(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/StorefrontHotCacheBagSeeder.php'
        );
        self::assertStringContainsString('primeSearchProjectionScope', $src);
        self::assertStringContainsString('product.search_projection_scope', $src);
        self::assertTrue(method_exists(StorefrontHotCacheBagSeeder::class, 'prime'));
    }

    public function testWarmerDoesNotCallSnapshotWebsite(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/SearchProjectionScopeCacheWarmer.php'
        );
        self::assertStringContainsString('snapshotScope', $src);
        self::assertStringNotContainsString('->snapshotWebsite(', $src);
        self::assertStringNotContainsString('::snapshotWebsite(', $src);
    }
}
