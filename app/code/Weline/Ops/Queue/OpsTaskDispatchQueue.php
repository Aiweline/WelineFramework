<?php

declare(strict_types=1);

namespace Weline\Ops\Queue;

use Weline\Ai\Api\AiRuntimeInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Ops\Model\OpsRunLog;
use Weline\Ops\Model\OpsTask;
use Weline\Ops\Service\OpsRunLogService;
use Weline\Ops\Service\OpsSessionService;
use Weline\Ops\Service\OpsTaskService;
use Weline\Queue\Api\QueueConsumerInterface;
use Weline\Queue\Api\QueueTaskContextInterface;

/**
 * 消费 OpsTask：claim → 读 Session → executeAgent → 写账本。
 * 分析任务成功 → done；博客/社媒在 require_approval 时 → awaiting_approval。
 */
class OpsTaskDispatchQueue implements QueueConsumerInterface
{
    public function name(): string
    {
        return (string)__('Ops 任务调度队列');
    }

    public function attributes(): array
    {
        return [];
    }

    public function tip(): string
    {
        return (string)__('按 OpsTask 指派智能体异步执行；外发须审批闸门。');
    }

    public function validate(QueueTaskContextInterface $queue): bool
    {
        $content = json_decode((string)$queue->getContent(), true);
        if (!is_array($content) || (int)($content['task_id'] ?? 0) < 1) {
            $queue->setResult((string)__('缺少 task_id'));

            return false;
        }
        $taskId = (int)$content['task_id'];
        /** @var OpsTaskService $tasks */
        $tasks = ObjectManager::getInstance(OpsTaskService::class);
        $got = $tasks->get($taskId);
        if (!($got['ok'] ?? false)) {
            $queue->setResult((string)__('任务不存在'));

            return false;
        }
        $status = (string)(($got['task']['status'] ?? ''));
        if ($status !== OpsTask::STATUS_QUEUED) {
            $queue->setResult((string)__('任务状态不是 queued'));

            return false;
        }

        return true;
    }

    public function execute(QueueTaskContextInterface $queue): string
    {
        $content = json_decode((string)$queue->getContent(), true);
        if (!is_array($content)) {
            return (string)__('无效队列内容');
        }
        $taskId = (int)($content['task_id'] ?? 0);
        $sessionId = trim((string)($content['session_id'] ?? ''));

        /** @var OpsTaskService $tasks */
        $tasks = ObjectManager::getInstance(OpsTaskService::class);
        /** @var OpsSessionService $sessions */
        $sessions = ObjectManager::getInstance(OpsSessionService::class);
        /** @var OpsRunLogService $logs */
        $logs = ObjectManager::getInstance(OpsRunLogService::class);

        $claim = $tasks->claimRunning($taskId);
        if (!($claim['ok'] ?? false)) {
            return (string)($claim['message'] ?? __('领取失败'));
        }
        $task = $claim['task'] ?? [];
        $websiteId = (int)($task['website_id'] ?? 0);
        $assignee = trim((string)($task['assignee_agent'] ?? ($content['assignee_agent'] ?? '')));
        if ($sessionId === '') {
            $sessionId = (string)($task['session_id'] ?? '');
        }

        $sessionGot = $sessions->get($sessionId);
        if (!($sessionGot['ok'] ?? false)) {
            $tasks->setStatus($taskId, OpsTask::STATUS_FAILED);
            $logs->write($websiteId, OpsRunLog::KIND_DRAFT, 'error', 'session missing', '', [
                'task_id' => $taskId,
                'session_id' => $sessionId,
            ]);

            return (string)__('会话不存在');
        }

        if ($assignee === '') {
            $tasks->setStatus($taskId, OpsTask::STATUS_FAILED);

            return (string)__('缺少指派智能体');
        }

        $prompt = $this->buildPrompt($assignee, $taskId, $websiteId, $sessionId);

        try {
            /** @var AiRuntimeInterface $runtime */
            $runtime = ObjectManager::getInstance(AiRuntimeInterface::class);
            $result = $runtime->executeAgent($assignee, $prompt, null, [
                'website_id' => $websiteId,
                'session_id' => $sessionId,
                'task_id' => $taskId,
                'task' => $task,
                'session' => $sessionGot['session'] ?? [],
            ]);
        } catch (\Throwable $e) {
            $tasks->setStatus($taskId, OpsTask::STATUS_FAILED);
            $logs->write($websiteId, $this->logKind($assignee), 'error', $e->getMessage(), '', [
                'task_id' => $taskId,
            ]);

            return $e->getMessage();
        }

        $success = is_object($result) && !empty($result->success);
        $agentPayload = [];
        if (is_object($result) && isset($result->messages) && is_array($result->messages)) {
            foreach ($result->messages as $msg) {
                if (is_array($msg) && isset($msg['ops_result']) && is_array($msg['ops_result'])) {
                    $agentPayload = $msg['ops_result'];
                    break;
                }
            }
        }
        if ($agentPayload !== []) {
            $tasks->mergePayload($taskId, $agentPayload);
        }

        $requireApproval = (bool)(($sessionGot['session']['context']['require_approval'] ?? true));
        $nextStatus = $this->resolveNextStatus($assignee, $success, $requireApproval);

        $tasks->setStatus($taskId, $nextStatus);
        $summary = is_object($result) ? (string)($result->content ?? '') : '';
        $logs->write(
            $websiteId,
            $this->logKind($assignee),
            $success ? 'ok' : 'error',
            $summary !== '' ? $summary : ('task ' . $taskId . ' → ' . $nextStatus),
            '',
            [
                'task_id' => $taskId,
                'session_id' => $sessionId,
                'assignee' => $assignee,
                'status' => $nextStatus,
                'payload' => $agentPayload,
            ],
        );

        return json_encode([
            'task_id' => $taskId,
            'status' => $nextStatus,
            'assignee' => $assignee,
            'success' => $success,
        ], JSON_UNESCAPED_UNICODE) ?: (string)__('完成');
    }

    private function buildPrompt(string $assignee, int $taskId, int $websiteId, string $sessionId): string
    {
        if ($assignee === OpsTask::AGENT_OPS_ANALYST) {
            return sprintf(
                'Run daily analysis for ops task %d website %d session %s. Write analysis only; do not publish.',
                $taskId,
                $websiteId,
                $sessionId,
            );
        }

        return sprintf(
            'Execute ops task %d for website %d using session %s. Create draft only; do not publish publicly. Prefer session.last_analysis if present; otherwise set analysis_missing in result.',
            $taskId,
            $websiteId,
            $sessionId,
        );
    }

    private function resolveNextStatus(string $assignee, bool $success, bool $requireApproval): string
    {
        if (!$success) {
            return OpsTask::STATUS_FAILED;
        }
        if ($assignee === OpsTask::AGENT_OPS_ANALYST) {
            return OpsTask::STATUS_DONE;
        }
        if ($requireApproval) {
            return OpsTask::STATUS_AWAITING_APPROVAL;
        }

        return OpsTask::STATUS_DONE;
    }

    private function logKind(string $assignee): string
    {
        return match ($assignee) {
            OpsTask::AGENT_OPS_ANALYST => OpsRunLog::KIND_DAILY_ANALYSIS,
            default => OpsRunLog::KIND_DRAFT,
        };
    }
}
