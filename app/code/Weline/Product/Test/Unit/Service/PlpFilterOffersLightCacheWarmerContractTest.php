<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Product\Service\PlpFilterOffersLightCacheWarmer;
use Weline\Product\Service\StorefrontCatalogViewService;

/** Guard: Product PLP light warmer provides + light-only paths (no 1000-row / no chrome). */
final class PlpFilterOffersLightCacheWarmerContractTest extends TestCase
{
    public function testModuleProvidesPlpLightWarmer(): void
    {
        $module = require dirname(__DIR__, 3) . '/etc/module.php';
        $provides = $module['provides'] ?? [];
        self::assertSame(
            PlpFilterOffersLightCacheWarmer::class,
            $provides['cache.warmer.product.plp_light'] ?? null,
        );
        self::assertSame('1.0.390', (string)($module['version'] ?? ''));
    }

    public function testWarmerTargetsProductPoolAfterShelf(): void
    {
        $warmer = new PlpFilterOffersLightCacheWarmer();
        self::assertSame(StorefrontCatalogViewService::cachePool(), $warmer->getTargetPool());
        self::assertSame('product.plp_light', $warmer->getName());
        self::assertSame(235, $warmer->getPriority());
        self::assertGreaterThan(230, $warmer->getPriority());
    }

    public function testClearDiscoversViaProductPoolWithoutFqcn(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 4) . '/Framework/Cache/Console/Cache/Clear.php'
        );
        self::assertStringContainsString("warmUp('product')", $src);
        self::assertStringNotContainsString('PlpFilterOffersLightCacheWarmer', $src);
    }

    public function testWarmerUsesLightPathsOnly(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/PlpFilterOffersLightCacheWarmer.php'
        );
        self::assertStringContainsString('publishedListingCandidates', $src);
        self::assertStringContainsString('publishedOfferSummaries', $src);
        self::assertStringContainsString('facetCountsForProductIds', $src);
        self::assertStringContainsString('buildPanel', $src);
        self::assertStringContainsString('StorefrontFilterPanelService', $src);
        self::assertStringContainsString('TIP_SCOPES', $src);
        self::assertStringContainsString('BUDGET_MS', $src);
        self::assertStringNotContainsString('publishedOffers(1000', $src);
        self::assertDoesNotMatchRegularExpression('/->publishedOffers\s*\(/', $src);
        self::assertStringNotContainsString('renderPartials', $src);
        self::assertStringNotContainsString("warmUp('weline_theme_storefront_chrome')", $src);
        self::assertStringNotContainsString('Partials::', $src);
    }
}
