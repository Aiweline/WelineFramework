<?php

declare(strict_types=1);

namespace Weline\B2B\Test\Unit\Service;

use Weline\CustomerAsset\Api\CustomerAssetFacadeInterface;

final class FakeCustomerAssetFacade implements CustomerAssetFacadeInterface
{
    public int $balance = 0;
    /** @var list<string> */
    public array $events = [];
    /** @var array<string, array<string,mixed>> */
    public array $reservations = [];

    public function credit(array $request): array
    {
        $eventId = (string)($request['event_id'] ?? '');
        if (in_array($eventId, $this->events, true)) {
            return ['ok' => true, 'idempotent' => true];
        }
        $this->events[] = $eventId;
        $this->balance += (int)($request['amount_minor'] ?? 0);

        return ['ok' => true];
    }

    public function reserve(array $request): array
    {
        $id = 'res_' . count($this->reservations);
        $this->reservations[$id] = $request;
        $this->balance -= (int)($request['amount_minor'] ?? 0);

        return ['reservation' => ['reservation_id' => $id]];
    }

    public function release(string $reservationId, string $eventId): array
    {
        if (isset($this->reservations[$reservationId])) {
            $this->balance += (int)($this->reservations[$reservationId]['amount_minor'] ?? 0);
            unset($this->reservations[$reservationId]);
        }

        return ['ok' => true];
    }

    public function commit(string $reservationId, string $eventId): array
    {
        unset($this->reservations[$reservationId]);

        return ['ok' => true];
    }

    public function returnCommitted(string $reservationId, int $amountMinor, string $eventId): array
    {
        $this->balance += max(0, $amountMinor);

        return ['ok' => true];
    }

    public function getBalance(
        string|int $customerId,
        int $websiteId,
        string $assetCode,
        string $namespace = 'live',
    ): array {
        return [
            'available_minor' => $this->balance,
            'reservable_minor' => $this->balance,
            'reserved_minor' => 0,
        ];
    }

    public function listAccounts(
        string|int $customerId,
        int $websiteId,
        string $namespace = 'live',
        int $limit = 100,
    ): array {
        return [];
    }

    public function listLedger(
        string|int $customerId,
        int $websiteId,
        string $assetCode,
        string $namespace = 'live',
        int $limit = 100,
    ): array {
        return [];
    }

    public function getReservation(string $reservationId): array
    {
        return $this->reservations[$reservationId] ?? [];
    }
}
