<?php

declare(strict_types=1);

namespace Weline\Shipping\Api;

/**
 * 权威：Inventory warehouse_id → ShippingAddress（多仓拆单发货锚点）。
 */
interface WarehouseShippingOriginInterface
{
    public const ERROR_MISSING = 'shipping_warehouse_origin_missing';
    public const ERROR_INVALID = 'shipping_warehouse_origin_invalid';

    /** Resolve active shipping_address_id or throw ERROR_MISSING. */
    public function requireShippingAddressId(int $websiteId, int $warehouseId): int;

    public function findShippingAddressId(int $websiteId, int $warehouseId): ?int;

    /**
     * Upsert binding. Dropship and admin must write through this port.
     *
     * @return array{origin_id:int,website_id:int,warehouse_id:int,shipping_address_id:int,is_active:int}
     */
    public function bind(int $websiteId, int $warehouseId, int $shippingAddressId, bool $active = true): array;
}
