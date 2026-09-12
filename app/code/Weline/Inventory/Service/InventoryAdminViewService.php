<?php

declare(strict_types=1);

namespace Weline\Inventory\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Inventory\Model\InventoryLedger;
use Weline\Inventory\Model\InventoryStock;
use Weline\Inventory\Model\Reservation;
use Weline\Inventory\Model\Warehouse;
use Weline\Inventory\Model\WarehousePool;
use Weline\Inventory\Model\WarehouseStoreAuthorization;
use Weline\Websites\Api\Catalog\StoreCatalogInterface;

/**
 * Read-only inventory administration projection.
 */
final class InventoryAdminViewService
{
    /** @var array<string, string> */
    private const STRATEGY_LABELS = [
        'strict' => '严格',
        'oversell' => '允许超卖',
        'preorder' => '预售',
        'unlimited' => '不限量',
    ];

    /** @var array<string, string> */
    private const EVENT_TYPE_LABELS = [
        InventoryLedger::TYPE_STOCK_SET => '设置库存',
        InventoryLedger::TYPE_STOCK_ADJUST => '调整库存',
    ];

    public function __construct(
        private readonly InventoryStock $stocks,
        private readonly InventoryLedger $ledger,
        private readonly Warehouse $warehouses,
        private readonly WarehouseStoreAuthorization $authorizations,
        private readonly Reservation $reservations,
        private readonly WarehousePool $warehousePools,
        private readonly WarehouseHierarchyService $hierarchy,
    ) {
    }

    /**
     * @param array{keyword?:string,page?:int,limit?:int} $options
     * @return array{
     *   rows:list<array<string,mixed>>,
     *   columns:list<string>,
     *   column_labels?:array<string,string>,
     *   meta?:array{keyword:string,page:int,limit:int,total:int,total_pages:int}
     * }
     */
    public function load(string $section, int $websiteId, int $storeId = 0, array $options = []): array
    {
        if ($websiteId < 0) {
            throw new \InvalidArgumentException(__('website_id 不能小于 0'));
        }
        if ($storeId < 0) {
            throw new \InvalidArgumentException(__('store_id 不能小于 0'));
        }

        if ($section === 'authorizations') {
            return $this->projectAuthorizationAccordion(
                $this->authorizationTreeRows($websiteId, $storeId),
                $options,
            );
        }

        $rows = match ($section) {
            'stocks' => $this->rows($this->stocks, $websiteId, 100),
            'adjustments' => $this->adjustmentRows($websiteId, 100),
            'warehouses' => $this->warehouseTreeRows($websiteId),
            'reservations' => $this->rows($this->reservations, $websiteId, 100),
            'leases' => array_values(array_filter(
                $this->rows($this->reservations, $websiteId, 200),
                static fn(array $row): bool => ($row[Reservation::schema_fields_LEASE_EXPIRES_AT] ?? null) !== null,
            )),
            'ledger' => $this->ledgerRows($websiteId, 100),
            'migration' => $this->migrationRows($websiteId),
            default => throw new \InvalidArgumentException(__('未知库存管理区段：%{1}', [$section])),
        };
        if ($section !== 'warehouses') {
            $rows = array_slice(array_map([$this, 'sanitize'], $rows), 0, 100);
        } else {
            $rows = array_map([$this, 'sanitize'], $rows);
        }

        if ($section === 'stocks') {
            return $this->projectStocks($rows, $websiteId);
        }
        if ($section === 'adjustments') {
            return $this->projectAdjustments($rows, $websiteId);
        }

        return ['rows' => $rows, 'columns' => $this->columns($rows)];
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return array{rows:list<array<string,mixed>>,columns:list<string>,column_labels:array<string,string>}
     */
    private function projectStocks(array $rows, int $websiteId): array
    {
        $labels = $this->offerLabels($websiteId, $this->collectOfferIds($rows));
        $projected = [];
        foreach ($rows as $row) {
            $offerId = (int)($row[InventoryStock::schema_fields_OFFER_ID] ?? 0);
            $onHand = (int)($row[InventoryStock::schema_fields_ON_HAND_MINOR] ?? 0);
            $reserved = (int)($row[InventoryStock::schema_fields_RESERVED_MINOR] ?? 0);
            $strategy = (string)($row[InventoryStock::schema_fields_STRATEGY] ?? '');
            $projected[] = [
                'product' => $labels[$offerId] ?? $this->fallbackOfferLabel($offerId),
                'strategy' => $this->strategyLabel($strategy),
                'on_hand' => $onHand,
                'reserved' => $reserved,
                'available' => max(0, $onHand - $reserved),
                'oversell' => (int)($row[InventoryStock::schema_fields_OVERSELL_ALLOWANCE] ?? 0),
                'preorder' => (int)($row[InventoryStock::schema_fields_PREORDER_ALLOWANCE] ?? 0),
                'store_id' => (int)($row[InventoryStock::schema_fields_STORE_ID] ?? 0),
                'stock_id' => (int)($row[InventoryStock::schema_fields_ID] ?? 0),
                'stock_version' => (int)($row[InventoryStock::schema_fields_STOCK_VERSION] ?? 0),
            ];
        }

        return [
            'rows' => $projected,
            'columns' => ['product', 'strategy', 'on_hand', 'reserved', 'available', 'oversell', 'preorder', 'store_id', 'stock_id', 'stock_version'],
            'column_labels' => [
                'product' => (string)__('商品'),
                'strategy' => (string)__('策略'),
                'on_hand' => (string)__('在手（件）'),
                'reserved' => (string)__('预占（件）'),
                'available' => (string)__('可售（件）'),
                'oversell' => (string)__('超卖额度'),
                'preorder' => (string)__('预售额度'),
                'store_id' => (string)__('店铺'),
                'stock_id' => (string)__('库存 ID'),
                'stock_version' => (string)__('版本'),
            ],
        ];
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return array{rows:list<array<string,mixed>>,columns:list<string>,column_labels:array<string,string>}
     */
    private function projectAdjustments(array $rows, int $websiteId): array
    {
        $labels = $this->offerLabels($websiteId, $this->collectOfferIds($rows));
        $projected = [];
        foreach ($rows as $row) {
            $offerId = (int)($row[InventoryLedger::schema_fields_OFFER_ID] ?? 0);
            $eventType = (string)($row[InventoryLedger::schema_fields_EVENT_TYPE] ?? '');
            $strategy = (string)($row[InventoryLedger::schema_fields_STRATEGY] ?? '');
            $projected[] = [
                'created_at' => (string)($row[InventoryLedger::schema_fields_CREATED_AT] ?? ''),
                'event_type' => $this->eventTypeLabel($eventType),
                'product' => $labels[$offerId] ?? $this->fallbackOfferLabel($offerId),
                'qty_delta' => (int)($row[InventoryLedger::schema_fields_QTY_DELTA_MINOR] ?? 0),
                'strategy' => $this->strategyLabel($strategy),
                'store_id' => (int)($row[InventoryLedger::schema_fields_STORE_ID] ?? 0),
                'warehouse_id' => (int)($row[InventoryLedger::schema_fields_WAREHOUSE_ID] ?? 0),
                'ledger_id' => (int)($row[InventoryLedger::schema_fields_ID] ?? 0),
            ];
        }

        return [
            'rows' => $projected,
            'columns' => ['created_at', 'event_type', 'product', 'qty_delta', 'strategy', 'store_id', 'warehouse_id', 'ledger_id'],
            'column_labels' => [
                'created_at' => (string)__('时间'),
                'event_type' => (string)__('类型'),
                'product' => (string)__('商品'),
                'qty_delta' => (string)__('变动（件）'),
                'strategy' => (string)__('策略'),
                'store_id' => (string)__('店铺'),
                'warehouse_id' => (string)__('仓库 ID'),
                'ledger_id' => (string)__('账本 ID'),
            ],
        ];
    }

    /** @param list<array<string,mixed>> $rows @return list<int> */
    private function collectOfferIds(array $rows): array
    {
        $ids = [];
        foreach ($rows as $row) {
            $offerId = (int)($row['offer_id'] ?? 0);
            if ($offerId > 0) {
                $ids[$offerId] = $offerId;
            }
        }

        return array_values($ids);
    }

    /**
     * Optional Product Offer SKU lookup; never fails the admin page.
     *
     * @param list<int> $offerIds
     * @return array<int, string>
     */
    private function offerLabels(int $websiteId, array $offerIds): array
    {
        if ($offerIds === [] || !class_exists(\Weline\Product\Model\Shard\Offer::class)) {
            return [];
        }

        try {
            /** @var \Weline\Product\Repository\OfferRepository $repo */
            $repo = ObjectManager::getInstance(\Weline\Product\Repository\OfferRepository::class);
            $out = [];
            foreach ($offerIds as $offerId) {
                $offer = $repo->findById($websiteId, $offerId);
                if ($offer === null) {
                    continue;
                }
                $sku = trim((string)$offer->getData(\Weline\Product\Model\Shard\Offer::schema_fields_SKU));
                $out[$offerId] = $sku !== ''
                    ? $sku . ' · Offer #' . $offerId
                    : $this->fallbackOfferLabel($offerId);
            }

            return $out;
        } catch (\Throwable) {
            return [];
        }
    }

    private function fallbackOfferLabel(int $offerId): string
    {
        return $offerId > 0 ? 'Offer #' . $offerId : (string)__('未知商品');
    }

    private function strategyLabel(string $strategy): string
    {
        return self::STRATEGY_LABELS[$strategy] ?? ($strategy !== '' ? $strategy : '—');
    }

    private function eventTypeLabel(string $eventType): string
    {
        return self::EVENT_TYPE_LABELS[$eventType] ?? ($eventType !== '' ? $eventType : '—');
    }

    /** @return list<array<string,mixed>> */
    private function warehouseTreeRows(int $websiteId): array
    {
        try {
            $this->hierarchy->ensureDefaultTree($websiteId);
        } catch (\Throwable) {
            // Read path still returns whatever exists; seed failures surface via mutation CTA.
        }
        $rows = $this->warehouses->clear()->where('website_id', $websiteId)->select()->fetchArray();
        $ordered = WarehouseHierarchyService::orderAsTree($rows);
        return array_map(static function (array $row): array {
            $depth = (int)($row['_depth'] ?? 0);
            unset($row['_depth'], $row['_created']);
            $row['depth'] = $depth;
            $row['tree_label'] = str_repeat('— ', $depth) . (string)($row[Warehouse::schema_fields_NAME] ?? '');
            return $row;
        }, $ordered);
    }

    /**
     * Recent stock set/adjust events only — never unbounded ledger fetch.
     *
     * @return list<array<string,mixed>>
     */
    private function adjustmentRows(int $websiteId, int $limit): array
    {
        /** @var list<array<string,mixed>> $rows */
        $rows = $this->ledger->clear()
            ->where('website_id', $websiteId)
            ->where(
                InventoryLedger::schema_fields_EVENT_TYPE,
                [InventoryLedger::TYPE_STOCK_SET, InventoryLedger::TYPE_STOCK_ADJUST],
                'IN',
            )
            ->order(InventoryLedger::schema_fields_CREATED_AT, 'DESC')
            ->limit($limit)
            ->select()
            ->fetchArray();

        return $rows;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function ledgerRows(int $websiteId, int $limit): array
    {
        /** @var list<array<string,mixed>> $rows */
        $rows = $this->ledger->clear()
            ->where('website_id', $websiteId)
            ->order(InventoryLedger::schema_fields_CREATED_AT, 'DESC')
            ->limit($limit)
            ->select()
            ->fetchArray();

        return $rows;
    }

    /** @return list<array<string,mixed>> */
    private function authorizationTreeRows(int $websiteId, int $storeId): array
    {
        if ($websiteId === WarehouseAuthorizationService::DEFAULT_SITE_WEBSITE_ID) {
            try {
                ObjectManager::getInstance(WarehouseAuthorizationService::class)
                    ->ensureDefaultSiteAuthorization();
            } catch (\Throwable) {
                // Read path still returns whatever exists; seed failures surface via mutation CTA.
            }
        }

        $tree = $this->warehouseTreeRows($websiteId);
        /** @var list<array<string,mixed>> $authRows */
        $authRows = $this->authorizations->clear()
            ->where('website_id', $websiteId)
            ->limit(1000)
            ->select()
            ->fetchArray();
        $storesByWarehouse = [];
        foreach ($authRows as $authRow) {
            if ((int)($authRow[WarehouseStoreAuthorization::schema_fields_ENABLED] ?? 0) !== 1) {
                continue;
            }
            $warehouseId = (int)($authRow[WarehouseStoreAuthorization::schema_fields_WAREHOUSE_ID] ?? 0);
            $authStoreId = (int)($authRow[WarehouseStoreAuthorization::schema_fields_STORE_ID] ?? 0);
            if ($warehouseId <= 0 || $authStoreId < 0) {
                continue;
            }
            $storesByWarehouse[$warehouseId][] = [
                'authorization_id' => (int)($authRow[WarehouseStoreAuthorization::schema_fields_ID] ?? 0),
                'store_id' => $authStoreId,
                'store_name' => $this->storeName($authStoreId),
                'is_default' => (int)($authRow[WarehouseStoreAuthorization::schema_fields_IS_DEFAULT] ?? 0) === 1 ? 1 : 0,
                'is_seed' => (int)($authRow[WarehouseStoreAuthorization::schema_fields_IS_SEED] ?? 0) === 1 ? 1 : 0,
            ];
        }

        $projected = [];
        foreach ($tree as $row) {
            $warehouseId = (int)($row[Warehouse::schema_fields_ID] ?? 0);
            $nodeKind = (string)($row[Warehouse::schema_fields_NODE_KIND] ?? Warehouse::NODE_WAREHOUSE);
            $stores = $storesByWarehouse[$warehouseId] ?? [];
            $row['authorized_stores'] = $stores;
            $row['authorized_store_count'] = count($stores);
            $row['authorized'] = $stores !== [] ? 1 : 0;
            $row['is_default_auth'] = 0;
            $row['auth_is_seed'] = 0;
            foreach ($stores as $storeAuth) {
                if ((int)($storeAuth['is_default'] ?? 0) === 1) {
                    $row['is_default_auth'] = 1;
                }
                if ((int)($storeAuth['is_seed'] ?? 0) === 1) {
                    $row['auth_is_seed'] = 1;
                }
            }
            $row['warehouse_name'] = trim((string)($row[Warehouse::schema_fields_NAME] ?? ''));
            if ($row['warehouse_name'] === '') {
                $row['warehouse_name'] = trim((string)($row[Warehouse::schema_fields_WAREHOUSE_CODE] ?? ''));
            }
            $row['is_leaf_warehouse'] = $nodeKind === Warehouse::NODE_WAREHOUSE ? 1 : 0;
            $projected[] = $row;
        }

        return $projected;
    }

    /**
     * Leaf-warehouse accordion projection with keyword filter + pagination.
     *
     * @param list<array<string,mixed>> $projected
     * @param array{keyword?:string,page?:int,limit?:int} $options
     * @return array{
     *   rows:list<array<string,mixed>>,
     *   columns:list<string>,
     *   meta:array{keyword:string,page:int,limit:int,total:int,total_pages:int}
     * }
     */
    private function projectAuthorizationAccordion(array $projected, array $options = []): array
    {
        $leaves = [];
        foreach ($projected as $row) {
            if ((int)($row['is_leaf_warehouse'] ?? 0) === 1) {
                $leaves[] = $row;
            }
        }

        $keyword = trim((string)($options['keyword'] ?? ''));
        if ($keyword !== '') {
            $needle = mb_strtolower($keyword);
            $leaves = array_values(array_filter(
                $leaves,
                function (array $row) use ($needle): bool {
                    $parts = [
                        (string)($row['warehouse_name'] ?? ''),
                        (string)($row['warehouse_code'] ?? ''),
                        (string)($row['country_code'] ?? ''),
                        (string)($row['warehouse_type'] ?? ''),
                    ];
                    $stores = is_array($row['authorized_stores'] ?? null) ? $row['authorized_stores'] : [];
                    foreach ($stores as $storeAuth) {
                        if (!is_array($storeAuth)) {
                            continue;
                        }
                        $parts[] = (string)($storeAuth['store_name'] ?? '');
                        $parts[] = (string)($storeAuth['store_id'] ?? '');
                    }

                    return str_contains(mb_strtolower(implode(' ', $parts)), $needle);
                },
            ));
        }

        $total = count($leaves);
        $limit = (int)($options['limit'] ?? 10);
        $limit = $limit > 0 ? min($limit, 50) : 10;
        $totalPages = $total === 0 ? 1 : max(1, (int)ceil($total / $limit));
        $page = max(1, (int)($options['page'] ?? 1));
        if ($page > $totalPages) {
            $page = $totalPages;
        }
        $offset = ($page - 1) * $limit;
        $pageRows = array_slice($leaves, $offset, $limit);
        $rows = array_map([$this, 'sanitize'], $pageRows);

        return [
            'rows' => $rows,
            'columns' => $this->columns($rows),
            'meta' => [
                'keyword' => $keyword,
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'total_pages' => $totalPages,
            ],
        ];
    }

    private function storeName(int $storeId): string
    {
        $store = $this->storeCatalog()?->byId($storeId);
        $name = $store !== null ? trim((string)$store->name) : '';

        return $name !== '' ? $name : (string)__('未知店铺');
    }

    /** @return list<array<string,mixed>> */
    private function authorizationRows(int $websiteId, int $limit = 100): array
    {
        if ($websiteId === WarehouseAuthorizationService::DEFAULT_SITE_WEBSITE_ID) {
            try {
                ObjectManager::getInstance(WarehouseAuthorizationService::class)
                    ->ensureDefaultSiteAuthorization();
            } catch (\Throwable) {
                // Read path still returns whatever exists; seed failures surface via mutation CTA.
            }
        }
        /** @var list<array<string,mixed>> $rows */
        $rows = $this->authorizations->clear()
            ->where('website_id', $websiteId)
            ->limit($limit)
            ->select()
            ->fetchArray();

        return $this->projectAuthorizationRows($rows);
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    private function projectAuthorizationRows(array $rows): array
    {
        if ($rows === []) {
            return [];
        }
        $warehouseIds = [];
        foreach ($rows as $row) {
            $warehouseId = (int)($row[WarehouseStoreAuthorization::schema_fields_WAREHOUSE_ID] ?? 0);
            if ($warehouseId > 0) {
                $warehouseIds[$warehouseId] = $warehouseId;
            }
        }
        $warehouseNames = $this->warehouseNamesByIds(array_values($warehouseIds));
        $stores = $this->storeCatalog();
        $projected = [];
        foreach ($rows as $row) {
            $storeId = (int)($row[WarehouseStoreAuthorization::schema_fields_STORE_ID] ?? 0);
            $warehouseId = (int)($row[WarehouseStoreAuthorization::schema_fields_WAREHOUSE_ID] ?? 0);
            $store = $stores?->byId($storeId);
            $storeName = $store !== null ? trim((string)$store->name) : '';
            $warehouseName = trim((string)($warehouseNames[$warehouseId] ?? ''));
            $row['store_name'] = $storeName !== '' ? $storeName : (string)__('未知店铺');
            $row['warehouse_name'] = $warehouseName !== '' ? $warehouseName : (string)__('未知仓库');
            $projected[] = $row;
        }

        return $projected;
    }

    /**
     * @param list<int> $warehouseIds
     * @return array<int, string>
     */
    private function warehouseNamesByIds(array $warehouseIds): array
    {
        if ($warehouseIds === []) {
            return [];
        }
        try {
            /** @var list<array<string,mixed>> $rows */
            $rows = $this->warehouses->clear()
                ->where(Warehouse::schema_fields_ID, $warehouseIds, 'IN')
                ->select()
                ->fetchArray();
        } catch (\Throwable) {
            return [];
        }
        $out = [];
        foreach ($rows as $row) {
            $id = (int)($row[Warehouse::schema_fields_ID] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $name = trim((string)($row[Warehouse::schema_fields_NAME] ?? ''));
            if ($name === '') {
                $name = trim((string)($row[Warehouse::schema_fields_WAREHOUSE_CODE] ?? ''));
            }
            if ($name !== '') {
                $out[$id] = $name;
            }
        }

        return $out;
    }

    private function storeCatalog(): ?StoreCatalogInterface
    {
        try {
            $catalog = ObjectManager::getInstance(StoreCatalogInterface::class);
            return $catalog instanceof StoreCatalogInterface ? $catalog : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return list<array<string,mixed>> */
    private function rows(object $model, int $websiteId, int $limit = 100): array
    {
        /** @var list<array<string,mixed>> $rows */
        $rows = $model->clear()->where('website_id', $websiteId)->limit($limit)->select()->fetchArray();
        return $rows;
    }

    /** @return list<array<string,mixed>> */
    private function migrationRows(int $websiteId): array
    {
        $rows = $this->warehousePools->clear()->where('website_id', $websiteId)->limit(100)->select()->fetchArray();
        if ($rows === []) {
            return [[
                'website_id' => $websiteId,
                'migration_state' => 'not_started',
                'warehouse_pool_count' => 0,
            ]];
        }
        return array_map(static fn(array $row): array => [
            'website_id' => $websiteId,
            'migration_state' => 'warehouse_pool_ready',
            'warehouse_pool_id' => $row['pool_id'] ?? null,
            'warehouse_id' => $row['warehouse_id'] ?? null,
        ], $rows);
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function sanitize(array $row): array
    {
        unset($row['request_hash'], $row['idempotency_key'], $row['lease_owner_attempt_code']);
        return $row;
    }

    /** @param list<array<string,mixed>> $rows @return list<string> */
    private function columns(array $rows): array
    {
        $columns = [];
        foreach ($rows as $row) {
            foreach (array_keys($row) as $column) {
                if (!in_array($column, $columns, true)) {
                    $columns[] = $column;
                }
            }
        }
        return $columns;
    }
}
