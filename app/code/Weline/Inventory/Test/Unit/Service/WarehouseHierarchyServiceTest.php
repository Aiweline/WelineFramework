<?php

declare(strict_types=1);

namespace Weline\Inventory\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Inventory\Model\Warehouse;
use Weline\Inventory\Service\WarehouseHierarchyService;

final class WarehouseHierarchyServiceTest extends TestCase
{
    public function testBuildCodeJoinsCountryThenProvinceThenWarehouse(): void
    {
        self::assertSame('CN', WarehouseHierarchyService::buildCode(null, 'cn'));
        self::assertSame('CN-GD', WarehouseHierarchyService::buildCode('CN', 'gd'));
        self::assertSame('CN-GD-WH01', WarehouseHierarchyService::buildCode('CN-GD', 'wh01'));
        self::assertSame('US-CA-LAX', WarehouseHierarchyService::buildCode('US-CA', 'US-CA-LAX'));
    }

    public function testAssertCodeImmutableRejectsChange(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        WarehouseHierarchyService::assertCodeImmutable('CN-GD-WH01', 'CN-GD-WH02');
    }

    public function testAssertCodeImmutableAllowsSameCode(): void
    {
        WarehouseHierarchyService::assertCodeImmutable('CN-GD-WH01', 'cn-gd-wh01');
        $this->addToAssertionCount(1);
    }

    public function testDefaultCatalogCoversMajorFulfillmentCountries(): void
    {
        $codes = array_column(WarehouseHierarchyService::defaultCatalog(), 'country_code');
        foreach (['CN', 'US', 'GB', 'DE', 'JP', 'AU', 'SG'] as $expected) {
            self::assertContains($expected, $codes);
        }
        // CJ 远程仓覆盖国应有对应本地默认叶仓种子
        foreach (['BR', 'ES', 'MX', 'NG', 'PH', 'RO', 'TH', 'VN', 'AE', 'CA', 'FR'] as $expected) {
            self::assertContains($expected, $codes);
        }
        $cn = WarehouseHierarchyService::defaultCatalog()[0];
        self::assertSame('CN', $cn['country_code']);
        self::assertNotEmpty($cn['provinces']);
    }

    public function testOrderAsTreeGroupsCountryProvinceWarehouse(): void
    {
        $rows = [
            [
                Warehouse::schema_fields_ID => 2,
                Warehouse::schema_fields_PARENT_ID => 1,
                Warehouse::schema_fields_WAREHOUSE_CODE => 'CN-GD',
                Warehouse::schema_fields_NODE_KIND => Warehouse::NODE_PROVINCE,
            ],
            [
                Warehouse::schema_fields_ID => 3,
                Warehouse::schema_fields_PARENT_ID => 2,
                Warehouse::schema_fields_WAREHOUSE_CODE => 'CN-GD-WH01',
                Warehouse::schema_fields_NODE_KIND => Warehouse::NODE_WAREHOUSE,
            ],
            [
                Warehouse::schema_fields_ID => 1,
                Warehouse::schema_fields_PARENT_ID => 0,
                Warehouse::schema_fields_WAREHOUSE_CODE => 'CN',
                Warehouse::schema_fields_NODE_KIND => Warehouse::NODE_COUNTRY,
            ],
            [
                Warehouse::schema_fields_ID => 4,
                Warehouse::schema_fields_PARENT_ID => 0,
                Warehouse::schema_fields_WAREHOUSE_CODE => 'US',
                Warehouse::schema_fields_NODE_KIND => Warehouse::NODE_COUNTRY,
            ],
        ];
        $ordered = WarehouseHierarchyService::orderAsTree($rows);
        self::assertSame(['CN', 'CN-GD', 'CN-GD-WH01', 'US'], array_column($ordered, Warehouse::schema_fields_WAREHOUSE_CODE));
        self::assertSame([0, 1, 2, 0], array_column($ordered, '_depth'));
    }
}
