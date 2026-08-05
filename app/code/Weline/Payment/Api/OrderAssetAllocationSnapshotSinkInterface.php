<?php

declare(strict_types=1);

namespace Weline\Payment\Api;

/**
 * Optional downstream port implemented by Order.
 *
 * Payment invokes this inside the asset-commit outbox transaction. Absence is
 * valid for non-Order payables; a configured implementation must fail closed.
 */
interface OrderAssetAllocationSnapshotSinkInterface
{
    /**
     * @param list<array<string, mixed>> $allocations
     * @return array<string, mixed>
     */
    public function recordCommittedAllocations(
        string $payableType,
        string $payableId,
        string $intentCode,
        ?string $attemptCode,
        array $allocations,
        string $effectKey,
    ): array;
}
