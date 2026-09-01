<?php

declare(strict_types=1);

namespace Weline\Inventory\Test\Unit\Controller\Backend;

use PHPUnit\Framework\TestCase;

final class InventoryAdminSurfaceContractTest extends TestCase
{
    /** @var array<string,string> */
    private const FEATURES = [
        'stocks' => 'stocks',
        'adjustments' => 'adjustments',
        'warehouses' => 'warehouses',
        'authorizations' => 'authorizations',
        'reservations' => 'reservations',
        'leases' => 'leases',
        'ledger' => 'ledger',
        'migration' => 'migration',
    ];

    /** @var array<string,string> */
    private const WRITE_ACTIONS = [
        'postCreateWarehouse' => 'warehouses',
        'postAuthorizeWarehouse' => 'authorizations',
        'postAdjustStock' => 'adjustments',
    ];

    public function testEveryInventoryMenuHasAnExactAclAction(): void
    {
        $menu = (string)file_get_contents(BP . 'app/code/Weline/Inventory/etc/backend/menu.xml');
        $controller = (string)file_get_contents(BP . 'app/code/Weline/Inventory/Controller/Backend/Inventory.php');
        foreach (self::FEATURES as $method => $code) {
            self::assertStringContainsString("source=\"Weline_Inventory::commerce:inventory:{$code}\"", $menu);
            self::assertStringContainsString("action=\"weline_inventory/backend/inventory/{$method}\"", $menu);
            self::assertMatchesRegularExpression(
                '/#\\[Acl\\(\'Weline_Inventory::commerce:inventory:' . preg_quote($code, '/') . '\'.*?\\)\\]\\s+public function ' . preg_quote($method, '/') . '\\(\\): string/s',
                $controller,
            );
        }
    }

    public function testInventoryPagesUseExistingReadAndWriteServices(): void
    {
        $controller = (string)file_get_contents(BP . 'app/code/Weline/Inventory/Controller/Backend/Inventory.php');
        $service = (string)file_get_contents(BP . 'app/code/Weline/Inventory/Service/InventoryAdminMutationService.php');
        $authorization = (string)file_get_contents(BP . 'app/code/Weline/Inventory/Service/WarehouseAuthorizationService.php');
        $template = (string)file_get_contents(BP . 'app/code/Weline/Inventory/view/templates/backend/inventory/index.phtml');
        self::assertStringContainsString('InventoryAdminViewService', $controller);
        self::assertStringContainsString('InventoryAdminMutationService', $controller);
        foreach (self::WRITE_ACTIONS as $method => $code) {
            self::assertMatchesRegularExpression(
                '/#\\[Acl\\(\'Weline_Inventory::commerce:inventory:' . preg_quote($code, '/') . '\'.*?\\)\\]\\s+public function ' . $method . '\\(\\): string/s',
                $controller,
            );
        }
        self::assertStringContainsString('WarehouseAuthorizationService', $service);
        self::assertStringContainsString('InventoryService', $service);
        self::assertStringContainsString('function createWarehouse', $authorization);
        self::assertSame(2, substr_count($controller, "postNonNegativeInt('store_id', 0)"));
        self::assertStringNotContainsString("postPositiveInt('store_id')", $controller);
        self::assertSame(2, substr_count($template, 'min="0" name="store_id"'));
        foreach (['inventory-warehouse-create-form', 'inventory-warehouse-authorization-form', 'inventory-stock-adjust-form'] as $testId) {
            self::assertStringContainsString('data-testid="' . $testId . '"', $template);
        }
        self::assertSame(3, substr_count($template, 'csrf="auto"'));
    }

    public function testSetOnHandDispatchesStockProjectionChangedEvent(): void
    {
        $service = (string)file_get_contents(BP . 'app/code/Weline/Inventory/Service/InventoryService.php');
        self::assertStringContainsString(
            "EVENT_STOCK_PROJECTION_CHANGED = 'Weline_Inventory::stock_projection_changed'",
            $service,
        );
        self::assertStringContainsString('notifyStockProjectionChanged', $service);
        self::assertStringContainsString('$didMutate = true', $service);
    }

    public function testInventoryBrowserCaseHasPostgresqlAssertionAndCleanup(): void
    {
        $spec = (string)file_get_contents(BP . 'app/code/Weline/Inventory/Test/e2e/backend/Weline_Inventory-menu-backend.spec.js');
        $fixture = (string)file_get_contents(BP . 'app/code/Weline/Inventory/Test/e2e/backend/Weline_Inventory-write-fixture.php');
        self::assertStringContainsString('CK-R43-INVENTORY-WRITE-001', $spec);
        self::assertStringContainsString('openBackendMenuBySource', $spec);
        self::assertStringContainsString("fixture('inspect'", $spec);
        self::assertStringContainsString("fixture('cleanup'", $spec);
        self::assertStringContainsString('r43_inventory_requires_postgresql', $fixture);
        self::assertStringContainsString('InventoryLedger::schema_fields_IDEMPOTENCY_KEY', $fixture);
        self::assertStringContainsString("array_key_exists('store_id', \$data) && (int)\$data['store_id'] >= 0", $fixture);
        self::assertStringNotContainsString("!empty(\$data['store_id'])", $fixture);
    }
}
