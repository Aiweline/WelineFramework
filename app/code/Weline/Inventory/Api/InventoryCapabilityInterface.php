<?php

declare(strict_types=1);

namespace Weline\Inventory\Api;

use Weline\Inventory\Api\Data\AvailabilityResult;
use Weline\Inventory\Api\Data\ReservationResult;

/**
 * Inventory capability SPI（Store 逻辑库存；不暴露内部 Model）.
 *
 * P3A：仓维能力以 additive 方式扩展（默认逻辑仓 / warehouse reservation），
 * 本接口 P2 方法保持 ABI；仓维入口见 `doc/warehouse.md`（mode off 直至 MIG-P3A）。
 */
interface InventoryCapabilityInterface
{
    public const STRATEGY_STRICT = 'strict';
    public const STRATEGY_OVERSELL = 'oversell';
    public const STRATEGY_PREORDER = 'preorder';
    public const STRATEGY_UNLIMITED = 'unlimited';

    public function getAvailability(int $websiteId, int $storeId, int $offerId): AvailabilityResult;

    /**
     * Basic reserve (P2B-001). Lease renew/commit/expire belong to P2B-002.
     *
     * @throws InventoryConflictException
     */
    public function reserve(
        int $websiteId,
        int $storeId,
        int $offerId,
        int $quantityMinor,
        string $idempotencyKey,
        string $requestHash,
    ): ReservationResult;

    public function release(string $reservationUuid): void;
}
