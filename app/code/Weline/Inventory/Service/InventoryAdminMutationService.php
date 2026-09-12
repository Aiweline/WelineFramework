<?php

declare(strict_types=1);

namespace Weline\Inventory\Service;

use Weline\Inventory\Api\Data\AvailabilityResult;
use Weline\Inventory\Model\Warehouse;

final class InventoryAdminMutationService
{
    public function __construct(
        private readonly WarehouseAuthorizationService $authorizations,
        private readonly InventoryService $inventory,
        private readonly WarehouseHierarchyService $hierarchy,
        private readonly WarehouseCodeLabelAdminService $codeLabels,
    ) {
    }

    /** @return array<string,mixed> */
    public function createWarehouse(
        int $websiteId,
        string $code,
        string $name,
        string $mode,
        string $type,
    ): array {
        $this->assertWebsiteId($websiteId);
        $code = trim($code);
        $name = trim($name);
        $mode = strtolower(trim($mode));
        $type = strtolower(trim($type));
        if ($code === '' || strlen($code) > 64 || $name === '' || strlen($name) > 128) {
            throw new \InvalidArgumentException(__('仓代码/名称不能为空且不得超过字段长度'));
        }
        if (!in_array($mode, Warehouse::MODES, true) || !in_array($type, Warehouse::TYPES, true)) {
            throw new \InvalidArgumentException(__('仓模式或类型无效'));
        }

        return $this->authorizations->createWarehouse([
            Warehouse::schema_fields_WEBSITE_ID => $websiteId,
            Warehouse::schema_fields_PARENT_ID => 0,
            Warehouse::schema_fields_NODE_KIND => Warehouse::NODE_WAREHOUSE,
            Warehouse::schema_fields_WAREHOUSE_CODE => $code,
            Warehouse::schema_fields_NAME => $name,
            Warehouse::schema_fields_MODE => $mode,
            Warehouse::schema_fields_WAREHOUSE_TYPE => $type,
            Warehouse::schema_fields_IS_DEFAULT_LOGICAL => 0,
            Warehouse::schema_fields_ENABLED => 1,
        ]);
    }

    /** @return array<string,mixed> */
    public function createChildWarehouse(
        int $websiteId,
        int $parentId,
        string $nodeKind,
        string $segment,
        string $name,
        string $mode,
        string $type,
        ?string $countryCode = null,
        ?string $regionCode = null,
    ): array {
        $this->assertWebsiteId($websiteId);
        $name = trim($name);
        if ($name === '' || strlen($name) > 128) {
            throw new \InvalidArgumentException(__('仓名称不能为空且不得超过字段长度'));
        }
        $mode = strtolower(trim($mode));
        $type = strtolower(trim($type));
        if (!in_array($mode, Warehouse::MODES, true) || !in_array($type, Warehouse::TYPES, true)) {
            throw new \InvalidArgumentException(__('仓模式或类型无效'));
        }

        return $this->hierarchy->createChild(
            $websiteId,
            $parentId,
            $nodeKind,
            $segment,
            $name,
            $mode,
            $type,
            $countryCode,
            $regionCode,
        );
    }

    /** @return array{created:int,skipped:int} */
    public function seedDefaultWarehouses(int $websiteId, string $mode = Warehouse::MODE_NORMAL): array
    {
        $this->assertWebsiteId($websiteId);
        $mode = strtolower(trim($mode));
        if (!in_array($mode, Warehouse::MODES, true)) {
            throw new \InvalidArgumentException(__('仓模式无效'));
        }

        return $this->hierarchy->ensureDefaultTree($websiteId, $mode);
    }

    public function deleteWarehouse(int $websiteId, int $warehouseId): void
    {
        $this->assertWebsiteId($websiteId);
        $this->hierarchy->deleteWarehouse($websiteId, $warehouseId);
    }

    /** @return array<string,mixed> */
    public function saveWarehouseCodeLabel(
        string $group,
        string $code,
        string $labelName,
        ?int $sortOrder = null,
    ): array {
        return $this->codeLabels->save($group, $code, $labelName, $sortOrder, true);
    }

    public function deleteWarehouseCodeLabel(int $labelId): void
    {
        $this->codeLabels->delete($labelId);
    }

    public function seedWarehouseCodeLabels(): int
    {
        return $this->codeLabels->seedDefaults();
    }

    /** @return array<string,mixed> */
    public function authorizeWarehouse(
        int $websiteId,
        int $storeId,
        int $warehouseId,
        bool $isDefault,
    ): array {
        $this->assertWebsiteId($websiteId);
        if ($storeId < 0 || $warehouseId <= 0) {
            throw new \InvalidArgumentException(__('store_id 不能为负数，warehouse_id 必须是正整数'));
        }

        return $this->authorizations->bind([
            'website_id' => $websiteId,
            'store_id' => $storeId,
            'warehouse_id' => $warehouseId,
            'is_default' => $isDefault,
        ]);
    }

    /** @return array{mounted:int,default_authorization_id:int} */
    public function ensureDefaultStoreWarehouseMount(): array
    {
        return $this->authorizations->ensureDefaultStoreWarehouseMount();
    }

    /** @return array<string,mixed> */
    public function ensureDefaultSiteAuthorization(): array
    {
        return $this->authorizations->ensureDefaultSiteAuthorization();
    }

    public function deleteAuthorization(int $websiteId, int $authorizationId): void
    {
        $this->assertWebsiteId($websiteId);
        if ($authorizationId <= 0) {
            throw new \InvalidArgumentException(__('authorization_id 必须是正整数'));
        }
        $this->authorizations->deleteAuthorization($websiteId, $authorizationId);
    }

    public function setOnHand(
        int $websiteId,
        int $storeId,
        int $offerId,
        int $onHandMinor,
        string $commandId,
        string $strategy = InventoryService::STRATEGY_STRICT,
    ): AvailabilityResult {
        $this->assertWebsiteId($websiteId);
        $commandId = trim($commandId);
        if ($storeId < 0 || $offerId <= 0 || $onHandMinor < 0) {
            throw new \InvalidArgumentException(__('库存调整 Scope 或数量无效'));
        }
        if ($commandId === '' || strlen($commandId) > 96 || !preg_match('/^[a-z0-9:_-]+$/i', $commandId)) {
            throw new \InvalidArgumentException(__('command_id 格式无效'));
        }
        $requestHash = hash('sha256', json_encode([
            'website_id' => $websiteId,
            'store_id' => $storeId,
            'offer_id' => $offerId,
            'on_hand_minor' => $onHandMinor,
            'strategy' => $strategy,
        ], JSON_THROW_ON_ERROR));

        return $this->inventory->setOnHand(
            $websiteId,
            $storeId,
            $offerId,
            $onHandMinor,
            $commandId,
            $requestHash,
            $strategy,
        );
    }

    private function assertWebsiteId(int $websiteId): void
    {
        if ($websiteId < 0) {
            throw new \InvalidArgumentException(__('website_id 不能为负'));
        }
    }
}
