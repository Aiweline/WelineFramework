<?php

declare(strict_types=1);

namespace Weline\Shipping\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Shipping\Service\RegionCascadeEnsureService;

final class ChinaRegionPackContractTest extends TestCase
{
    public function testCnAddressCatalogExistsAndJsonPackRemoved(): void
    {
        $service = new RegionCascadeEnsureService();
        self::assertTrue($service->hasCatalogCoverage('CN'));
        self::assertNull($service->loadPack('CN'));
        $cnDir = dirname(__DIR__, 3) . '/data/address-catalog/CN';
        self::assertFileExists($cnDir . '/provinces.tsv.gz');
        self::assertFileExists($cnDir . '/cities.tsv.gz');
        self::assertFileExists($cnDir . '/districts.tsv.gz');
    }

    public function testRegionQueryProviderDeclaresSuggest(): void
    {
        $path = dirname(__DIR__, 3) . '/extends/module/Weline_Framework/Query/RegionQueryProvider.php';
        $content = (string)file_get_contents($path);
        self::assertStringContainsString("'suggest'", $content);
        self::assertStringContainsString("'format_suggestion'", $content);
        self::assertStringContainsString("'country_profile'", $content);
    }
}
