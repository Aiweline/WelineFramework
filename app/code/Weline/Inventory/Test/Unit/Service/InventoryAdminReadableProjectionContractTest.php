<?php

declare(strict_types=1);

namespace Weline\Inventory\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

final class InventoryAdminReadableProjectionContractTest extends TestCase
{
    public function testStocksAndAdjustmentsProjectReadableChineseColumns(): void
    {
        $source = (string)file_get_contents(BP . 'app/code/Weline/Inventory/Service/InventoryAdminViewService.php');
        $template = (string)file_get_contents(BP . 'app/code/Weline/Inventory/view/templates/backend/inventory/index.phtml');

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
        self::assertStringContainsString('在手库存（件）', (string)file_get_contents(BP . 'app/code/Weline/Inventory/Taglib/OnHandField.php'));
        self::assertStringContainsString('data-testid="inventory-page-subtitle"', $template);
    }
}
