<?php

declare(strict_types=1);

/**
 * Ensure a dedicated B2B display_type test store+channel (not default store /b2b),
 * warehouse authorizations, then prepare customer / wholesale fixtures.
 *
 * Store: e2e_b2b_commerce @ /e2e-b2b-commerce
 * Channel: web @ /e2e-b2b-commerce/web
 */

use Weline\B2B\Model\SystemVipLadder;
use Weline\B2B\Service\CustomerGroupStore;
use Weline\Framework\Manager\ObjectManager;
use Weline\Inventory\Model\InventoryStock;
use Weline\Inventory\Model\WarehouseStoreAuthorization;
use Weline\Inventory\Service\InventoryService;
use Weline\Inventory\Service\WarehouseAuthorizationService;
use Weline\Product\Model\OfferIdentityRegistry;
use Weline\Websites\Model\SalesChannel;
use Weline\Websites\Model\Store;
use Weline\Websites\Service\StoreChannelAdminService;

require dirname(__DIR__, 7) . '/app/bootstrap.php';

const E2E_B2B_STORE_CODE = 'e2e_b2b_commerce';
const E2E_B2B_CHANNEL_CODE = 'web';
const E2E_B2B_STORE_NAME = 'E2E B2B 测试店';
const E2E_B2B_CHANNEL_NAME = 'E2E B2B Web';
const E2E_B2B_BASE_HOST = 'https://p05113ef3.test.weline.com';
const E2E_B2B_SYS_DEFAULT_WH = 32;

/**
 * @return array<string, mixed>
 */
function e2e_b2b_input(): array
{
    $raw = file_get_contents('php://stdin');
    if ($raw === false || trim($raw) === '') {
        throw new InvalidArgumentException('empty stdin');
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        throw new InvalidArgumentException('stdin must be JSON object');
    }

    return $data;
}

/**
 * @param array<string, mixed> $payload
 */
function e2e_b2b_output(array $payload): void
{
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
}

function e2e_b2b_fail(string $message, int $code = 1): never
{
    e2e_b2b_output(['ok' => false, 'error' => $message]);
    exit($code);
}

/**
 * @return array{store_id:int,channel_id:int,store_url:string,base_url:string,display_type:string,store_code:string,channel_code:string}
 */
function e2e_b2b_ensure_store_channel(): array
{
    $websiteId = 0;
    $storeUrl = E2E_B2B_BASE_HOST . '/e2e-b2b-commerce';
    $channelUrl = $storeUrl . '/web';

    /** @var StoreChannelAdminService $admin */
    $admin = ObjectManager::getInstance()->get(StoreChannelAdminService::class);
    /** @var Store $storeModel */
    $storeModel = ObjectManager::getInstance()->get(Store::class);
    /** @var SalesChannel $channelModel */
    $channelModel = ObjectManager::getInstance()->get(SalesChannel::class);

    $storeRow = (clone $storeModel)->clear()
        ->where(Store::schema_fields_WEBSITE_ID, $websiteId)
        ->where(Store::schema_fields_CODE, E2E_B2B_STORE_CODE)
        ->find()
        ->fetch();
    $storeId = (int)($storeRow?->getId() ?? 0);
    if ($storeId <= 0) {
        $summary = $admin->createStore(
            $websiteId,
            E2E_B2B_STORE_CODE,
            E2E_B2B_STORE_NAME,
            Store::MODE_NORMAL,
            $storeUrl,
            'b2b',
        );
        $storeId = (int)$summary->id;
        $storeRow = (clone $storeModel)->clear()->load($storeId);
    } else {
        // Keep display_type / url / active for idempotent re-runs.
        $storeRow->setData(Store::schema_fields_NAME, E2E_B2B_STORE_NAME);
        $storeRow->setData(Store::schema_fields_URL, $storeUrl);
        $storeRow->setData(Store::schema_fields_DISPLAY_TYPE, 'b2b');
        $storeRow->setData(Store::schema_fields_STATUS, 1);
        $storeRow->setData(Store::schema_fields_LIFECYCLE_STATUS, Store::LIFECYCLE_ACTIVE);
        $storeRow->setData(Store::schema_fields_TOMBSTONED_AT, null);
        $storeRow->save();
    }
    if ($storeId <= 0) {
        e2e_b2b_fail('store_ensure_failed');
    }

    $channelRow = (clone $channelModel)->clear()
        ->where(SalesChannel::schema_fields_STORE_ID, $storeId)
        ->where(SalesChannel::schema_fields_CODE, E2E_B2B_CHANNEL_CODE)
        ->find()
        ->fetch();
    $channelId = (int)($channelRow?->getId() ?? 0);
    if ($channelId <= 0) {
        try {
            $ch = $admin->createChannel(
                $websiteId,
                $storeId,
                E2E_B2B_CHANNEL_CODE,
                E2E_B2B_CHANNEL_NAME,
                $channelUrl,
                null,
            );
            $channelId = (int)$ch->id;
        } catch (Throwable $e) {
            // Catalog read-after-write can lag; fall back to model.
            $channelRow = (clone $channelModel)->clear()
                ->where(SalesChannel::schema_fields_STORE_ID, $storeId)
                ->where(SalesChannel::schema_fields_CODE, E2E_B2B_CHANNEL_CODE)
                ->find()
                ->fetch();
            $channelId = (int)($channelRow?->getId() ?? 0);
            if ($channelId <= 0) {
                e2e_b2b_fail('channel_ensure_failed:' . $e->getMessage());
            }
        }
        $channelRow = (clone $channelModel)->clear()->load($channelId);
    }
    if ($channelRow) {
        $channelRow->setData(SalesChannel::schema_fields_URL, $channelUrl);
        $channelRow->setData(SalesChannel::schema_fields_STATUS, 1);
        $channelRow->setData(SalesChannel::schema_fields_NAME, E2E_B2B_CHANNEL_NAME);
        $channelRow->save();
    }

    e2e_b2b_ensure_warehouses($websiteId, $storeId);
    $stockSeeded = e2e_b2b_ensure_product_stock($websiteId, $storeId);

    return [
        'store_id' => $storeId,
        'channel_id' => $channelId,
        'store_url' => $storeUrl,
        'base_url' => $channelUrl,
        'display_type' => 'b2b',
        'store_code' => E2E_B2B_STORE_CODE,
        'channel_code' => E2E_B2B_CHANNEL_CODE,
        'stock_seeded' => $stockSeeded,
    ];
}

/**
 * Copy / force on-hand for the default pathway product offers onto the new store.
 * Inventory is store-scoped; without this, cart.add fails with 库存不足.
 *
 * @return array{product_uuid:string,offers:int,seeded:int}
 */
function e2e_b2b_ensure_product_stock(int $websiteId, int $storeId): array
{
    $productUuid = '8a3eda07-182e-57c2-991b-bfa66251637b'; // product_id 321 黑山茶
    /** @var OfferIdentityRegistry $reg */
    $reg = ObjectManager::getInstance()->get(OfferIdentityRegistry::class);
    /** @var InventoryStock $stockModel */
    $stockModel = ObjectManager::getInstance()->get(InventoryStock::class);
    /** @var InventoryService $inventory */
    $inventory = ObjectManager::getInstance()->get(InventoryService::class);

    $regs = (clone $reg)->clear()
        ->where(OfferIdentityRegistry::schema_fields_PRODUCT_UUID, $productUuid)
        ->select()
        ->fetchArray();
    $seeded = 0;
    $offerCount = 0;
    foreach (($regs ?: []) as $row) {
        if (!is_array($row)) {
            continue;
        }
        $offerId = (int)($row[OfferIdentityRegistry::schema_fields_ID] ?? 0);
        if ($offerId <= 0) {
            continue;
        }
        $offerCount++;
        $src = (clone $stockModel)->clear()
            ->where(InventoryStock::schema_fields_WEBSITE_ID, $websiteId)
            ->where(InventoryStock::schema_fields_STORE_ID, 0)
            ->where(InventoryStock::schema_fields_OFFER_ID, $offerId)
            ->find()
            ->fetch();
        $onHand = 10000;
        $strategy = 'strict';
        $oversell = 0;
        $preorder = 0;
        if ($src && $src->getId()) {
            $onHand = max(10000, (int)$src->getData(InventoryStock::schema_fields_ON_HAND_MINOR));
            $strategy = (string)$src->getData(InventoryStock::schema_fields_STRATEGY) ?: $strategy;
            $oversell = (int)$src->getData(InventoryStock::schema_fields_OVERSELL_ALLOWANCE);
            $preorder = (int)$src->getData(InventoryStock::schema_fields_PREORDER_ALLOWANCE);
        }
        $dst = (clone $stockModel)->clear()
            ->where(InventoryStock::schema_fields_WEBSITE_ID, $websiteId)
            ->where(InventoryStock::schema_fields_STORE_ID, $storeId)
            ->where(InventoryStock::schema_fields_OFFER_ID, $offerId)
            ->find()
            ->fetch();
        if ($dst && $dst->getId() && (int)$dst->getData(InventoryStock::schema_fields_ON_HAND_MINOR) >= 1000) {
            continue;
        }
        try {
            $inventory->ensureStock($websiteId, $storeId, $offerId, $strategy, 0, $oversell, $preorder);
            $key = 'e2e_b2b_stock_' . $storeId . '_' . $offerId;
            $inventory->setOnHand(
                $websiteId,
                $storeId,
                $offerId,
                $onHand,
                $key,
                hash('sha256', $key . ':' . $onHand),
                $strategy,
                $oversell,
                $preorder,
            );
            $seeded++;
        } catch (Throwable) {
            // keep going; pathway will fail closed if still OOS
        }
    }

    return [
        'product_uuid' => $productUuid,
        'offers' => $offerCount,
        'seeded' => $seeded,
    ];
}

function e2e_b2b_ensure_warehouses(int $websiteId, int $storeId): void
{
    /** @var WarehouseAuthorizationService $auth */
    $auth = ObjectManager::getInstance()->get(WarehouseAuthorizationService::class);
    try {
        $auth->bind([
            'website_id' => $websiteId,
            'store_id' => $storeId,
            'warehouse_id' => E2E_B2B_SYS_DEFAULT_WH,
            'is_default' => true,
        ]);
    } catch (Throwable) {
        // already bound / conflict
    }

    /** @var WarehouseStoreAuthorization $authModel */
    $authModel = ObjectManager::getInstance()->get(WarehouseStoreAuthorization::class);
    $seedRows = (clone $authModel)->clear()
        ->where(WarehouseStoreAuthorization::schema_fields_WEBSITE_ID, 0)
        ->where(WarehouseStoreAuthorization::schema_fields_STORE_ID, 0)
        ->where(WarehouseStoreAuthorization::schema_fields_ENABLED, 1)
        ->select()
        ->fetchArray();
    foreach ($seedRows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $whId = (int)($row[WarehouseStoreAuthorization::schema_fields_WAREHOUSE_ID] ?? 0);
        if ($whId <= 0 || $whId === E2E_B2B_SYS_DEFAULT_WH) {
            continue;
        }
        try {
            $auth->bind([
                'website_id' => $websiteId,
                'store_id' => $storeId,
                'warehouse_id' => $whId,
                'is_default' => false,
                'is_seed' => !empty($row[WarehouseStoreAuthorization::schema_fields_IS_SEED]),
            ]);
        } catch (Throwable) {
            // ignore
        }
    }
}

/**
 * @return array<string, mixed>
 */
function e2e_b2b_prepare_customer(?string $token): array
{
    $fixturePhp = __DIR__ . '/release-paypal-account-lifecycle-fixture.php';
    $payload = json_encode(['action' => 'prepare', 'token' => $token], JSON_UNESCAPED_UNICODE);
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $proc = proc_open(['php', $fixturePhp], $descriptors, $pipes, dirname(__DIR__, 7));
    if (!is_resource($proc)) {
        e2e_b2b_fail('prepare_spawn_failed');
    }
    fwrite($pipes[0], (string)$payload);
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($proc);
    $lines = array_values(array_filter(array_map('trim', explode("\n", (string)$stdout))));
    $parsed = json_decode((string)end($lines), true);
    if ($exit !== 0 || !is_array($parsed) || empty($parsed['ok'])) {
        e2e_b2b_fail('prepare_failed:' . ($parsed['error'] ?? $stderr ?: $stdout));
    }

    return is_array($parsed['fixture'] ?? null) ? $parsed['fixture'] : [];
}

/**
 * @param array<string, mixed> $fixture
 * @return array<string, mixed>
 */
function e2e_b2b_assign_wholesale(array $fixture): array
{
    $customerId = (string)(int)($fixture['customer_id'] ?? 0);
    if ($customerId === '0' || $customerId === '') {
        e2e_b2b_fail('wholesale_customer_id_missing');
    }
    $groupId = SystemVipLadder::groupId(0);
    $store = ObjectManager::getInstance()->get(CustomerGroupStore::class);
    if (!$store instanceof CustomerGroupStore) {
        $store = new CustomerGroupStore();
    }
    $group = $store->get($groupId);
    if ($group === null) {
        e2e_b2b_fail('vip0_group_missing:' . $groupId);
    }
    $store->assignCustomer($customerId, $groupId);
    $assigned = $store->groupForCustomer($customerId, (int)$group->websiteId);

    return [
        'group_id' => $groupId,
        'website_id' => (int)$group->websiteId,
        'assigned' => $assigned !== null && $assigned->groupId === $groupId,
        'status' => $assigned?->status,
    ];
}

try {
    $input = e2e_b2b_input();
    $action = trim((string)($input['action'] ?? ''));
    $token = isset($input['token']) ? (string)$input['token'] : null;
    $scope = e2e_b2b_ensure_store_channel();

    if ($action === 'ensure_store') {
        e2e_b2b_output(['ok' => true, 'scope' => $scope]);
        exit(0);
    }

    if ($action === 'prepare') {
        $fixture = e2e_b2b_prepare_customer($token);
        e2e_b2b_output(['ok' => true, 'scope' => $scope, 'fixture' => $fixture]);
        exit(0);
    }

    if ($action === 'prepare_wholesale') {
        $fixture = e2e_b2b_prepare_customer($token);
        $fixture['wholesale'] = e2e_b2b_assign_wholesale($fixture);
        e2e_b2b_output(['ok' => true, 'scope' => $scope, 'fixture' => $fixture]);
        exit(0);
    }

    e2e_b2b_fail('unsupported_action:' . $action);
} catch (Throwable $e) {
    e2e_b2b_fail($e->getMessage());
}
