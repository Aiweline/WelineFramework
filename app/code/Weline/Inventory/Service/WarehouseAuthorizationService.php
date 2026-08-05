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
            throw new \InvalidArgumentException(__('仓 website_id、代码和名称不能为空'));
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
            throw new \RuntimeException(__('仓写入后无法回读：%{1}', [$warehouseId]));
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
                __('仓授权被拒绝'),
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
     * @param array{website_id:int,store_id:int,warehouse_id:int,store_mode?:string,is_default?:bool} $binding
     * @return array<string, mixed>
     */
    public function bind(array $binding): array
    {
        $websiteId = (int) ($binding['website_id'] ?? -1);
        $storeId = (int) ($binding['store_id'] ?? 0);
        $warehouseId = (int) ($binding['warehouse_id'] ?? 0);
        $isDefault = (bool) ($binding['is_default'] ?? false);
        if ($websiteId < 0 || $storeId < 0 || $warehouseId <= 0) {
            throw new InventoryConflictException(self::ERROR_NOT_AUTHORIZED, __('仓授权 Scope 无效'));
        }

        if ($this->warehouses !== null && $this->grants !== null) {
            $storeMode = (string) ($binding['store_mode'] ?? '');
            return $this->bindMemory($websiteId, $storeId, $storeMode, $warehouseId, $isDefault);
        }

        $store = $this->storeCatalog()->byId($storeId);
        if ($store === null) {
            throw new InventoryConflictException(
                self::ERROR_NOT_AUTHORIZED,
                __('Store 不存在：%{1}', [$storeId]),
            );
        }
        if ($store->websiteId !== $websiteId) {
            throw new InventoryConflictException(
                self::ERROR_WEBSITE_MISMATCH,
                __('Store 与仓不属于同一 Website'),
            );
        }
        if (!$store->enabled || $store->lifecycleStatus !== 'active' || $store->tombstonedAt !== null) {
            throw new InventoryConflictException(
                self::ERROR_STORE_INACTIVE,
                __('Store %{1} 已停用或不在 active 生命周期', [$storeId]),
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
        );
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
                    __('仓授权请求与既有绑定冲突'),
                );
            }
            return $existing;
        }
        if ($isDefault) {
            foreach ($this->grants as $grant) {
                if ((int) $grant['website_id'] === $websiteId
                    && (int) $grant['store_id'] === $storeId
                    && (bool) ($grant['is_default'] ?? false)
                ) {
                    throw new InventoryConflictException(
                        self::ERROR_DEFAULT_CONFLICT,
                        __('Store 已存在不同的默认逻辑仓'),
                    );
                }
            }
        }
        $row = [
            'website_id' => $websiteId,
            'store_id' => $storeId,
            'warehouse_id' => $warehouseId,
            'store_mode_snapshot' => $storeMode,
            'is_default' => $isDefault ? 1 : 0,
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
            throw new InventoryConflictException(self::ERROR_NOT_AUTHORIZED, __('仓不存在'));
        }
        if ((int) ($warehouse[Warehouse::schema_fields_WEBSITE_ID] ?? -1) !== $websiteId) {
            throw new InventoryConflictException(
                self::ERROR_WEBSITE_MISMATCH,
                __('Store 与仓不属于同一 Website'),
            );
        }
        if ((int) ($warehouse[Warehouse::schema_fields_ENABLED] ?? 0) !== 1) {
            throw new InventoryConflictException(self::ERROR_DISABLED, __('仓已停用'));
        }
        if ((string) ($warehouse[Warehouse::schema_fields_MODE] ?? '') !== $requiredWarehouseMode) {
            throw new InventoryConflictException(
                self::ERROR_MODE_MISMATCH,
                __('Store 环境与仓模式不兼容'),
            );
        }
        if ($isDefault && !$this->isLogicalWarehouse($warehouse)) {
            throw new InventoryConflictException(
                self::ERROR_DEFAULT_REQUIRES_LOGICAL,
                __('默认仓必须是逻辑仓'),
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
                __('Store mode 不受支持：%{1}', [$storeMode]),
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
    ): array {
        $existing = $this->findAuthorization($websiteId, $storeId, $warehouseId);
        if ($existing !== null) {
            if ((int) ($existing[WarehouseStoreAuthorization::schema_fields_IS_DEFAULT] ?? 0)
                !== ($isDefault ? 1 : 0)
            ) {
                throw new InventoryConflictException(
                    self::ERROR_DEFAULT_CONFLICT,
                    __('仓授权请求与既有绑定冲突'),
                );
            }
            return $existing;
        }
        if ($isDefault) {
            $default = $this->findDefaultAuthorization($websiteId, $storeId);
            if ($default !== null
                && (int) $default[WarehouseStoreAuthorization::schema_fields_WAREHOUSE_ID]
                    !== $warehouseId
            ) {
                throw new InventoryConflictException(
                    self::ERROR_DEFAULT_CONFLICT,
                    __('Store 已存在不同的默认逻辑仓'),
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
                    __('Store 已存在不同的默认逻辑仓'),
                    previous: $exception,
                );
            }
            throw new InventoryConflictException(
                self::ERROR_WRITE_CONFLICT,
                __('仓授权写入冲突'),
                previous: $exception,
            );
        }
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
}
