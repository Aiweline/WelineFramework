<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Service;

use GuoLaiRen\PageBuilder\Model\AiSiteAgentSession;

/**
 * AiSiteAgentTaskPlanQueueRecoveryService
 *
 * 从 `AiSiteAgent.php` 控制器抽出的 **第二阶段（task_plan）队列自愈恢复** 服务。
 * 本轮（R4.F5 · Step B）迁移 1 个核心方法：
 *  - `autoRerunTaskPlanQueueWhenQueueDoneButDraftMissing`：在 `STAGE_VISUAL_EDIT` 阶段检测
 *    到 `task_plan.draft` 缺失时，按当前 queue 状态触发两种恢复路径：
 *      · 无 queueId → 创建新 queue 入队 + dispatch worker
 *      · queueId 存在且已 `done` → 重置原 queue 为 `pending` 并 dispatch worker
 *
 * 设计准则：
 *  - **零 service 成员依赖**：全部 11 个控制器私有 helper / 外部 service / 全局副作用（`w_query`）
 *    通过 {@see AiSiteAgentTaskPlanQueueRecoveryPorts} Ports Bag 以 `\Closure` 形式注入。
 *    这避免了引入新的 Port interface（老板 Q8 决策），也保留未来将 Ports 升级为一等对象的空间。
 *  - **签名稳定**：方法返回结构与控制器原私有方法一致
 *    （`{normalized, active_operation, task_plan_queue_info}`），确保薄壳化零行为漂移。
 *  - **Stage/Gate 短路**：原方法中 4 个早期 short-circuit（非 visual_edit 阶段 / draft 存在 /
 *    recovery-init 不满足 / queue 未 done）全部保留为首几个分支，便于 Characterization 覆盖。
 *  - **失败降级**：所有副作用都在 `try/catch Throwable` 中；异常时仅记录 SSE error 日志，
 *    返回原状态（不抛出）—— 与控制器原行为一致。
 */
class AiSiteAgentTaskPlanQueueRecoveryService
{
    /**
     * @param array<string, mixed> $normalized
     * @param array<string, mixed> $activeOperation
     * @return array<string, mixed>
     */
    private function buildActiveOperationScopePatch(array $normalized, array $activeOperation): array
    {
        $patch = ['active_operation' => $activeOperation];
        $operation = \trim((string)($activeOperation['operation'] ?? ''));
        if ($operation === '') {
            return $patch;
        }

        $activeOperations = \is_array($normalized['active_operations'] ?? null)
            ? $normalized['active_operations']
            : [];
        $previous = \is_array($activeOperations[$operation] ?? null) ? $activeOperations[$operation] : [];
        $activeOperations[$operation] = \array_replace($previous, $activeOperation, [
            'operation' => $operation,
        ]);
        $patch['active_operations'] = $activeOperations;

        return $patch;
    }

    /**
     * 第二阶段队列自愈：queue done 但 draft 缺失 / 或 queue 完全不存在时触发恢复派发。
     *
     * 决策链（与原控制器 `autoRerunTaskPlanQueueWhenQueueDoneButDraftMissing` 一致）：
     *  1. `$currentStage !== STAGE_VISUAL_EDIT` ⇒ 直接返回；
     *  2. `isTaskPlanDraftMissing($normalized) === false` ⇒ 草稿未缺失，返回；
     *  3. 从 `$taskPlanQueueInfo['snapshot']` 提取 `status` + `queue_id`；若两者都空，回退调
     *     `$ports->findQueueRow($session, 'task_plan')` 再抽一次；
     *  4. recovery-init gate：`active.operation === 'task_plan' || queueId > 0` 不成立 ⇒ 返回；
     *  5. in-progress gate：queue.status ∈ {pending,queued,running} 或
     *     `status='' && active 仍在 queued/running` ⇒ 视作仍在跑，返回；
     *  6. not-terminal gate：`queueId > 0 && status 不属于 {done,complete,completed,error,failed,stop,stopped}` ⇒ 返回；
     *  7. **Branch A（queueId≤0）**：创建新 queue → merge scope → dispatch worker → 重建 queue info
     *     → 日志 `task_plan_queue_auto_rerun / reason=queue_missing_and_draft_missing_create_new_queue`；
     *  8. **Branch B（queueDone）**：查找原 queue row → 重置 pending + 新 content + 新 envelope
     *     → merge scope → dispatch worker → 重建 queue info
     *     → 日志 `task_plan_queue_auto_rerun / reason=queue_done_but_draft_missing_reuse_queue`；
     *  9. Branch A/B 抛出任意 `\Throwable` ⇒ 日志 `task_plan_queue_auto_rerun_failed` level=error，
     *     返回当前状态（不重抛）。
     *
     * @param string $currentStage 需与 `AiSiteAgentSession::STAGE_VISUAL_EDIT` 等字面值比较
     * @param string $stageVisualEdit 实际的 `STAGE_VISUAL_EDIT` 常量值（由调用方透传，避免跨命名空间耦合）
     * @param array<string, mixed> $normalized 已 normalizeScope 后的 scope
     * @param array<string, mixed> $activeOperation 当前 active_operation 子数组
     * @param array<string, mixed>|null $taskPlanQueueInfo 当前 task_plan queue info payload
     * @param AiSiteAgentTaskPlanQueueRecoveryPorts $ports 控制器注入的端口集合
     *
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
        $allowTaskPlanRecoveryInit = $activeOperationName === 'task_plan' || $queueId > 0;
        if (!$allowTaskPlanRecoveryInit) {
            return [
                'normalized' => $normalized,
                'active_operation' => $activeOperation,
                'task_plan_queue_info' => $taskPlanQueueInfo,
            ];
        }
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
        $queueDone = \in_array($queueStatus, ['done', 'complete', 'completed'], true);
        $queueReusableTerminal = $queueDone || \in_array($queueStatus, ['error', 'failed', 'stop', 'stopped'], true);
        if ($queueId > 0 && !$queueReusableTerminal) {
            return [
                'normalized' => $normalized,
                'active_operation' => $activeOperation,
                'task_plan_queue_info' => $taskPlanQueueInfo,
            ];
        }

        try {
            if ($queueId <= 0) {
                $executionToken = \bin2hex(\random_bytes(16));
                $newQueueId = ($ports->enqueueTask)(
                    $session,
                    $adminId,
                    'task_plan',
                    $executionToken,
                    ['_force_rebuild' => 1]
                );
                if ((int)$newQueueId <= 0) {
                    return [
                        'normalized' => $normalized,
                        'active_operation' => $activeOperation,
                        'task_plan_queue_info' => $taskPlanQueueInfo,
                    ];
                }
                $operationEnvelope = ($ports->buildOperationEnvelope)(
                    $session,
                    'task_plan',
                    $executionToken,
                    'queued'
                );
                $activeOperation = \array_replace([
                    'operation' => 'task_plan',
                    'execution_token' => $executionToken,
                    'status' => 'queued',
                    'page_type' => '',
                    'started_at' => \date('Y-m-d H:i:s'),
                    'updated_at' => \date('Y-m-d H:i:s'),
                    'message' => (string)__('检测到第二阶段方案为空，已初始化队列并开始执行。'),
                    'task_plan_recovery_action' => 'created_queue',
                    'queue_id' => (int)$newQueueId,
                ], $operationEnvelope, [
                    'queue_id' => (int)$newQueueId,
                ]);
                $scopePatch = $this->buildActiveOperationScopePatch($normalized, $activeOperation);
                $normalized = \array_replace($normalized, $scopePatch);
                ($ports->mergeSessionScope)(
                    (int)$session->getId(),
                    $adminId,
                    $scopePatch
                );
                $dispatch = ($ports->ensureWorkerDispatched)(
                    $session,
                    $adminId,
                    'task_plan',
                    (int)$newQueueId,
                    $executionToken,
                    true
                );
                $fresh = ($ports->loadSession)((int)$session->getId(), $adminId) ?? $session;
                $taskPlanQueueInfo = ($ports->buildQueueInfoPayload)($fresh, $activeOperation, 'task_plan');
                if (\is_array($taskPlanQueueInfo['snapshot'] ?? null)) {
                    $taskPlanQueueInfo['snapshot']['status'] = \Weline\Queue\Model\Queue::status_pending;
                    $taskPlanQueueInfo['queue_status'] = \Weline\Queue\Model\Queue::status_pending;
                }
                ($ports->logSse)('task_plan_queue_auto_rerun', [
                    'session_id' => (int)$session->getId(),
                    'queue_id' => (int)$newQueueId,
                    'reason' => 'queue_missing_and_draft_missing_create_new_queue',
                    'dispatch_started' => (int)((bool)(\is_array($dispatch) ? ($dispatch['started'] ?? false) : false)),
                    'dispatch_pid' => (int)(\is_array($dispatch) ? ($dispatch['pid'] ?? 0) : 0),
                ], 'info');
                return [
                    'normalized' => $normalized,
                    'active_operation' => $activeOperation,
                    'task_plan_queue_info' => $taskPlanQueueInfo,
                ];
            }

            // Branch B: queueId>0 且进入终态（成功或失败）→ 通过统一入队路径复用当前阶段 queue
            $executionToken = \bin2hex(\random_bytes(16));
            $newQueueId = ($ports->enqueueTask)(
                $session,
                $adminId,
                'task_plan',
                $executionToken,
                ['_force_rebuild' => 1]
            );
            if ((int)$newQueueId <= 0) {
                return [
                    'normalized' => $normalized,
                    'active_operation' => $activeOperation,
                    'task_plan_queue_info' => $taskPlanQueueInfo,
                ];
            }
            $queueId = (int)$newQueueId;

            $operationEnvelope = ($ports->buildOperationEnvelope)(
                $session,
                'task_plan',
                $executionToken,
                'queued'
            );
            $activeOperation = \array_replace([
                'operation' => 'task_plan',
                'execution_token' => $executionToken,
                'status' => 'queued',
                'page_type' => '',
                'started_at' => \date('Y-m-d H:i:s'),
                'updated_at' => \date('Y-m-d H:i:s'),
                'message' => (string)__('检测到第二阶段方案为空，已重跑原队列。'),
                'task_plan_recovery_action' => 'reused_queue',
                'queue_id' => $queueId,
            ], $operationEnvelope, [
                'queue_id' => $queueId,
            ]);
            $scopePatch = $this->buildActiveOperationScopePatch($normalized, $activeOperation);
            $normalized = \array_replace($normalized, $scopePatch);
            ($ports->mergeSessionScope)(
                (int)$session->getId(),
                $adminId,
                $scopePatch
            );
            $dispatch = ($ports->ensureWorkerDispatched)(
                $session,
                $adminId,
                'task_plan',
                $queueId,
                $executionToken,
                true
            );
            $fresh = ($ports->loadSession)((int)$session->getId(), $adminId) ?? $session;
            $taskPlanQueueInfo = ($ports->buildQueueInfoPayload)($fresh, $activeOperation, 'task_plan');
            if (\is_array($taskPlanQueueInfo['snapshot'] ?? null)) {
                $taskPlanQueueInfo['snapshot']['status'] = \Weline\Queue\Model\Queue::status_pending;
                $taskPlanQueueInfo['queue_status'] = \Weline\Queue\Model\Queue::status_pending;
            }
            ($ports->logSse)('task_plan_queue_auto_rerun', [
                'session_id' => (int)$session->getId(),
                'queue_id' => $queueId,
                'reason' => $queueDone
                    ? 'queue_done_but_draft_missing_reuse_queue'
                    : 'queue_failed_or_stopped_and_draft_missing_reuse_queue',
                'dispatch_started' => (int)((bool)(\is_array($dispatch) ? ($dispatch['started'] ?? false) : false)),
                'dispatch_pid' => (int)(\is_array($dispatch) ? ($dispatch['pid'] ?? 0) : 0),
            ], 'info');
        } catch (\Throwable $throwable) {
            ($ports->logSse)('task_plan_queue_auto_rerun_failed', [
                'session_id' => (int)$session->getId(),
                'queue_id' => $queueId,
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
