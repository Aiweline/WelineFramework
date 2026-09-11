<?php

declare(strict_types=1);

namespace Weline\Dropship\Service;

use Weline\Dropship\Model\DropshipScopeWarehouseMap;
use Weline\Framework\Manager\ObjectManager;

class DropshipWarehouseMapService
{
    public const ERROR_MISSING = 'dropship_scope_warehouse_map_missing';

    /**
     * @return array<string, mixed>
     */
    public function resolve(string $providerCode, int $websiteId, int $storeId): array
    {
        /** @var DropshipScopeWarehouseMap $model */
        $model = ObjectManager::getInstance(DropshipScopeWarehouseMap::class);
        $row = $model->clear()
            ->where(DropshipScopeWarehouseMap::schema_fields_PROVIDER_CODE, $providerCode)
            ->where(DropshipScopeWarehouseMap::schema_fields_WEBSITE_ID, $websiteId)
            ->where(DropshipScopeWarehouseMap::schema_fields_STORE_ID, $storeId)
            ->where(DropshipScopeWarehouseMap::schema_fields_ENABLED, 1)
            ->find()
            ->fetch();

        if (!$row || !$row->getId()) {
            throw new \RuntimeException(self::ERROR_MISSING);
        }

        return $row->getData();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listAll(): array
    {
        /** @var DropshipScopeWarehouseMap $model */
        $model = ObjectManager::getInstance(DropshipScopeWarehouseMap::class);
        $rows = $model->clear()->select()->fetchArray();

        return is_array($rows) ? $rows : [];
    }
}
