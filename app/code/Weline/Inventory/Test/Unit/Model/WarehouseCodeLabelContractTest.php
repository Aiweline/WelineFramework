<?php

declare(strict_types=1);

namespace Weline\Inventory\Test\Unit\Model;

use PHPUnit\Framework\TestCase;
use Weline\I18n\Api\Localization\LocalModel;
use Weline\Inventory\Model\WarehouseCodeLabel;
use Weline\Inventory\Model\WarehouseCodeLabel\LocalDescription;
use Weline\Inventory\Service\WarehouseCodeLabelAdminService;

final class WarehouseCodeLabelContractTest extends TestCase
{
    public function testLocalDescriptionExtendsLocalModel(): void
    {
        self::assertTrue(is_subclass_of(LocalDescription::class, LocalModel::class));
        self::assertSame('weline_inventory_warehouse_code_label_local', LocalDescription::schema_table);
        self::assertSame(WarehouseCodeLabel::schema_fields_ID, LocalDescription::schema_primary_key);
        self::assertSame(WarehouseCodeLabel::schema_fields_LABEL_NAME, LocalDescription::schema_fields_name);
    }

    public function testAdminServiceContractSurface(): void
    {
        $src = (string)file_get_contents(dirname(__DIR__, 3) . '/Service/WarehouseCodeLabelAdminService.php');
        self::assertStringContainsString('DEFAULT_SEEDS', $src);
        self::assertTrue(method_exists(WarehouseCodeLabelAdminService::class, 'seedDefaults'));
        self::assertTrue(method_exists(WarehouseCodeLabelAdminService::class, 'listAll'));
        self::assertTrue(method_exists(WarehouseCodeLabelAdminService::class, 'labelFor'));
        self::assertTrue(method_exists(WarehouseCodeLabelAdminService::class, 'save'));
        self::assertTrue(method_exists(WarehouseCodeLabelAdminService::class, 'delete'));
    }
}
