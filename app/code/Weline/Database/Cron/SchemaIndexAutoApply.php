<?php

declare(strict_types=1);

namespace Weline\Database\Cron;

use Weline\Database\Service\Index\SchemaIndexConfig;
use Weline\Database\Service\Index\SchemaIndexJobService;
use Weline\Framework\Cron\CronTaskInterface;

/**
 * Schedules at most max_concurrent index builds. The actual long DDL runs in
 * SchemaIndexApplyQueue, so the cron process never owns a multi-minute
 * CREATE INDEX CONCURRENTLY call.
 */
final class SchemaIndexAutoApply implements CronTaskInterface
{
    public function __construct(
        private readonly SchemaIndexConfig $config,
        private readonly SchemaIndexJobService $jobService,
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
        return '* * * * *';
    }

    public function execute(): string
    {
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
