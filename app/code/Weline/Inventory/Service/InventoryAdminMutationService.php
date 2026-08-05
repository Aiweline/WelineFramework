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
            Warehouse::schema_fields_WAREHOUSE_CODE => $code,
            Warehouse::schema_fields_NAME => $name,
            Warehouse::schema_fields_MODE => $mode,
            Warehouse::schema_fields_WAREHOUSE_TYPE => $type,
            Warehouse::schema_fields_IS_DEFAULT_LOGICAL => 0,
            Warehouse::schema_fields_ENABLED => 1,
        ]);
    }

    /** @return array<string,mixed> */
    public function authorizeWarehouse(
        int $websiteId,
        int $storeId,
        int $warehouseId,
        bool $isDefault,
    ): array {
        $this->assertWebsiteId($websiteId);
        if ($storeId <= 0 || $warehouseId <= 0) {
            throw new \InvalidArgumentException(__('store_id 与 warehouse_id 必须是正整数'));
        }

        return $this->authorizations->bind([
            'website_id' => $websiteId,
            'store_id' => $storeId,
            'warehouse_id' => $warehouseId,
            'is_default' => $isDefault,
        ]);
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
        if ($storeId <= 0 || $offerId <= 0 || $onHandMinor < 0) {
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
