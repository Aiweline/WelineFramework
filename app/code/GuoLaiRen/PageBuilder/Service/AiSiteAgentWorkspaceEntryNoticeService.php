<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Service;

/**
 * Builds the workspace entry queue notice without triggering queue execution.
 */
class AiSiteAgentWorkspaceEntryNoticeService
{
    /**
     * @param array<string, mixed> $activeOperation
     * @param array<string, array<string, mixed>|null> $queueInfoByOperation
     * @return array<string, mixed>
     */
    public function buildWorkspaceEntryQueueNotice(
        array $activeOperation,
        array $queueInfoByOperation,
        ?\Closure $ignoredRecoveryAction = null
    ): array {
        $operation = $this->normalizeOperation((string)($activeOperation['operation'] ?? ''));
        if ($operation === '') {
            $operation = $this->selectFallbackOperation($queueInfoByOperation);
        }

        if ($operation === '') {
            return ['show' => false];
        }

        $queueInfo = \is_array($queueInfoByOperation[$operation] ?? null)
            ? $queueInfoByOperation[$operation]
            : null;
        if ($queueInfo === null || !\is_array($queueInfo['snapshot'] ?? null)) {
            return ['show' => false];
        }

        $snapshot = $queueInfo['snapshot'];
        $queueId = (int)($queueInfo['queue_id'] ?? $snapshot['queue_id'] ?? 0);
        $queueStatus = \trim((string)($snapshot['status'] ?? ''));
        $activeStatus = \trim((string)($activeOperation['status'] ?? ''));
        $status = $queueStatus !== '' ? $queueStatus : $activeStatus;
        if ($queueId <= 0 || $status === '') {
            return ['show' => false];
        }

        $operationLabel = $this->operationLabel($operation);
        $statusLabel = $this->statusLabel($status);
        $level = $this->noticeLevel($status);
        $resultLog = \trim((string)($queueInfo['result_log'] ?? ''));

        return [
            'show' => true,
            'level' => $level,
            'title' => (string)__('已读取队列状态'),
            'message' => $this->messageForStatus($status, $operationLabel, $queueId, $statusLabel),
            'operation' => $operation,
            'operation_label' => $operationLabel,
            'queue_id' => $queueId,
            'queue_status' => $status,
            'queue_status_label' => $statusLabel,
            'queue_name' => (string)($snapshot['name'] ?? ''),
            'biz_key' => (string)($snapshot['biz_key'] ?? ''),
            'process' => \trim((string)($queueInfo['process'] ?? '')),
            'result_excerpt' => $resultLog !== '' ? \mb_substr($resultLog, -600) : '',
            'confirm_label' => (string)__('知道了'),
        ];
    }

    private function normalizeOperation(string $operation): string
    {
        $operation = \trim($operation);
        return \in_array($operation, ['plan', 'build'], true) ? $operation : '';
    }

    /**
     * @param array<string, array<string, mixed>|null> $queueInfoByOperation
     */
    private function selectFallbackOperation(array $queueInfoByOperation): string
    {
        foreach (['build', 'plan'] as $operation) {
            $queueInfo = \is_array($queueInfoByOperation[$operation] ?? null) ? $queueInfoByOperation[$operation] : null;
            $status = \trim((string)($queueInfo['snapshot']['status'] ?? ''));
            if ($queueInfo !== null && \in_array($status, ['running', 'pending', 'queued', 'error'], true)) {
                return $operation;
            }
        }

        return '';
    }

    private function operationLabel(string $operation): string
    {
        return match ($operation) {
            'plan' => (string)__('方案生成'),
            'build' => (string)__('生成主题'),
            default => (string)__('后台任务'),
        };
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            'pending', 'queued' => (string)__('待执行'),
            'running' => (string)__('执行中'),
            'done', 'complete', 'completed' => (string)__('已完成'),
            'error' => (string)__('失败'),
            'stop', 'stopped', 'cancelled', 'canceled' => (string)__('已取消'),
            default => $status,
        };
    }

    private function noticeLevel(string $status): string
    {
        return match ($status) {
            'error' => 'error',
            'done', 'complete', 'completed' => 'success',
            'pending', 'queued', 'running' => 'warning',
            default => 'info',
        };
    }

    private function messageForStatus(string $status, string $operationLabel, int $queueId, string $statusLabel): string
    {
        return match ($status) {
            'pending', 'queued' => (string)__('检测到 %{operation} 队列 #%{queue_id} 处于待执行状态，系统调度器会继续处理。', [
                'operation' => $operationLabel,
                'queue_id' => (string)$queueId,
            ]),
            'running' => (string)__('%{operation} 队列 #%{queue_id} 正在执行，请等待进度自动刷新。', [
                'operation' => $operationLabel,
                'queue_id' => (string)$queueId,
            ]),
            'error' => (string)__('%{operation} 队列 #%{queue_id} 执行失败，请查看队列日志后重试。', [
                'operation' => $operationLabel,
                'queue_id' => (string)$queueId,
            ]),
            'done', 'complete', 'completed' => (string)__('%{operation} 队列 #%{queue_id} 已完成，工作台已读取最新状态。', [
                'operation' => $operationLabel,
                'queue_id' => (string)$queueId,
            ]),
            default => (string)__('%{operation} 队列 #%{queue_id} 当前状态：%{status}。', [
                'operation' => $operationLabel,
                'queue_id' => (string)$queueId,
                'status' => $statusLabel,
            ]),
        };
    }
}
