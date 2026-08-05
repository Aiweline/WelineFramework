<?php

declare(strict_types=1);

namespace Weline\Inventory\Api;

/**
 * Idempotent stock-return port for unshipped refunded quantities.
 */
interface InventoryRefundCapabilityInterface
{
    public function returnCommitted(
        int $websiteId,
        int $storeId,
        int $offerId,
        int $quantityMinor,
        string $idempotencyKey,
        string $requestHash,
    ): void;
}
