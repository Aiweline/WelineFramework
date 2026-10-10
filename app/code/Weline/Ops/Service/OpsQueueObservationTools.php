<?php

declare(strict_types=1);

namespace Weline\Ops\Service;

use Weline\Ai\Api\ToolInterface;
use Weline\Framework\Manager\ObjectManager;

final class OpsQueueObservationTools
{
    /** @return list<ToolInterface> */
    public static function all(): array
    {
        return [
            ObjectManager::getInstance(ListOpsQueueTasksTool::class),
            ObjectManager::getInstance(GetOpsTaskTool::class),
        ];
    }
}

final class ListOpsQueueTasksTool implements ToolInterface
{
    public function __construct(
        private readonly OpsTaskService $taskService,
    ) {
    }

    public function getName(): string
    {
        return 'list_ops_queue_tasks';
    }

    public function getDescription(): string
    {
        return 'List Ops tasks for an assignee (queued/running). Read-only; does not claim.';
    }

    public function isEnabled(): bool
    {
        return true;
    }

    public function getParameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'assignee_agent' => ['type' => 'string'],
                'website_id' => ['type' => 'integer'],
                'session_id' => ['type' => 'string'],
                'limit' => ['type' => 'integer'],
            ],
            'required' => ['assignee_agent'],
        ];
    }

    public function execute(array $arguments): string
    {
        $assignee = trim((string)($arguments['assignee_agent'] ?? ''));
        if ($assignee === '') {
            return json_encode(['ok' => false, 'message' => 'assignee_agent required'], JSON_UNESCAPED_UNICODE) ?: '{}';
        }
        $filters = [
            'assignee_agent' => $assignee,
            'statuses' => ['queued', 'running'],
            'dispatch_only' => true,
        ];
        if (isset($arguments['website_id'])) {
            $filters['website_id'] = (int)$arguments['website_id'];
        }
        $sessionId = trim((string)($arguments['session_id'] ?? ''));
        if ($sessionId !== '') {
            $filters['session_id'] = $sessionId;
        }
        $tasks = $this->taskService->listFiltered($filters, (int)($arguments['limit'] ?? 20));

        return json_encode(['ok' => true, 'tasks' => $tasks], JSON_UNESCAPED_UNICODE) ?: '{}';
    }
}

final class GetOpsTaskTool implements ToolInterface
{
    public function __construct(
        private readonly OpsTaskService $taskService,
        private readonly OpsSessionService $sessionService,
    ) {
    }

    public function getName(): string
    {
        return 'get_ops_task';
    }

    public function getDescription(): string
    {
        return 'Get one Ops task with session context summary. Read-only.';
    }

    public function isEnabled(): bool
    {
        return true;
    }

    public function getParameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'task_id' => ['type' => 'integer'],
            ],
            'required' => ['task_id'],
        ];
    }

    public function execute(array $arguments): string
    {
        $taskId = (int)($arguments['task_id'] ?? 0);
        $got = $this->taskService->get($taskId);
        if (!($got['ok'] ?? false)) {
            return json_encode($got, JSON_UNESCAPED_UNICODE) ?: '{}';
        }
        $task = $got['task'] ?? [];
        $sessionId = (string)($task['session_id'] ?? '');
        $session = null;
        if ($sessionId !== '') {
            $s = $this->sessionService->get($sessionId);
            $session = ($s['ok'] ?? false) ? ($s['session'] ?? null) : null;
        }

        return json_encode([
            'ok' => true,
            'task' => $task,
            'session' => $session,
        ], JSON_UNESCAPED_UNICODE) ?: '{}';
    }
}
