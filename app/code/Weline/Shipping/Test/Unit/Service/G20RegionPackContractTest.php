<?php

declare(strict_types=1);

namespace Weline\Shipping\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Shipping\Service\AddressCountryProfileService;
use Weline\Shipping\Service\RegionCascadeEnsureService;

final class G20RegionPackContractTest extends TestCase
{
    /** @return list<string> */
    private function catalogCountries(): array
    {
        return ['AR', 'AU', 'BR', 'CA', 'FR', 'DE', 'IN', 'ID', 'IT', 'JP', 'KR', 'MX', 'RU', 'SA', 'ZA', 'TR', 'GB', 'US', 'CN', 'HK', 'MO', 'TW', 'NZ'];
    }

    public function testAddressCatalogProvincesExistForCoveredCountries(): void
    {
        $service = new RegionCascadeEnsureService();
        foreach ($this->catalogCountries() as $cc) {
            self::assertTrue(
                $service->hasCatalogCoverage($cc),
                $cc . ' should have address-catalog/provinces.tsv.gz'
            );
            self::assertNull($service->loadPack($cc), 'JSON packs removed');
        }
    }

    public function testGeonamesFilledCountriesHaveProvinces(): void
    {
        $service = new RegionCascadeEnsureService();
        foreach (['PY', 'EG', 'AI'] as $cc) {
            self::assertTrue($service->hasCatalogCoverage($cc), $cc . ' should be filled from GeoNames ADM1');
        }
        self::assertFalse($service->hasCatalogCoverage('ZZ'), 'ZZ is not a real catalog country');
    }

    public function testProfilesOpenDistrictForNestedG20Only(): void
    {
        $profiles = new AddressCountryProfileService();
        self::assertContains('district', $profiles->profileFor('FR')['levels']);
        self::assertContains('district', $profiles->profileFor('GB')['levels']);
        self::assertContains('district', $profiles->profileFor('IT')['levels']);
        self::assertNotContains('district', $profiles->profileFor('US')['levels']);
        self::assertNotContains('district', $profiles->profileFor('JP')['levels']);
    }
}
