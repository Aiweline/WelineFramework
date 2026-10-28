<?php

declare(strict_types=1);

namespace Weline\Database\Cron;

use Weline\Cron\CronTaskPreflightInterface;
use Weline\Database\Model\SchemaIndexJob;
use Weline\Database\Service\Index\SchemaIndexConfig;
use Weline\Database\Service\Index\SchemaIndexDeltaDetector;
use Weline\Database\Service\Index\SchemaIndexJobService;
use Weline\Framework\App\SystemUpdateActivity;
use Weline\Framework\Cron\CronTaskInterface;
use Weline\Framework\Manager\ObjectManager;

/**
 * Schedules at most max_concurrent index builds. The actual long DDL runs in
 * SchemaIndexApplyQueue, so the cron process never owns a multi-minute
 * CREATE INDEX CONCURRENTLY call.
 *
 * 只消费看门狗（SchemaIndexWatchdog）写进 flagged 的表：flagged 为空说明本轮没有
 * 数据变化，直接 skip:no_watchdog_flagged，不查窗口/负载/任务。
 */
final class SchemaIndexAutoApply implements CronTaskInterface, CronTaskPreflightInterface
{
    public function __construct(
        private readonly SchemaIndexConfig $config,
        private readonly SchemaIndexJobService $jobService,
        private readonly SchemaIndexDeltaDetector $deltaDetector,
    ) {
    }

    public function name(): string
    {
        return '异步索引自动应用';
    }

    public function execute_name(): string
    {
        return 'schema_index_auto_apply';
    }

    public function tip(): string
    {
        return '按系统级开关/窗口/负载/并发把待索引任务投递到队列；默认启用。';
    }

    public function cron_time(): string
    {
        // 窗口写进表达式：窗口外直接不派发，不再每分钟醒来判「不在窗口」。
        return '*/5 1-5 * * *';
    }

    /**
     * 前置检查：看门狗 flagged 非空，且确实存在 pending job。
     * 只读一次状态文件 + 一次状态计数，不做窗口/负载判断（留给 execute）。
     */
    public function hasPendingWork(): bool
    {
        try {
            if ($this->deltaDetector->flaggedTables() === []) {
                return false;
            }
            $model = ObjectManager::getInstance(SchemaIndexJob::class);

            return (int)$model->clear()->reset()
                ->where(SchemaIndexJob::schema_fields_STATUS, SchemaIndexJob::STATUS_PENDING)
                ->total() > 0;
        } catch (\Throwable) {
            // fail-open：前置检查失败按「有活」处理，交给 execute 里的完整判断。
            return true;
        }
    }

    public function execute(): string
    {
        if ($this->deltaDetector->flaggedTables() === []) {
            return 'skip:no_watchdog_flagged';
        }
        if (SystemUpdateActivity::inProgress()) {
            return (string)__('系统正在更新，索引构建改到闲时再做。');
        }
        if (!$this->config->autoApply()) {
            return (string)__('自动索引未启用，跳过。');
        }
        if (!$this->config->isWithinWindow()) {
            return (string)__('当前不在索引窗口（%{1}），跳过。', [$this->config->window()]);
        }
        if (!$this->config->isLoadAcceptable()) {
            return (string)__('系统负载超过索引阈值 %{1}，本轮跳过。', [(string)$this->config->loadThreshold()]);
        }
        if ($this->jobService->activeCount() >= $this->config->maxConcurrent()) {
            return (string)__('已有索引构建运行中，达到并发上限 %{1}，本轮跳过。', [(string)$this->config->maxConcurrent()]);
        }

        $job = $this->jobService->nextPendingJob(
            $this->config->cooldownSeconds(),
            $this->config->maxPerTableWindow(),
        );
        if ($job === null) {
            return (string)__('没有待索引任务。');
        }

        $jobId = (int)($job['job_id'] ?? 0);
        if ($jobId < 1) {
            return (string)__('待索引任务数据异常，缺少 job_id。');
        }

        try {
            $queueId = $this->jobService->enqueueApplyJob($jobId);
            $this->jobService->markQueued($jobId);
            $table = trim((string)($job[SchemaIndexJob::schema_fields_TABLE_NAME] ?? ''));
            if ($table !== '') {
                // 该表本轮已消费：从看门狗 flagged 中移除，下轮靠新的增量再触发。
                $this->deltaDetector->markProcessed([$table]);
            }

            return (string)__('索引任务 #%{1} 已入队 #%{2}。', [(string)$jobId, (string)$queueId]);
        } catch (\Throwable $throwable) {
            $this->jobService->markPendingAgain($jobId, $throwable->getMessage());

            return (string)__('索引任务 #%{1} 入队失败：%{2}', [(string)$jobId, $throwable->getMessage()]);
        }
    }

    public function unlock_timeout(int $minute = 30): int
    {
        return 600;
    }
}
