<?php

declare(strict_types=1);

namespace Weline\Inventory\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Inventory\Api\Data\WarehouseAssignment;
use Weline\Inventory\Api\DefaultWarehouseResolverInterface;
use Weline\Inventory\Model\Warehouse;
use Weline\Inventory\Model\WarehouseStoreAuthorization;
use Weline\Websites\Api\Catalog\StoreCatalogInterface;

/** Store → durable default logical warehouse resolver（P3A-001）. */
final class DefaultLogicalWarehouseResolver implements DefaultWarehouseResolverInterface
{
    public const ERROR_AMBIGUOUS = 'inventory_default_logical_warehouse_ambiguous';
    public const ERROR_MISSING = DefaultWarehouseResolverInterface::ERROR_MISSING;

    /** @var array<string, array<string, mixed>>|null */
    private ?array $warehouses = null;
    /** @var array<string, int>|null */
    private ?array $storeMap = null;
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
            $this->warehouses = [];
            $this->storeMap = [];
        }
    }

    public static function forTesting(): self
    {
        return new self(useMemory: true);
    }

    /**
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    public function seedWarehouse(
        int $warehouseId,
        int $websiteId,
        string $code,
        string $mode = Warehouse::MODE_NORMAL,
        bool $isDefaultLogical = false,
        array $extra = [],
    ): array {
        if ($this->warehouses === null) {
            throw new \LogicException('seedWarehouse is available only in the explicit memory harness');
        }
        $row = array_merge([
            Warehouse::schema_fields_ID => $warehouseId,
            Warehouse::schema_fields_WEBSITE_ID => $websiteId,
            Warehouse::schema_fields_WAREHOUSE_CODE => $code,
            Warehouse::schema_fields_NAME => $code,
            Warehouse::schema_fields_MODE => $mode,
            Warehouse::schema_fields_WAREHOUSE_TYPE => $isDefaultLogical
                ? Warehouse::TYPE_LOGICAL
                : Warehouse::TYPE_PHYSICAL,
            Warehouse::schema_fields_IS_DEFAULT_LOGICAL => $isDefaultLogical ? 1 : 0,
            Warehouse::schema_fields_DEFAULT_LOGICAL_GUARD => $isDefaultLogical
                ? Warehouse::DEFAULT_GUARD
                : null,
            Warehouse::schema_fields_ENABLED => 1,
        ], $extra, [
            Warehouse::schema_fields_ID => $warehouseId,
            Warehouse::schema_fields_WEBSITE_ID => $websiteId,
            Warehouse::schema_fields_WAREHOUSE_CODE => $code,
            Warehouse::schema_fields_MODE => $mode,
            Warehouse::schema_fields_IS_DEFAULT_LOGICAL => $isDefaultLogical ? 1 : 0,
        ]);
        $this->warehouses[(string) $warehouseId] = $row;
        return $row;
    }

    public function bindStoreDefault(int $websiteId, int $storeId, int $warehouseId): void
    {
        if ($this->storeMap === null) {
            throw new \LogicException(
                'Durable defaults must be written through WarehouseAuthorizationService',
            );
        }
        $this->storeMap[$this->storeKey($websiteId, $storeId)] = $warehouseId;
    }

    /** @return array<string, mixed> */
    public function resolve(
        int $websiteId,
        int $storeId,
        string $storeMode = Warehouse::MODE_NORMAL,
    ): array {
        if ($this->warehouses !== null && $this->storeMap !== null) {
            return $this->resolveMemory($websiteId, $storeId, $storeMode);
        }

        $store = $this->storeCatalog()->byId($storeId);
        if ($store === null || $store->websiteId !== $websiteId) {
            throw new InventoryConflictException(
                self::ERROR_MISSING,
                __('Store %{1}/%{2} 默认仓缺失', [$websiteId, $storeId]),
            );
        }
        if (!$store->enabled
            || $store->lifecycleStatus !== 'active'
            || $store->tombstonedAt !== null
        ) {
            throw new InventoryConflictException(
                WarehouseAuthorizationService::ERROR_STORE_INACTIVE,
                __('Store %{1} 已停用或不在 active 生命周期', [$storeId]),
            );
        }
        $warehouseMode = $this->warehouseModeForStore($store->storeMode);
        $bindings = $this->defaultBindings($websiteId, $storeId);
        if (count($bindings) > 1) {
            throw new InventoryConflictException(
                self::ERROR_AMBIGUOUS,
                __('Store %{1}/%{2} 默认逻辑仓不唯一', [$websiteId, $storeId]),
            );
        }
        if ($bindings !== []) {
            $warehouse = $this->loadWarehouse(
                (int) $bindings[0][WarehouseStoreAuthorization::schema_fields_WAREHOUSE_ID],
            );
            $this->assertWarehouse($warehouse, $websiteId, $warehouseMode, $storeId);
            return $warehouse;
        }

        $candidates = $this->websiteDefaults($websiteId, $warehouseMode);
        if ($candidates === []) {
            throw new InventoryConflictException(
                self::ERROR_MISSING,
                __('Website %{1} 无 mode=%{2} 默认逻辑仓', [$websiteId, $warehouseMode]),
            );
        }
        if (count($candidates) > 1) {
            throw new InventoryConflictException(
                self::ERROR_AMBIGUOUS,
                __('Website %{1} 默认逻辑仓不唯一（mode=%{2}）', [$websiteId, $warehouseMode]),
            );
        }
        return $candidates[0];
    }

    public function resolveDefault(int $websiteId, int $storeId): WarehouseAssignment
    {
        $warehouse = $this->resolve($websiteId, $storeId);
        $writerEnabled = false;
        if ($this->warehouses === null) {
            $bindings = $this->defaultBindings($websiteId, $storeId);
            if (count($bindings) === 1) {
                $writerEnabled = (int) (
                    $bindings[0][WarehouseStoreAuthorization::schema_fields_WRITER_ENABLED]
                    ?? 0
                ) === 1;
            }
        }

        return new WarehouseAssignment(
            warehouseId: (int) $warehouse[Warehouse::schema_fields_ID],
            websiteId: (int) $warehouse[Warehouse::schema_fields_WEBSITE_ID],
            warehouseCode: (string) $warehouse[Warehouse::schema_fields_WAREHOUSE_CODE],
            mode: (string) $warehouse[Warehouse::schema_fields_MODE],
            warehouseType: (string) $warehouse[Warehouse::schema_fields_WAREHOUSE_TYPE],
            writerEnabled: $writerEnabled,
        );
    }

    /** @return array<string, mixed> */
    private function resolveMemory(int $websiteId, int $storeId, string $storeMode): array
    {
        $warehouseMode = $this->warehouseModeForStore($storeMode);
        $key = $this->storeKey($websiteId, $storeId);
        if (isset($this->storeMap[$key])) {
            $warehouse = $this->warehouses[(string) $this->storeMap[$key]] ?? null;
            $this->assertWarehouse($warehouse, $websiteId, $warehouseMode, $storeId);
            return $warehouse;
        }

        $candidates = [];
        foreach ($this->warehouses as $warehouse) {
            if ((int) ($warehouse[Warehouse::schema_fields_WEBSITE_ID] ?? -1) !== $websiteId
                || (int) ($warehouse[Warehouse::schema_fields_ENABLED] ?? 0) !== 1
                || (int) ($warehouse[Warehouse::schema_fields_IS_DEFAULT_LOGICAL] ?? 0) !== 1
                || (string) ($warehouse[Warehouse::schema_fields_MODE] ?? '')
                    !== $warehouseMode
                || !$this->isLogicalWarehouse($warehouse)
            ) {
                continue;
            }
            $candidates[] = $warehouse;
        }
        if ($candidates === []) {
            throw new InventoryConflictException(
                self::ERROR_MISSING,
                __('Website %{1} 无 mode=%{2} 默认逻辑仓', [$websiteId, $warehouseMode]),
            );
        }
        if (count($candidates) > 1) {
            throw new InventoryConflictException(
                self::ERROR_AMBIGUOUS,
                __('Website %{1} 默认逻辑仓不唯一（mode=%{2}）', [$websiteId, $warehouseMode]),
            );
        }
        return $candidates[0];
    }

    /** @return list<array<string, mixed>> */
    private function defaultBindings(int $websiteId, int $storeId): array
    {
        $rows = $this->newAuthorization()->clear()
            ->where(WarehouseStoreAuthorization::schema_fields_WEBSITE_ID, $websiteId)
            ->where(WarehouseStoreAuthorization::schema_fields_STORE_ID, $storeId)
            ->where(
                WarehouseStoreAuthorization::schema_fields_DEFAULT_GUARD,
                WarehouseStoreAuthorization::DEFAULT_GUARD,
            )
            ->where(WarehouseStoreAuthorization::schema_fields_ENABLED, 1)
            ->select()
            ->fetchArray();
        return is_array($rows) ? array_values($rows) : [];
    }

    /** @return list<array<string, mixed>> */
    private function websiteDefaults(int $websiteId, string $warehouseMode): array
    {
        $rows = $this->newWarehouse()->clear()
            ->where(Warehouse::schema_fields_WEBSITE_ID, $websiteId)
            ->where(Warehouse::schema_fields_MODE, $warehouseMode)
            ->where(Warehouse::schema_fields_IS_DEFAULT_LOGICAL, 1)
            ->where(Warehouse::schema_fields_ENABLED, 1)
            ->order(Warehouse::schema_fields_ID, 'ASC')
            ->select()
            ->fetchArray();
        if (!is_array($rows)) {
            return [];
        }
        return array_values(array_filter(
            $rows,
            fn (array $warehouse): bool => $this->isLogicalWarehouse($warehouse),
        ));
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

    /** @param array<string, mixed>|null $warehouse */
    private function assertWarehouse(
        ?array $warehouse,
        int $websiteId,
        string $warehouseMode,
        int $storeId,
    ): void {
        if ($warehouse === null
            || (int) ($warehouse[Warehouse::schema_fields_WEBSITE_ID] ?? -1) !== $websiteId
            || (int) ($warehouse[Warehouse::schema_fields_ENABLED] ?? 0) !== 1
            || !$this->isLogicalWarehouse($warehouse)
        ) {
            throw new InventoryConflictException(
                self::ERROR_MISSING,
                __('Store %{1}/%{2} 默认仓缺失', [$websiteId, $storeId]),
            );
        }
        if ((string) ($warehouse[Warehouse::schema_fields_MODE] ?? '') !== $warehouseMode) {
            throw new InventoryConflictException(
                WarehouseAuthorizationService::ERROR_MODE_MISMATCH,
                __('Store 环境与仓模式不兼容'),
            );
        }
    }

    private function warehouseModeForStore(string $storeMode): string
    {
        return match (strtolower(trim($storeMode))) {
            'normal' => Warehouse::MODE_NORMAL,
            'dev', 'test' => Warehouse::MODE_TEST,
            default => throw new InventoryConflictException(
                WarehouseAuthorizationService::ERROR_STORE_MODE_INVALID,
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

    private function storeKey(int $websiteId, int $storeId): string
    {
        return $websiteId . ':' . $storeId;
    }
}
