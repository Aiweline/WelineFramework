<?php

declare(strict_types=1);

namespace Weline\Order\Api;

/**
 * Optional public port for refunding committed non-cash allocations.
 *
 * P2 creates and retries the durable step. The owning asset module may provide
 * the implementation when asset tender is enabled in a later phase.
 */
interface RefundAssetReturnCapabilityInterface
{
    /**
     * @param list<array<string, mixed>> $allocations
     * @return array<string, mixed>
     */
    public function returnCommittedAllocations(
        string $refundCaseUuid,
        array $allocations,
        string $effectKey,
    ): array;
}
