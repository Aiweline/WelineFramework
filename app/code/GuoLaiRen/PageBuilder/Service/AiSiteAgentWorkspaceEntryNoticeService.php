<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Service;

/**
 * AiSiteAgentWorkspaceEntryNoticeService
 *
 * 从 `AiSiteAgent.php` 控制器抽出的 **工作区首屏队列通知构造器**：
 *  - `buildWorkspaceEntryQueueNotice`：根据 `active_operation` + 多 operation 的 queue info
 *    快照，产出 `{show, level, title, message, operation, queue_id, queue_status, ...}`
 *    结构，供工作区首屏 modal / toast 渲染。
 *
 * 设计：
 *  - **几乎纯函数**：仅依赖 PHP 原生 + `__()`；只有 1 个控制器私有 helper 依赖
 *    (`getTaskPlanQueueRecoveryAction`) 通过单 `\Closure` 端口注入，避免引入 Ports bag。
 *  - **i18n 保留原处**：`__('中文')` 在服务内部按原行为就地调用；这是控制器历史约定，
 *    本次 Characterization 锁定。
 *  - **不触发副作用**：纯构造；没有 SSE / 没有 w_query / 没有 session merge。
 */
class AiSiteAgentWorkspaceEntryNoticeService
{
    /**
     * 首屏队列通知构造：
     *
     * 决策链：
     *  1. 先取 `active_operation.operation` 对应的 queue info；若空，依次查
     *     `build / task_plan / plan` 里第一个处于 `running|pending|error` 的回退；
     *  2. 若仍无效或缺 snapshot → `{show: false}`；
     *  3. `queue_id<=0 || status==''` → `{show: false}`；
     *  4. task_plan + recovery action ∈ {created_queue, reused_queue} →
     *     产出**恢复型** notice（warning level + 固定 title + 条件拼装 message）；
     *  5. 否则走**通用型** notice（level/title/message 按 status match）。
     *
     * @param array<string, mixed> $activeOperation
     * @param array<string, array<string, mixed>|null> $queueInfoByOperation
     * @param \Closure(array): string $getTaskPlanRecoveryAction 返回
     *        `'created_queue' | 'reused_queue' | ''`
     *
     * @return array<string, mixed> 若 `show=false` 仅含此键；否则完整通知 payload
     */
    public function buildWorkspaceEntryQueueNotice(
        array $activeOperation,
        array $queueInfoByOperation,
        \Closure $getTaskPlanRecoveryAction
    ): array {
        $operation = \trim((string)($activeOperation['operation'] ?? ''));
        $queueInfo = ($operation !== '' && \is_array($queueInfoByOperation[$operation] ?? null))
            ? $queueInfoByOperation[$operation]
            : null;

        if ($queueInfo === null) {
            foreach (['build', 'task_plan', 'plan'] as $candidateOperation) {
                $candidate = \is_array($queueInfoByOperation[$candidateOperation] ?? null) ? $queueInfoByOperation[$candidateOperation] : null;
                $candidateStatus = \trim((string)($candidate['snapshot']['status'] ?? ''));
                if ($candidate !== null && \in_array($candidateStatus, ['running', 'pending', 'error'], true)) {
                    $operation = $candidateOperation;
                    $queueInfo = $candidate;
                    break;
                }
            }
        }

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

        $operationLabel = match ($operation) {
            'plan' => (string)__('阶段一方案'),
            'task_plan' => (string)__('第二阶段任务方案'),
            'build' => (string)__('生成主题'),
            default => (string)__('后台任务'),
        };
        $statusLabel = match ($status) {
            'pending', 'queued' => (string)__('待执行'),
            'running' => (string)__('执行中'),
            'done', 'complete', 'completed' => (string)__('已完成'),
            'error' => (string)__('失败'),
            'stop', 'stopped', 'cancelled', 'canceled' => (string)__('已取消'),
            default => $status,
        };
        $level = match ($status) {
            'error' => 'error',
            'done', 'complete', 'completed' => 'success',
            'pending', 'queued', 'running' => 'warning',
            default => 'info',
        };

        $process = \trim((string)($queueInfo['process'] ?? ''));
        $resultLog = \trim((string)($queueInfo['result_log'] ?? ''));
        $taskPlanRecoveryAction = $operation === 'task_plan'
            ? $getTaskPlanRecoveryAction($activeOperation)
            : '';
        if (\in_array($taskPlanRecoveryAction, ['created_queue', 'reused_queue'], true)) {
            $defaultRecoveryMessage = $taskPlanRecoveryAction === 'reused_queue'
                ? (string)__('检测到第二阶段方案为空，已重跑原队列 #%{queue_id}。', ['queue_id' => (string)$queueId])
                : (string)__('检测到第二阶段方案为空，已初始化队列 #%{queue_id} 并开始执行。', ['queue_id' => (string)$queueId]);
            $activeMessage = \trim((string)($activeOperation['message'] ?? ''));
            if (
                $activeMessage === ''
                || (
                    $taskPlanRecoveryAction === 'reused_queue'
                    && !\str_contains($activeMessage, '重跑')
                )
                || (
                    $taskPlanRecoveryAction === 'created_queue'
                    && !\str_contains($activeMessage, '初始化')
                )
            ) {
                $activeMessage = $defaultRecoveryMessage;
            }

            return [
                'show' => true,
                'level' => 'warning',
                'title' => (string)__('已初始化队列状态'),
                'message' => $activeMessage,
                'operation' => $operation,
                'operation_label' => $operationLabel,
                'queue_id' => $queueId,
                'queue_status' => $status,
                'queue_status_label' => $taskPlanRecoveryAction === 'reused_queue'
                    ? (string)__('已重跑')
                    : (string)__('已初始化'),
                'queue_name' => (string)($snapshot['name'] ?? ''),
                'biz_key' => (string)($snapshot['biz_key'] ?? ''),
                'process' => $process,
                'result_excerpt' => $resultLog !== '' ? \mb_substr($resultLog, -600) : '',
                'confirm_label' => (string)__('知道了'),
            ];
        }
        $message = match ($status) {
            'pending', 'queued' => (string)__('检测到 %{operation} 队列 #%{queue_id} 处于待执行状态，工作区会按队列状态继续刷新。', [
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
            'done', 'complete', 'completed' => (string)__('%{operation} 队列 #%{queue_id} 已完成，工作区已读取最新状态。', [
                'operation' => $operationLabel,
                'queue_id' => (string)$queueId,
            ]),
            default => (string)__('%{operation} 队列 #%{queue_id} 当前状态：%{status}。', [
                'operation' => $operationLabel,
                'queue_id' => (string)$queueId,
                'status' => $statusLabel,
            ]),
        };

        return [
            'show' => true,
            'level' => $level,
            'title' => (string)__('已读取队列状态'),
            'message' => $message,
            'operation' => $operation,
            'operation_label' => $operationLabel,
            'queue_id' => $queueId,
            'queue_status' => $status,
            'queue_status_label' => $statusLabel,
            'queue_name' => (string)($snapshot['name'] ?? ''),
            'biz_key' => (string)($snapshot['biz_key'] ?? ''),
            'process' => $process,
            'result_excerpt' => $resultLog !== '' ? \mb_substr($resultLog, -600) : '',
            'confirm_label' => (string)__('知道了'),
        ];
    }
}
