<?php

declare(strict_types=1);

namespace Weline\Inventory\Test\Unit\Model;

use PHPUnit\Framework\TestCase;
use Weline\I18n\Api\Localization\LocalModel;
use Weline\Inventory\Model\Warehouse;
use Weline\Inventory\Model\Warehouse\LocalDescription;

final class WarehouseLocalDescriptionContractTest extends TestCase
{
    public function testLocalDescriptionExtendsLocalModelWithWarehouseKeys(): void
    {
        self::assertTrue(is_subclass_of(LocalDescription::class, LocalModel::class));
        self::assertSame('weline_inventory_warehouse_local', LocalDescription::schema_table);
        self::assertSame(Warehouse::schema_fields_ID, LocalDescription::schema_primary_key);
        self::assertSame(Warehouse::schema_fields_NAME, LocalDescription::schema_fields_name);
        self::assertSame('warehouse_id', LocalDescription::schema_fields_ID);
    }
}
