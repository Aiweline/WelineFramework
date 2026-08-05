<?php

declare(strict_types=1);

namespace Weline\Inventory\Api;

use Weline\Inventory\Api\Data\ReservationResult;

/**
 * Versioned Warehouse inventory writer port.
 *
 * Callers must enable this writer only after their rollout gate allows it.
 * Existing historical Warehouse facts may keep using the port while new
 * selection remains off.
 */
interface WarehouseInventoryCapabilityInterface
{
    /**
     * Idempotently bind an existing P2 Reservation to an authorized Warehouse.
     *
     * @throws InventoryConflictException
     */
    public function assignReservationWarehouse(
        string $reservationUuid,
        int $websiteId,
        int $storeId,
        int $warehouseId,
        string $idempotencyKey,
        string $requestHash,
    ): ReservationResult;

    /**
     * Return committed quantity to the exact Warehouse/Offer quota.
     *
     * @throws InventoryConflictException
     */
    public function returnCommittedToWarehouse(
        int $websiteId,
        int $storeId,
        int $warehouseId,
        int $offerId,
        int $quantityMinor,
        string $idempotencyKey,
        string $requestHash,
    ): void;
}
