<?php

declare(strict_types=1);

namespace Weline\Inventory\Cron;

use Weline\Framework\Cron\CronTaskInterface;
use Weline\Inventory\Service\ClockInterface;
use Weline\Inventory\Service\InventoryService;
use Weline\Inventory\Service\SystemClock;

/**
 * Expire reservations whose lease_expires_at has passed (DEC-012 / TEST-P2B-05).
 */
class ReservationExpiry implements CronTaskInterface
{
    public const BATCH_LIMIT = 500;

    private ClockInterface $clock;

    public function __construct(
        private readonly InventoryService $inventory,
    ) {
        $this->clock = new SystemClock();
    }

    /** @internal tests */
    public function setClock(ClockInterface $clock): void
    {
        $this->clock = $clock;
    }

    public function name(): string
    {
        return '库存预占租约过期';
    }

    public function execute_name(): string
    {
        return 'inventory_reservation_expiry';
    }

    public function tip(): string
    {
        return '扫描 lease_expires_at 已过期的 reserved 预占并 expire 释库；每 5 分钟。';
    }

    public function cron_time(): string
    {
        return '*/5 * * * *';
    }

    public function execute(): string
    {
        $now = $this->clock->now()->format('Y-m-d H:i:s');
        $rows = $this->inventory->listExpiredReservations($now, self::BATCH_LIMIT);
        $expired = 0;
        $skipped = 0;
        $errors = 0;
        foreach ($rows as $row) {
            $uuid = (string)($row['reservation_uuid'] ?? '');
            if ($uuid === '') {
                continue;
            }
            try {
                $changed = $this->inventory->expire(
                    $uuid,
                    (int)($row['lease_version'] ?? 0),
                    $now,
                );
                if ($changed) {
                    $expired++;
                } else {
                    $skipped++;
                }
            } catch (\Throwable) {
                $errors++;
            }
        }

        return sprintf(
            'expired=%d;skipped=%d;errors=%d;scanned=%d',
            $expired,
            $skipped,
            $errors,
            count($rows),
        );
    }

    public function unlock_timeout(int $minute = 30): int
    {
        return $minute;
    }
}
