<?php

declare(strict_types=1);

namespace Weline\Inventory\Api;

use Weline\Inventory\Api\Data\WarehouseAssignment;

/**
 * Published Store -> default logical Warehouse reader.
 */
interface DefaultWarehouseResolverInterface
{
    /**
     * Additive Warehouse data is absent before MIG-P3A. Consumers may use
     * this stable code to preserve the legacy path while the writer is off.
     */
    public const ERROR_MISSING = 'inventory_default_logical_warehouse_missing';

    /**
     * Production derives Store environment from the trusted Store catalog.
     *
     * @throws InventoryConflictException
     */
    public function resolveDefault(int $websiteId, int $storeId): WarehouseAssignment;
}
