<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Service;

use Weline\Framework\App\Env;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Test\Model\TestRun;
use Weline\Framework\Test\Queue\E2eTestRunTask;
use Weline\Framework\Test\Queue\PhpUnitTestRunTask;

/**
 * Creates TestRun rows and enqueues async execution without importing Weline_Queue classes.
 */
final class TestRunService
{
    public function __construct(
        private readonly TestCatalogService $catalogService = new TestCatalogService(),
    ) {
    }

    /**
     * @param list<string> $files
     * @return array{run_id:int,task_id:int,status:string,ui_enabled:bool,module:string,type:string}
     */
    public function startRun(string $type, string $module, bool $uiEnabled = false, array $files = []): array
    {
        $this->assertDevDeploy();

        $type = strtolower(trim($type));
        if (!in_array($type, [TestRun::TYPE_E2E, TestRun::TYPE_UNIT, TestRun::TYPE_INTEGRATION], true)) {
            throw new \InvalidArgumentException((string)__('不支持的测试类型：%{1}', [$type]));
        }

        $cases = $this->catalogService->listCases($module, $type === TestRun::TYPE_INTEGRATION ? 'integration' : $type);
        $moduleName = (string)$cases['module'];
        $available = match ($type) {
            TestRun::TYPE_E2E => $cases['tests']['e2e'],
            TestRun::TYPE_INTEGRATION => $cases['tests']['integration'],
            default => array_values(array_unique(array_merge($cases['tests']['unit'], $cases['tests']['phpunit']))),
        };

        $selected = [];
        foreach ($files as $file) {
            if (!is_string($file)) {
                continue;
            }
            $normalized = str_replace('\\', '/', trim($file));
            if ($normalized !== '' && in_array($normalized, $available, true)) {
                $selected[] = $normalized;
            }
        }
        if ($selected === []) {
            $selected = $available;
        }
        if ($selected === []) {
            throw new \InvalidArgumentException((string)__('模块 %{1} 没有可运行的 %{2} 用例。', [$moduleName, $type]));
        }

        /** @var TestRun $run */
        $run = ObjectManager::getInstance(TestRun::class);
        $run->clear()
            ->setData(TestRun::schema_fields_MODULE, $moduleName)
            ->setData(TestRun::schema_fields_TYPE, $type)
            ->setData(TestRun::schema_fields_UI_ENABLED, $uiEnabled ? 1 : 0)
            ->setData(TestRun::schema_fields_STATUS, TestRun::STATUS_PENDING)
            ->setData(TestRun::schema_fields_TASK_ID, 0)
            ->setData(TestRun::schema_fields_PROGRESS_JSON, json_encode([
                'passed' => 0,
                'failed' => 0,
                'skipped' => 0,
                'total' => count($selected),
                'current' => '',
                'percent' => 0,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))
            ->setData(TestRun::schema_fields_LOG, '')
            ->setData(TestRun::schema_fields_FILES_JSON, json_encode(array_values($selected), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))
            ->setData(TestRun::schema_fields_REPORT_PATH, '')
            ->setData(TestRun::schema_fields_ERROR_SUMMARY, '')
            ->save();

        $runId = (int)$run->getId();
        if ($runId <= 0) {
            throw new \RuntimeException((string)__('创建测试运行记录失败。'));
        }

        $taskId = $this->enqueue($runId, $type, $moduleName, $uiEnabled, $selected);
        $run->setData(TestRun::schema_fields_TASK_ID, $taskId)->save();

        return [
            'run_id' => $runId,
            'task_id' => $taskId,
            'status' => TestRun::STATUS_PENDING,
            'ui_enabled' => $uiEnabled,
            'module' => $moduleName,
            'type' => $type,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function getRun(int $runId): array
    {
        if ($runId <= 0) {
            throw new \InvalidArgumentException((string)__('run_id 无效。'));
        }

        /** @var TestRun $run */
        $run = ObjectManager::getInstance(TestRun::class);
        $run->clear()->load($runId);
        if (!(int)$run->getId()) {
            throw new \InvalidArgumentException((string)__('测试运行不存在：%{1}', [(string)$runId]));
        }

        return $this->serializeRun($run);
    }

    /**
     * @return array{items:list<array<string,mixed>>,total:int,page:int,page_size:int}
     */
    public function listRuns(int $page = 1, int $pageSize = 20, ?string $module = null, ?string $type = null): array
    {
        $page = max(1, $page);
        $pageSize = max(1, min(100, $pageSize));

        /** @var TestRun $model */
        $model = ObjectManager::getInstance(TestRun::class);
        $query = $model->clear();
        if ($module !== null && trim($module) !== '') {
            $query->where(TestRun::schema_fields_MODULE, trim($module));
        }
        if ($type !== null && trim($type) !== '') {
            $query->where(TestRun::schema_fields_TYPE, trim($type));
        }

        $total = (int)$query->count(TestRun::schema_fields_ID);
        $rows = $query
            ->order(TestRun::schema_fields_ID, 'DESC')
            ->pagination($page, $pageSize)
            ->select()
            ->fetchArray();

        $items = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $items[] = $this->serializeRow($row);
        }

        return [
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'page_size' => $pageSize,
        ];
    }

    /**
     * @param array<string,mixed> $progress
     */
    public function appendProgress(int $runId, array $progress, string $logChunk = '', ?string $status = null): void
    {
        /** @var TestRun $run */
        $run = ObjectManager::getInstance(TestRun::class);
        $run->clear()->load($runId);
        if (!(int)$run->getId()) {
            return;
        }

        if ($progress !== []) {
            $run->setData(
                TestRun::schema_fields_PROGRESS_JSON,
                json_encode($progress, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}'
            );
        }

        if ($logChunk !== '') {
            $existing = (string)$run->getData(TestRun::schema_fields_LOG);
            $merged = $existing === '' ? $logChunk : rtrim($existing) . "\n" . $logChunk;
            if (strlen($merged) > 500000) {
                $merged = substr($merged, -500000);
            }
            $run->setData(TestRun::schema_fields_LOG, $merged);
        }

        if ($status !== null && $status !== '') {
            $run->setData(TestRun::schema_fields_STATUS, $status);
            if ($status === TestRun::STATUS_RUNNING && !(string)$run->getData(TestRun::schema_fields_STARTED_AT)) {
                $run->setData(TestRun::schema_fields_STARTED_AT, date('Y-m-d H:i:s'));
            }
            if (in_array($status, [TestRun::STATUS_SUCCESS, TestRun::STATUS_FAILED, TestRun::STATUS_ERROR], true)) {
                $run->setData(TestRun::schema_fields_FINISHED_AT, date('Y-m-d H:i:s'));
            }
        }

        $run->save();
    }

    public function finalize(int $runId, int $exitCode, string $reportPath = '', string $errorSummary = ''): void
    {
        /** @var TestRun $run */
        $run = ObjectManager::getInstance(TestRun::class);
        $run->clear()->load($runId);
        if (!(int)$run->getId()) {
            return;
        }

        $status = $exitCode === 0 ? TestRun::STATUS_SUCCESS : TestRun::STATUS_FAILED;
        $run->setData(TestRun::schema_fields_EXIT_CODE, $exitCode)
            ->setData(TestRun::schema_fields_STATUS, $status)
            ->setData(TestRun::schema_fields_REPORT_PATH, $reportPath)
            ->setData(TestRun::schema_fields_ERROR_SUMMARY, $errorSummary)
            ->setData(TestRun::schema_fields_FINISHED_AT, date('Y-m-d H:i:s'))
            ->save();
    }

    /**
     * @param list<string> $files
     */
    private function enqueue(int $runId, string $type, string $module, bool $uiEnabled, array $files): int
    {
        $class = $type === TestRun::TYPE_E2E ? E2eTestRunTask::class : PhpUnitTestRunTask::class;
        $name = $type === TestRun::TYPE_E2E
            ? (string)__('E2E 测试：%{1}', [$module])
            : (string)__('单元测试：%{1}', [$module]);

        $content = [
            'run_id' => $runId,
            'module' => $module,
            'type' => $type,
            'ui_enabled' => $uiEnabled,
            'files' => array_values($files),
        ];

        $idempotencyKey = 'framework-test:' . $type . ':' . $module . ':' . $runId;
        try {
            $result = w_query('queue', 'createIfAbsent', [
                'class' => $class,
                'name' => $name,
                'module' => 'Weline_Framework',
                'content' => $content,
                'status' => 'pending',
                'auto' => true,
                'biz_key' => 'framework_test_run:' . $runId,
                'idempotency_key' => $idempotencyKey,
                'dispatch' => true,
            ]);
        } catch (\InvalidArgumentException $e) {
            throw new \RuntimeException(
                (string)__('队列模块未启用或 queue 查询器不可用：%{1}', [$e->getMessage()]),
                0,
                $e
            );
        } catch (\Throwable $e) {
            throw new \RuntimeException(
                (string)__('创建测试队列失败：%{1}', [$e->getMessage()]),
                0,
                $e
            );
        }

        $taskId = is_array($result) ? (int)($result['queue_id'] ?? 0) : 0;
        if ($taskId <= 0) {
            throw new \RuntimeException((string)__('创建测试队列失败：未返回 queue_id。'));
        }

        return $taskId;
    }

    private function assertDevDeploy(): void
    {
        if (Env::system('deploy') !== 'dev') {
            throw new \RuntimeException(
                (string)__('非开发环境禁止运行测试。请先执行 php bin/w deploy:mode:set dev。')
            );
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function serializeRun(TestRun $run): array
    {
        return $this->serializeRow($run->getData());
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function serializeRow(array $row): array
    {
        $progressRaw = (string)($row[TestRun::schema_fields_PROGRESS_JSON] ?? '');
        $progress = json_decode($progressRaw, true);
        if (!is_array($progress)) {
            $progress = [];
        }

        $filesRaw = (string)($row[TestRun::schema_fields_FILES_JSON] ?? '');
        $files = json_decode($filesRaw, true);
        if (!is_array($files)) {
            $files = [];
        }

        return [
            'run_id' => (int)($row[TestRun::schema_fields_ID] ?? 0),
            'module' => (string)($row[TestRun::schema_fields_MODULE] ?? ''),
            'type' => (string)($row[TestRun::schema_fields_TYPE] ?? ''),
            'ui_enabled' => (int)($row[TestRun::schema_fields_UI_ENABLED] ?? 0) === 1,
            'status' => (string)($row[TestRun::schema_fields_STATUS] ?? ''),
            'task_id' => (int)($row[TestRun::schema_fields_TASK_ID] ?? 0),
            'exit_code' => array_key_exists(TestRun::schema_fields_EXIT_CODE, $row)
                ? ($row[TestRun::schema_fields_EXIT_CODE] === null ? null : (int)$row[TestRun::schema_fields_EXIT_CODE])
                : null,
            'progress' => $progress,
            'log' => (string)($row[TestRun::schema_fields_LOG] ?? ''),
            'files' => array_values(array_filter($files, 'is_string')),
            'report_path' => (string)($row[TestRun::schema_fields_REPORT_PATH] ?? ''),
            'error_summary' => (string)($row[TestRun::schema_fields_ERROR_SUMMARY] ?? ''),
            'started_at' => (string)($row[TestRun::schema_fields_STARTED_AT] ?? ''),
            'finished_at' => (string)($row[TestRun::schema_fields_FINISHED_AT] ?? ''),
            'created_at' => (string)($row[TestRun::schema_fields_CREATED_AT] ?? ''),
            'updated_at' => (string)($row[TestRun::schema_fields_UPDATED_AT] ?? ''),
        ];
    }
}
