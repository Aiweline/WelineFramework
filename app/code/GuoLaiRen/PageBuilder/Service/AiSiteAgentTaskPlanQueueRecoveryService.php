<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Service;

use GuoLaiRen\PageBuilder\Model\AiSiteAgentSession;
use Weline\Queue\Model\Queue;

/**
 * Observes task-plan queue recovery state without creating or dispatching queues.
 */
class AiSiteAgentTaskPlanQueueRecoveryService
{
    /**
     * @param array<string, mixed> $normalized
     * @param array<string, mixed> $activeOperation
     * @param array<string, mixed>|null $taskPlanQueueInfo
     * @return array{normalized:array<string,mixed>,active_operation:array<string,mixed>,task_plan_queue_info:array<string,mixed>|null}
     */
    public function autoRerunTaskPlanQueueWhenQueueDoneButDraftMissing(
        AiSiteAgentSession $session,
        int $adminId,
        string $currentStage,
        string $stageVisualEdit,
        array $normalized,
        array $activeOperation,
        ?array $taskPlanQueueInfo,
        AiSiteAgentTaskPlanQueueRecoveryPorts $ports
    ): array {
        if ($currentStage !== $stageVisualEdit) {
            return [
                'normalized' => $normalized,
                'active_operation' => $activeOperation,
                'task_plan_queue_info' => $taskPlanQueueInfo,
            ];
        }
        if (!($ports->isTaskPlanDraftMissing)($normalized)) {
            return [
                'normalized' => $normalized,
                'active_operation' => $activeOperation,
                'task_plan_queue_info' => $taskPlanQueueInfo,
            ];
        }

        $queueSnapshot = \is_array($taskPlanQueueInfo['snapshot'] ?? null) ? $taskPlanQueueInfo['snapshot'] : [];
        $queueStatus = \trim((string)($queueSnapshot['status'] ?? ''));
        $queueId = (int)($taskPlanQueueInfo['queue_id'] ?? $queueSnapshot['queue_id'] ?? 0);
        if ($queueStatus === '' && $queueId <= 0) {
            $fallbackQueueRow = ($ports->findQueueRow)($session, 'task_plan', 0);
            if (\is_array($fallbackQueueRow) && $fallbackQueueRow !== []) {
                $queueStatus = \trim((string)($fallbackQueueRow['status'] ?? ''));
                $queueId = (int)($fallbackQueueRow['queue_id'] ?? 0);
            }
        }

        $activeOperationName = \trim((string)($activeOperation['operation'] ?? ''));
        $activeOperationStatus = \trim((string)($activeOperation['status'] ?? ''));
        $queueInProgress = \in_array($queueStatus, ['pending', 'queued', 'running'], true);
        $activeInProgress = $activeOperationName === 'task_plan'
            && \in_array($activeOperationStatus, ['queued', 'running'], true);

        if ($queueInProgress || ($queueStatus === '' && $activeInProgress)) {
            return [
                'normalized' => $normalized,
                'active_operation' => $activeOperation,
                'task_plan_queue_info' => $taskPlanQueueInfo,
            ];
        }

        $allowRecoveryInit = $activeOperationName === 'task_plan' || $queueId > 0;
        if (!$allowRecoveryInit) {
            return [
                'normalized' => $normalized,
                'active_operation' => $activeOperation,
                'task_plan_queue_info' => $taskPlanQueueInfo,
            ];
        }

        $terminalStatuses = ['done', 'complete', 'completed', 'error', 'failed', 'fail', 'stop', 'stopped', 'cancelled', 'canceled'];
        if ($queueId > 0 && !\in_array($queueStatus, $terminalStatuses, true)) {
            return [
                'normalized' => $normalized,
                'active_operation' => $activeOperation,
                'task_plan_queue_info' => $taskPlanQueueInfo,
            ];
        }

        $originalQueueId = $queueId;
        $isCreateNewQueue = $queueId <= 0;
        $reason = $isCreateNewQueue
            ? 'queue_missing_and_draft_missing_create_new_queue'
            : (\in_array($queueStatus, ['error', 'failed', 'fail', 'stop', 'stopped', 'cancelled', 'canceled'], true)
                ? 'queue_failed_or_stopped_and_draft_missing_reuse_queue'
                : 'queue_done_but_draft_missing_reuse_queue');

        try {
            $executionToken = \bin2hex(\random_bytes(16));
            $newQueueId = ($ports->enqueueTask)($session, $adminId, 'task_plan', $executionToken, ['_force_rebuild' => 1]);
            if ($newQueueId <= 0) {
                return [
                    'normalized' => $normalized,
                    'active_operation' => $activeOperation,
                    'task_plan_queue_info' => $taskPlanQueueInfo,
                ];
            }
            $queueId = $newQueueId;
            $active = \array_replace(
                ($ports->buildOperationEnvelope)($session, 'task_plan', $executionToken, 'queued'),
                [
                    'operation' => 'task_plan',
                    'status' => 'queued',
                    'queue_id' => $queueId,
                    'execution_token' => $executionToken,
                    'task_plan_recovery_action' => $isCreateNewQueue ? 'created_queue' : 'reused_queue',
                    'updated_at' => \date('Y-m-d H:i:s'),
                ]
            );
            $normalized['active_operation'] = $active;
            $normalized['active_operations'] = \is_array($normalized['active_operations'] ?? null) ? $normalized['active_operations'] : [];
            $normalized['active_operations']['task_plan'] = $active;

            ($ports->mergeSessionScope)((int)$session->getId(), $adminId, [
                'active_operation' => $active,
                'active_operations' => ['task_plan' => $active],
            ]);

            $dispatch = \is_callable($ports->ensureWorkerDispatched)
                ? ($ports->ensureWorkerDispatched)($session, $adminId, 'task_plan', $queueId, $executionToken, true)
                : ['started' => false, 'attempted' => false, 'pid' => 0];
            $fresh = ($ports->loadSession)((int)$session->getId(), $adminId) ?? $session;
            $queueInfo = ($ports->buildQueueInfoPayload)($fresh, $active, 'task_plan');
            $queueInfo = \is_array($queueInfo) ? $queueInfo : [];
            $queueInfo['queue_id'] = $queueId;
            $queueInfo['queue_status'] = Queue::status_pending;
            $queueInfo['snapshot'] = \is_array($queueInfo['snapshot'] ?? null) ? $queueInfo['snapshot'] : [];
            $queueInfo['snapshot']['queue_id'] = $queueId;
            $queueInfo['snapshot']['status'] = Queue::status_pending;

            ($ports->logSse)('task_plan_queue_auto_rerun', [
                'session_id' => (int)$session->getId(),
                'admin_id' => $adminId,
                'queue_id' => $queueId,
                'reason' => $reason,
                'dispatch_started' => !empty($dispatch['started']) ? 1 : 0,
                'dispatch_pid' => (int)($dispatch['pid'] ?? 0),
            ], 'info');

            return [
                'normalized' => $normalized,
                'active_operation' => $active,
                'task_plan_queue_info' => $queueInfo,
            ];
        } catch (\Throwable $throwable) {
            ($ports->logSse)('task_plan_queue_auto_rerun_failed', [
                'session_id' => (int)$session->getId(),
                'admin_id' => $adminId,
                'queue_id' => $originalQueueId,
                'error' => $throwable->getMessage(),
            ], 'error');
        }

        return [
            'normalized' => $normalized,
            'active_operation' => $activeOperation,
            'task_plan_queue_info' => $taskPlanQueueInfo,
        ];
    }
}
