<?php

declare(strict_types=1);

namespace Weline\Shipping\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

final class RegionGlobalCatalogContractTest extends TestCase
{
    public function testRegionServiceSupportsGlobalCountryCatalog(): void
    {
        $path = dirname(__DIR__, 3) . '/Service/RegionService.php';
        $src = (string)file_get_contents($path);
        self::assertStringContainsString("string \$countryCatalog = 'installed'", $src);
        self::assertStringContainsString('getGlobalCountriesAsRegions', $src);
        self::assertStringContainsString('applyGlobalCountryCatalog', $src);
    }

    public function testRegionQueryProviderExposesCatalogParam(): void
    {
        $path = dirname(__DIR__, 3) . '/extends/module/Weline_Framework/Query/RegionQueryProvider.php';
        $src = (string)file_get_contents($path);
        self::assertStringContainsString("'catalog'", $src);
        self::assertStringContainsString("'global'", $src);
    }

    public function testFrontendRegionControllerAcceptsCatalogParam(): void
    {
        $path = dirname(__DIR__, 3) . '/Controller/Frontend/Region.php';
        $src = (string)file_get_contents($path);
        self::assertStringContainsString("getParam('catalog'", $src);
        self::assertStringContainsString("'global'", $src);
    }

    public function testAddressJsUsesHttpFetchForGlobalCatalog(): void
    {
        $path = dirname(__DIR__, 4) . '/Theme/view/statics/js/address.js';
        $src = (string)file_get_contents($path);
        self::assertStringContainsString('fetchRegionsFromSource', $src);
        self::assertStringContainsString("catalog === 'global'", $src);
        self::assertStringContainsString('catalog=global', $src);
    }
}
