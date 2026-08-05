<?php

declare(strict_types=1);

namespace Weline\Inventory\Api\Data;

/** Result of a reserve attempt (P2B-001 basic; lease fields filled in P2B-002). */
final class ReservationResult
{
    public function __construct(
        public readonly string $reservationUuid,
        public readonly string $state,
        public readonly int $quantityMinor,
        public readonly string $idempotencyKey,
        public readonly string $requestHash,
        public readonly bool $replayed = false,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'reservation_uuid' => $this->reservationUuid,
            'state' => $this->state,
            'quantity_minor' => $this->quantityMinor,
            'idempotency_key' => $this->idempotencyKey,
            'request_hash' => $this->requestHash,
            'replayed' => $this->replayed,
        ];
    }
}
