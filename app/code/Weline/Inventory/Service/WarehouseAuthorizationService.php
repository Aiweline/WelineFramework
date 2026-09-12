<?php

declare(strict_types=1);

namespace Weline\Inventory\Service;

use Throwable;
use Weline\Framework\Manager\ObjectManager;
use Weline\Inventory\Model\Warehouse;
use Weline\Inventory\Model\WarehouseStoreAuthorization;
use Weline\Websites\Api\Catalog\StoreCatalogInterface;

/**
 * Durable Store↔Warehouse authorization matrix（P3A-001 / TEST-P3A-04）.
 * Production derives Store ownership and environment from StoreCatalogInterface.
 */
final class WarehouseAuthorizationService
{
    public const ERROR_MODE_MISMATCH = 'inventory_warehouse_mode_mismatch';
    public const ERROR_NOT_AUTHORIZED = 'inventory_warehouse_not_authorized';
    public const ERROR_DISABLED = 'inventory_warehouse_disabled';
    public const ERROR_WEBSITE_MISMATCH = 'inventory_warehouse_website_mismatch';
    public const ERROR_STORE_INACTIVE = 'inventory_warehouse_store_inactive';
    public const ERROR_STORE_MODE_INVALID = 'inventory_warehouse_store_mode_invalid';
    public const ERROR_DEFAULT_REQUIRES_LOGICAL = 'inventory_warehouse_default_requires_logical';
    public const ERROR_DEFAULT_CONFLICT = 'inventory_warehouse_default_conflict';
    public const ERROR_WRITE_CONFLICT = 'inventory_warehouse_authorization_write_conflict';
    public const ERROR_SEED_LOCKED = 'inventory_warehouse_authorization_seed_locked';
    public const DEFAULT_SITE_WEBSITE_ID = 0;
    public const DEFAULT_SITE_STORE_ID = 0;
    public const DEFAULT_LOGICAL_CODE = 'SYS-DEFAULT';

    /** @var array<string, array<string, mixed>>|null */
    private ?array $grants = null;
    /** @var array<string, array<string, mixed>>|null */
    private ?array $warehouses = null;
    /** @var (\Closure(): Warehouse)|null */
    private readonly ?\Closure $warehouseFactory;
    /** @var (\Closure(): WarehouseStoreAuthorization)|null */
    private readonly ?\Closure $authorizationFactory;

    /**
     * @param (callable(): Warehouse)|null $warehouseFactory
     * @param (callable(): WarehouseStoreAuthorization)|null $authorizationFactory
     */
    public function __construct(
        private readonly ?StoreCatalogInterface $stores = null,
        ?callable $warehouseFactory = null,
        ?callable $authorizationFactory = null,
        bool $useMemory = false,
    ) {
        $this->warehouseFactory = $warehouseFactory !== null
            ? \Closure::fromCallable($warehouseFactory)
            : null;
        $this->authorizationFactory = $authorizationFactory !== null
            ? \Closure::fromCallable($authorizationFactory)
            : null;
        if ($useMemory) {
            $this->grants = [];
            $this->warehouses = [];
        }
    }

    public static function forTesting(): self
    {
        return new self(useMemory: true);
    }

    /** @param array<string, mixed> $warehouse */
    public function registerWarehouse(array $warehouse): void
    {
        if ($this->warehouses === null) {
            throw new \LogicException('registerWarehouse is available only in the explicit memory harness');
        }
        $id = (int) ($warehouse[Warehouse::schema_fields_ID] ?? 0);
        if ($id <= 0) {
            throw new \InvalidArgumentException('warehouse_id required');
        }
        $this->warehouses[(string) $id] = $warehouse;
    }
    /** @param array<string,mixed> $warehouse @return array<string,mixed> */
    public function createWarehouse(array $warehouse): array
    {
        if ($this->warehouses !== null) {
            throw new \LogicException('createWarehouse is available only with the durable repository');
        }
        $websiteId = (int)($warehouse[Warehouse::schema_fields_WEBSITE_ID] ?? -1);
        $code = trim((string)($warehouse[Warehouse::schema_fields_WAREHOUSE_CODE] ?? ''));
        $name = trim((string)($warehouse[Warehouse::schema_fields_NAME] ?? ''));
        if ($websiteId < 0 || $code === '' || $name === '') {
            throw new \InvalidArgumentException(self::t('仓 website_id、代码和名称不能为空'));
        }

        $now = date('Y-m-d H:i:s');
        $model = $this->newWarehouse();
        $model->clear()->setData(array_merge($warehouse, [
            Warehouse::schema_fields_WEBSITE_ID => $websiteId,
            Warehouse::schema_fields_WAREHOUSE_CODE => $code,
            Warehouse::schema_fields_NAME => $name,
            Warehouse::schema_fields_CREATED_AT => $now,
            Warehouse::schema_fields_UPDATED_AT => $now,
        ]))->save();
        $warehouseId = (int)$model->getId();
        $loaded = $this->loadWarehouse($warehouseId);
        if ($loaded === null) {
            throw new \RuntimeException(self::t('仓写入后无法回读：%{1}', [$warehouseId]));
        }

        return $loaded;
    }


    /**
     * Compatibility entry point. Production still performs the complete trusted validation.
     */
    public function grant(
        int $websiteId,
        int $storeId,
        int $warehouseId,
        ?string $storeMode = null,
        bool $isDefault = false,
    ): void {
        if ($storeMode === null && $this->warehouses !== null) {
            $storeMode = (string) (
                $this->warehouses[(string) $warehouseId][Warehouse::schema_fields_MODE]
                ?? Warehouse::MODE_NORMAL
            );
        }
        $result = $this->assertBindAllowed([
            'website_id' => $websiteId,
            'store_id' => $storeId,
            'store_mode' => (string) $storeMode,
            'warehouse_id' => $warehouseId,
            'is_default' => $isDefault,
        ]);
        if (!$result['ok']) {
            throw new InventoryConflictException(
                (string) $result['error'],
                self::t('仓授权被拒绝'),
                ['website_id' => $websiteId, 'store_id' => $storeId, 'warehouse_id' => $warehouseId],
            );
        }
    }

    /**
     * @param array{website_id:int,store_id:int,warehouse_id:int,store_mode?:string,is_default?:bool} $binding
     * @return array{ok:bool,granted?:bool,error?:string}
     */
    public function assertBindAllowed(array $binding): array
    {
        try {
            $this->bind($binding);
            return ['ok' => true, 'granted' => true];
        } catch (InventoryConflictException $exception) {
            return ['ok' => false, 'error' => $exception->errorCode()];
        }
    }

    /**
     * @param array{website_id:int,store_id:int,warehouse_id:int,store_mode?:string,is_default?:bool,is_seed?:bool} $binding
     * @return array<string, mixed>
     */
    public function bind(array $binding): array
    {
        $websiteId = (int) ($binding['website_id'] ?? -1);
        $storeId = (int) ($binding['store_id'] ?? 0);
        $warehouseId = (int) ($binding['warehouse_id'] ?? 0);
        $isDefault = (bool) ($binding['is_default'] ?? false);
        $isSeed = (bool) ($binding['is_seed'] ?? false);
        if ($websiteId < 0 || $storeId < 0 || $warehouseId <= 0) {
            throw new InventoryConflictException(self::ERROR_NOT_AUTHORIZED, self::t('仓授权 Scope 无效'));
        }

        if ($this->warehouses !== null && $this->grants !== null) {
            $storeMode = (string) ($binding['store_mode'] ?? '');
            return $this->bindMemory($websiteId, $storeId, $storeMode, $warehouseId, $isDefault, $isSeed);
        }

        $store = $this->storeCatalog()->byId($storeId);
        if ($store === null) {
            throw new InventoryConflictException(
                self::ERROR_NOT_AUTHORIZED,
                self::t('Store 不存在：%{1}', [$storeId]),
            );
        }
        if ($store->websiteId !== $websiteId) {
            throw new InventoryConflictException(
                self::ERROR_WEBSITE_MISMATCH,
                self::t('Store 与仓不属于同一 Website'),
            );
        }
        if (!$store->enabled || $store->lifecycleStatus !== 'active' || $store->tombstonedAt !== null) {
            throw new InventoryConflictException(
                self::ERROR_STORE_INACTIVE,
                self::t('Store %{1} 已停用或不在 active 生命周期', [$storeId]),
            );
        }
        $requiredWarehouseMode = $this->warehouseModeForStore($store->storeMode);
        $warehouse = $this->loadWarehouse($warehouseId);
        $this->assertWarehouseAllowed($warehouse, $websiteId, $requiredWarehouseMode, $isDefault);

        return $this->persistBinding(
            $websiteId,
            $storeId,
            $store->storeMode,
            $warehouseId,
            $isDefault,
            $isSeed,
        );
    }

    /**
     * Ensure default website/store seed authorization matrix (idempotent).
     * Mounts seed leaf warehouses under store 0 and keeps one default logical seed.
     *
     * @return array<string, mixed>
     */
    public function ensureDefaultSiteAuthorization(): array
    {
        $websiteId = self::DEFAULT_SITE_WEBSITE_ID;
        $storeId = self::DEFAULT_SITE_STORE_ID;
        if ($this->warehouses !== null && $this->grants !== null) {
            return $this->ensureDefaultSiteAuthorizationMemory();
        }

        $store = $this->storeCatalog()->byId($storeId);
        if ($store === null || $store->websiteId !== $websiteId) {
            throw new InventoryConflictException(
                self::ERROR_NOT_AUTHORIZED,
                self::t('默认店铺不可用于仓授权种子'),
            );
        }
        $mode = $this->warehouseModeForStore($store->storeMode);
        $this->ensureSeedWarehouseTree($websiteId, $mode);
        $default = $this->ensureDefaultLogicalSeedBinding($websiteId, $storeId, $store->storeMode, $mode);
        $this->ensureSeedLeafAuthorizations($websiteId, $storeId, $store->storeMode);

        return $default;
    }

    /** @return array{mounted:int,default_authorization_id:int} */
    public function ensureDefaultStoreWarehouseMount(): array
    {
        $default = $this->ensureDefaultSiteAuthorization();

        return [
            'mounted' => $this->countSeedAuthorizations(
                self::DEFAULT_SITE_WEBSITE_ID,
                self::DEFAULT_SITE_STORE_ID,
            ),
            'default_authorization_id' => (int) ($default[WarehouseStoreAuthorization::schema_fields_ID] ?? 0),
        ];
    }

    public function deleteAuthorization(int $websiteId, int $authorizationId): void
    {
        if ($websiteId < 0 || $authorizationId <= 0) {
            throw new \InvalidArgumentException(self::t('删除仓授权参数无效'));
        }
        if ($this->grants !== null) {
            $this->deleteAuthorizationMemory($websiteId, $authorizationId);
            return;
        }
        $model = $this->newAuthorization();
        $model->clear()
            ->where(WarehouseStoreAuthorization::schema_fields_ID, $authorizationId)
            ->where(WarehouseStoreAuthorization::schema_fields_WEBSITE_ID, $websiteId)
            ->find()
            ->fetch();
        if (!$model->getId()) {
            throw new \InvalidArgumentException(self::t('仓授权不存在'));
        }
        if ((int) $model->getData(WarehouseStoreAuthorization::schema_fields_IS_SEED) === 1) {
            throw new InventoryConflictException(
                self::ERROR_SEED_LOCKED,
                self::t('系统种子授权不允许删除'),
            );
        }
        $model->clear()
            ->where(WarehouseStoreAuthorization::schema_fields_ID, $authorizationId)
            ->where(WarehouseStoreAuthorization::schema_fields_WEBSITE_ID, $websiteId)
            ->delete();
    }

    /** @return array<string, mixed> */
    public function bindDefault(int $websiteId, int $storeId, int $warehouseId): array
    {
        return $this->bind([
            'website_id' => $websiteId,
            'store_id' => $storeId,
            'warehouse_id' => $warehouseId,
            'is_default' => true,
        ]);
    }

    public function isAuthorized(int $websiteId, int $storeId, int $warehouseId): bool
    {
        if ($this->grants !== null) {
            return isset($this->grants[$this->grantKey($websiteId, $storeId, $warehouseId)]);
        }
        $row = $this->findAuthorization($websiteId, $storeId, $warehouseId);
        return $row !== null
            && (int) ($row[WarehouseStoreAuthorization::schema_fields_ENABLED] ?? 0) === 1;
    }

    public function grantCount(): int
    {
        if ($this->grants !== null) {
            return count($this->grants);
        }
        $rows = $this->newAuthorization()->clear()->select()->fetchArray();
        return is_array($rows) ? count($rows) : 0;
    }

    /** @return array<string, mixed> */
    private function bindMemory(
        int $websiteId,
        int $storeId,
        string $storeMode,
        int $warehouseId,
        bool $isDefault,
        bool $isSeed = false,
    ): array {
        $requiredWarehouseMode = $this->warehouseModeForStore($storeMode);
        $warehouse = $this->warehouses[(string) $warehouseId] ?? null;
        $this->assertWarehouseAllowed($warehouse, $websiteId, $requiredWarehouseMode, $isDefault);
        $key = $this->grantKey($websiteId, $storeId, $warehouseId);
        $existing = $this->grants[$key] ?? null;
        if ($existing !== null) {
            if ((bool) ($existing['is_default'] ?? false) !== $isDefault) {
                throw new InventoryConflictException(
                    self::ERROR_DEFAULT_CONFLICT,
                    self::t('仓授权请求与既有绑定冲突'),
                );
            }
            if ($isSeed && (int) ($existing['is_seed'] ?? 0) !== 1) {
                $existing['is_seed'] = 1;
                $this->grants[$key] = $existing;
            }
            return $existing;
        }
        if ($isDefault) {
            foreach ($this->grants as $grantKey => $grant) {
                if ((int) $grant['website_id'] === $websiteId
                    && (int) $grant['store_id'] === $storeId
                    && (bool) ($grant['is_default'] ?? false)
                ) {
                    if ((int) ($grant['is_seed'] ?? 0) === 1
                        && (int) $grant['warehouse_id'] !== $warehouseId
                    ) {
                        unset($this->grants[$grantKey]);
                        $grant['warehouse_id'] = $warehouseId;
                        $grant['store_mode_snapshot'] = $storeMode;
                        $grant['is_seed'] = 1;
                        $this->grants[$this->grantKey($websiteId, $storeId, $warehouseId)] = $grant;
                        return $grant;
                    }
                    throw new InventoryConflictException(
                        self::ERROR_DEFAULT_CONFLICT,
                        self::t('Store 已存在不同的默认逻辑仓'),
                    );
                }
            }
        }
        $row = [
            'authorization_id' => count($this->grants) + 1,
            'website_id' => $websiteId,
            'store_id' => $storeId,
            'warehouse_id' => $warehouseId,
            'store_mode_snapshot' => $storeMode,
            'is_default' => $isDefault ? 1 : 0,
            'is_seed' => $isSeed ? 1 : 0,
            'enabled' => 1,
        ];
        $this->grants[$key] = $row;
        return $row;
    }

    /** @param array<string, mixed>|null $warehouse */
    private function assertWarehouseAllowed(
        ?array $warehouse,
        int $websiteId,
        string $requiredWarehouseMode,
        bool $isDefault,
    ): void {
        if ($warehouse === null) {
            throw new InventoryConflictException(self::ERROR_NOT_AUTHORIZED, self::t('仓不存在'));
        }
        if ((int) ($warehouse[Warehouse::schema_fields_WEBSITE_ID] ?? -1) !== $websiteId) {
            throw new InventoryConflictException(
                self::ERROR_WEBSITE_MISMATCH,
                self::t('Store 与仓不属于同一 Website'),
            );
        }
        if ((int) ($warehouse[Warehouse::schema_fields_ENABLED] ?? 0) !== 1) {
            throw new InventoryConflictException(self::ERROR_DISABLED, self::t('仓已停用'));
        }
        if ((string) ($warehouse[Warehouse::schema_fields_MODE] ?? '') !== $requiredWarehouseMode) {
            throw new InventoryConflictException(
                self::ERROR_MODE_MISMATCH,
                self::t('Store 环境与仓模式不兼容'),
            );
        }
        if ($isDefault && !$this->isLogicalWarehouse($warehouse)) {
            throw new InventoryConflictException(
                self::ERROR_DEFAULT_REQUIRES_LOGICAL,
                self::t('默认仓必须是逻辑仓'),
            );
        }
    }

    private function warehouseModeForStore(string $storeMode): string
    {
        return match (strtolower(trim($storeMode))) {
            'normal' => Warehouse::MODE_NORMAL,
            'dev', 'test' => Warehouse::MODE_TEST,
            default => throw new InventoryConflictException(
                self::ERROR_STORE_MODE_INVALID,
                self::t('Store mode 不受支持：%{1}', [$storeMode]),
            ),
        };
    }

    /** @param array<string, mixed> $warehouse */
    private function isLogicalWarehouse(array $warehouse): bool
    {
        return (string) ($warehouse[Warehouse::schema_fields_WAREHOUSE_TYPE] ?? '')
                === Warehouse::TYPE_LOGICAL
            || (int) ($warehouse[Warehouse::schema_fields_IS_DEFAULT_LOGICAL] ?? 0) === 1;
    }

    /** @return array<string, mixed> */
    private function persistBinding(
        int $websiteId,
        int $storeId,
        string $storeMode,
        int $warehouseId,
        bool $isDefault,
        bool $isSeed = false,
    ): array {
        $existing = $this->findAuthorization($websiteId, $storeId, $warehouseId);
        if ($existing !== null) {
            if ((int) ($existing[WarehouseStoreAuthorization::schema_fields_IS_DEFAULT] ?? 0)
                !== ($isDefault ? 1 : 0)
            ) {
                throw new InventoryConflictException(
                    self::ERROR_DEFAULT_CONFLICT,
                    self::t('仓授权请求与既有绑定冲突'),
                );
            }
            if ($isSeed && (int) ($existing[WarehouseStoreAuthorization::schema_fields_IS_SEED] ?? 0) !== 1) {
                return $this->markAuthorizationSeed($existing);
            }
            return $existing;
        }
        if ($isDefault) {
            $default = $this->findDefaultAuthorization($websiteId, $storeId);
            if ($default !== null
                && (int) $default[WarehouseStoreAuthorization::schema_fields_WAREHOUSE_ID]
                    !== $warehouseId
            ) {
                if ((int) ($default[WarehouseStoreAuthorization::schema_fields_IS_SEED] ?? 0) === 1) {
                    return $this->rebindSeedDefault($default, $warehouseId, $storeMode);
                }
                throw new InventoryConflictException(
                    self::ERROR_DEFAULT_CONFLICT,
                    self::t('Store 已存在不同的默认逻辑仓'),
                );
            }
        }

        $now = date('Y-m-d H:i:s');
        try {
            $model = $this->newAuthorization();
            $model->clear()->setData([
                WarehouseStoreAuthorization::schema_fields_WEBSITE_ID => $websiteId,
                WarehouseStoreAuthorization::schema_fields_STORE_ID => $storeId,
                WarehouseStoreAuthorization::schema_fields_WAREHOUSE_ID => $warehouseId,
                WarehouseStoreAuthorization::schema_fields_STORE_MODE_SNAPSHOT => $storeMode,
                WarehouseStoreAuthorization::schema_fields_IS_DEFAULT => $isDefault ? 1 : 0,
                WarehouseStoreAuthorization::schema_fields_ENABLED => 1,
                WarehouseStoreAuthorization::schema_fields_IS_SEED => $isSeed ? 1 : 0,
                WarehouseStoreAuthorization::schema_fields_AUTHORIZATION_VERSION => 0,
                WarehouseStoreAuthorization::schema_fields_CREATED_AT => $now,
                WarehouseStoreAuthorization::schema_fields_UPDATED_AT => $now,
            ])->save();
            return $model->getData();
        } catch (Throwable $exception) {
            $winner = $this->findAuthorization($websiteId, $storeId, $warehouseId);
            if ($winner !== null
                && (int) ($winner[WarehouseStoreAuthorization::schema_fields_IS_DEFAULT] ?? 0)
                    === ($isDefault ? 1 : 0)
            ) {
                return $winner;
            }
            if ($isDefault && $this->findDefaultAuthorization($websiteId, $storeId) !== null) {
                throw new InventoryConflictException(
                    self::ERROR_DEFAULT_CONFLICT,
                    self::t('Store 已存在不同的默认逻辑仓'),
                    previous: $exception,
                );
            }
            throw new InventoryConflictException(
                self::ERROR_WRITE_CONFLICT,
                self::t('仓授权写入冲突'),
                previous: $exception,
            );
        }
    }

    /**
     * @param array<string, mixed> $seed
     * @return array<string, mixed>
     */
    private function rebindSeedDefault(array $seed, int $warehouseId, string $storeMode): array
    {
        $authorizationId = (int) ($seed[WarehouseStoreAuthorization::schema_fields_ID] ?? 0);
        $websiteId = (int) ($seed[WarehouseStoreAuthorization::schema_fields_WEBSITE_ID] ?? -1);
        $storeId = (int) ($seed[WarehouseStoreAuthorization::schema_fields_STORE_ID] ?? -1);
        if ($authorizationId <= 0 || $websiteId < 0 || $storeId < 0) {
            throw new InventoryConflictException(self::ERROR_WRITE_CONFLICT, self::t('种子授权改绑失败'));
        }
        $collision = $this->findAuthorization($websiteId, $storeId, $warehouseId);
        if ($collision !== null
            && (int) ($collision[WarehouseStoreAuthorization::schema_fields_ID] ?? 0) !== $authorizationId
        ) {
            $this->newAuthorization()->clear()
                ->where(
                    WarehouseStoreAuthorization::schema_fields_ID,
                    (int) $collision[WarehouseStoreAuthorization::schema_fields_ID],
                )
                ->delete();
        }
        $version = max(0, (int) ($seed[WarehouseStoreAuthorization::schema_fields_AUTHORIZATION_VERSION] ?? 0)) + 1;
        $now = date('Y-m-d H:i:s');
        $this->newAuthorization()->clear()
            ->where(WarehouseStoreAuthorization::schema_fields_ID, $authorizationId)
            ->update([
                WarehouseStoreAuthorization::schema_fields_WAREHOUSE_ID => $warehouseId,
                WarehouseStoreAuthorization::schema_fields_STORE_MODE_SNAPSHOT => $storeMode,
                WarehouseStoreAuthorization::schema_fields_IS_SEED => 1,
                WarehouseStoreAuthorization::schema_fields_AUTHORIZATION_VERSION => $version,
                WarehouseStoreAuthorization::schema_fields_UPDATED_AT => $now,
            ])
            ->fetch();
        $reloaded = $this->findAuthorization($websiteId, $storeId, $warehouseId);
        if ($reloaded === null) {
            throw new InventoryConflictException(self::ERROR_WRITE_CONFLICT, self::t('种子授权改绑后无法回读'));
        }
        return $reloaded;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function markAuthorizationSeed(array $row): array
    {
        $authorizationId = (int) ($row[WarehouseStoreAuthorization::schema_fields_ID] ?? 0);
        if ($authorizationId <= 0) {
            return $row;
        }
        if ((int) ($row[WarehouseStoreAuthorization::schema_fields_IS_SEED] ?? 0) === 1) {
            return $row;
        }
        $now = date('Y-m-d H:i:s');
        $this->newAuthorization()->clear()
            ->where(WarehouseStoreAuthorization::schema_fields_ID, $authorizationId)
            ->update([
                WarehouseStoreAuthorization::schema_fields_IS_SEED => 1,
                WarehouseStoreAuthorization::schema_fields_UPDATED_AT => $now,
            ])
            ->fetch();
        $row[WarehouseStoreAuthorization::schema_fields_IS_SEED] = 1;
        $row[WarehouseStoreAuthorization::schema_fields_UPDATED_AT] = $now;
        return $row;
    }

    /** @return array<string, mixed> */
    private function ensureDefaultLogicalWarehouse(int $websiteId, string $mode): array
    {
        $existing = $this->findDefaultLogicalWarehouse($websiteId, $mode);
        if ($existing !== null) {
            if ((int) ($existing[Warehouse::schema_fields_IS_SEED] ?? 0) !== 1) {
                $this->newWarehouse()->clear()
                    ->where(Warehouse::schema_fields_ID, (int) $existing[Warehouse::schema_fields_ID])
                    ->update([
                        Warehouse::schema_fields_IS_SEED => 1,
                        Warehouse::schema_fields_UPDATED_AT => date('Y-m-d H:i:s'),
                    ])
                    ->fetch();
                $existing[Warehouse::schema_fields_IS_SEED] = 1;
            }
            return $existing;
        }

        return $this->createWarehouse([
            Warehouse::schema_fields_WEBSITE_ID => $websiteId,
            Warehouse::schema_fields_PARENT_ID => 0,
            Warehouse::schema_fields_NODE_KIND => Warehouse::NODE_WAREHOUSE,
            Warehouse::schema_fields_WAREHOUSE_CODE => self::DEFAULT_LOGICAL_CODE,
            Warehouse::schema_fields_NAME => '系统默认逻辑仓',
            Warehouse::schema_fields_MODE => $mode,
            Warehouse::schema_fields_WAREHOUSE_TYPE => Warehouse::TYPE_LOGICAL,
            Warehouse::schema_fields_IS_DEFAULT_LOGICAL => 1,
            Warehouse::schema_fields_ENABLED => 1,
            Warehouse::schema_fields_IS_SEED => 1,
        ]);
    }

    /** @return array<string, mixed>|null */
    private function findDefaultLogicalWarehouse(int $websiteId, string $mode): ?array
    {
        $model = $this->newWarehouse();
        $model->clear()
            ->where(Warehouse::schema_fields_WEBSITE_ID, $websiteId)
            ->where(Warehouse::schema_fields_MODE, $mode)
            ->where(Warehouse::schema_fields_IS_DEFAULT_LOGICAL, 1)
            ->where(Warehouse::schema_fields_ENABLED, 1)
            ->find()
            ->fetch();
        if ($model->getId()) {
            return $model->getData();
        }
        $model->clear()
            ->where(Warehouse::schema_fields_WEBSITE_ID, $websiteId)
            ->where(Warehouse::schema_fields_WAREHOUSE_CODE, self::DEFAULT_LOGICAL_CODE)
            ->find()
            ->fetch();
        return $model->getId() ? $model->getData() : null;
    }

    /** @return array<string, mixed>|null */
    private function findSeedAuthorization(int $websiteId, int $storeId): ?array
    {
        $model = $this->newAuthorization();
        $model->clear()
            ->where(WarehouseStoreAuthorization::schema_fields_WEBSITE_ID, $websiteId)
            ->where(WarehouseStoreAuthorization::schema_fields_STORE_ID, $storeId)
            ->where(WarehouseStoreAuthorization::schema_fields_IS_SEED, 1)
            ->where(WarehouseStoreAuthorization::schema_fields_ENABLED, 1)
            ->find()
            ->fetch();
        return $model->getId() ? $model->getData() : null;
    }

    private function ensureSeedWarehouseTree(int $websiteId, string $mode): void
    {
        try {
            /** @var WarehouseHierarchyService $hierarchy */
            $hierarchy = ObjectManager::getInstance(WarehouseHierarchyService::class);
            $hierarchy->ensureDefaultTree($websiteId, $mode);
        } catch (\Throwable) {
            // Tree seed must not block authorization ensure; leaf mount uses whatever exists.
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function ensureDefaultLogicalSeedBinding(
        int $websiteId,
        int $storeId,
        string $storeMode,
        string $warehouseMode,
    ): array {
        $seedDefault = $this->findSeedDefaultAuthorization($websiteId, $storeId);
        if ($seedDefault !== null) {
            return $seedDefault;
        }
        $default = $this->findDefaultAuthorization($websiteId, $storeId);
        if ($default !== null) {
            return $this->markAuthorizationSeed($default);
        }
        $warehouse = $this->ensureDefaultLogicalWarehouse($websiteId, $warehouseMode);

        return $this->persistBinding(
            $websiteId,
            $storeId,
            $storeMode,
            (int) $warehouse[Warehouse::schema_fields_ID],
            true,
            true,
        );
    }

    private function ensureSeedLeafAuthorizations(int $websiteId, int $storeId, string $storeMode): void
    {
        $leaves = $this->listSeedLeafWarehouses($websiteId);
        foreach ($leaves as $leaf) {
            $warehouseId = (int) ($leaf[Warehouse::schema_fields_ID] ?? 0);
            if ($warehouseId <= 0) {
                continue;
            }
            $existing = $this->findAuthorization($websiteId, $storeId, $warehouseId);
            if ($existing !== null) {
                if ((int) ($existing[WarehouseStoreAuthorization::schema_fields_IS_SEED] ?? 0) !== 1) {
                    $this->markAuthorizationSeed($existing);
                }
                continue;
            }
            try {
                $this->persistBinding(
                    $websiteId,
                    $storeId,
                    $storeMode,
                    $warehouseId,
                    false,
                    true,
                );
            } catch (InventoryConflictException) {
                // Skip leaves that cannot bind under current mode/type rules.
            }
        }
    }

    /** @return list<array<string, mixed>> */
    private function listSeedLeafWarehouses(int $websiteId): array
    {
        $model = $this->newWarehouse();
        $rows = $model->clear()
            ->where(Warehouse::schema_fields_WEBSITE_ID, $websiteId)
            ->where(Warehouse::schema_fields_IS_SEED, 1)
            ->where(Warehouse::schema_fields_NODE_KIND, Warehouse::NODE_WAREHOUSE)
            ->where(Warehouse::schema_fields_ENABLED, 1)
            ->select()
            ->fetchArray();

        return is_array($rows) ? $rows : [];
    }

    /** @return array<string, mixed>|null */
    private function findSeedDefaultAuthorization(int $websiteId, int $storeId): ?array
    {
        $model = $this->newAuthorization();
        $model->clear()
            ->where(WarehouseStoreAuthorization::schema_fields_WEBSITE_ID, $websiteId)
            ->where(WarehouseStoreAuthorization::schema_fields_STORE_ID, $storeId)
            ->where(WarehouseStoreAuthorization::schema_fields_IS_SEED, 1)
            ->where(WarehouseStoreAuthorization::schema_fields_IS_DEFAULT, 1)
            ->where(WarehouseStoreAuthorization::schema_fields_ENABLED, 1)
            ->find()
            ->fetch();

        return $model->getId() ? $model->getData() : null;
    }

    private function countSeedAuthorizations(int $websiteId, int $storeId): int
    {
        $rows = $this->newAuthorization()->clear()
            ->where(WarehouseStoreAuthorization::schema_fields_WEBSITE_ID, $websiteId)
            ->where(WarehouseStoreAuthorization::schema_fields_STORE_ID, $storeId)
            ->where(WarehouseStoreAuthorization::schema_fields_IS_SEED, 1)
            ->where(WarehouseStoreAuthorization::schema_fields_ENABLED, 1)
            ->select()
            ->fetchArray();

        return is_array($rows) ? count($rows) : 0;
    }

    /** @return array<string, mixed> */
    private function ensureDefaultSiteAuthorizationMemory(): array
    {
        foreach ($this->grants ?? [] as $grant) {
            if ((int) $grant['website_id'] === self::DEFAULT_SITE_WEBSITE_ID
                && (int) $grant['store_id'] === self::DEFAULT_SITE_STORE_ID
                && (int) ($grant['is_seed'] ?? 0) === 1
            ) {
                return $grant;
            }
        }
        $warehouseId = null;
        foreach ($this->warehouses ?? [] as $warehouse) {
            if ((int) ($warehouse[Warehouse::schema_fields_WEBSITE_ID] ?? -1) !== self::DEFAULT_SITE_WEBSITE_ID) {
                continue;
            }
            if ($this->isLogicalWarehouse($warehouse)
                && (int) ($warehouse[Warehouse::schema_fields_IS_DEFAULT_LOGICAL] ?? 0) === 1
            ) {
                $warehouseId = (int) $warehouse[Warehouse::schema_fields_ID];
                break;
            }
        }
        if ($warehouseId === null) {
            throw new InventoryConflictException(
                self::ERROR_NOT_AUTHORIZED,
                self::t('内存 harness 需先注册默认逻辑仓'),
            );
        }
        return $this->bindMemory(
            self::DEFAULT_SITE_WEBSITE_ID,
            self::DEFAULT_SITE_STORE_ID,
            Warehouse::MODE_NORMAL,
            $warehouseId,
            true,
            true,
        );
    }

    private function deleteAuthorizationMemory(int $websiteId, int $authorizationId): void
    {
        foreach ($this->grants ?? [] as $key => $grant) {
            if ((int) ($grant['authorization_id'] ?? 0) !== $authorizationId) {
                continue;
            }
            if ((int) $grant['website_id'] !== $websiteId) {
                throw new \InvalidArgumentException(self::t('仓授权不存在'));
            }
            if ((int) ($grant['is_seed'] ?? 0) === 1) {
                throw new InventoryConflictException(
                    self::ERROR_SEED_LOCKED,
                    self::t('系统种子授权不允许删除'),
                );
            }
            unset($this->grants[$key]);
            return;
        }
        throw new \InvalidArgumentException(self::t('仓授权不存在'));
    }

    /** @return array<string, mixed>|null */
    private function loadWarehouse(int $warehouseId): ?array
    {
        $model = $this->newWarehouse();
        $model->clear()
            ->where(Warehouse::schema_fields_ID, $warehouseId)
            ->find()
            ->fetch();
        return $model->getId() ? $model->getData() : null;
    }

    /** @return array<string, mixed>|null */
    private function findAuthorization(int $websiteId, int $storeId, int $warehouseId): ?array
    {
        $model = $this->newAuthorization();
        $model->clear()
            ->where(WarehouseStoreAuthorization::schema_fields_WEBSITE_ID, $websiteId)
            ->where(WarehouseStoreAuthorization::schema_fields_STORE_ID, $storeId)
            ->where(WarehouseStoreAuthorization::schema_fields_WAREHOUSE_ID, $warehouseId)
            ->find()
            ->fetch();
        return $model->getId() ? $model->getData() : null;
    }

    /** @return array<string, mixed>|null */
    private function findDefaultAuthorization(int $websiteId, int $storeId): ?array
    {
        $model = $this->newAuthorization();
        $model->clear()
            ->where(WarehouseStoreAuthorization::schema_fields_WEBSITE_ID, $websiteId)
            ->where(WarehouseStoreAuthorization::schema_fields_STORE_ID, $storeId)
            ->where(
                WarehouseStoreAuthorization::schema_fields_DEFAULT_GUARD,
                WarehouseStoreAuthorization::DEFAULT_GUARD,
            )
            ->where(WarehouseStoreAuthorization::schema_fields_ENABLED, 1)
            ->find()
            ->fetch();
        return $model->getId() ? $model->getData() : null;
    }

    private function newWarehouse(): Warehouse
    {
        return $this->warehouseFactory !== null
            ? ($this->warehouseFactory)()
            : ObjectManager::create(Warehouse::class, [], false);
    }

    private function newAuthorization(): WarehouseStoreAuthorization
    {
        return $this->authorizationFactory !== null
            ? ($this->authorizationFactory)()
            : ObjectManager::create(WarehouseStoreAuthorization::class, [], false);
    }

    private function storeCatalog(): StoreCatalogInterface
    {
        $catalog = $this->stores ?? ObjectManager::getInstance(StoreCatalogInterface::class);
        if (!$catalog instanceof StoreCatalogInterface) {
            throw new \LogicException('StoreCatalogInterface binding is unavailable');
        }
        return $catalog;
    }

    private function grantKey(int $websiteId, int $storeId, int $warehouseId): string
    {
        return $websiteId . ':' . $storeId . ':' . $warehouseId;
    }

    /** @param list<string|int>|array<int, string|int> $args */
    private static function t(string $text, array $args = []): string
    {
        try {
            return (string) __($text, $args);
        } catch (\Throwable) {
            $out = $text;
            $i = 1;
            foreach ($args as $arg) {
                $out = str_replace('%{' . $i . '}', (string) $arg, $out);
                $i++;
            }
            return $out;
        }
    }
}
