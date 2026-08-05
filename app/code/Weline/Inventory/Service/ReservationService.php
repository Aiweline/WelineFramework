<?php

declare(strict_types=1);

namespace Weline\Inventory\Service;

use Weline\Inventory\Api\Data\ReservationResult;
use Weline\Inventory\Model\Reservation;

/**
 * Orchestrates reserve → lease assign → renew / commit / release / expire (MOD-P2B-002).
 */
final class ReservationService
{
    public function __construct(
        private readonly InventoryService $inventory,
        private readonly LeaseCoordinator $lease,
    ) {
    }

    public static function forTesting(?ClockInterface $clock = null): self
    {
        $inventory = InventoryService::forTesting();
        $lease = new LeaseCoordinator($inventory, $clock ?? new SystemClock());

        return new self($inventory, $lease);
    }

    public function inventory(): InventoryService
    {
        return $this->inventory;
    }

    public function lease(): LeaseCoordinator
    {
        return $this->lease;
    }

    /**
     * Reserve + assign lease owner（DEC-012）.
     *
     * @return array{reservation:ReservationResult,lease:array<string,mixed>}
     */
    public function reserve(
        int $websiteId,
        int $storeId,
        int $offerId,
        int $quantityMinor,
        string $idempotencyKey,
        string $requestHash,
        string $attemptCode,
        bool $queuedOrder = false,
        ?\DateTimeImmutable $attemptStartedAt = null,
    ): array {
        $existingBefore = $this->inventory->getReservationByIdempotencyKey(
            trim($idempotencyKey),
        );
        $initialLease = $existingBefore === null
            ? $this->lease->assignmentFields($attemptCode, $attemptStartedAt, $queuedOrder)
            : [];
        $reservation = $this->inventory->reserve(
            $websiteId,
            $storeId,
            $offerId,
            $quantityMinor,
            $idempotencyKey,
            $requestHash,
            $initialLease,
        );

        $row = $this->inventory->getReservation($reservation->reservationUuid);
        if ($row === null || (string)$row['state'] !== Reservation::STATE_RESERVED) {
            throw new InventoryConflictException(
                'inventory_lease_invalid_state',
                __('预占完成后缺少可分配 lease 的 reserved 行'),
                ['reservation_uuid' => $reservation->reservationUuid],
            );
        }
        if ((int)($row['lease_version'] ?? 0) === 0) {
            $lease = $this->lease->assignOwner(
                $reservation->reservationUuid,
                $attemptCode,
                $attemptStartedAt,
                $queuedOrder,
            );
        } else {
            $lease = $this->lease->assertAssignmentReplay(
                $row,
                $attemptCode,
                $queuedOrder,
                $attemptStartedAt,
            );
        }

        return [
            'reservation' => $reservation,
            'lease' => $lease,
        ];
    }

    /** @return array{lease_version:int,lease_expires_at:string,reconciliation_required:bool} */
    public function renew(string $reservationUuid, string $attemptCode, int $expectedVersion): array
    {
        return $this->lease->renew($reservationUuid, $attemptCode, $expectedVersion);
    }

    public function commit(string $reservationUuid, string $idempotencyKey, string $requestHash): void
    {
        $this->inventory->commit($reservationUuid, $idempotencyKey, $requestHash);
    }

    public function release(string $reservationUuid): void
    {
        $this->inventory->release($reservationUuid);
    }

    public function expire(string $reservationUuid): void
    {
        $this->inventory->expire($reservationUuid);
    }
}
