<?php

declare(strict_types=1);

namespace Weline\Inventory\Api;

use Weline\Inventory\Api\Data\AvailabilityResult;

/**
 * Published inventory port used by catalog-copy orchestration.
 *
 * The callback boundary lets the catalog transaction and inventory mutations
 * fail as one unit. Implementations must restore their own state when the
 * callback throws.
 */
interface InventoryCatalogCopyCapabilityInterface
{
    /**
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    public function transactional(callable $callback): mixed;

    public function getAvailability(int $websiteId, int $storeId, int $offerId): AvailabilityResult;

    public function ensureStock(
        int $websiteId,
        int $storeId,
        int $offerId,
        string $strategy = InventoryCapabilityInterface::STRATEGY_STRICT,
        int $onHandMinor = 0,
        int $oversellAllowance = 0,
        int $preorderAllowance = 0,
    ): void;

    public function setOnHand(
        int $websiteId,
        int $storeId,
        int $offerId,
        int $onHandMinor,
        string $idempotencyKey,
        string $requestHash,
        ?string $strategy = null,
        ?int $oversellAllowance = null,
        ?int $preorderAllowance = null,
    ): AvailabilityResult;
}
