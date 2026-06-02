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
        $queueState = $this->resolveQueueCurrentState($queueInfo);
        if ($queueState === []) {
            return ['show' => false];
        }

        $queueId = (int)($queueState['queue_id'] ?? 0);
        $queueStatus = \trim((string)($queueState['status'] ?? $queueState['queue_status'] ?? $queueState['job_status'] ?? ''));
        $activeStatus = \trim((string)($activeOperation['status'] ?? ''));
        $status = $queueStatus !== '' ? $queueStatus : $activeStatus;
        if ($queueId <= 0 || $status === '') {
            return ['show' => false];
        }

        $operationLabel = $this->operationLabel($operation);
        $statusLabel = $this->statusLabel($status);
        $level = $this->noticeLevel($status);
        $resultLog = \trim((string)($queueInfo['result_log'] ?? ''));
        $reloadOnAck = $operation === 'plan' && $this->isFailureStatus($status);

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
            'queue_name' => (string)($queueState['name'] ?? ''),
            'biz_key' => (string)($queueState['biz_key'] ?? ''),
            'process' => \trim((string)($queueInfo['process'] ?? '')),
            'result_excerpt' => $resultLog !== '' ? \mb_substr($resultLog, -600) : '',
            'ack_action' => $reloadOnAck ? 'reload_workspace' : '',
            'reload_on_ack' => $reloadOnAck ? 1 : 0,
            'confirm_label' => (string)__('知道了'),
        ];
    }

    private function normalizeOperation(string $operation): string
    {
        $operation = \trim($operation);
        if ($operation === 'block_refine') {
            $operation = 'block_regenerate';
        }

        return \in_array($operation, [
            'plan',
            'build',
            'publish',
            'regenerate_page',
            'block_regenerate',
            'block_partial_patch',
            'image_asset',
        ], true) ? $operation : '';
    }

    /**
     * @param array<string, mixed>|null $queueInfo
     * @return array<string, mixed>
     */
    private function resolveQueueCurrentState(?array $queueInfo): array
    {
        if ($queueInfo === null) {
            return [];
        }
        $current = $queueInfo;
        unset($current['snapshot']);

        return $current;
    }

    /**
     * @param array<string, array<string, mixed>|null> $queueInfoByOperation
     */
    private function selectFallbackOperation(array $queueInfoByOperation): string
    {
        foreach (['publish', 'image_asset', 'block_partial_patch', 'block_regenerate', 'regenerate_page', 'build', 'plan'] as $operation) {
            $queueInfo = \is_array($queueInfoByOperation[$operation] ?? null) ? $queueInfoByOperation[$operation] : null;
            $queueState = $this->resolveQueueCurrentState($queueInfo);
            $status = \trim((string)($queueState['status'] ?? $queueState['queue_status'] ?? $queueState['job_status'] ?? ''));
            if ($queueInfo !== null && \in_array($status, ['running', 'pending', 'queued', 'error'], true)) {
                return $operation;
            }
        }

        return '';
    }

    private function operationLabel(string $operation): string
    {
        return match ($operation) {
            'regenerate_page' => (string)__('页面重新生成'),
            'block_regenerate' => (string)__('区块重新生成'),
            'block_partial_patch' => (string)__('区块局部修改'),
            'image_asset' => (string)__('图片资源生成'),
            'plan' => (string)__('方案生成'),
            'build' => (string)__('生成主题'),
            'publish' => (string)__('发布站点'),
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
            'error', 'failed', 'fail' => 'error',
            'done', 'complete', 'completed' => 'success',
            'pending', 'queued', 'running' => 'warning',
            default => 'info',
        };
    }

    private function isFailureStatus(string $status): bool
    {
        return \in_array(\strtolower(\trim($status)), ['error', 'failed', 'fail'], true);
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
