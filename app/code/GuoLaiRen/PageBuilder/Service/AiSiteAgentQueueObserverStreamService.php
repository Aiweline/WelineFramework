<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Service;

use Weline\Framework\Http\Sse\SseWriter;
use Weline\Framework\Manager\ObjectManager;

/**
 * AiSiteAgentQueueObserverStreamService
 *
 * 从 `AiSiteAgent.php` 抽出的 **Queue Observer SSE 主干** 与 **活跃操作 × 队列状态归一** 方法族。
 * 本轮（R4.F4）迁移 3 个核心方法：
 *  - `reconcileActiveOperationWithQueueInfo`：workspace 构建路径上根据 `queue_info.snapshot.status` 归一 active_operation；
 *  - `emitQueueObserverQueueDetailEvents`：SSE 观察流起手阶段打印队列快照 `info` 事件；
 *  - `forwardObservedQueueSignals`：SSE 观察流轮询阶段，将 status 变更 / PID 领取 / process 镜像 / result 增量推为 SSE 事件。
 *
 * 构造注入：
 *  - `AiSiteQueueSnapshotService`：产出 `queue_snapshot`（公共字段子集 + token_usage 归一）；
 *  - `AiSiteAgentQueueObserverHelperService`：产出 panel payload + `shouldSuppressProcessMirror` / `shouldSkipResultLine` 判定。
 *
 * `SseWriter` **不** 构造注入，统一作为方法参数传入（保持服务无状态、随请求生命周期绑定）。
 *
 * 方法签名、事件 payload shape 必须与 `AiSiteAgent.php` 原私有方法一致，以保证 SSE 前端链路向后兼容。
 * 调整任一字段需同步更新 Characterization Test 与 `AI建站中台-计划.md` §12.4 SSE 契约文档。
 */
class AiSiteAgentQueueObserverStreamService
{
    private ?AiSiteQueueSnapshotService $queueSnapshotService;
    private ?AiSiteAgentQueueObserverHelperService $queueObserverHelperService;
    private ?AiSiteSsePayloadNormalizer $ssePayloadNormalizer;

    public function __construct(
        ?AiSiteQueueSnapshotService $queueSnapshotService = null,
        ?AiSiteAgentQueueObserverHelperService $queueObserverHelperService = null,
        ?AiSiteSsePayloadNormalizer $ssePayloadNormalizer = null
    ) {
        $this->queueSnapshotService = $queueSnapshotService;
        $this->queueObserverHelperService = $queueObserverHelperService;
        $this->ssePayloadNormalizer = $ssePayloadNormalizer;
    }

    private function queueSnapshotService(): AiSiteQueueSnapshotService
    {
        if ($this->queueSnapshotService === null) {
            $this->queueSnapshotService = ObjectManager::getInstance(AiSiteQueueSnapshotService::class);
        }
        return $this->queueSnapshotService;
    }

    private function queueObserverHelperService(): AiSiteAgentQueueObserverHelperService
    {
        if ($this->queueObserverHelperService === null) {
            $this->queueObserverHelperService = ObjectManager::getInstance(AiSiteAgentQueueObserverHelperService::class);
        }
        return $this->queueObserverHelperService;
    }

    private function ssePayloadNormalizer(): AiSiteSsePayloadNormalizer
    {
        if ($this->ssePayloadNormalizer === null) {
            $this->ssePayloadNormalizer = ObjectManager::getInstance(AiSiteSsePayloadNormalizer::class);
        }
        return $this->ssePayloadNormalizer;
    }

    /**
     * 通过 normalizer 归一化 payload 后再发 SSE。所有本服务对外发的 SSE 事件都应该走这里，
     * 保证 queue_status / task_summary / status / job_status 等家族字段一致性。
     *
     * @param array<string, mixed> $payload
     */
    private function emitNormalizedSseEvent(SseWriter $sse, string $event, array $payload, ?int $eventId = null): void
    {
        $payload = $this->ssePayloadNormalizer()->normalize($payload);
        $sse->sendEvent($event, $payload, $eventId);
    }

    private function isQueueWaitingForSystemScheduler(string $queueStatus, int $_queuePid): bool
    {
        // 仅待调度：pending/queued。running 表示 worker 已执行，不再用「等定时任务/约 1 分钟」类提示打扰用户。
        return \in_array($queueStatus, ['pending', 'queued'], true);
    }

    private function isTerminalQueueFailureStatus(string $queueStatus): bool
    {
        return \in_array($queueStatus, ['error', 'stop', 'cancelled', 'canceled'], true);
    }

    private function buildSystemSchedulerWaitMessage(int $queueId = 0): string
    {
        if ($queueId > 0) {
            return (string)__('队列 #%{queue_id} 正在等待系统定时任务调度，通常约 1 分钟内开始执行；你可以关闭当前进度窗口，继续操作其他内容。', [
                'queue_id' => (string)$queueId,
            ]);
        }

        return (string)__('队列正在等待系统定时任务调度，通常约 1 分钟内开始执行；你可以关闭当前进度窗口，继续操作其他内容。');
    }

    /**
     * 根据 queue_info 归一 activeOperation.status / message / updated_at。
     *
     * 规则（与原控制器 `reconcileActiveOperationWithQueueInfo` 一致）：
     *  - operation 不匹配 / 非 queued|running / 无 snapshot ⇒ 原样返回；
     *  - snapshot.status = 'error' ⇒ active_operation.status='error'，message 取 process → result_log → i18n 兜底；
     *  - snapshot.status = 'done' ⇒ active_operation.status='done'，message 按 operation 分支 i18n 兜底。
     *  - snapshot.status ∈ {stop, stopped, cancelled, canceled} ⇒ active_operation.status='cancelled'，避免被误读为成功完成。
     *
     * @param array<string, mixed> $activeOperation
     * @param array<string, mixed>|null $queueInfo
     *
     * @return array<string, mixed>
     */
    /**
     * @param array<string, mixed> $activeOperation
     * @return array<string, mixed>
     */
    private function applySchedulerWaitingState(array $activeOperation, bool $waitingForScheduler): array
    {
        $activeOperation['queue_waiting_for_scheduler'] = $waitingForScheduler;
        $activeOperation['can_close_stream'] = $waitingForScheduler;
        $activeOperation['continue_other_operations'] = $waitingForScheduler;

        return $activeOperation;
    }

    public function reconcileActiveOperationWithQueueInfo(
        array $activeOperation,
        ?array $queueInfo,
        string $operation
    ): array {
        if (
            \trim((string)($activeOperation['operation'] ?? '')) !== $operation
            || !\in_array(\trim((string)($activeOperation['status'] ?? '')), ['queued', 'running', 'done', 'error', 'stop', 'cancelled', 'canceled'], true)
            || !\is_array($queueInfo['snapshot'] ?? null)
        ) {
            return $activeOperation;
        }

        $activeStatus = \trim((string)($activeOperation['status'] ?? ''));
        $queueSnapshot = \is_array($queueInfo['snapshot'] ?? null) ? $queueInfo['snapshot'] : [];
        $queueStatus = \strtolower(\trim((string)($queueSnapshot['status'] ?? '')));
        $semanticStatus = \strtolower(\trim((string)($queueInfo['semantic_status'] ?? $queueSnapshot['semantic_status'] ?? '')));
        $queueDone = \in_array($queueStatus, ['done', 'complete', 'completed'], true)
            || \in_array($semanticStatus, ['done', 'complete', 'completed'], true);
        if ($queueDone && $queueStatus === '') {
            $queueStatus = 'done';
        }
        $queueRecoveredForRetry = !$queueDone
            && (
                !empty($queueInfo['queue_terminal_recovered'])
                || !empty($queueSnapshot['queue_terminal_recovered'])
                || !empty($queueInfo['retry_allowed'])
                || !empty($queueSnapshot['retry_allowed'])
                || \in_array($semanticStatus, ['cancelled', 'canceled', 'stale'], true)
            );
        if ($activeStatus === 'done' && !$queueDone) {
            return $activeOperation;
        }
        if ($queueRecoveredForRetry) {
            $queueId = (int)($queueInfo['queue_id'] ?? $queueSnapshot['queue_id'] ?? 0);
            if ($queueId > 0) {
                $activeOperation['queue_id'] = $queueId;
            }
            $activeOperation['status'] = 'cancelled';
            $activeOperation['semantic_status'] = 'cancelled';
            $activeOperation['message'] = \trim((string)($queueInfo['message'] ?? $queueInfo['process'] ?? '')) !== ''
                ? \trim((string)($queueInfo['message'] ?? $queueInfo['process'] ?? ''))
                : 'Linked queue process ended without a terminal queue status; retry is allowed.';
            $activeOperation['retry_allowed'] = 1;
            $activeOperation['queue_terminal_recovered'] = 1;
            $activeOperation = $this->applySchedulerWaitingState($activeOperation, false);
            $activeOperation['updated_at'] = \date('Y-m-d H:i:s');

            return $activeOperation;
        }
        if (\in_array($queueStatus, ['pending', 'queued', 'running', 'processing'], true)) {
            $activeOperation['status'] = \in_array($queueStatus, ['running', 'processing'], true) ? 'running' : 'queued';
            $activeOperation['semantic_status'] = $activeOperation['status'];
            $activeOperation['retry_allowed'] = 0;
            $activeOperation['queue_terminal_recovered'] = 0;
            $queueId = (int)($queueInfo['queue_id'] ?? $queueInfo['snapshot']['queue_id'] ?? 0);
            $queuePid = (int)($queueInfo['pid'] ?? $queueInfo['snapshot']['pid'] ?? 0);
            if ($queueId > 0) {
                $activeOperation['queue_id'] = $queueId;
            }
            $queueProcess = \trim((string)($queueInfo['process'] ?? ''));
            $currentMessage = \trim((string)($activeOperation['message'] ?? ''));
            $waitingForScheduler = $this->isQueueWaitingForSystemScheduler($queueStatus, $queuePid);
            if ($waitingForScheduler) {
                $activeOperation['message'] = $this->buildSystemSchedulerWaitMessage($queueId);
            }
            $activeOperation = $this->applySchedulerWaitingState($activeOperation, $waitingForScheduler);
            if (!$waitingForScheduler && $queueProcess !== '') {
                $activeOperation['message'] = $queueProcess;
            } elseif (!$waitingForScheduler && ($activeStatus === 'error' || $currentMessage === '')) {
                $activeOperation['message'] = match ($operation) {
                    'build' => 'Build queue is running.',
                    default => 'Stage-1 plan queue is running.',
                };
            }
            $activeOperation['updated_at'] = \date('Y-m-d H:i:s');
        } elseif ($queueStatus === 'error') {
            $queueId = (int)($queueInfo['queue_id'] ?? $queueInfo['snapshot']['queue_id'] ?? 0);
            if ($queueId > 0) {
                $activeOperation['queue_id'] = $queueId;
            }
            $queueRowForMessage = [
                'status' => $queueStatus !== '' ? $queueStatus : 'error',
                'process' => (string)($queueInfo['process'] ?? ''),
                'result' => (string)($queueInfo['result_log'] ?? ''),
            ];
            $queueMessage = \trim($this->queueObserverHelperService()->resolveMessage($queueRowForMessage, false));
            $activeOperation['status'] = 'error';
            $activeOperation['message'] = $queueMessage !== '' ? $queueMessage : (string)__('队列执行失败，请重试。');
            $activeOperation = $this->applySchedulerWaitingState($activeOperation, false);
            $activeOperation['updated_at'] = \date('Y-m-d H:i:s');
        } elseif (\in_array($queueStatus, ['done', 'complete', 'completed', 'stop', 'stopped', 'cancelled', 'canceled'], true)) {
            $activeOperation['status'] = \in_array($queueStatus, ['done', 'complete', 'completed'], true) ? 'done' : 'cancelled';
            $activeOperation['semantic_status'] = $activeOperation['status'];
            $activeOperation = $this->applySchedulerWaitingState($activeOperation, false);
            if ($activeOperation['status'] === 'done') {
                $activeOperation['retry_allowed'] = 0;
                $activeOperation['queue_terminal_recovered'] = 0;
                $currentMessage = \trim((string)($activeOperation['message'] ?? ''));
                if (
                    $currentMessage !== ''
                    && !\in_array($activeStatus, ['error', 'stop', 'stopped', 'cancelled', 'canceled'], true)
                ) {
                    $activeOperation['updated_at'] = \date('Y-m-d H:i:s');
                    return $activeOperation;
                }
                $queueProcess = \trim((string)($queueInfo['process'] ?? ''));
                $activeOperation['message'] = $queueProcess !== '' ? $queueProcess : 'Queue operation completed.';
            }
            if (\trim((string)($activeOperation['message'] ?? '')) === '') {
                $activeOperation['message'] = match ($operation) {
                    'build' => $activeOperation['status'] === 'cancelled'
                        ? (string)__('生成主题队列已取消。')
                        : (string)__('生成主题队列已完成。'),
                    default => $activeOperation['status'] === 'cancelled'
                        ? (string)__('建站方案队列已取消。')
                        : (string)__('建站方案队列已完成。'),
                };
            }
            $activeOperation['updated_at'] = \date('Y-m-d H:i:s');
        }

        return $activeOperation;
    }

    /**
     * 队列创建/连接观察流后，向前台打印可读的队列元数据（多行 detail_lines + 结构化快照）。
     *
     * 与原控制器 `emitQueueObserverQueueDetailEvents` 事件 payload 完全一致。
     *
     * @param array<string, mixed> $queueRow
     */
    public function emitQueueDetailEvents(SseWriter $sse, array $queueRow, string $operation): void
    {
        if (!$sse->isAlive()) {
            return;
        }
        $snap = $this->queueSnapshotService()->buildObserverPublicSnapshot($queueRow);
        $queueInfo = $this->queueObserverHelperService()->buildPanelPayload($queueRow, $snap);
        $tokenUsage = \is_array($snap['token_usage'] ?? null) ? $snap['token_usage'] : [];
        $lines = [
            (string)__('【队列】任务已就绪，以下为队列快照（进度请在工作区自动刷新）。'),
            (string)__('队列编号：%{id}', ['id' => (string)$snap['queue_id']]),
            (string)__('业务键 biz_key：%{k}', ['k' => ($snap['biz_key'] !== '' ? (string)$snap['biz_key'] : '-')]),
            (string)__('任务：%{name}（模块 %{module}）', [
                'name' => ($snap['name'] !== '' ? (string)$snap['name'] : '-'),
                'module' => ($snap['module'] !== '' ? (string)$snap['module'] : '-'),
            ]),
            (string)__('状态：%{status}；调度 PID：%{pid}', [
                'status' => (string)$snap['status'],
                'pid' => (string)$snap['pid'],
            ]),
            (string)__('类型 ID：%{tid}；完成标记：%{fin}', [
                'tid' => (string)$snap['type_id'],
                'fin' => (string)$snap['finished'],
            ]),
        ];
        if (($snap['start_at'] ?? '') !== '' || ($snap['end_at'] ?? '') !== '') {
            $lines[] = (string)__('开始/结束：%{s} / %{e}', [
                's' => ($snap['start_at'] !== '' ? (string)$snap['start_at'] : '-'),
                'e' => ($snap['end_at'] !== '' ? (string)$snap['end_at'] : '-'),
            ]);
        }
        if (($snap['public_id_hint'] ?? '') !== '') {
            $lines[] = (\defined('DEV') && DEV)
                ? (string)__('会话 public_id：%{h}', ['h' => (string)$snap['public_id_hint']])
                : (string)__('会话 public_id（脱敏）：%{h}', ['h' => (string)$snap['public_id_hint']]);
        }

        $queueStatus = \trim((string)($snap['status'] ?? ''));
        $queuePid = (int)($snap['pid'] ?? 0);
        $waitingForScheduler = $this->isQueueWaitingForSystemScheduler($queueStatus, $queuePid);
        $schedulerWaitMessage = $this->buildSystemSchedulerWaitMessage((int)($snap['queue_id'] ?? 0));
        if ($waitingForScheduler) {
            $lines[] = $schedulerWaitMessage;
        }

        $this->emitNormalizedSseEvent($sse, 'info', [
            'message' => $waitingForScheduler ? $schedulerWaitMessage : (string)($lines[0] ?? ''),
            'detail_lines' => $lines,
            'queue_snapshot' => $snap,
            'queue_info' => $queueInfo,
            'operation' => $operation,
            'queue_id' => (int)$snap['queue_id'],
            'queue_status' => (string)$snap['status'],
            'token_usage' => $tokenUsage,
            'progress_kind' => 'queue_info',
            'observer_detail' => true,
            'queue_waiting_for_scheduler' => $waitingForScheduler,
            'can_close_stream' => $waitingForScheduler,
            'continue_other_operations' => $waitingForScheduler,
        ]);
    }

    /**
     * SSE 观察流轮询阶段，把 status/PID/process/result 的增量变化推成事件。
     *
     * 与原控制器 `forwardObservedQueueSignals` 事件 payload + 返回四元组完全一致。
     *
     * @param array<string, mixed>|null $queueRow
     *
     * @return array{0:string,1:int,2:string,3:int} 新的 [lastQueueProcess, lastQueueResultLength, nextQueueStatus, queuePid]
     */
    public function forwardObservedQueueSignals(
        SseWriter $sse,
        ?array $queueRow,
        string $operation,
        string $lastQueueProcess,
        int $lastQueueResultLength,
        string $lastQueueStatus,
        int $lastQueuePid
    ): array {
        if (!\is_array($queueRow) || $queueRow === []) {
            return [$lastQueueProcess, $lastQueueResultLength, $lastQueueStatus, $lastQueuePid];
        }

        $queueId = (int)($queueRow['queue_id'] ?? 0);
        $queueStatus = \trim((string)($queueRow['status'] ?? ''));
        $queuePid = (int)($queueRow['pid'] ?? 0);
        $snapshotService = $this->queueSnapshotService();
        $helperService = $this->queueObserverHelperService();
        $queueSnapshot = $snapshotService->buildObserverPublicSnapshot($queueRow);
        $queuePanelInfo = $helperService->buildPanelPayload($queueRow, $queueSnapshot);
        $tokenUsage = \is_array($queueSnapshot['token_usage'] ?? null) ? $queueSnapshot['token_usage'] : [];
        $process = \trim((string)($queueRow['process'] ?? ''));
        // 调度器 worker 是队列存活的唯一权威源：只要 weline_queue.status 处于 running/processing，
        // 当前 HTTP/SSE worker 就以「队列活着」对待，不再对 PID 做跨进程探活。
        // 历史 PID 兜底会在调度 worker 与请求 worker 隔离（容器/子进程/权限）时把活着的队列
        // 误判为 cancelled，触发"前端显示失败 → 用户重试 → 与正在运行的队列抢锁"的级联问题。

        if ($queueStatus !== '' && $lastQueueStatus !== '' && $queueStatus !== $lastQueueStatus) {
            $this->emitNormalizedSseEvent($sse, 'info', [
                'message' => (string)__('队列状态变更：%{from} → %{to}', [
                    'from' => $lastQueueStatus,
                    'to' => $queueStatus,
                ]),
                'operation' => $operation,
                'queue_id' => $queueId,
                'queue_status' => $queueStatus,
                'queue_snapshot' => $queueSnapshot,
                'queue_info' => $queuePanelInfo,
                'token_usage' => $tokenUsage,
                'progress_kind' => 'queue_info',
                'observer_detail' => true,
            ]);
        }
        if ($lastQueuePid === 0 && $queuePid > 0) {
            $this->emitNormalizedSseEvent($sse, 'info', [
                'message' => (string)__('队列已被 worker 领取执行（PID %{pid}）。', ['pid' => (string)$queuePid]),
                'operation' => $operation,
                'queue_id' => $queueId,
                'queue_status' => $queueStatus,
                'queue_snapshot' => $queueSnapshot,
                'queue_info' => $queuePanelInfo,
                'token_usage' => $tokenUsage,
                'progress_kind' => 'queue_info',
                'observer_detail' => true,
            ]);
        }

        if (
            $this->isQueueWaitingForSystemScheduler($queueStatus, $queuePid)
            && ($lastQueueStatus === '' || $queueStatus !== $lastQueueStatus || $queuePid !== $lastQueuePid)
        ) {
            $this->emitNormalizedSseEvent($sse, 'info', [
                'message' => $this->buildSystemSchedulerWaitMessage($queueId),
                'operation' => $operation,
                'queue_id' => $queueId,
                'queue_status' => $queueStatus,
                'queue_snapshot' => $queueSnapshot,
                'queue_info' => $queuePanelInfo,
                'token_usage' => $tokenUsage,
                'progress_kind' => 'queue_info',
                'observer_detail' => true,
                'queue_waiting_for_scheduler' => true,
                'can_close_stream' => true,
                'continue_other_operations' => true,
            ]);
        }

        $nextQueueStatus = $queueStatus !== '' ? $queueStatus : $lastQueueStatus;

        if ($queueStatus !== '' && $queueStatus !== $lastQueueStatus && $this->isTerminalQueueFailureStatus($queueStatus)) {
            $message = \trim($helperService->resolveMessage($queueRow, false));
            if ($message === '') {
                $message = 'Queue operation failed.';
            }
            $this->emitNormalizedSseEvent($sse, 'error', [
                'message' => $message,
                'operation' => $operation,
                'queue_id' => $queueId,
                'queue_status' => $queueStatus,
                'queue_snapshot' => $queueSnapshot,
                'queue_info' => $queuePanelInfo,
                'queue_process' => $process,
                'token_usage' => $tokenUsage,
                'progress_kind' => 'queue_info',
                'observer_detail' => true,
                'queue_panel_update' => true,
                'http_code' => 409,
            ]);
        }

        if ($process !== '' && $process !== $lastQueueProcess) {
            if ($helperService->shouldSuppressProcessMirror($operation)) {
                $this->emitNormalizedSseEvent($sse, 'info', [
                    'message' => '',
                    'operation' => $operation,
                    'queue_id' => $queueId,
                    'queue_status' => $queueStatus,
                    'queue_snapshot' => $queueSnapshot,
                    'queue_info' => $queuePanelInfo,
                    'queue_process' => $process,
                    'token_usage' => $tokenUsage,
                    'progress_kind' => 'queue_info',
                    'observer_detail' => true,
                    'queue_panel_update' => true,
                ]);
            } else {
                $this->emitNormalizedSseEvent($sse, 'progress', [
                    'message' => $process,
                    'operation' => $operation,
                    'progress_percent' => 0,
                    'queue_id' => $queueId,
                    'queue_status' => $queueStatus,
                    'queue_snapshot' => $queueSnapshot,
                    'queue_info' => $queuePanelInfo,
                    'queue_process' => $process,
                    'token_usage' => $tokenUsage,
                    'progress_kind' => 'queue_info',
                ]);
            }
            $lastQueueProcess = $process;
        }

        $result = (string)($queueRow['result'] ?? '');
        $resultLength = \strlen($result);
        if ($resultLength < $lastQueueResultLength) {
            $lastQueueResultLength = 0;
        }
        if ($resultLength > $lastQueueResultLength) {
            $delta = \substr($result, $lastQueueResultLength);
            $lines = \preg_split("/\\r\\n|\\n|\\r/", $delta) ?: [];
            foreach ($lines as $line) {
                $line = \trim((string)$line);
                if ($line === '' || $helperService->shouldSkipResultLine($operation, $line)) {
                    continue;
                }
                $this->emitNormalizedSseEvent($sse, 'chunk', [
                    'message' => $line,
                    'operation' => $operation,
                    'chunk' => $line . PHP_EOL,
                    'content' => $line . PHP_EOL,
                    'queue_id' => $queueId,
                    'queue_status' => $queueStatus,
                    'queue_snapshot' => $queueSnapshot,
                    'queue_info' => $queuePanelInfo,
                    'queue_process' => $process,
                    'queue_result_delta' => $line . PHP_EOL,
                    'token_usage' => $tokenUsage,
                    'progress_kind' => 'queue_info',
                ]);
            }
            $lastQueueResultLength = $resultLength;
        }

        return [$lastQueueProcess, $lastQueueResultLength, $nextQueueStatus, $queuePid];
    }
}
