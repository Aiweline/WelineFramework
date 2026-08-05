<?php

declare(strict_types=1);

namespace Weline\Inventory\Service;

use Weline\Inventory\Model\Reservation;

/**
 * DEC-012 lease policy：
 * - 续租目标 expires = min(now+30m, lease_max_expires_at)
 * - max = attempt_started_at + 2h（不可延长）
 * - 排队单不允许续租
 * - 达上限 → reconciliation_required，禁止再续
 */
final class LeaseCoordinator
{
    public const RENEW_EXTEND = '+30 minutes';
    public const MAX_LEASE = '+2 hours';
    public const CHECK_INTERVAL = '+5 minutes';
    public const SQL_DATETIME = 'Y-m-d H:i:s';

    private readonly ClockInterface $clock;

    public function __construct(
        private readonly InventoryService $inventory,
        ?ClockInterface $clock = null,
    ) {
        $this->clock = $clock ?? new SystemClock();
    }

    /**
     * Build fields that are inserted atomically with a new reservation.
     *
     * @return array{
     *   lease_owner_attempt_code:string,
     *   lease_started_at:string,
     *   queued_order:int,
     *   lease_version:int,
     *   lease_expires_at:string,
     *   lease_max_expires_at:string
     * }
     */
    public function assignmentFields(
        string $attemptCode,
        ?\DateTimeImmutable $attemptStartedAt = null,
        bool $queuedOrder = false,
    ): array {
        $attemptCode = $this->normalizeAttemptCode($attemptCode);
        $now = $this->utc($this->clock->now());
        $started = $attemptStartedAt === null ? $now : $this->utc($attemptStartedAt);
        if ($started > $now) {
            throw new \InvalidArgumentException(__('attempt_started_at 不能晚于当前 UTC 时间'));
        }
        $max = $started->modify(self::MAX_LEASE);
        if ($now >= $max) {
            throw new InventoryConflictException(
                'inventory_lease_reconciliation_required',
                __('Attempt 已达 lease 硬上限，禁止新增预占并需 reconcile'),
                [
                    'lease_max_expires_at' => $max->format(self::SQL_DATETIME),
                    'reconciliation_required' => true,
                ],
            );
        }
        $expires = $queuedOrder
            ? $this->minDate($now->modify(self::CHECK_INTERVAL), $max)
            : $this->nextExpires($now, $max);

        return [
            'lease_owner_attempt_code' => $attemptCode,
            'lease_started_at' => $started->format(self::SQL_DATETIME),
            'queued_order' => $queuedOrder ? 1 : 0,
            'lease_version' => 1,
            'lease_expires_at' => $expires->format(self::SQL_DATETIME),
            'lease_max_expires_at' => $max->format(self::SQL_DATETIME),
        ];
    }

    /**
     * @return array{lease_owner_attempt_code:string,lease_version:int,lease_expires_at:string,lease_max_expires_at:string,queued_order:bool}
     */
    public function assignOwner(
        string $reservationUuid,
        string $attemptCode,
        ?\DateTimeImmutable $attemptStartedAt = null,
        bool $queuedOrder = false,
    ): array {
        $attemptCode = $this->normalizeAttemptCode($attemptCode);
        $row = $this->requireReserved($reservationUuid);
        if ((int)($row['lease_version'] ?? 0) > 0) {
            return $this->assertAssignmentReplay(
                $row,
                $attemptCode,
                $queuedOrder,
                $attemptStartedAt,
            );
        }

        $fields = $this->assignmentFields($attemptCode, $attemptStartedAt, $queuedOrder);
        $ok = $this->inventory->patchReservation(
            $reservationUuid,
            $fields,
            expectedLeaseVersion: (int)($row['lease_version'] ?? 0),
            expectedState: Reservation::STATE_RESERVED,
        );
        if (!$ok) {
            $reloaded = $this->requireReserved($reservationUuid);
            if ((int)($reloaded['lease_version'] ?? 0) > 0) {
                return $this->assertAssignmentReplay(
                    $reloaded,
                    $attemptCode,
                    $queuedOrder,
                    $attemptStartedAt,
                );
            }
            throw new InventoryConflictException(
                'inventory_lease_assign_conflict',
                __('Lease assign CAS 失败'),
                ['reservation_uuid' => $reservationUuid],
            );
        }
        return $this->snapshot($fields);
    }

    /**
     * @param array<string, mixed> $row
     * @return array{lease_owner_attempt_code:string,lease_version:int,lease_expires_at:string,lease_max_expires_at:string,queued_order:bool}
     */
    public function assertAssignmentReplay(
        array $row,
        string $attemptCode,
        bool $queuedOrder,
        ?\DateTimeImmutable $attemptStartedAt = null,
    ): array {
        $attemptCode = $this->normalizeAttemptCode($attemptCode);
        $owner = (string)($row['lease_owner_attempt_code'] ?? '');
        $queued = (bool)($row['queued_order'] ?? false);
        if ($owner !== $attemptCode || $queued !== $queuedOrder) {
            throw new InventoryConflictException(
                'inventory_lease_payload_conflict',
                __('Lease 重放 owner/queued 负载冲突'),
                ['reservation_uuid' => (string)($row['reservation_uuid'] ?? '')],
            );
        }
        $started = $this->parseUtc(
            (string)($row['lease_started_at'] ?? ''),
            'lease_started_at',
        );
        if ($attemptStartedAt !== null && $started != $this->utc($attemptStartedAt)) {
            throw new InventoryConflictException(
                'inventory_lease_payload_conflict',
                __('Lease 重放 attempt_started_at 冲突'),
                ['reservation_uuid' => (string)($row['reservation_uuid'] ?? '')],
            );
        }
        $max = $this->parseUtc(
            (string)($row['lease_max_expires_at'] ?? ''),
            'lease_max_expires_at',
        );
        $expires = $this->parseUtc(
            (string)($row['lease_expires_at'] ?? ''),
            'lease_expires_at',
        );
        if ($max != $started->modify(self::MAX_LEASE) || $expires > $max
            || (int)($row['lease_version'] ?? 0) < 1
        ) {
            throw new InventoryConflictException(
                'inventory_lease_invariant_violation',
                __('Lease 持久化时间或版本不满足硬上限不变量'),
                ['reservation_uuid' => (string)($row['reservation_uuid'] ?? '')],
            );
        }
        return $this->snapshot($row);
    }

    /**
     * @return array{lease_version:int,lease_expires_at:string,reconciliation_required:bool}
     */
    public function renew(string $reservationUuid, string $attemptCode, int $expectedVersion): array
    {
        $row = $this->requireReserved($reservationUuid);
        $attemptCode = $this->normalizeAttemptCode($attemptCode);
        $owner = (string)($row['lease_owner_attempt_code'] ?? '');
        if ($owner === '' || $owner !== $attemptCode) {
            throw new InventoryConflictException(
                'inventory_lease_owner_mismatch',
                __('仅 lease owner Attempt 可续租'),
                ['reservation_uuid' => $reservationUuid],
            );
        }
        if ((bool)($row['queued_order'] ?? false)) {
            throw new InventoryConflictException(
                'inventory_lease_queue_no_renew',
                __('排队 Order 不续租，须重新 availability/reserve'),
                ['reservation_uuid' => $reservationUuid],
            );
        }
        if ($expectedVersion < 1) {
            throw new \InvalidArgumentException(__('expected lease_version 必须 >= 1'));
        }
        if ((int)($row['lease_version'] ?? 0) !== $expectedVersion) {
            throw new InventoryConflictException(
                'inventory_lease_version_conflict',
                __('Lease version 冲突'),
                [
                    'reservation_uuid' => $reservationUuid,
                    'expected' => $expectedVersion,
                    'actual' => (int)($row['lease_version'] ?? 0),
                ],
            );
        }

        $now = $this->utc($this->clock->now());
        $max = $this->parseUtc(
            (string)($row['lease_max_expires_at'] ?? ''),
            'lease_max_expires_at',
        );
        if ($now >= $max) {
            throw new InventoryConflictException(
                'inventory_lease_reconciliation_required',
                __('已达 lease 硬上限，停止续租并需 reconcile'),
                [
                    'reservation_uuid' => $reservationUuid,
                    'lease_max_expires_at' => $row['lease_max_expires_at'],
                    'reconciliation_required' => true,
                ],
            );
        }

        $expiresAt = $this->parseUtc(
            (string)($row['lease_expires_at'] ?? ''),
            'lease_expires_at',
        );
        if ($expiresAt <= $now) {
            throw new InventoryConflictException(
                'inventory_lease_expired',
                __('Lease 已过期，禁止复活'),
                ['reservation_uuid' => $reservationUuid],
            );
        }

        $nextExpires = $this->nextExpires($now, $max);
        $nextVersion = $expectedVersion + 1;
        $ok = $this->inventory->patchReservation(
            $reservationUuid,
            [
                'lease_version' => $nextVersion,
                'lease_expires_at' => $nextExpires->format(self::SQL_DATETIME),
            ],
            expectedLeaseVersion: $expectedVersion,
            expectedState: Reservation::STATE_RESERVED,
        );
        if (!$ok) {
            throw new InventoryConflictException(
                'inventory_lease_version_conflict',
                __('Lease renew CAS 失败'),
                ['reservation_uuid' => $reservationUuid, 'expected' => $expectedVersion],
            );
        }

        return [
            'lease_version' => $nextVersion,
            'lease_expires_at' => $nextExpires->format(self::SQL_DATETIME),
            'reconciliation_required' => false,
        ];
    }

    public function markQueued(string $reservationUuid, bool $queued = true): void
    {
        $row = $this->requireReserved($reservationUuid);
        if ((bool)($row['queued_order'] ?? false) === $queued) {
            return;
        }
        $version = (int)($row['lease_version'] ?? 0);
        if (!$this->inventory->patchReservation(
            $reservationUuid,
            [
                'queued_order' => $queued ? 1 : 0,
                'lease_version' => $version + 1,
            ],
            expectedLeaseVersion: $version,
            expectedState: Reservation::STATE_RESERVED,
        )) {
            throw new InventoryConflictException(
                'inventory_lease_version_conflict',
                __('Lease queued 标记 CAS 失败'),
                ['reservation_uuid' => $reservationUuid, 'expected' => $version],
            );
        }
    }

    public function isQueued(string $reservationUuid): bool
    {
        $row = $this->inventory->getReservation($reservationUuid);
        return $row !== null && (bool)($row['queued_order'] ?? false);
    }

    private function nextExpires(\DateTimeImmutable $now, \DateTimeImmutable $max): \DateTimeImmutable
    {
        $candidate = $now->modify(self::RENEW_EXTEND);

        return $this->minDate($candidate, $max);
    }

    private function minDate(\DateTimeImmutable $a, \DateTimeImmutable $b): \DateTimeImmutable
    {
        return $a <= $b ? $a : $b;
    }

    private function normalizeAttemptCode(string $attemptCode): string
    {
        $attemptCode = trim($attemptCode);
        if ($attemptCode === '') {
            throw new \InvalidArgumentException(__('lease_owner_attempt_code 不能为空'));
        }
        if (strlen($attemptCode) > 64) {
            throw new \InvalidArgumentException(__('lease_owner_attempt_code 超出 64 字符限制'));
        }
        return $attemptCode;
    }

    private function utc(\DateTimeImmutable $date): \DateTimeImmutable
    {
        return $date->setTimezone(new \DateTimeZone('UTC'));
    }

    private function parseUtc(string $value, string $field): \DateTimeImmutable
    {
        $date = \DateTimeImmutable::createFromFormat(
            '!' . self::SQL_DATETIME,
            trim($value),
            new \DateTimeZone('UTC'),
        );
        $errors = \DateTimeImmutable::getLastErrors();
        if ($date === false || (is_array($errors)
            && ((int)$errors['warning_count'] > 0 || (int)$errors['error_count'] > 0)
        )) {
            throw new InventoryConflictException(
                'inventory_lease_invalid_timestamp',
                __('Lease 时间字段无效：%{1}', [$field]),
                ['field' => $field],
            );
        }
        return $date;
    }

    /**
     * @param array<string, mixed> $row
     * @return array{lease_owner_attempt_code:string,lease_version:int,lease_expires_at:string,lease_max_expires_at:string,queued_order:bool}
     */
    private function snapshot(array $row): array
    {
        return [
            'lease_owner_attempt_code' => (string)$row['lease_owner_attempt_code'],
            'lease_version' => (int)$row['lease_version'],
            'lease_expires_at' => (string)$row['lease_expires_at'],
            'lease_max_expires_at' => (string)$row['lease_max_expires_at'],
            'queued_order' => (bool)$row['queued_order'],
        ];
    }

    /** @return array<string, mixed> */
    private function requireReserved(string $reservationUuid): array
    {
        $row = $this->inventory->getReservation($reservationUuid);
        if ($row === null) {
            throw new \InvalidArgumentException(__('Reservation 不存在：%{1}', [$reservationUuid]));
        }
        if ((string)$row['state'] !== Reservation::STATE_RESERVED) {
            throw new InventoryConflictException(
                'inventory_lease_invalid_state',
                __('禁止对非 reserved 续租/复活：%{1}', [$row['state']]),
                ['reservation_uuid' => $reservationUuid, 'state' => $row['state']],
            );
        }

        return $row;
    }
}
