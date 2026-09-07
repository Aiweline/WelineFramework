<?php

declare(strict_types=1);

namespace Weline\Shipping\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Shipping\Service\CarrierCoverageAdminService;
use Weline\Shipping\Service\CarrierCoverageProviderRegistry;
use Weline\Shipping\Service\CoverageRuleMatcher;
use Weline\Shipping\Service\DefaultCarrierCoverageProvider;

final class CarrierCoverageContractTest extends TestCase
{
    public function testDefaultProviderReturnsChinaCountry(): void
    {
        $provider = new DefaultCarrierCoverageProvider();
        self::assertSame('default', $provider->providerCode());
        $rows = $provider->defaultCoverage();
        self::assertNotEmpty($rows);
        self::assertSame('country', $rows[0]['region_type']);
        self::assertSame('CN', $rows[0]['country_code']);
    }

    public function testNormalizeRowsAcceptsMultiSelectionShape(): void
    {
        $om = $this->createMock(\Weline\Framework\Manager\ObjectManager::class);
        $registry = new CarrierCoverageProviderRegistry($om, null);
        $admin = new CarrierCoverageAdminService($om, $registry);
        $rows = $admin->normalizeRows([
            ['region_type' => 'country', 'country_code' => 'cn'],
            ['region_type' => 'province', 'country_code' => 'CN', 'region_id' => 15860, 'region_code' => 'SC', 'region_name' => '四川省'],
            ['region_type' => 'province', 'country_code' => 'CN', 'region_id' => 15860, 'region_code' => 'SC'],
        ]);
        self::assertCount(2, $rows);
        self::assertSame('CN', $rows[0]['country_code']);
        self::assertSame('country', $rows[0]['region_type']);
        self::assertSame(15860, $rows[1]['region_id']);
    }

    public function testFormatRowLabelsIsHumanReadable(): void
    {
        $om = $this->createMock(\Weline\Framework\Manager\ObjectManager::class);
        $registry = new CarrierCoverageProviderRegistry($om, null);
        $admin = new CarrierCoverageAdminService($om, $registry);
        $labels = $admin->formatRowLabels([
            ['region_type' => 'country', 'country_code' => 'CN'],
            ['region_type' => 'province', 'country_code' => 'CN', 'region_id' => 15860, 'region_code' => 'SC', 'region_name' => '四川省'],
        ]);
        self::assertCount(2, $labels);
        self::assertStringContainsString('CN', $labels[0] . $labels[1]);
        self::assertStringNotContainsString('{', $labels[0]);
        self::assertStringContainsString('四川省', $labels[1]);
    }

    public function testCoverageRuleMatcherCountryCoversProvinceAddress(): void
    {
        $om = $this->createMock(\Weline\Framework\Manager\ObjectManager::class);
        $regionService = $this->getMockBuilder(\Weline\Shipping\Service\RegionService::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['findByLocation'])
            ->getMock();
        $regionService->method('findByLocation')->willReturn(null);
        $matcher = new CoverageRuleMatcher($om, $regionService);
        $ok = $matcher->addressMatchesAnyRule(
            ['country_code' => 'CN', 'province' => '四川省', 'city' => '成都市', 'district' => '新都区'],
            [['region_type' => 'country', 'country_code' => 'CN', 'region_id' => 0, 'region_code' => 'CN']],
        );
        self::assertTrue($ok);
    }

    public function testCoverageRuleMatcherRejectsOtherCountry(): void
    {
        $om = $this->createMock(\Weline\Framework\Manager\ObjectManager::class);
        $regionService = $this->getMockBuilder(\Weline\Shipping\Service\RegionService::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['findByLocation'])
            ->getMock();
        $regionService->method('findByLocation')->willReturn(null);
        $matcher = new CoverageRuleMatcher($om, $regionService);
        $ok = $matcher->addressMatchesAnyRule(
            ['country_code' => 'CN', 'province' => '四川省'],
            [['region_type' => 'country', 'country_code' => 'NG', 'region_id' => 0, 'region_code' => 'NG']],
        );
        self::assertFalse($ok);
    }
}
