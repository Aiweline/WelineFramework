<?php

declare(strict_types=1);

namespace Weline\CustomerAsset\Service;

use Weline\CustomerAsset\Model\AssetReservation;

final class AssetReservationStore
{
    /** @var array<string, AssetReservation> */
    private array $rows = [];

    /** @var array<string, string> event_id => reservation_id */
    private array $byEvent = [];

    public static function forTesting(): self
    {
        return new self();
    }

    public function put(AssetReservation $reservation): void
    {
        $this->rows[$reservation->reservationId] = $reservation;
        $this->byEvent[$reservation->eventId] = $reservation->reservationId;
    }

    public function get(string $reservationId): ?AssetReservation
    {
        return $this->rows[$reservationId] ?? null;
    }

    public function getByEvent(string $eventId): ?AssetReservation
    {
        $id = $this->byEvent[$eventId] ?? null;

        return $id !== null ? ($this->rows[$id] ?? null) : null;
    }

    /**
     * @return list<AssetReservation>
     */
    public function openForAccount(string $accountId): array
    {
        $out = [];
        foreach ($this->rows as $row) {
            if ($row->accountId === $accountId && $row->status === AssetReservation::STATUS_RESERVED) {
                $out[] = $row;
            }
        }

        return $out;
    }

    public function sumReserved(string $accountId): int
    {
        $sum = 0;
        foreach ($this->openForAccount($accountId) as $row) {
            $sum += $row->amountMinor;
        }

        return $sum;
    }
}
