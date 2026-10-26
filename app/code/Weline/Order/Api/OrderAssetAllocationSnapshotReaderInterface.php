<?php

declare(strict_types=1);

namespace Weline\Order\Api;

/**
 * Read-only public boundary for committed asset allocations frozen on an order.
 */
interface OrderAssetAllocationSnapshotReaderInterface
{
    /** @return list<array<string, mixed>> */
    public function listCommittedAllocationsForOrder(string $orderUuid): array;
}
