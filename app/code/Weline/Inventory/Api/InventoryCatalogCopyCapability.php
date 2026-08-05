<?php

declare(strict_types=1);

namespace Weline\Inventory\Api;

use Weline\Inventory\Api\Data\AvailabilityResult;
use Weline\Inventory\Service\InventoryService;

/**
 * Public facade that keeps callers outside Inventory service/model internals.
 */
final class InventoryCatalogCopyCapability implements InventoryCatalogCopyCapabilityInterface
{
    public function __construct(
        private readonly InventoryService $inventory,
    ) {
    }

    public static function forTesting(?InventoryService $inventory = null): self
    {
        return new self($inventory ?? InventoryService::forTesting());
    }

    public function transactional(callable $callback): mixed
    {
        return $this->inventory->transactional($callback);
    }

    public function getAvailability(int $websiteId, int $storeId, int $offerId): AvailabilityResult
    {
        return $this->inventory->getAvailability($websiteId, $storeId, $offerId);
    }

    public function ensureStock(
        int $websiteId,
        int $storeId,
        int $offerId,
        string $strategy = InventoryCapabilityInterface::STRATEGY_STRICT,
        int $onHandMinor = 0,
        int $oversellAllowance = 0,
        int $preorderAllowance = 0,
    ): void {
        $this->inventory->ensureStock(
            $websiteId,
            $storeId,
            $offerId,
            $strategy,
            $onHandMinor,
            $oversellAllowance,
            $preorderAllowance,
        );
    }

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
    ): AvailabilityResult {
        return $this->inventory->setOnHand(
            $websiteId,
            $storeId,
            $offerId,
            $onHandMinor,
            $idempotencyKey,
            $requestHash,
            $strategy,
            $oversellAllowance,
            $preorderAllowance,
        );
    }
}
