<?php

declare(strict_types=1);

namespace Weline\Shipping\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Shipping\Extends\Module\Weline_Shipping\ShippingProvider\YanwenDestinationCoverage;
use Weline\Shipping\Model\CarrierRegion;

/**
 * Yanwen coverage is country-level only, hardcoded in Provider catalog.
 */
final class YanwenDestinationCoverageContractTest extends TestCase
{
    public function testCoverageIsCountryLevelOnly(): void
    {
        $rows = YanwenDestinationCoverage::defaultCoverageRegions();
        self::assertNotEmpty($rows);
        foreach ($rows as $row) {
            self::assertSame(CarrierRegion::TYPE_COUNTRY, $row['region_type']);
            self::assertMatchesRegularExpression('/^[A-Z]{2}$/', $row['country_code']);
            self::assertNull($row['region_id']);
            self::assertNull($row['street_id']);
        }
    }

    public function testIncludesUsExcludesCnAndProvinces(): void
    {
        $codes = YanwenDestinationCoverage::countryCodes();
        self::assertContains('US', $codes);
        self::assertContains('GB', $codes);
        self::assertContains('HK', $codes);
        self::assertNotContains('CN', $codes);
        self::assertTrue(YanwenDestinationCoverage::coversCountry('us'));
        self::assertFalse(YanwenDestinationCoverage::coversCountry('CN'));
    }

    public function testProviderExposesSameCatalog(): void
    {
        $providerSrc = (string)file_get_contents(
            dirname(__DIR__, 3) . '/extends/module/Weline_Shipping/ShippingProvider/YanwenProvider.php',
        );
        self::assertStringContainsString('defaultCoverageRegions', $providerSrc);
        self::assertStringContainsString('YanwenDestinationCoverage', $providerSrc);
        self::assertStringContainsString('destination_not_covered', $providerSrc);
    }

    public function testRegistryPrefersProviderCode(): void
    {
        $registrySrc = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/CarrierCoverageProviderRegistry.php',
        );
        self::assertStringContainsString('defaultCoverageForCode', $registrySrc);
        self::assertStringContainsString('defaultCoverageRegions', $registrySrc);
    }
}
