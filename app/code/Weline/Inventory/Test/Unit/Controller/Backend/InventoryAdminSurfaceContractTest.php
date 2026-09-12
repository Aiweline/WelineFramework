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
        'postCreateChildWarehouse' => 'warehouses',
        'postSeedWarehouses' => 'warehouses',
        'postDeleteWarehouse' => 'warehouses',
        'postSaveWarehouseCodeLabel' => 'warehouses',
        'postDeleteWarehouseCodeLabel' => 'warehouses',
        'postSeedWarehouseCodeLabels' => 'warehouses',
        'postAuthorizeWarehouse' => 'authorizations',
        'postDeleteAuthorization' => 'authorizations',
        'postSeedDefaultAuthorization' => 'authorizations',
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
        self::assertStringContainsString('WarehouseHierarchyService', $service);
        self::assertStringContainsString('InventoryService', $service);
        self::assertStringContainsString('function createWarehouse', $authorization);
        self::assertSame(2, substr_count($controller, "postNonNegativeInt('store_id', 0)"));
        self::assertStringNotContainsString("postPositiveInt('store_id')", $controller);
        self::assertGreaterThanOrEqual(2, substr_count($template, 'w:websites:store:select'));
        self::assertStringContainsString('w:product:offer:select', $template);
        self::assertStringContainsString('w:inventory:on-hand:field', $template);
        self::assertStringContainsString('w:inventory:command-id:field', $template);
        self::assertStringContainsString('w:inventory:warehouse:select', $template);
        self::assertStringNotContainsString('min="0" name="store_id"', $template);
        self::assertStringNotContainsString('name="offer_id" required', $template);
        self::assertStringNotContainsString('name="on_hand_minor" required', $template);
        self::assertStringNotContainsString('name="command_id" maxlength', $template);
        self::assertStringNotContainsString('name="warehouse_id" required', $template);
        foreach ([
            'inventory-warehouse-create-form',
            'inventory-warehouse-authorization-form',
            'inventory-authorization-seed-form',
            'inventory-stock-adjust-form',
            'inventory-warehouse-tree-table',
            'inventory-warehouse-seed-form',
        ] as $testId) {
            self::assertStringContainsString('data-testid="' . $testId . '"', $template);
        }
        self::assertStringContainsString('data-testid="inventory-stock-adjust-submit"', $template);
        // 提交按钮不得再用 span1 + data-w-width=full（会把「提交调整」压成竖排）
        self::assertDoesNotMatchRegularExpression(
            '/inventory-stock-adjust-form[\s\S]*?--w-span-md:1;[\s\S]*?data-w-width="full"[\s\S]*?提交调整/',
            $template,
        );
        self::assertStringContainsString('inventory-authorization-seed-badge', $template);
        self::assertStringContainsString('inventory-authorization-seed-locked', $template);
        self::assertStringContainsString('delete-authorization', $template);
        self::assertStringContainsString('<w:theme:address-quick', $template);
        self::assertStringContainsString('filter-target=', $template);
        self::assertStringContainsString('仓码创建后不可修改', $template);
        self::assertStringContainsString('create-child-warehouse', $template);
        self::assertStringContainsString('delete-warehouse', $template);
        self::assertStringContainsString('data-country-code=', $template);
        self::assertStringContainsString('WarehouseCodeAlias', $template);
        self::assertStringContainsString('is_seed', $template);
        self::assertStringContainsString('inventory-warehouse-seed-badge', $template);
        self::assertStringContainsString('inventory-warehouse-seed-locked', $template);
        self::assertStringContainsString('Weline\\Inventory\\Model\\Warehouse\\LocalDescription', $template);
        self::assertStringContainsString('<local', $template);
        self::assertStringContainsString('bindRuntimeRecordId', $template);
        self::assertStringContainsString('inventory-warehouse-tabs', $template);
        self::assertStringContainsString('inventory-wh-tab-labels', $template);
        self::assertStringContainsString('inventory-warehouse-code-label-table', $template);
        self::assertStringContainsString('WarehouseCodeLabel\\LocalDescription', $template);
        self::assertStringContainsString('save-warehouse-code-label', $template);
        self::assertGreaterThanOrEqual(3, substr_count($template, 'csrf="auto"'));
        self::assertStringContainsString('库存管理', $template);
        self::assertStringNotContainsString('库存内核管理工作台', $template);
        self::assertStringContainsString('inventory-readable-table', $template);
        self::assertStringContainsString('商品 Offer', $template);
        self::assertStringContainsString('<lang>严格</lang>', $template);
        $onHandTag = (string)file_get_contents(BP . 'app/code/Weline/Inventory/Taglib/OnHandField.php');
        $commandTag = (string)file_get_contents(BP . 'app/code/Weline/Inventory/Taglib/CommandIdField.php');
        self::assertStringContainsString('在手库存（件）', $onHandTag);
        self::assertStringContainsString('幂等键', $commandTag);
        self::assertStringContainsString('column_labels', $controller);
        $viewService = (string)file_get_contents(BP . 'app/code/Weline/Inventory/Service/InventoryAdminViewService.php');
        self::assertStringContainsString('projectStocks', $viewService);
        self::assertStringContainsString('projectAdjustments', $viewService);
        self::assertStringContainsString('adjustmentRows', $viewService);
        self::assertStringContainsString('authorizationRows', $viewService);
        self::assertStringContainsString('authorizationTreeRows', $viewService);
        self::assertStringContainsString('projectAuthorizationRows', $viewService);
        self::assertStringContainsString('store_name', $viewService);
        self::assertStringContainsString('warehouse_name', $viewService);
        self::assertStringContainsString('ensureDefaultSiteAuthorization', $viewService);
        self::assertStringContainsString('ensureSeedLeafAuthorizations', $authorization);
        self::assertStringContainsString('data-testid="inventory-management-', $template);
        self::assertStringContainsString('class="w-backend-page"', $template);
        self::assertStringNotContainsString('class="w-container" data-testid="inventory-management-', $template);
        self::assertStringContainsString('inventory-authorization-accordion', $template);
        self::assertStringContainsString('inventory-authorization-list-card', $template);
        self::assertStringContainsString('inventory-authorization-bind-card', $template);
        self::assertStringContainsString('inventory-authorization-warehouse-list', $template);
        self::assertStringContainsString('inventory-authorization-search-form', $template);
        self::assertStringContainsString('inventory-authorization-keyword', $template);
        self::assertStringContainsString('inventory-authorization-pagination', $template);
        self::assertStringContainsString("@backend-url('*/backend/inventory/authorizations')?", $template);
        self::assertLessThan(
            strpos($template, 'inventory-authorization-warehouse-list') ?: PHP_INT_MAX,
            strpos($template, 'inventory-authorization-pagination') ?: PHP_INT_MAX,
            'pagination must sit above the warehouse accordion list',
        );
        self::assertStringNotContainsString("\$open = \$storeCount > 0 ? ' open' : '';", $template);
        self::assertStringContainsString('projectAuthorizationAccordion', $viewService);
        self::assertStringContainsString('auth_keyword', $controller);
        self::assertStringContainsString('inventory-authorization-store-name', $template);
        self::assertStringContainsString('inventory-authorization-warehouse-name', $template);
        self::assertStringNotContainsString('inventory-authorization-tree-a', $template);
        self::assertStringNotContainsString('inventory-authorization-prototype-switcher', $template);
        self::assertStringContainsString('authorized_stores', $viewService);
        self::assertStringContainsString('authorized_store_count', $viewService);
        self::assertStringContainsString('is_leaf_warehouse', $viewService);
        self::assertStringContainsString("storeAuth['store_name']", $template);
        self::assertStringContainsString("authRow['warehouse_name']", $template);
        self::assertStringContainsString('在手（件）', $viewService);
        self::assertStringContainsString('ERROR_SEED_LOCKED', $authorization);
        self::assertStringContainsString('function ensureDefaultSiteAuthorization', $authorization);
        self::assertStringContainsString('schema_fields_IS_SEED', (string)file_get_contents(BP . 'app/code/Weline/Inventory/Model/WarehouseStoreAuthorization.php'));
        self::assertStringNotContainsString("'on_hand_minor' =>", $viewService);
    }

    public function testWarehouseHierarchyServiceIsPresent(): void
    {
        $hierarchy = (string)file_get_contents(BP . 'app/code/Weline/Inventory/Service/WarehouseHierarchyService.php');
        $model = (string)file_get_contents(BP . 'app/code/Weline/Inventory/Model/Warehouse.php');
        self::assertStringContainsString('function buildCode', $hierarchy);
        self::assertStringContainsString('function assertCodeImmutable', $hierarchy);
        self::assertStringContainsString('function ensureDefaultTree', $hierarchy);
        self::assertStringContainsString('hasBlockingInventory', $hierarchy);
        self::assertStringContainsString('系统种子仓不允许删除', $hierarchy);
        self::assertStringContainsString('schema_fields_IS_SEED', $hierarchy);
        self::assertStringContainsString('NODE_COUNTRY', $model);
        self::assertStringContainsString('schema_fields_PARENT_ID', $model);
        self::assertStringContainsString('schema_fields_IS_SEED', $model);
        self::assertStringContainsString('仓库码一旦确定不可修改', $model);
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
