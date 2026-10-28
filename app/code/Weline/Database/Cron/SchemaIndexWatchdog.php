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
use Weline\Framework\Database\Connection\Api\ConcurrentIndexBuildInterface;
use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Manager\ObjectManager;

/**
 * Load guard for already-running index builds. CREATE INDEX CONCURRENTLY does
 * not block reads/writes, but it still consumes I/O; when host load crosses the
 * configured threshold the watchdog cancels the backend and reschedules the job.
 *
 * 同时是本模块的增量探针：先看表级指纹有没有变化，没有变化直接 skip:no_delta，
 * 有变化才落 flagged 交给 auto_apply 消费。另有 pending 索引 job 的表即使没有写入
 * 也会并入 flagged，避免 setup:upgrade 产生的 job 永远等不到应用。
 */
final class SchemaIndexWatchdog implements CronTaskInterface, CronTaskPreflightInterface
{
    /** @var null|\Closure(): array<int,string> 仅单测注入：直接给 pending 表名，不碰数据库 */
    private readonly ?\Closure $pendingTableReader;

    /**
     * @param callable|null $pendingTableReader 仅单测注入；不给则查 SchemaIndexJob 的 status=pending
     */
    public function __construct(
        private readonly SchemaIndexConfig $config,
        private readonly SchemaIndexJobService $jobService,
        private readonly ConnectionFactory $connectionFactory,
        private readonly SchemaIndexDeltaDetector $deltaDetector,
        ?callable $pendingTableReader = null,
    ) {
        $this->pendingTableReader = $pendingTableReader !== null
            ? \Closure::fromCallable($pendingTableReader)
            : null;
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
        // 只在索引维护窗口内、且 5 分钟粒度足够看住负载；窗口外不产生子进程。
        return '*/5 1-5 * * *';
    }

    /**
     * 前置检查：有增量表，或有 pending 索引 job 的表，才生成子进程。
     * 两次都是廉价查询（一条全库指纹 + 一条 status=pending 的有界查询）。
     */
    public function hasPendingWork(): bool
    {
        return $this->deltaDetector->changedTables() !== [] || $this->pendingJobTables() !== [];
    }

    public function execute(): string
    {
        $changedTables = $this->deltaDetector->changedTables();
        $pendingTables = $this->pendingJobTables();
        if ($changedTables === [] && $pendingTables === []) {
            return 'skip:no_delta';
        }
        if ($changedTables !== []) {
            // 落 flagged：本轮有增量的表交给 auto_apply 消费，再走负载/取消判断。
            $this->deltaDetector->remember();
        }
        if ($pendingTables !== []) {
            // 表没有写入但 job 还 pending：补标一次，避免 job 永远等不到应用。
            $this->deltaDetector->flagTables($pendingTables);
        }

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

    /**
     * 当前存在 pending 索引 job 的表名（有界查询：只取 table_name，最多 200 条）。
     *
     * 走 SchemaIndexJob 模型（不改 SchemaIndexJobService）：这里只需要「表名集合」，
     * 不需要 nextPendingJob() 的冷却/预算判定，留给 auto_apply 去做。
     *
     * @return list<string>
     */
    private function pendingJobTables(): array
    {
        if ($this->pendingTableReader !== null) {
            $tables = ($this->pendingTableReader)();

            return is_array($tables) ? array_values(array_filter(array_map(
                static fn(mixed $table): string => trim((string)$table),
                $tables,
            ), static fn(string $table): bool => $table !== '')) : [];
        }
        try {
            $rows = ObjectManager::getInstance(SchemaIndexJob::class)->clear()->reset()
                ->fields(SchemaIndexJob::schema_fields_TABLE_NAME)
                ->where(SchemaIndexJob::schema_fields_STATUS, SchemaIndexJob::STATUS_PENDING)
                ->limit(200)
                ->select()
                ->fetchArray();
        } catch (\Throwable $throwable) {
            w_log_warning(
                'Schema index watchdog pending table scan failed: ' . $throwable->getMessage(),
                [],
                'database',
            );

            return [];
        }
        $tables = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $name = trim((string)($row[SchemaIndexJob::schema_fields_TABLE_NAME] ?? ''));
            if ($name !== '' && !in_array($name, $tables, true)) {
                $tables[] = $name;
            }
        }

        return $tables;
    }

    public function unlock_timeout(int $minute = 30): int
    {
        return 300;
    }
}
