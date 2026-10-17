<?php

declare(strict_types=1);

namespace Weline\Inventory\Test\Unit\Model;

use PHPUnit\Framework\TestCase;
use Weline\I18n\Api\Localization\LocalModel;
use Weline\Inventory\Model\Warehouse;
use Weline\Inventory\Model\WarehouseCodeLabel;
use Weline\Inventory\Model\WarehouseCodeLabel\LocalDescription as WarehouseCodeLabelLocalDescription;
use Weline\Inventory\Model\Warehouse\LocalDescription as WarehouseLocalDescription;
use Weline\Inventory\Service\WarehouseCodeLabelAdminService;

/**
 * Aggregated contract suite (merged from 2 *ContractTest files).
 * Observe one overall pass/fail via testAllInventoryModelContractsPass().
 */
final class InventoryModelContractsTest extends TestCase
{
    public function testAllInventoryModelContractsPass(): void
    {
        $failures = [];
        foreach ($this->contractChecks() as $name => $fn) {
            try {
                $fn();
            } catch (\Throwable $e) {
                $failures[] = $name . ": " . $e->getMessage();
            }
        }
        self::assertSame([], $failures, implode("\n", $failures));
    }

    /** @return array<string, callable(): void> */
    private function contractChecks(): array
    {
        return [
            'WarehouseCodeLabel::testLocalDescriptionExtendsLocalModel' => function () {
            $this->assert_WarehouseCodeLabel_testLocalDescriptionExtendsLocalModel();
        },
            'WarehouseCodeLabel::testAdminServiceContractSurface' => function () {
            $this->assert_WarehouseCodeLabel_testAdminServiceContractSurface();
        },
            'WarehouseLocalDescription::testLocalDescriptionExtendsLocalModelWithWarehouseKeys' => function () {
            $this->assert_WarehouseLocalDescription_testLocalDescriptionExtendsLocalModelWithWarehouseKeys();
        },
        ];
    }

private function assert_WarehouseCodeLabel_testLocalDescriptionExtendsLocalModel(): void
{
    self::assertTrue(is_subclass_of(LocalDescription::class, LocalModel::class));
    self::assertSame('weline_inventory_warehouse_code_label_local', LocalDescription::schema_table);
    self::assertSame(WarehouseCodeLabel::schema_fields_ID, LocalDescription::schema_primary_key);
    self::assertSame(WarehouseCodeLabel::schema_fields_LABEL_NAME, LocalDescription::schema_fields_name);
}

private function assert_WarehouseCodeLabel_testAdminServiceContractSurface(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Inventory' . '/Service/WarehouseCodeLabelAdminService.php');
    self::assertStringContainsString('DEFAULT_SEEDS', $src);
    self::assertTrue(method_exists(WarehouseCodeLabelAdminService::class, 'seedDefaults'));
    self::assertTrue(method_exists(WarehouseCodeLabelAdminService::class, 'listAll'));
    self::assertTrue(method_exists(WarehouseCodeLabelAdminService::class, 'labelFor'));
    self::assertTrue(method_exists(WarehouseCodeLabelAdminService::class, 'save'));
    self::assertTrue(method_exists(WarehouseCodeLabelAdminService::class, 'delete'));
}

private function assert_WarehouseLocalDescription_testLocalDescriptionExtendsLocalModelWithWarehouseKeys(): void
{
    self::assertTrue(is_subclass_of(LocalDescription::class, LocalModel::class));
    self::assertSame('weline_inventory_warehouse_local', LocalDescription::schema_table);
    self::assertSame(Warehouse::schema_fields_ID, LocalDescription::schema_primary_key);
    self::assertSame(Warehouse::schema_fields_NAME, LocalDescription::schema_fields_name);
    self::assertSame('warehouse_id', LocalDescription::schema_fields_ID);
}
}
