<?php

declare(strict_types=1);

namespace Weline\Framework\Console\Console\Runtime\Task;

use Weline\Framework\Console\CommandAbstract;
use Weline\Framework\Console\CommandHelper;
use Weline\Framework\Service\Runtime\ResumableTaskStore;

/** Read-only operator inspection; it never starts, stops or renews a task. */
final class Inspect extends CommandAbstract
{
    public function __construct(private readonly ResumableTaskStore $store)
    {
    }

    public function execute(array $args = [], array $data = []): void
    {
        $taskId = trim((string)($args['task-id'] ?? $args['task_id'] ?? ''));
        if ($taskId === '') {
            $this->printer->error(__('必须提供 --task-id。'));
            return;
        }
        $task = $this->store->findTask($taskId);
        if ($task === null) {
            $this->printer->error(__('未找到可恢复任务。'));
            return;
        }
        $this->printer->note(json_encode($task, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) ?: '{}');
    }

    public function tip(): string
    {
        return __('查看可恢复后台任务的持久状态');
    }

    public function help(): array|string
    {
        return CommandHelper::formatHelp(
            'runtime:task:inspect --task-id=<id>',
            $this->tip(),
            [],
            [],
            [],
        );
    }
}
