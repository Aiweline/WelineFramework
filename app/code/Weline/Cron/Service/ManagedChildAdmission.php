<?php

declare(strict_types=1);

namespace Weline\Cron\Service;

use Weline\Cron\Helper\CronStatus;
use Weline\Cron\Helper\Process;
use Weline\Cron\Model\CronTask;
use Weline\Framework\Manager\ObjectManager;
use Weline\SystemConfig\Api\ConfigReader;
use Weline\SystemConfig\Model\SystemConfig;

/**
 * Soft concurrent-child admission for non-SQLite Cron dispatchers.
 *
 * Counts live managed children (verified PID) plus READY windows (BLOCK + pid=0
 * + launch_id) so claim-before-slot races do not overshoot the effective max.
 *
 * Effective max = min(configured, host capacity cap) when host-capacity admission
 * is enabled; also defers when 1-minute loadavg >= cpu cores.
 */
final class ManagedChildAdmission
{
    public const CONFIG_KEY = 'cron/max_concurrent_managed_children';
    public const CONFIG_HOST_CAPACITY_ADMISSION = 'cron/host_capacity_admission';
    public const CONFIG_HOST_CPU_RESERVE = 'cron/host_cpu_reserve';
    public const MODULE = 'Weline_Cron';
    public const DEFAULT_MAX = 3;
    public const MIN_MAX = 1;
    public const MAX_MAX = 16;
    public const DEFAULT_HOST_CPU_RESERVE = 1;

    public function __construct(
        private readonly ?SystemConfig $systemConfig = null,
        private readonly ?HostCapacityProbe $hostCapacityProbe = null,
    ) {
    }

    public function maxConcurrent(): int
    {
        $config = $this->systemConfig ?? ObjectManager::getInstance(SystemConfig::class);
        $raw = $config->getConfig(
            self::CONFIG_KEY,
            self::MODULE,
            ConfigReader::area_BACKEND,
            self::DEFAULT_MAX,
        );

        return self::normalizeMax((int)$raw);
    }

    /**
     * Configured soft max after host-capacity ceiling (when enabled).
     * Run.php defer notes and post-claim TOCTOU checks must use this.
     */
    public function effectiveMax(): int
    {
        $configured = $this->maxConcurrent();
        if (!$this->isHostCapacityAdmissionEnabled()) {
            return $configured;
        }

        return \min($configured, $this->hostCapacityMax());
    }

    public function isHostCapacityAdmissionEnabled(): bool
    {
        $config = $this->systemConfig ?? ObjectManager::getInstance(SystemConfig::class);
        $raw = $config->getConfig(
            self::CONFIG_HOST_CAPACITY_ADMISSION,
            self::MODULE,
            ConfigReader::area_BACKEND,
            '1',
        );

        return self::parseTruthy($raw, true);
    }

    public function hostCpuReserve(): int
    {
        $config = $this->systemConfig ?? ObjectManager::getInstance(SystemConfig::class);
        $raw = $config->getConfig(
            self::CONFIG_HOST_CPU_RESERVE,
            self::MODULE,
            ConfigReader::area_BACKEND,
            self::DEFAULT_HOST_CPU_RESERVE,
        );
        $reserve = (int)$raw;
        if ($reserve < 0) {
            return self::DEFAULT_HOST_CPU_RESERVE;
        }

        return $reserve;
    }

    /**
     * max(1, cpuCores - reserve), clamped so reserve cannot exceed cores-1.
     */
    public function hostCapacityMax(): int
    {
        $cores = $this->probe()->cpuCores();
        $reserve = \min($this->hostCpuReserve(), \max(0, $cores - 1));

        return \max(1, $cores - $reserve);
    }

    /**
     * True when loadavg_1 is available and already at/above cpu core count.
     */
    public function isLoadSaturated(): bool
    {
        if (!$this->isHostCapacityAdmissionEnabled()) {
            return false;
        }
        $load = $this->probe()->loadAvg1();
        if ($load === null) {
            return false;
        }

        return $load >= (float)$this->probe()->cpuCores();
    }

    /** Clamp configured concurrency; invalid/zero falls back to DEFAULT_MAX. */
    public static function normalizeMax(int $raw): int
    {
        if ($raw < self::MIN_MAX) {
            return self::DEFAULT_MAX;
        }

        return min(self::MAX_MAX, max(self::MIN_MAX, $raw));
    }

    /**
     * @param mixed $raw
     */
    public static function parseTruthy(mixed $raw, bool $default): bool
    {
        if ($raw === null || $raw === '') {
            return $default;
        }
        if (\is_bool($raw)) {
            return $raw;
        }
        if (\is_int($raw) || \is_float($raw)) {
            return ((int)$raw) !== 0;
        }
        $s = \strtolower(\trim((string)$raw));
        if ($s === '') {
            return $default;
        }
        if (\in_array($s, ['0', 'false', 'off', 'no', 'n'], true)) {
            return false;
        }
        if (\in_array($s, ['1', 'true', 'on', 'yes', 'y'], true)) {
            return true;
        }

        return $default;
    }

    /**
     * @return int Live managed children + READY reserved slots
     */
    public function countLiveManagedChildren(?CronTask $taskModel = null): int
    {
        // Always use a fresh query model — do not mutate the caller's CronTask.
        unset($taskModel);
        /** @var CronTask $model */
        $model = ObjectManager::getInstance(CronTask::class)->reset();

        $rows = $model
            ->where(
                CronTask::schema_fields_STATUS,
                [CronStatus::RUNNING->value, CronStatus::BLOCK->value],
                'IN',
            )
            ->select()
            ->fetchArray();

        $count = 0;
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $status = (string)($row[CronTask::schema_fields_STATUS] ?? '');
            $pid = (int)($row[CronTask::schema_fields_PID] ?? 0);
            $launchId = strtolower(trim((string)($row[CronTask::schema_fields_LAUNCH_ID] ?? '')));
            $runStart = trim((string)($row[CronTask::schema_fields_RUN_TIME] ?? ''));
            $executeName = (string)($row[CronTask::schema_fields_EXECUTE_NAME] ?? '');
            if ($executeName === '') {
                continue;
            }

            if ($pid > 0) {
                if ($launchId === '' || $runStart === '') {
                    // Fail-closed: unverifiable generation still occupies a slot.
                    $count++;
                    continue;
                }
                $probe = Process::probeManagedCronProcess(
                    $pid,
                    $executeName,
                    $launchId,
                    $runStart,
                );
                if (!empty($probe['running']) || !empty($probe['unknown'])) {
                    $count++;
                }
                continue;
            }

            // READY window: BLOCK + pid=0 + valid launch_id already claimed a slot.
            if (
                $status === CronStatus::BLOCK->value
                && preg_match('/^[0-9a-f]{32}$/D', $launchId) === 1
            ) {
                $count++;
            }
        }

        return $count;
    }

    public function hasAvailableSlot(?CronTask $taskModel = null): bool
    {
        if ($this->isLoadSaturated()) {
            return false;
        }

        return $this->countLiveManagedChildren($taskModel) < $this->effectiveMax();
    }

    private function probe(): HostCapacityProbe
    {
        return $this->hostCapacityProbe ?? ObjectManager::getInstance(HostCapacityProbe::class);
    }
}
