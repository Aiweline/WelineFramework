<?php

declare(strict_types=1);

namespace Weline\Inventory\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Inventory\Api\FulfillmentSplitPlanInterface;
use Weline\Inventory\Service\FulfillmentSplitPlanService;

/**
 * Aggregated contract suite (merged from 2 *ContractTest files).
 * Observe one overall pass/fail via testAllInventoryServiceContractsPass().
 */
final class InventoryServiceContractsTest extends TestCase
{
    public function testAllInventoryServiceContractsPass(): void
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
            'FulfillmentSplitPlan::testInterfaceAndDeterministicOrderingInSource' => function () {
            $this->assert_FulfillmentSplitPlan_testInterfaceAndDeterministicOrderingInSource();
        },
            'InventoryAdminReadableProjection::testStocksAndAdjustmentsProjectReadableChineseColumns' => function () {
            $this->assert_InventoryAdminReadableProjection_testStocksAndAdjustmentsProjectReadableChineseColumns();
        },
        ];
    }

private function assert_FulfillmentSplitPlan_testInterfaceAndDeterministicOrderingInSource(): void
{
    self::assertTrue(interface_exists(FulfillmentSplitPlanInterface::class));
    self::assertTrue(class_exists(FulfillmentSplitPlanService::class));
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Inventory' . '/Service/FulfillmentSplitPlanService.php');
    self::assertStringContainsString('split_key', $src);
    self::assertStringContainsString('wh:', $src);
    self::assertStringContainsString('preferred_warehouse_id', $src);
    self::assertStringContainsString('resolveDefault', $src);
    self::assertStringNotContainsString('mt_rand', $src);
    self::assertStringNotContainsString('array_rand', $src);
    $module = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Inventory' . '/etc/module.php');
    self::assertStringContainsString('FulfillmentSplitPlanInterface', $module);
    self::assertStringContainsString('2.5.25', $module);
}

private function assert_InventoryAdminReadableProjection_testStocksAndAdjustmentsProjectReadableChineseColumns(): void
{
    $source = (string) file_get_contents(BP . 'app/code/Weline/Inventory/Service/InventoryAdminViewService.php');
    $template = (string) file_get_contents(BP . 'app/code/Weline/Inventory/view/templates/backend/inventory/index.phtml');
    self::assertStringContainsString("'product' => (string)__('商品')", $source);
    self::assertStringContainsString("'on_hand' => (string)__('在手（件）')", $source);
    self::assertStringContainsString("'reserved' => (string)__('预占（件）')", $source);
    self::assertStringContainsString("'available' => (string)__('可售（件）')", $source);
    self::assertStringContainsString("'qty_delta' => (string)__('变动（件）')", $source);
    self::assertStringContainsString("'strict' => '严格'", $source);
    self::assertStringContainsString('offerLabels', $source);
    self::assertStringContainsString('fallbackOfferLabel', $source);
    self::assertStringContainsString('projectStocks', $source);
    self::assertStringContainsString('projectAdjustments', $source);
    self::assertStringContainsString('adjustmentRows', $source);
    self::assertStringContainsString('->limit($limit)', $source);
    self::assertStringContainsString('inventory-readable-table', $template);
    self::assertStringContainsString('<lang>库存管理</lang>', $template);
    self::assertStringNotContainsString('库存内核管理工作台', $template);
    self::assertStringContainsString('$columnLabels[$column]', $template);
    self::assertStringContainsString('w:inventory:on-hand:field', $template);
    self::assertStringContainsString('在手库存（件）', (string) file_get_contents(BP . 'app/code/Weline/Inventory/Taglib/OnHandField.php'));
    self::assertStringContainsString('data-testid="inventory-page-subtitle"', $template);
}
}
