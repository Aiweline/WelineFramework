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

    public function __construct(
        ?AiSiteQueueSnapshotService $queueSnapshotService = null,
        ?AiSiteAgentQueueObserverHelperService $queueObserverHelperService = null
    ) {
        $this->queueSnapshotService = $queueSnapshotService;
        $this->queueObserverHelperService = $queueObserverHelperService;
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

    private function isQueueWaitingForSystemScheduler(string $queueStatus, int $_queuePid): bool
    {
        // 仅待调度：pending/queued。running 表示 worker 已执行，不再用「等定时任务/约 1 分钟」类提示打扰用户。
        return \in_array($queueStatus, ['pending', 'queued'], true);
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
        $queueStatus = \trim((string)($queueInfo['snapshot']['status'] ?? ''));
        if ($activeStatus === 'done' && $queueStatus !== 'done') {
            return $activeOperation;
        }
        if (\in_array($queueStatus, ['pending', 'queued', 'running'], true)) {
            $activeOperation['status'] = $queueStatus === 'running' ? 'running' : 'queued';
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
                $activeOperation['queue_waiting_for_scheduler'] = true;
                $activeOperation['can_close_stream'] = true;
                $activeOperation['continue_other_operations'] = true;
            } else {
                $activeOperation['queue_waiting_for_scheduler'] = false;
                $activeOperation['can_close_stream'] = false;
                $activeOperation['continue_other_operations'] = false;
            }
            if (!$waitingForScheduler && $queueProcess !== '') {
                $activeOperation['message'] = $queueProcess;
            } elseif (!$waitingForScheduler && ($activeStatus === 'error' || $currentMessage === '')) {
                $activeOperation['message'] = match ($operation) {
                    'task_plan' => 'Stage-2 task-plan queue is running.',
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
            $activeOperation['queue_waiting_for_scheduler'] = false;
            $activeOperation['can_close_stream'] = false;
            $activeOperation['continue_other_operations'] = false;
            $activeOperation['updated_at'] = \date('Y-m-d H:i:s');
        } elseif (\in_array($queueStatus, ['done', 'stop', 'stopped', 'cancelled', 'canceled'], true)) {
            $activeOperation['status'] = $queueStatus === 'done' ? 'done' : 'cancelled';
            $activeOperation['queue_waiting_for_scheduler'] = false;
            $activeOperation['can_close_stream'] = false;
            $activeOperation['continue_other_operations'] = false;
            if ($queueStatus === 'done') {
                $currentMessage = \trim((string)($activeOperation['message'] ?? ''));
                if ($currentMessage !== '' && $activeStatus !== 'error') {
                    $activeOperation['updated_at'] = \date('Y-m-d H:i:s');
                    return $activeOperation;
                }
                $queueProcess = \trim((string)($queueInfo['process'] ?? ''));
                $activeOperation['message'] = $queueProcess !== '' ? $queueProcess : 'Queue operation completed.';
            }
            if (\trim((string)($activeOperation['message'] ?? '')) === '') {
                $activeOperation['message'] = match ($operation) {
                    'task_plan' => $activeOperation['status'] === 'cancelled'
                        ? (string)__('第二阶段任务方案队列已取消。')
                        : (string)__('第二阶段任务方案队列已完成。'),
                    'build' => $activeOperation['status'] === 'cancelled'
                        ? (string)__('生成主题队列已取消。')
                        : (string)__('生成主题队列已完成。'),
                    default => $activeOperation['status'] === 'cancelled'
                        ? (string)__('阶段一方案队列已取消。')
                        : (string)__('阶段一方案队列已完成。'),
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

        $sse->sendEvent('info', [
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

        if ($queueStatus !== '' && $lastQueueStatus !== '' && $queueStatus !== $lastQueueStatus) {
            $sse->sendEvent('info', [
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
            $sse->sendEvent('info', [
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
            $sse->sendEvent('info', [
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

        $process = \trim((string)($queueRow['process'] ?? ''));
        if ($process !== '' && $process !== $lastQueueProcess) {
            if ($helperService->shouldSuppressProcessMirror($operation)) {
                $sse->sendEvent('info', [
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
                $sse->sendEvent('progress', [
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
                $sse->sendEvent('chunk', [
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
