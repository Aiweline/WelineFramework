<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Cron;

use GuoLaiRen\PageBuilder\Model\AiSiteAgentSession;
use GuoLaiRen\PageBuilder\Service\AiSiteAgentSessionService;
use GuoLaiRen\PageBuilder\Service\AiSiteBuildTaskService;
use GuoLaiRen\PageBuilder\Service\AiSiteScopeCompatibilityService;
use Weline\Cron\CronTaskInterface;
use Weline\Queue\Model\Queue;

class QueueWatchdog implements CronTaskInterface
{
    private const MODULE_NAME = 'GuoLaiRen_PageBuilder';
    private const WATCHDOG_RETRY_KEY = 'watchdog_retry_count';
    private const WATCHDOG_EXHAUSTED_KEY = 'watchdog_retry_exhausted';
    private const MAX_RETRY_COUNT = 3;

    public function __construct(
        private readonly Queue $queueModel,
        private readonly AiSiteAgentSessionService $sessionService,
        private readonly AiSiteScopeCompatibilityService $scopeCompatibilityService,
        private readonly AiSiteBuildTaskService $buildTaskService,
    ) {
    }

    public function name(): string
    {
        return 'PageBuilder 队列巡检与自动重试';
    }

    public function execute_name(): string
    {
        return 'pagebuilder:queue-watchdog';
    }

    public function tip(): string
    {
        return '每分钟巡检一次 PageBuilder 构建队列；当队列已结束但构建任务未完成时，自动回退任务与队列到 pending，最多重试 3 次。';
    }

    public function cron_time(): string
    {
        return '*/1 * * * *';
    }

    public function execute(): string
    {
        foreach ($this->loadTerminalBuildQueues() as $queue) {
            $this->repairQueueIfNeeded($queue);
        }

        return 'OK';
    }

    public function unlock_timeout(int $minute = 30): int
    {
        return 120;
    }

    /**
     * @return list<Queue>
     */
    private function loadTerminalBuildQueues(): array
    {
        return $this->queueModel->reset()
            ->where(Queue::schema_fields_module, self::MODULE_NAME)
            ->where(Queue::schema_fields_auto, 1)
            ->where(Queue::schema_fields_status, [
                Queue::status_done,
                Queue::status_error,
                Queue::status_stop,
            ], 'IN')
            ->order(Queue::schema_fields_ID, 'DESC')
            ->select()
            ->fetch()
            ->getItems();
    }

    private function repairQueueIfNeeded(Queue $queue): void
    {
        if (!$this->isLatestQueueForBizKey($queue)) {
            return;
        }

        $content = $this->decodeQueueContent($queue);
        if (!$this->isBuildOperationQueue($queue, $content)) {
            return;
        }

        $publicId = \trim((string)($content['public_id'] ?? ''));
        $adminId = (int)($content['admin_id'] ?? 0);
        if ($publicId === '' || $adminId <= 0) {
            return;
        }

        $session = $this->sessionService->loadByPublicId($publicId, $adminId);
        if (!$session instanceof AiSiteAgentSession) {
            return;
        }

        $scope = $this->scopeCompatibilityService->normalizeScope(
            $this->sessionService->loadScopeForStage($session, AiSiteAgentSession::STAGE_VISUAL_EDIT)
        );
        $finalizedScope = $this->buildTaskService->finalizeBuildTaskStatesAfterRunLoop($scope);
        if ($finalizedScope !== $scope) {
            $this->sessionService->replaceScope((int)$session->getId(), $adminId, $finalizedScope);
            $session = $this->sessionService->loadByPublicId($publicId, $adminId) ?? $session;
            $scope = $this->scopeCompatibilityService->normalizeScope(
                $this->sessionService->loadScopeForStage($session, AiSiteAgentSession::STAGE_VISUAL_EDIT)
            );
        } else {
            $scope = $finalizedScope;
        }
        $completionGate = $this->buildTaskService->inspectBuildCompletionGate($scope);
        if (!empty($completionGate['passed'])) {
            $this->markGatePassedQueueComplete($queue, $content, $session, $adminId, $scope, $completionGate);
            return;
        }
        if (!$this->shouldRetryBuildQueue($completionGate)) {
            return;
        }

        $retryCount = \max(0, (int)($content[self::WATCHDOG_RETRY_KEY] ?? 0));
        if ($retryCount >= self::MAX_RETRY_COUNT) {
            $this->markRetryExhausted($queue, $content, $session, $adminId, $completionGate);
            return;
        }

        $nextRetryCount = $retryCount + 1;
        $message = $this->buildRetryMessage($completionGate);

        $repairedScope = $this->buildTaskService->resetUnfinishedTasksForQueueRetry($scope, $message);
        $repairedScope = $this->patchBuildActiveOperationForRetry($repairedScope, (int)$queue->getId(), $nextRetryCount, $message);
        $this->sessionService->replaceScope((int)$session->getId(), $adminId, $repairedScope);
        $this->sessionService->appendEvent(
            (int)$session->getId(),
            $adminId,
            'pagebuilder_queue_watchdog_retry',
            [
                'queue_id' => (int)$queue->getId(),
                'retry_count' => $nextRetryCount,
                'retry_limit' => self::MAX_RETRY_COUNT,
                'message' => $message,
            ],
            AiSiteAgentSession::STAGE_VISUAL_EDIT
        );

        $content[self::WATCHDOG_RETRY_KEY] = $nextRetryCount;
        $content['watchdog_last_reset_at'] = \date('Y-m-d H:i:s');
        $content['watchdog_last_reason'] = (string)($completionGate['reason'] ?? 'completion_gate_failed');
        $content[self::WATCHDOG_EXHAUSTED_KEY] = 0;
        $this->resetQueueToPending($queue, $content, $message);
    }

    /**
     * @param array<string, mixed> $completionGate
     */
    private function buildRetryMessage(array $completionGate): string
    {
        return (string)__(
            '队列巡检检测到构建队列已结束，但完成门禁未通过（reason=%{1}，未完成=%{2}/%{3}，无效产物=%{4}）；已回退为 pending，等待系统下一轮调度。',
            [
                (string)($completionGate['reason'] ?? 'completion_gate_failed'),
                (string)(int)($completionGate['unfinished'] ?? 0),
                (string)(int)($completionGate['total'] ?? 0),
                (string)(int)($completionGate['invalid_artifacts'] ?? 0),
            ]
        );
    }

    /**
     * @param array<string, mixed> $completionGate
     */
    private function buildRetryExhaustedMessage(array $completionGate): string
    {
        return (string)__(
            '队列巡检已达到最大自动重试次数 %{1}；完成门禁仍未通过（reason=%{2}，未完成=%{3}/%{4}，无效产物=%{5}），已停止自动重试。',
            [
                (string)self::MAX_RETRY_COUNT,
                (string)($completionGate['reason'] ?? 'completion_gate_failed'),
                (string)(int)($completionGate['unfinished'] ?? 0),
                (string)(int)($completionGate['total'] ?? 0),
                (string)(int)($completionGate['invalid_artifacts'] ?? 0),
            ]
        );
    }

    /**
     * @param array<string, mixed> $completionGate
     */
    private function shouldRetryBuildQueue(array $completionGate): bool
    {
        $total = (int)($completionGate['total'] ?? 0);

        return $total > 0 && empty($completionGate['passed']);
    }

    /**
     * @param array<string, mixed> $content
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $completionGate
     */
    private function markGatePassedQueueComplete(
        Queue $queue,
        array $content,
        AiSiteAgentSession $session,
        int $adminId,
        array $scope,
        array $completionGate
    ): void {
        if ((string)$queue->getStatus() === Queue::status_stop) {
            return;
        }

        $now = \date('Y-m-d H:i:s');
        $message = (string)__(
            '构建完成门禁已通过；队列巡检已按统一门禁修正终态为完成。'
        );
        $operationState = [
            'operation' => 'build',
            'status' => 'done',
            'queue_id' => (int)$queue->getId(),
            'execution_token' => \trim((string)($content['execution_token'] ?? '')),
            'message' => $message,
            'updated_at' => $now,
            'finished_at' => $now,
            'progress_percent' => 100,
            'failure_mode' => '',
            'retry_allowed' => 0,
            'retryable_ai_failure_count' => 0,
            'queue_waiting_for_scheduler' => false,
            'can_close_stream' => true,
            'continue_other_operations' => false,
        ];

        $scope['active_operation'] = \array_replace(
            \is_array($scope['active_operation'] ?? null) ? $scope['active_operation'] : [],
            $operationState
        );
        $activeOperations = \is_array($scope['active_operations'] ?? null) ? $scope['active_operations'] : [];
        $activeOperations['build'] = \array_replace(
            \is_array($activeOperations['build'] ?? null) ? $activeOperations['build'] : [],
            $operationState
        );
        $scope['active_operations'] = $activeOperations;
        $scope = $this->buildTaskService->clearRetryableAiFailures($scope, 'build');
        $scope['workspace_status'] = AiSiteScopeCompatibilityService::WORKSPACE_STATUS_CAN_PUBLISH;
        $scope['can_publish'] = 1;
        $scope['site_ready'] = 1;
        $scope['latest_build_failed'] = 0;
        $scope['latest_build_failure'] = [];
        $scope['publish_blocked_by_latest_ai_failure'] = 0;
        $scope['publish_blocked_reason'] = '';
        $scope['next_stage_blocked_by_ai_failures'] = 0;
        $scope['build_task_summary'] = \is_array($completionGate['summary'] ?? null) ? $completionGate['summary'] : [];
        $buildSummary = \is_array($scope['build_summary'] ?? null) ? $scope['build_summary'] : [];
        $buildSummary['can_publish'] = true;
        $buildSummary['active_operation'] = 'build';
        $buildSummary['last_generated_at'] = $now;
        $buildSummary['completion_gate'] = \array_diff_key($completionGate, ['summary' => true]);
        $scope['build_summary'] = $buildSummary;
        $this->sessionService->replaceScope((int)$session->getId(), $adminId, $scope);

        $content[self::WATCHDOG_EXHAUSTED_KEY] = 0;
        $content['watchdog_gate_promoted_at'] = $now;
        $queue->setStatus(Queue::status_done)
            ->setFinished(true)
            ->setPid(0)
            ->setContent($this->encodeQueueContent($content))
            ->setResult($this->appendQueueMessage(\trim((string)$queue->getResult()), $message))
            ->setProcess($message)
            ->save();

        $this->sessionService->appendEvent(
            (int)$session->getId(),
            $adminId,
            'pagebuilder_queue_watchdog_gate_passed',
            [
                'queue_id' => (int)$queue->getId(),
                'message' => $message,
                'completion_gate' => \array_diff_key($completionGate, ['summary' => true]),
            ],
            AiSiteAgentSession::STAGE_VISUAL_EDIT
        );
    }

    /**
     * @param array<string, mixed> $scope
     * @return array<string, mixed>
     */
    private function patchBuildActiveOperationForRetry(array $scope, int $queueId, int $retryCount, string $message): array
    {
        $activeOperation = \is_array($scope['active_operation'] ?? null) ? $scope['active_operation'] : [];
        $startedAt = \trim((string)($activeOperation['started_at'] ?? ''));
        if ($startedAt === '') {
            $startedAt = \date('Y-m-d H:i:s');
        }
        $operationState = \array_replace($activeOperation, [
            'operation' => 'build',
            'status' => 'queued',
            'queue_id' => $queueId,
            'message' => $message,
            'queue_waiting_for_scheduler' => true,
            'started_at' => $startedAt,
            'updated_at' => \date('Y-m-d H:i:s'),
            'watchdog_retry_count' => $retryCount,
        ]);

        $scope['active_operation'] = $operationState;
        $activeOperations = \is_array($scope['active_operations'] ?? null) ? $scope['active_operations'] : [];
        $activeOperations['build'] = $operationState;
        $scope['active_operations'] = $activeOperations;

        return $scope;
    }

    /**
     * @param array<string, mixed> $content
     */
    private function resetQueueToPending(Queue $queue, array $content, string $message): void
    {
        $existingResult = \trim((string)$queue->getResult());
        $existingProcess = \trim((string)$queue->getProcess());
        $queue->setStatus(Queue::status_pending)
            ->setFinished(false)
            ->setPid(0)
            ->setData(Queue::schema_fields_start_at, null)
            ->setData(Queue::schema_fields_end_at, null)
            ->setContent($this->encodeQueueContent($content))
            ->setResult($this->appendQueueMessage($existingResult, $message))
            ->setProcess($this->appendQueueMessage($existingProcess, $message))
            ->save();
    }

    /**
     * @param array<string, mixed> $content
     * @param array<string, mixed> $completionGate
     */
    private function markRetryExhausted(
        Queue $queue,
        array $content,
        AiSiteAgentSession $session,
        int $adminId,
        array $completionGate
    ): void {
        if ((int)($content[self::WATCHDOG_EXHAUSTED_KEY] ?? 0) === 1) {
            return;
        }

        $message = $this->buildRetryExhaustedMessage($completionGate);

        $scope = $this->scopeCompatibilityService->normalizeScope(
            $this->sessionService->loadScopeForStage($session, AiSiteAgentSession::STAGE_VISUAL_EDIT)
        );
        $scope = $this->patchBuildActiveOperationExhausted($scope, (int)$queue->getId(), $message, $completionGate);
        $this->sessionService->replaceScope((int)$session->getId(), $adminId, $scope);
        $this->sessionService->appendEvent(
            (int)$session->getId(),
            $adminId,
            'pagebuilder_queue_watchdog_exhausted',
            [
                'queue_id' => (int)$queue->getId(),
                'retry_limit' => self::MAX_RETRY_COUNT,
                'message' => $message,
            ],
            AiSiteAgentSession::STAGE_VISUAL_EDIT,
            'warning'
        );

        $content[self::WATCHDOG_EXHAUSTED_KEY] = 1;
        $content['watchdog_exhausted_at'] = \date('Y-m-d H:i:s');
        $queue->setStatus(Queue::status_error)
            ->setFinished(true)
            ->setPid(0)
            ->setContent($this->encodeQueueContent($content))
            ->setResult($this->appendQueueMessage(\trim((string)$queue->getResult()), $message))
            ->setProcess($this->appendQueueMessage(\trim((string)$queue->getProcess()), $message))
            ->save();
    }

    /**
     * @param array<string, mixed> $scope
     * @return array<string, mixed>
     */
    private function patchBuildActiveOperationExhausted(array $scope, int $queueId, string $message, array $completionGate): array
    {
        $activeOperation = \is_array($scope['active_operation'] ?? null) ? $scope['active_operation'] : [];
        $operationState = \array_replace($activeOperation, [
            'operation' => 'build',
            'status' => 'error',
            'queue_id' => $queueId,
            'message' => $message,
            'queue_waiting_for_scheduler' => false,
            'updated_at' => \date('Y-m-d H:i:s'),
            'watchdog_retry_count' => self::MAX_RETRY_COUNT,
        ]);

        $scope['active_operation'] = $operationState;
        $activeOperations = \is_array($scope['active_operations'] ?? null) ? $scope['active_operations'] : [];
        $activeOperations['build'] = $operationState;
        $scope['active_operations'] = $activeOperations;
        $scope['workspace_status'] = AiSiteScopeCompatibilityService::WORKSPACE_STATUS_FAILED;
        $scope['can_publish'] = 0;
        $scope['site_ready'] = 0;
        $scope['latest_build_failed'] = 1;
        $scope['latest_build_failure'] = [
            'operation' => 'build',
            'queue_id' => $queueId,
            'message' => $message,
            'reason' => (string)($completionGate['reason'] ?? 'completion_gate_failed'),
            'failed_at' => \date('Y-m-d H:i:s'),
        ];
        $scope['publish_blocked_by_latest_ai_failure'] = 1;
        $scope['publish_blocked_reason'] = $message;
        $scope['build_task_summary'] = \is_array($completionGate['summary'] ?? null) ? $completionGate['summary'] : [];
        $buildSummary = \is_array($scope['build_summary'] ?? null) ? $scope['build_summary'] : [];
        $buildSummary['can_publish'] = false;
        $buildSummary['active_operation'] = 'build';
        $buildSummary['completion_gate'] = \array_diff_key($completionGate, ['summary' => true]);
        $scope['build_summary'] = $buildSummary;

        return $scope;
    }

    private function isLatestQueueForBizKey(Queue $queue): bool
    {
        $bizKey = \trim($queue->getBizKey());
        if ($bizKey === '') {
            return false;
        }

        /** @var Queue $latest */
        $latest = clone $this->queueModel;
        $latest->clearData()->clearQuery()
            ->where(Queue::schema_fields_BIZ_KEY, $bizKey)
            ->order(Queue::schema_fields_ID, 'DESC')
            ->limit(1)
            ->find()
            ->fetch();

        return (int)$latest->getId() === (int)$queue->getId();
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeQueueContent(Queue $queue): array
    {
        $raw = \trim((string)$queue->getContent());
        if ($raw === '') {
            return [];
        }

        try {
            $decoded = \json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        return \is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string, mixed> $content
     */
    private function isBuildOperationQueue(Queue $queue, array $content): bool
    {
        $operation = \trim((string)($content['operation'] ?? ''));
        if ($operation !== '') {
            return $operation === 'build';
        }

        $bizKey = \trim($queue->getBizKey());
        return \str_contains($bizKey, ':queue_slot:build') || \str_contains($bizKey, ':operation:build');
    }

    /**
     * @param array<string, mixed> $content
     */
    private function encodeQueueContent(array $content): string
    {
        try {
            return (string)\json_encode(
                $content,
                \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_INVALID_UTF8_SUBSTITUTE | \JSON_THROW_ON_ERROR
            );
        } catch (\JsonException) {
            return '{}';
        }
    }

    private function appendQueueMessage(string $existing, string $message): string
    {
        if ($existing === '') {
            return $message;
        }

        return $existing . PHP_EOL . '[' . \date('Y-m-d H:i:s') . '] ' . $message;
    }
}
