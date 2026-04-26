<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Service;

use GuoLaiRen\PageBuilder\Model\AiSiteAgentSession;

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
        if ($currentStage !== $stageVisualEdit || !($ports->isTaskPlanDraftMissing)($normalized)) {
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
            ($ports->logSse)('task_plan_queue_waiting_for_scheduler', [
                'session_id' => (int)$session->getId(),
                'admin_id' => $adminId,
                'queue_id' => $queueId,
                'queue_status' => $queueStatus,
                'reason' => 'task_plan_queue_waiting_for_system_scheduler',
                'queue_waiting_for_scheduler' => 1,
                'can_close_stream' => 1,
                'continue_other_operations' => 1,
            ], 'info');
        }

        return [
            'normalized' => $normalized,
            'active_operation' => $activeOperation,
            'task_plan_queue_info' => $taskPlanQueueInfo,
        ];
    }
}
