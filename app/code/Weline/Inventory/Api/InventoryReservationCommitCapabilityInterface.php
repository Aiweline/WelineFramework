<?php

declare(strict_types=1);

namespace Weline\Inventory\Api;

/**
 * Published reservation-commit port for same-connector commerce transactions.
 *
 * Callers own the outer business transaction. Implementations must join that
 * transaction when the configured connector is the same.
 */
interface InventoryReservationCommitCapabilityInterface
{
    /**
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    public function transactional(callable $callback): mixed;

    public function commit(
        string $reservationUuid,
        string $idempotencyKey,
        string $requestHash,
    ): void;
}
