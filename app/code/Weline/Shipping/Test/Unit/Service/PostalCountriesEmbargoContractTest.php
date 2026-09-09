<?php

declare(strict_types=1);

namespace Weline\Shipping\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

final class PostalCountriesEmbargoContractTest extends TestCase
{
    public function testPostalCountriesReturnsPlaceNameAndEmbargoed(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/RegionService.php',
        );
        self::assertStringContainsString('function postalCountries', $src);
        self::assertStringContainsString("'place_name' => \$placeName", $src);
        self::assertStringContainsString("'embargoed' => \$isEmbargoed", $src);
        self::assertStringContainsString('evaluateAddress', $src);
        self::assertStringContainsString('EmbargoService::class', $src);
    }

    public function testCnPostalCatalogIncludesPudongAlias200100(): void
    {
        $gz = dirname(__DIR__, 3) . '/data/address-catalog/CN/postal.tsv.gz';
        self::assertFileExists($gz);
        $fh = gzopen($gz, 'rb');
        self::assertNotFalse($fh);
        $found = false;
        while (($line = gzgets($fh)) !== false) {
            if (str_starts_with($line, "CN\t200100\t")) {
                self::assertStringContainsString('浦东新区', $line);
                self::assertStringContainsString("\t31\t3101\t310115", $line);
                $found = true;
                break;
            }
        }
        gzclose($fh);
        self::assertTrue($found, 'CN postal catalog must include 200100 → 浦东新区');
    }

    public function testShippingModuleVersionBumpedForPostalEmbargo(): void
    {
        $module = include dirname(__DIR__, 3) . '/etc/module.php';
        self::assertIsArray($module);
        self::assertSame('2.4.73', (string)($module['version'] ?? ''));
    }
}
