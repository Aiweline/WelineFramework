<?php

declare(strict_types=1);

namespace Weline\Cron\Service;

use Weline\Cron\Model\CronTask;
use Weline\Framework\Manager\ObjectManager;

/**
 * Cron 后台只读接口的共享业务边界。
 *
 * Controller 保留兼容 HTTP 路由，QueryProvider 只返回 data；两者必须经过
 * 同一处任务解析、日志路径校验与响应整形，避免迁移到 bin-query 后语义分叉。
 */
final class CronAdminReadService
{
    public function __construct(
        private readonly CronRunLogService $runLogService,
    ) {
    }

    /**
     * @return array{status:int,data:array<string,mixed>}
     */
    public function getRunHelp(string $taskIdentifier): array
    {
        $task = $this->resolveRequiredTask($taskIdentifier);
        if (\is_array($task)) {
            return $task;
        }

        $executeName = (string)($task->getData(CronTask::schema_fields_EXECUTE_NAME) ?? '');
        $tip = (string)($task->getData(CronTask::schema_fields_TIP) ?? '');
        $row = CronTestDiscovery::findById($executeName);
        $description = '';
        $examples = [];
        $manualHelp = [];
        if ($row !== null) {
            $description = (string)($row['description'] ?? '');
            $examples = \is_array($row['examples'] ?? null) ? $row['examples'] : [];
            $manualHelp = \is_array($row['manual_help'] ?? null) ? $row['manual_help'] : [];
        }

        $manualItems = [];
        foreach ($manualHelp as $line) {
            $item = \trim((string)$line);
            if ($item !== '') {
                $manualItems[] = $item;
            }
        }
        $manualFallback = (string)__(
            '本任务未在 #[CronTestHelp] 中配置 manual_help。「后缀」会写入 WELINE_CRON_MANUAL_ARGS，是否生效取决于 execute() 是否解析；留空同定时。'
        );
        $helpRows = [
            [
                'k' => (string)__('调度说明'),
                'fmt' => 'text',
                'v' => $tip !== '' ? $tip : '-',
            ],
            $manualItems !== []
                ? [
                    'k' => (string)__('手动参数'),
                    'fmt' => 'list',
                    'items' => $manualItems,
                ]
                : [
                    'k' => (string)__('手动参数'),
                    'fmt' => 'text',
                    'v' => $manualFallback,
                ],
        ];
        if ($description !== '') {
            $helpRows[] = [
                'k' => (string)__('测试说明'),
                'fmt' => 'text',
                'v' => $description,
            ];
        }
        if ($examples !== []) {
            $helpRows[] = [
                'k' => (string)__('示例'),
                'fmt' => 'pre_lines',
                'items' => $examples,
            ];
        }

        return $this->success([
            'success' => true,
            'execute_name' => $executeName,
            'name' => (string)($task->getData(CronTask::schema_fields_NAME) ?? ''),
            'tip' => $tip,
            'test_help_description' => $description,
            'test_help_examples' => $examples,
            'manual_args_hint' => (string)__(
                '可选「后缀」会写入子进程环境变量 WELINE_CRON_MANUAL_ARGS；任务可在 execute() 内 getenv 读取。留空则与定时调度一致。'
            ),
            'help_rows' => $helpRows,
        ]);
    }

    /**
     * @return array{status:int,data:array<string,mixed>}
     */
    public function runLogList(string $taskIdentifier): array
    {
        $task = $this->resolveRequiredTask($taskIdentifier);
        if (\is_array($task)) {
            return $task;
        }

        $executeName = (string)($task->getData(CronTask::schema_fields_EXECUTE_NAME) ?? '');
        $data = $this->runLogService->listForExecuteName($executeName);
        if (!($data['success'] ?? false)) {
            return $this->failure(400, (string)($data['message'] ?? __('请求失败')));
        }

        return $this->success([
            'success' => true,
            'task_running' => (bool)($data['task_running'] ?? false),
            'live_exists' => (bool)($data['live_exists'] ?? false),
            'live_size' => (int)($data['live_size'] ?? 0),
            'items' => $data['items'] ?? [],
        ]);
    }

    /**
     * @return array{status:int,data:array<string,mixed>}
     */
    public function runLogContent(string $taskIdentifier, string $file): array
    {
        $task = $this->resolveRequiredTask($taskIdentifier);
        if (\is_array($task)) {
            return $task;
        }

        $executeName = (string)($task->getData(CronTask::schema_fields_EXECUTE_NAME) ?? '');
        $data = $this->runLogService->readHistoryFile($executeName, \trim($file));
        if (!($data['success'] ?? false)) {
            $message = (string)($data['message'] ?? __('读取失败'));
            $status = ($data['code'] ?? '') === 'log_not_found' ? 404 : 400;

            return $this->failure($status, $message);
        }

        return $this->success([
            'success' => true,
            'content' => (string)($data['content'] ?? ''),
            'truncated' => (bool)($data['truncated'] ?? false),
        ]);
    }

    /**
     * @return CronTask|array{status:int,data:array<string,mixed>}
     */
    private function resolveRequiredTask(string $identifier): CronTask|array
    {
        $identifier = \trim($identifier);
        if ($identifier === '') {
            return $this->failure(400, (string)__('参数 execute_name 不能为空'));
        }

        /** @var CronTask $task */
        $task = ObjectManager::make(CronTask::class)->reset()
            ->where(CronTask::schema_fields_EXECUTE_NAME, $identifier)
            ->find()
            ->fetch();
        if ($task->getId()) {
            return $task;
        }

        /** @var CronTask $byName */
        $byName = ObjectManager::make(CronTask::class)->reset()
            ->where(CronTask::schema_fields_NAME, $identifier)
            ->find()
            ->fetch();

        return $byName->getId()
            ? $byName
            : $this->failure(404, (string)__('任务不存在'));
    }

    /**
     * @param array<string,mixed> $data
     * @return array{status:int,data:array<string,mixed>}
     */
    private function success(array $data): array
    {
        return ['status' => 200, 'data' => $data];
    }

    /**
     * @return array{status:int,data:array<string,mixed>}
     */
    private function failure(int $status, string $message): array
    {
        return [
            'status' => $status,
            'data' => [
                'success' => false,
                'message' => $message,
            ],
        ];
    }
}
