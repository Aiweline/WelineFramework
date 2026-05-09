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
        $summary = $this->buildTaskService->summarize($scope);
        if (!$this->shouldRetryBuildQueue($summary)) {
            return;
        }

        $retryCount = \max(0, (int)($content[self::WATCHDOG_RETRY_KEY] ?? 0));
        if ($retryCount >= self::MAX_RETRY_COUNT) {
            $this->markRetryExhausted($queue, $content, $session, $adminId, $summary);
            return;
        }

        $nextRetryCount = $retryCount + 1;
        $message = (string)__(
            '队列巡检检测到构建队列已结束，但仍有 %{1}/%{2} 个任务未完成；已回退为 pending，等待系统下一轮调度。',
            [
                (string)((int)($summary['total'] ?? 0) - (int)($summary['done'] ?? 0)),
                (string)(int)($summary['total'] ?? 0),
            ]
        );

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
        $content['watchdog_last_reason'] = 'unfinished_build_tasks';
        $content[self::WATCHDOG_EXHAUSTED_KEY] = 0;
        $this->resetQueueToPending($queue, $content, $message);
    }

    /**
     * @param array<string, mixed> $summary
     */
    private function shouldRetryBuildQueue(array $summary): bool
    {
        $total = (int)($summary['total'] ?? 0);
        $done = (int)($summary['done'] ?? 0);

        return $total > 0 && $done < $total;
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
     * @param array<string, mixed> $summary
     */
    private function markRetryExhausted(
        Queue $queue,
        array $content,
        AiSiteAgentSession $session,
        int $adminId,
        array $summary
    ): void {
        if ((int)($content[self::WATCHDOG_EXHAUSTED_KEY] ?? 0) === 1) {
            return;
        }

        $message = (string)__(
            '队列巡检已达到最大自动重试次数 %{1}；当前仍有 %{2}/%{3} 个构建任务未完成，已停止自动重试。',
            [
                (string)self::MAX_RETRY_COUNT,
                (string)((int)($summary['total'] ?? 0) - (int)($summary['done'] ?? 0)),
                (string)(int)($summary['total'] ?? 0),
            ]
        );

        $scope = $this->scopeCompatibilityService->normalizeScope(
            $this->sessionService->loadScopeForStage($session, AiSiteAgentSession::STAGE_VISUAL_EDIT)
        );
        $scope = $this->patchBuildActiveOperationExhausted($scope, (int)$queue->getId(), $message);
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
        $queue->setContent($this->encodeQueueContent($content))
            ->setResult($this->appendQueueMessage(\trim((string)$queue->getResult()), $message))
            ->setProcess($this->appendQueueMessage(\trim((string)$queue->getProcess()), $message))
            ->save();
    }

    /**
     * @param array<string, mixed> $scope
     * @return array<string, mixed>
     */
    private function patchBuildActiveOperationExhausted(array $scope, int $queueId, string $message): array
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
