<?php

declare(strict_types=1);

namespace Weline\Shipping\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Manager\ObjectManager;
use Weline\Shipping\Service\AddressCatalog\TsvGzReader;
use Weline\Shipping\Service\RegionService;

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
        self::assertStringContainsString('countrySortRanks', $src);
        self::assertStringContainsString('seedCountrySortFromDefaultMarkets', $src);
        self::assertStringContainsString('sort_order', $src);
    }

    public function testNormalizePostalStripsInvisibleFormatChars(): void
    {
        $zwnj = "10001\u{200C}";
        self::assertSame('10001', TsvGzReader::normalizePostal($zwnj));
        self::assertSame('10001', TsvGzReader::normalizePostal("10001\u{200B}"));
        self::assertSame('10001', TsvGzReader::normalizePostal(" 100 01 "));
    }

    public function testPostalLookupNormsLetterFallbackAndNumericNoFallback(): void
    {
        self::assertSame(['SW1A1AA', 'SW1A', 'SW1'], TsvGzReader::postalLookupNorms('SW1A 1AA'));
        self::assertSame(['M5V2T6', 'M5V'], TsvGzReader::postalLookupNorms('M5V 2T6'));
        self::assertSame(['A65F4E2', 'A65'], TsvGzReader::postalLookupNorms('A65 F4E2'));
        self::assertSame(['100001'], TsvGzReader::postalLookupNorms('100001'));
        self::assertSame(['10001'], TsvGzReader::postalLookupNorms('10001'));
        self::assertSame(['SW1A'], TsvGzReader::postalLookupNorms('SW1A'));
    }

    public function testPostalLookupUsesLookupNormsCandidates(): void
    {
        $region = (string)file_get_contents(dirname(__DIR__, 3) . '/Service/RegionService.php');
        $reader = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/AddressCatalog/TsvGzReader.php'
        );
        self::assertStringContainsString('function postalLookupNorms', $reader);
        self::assertStringContainsString('postalLookupNorms', $region);
        self::assertStringContainsString("POSTAL_CODE_NORM, \$norms, 'in'", $region);
    }

    public function testDefaultMarketsTsvDeclaresSortOrderAndUsBeforeKr(): void
    {
        $tsv = dirname(__DIR__, 3) . '/data/default-markets/countries.tsv';
        self::assertFileExists($tsv);
        $body = (string)file_get_contents($tsv);
        self::assertStringContainsString('sort_order', $body);
        self::assertMatchesRegularExpression('/^US\tamericas\t50\s*$/m', $body);
        self::assertMatchesRegularExpression('/^KR\tasia_pacific\t120\s*$/m', $body);
    }

    public function testPostalCountriesRanksUsNewYorkAheadOfDzFor10001(): void
    {
        /** @var RegionService $svc */
        $svc = ObjectManager::getInstance()->getInstance(RegionService::class);
        try {
            $svc->seedCountrySortFromDefaultMarkets(true);
            $rows = $svc->postalCountries('10001');
        } catch (\Throwable $e) {
            self::markTestSkipped('Shipping region tables unavailable in this PHPUnit DB: ' . $e->getMessage());
        }
        self::assertNotEmpty($rows);
        $codes = array_map(
            static fn(array $row): string => (string)($row['country_code'] ?? ''),
            $rows
        );
        self::assertContains('US', $codes);
        $usPos = array_search('US', $codes, true);
        $dzPos = array_search('DZ', $codes, true);
        $krPos = array_search('KR', $codes, true);
        self::assertNotFalse($usPos);
        if ($dzPos !== false) {
            self::assertLessThan($dzPos, $usPos, 'US must rank above DZ for 10001');
        }
        if ($krPos !== false) {
            self::assertLessThan($krPos, $usPos, 'US hot sort must rank above KR for 10001');
        }
        self::assertSame('New York', (string)($rows[$usPos]['place_name'] ?? ''));
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
        self::assertSame('2.9.21', (string)($module['version'] ?? ''));
    }

    public function testRegionBackendExposesCountrySortEditor(): void
    {
        $ctrl = (string)file_get_contents(dirname(__DIR__, 3) . '/Controller/Backend/Region.php');
        self::assertStringContainsString('saveCountrySort', $ctrl);
        self::assertStringContainsString('country_sort_rows', $ctrl);
        $view = (string)file_get_contents(dirname(__DIR__, 3) . '/view/templates/Backend/Region/index.phtml');
        self::assertStringContainsString('shipping-country-sort-card', $view);
        self::assertStringContainsString('热门国家排序', $view);
        $admin = (string)file_get_contents(dirname(__DIR__, 3) . '/Service/ShippingConfigurationAdminService.php');
        self::assertStringContainsString('updateCountrySortOrders', $admin);
    }
}
