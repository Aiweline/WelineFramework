<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Service;

use GuoLaiRen\PageBuilder\Model\AiSiteAgentSession;
use Weline\Framework\Http\Sse\SseWriter;

/**
 * AiSiteAgentQueueDispatchGuardService
 *
 * 从 `AiSiteAgent.php` 抽出的 **队列观察流自愈派发守卫** 方法族。
 * 本轮（R4.F5 · Step A）迁移 1 个核心方法：
 *  - `maybeAutoDispatchObservedPendingQueue`：SSE 观察流每一轮发现 queue 处于 pending / 无 pid running /
 *    或 recoverable settled（error|stop 且 active 匹配）状态时，触发 `ensureAiSiteQueueWorkerDispatched`
 *    并把 SSE warning/info 事件转发给前端。
 *
 * 设计准则（本轮）：
 *  - **零 service 成员依赖**：dispatch 与 findQueueRow 两个跨领域依赖用 **Closure 端口** 注入，
 *    scope 由控制器侧预先 `normalizeScope` 后作为参数传入；本服务只承担 **决策 + SSE 事件转发 + 结果汇总**。
 *  - **签名稳定**：返回结构与控制器原 `array{attempted,started,queue,message}` 契约完全一致，
 *    便于控制器薄壳化且零 SSE 行为漂移。
 *  - **可测试性优先**：Spy SseWriter + 伪造 Closure 即可覆盖全部分支，不需要拉起真实 Worker 或 DB。
 *
 * 不引入 `QueueWorkerDispatchPort` 接口（老板 Q8 选择）：Closure 注入足以承载"dispatch"与"findRow"两种
 * 跨上下文能力；若未来回收到统一接口，再把 Closure 替换为 Port 实现即可（两者对调用端透明）。
 */
class AiSiteAgentQueueDispatchGuardService
{
    /**
     * 观察流自愈派发守卫：判断是否需要自动触发 dispatch，并把 SSE 事件转发给前端。
     *
     * 决策链（与原控制器 `maybeAutoDispatchObservedPendingQueue` 一致）：
     *  1. 已尝试 / queueRow 为空/null ⇒ 直接 no-op；
     *  2. 若 queue.status=error|stop 且 active_operation 匹配当前 (operation,executionToken)
     *     且 active.status∈{queued,running} 且 queueContent.execution_token 兼容 ⇒ 视作可恢复；
     *  3. 触发条件（任一）：status=pending / status=running 但 pid<=0 / 可恢复终态；
     *  4. 可恢复终态且 SSE 活着 ⇒ 发 warning 事件（恢复提示）；
     *  5. 调 `$dispatchPort(session, adminId, operation, queueId, executionToken, force=true)`；
     *  6. 调 `$findQueueRowPort(session, operation, queueId)` 刷新最新 queueRow；
     *  7. 若 dispatch.message 非空且 SSE 活着 ⇒ started=true 发 info / 否则发 warning；
     *  8. 返回 `{attempted, started, queue, message}`。
     *
     * @param array<string, mixed>|null $queueRow `weline_queue` 一行；null 或 [] 直接 no-op。
     * @param array<string, mixed> $normalizedScope 控制器侧已 `normalizeScope` 好的 scope；
     *        必须包含 `active_operation` 子数组（若无则按空数组处理）。
     * @param \Closure(AiSiteAgentSession,int,string,int,string,bool):array $dispatchPort
     *        dispatch 端口：签名 `(session, adminId, operation, queueId, executionToken, force): array{
     *          attempted:bool, started:bool, pid:int, queue_id:int, reason:string, message:string, process_name:string
     *        }`。
     * @param \Closure(AiSiteAgentSession,string,int):?array $findQueueRowPort
     *        findQueueRow 端口：签名 `(session, operation, queueId): ?array`；null 或 [] 表示未找到。
     *
     * @return array{attempted:bool, started:bool, queue:array<string,mixed>|null, message:string}
     */
    public function maybeAutoDispatchObservedPendingQueue(
        SseWriter $sse,
        AiSiteAgentSession $session,
        int $adminId,
        string $operation,
        string $executionToken,
        ?array $queueRow,
        bool $alreadyAttempted,
        array $normalizedScope,
        \Closure $dispatchPort,
        \Closure $findQueueRowPort
    ): array {
        if ($alreadyAttempted || !\is_array($queueRow) || $queueRow === []) {
            return ['attempted' => false, 'started' => false, 'queue' => $queueRow, 'message' => ''];
        }

        $queueId = (int)($queueRow['queue_id'] ?? 0);
        $status = \trim((string)($queueRow['status'] ?? ''));
        $pid = (int)($queueRow['pid'] ?? 0);
        $activeOperation = \is_array($normalizedScope['active_operation'] ?? null)
            ? $normalizedScope['active_operation']
            : [];
        $activeStatus = \trim((string)($activeOperation['status'] ?? ''));
        $activeMatchesCurrent = \trim((string)($activeOperation['operation'] ?? '')) === $operation
            && \trim((string)($activeOperation['execution_token'] ?? '')) === $executionToken
            && \in_array($activeStatus, ['queued', 'running'], true);
        $queueContent = \json_decode((string)($queueRow['content'] ?? ''), true);
        $queueExecutionToken = \is_array($queueContent) ? \trim((string)($queueContent['execution_token'] ?? '')) : '';
        $recoverableSettledQueue = \in_array(
                $status,
                [\Weline\Queue\Model\Queue::status_error, \Weline\Queue\Model\Queue::status_stop],
                true
            )
            && $activeMatchesCurrent
            && ($queueExecutionToken === '' || $queueExecutionToken === $executionToken);
        $needsDispatch = $status === \Weline\Queue\Model\Queue::status_pending
            || ($status === \Weline\Queue\Model\Queue::status_running && $pid <= 0)
            || $recoverableSettledQueue;
        if (!$needsDispatch || $queueId <= 0) {
            return ['attempted' => false, 'started' => false, 'queue' => $queueRow, 'message' => ''];
        }

        if ($recoverableSettledQueue && $sse->isAlive()) {
            $sse->sendEvent('warning', [
                'message' => (string)__('检测到队列上次异常结束，正在尝试自动恢复执行。'),
                'operation' => $operation,
                'queue_id' => $queueId,
                'queue_status' => $status,
                'observer_detail' => true,
            ]);
        }

        $dispatch = $dispatchPort($session, $adminId, $operation, $queueId, $executionToken, true);
        if (!\is_array($dispatch)) {
            $dispatch = [];
        }
        $message = (string)($dispatch['message'] ?? '');
        $updatedQueue = $findQueueRowPort($session, $operation, $queueId);
        if (!\is_array($updatedQueue)) {
            $updatedQueue = null;
        }

        if ($message !== '' && $sse->isAlive()) {
            $sse->sendEvent((bool)($dispatch['started'] ?? false) ? 'info' : 'warning', [
                'message' => $message,
                'operation' => $operation,
                'queue_id' => $queueId,
                'queue_status' => (string)(($updatedQueue['status'] ?? null) ?? ($queueRow['status'] ?? '')),
                'observer_detail' => true,
            ]);
        }

        return [
            'attempted' => (bool)($dispatch['attempted'] ?? false),
            'started' => (bool)($dispatch['started'] ?? false),
            'queue' => \is_array($updatedQueue) && $updatedQueue !== [] ? $updatedQueue : $queueRow,
            'message' => $message,
        ];
    }
}
