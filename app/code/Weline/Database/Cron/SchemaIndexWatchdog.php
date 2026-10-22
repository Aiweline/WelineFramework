<?php

declare(strict_types=1);

namespace Weline\Database\Cron;

use Weline\Database\Service\Index\SchemaIndexConfig;
use Weline\Database\Service\Index\SchemaIndexJobService;
use Weline\Framework\App\SystemUpdateActivity;
use Weline\Framework\Cron\CronTaskInterface;
use Weline\Framework\Database\Connection\Api\ConcurrentIndexBuildInterface;
use Weline\Framework\Database\ConnectionFactory;

/**
 * Load guard for already-running index builds. CREATE INDEX CONCURRENTLY does
 * not block reads/writes, but it still consumes I/O; when host load crosses the
 * configured threshold the watchdog cancels the backend and reschedules the job.
 */
final class SchemaIndexWatchdog implements CronTaskInterface
{
    public function __construct(
        private readonly SchemaIndexConfig $config,
        private readonly SchemaIndexJobService $jobService,
        private readonly ConnectionFactory $connectionFactory,
    ) {
    }

    public function name(): string
    {
        return '异步索引负载看门狗';
    }

    public function execute_name(): string
    {
        return 'schema_index_watchdog';
    }

    public function tip(): string
    {
        return '监控运行中的索引构建；负载超过阈值时取消并稍后重试。';
    }

    public function cron_time(): string
    {
        return '* * * * *';
    }

    public function execute(): string
    {
        $jobs = $this->jobService->runningJobs();
        if ($jobs === []) {
            return (string)__('没有运行中的索引构建。');
        }
        $updateRunning = SystemUpdateActivity::inProgress();
        if ($this->config->isLoadAcceptable() && !$updateRunning) {
            return (string)__('运行中 %{1} 个索引构建，负载正常。', [(string)count($jobs)]);
        }

        $connector = $this->connectionFactory->getConnector();
        if (!$connector instanceof ConcurrentIndexBuildInterface) {
            return (string)__('适配器不支持取消索引构建，跳过看门狗。');
        }

        $canceled = 0;
        foreach ($jobs as $job) {
            $jobId = (int)($job['job_id'] ?? 0);
            if ($jobId < 1) {
                continue;
            }
            $applicationName = 'weline-index:' . $jobId;
            try {
                if ($connector->cancelIndexBuild($applicationName)) {
                    $this->jobService->markPendingAfterCancel(
                        $jobId,
                        $updateRunning
                            ? 'canceled_by_system_update'
                            : 'canceled_by_load_watchdog:load=' . (string)$this->config->loadThreshold(),
                    );
                    $canceled++;
                }
            } catch (\Throwable $throwable) {
                w_log_warning(
                    'Schema index watchdog cancel failed #' . $jobId . ': ' . $throwable->getMessage(),
                    [],
                    'database',
                );
            }
        }

        return (string)__(
            $updateRunning
                ? '系统正在更新，已取消 %{2}/%{3} 个索引构建并延后到闲时。'
                : '负载超过阈值 %{1}，已取消 %{2}/%{3} 个索引构建并延后重试。',
            [(string)$this->config->loadThreshold(), (string)$canceled, (string)count($jobs)],
        );
    }

    public function unlock_timeout(int $minute = 30): int
    {
        return 300;
    }
}
