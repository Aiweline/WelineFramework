<?php

declare(strict_types=1);

namespace Weline\Database\Test\Unit\Service\Index;

use PHPUnit\Framework\TestCase;
use Weline\Cron\CronTaskPreflightInterface;
use Weline\Database\Cron\SchemaIndexAutoApply;
use Weline\Database\Cron\SchemaIndexWatchdog;
use Weline\Database\Model\SchemaIndexJob;
use Weline\Database\Service\Index\SchemaIndexConfig;
use Weline\Database\Service\Index\SchemaIndexDeltaDetector;
use Weline\Database\Service\Index\SchemaIndexJobService;
use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Manager\ObjectManager;

/**
 * 两个索引 cron 的增量闸门：无增量 → 不派生子进程、execute 直接 skip。
 */
final class SchemaIndexDeltaCronTest extends TestCase
{
    private string $stateFile;

    protected function setUp(): void
    {
        $this->stateFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'weline_schema_index_delta_cron_' . bin2hex(random_bytes(6)) . '.json';
    }

    protected function tearDown(): void
    {
        if (is_file($this->stateFile)) {
            @unlink($this->stateFile);
        }
    }

    public function testBothCronsImplementPreflightAndKeepFiveMinuteWindow(): void
    {
        $watchdog = (new \ReflectionClass(SchemaIndexWatchdog::class))->newInstanceWithoutConstructor();
        $autoApply = (new \ReflectionClass(SchemaIndexAutoApply::class))->newInstanceWithoutConstructor();

        self::assertInstanceOf(CronTaskPreflightInterface::class, $watchdog);
        self::assertInstanceOf(CronTaskPreflightInterface::class, $autoApply);
        self::assertSame('*/5 1-5 * * *', $watchdog->cron_time());
        self::assertSame('*/5 1-5 * * *', $autoApply->cron_time());
    }

    public function testWatchdogPreflightFalseAndExecuteSkipWhenNoDelta(): void
    {
        $this->seedState(['w_a' => ['rows' => 3, 'updated' => '2026-10-28 10:00:00']]);
        $detector = $this->detector([
            ['TABLE_NAME' => 'w_a', 'TABLE_ROWS' => 3, 'UPDATE_TIME' => '2026-10-28 10:00:00'],
        ]);
        $watchdog = new SchemaIndexWatchdog(
            new SchemaIndexConfig(),
            $this->jobService(),
            $this->connectionFactory(),
            $detector,
            static fn(): array => [],
        );

        self::assertFalse($watchdog->hasPendingWork());
        self::assertSame('skip:no_delta', $watchdog->execute());
        self::assertSame([], $detector->flaggedTables(), '无增量不得写入 flagged');
    }

    public function testWatchdogPreflightTrueWhenDeltaExists(): void
    {
        $this->seedState(['w_a' => ['rows' => 3, 'updated' => '2026-10-28 10:00:00']]);
        $watchdog = new SchemaIndexWatchdog(
            new SchemaIndexConfig(),
            $this->jobService(),
            $this->connectionFactory(),
            $this->detector([
                ['TABLE_NAME' => 'w_a', 'TABLE_ROWS' => 9, 'UPDATE_TIME' => '2026-10-28 10:00:00'],
            ]),
            static fn(): array => [],
        );

        self::assertTrue($watchdog->hasPendingWork());
    }

    /**
     * 无增量但有 pending job 的表：看门狗必须为它 preflight=true 并补标 flagged，
     * auto_apply 才能把 setup:upgrade 留下、目标表此后无写入的 job 消费掉。
     */
    public function testWatchdogFlagsPendingJobTableWithoutDeltaAndAutoApplyConsumes(): void
    {
        $this->requireDatabase();
        $table = 'w_pending_probe_' . bin2hex(random_bytes(4));
        $index = 'idx_pending_' . bin2hex(random_bytes(4));
        $definitionJson = (string)json_encode([
            'name' => $index,
            'columns' => ['a'],
            'type' => 'DEFAULT',
        ], JSON_UNESCAPED_UNICODE);
        $jobModel = ObjectManager::getInstance(SchemaIndexJob::class);
        $now = date('Y-m-d H:i:s');
        $jobId = 0;
        try {
            $jobModel->clear()->reset()->insert([
                SchemaIndexJob::schema_fields_CHANGE_SET_ID => 'pending-probe',
                SchemaIndexJob::schema_fields_MODULE => 'Weline_Database',
                SchemaIndexJob::schema_fields_TABLE_NAME => $table,
                SchemaIndexJob::schema_fields_INDEX_NAME => $index,
                SchemaIndexJob::schema_fields_ACTION => SchemaIndexJob::ACTION_CREATE,
                SchemaIndexJob::schema_fields_DEFINITION_JSON => $definitionJson,
                SchemaIndexJob::schema_fields_DEFINITION_HASH => hash('sha256', $definitionJson),
                SchemaIndexJob::schema_fields_PHYSICAL_NAME => 'probe_physical_' . $index,
                SchemaIndexJob::schema_fields_STATUS => SchemaIndexJob::STATUS_PENDING,
                SchemaIndexJob::schema_fields_PRIORITY => 100,
                SchemaIndexJob::schema_fields_ATTEMPTS => 0,
                SchemaIndexJob::schema_fields_MAX_ATTEMPTS => 3,
                SchemaIndexJob::schema_fields_CREATE_TIME => $now,
                SchemaIndexJob::schema_fields_UPDATE_TIME => $now,
            ], SchemaIndexJob::schema_fields_ID)->fetch();
            $jobId = (int)$jobModel->clear()->reset()
                ->where(SchemaIndexJob::schema_fields_TABLE_NAME, $table)
                ->where(SchemaIndexJob::schema_fields_INDEX_NAME, $index)
                ->find()
                ->fetch()
                ->getId();
        } catch (\Throwable $throwable) {
            self::markTestSkipped('schema index job table unavailable: ' . $throwable->getMessage());
        }
        self::assertGreaterThan(0, $jobId);

        try {
            // 无增量：指纹与基线一致，只有 pending job 这一个信号。
            $this->seedState(['w_a' => ['rows' => 3, 'updated' => '2026-10-28 10:00:00']]);
            $detector = $this->detector([
                ['TABLE_NAME' => 'w_a', 'TABLE_ROWS' => 3, 'UPDATE_TIME' => '2026-10-28 10:00:00'],
            ]);
            $jobService = ObjectManager::getInstance(SchemaIndexJobService::class);
            $watchdog = new SchemaIndexWatchdog(
                new SchemaIndexConfig(),
                $jobService,
                $this->connectionFactory(),
                $detector,
            );

            self::assertSame([], $detector->changedTables(), '前置：本轮没有增量');
            self::assertTrue($watchdog->hasPendingWork(), 'pending job 表存在时 preflight 必须为 true');
            self::assertNotSame('skip:no_delta', $watchdog->execute());
            self::assertContains($table, $detector->flaggedTables());

            // auto_apply 侧：真实消费（入队后 status=queued，该表从 flagged 移除）。
            $next = $jobService->nextPendingJob(300, 1);
            if ($next === null || (int)($next[SchemaIndexJob::schema_fields_ID] ?? 0) !== $jobId) {
                self::markTestSkipped('dev 库还有其它待索引 job 排在前面，跳过真实入队断言');
            }
            $autoApply = new SchemaIndexAutoApply(
                new SchemaIndexConfig(null, null, '*', true, 999.0),
                $jobService,
                $detector,
            );
            self::assertTrue($autoApply->hasPendingWork());
            self::assertStringContainsString('已入队', $autoApply->execute());
            self::assertNotContains($table, $detector->flaggedTables(), '入队成功后该表应被 markProcessed 移除');
        } finally {
            $this->cleanupProbeJob($jobId, $jobModel);
        }
    }

    public function testAutoApplyPreflightFalseAndExecuteSkipWithoutWatchdogFlagged(): void
    {
        $autoApply = new SchemaIndexAutoApply(
            new SchemaIndexConfig(),
            $this->jobService(),
            $this->detector([
                ['TABLE_NAME' => 'w_a', 'TABLE_ROWS' => 3, 'UPDATE_TIME' => '2026-10-28 10:00:00'],
            ]),
        );

        self::assertFalse($autoApply->hasPendingWork());
        self::assertSame('skip:no_watchdog_flagged', $autoApply->execute());
    }

    public function testAutoApplyConsumesWatchdogFlaggedTables(): void
    {
        // 看门狗侧：首次运行把全库表视为增量并写进 flagged。
        $detector = $this->detector([
            ['TABLE_NAME' => 'w_delta', 'TABLE_ROWS' => 5, 'UPDATE_TIME' => '2026-10-28 10:00:00'],
        ]);
        $detector->remember();
        self::assertSame(['w_delta'], $detector->flaggedTables());

        // auto_apply 关闭（config override），用它证明「已读到 flagged、过了 skip 闸门」。
        $autoApply = new SchemaIndexAutoApply(
            new SchemaIndexConfig(null, null, '*', false, 999.0),
            $this->jobService(),
            $detector,
        );

        self::assertNotSame('skip:no_watchdog_flagged', $autoApply->execute());
    }

    /** @param array<string, mixed> $tables */
    private function seedState(array $tables): void
    {
        file_put_contents($this->stateFile, (string)json_encode([
            'version' => 1,
            'snapshot_at' => '2026-10-28 10:00:00',
            'tables' => $tables,
            'flagged' => [],
        ], JSON_UNESCAPED_UNICODE));
    }

    /** @param array<int,array<string,mixed>> $rows */
    private function detector(array $rows): SchemaIndexDeltaDetector
    {
        return new SchemaIndexDeltaDetector(
            null,
            $this->stateFile,
            static fn(): array => $rows,
        );
    }

    private function jobService(): SchemaIndexJobService
    {
        // 两个用例都在触库之前返回，构造器依赖不必初始化。
        return (new \ReflectionClass(SchemaIndexJobService::class))->newInstanceWithoutConstructor();
    }

    private function connectionFactory(): ConnectionFactory
    {
        return (new \ReflectionClass(ConnectionFactory::class))->newInstanceWithoutConstructor();
    }

    private function requireDatabase(): void
    {
        try {
            ObjectManager::getInstance(ConnectionFactory::class)->getConnector()
                ->query('SELECT 1')
                ->fetch();
        } catch (\Throwable $throwable) {
            self::markTestSkipped('database unavailable: ' . $throwable->getMessage());
        }
    }

    private function cleanupProbeJob(int $jobId, SchemaIndexJob $jobModel): void
    {
        if ($jobId < 1) {
            return;
        }
        try {
            $bizKey = 'schema_index:job:' . $jobId;
            ObjectManager::getInstance(ConnectionFactory::class)->getConnector()->query(
                "DELETE FROM w_weline_queue WHERE biz_key = '" . str_replace("'", "''", $bizKey) . "'"
            )->fetch();
            $jobModel->clear()->reset()
                ->where(SchemaIndexJob::schema_fields_ID, $jobId)
                ->delete()
                ->fetch();
        } catch (\Throwable) {
        }
    }
}
