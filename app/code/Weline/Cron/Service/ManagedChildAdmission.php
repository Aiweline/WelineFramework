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
 * + launch_id) so claim-before-slot races do not overshoot the configured max.
 */
final class ManagedChildAdmission
{
    public const CONFIG_KEY = 'cron/max_concurrent_managed_children';
    public const MODULE = 'Weline_Cron';
    public const DEFAULT_MAX = 3;
    public const MIN_MAX = 1;
    public const MAX_MAX = 16;

    public function __construct(
        private readonly ?SystemConfig $systemConfig = null,
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

    /** Clamp configured concurrency; invalid/zero falls back to DEFAULT_MAX. */
    public static function normalizeMax(int $raw): int
    {
        if ($raw < self::MIN_MAX) {
            return self::DEFAULT_MAX;
        }

        return min(self::MAX_MAX, max(self::MIN_MAX, $raw));
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
        return $this->countLiveManagedChildren($taskModel) < $this->maxConcurrent();
    }
}
