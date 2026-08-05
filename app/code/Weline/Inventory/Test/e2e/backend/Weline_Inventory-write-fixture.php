<?php

declare(strict_types=1);

use Weline\Framework\Manager\ObjectManager;
use Weline\Inventory\Model\InventoryLedger;
use Weline\Inventory\Model\InventoryStock;
use Weline\Inventory\Model\Warehouse;
use Weline\Inventory\Model\WarehouseStoreAuthorization;
use Weline\Product\Model\Shard\Offer;
use Weline\Product\Model\Shard\Product;
use Weline\Product\Model\SkuAlias;
use Weline\Product\Model\SkuRegistry;
use Weline\Product\Service\ProductAdminMutationService;
use Weline\Product\Service\ProductShardProvisioner;
use Weline\Websites\Api\Catalog\StoreCatalogInterface;

require dirname(__DIR__, 7) . '/app/bootstrap.php';

/** @return array<string,mixed> */
function r43_inventory_input(): array
{
    $input = json_decode((string)stream_get_contents(STDIN), true);
    return is_array($input) ? $input : [];
}

/** @param array<string,mixed> $payload */
function r43_inventory_output(array $payload, int $code = 0): never
{
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
    exit($code);
}

function r43_inventory_token(array $input): string
{
    $token = strtolower((string)($input['token'] ?? ''));
    $token = preg_replace('/[^a-z0-9]/', '', $token) ?: '';
    return $token !== '' ? substr($token, 0, 20) : substr(bin2hex(random_bytes(8)), 0, 12);
}

/** @param array<string,mixed> $where @return list<array<string,mixed>> */
function r43_inventory_rows(object $model, array $where): array
{
    $query = $model->clear();
    foreach ($where as $field => $value) {
        $query->where((string)$field, $value);
    }
    $rows = $query->select()->fetchArray();
    return is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];
}

/** @param array<string,mixed> $where */
function r43_inventory_delete(object $model, array $where): int
{
    $rows = r43_inventory_rows($model, $where);
    $query = $model->clear();
    foreach ($where as $field => $value) {
        $query->where((string)$field, $value);
    }
    $query->delete()->fetch();
    return count($rows);
}

function r43_inventory_shard(string $class, int $websiteId): object
{
    return ObjectManager::getInstance($class, [], false)->forWebsite($websiteId);
}

function r43_inventory_assert_isolated_database(): string
{
    if (getenv('WELINE_E2E_ISOLATED_DB') !== '1') {
        throw new RuntimeException('r43_inventory_requires_isolated_database_flag');
    }
    $env = require dirname(__DIR__, 7) . '/app/etc/env.php';
    $database = trim((string)($env['db']['master']['database'] ?? ''));
    if (preg_match('/^mig_clone_[a-z0-9_]+$/D', $database) !== 1) {
        throw new RuntimeException('r43_inventory_requires_mig_clone_database:' . $database);
    }
    return $database;
}

function r43_inventory_assert_pgsql(): string
{
    $model = ObjectManager::getInstance(Warehouse::class, [], false);
    $connector = get_class($model->getConnection()->getConnector());
    $driver = strtolower($connector);
    if (!str_contains($driver, 'pgsql') && !str_contains($driver, 'postgres')) {
        throw new RuntimeException('r43_inventory_requires_postgresql:' . $connector);
    }
    return $connector;
}

/** @return array<string,mixed> */
function r43_inventory_data(string $token): array
{
    return [
        'token' => $token,
        'website_id' => 0,
        'sku' => 'R43-INV-' . strtoupper($token),
        'request_hash' => hash('sha256', 'r43-inventory-product-' . $token),
        'warehouse_code' => 'r43_wh_' . $token,
        'warehouse_name' => 'R43 Warehouse ' . $token,
        'warehouse_mode' => 'normal',
        'warehouse_type' => 'physical',
        'on_hand_minor' => 4300,
        'strategy' => 'strict',
        'command_id' => 'r43_inventory_' . $token,
    ];
}

/** @param array<string,mixed> $data @return array<string,mixed> */
function r43_inventory_inspect(array $data): array
{
    $websiteId = (int)$data['website_id'];
    $warehouses = r43_inventory_rows(
        ObjectManager::getInstance(Warehouse::class, [], false),
        [Warehouse::schema_fields_WEBSITE_ID => $websiteId, Warehouse::schema_fields_WAREHOUSE_CODE => (string)$data['warehouse_code']],
    );
    $warehouseId = (int)($warehouses[0][Warehouse::schema_fields_ID] ?? 0);
    $authorizations = $warehouseId > 0 ? r43_inventory_rows(
        ObjectManager::getInstance(WarehouseStoreAuthorization::class, [], false),
        [
            WarehouseStoreAuthorization::schema_fields_WEBSITE_ID => $websiteId,
            WarehouseStoreAuthorization::schema_fields_STORE_ID => (int)($data['store_id'] ?? 0),
            WarehouseStoreAuthorization::schema_fields_WAREHOUSE_ID => $warehouseId,
        ],
    ) : [];
    $stocks = r43_inventory_rows(
        ObjectManager::getInstance(InventoryStock::class, [], false),
        [
            InventoryStock::schema_fields_WEBSITE_ID => $websiteId,
            InventoryStock::schema_fields_STORE_ID => (int)($data['store_id'] ?? 0),
            InventoryStock::schema_fields_OFFER_ID => (int)($data['offer_id'] ?? 0),
        ],
    );
    $ledger = r43_inventory_rows(
        ObjectManager::getInstance(InventoryLedger::class, [], false),
        [InventoryLedger::schema_fields_IDEMPOTENCY_KEY => (string)$data['command_id']],
    );

    return compact('warehouses', 'authorizations', 'stocks', 'ledger');
}

/** @param array<string,mixed> $data @return array<string,mixed> */
function r43_inventory_cleanup(array $data): array
{
    if (!str_starts_with((string)$data['warehouse_code'], 'r43_wh_')) {
        throw new RuntimeException('refusing inventory cleanup outside R43 namespace');
    }
    $websiteId = (int)$data['website_id'];
    $deleted = ['ledger' => 0, 'stocks' => 0, 'authorizations' => 0, 'warehouses' => 0, 'offers' => 0, 'products' => 0, 'aliases' => 0, 'registry' => 0];
    $deleted['ledger'] = r43_inventory_delete(
        ObjectManager::getInstance(InventoryLedger::class, [], false),
        [InventoryLedger::schema_fields_IDEMPOTENCY_KEY => (string)$data['command_id']],
    );
    if (!empty($data['offer_id']) && !empty($data['store_id'])) {
        $deleted['stocks'] = r43_inventory_delete(
            ObjectManager::getInstance(InventoryStock::class, [], false),
            [
                InventoryStock::schema_fields_WEBSITE_ID => $websiteId,
                InventoryStock::schema_fields_STORE_ID => (int)$data['store_id'],
                InventoryStock::schema_fields_OFFER_ID => (int)$data['offer_id'],
            ],
        );
    }
    $warehouses = r43_inventory_rows(
        ObjectManager::getInstance(Warehouse::class, [], false),
        [Warehouse::schema_fields_WEBSITE_ID => $websiteId, Warehouse::schema_fields_WAREHOUSE_CODE => (string)$data['warehouse_code']],
    );
    $warehouseId = (int)($warehouses[0][Warehouse::schema_fields_ID] ?? 0);
    if ($warehouseId > 0) {
        $deleted['authorizations'] = r43_inventory_delete(
            ObjectManager::getInstance(WarehouseStoreAuthorization::class, [], false),
            [WarehouseStoreAuthorization::schema_fields_WAREHOUSE_ID => $warehouseId],
        );
        $deleted['warehouses'] = r43_inventory_delete(
            ObjectManager::getInstance(Warehouse::class, [], false),
            [Warehouse::schema_fields_ID => $warehouseId],
        );
    }

    $products = r43_inventory_rows(
        r43_inventory_shard(Product::class, $websiteId),
        [Product::schema_fields_SKU => (string)$data['sku']],
    );
    $productId = (int)($products[0][Product::schema_fields_ID] ?? 0);
    if ($productId > 0) {
        $deleted['offers'] = r43_inventory_delete(
            r43_inventory_shard(Offer::class, $websiteId),
            [Offer::schema_fields_PRODUCT_ID => $productId],
        );
    }
    $deleted['products'] = r43_inventory_delete(
        r43_inventory_shard(Product::class, $websiteId),
        [Product::schema_fields_SKU => (string)$data['sku']],
    );
    $registryRows = r43_inventory_rows(
        ObjectManager::getInstance(SkuRegistry::class, [], false),
        [SkuRegistry::schema_fields_SKU => (string)$data['sku']],
    );
    $registryId = (int)($registryRows[0][SkuRegistry::schema_fields_ID] ?? 0);
    if ($registryId > 0) {
        $deleted['aliases'] = r43_inventory_delete(
            ObjectManager::getInstance(SkuAlias::class, [], false),
            [SkuAlias::schema_fields_REGISTRY_ID => $registryId],
        );
    }
    $deleted['registry'] = r43_inventory_delete(
        ObjectManager::getInstance(SkuRegistry::class, [], false),
        [SkuRegistry::schema_fields_SKU => (string)$data['sku']],
    );

    $remaining = array_map('count', r43_inventory_inspect($data));
    if (array_sum($remaining) !== 0) {
        throw new RuntimeException('inventory fixture cleanup left rows: ' . json_encode($remaining));
    }
    return ['deleted' => $deleted, 'remaining' => $remaining];
}

try {
    $input = r43_inventory_input();
    $action = strtolower(trim((string)($input['action'] ?? '')));
    $data = array_merge(r43_inventory_data(r43_inventory_token($input)), $input);
    r43_inventory_assert_isolated_database();
    $connector = r43_inventory_assert_pgsql();
    ObjectManager::getInstance(ProductShardProvisioner::class)->provisionWebsite((int)$data['website_id']);

    if ($action === 'prepare') {
        r43_inventory_cleanup($data);
        $stores = ObjectManager::getInstance()->get(StoreCatalogInterface::class);
        $store = $stores->defaultStore((int)$data['website_id']);
        if ($store === null) {
            throw new RuntimeException('R43 inventory requires an existing default Store');
        }
        $mutations = ObjectManager::getInstance(ProductAdminMutationService::class);
        $mutations->registerSku((string)$data['sku'], (string)$data['request_hash']);
        $mutations->createProduct((int)$data['website_id'], (string)$data['sku']);
        $offer = $mutations->createOffer((int)$data['website_id'], (string)$data['sku']);
        $data['store_id'] = $store->id;
        $data['offer_id'] = (int)$offer->getId();
        r43_inventory_output(['ok' => true, 'connector' => $connector] + $data);
    }
    if ($action === 'inspect') {
        r43_inventory_output(['ok' => true, 'connector' => $connector] + r43_inventory_inspect($data));
    }
    if ($action === 'cleanup') {
        r43_inventory_output(['ok' => true, 'connector' => $connector] + r43_inventory_cleanup($data));
    }
    throw new InvalidArgumentException('unsupported action: ' . $action);
} catch (Throwable $throwable) {
    r43_inventory_output(['ok' => false, 'error' => $throwable->getMessage()], 1);
}
