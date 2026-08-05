<?php

declare(strict_types=1);

namespace Weline\Queue\Service;

use Weline\Cron\Api\Process\ProcessControlInterface;
use Weline\Framework\App\Env;
use Weline\Framework\Database\Connection\Api\Sql\WriteIntentQueryInterface;
use Weline\Framework\Database\TransactionContext;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RuntimeProviderResolver;
use Weline\Framework\System\Process\Processer;
use Weline\Queue\DeadWorkerRecoverableQueueInterface;
use Weline\Queue\DeadWorkerRecoveryPatchQueueInterface;
use Weline\Queue\Model\Queue;

class QueueDispatchService
{
    private const DISPATCH_CLAIM_SECONDS = 30;
    private const DEFAULT_WORKER_MEMORY_LIMIT = '512M';
    private const DEFAULT_WORKER_MEMORY_LIMIT_BY_CLASS = [];
    private ?ProcessControlInterface $processControl = null;

    public function __construct(
        private readonly Queue $queue,
        private readonly RuntimeProviderResolver $runtimeProviders,
    ) {
    }

    /**
     * Dispatch one specific queue through the same background worker contract
     * used by the cron scheduler.
     */
    public function dispatchQueueIfEligible(Queue $queue): bool
    {
        $queueId = (int)$queue->getId();
        if ($queueId <= 0 || $this->hasActiveQueueTransaction()) {
            return false;
        }

        $this->reconcileRunningQueues();
        $freshQueue = $this->loadFreshQueue($queueId);
        if ((int)$freshQueue->getId() <= 0 || !$this->isDispatchable($freshQueue)) {
            return false;
        }

        if ($this->countRunningAutoQueues() >= $this->resolveMaxConcurrent()) {
            return false;
        }

        return $this->startQueueProcess($freshQueue);
    }

    /**
     * Dispatch pending auto queues. This is the cron scheduler entry point.
     *
     * @return array{dispatched: int, slots: int}
     */
    public function dispatchPendingAutoQueues(?int $limit = null): array
    {
        if ($this->hasActiveQueueTransaction()) {
            return ['dispatched' => 0, 'slots' => 0];
        }
        $maxConcurrent = $this->resolveMaxConcurrent();
        $this->reconcileRunningQueues();
        $runningCount = $this->countRunningAutoQueues();
        $slots = \max(0, $maxConcurrent - $runningCount);
        if ($limit !== null) {
            $slots = \min($slots, \max(0, $limit));
        }
        if ($slots <= 0) {
            return ['dispatched' => 0, 'slots' => 0];
        }

        $pendingQueues = $this->loadDispatchReadyPendingQueues($slots);

        $dispatched = 0;
        foreach ($pendingQueues as $queue) {
            if ($queue instanceof Queue && $this->startQueueProcess($queue)) {
                $dispatched++;
            }
        }

        return ['dispatched' => $dispatched, 'slots' => $slots];
    }

    /** @return list<Queue> */
    protected function loadDispatchReadyPendingQueues(int $limit): array
    {
        $limit = \max(0, $limit);
        if ($limit === 0) {
            return [];
        }
        $items = [];
        foreach ([
            [null, 'IS NULL'],
            [\gmdate('Y-m-d H:i:s'), '<='],
        ] as [$notBefore, $condition]) {
            foreach ($this->loadDispatchReadyPendingQueuesByNotBefore(
                $notBefore,
                $condition,
                $limit,
            ) as $row) {
                if ($row instanceof Queue && $this->isDispatchable($row)) {
                    $queueId = (int)$row->getId();
                    if ($queueId > 0) {
                        $items[$queueId] = $row;
                    }
                }
            }
        }

        // Fetch a bounded page from both eligibility shapes, then merge by the
        // monotonic Queue id. Filling the whole limit from NULL rows first can
        // indefinitely hide already-due delayed rows. Global id order drains
        // older work across both shapes and the id map prevents duplicates.
        \ksort($items, \SORT_NUMERIC);

        return \array_slice(\array_values($items), 0, $limit);
    }

    /** @return list<Queue> */
    protected function loadDispatchReadyPendingQueuesByNotBefore(
        ?string $notBefore,
        string $condition,
        int $limit,
    ): array {
        $query = clone $this->queue;

        return $query->reset()
            ->where($query::schema_fields_finished, 0)
            ->where($query::schema_fields_auto, 1)
            ->where($query::schema_fields_status, $query::status_pending)
            ->where($query::schema_fields_NOT_BEFORE, $notBefore, $condition)
            ->order($query::schema_fields_ID, 'ASC')
            ->pagination(1, $limit)
            ->select()
            ->fetch()
            ->getItems();
    }

    public function reconcileRunningQueues(): void
    {
        if ($this->hasActiveQueueTransaction()) {
            return;
        }
        foreach ($this->loadRunningAutoQueues() as $queue) {
            if (!$queue instanceof Queue) {
                continue;
            }
            $queueId = (int)$queue->getId();
            $queueName = $this->normalizeQueueTaskName($queue->getName(), $queueId);
            $processName = $this->buildQueueRunProcessName(
                $queueId,
                $queueName,
                $queue,
                $queue->getDispatchToken(),
            );
            $queuePid = (int)($queue->getPid() ?: 0);
            $dispatchToken = $queue->getDispatchToken();
            if ($queuePid === 0 && $dispatchToken !== '') {
                $dispatchUntil = $queue->getDispatchUntil();
                if ($dispatchUntil !== '' && $dispatchUntil > \gmdate('Y-m-d H:i:s')) {
                    // Detached Worker 尚在启动窗口；running+pid=0 仍占用并发槽。
                    continue;
                }
                $this->releaseExpiredDispatchClaim($queue);
                continue;
            }
            $processState = $queuePid > 0
                ? $this->probeQueueProcessState($queuePid)
                : Processer::PROCESS_STATE_EXITED;
            if ($processState === Processer::PROCESS_STATE_UNKNOWN) {
                // A transient OS inspection failure is not proof that the old
                // generation exited. Leave the exact row untouched.
                continue;
            }
            $pidAlive = $processState === Processer::PROCESS_STATE_RUNNING;
            $running = $pidAlive && $this->isManagedQueueWorkerRunning(
                $queuePid,
                $queueName,
                $dispatchToken,
                $processName,
            );
            if (!$running && $pidAlive) {
                // 受管身份探测可能瞬时失败（pid 索引缺失、ps 读取失败、手动 queue:run 不带 --name）。
                // 只要命令行能确认该 PID 正在执行同一队列 ID，就视为仍在运行，绝不误杀合法 worker。
                $cmdLine = $this->getQueueProcessCommandLine($queuePid);
                if ($cmdLine === '') {
                    // 命令行暂不可读：进程仍存活，跳过本轮判定，等待下一轮探测。
                    continue;
                }
                if (\str_contains($cmdLine, 'queue:run')
                    && \preg_match('/--id[= ]' . $queueId . '(?:\s|$)/', $cmdLine) === 1) {
                    $running = true;
                }
            }
            if ($running) {
                continue;
            }

            if ($queuePid > 0) {
                $output = $this->getQueueManagedProcessOutput($processName, $queuePid);
                $updates = [
                    Queue::schema_fields_end_at => \date('Y-m-d H:i:s'),
                    Queue::schema_fields_pid => 0,
                ];
                if ($queue->isFinished() || $queue->getStatus() === $queue::status_done || $this->hasQueueDoneMarker($output, $queue)) {
                    $message = (string)__('队列进程已结束，检测到完成标记，已同步为完成状态。');
                    $updates += [
                        Queue::schema_fields_finished => 1,
                        Queue::schema_fields_result => $this->prependResultMessage($queue->getResult(), $output, $message),
                        Queue::schema_fields_process => $this->appendProcessMessage($queue->getProcess(), $message),
                        Queue::schema_fields_status => Queue::status_done,
                        Queue::schema_fields_DISPATCH_TOKEN => null,
                        Queue::schema_fields_DISPATCH_UNTIL => null,
                    ];
                    $this->updateQueueSnapshotIf($queue, $updates);
                    $this->releaseReconciledQueueLease($queue, $queueName);
                    continue;
                }
                $message = $pidAlive
                    ? (string)__('队列记录的 PID %{1} 仍存在，但已不匹配当前队列执行进程，已标记为异常。', [$queuePid])
                    : (string)__('队列记录的 PID %{1} 已不存在，已标记为异常。', [$queuePid]);
                $recoveryPatch = $this->deadWorkerRecoveryPatch($queue, $queuePid, $output);
                if ($recoveryPatch !== null) {
                    $message = $this->deadWorkerRecoveryMessage($queue, $queuePid, $output);
                    $updates = \array_replace($updates, [
                        Queue::schema_fields_status => Queue::status_pending,
                        Queue::schema_fields_finished => 0,
                        Queue::schema_fields_auto => 1,
                        Queue::schema_fields_DISPATCH_TOKEN => null,
                        Queue::schema_fields_DISPATCH_UNTIL => null,
                        Queue::schema_fields_start_at => null,
                        Queue::schema_fields_end_at => null,
                        Queue::schema_fields_result => $message,
                        Queue::schema_fields_process => $this->appendProcessMessage($queue->getProcess(), $message),
                    ], $recoveryPatch);
                    $this->updateQueueSnapshotIf($queue, $updates);
                    $this->releaseReconciledQueueLease($queue, $queueName);
                    continue;
                }
                // 保留 dispatch token 作为已启动 attempt 的终止证据；Delivery timeout
                // 仍需用同一 fence 确认该 PID 已死亡，之后 Transport 再清理 token。
                $updates += [
                    Queue::schema_fields_status => Queue::status_error,
                    Queue::schema_fields_result => $this->prependResultMessage($queue->getResult(), $output, $message),
                    Queue::schema_fields_process => $this->appendProcessMessage($queue->getProcess(), $message),
                ];
                $this->updateQueueSnapshotIf($queue, $updates);
                $this->releaseReconciledQueueLease($queue, $queueName);
                continue;
            }

            if ($queue->isFinished()) {
                $this->updateQueueSnapshotIf($queue, [
                    Queue::schema_fields_status => Queue::status_done,
                    Queue::schema_fields_pid => 0,
                    Queue::schema_fields_DISPATCH_TOKEN => null,
                    Queue::schema_fields_DISPATCH_UNTIL => null,
                    Queue::schema_fields_end_at => \date('Y-m-d H:i:s'),
                ]);
                continue;
            }

            if ($this->resolveDeadWorkerRecoverableQueue($queue) instanceof DeadWorkerRecoverableQueueInterface) {
                $output = '';
                $recoveryPatch = $this->deadWorkerRecoveryPatch($queue, 0, $output);
                if ($recoveryPatch !== null) {
                    $message = $this->deadWorkerRecoveryMessage($queue, 0, $output);
                    $this->updateQueueSnapshotIf($queue, \array_replace([
                        Queue::schema_fields_status => Queue::status_pending,
                        Queue::schema_fields_finished => 0,
                        Queue::schema_fields_pid => 0,
                        Queue::schema_fields_auto => 1,
                        Queue::schema_fields_DISPATCH_TOKEN => null,
                        Queue::schema_fields_DISPATCH_UNTIL => null,
                        Queue::schema_fields_start_at => null,
                        Queue::schema_fields_end_at => null,
                        Queue::schema_fields_result => $message,
                        Queue::schema_fields_process => $this->appendProcessMessage($queue->getProcess(), $message),
                    ], $recoveryPatch));
                    continue;
                }

                $message = (string)__('可恢复队列处于 running 但没有 PID，且恢复契约拒绝恢复；已标记为 error，避免重复派发。');
                $this->updateQueueSnapshotIf($queue, [
                    Queue::schema_fields_status => Queue::status_error,
                    Queue::schema_fields_finished => 1,
                    Queue::schema_fields_pid => 0,
                    Queue::schema_fields_DISPATCH_TOKEN => null,
                    Queue::schema_fields_DISPATCH_UNTIL => null,
                    Queue::schema_fields_end_at => \date('Y-m-d H:i:s'),
                    Queue::schema_fields_result => $this->appendProcessMessage($queue->getResult(), $message),
                    Queue::schema_fields_process => $this->appendProcessMessage($queue->getProcess(), $message),
                ]);
                continue;
            }

            $message = (string)__('运行中队列没有记录 PID，已重置为 pending 等待重新调度。');
            $this->updateQueueSnapshotIf($queue, [
                Queue::schema_fields_status => Queue::status_pending,
                Queue::schema_fields_DISPATCH_TOKEN => null,
                Queue::schema_fields_DISPATCH_UNTIL => null,
                Queue::schema_fields_result => $this->appendProcessMessage($queue->getResult(), $message),
                Queue::schema_fields_process => $this->appendProcessMessage($queue->getProcess(), $message),
            ]);
        }
    }

    public function countRunningAutoQueues(): int
    {
        $items = $this->queue->reset()
            ->where($this->queue::schema_fields_finished, 0)
            ->where($this->queue::schema_fields_auto, 1)
            ->where($this->queue::schema_fields_status, $this->queue::status_running)
            ->select()
            ->fetch()
            ->getItems();

        return \count($items);
    }

    public function getMaxConcurrent(): int
    {
        return $this->resolveMaxConcurrent();
    }

    public function getWorkerMemoryLimit(): string
    {
        return $this->resolveWorkerMemoryLimit();
    }

    /**
     * Reopen a failed transport attempt for the same idempotent Queue row.
     * The Delivery caller remains authoritative and decides whether retrying
     * this handle is still eligible.
     */
    public function requeueTransportAttempt(int $queueId, string $expectedDispatchToken = ''): bool
    {
        if ($queueId < 1) {
            return false;
        }
        $expectedDispatchToken = \strtolower(\trim($expectedDispatchToken));
        if ($expectedDispatchToken !== '' && !$this->isDispatchToken($expectedDispatchToken)) {
            return false;
        }
        $queue = $this->loadFreshQueue($queueId);
        if ((int)$queue->getId() < 1
            || $queue->getStatus() !== Queue::status_error
            || (int)$queue->getPid() !== 0
            || ($expectedDispatchToken === ''
                ? $queue->getDispatchToken() !== ''
                : ($queue->getDispatchToken() === ''
                    || !\hash_equals($expectedDispatchToken, $queue->getDispatchToken())))
        ) {
            return false;
        }

        return $this->updateQueueSnapshotIf($queue, [
            Queue::schema_fields_status => Queue::status_pending,
            Queue::schema_fields_finished => 0,
            Queue::schema_fields_auto => 1,
            Queue::schema_fields_DISPATCH_TOKEN => null,
            Queue::schema_fields_DISPATCH_UNTIL => null,
            Queue::schema_fields_start_at => null,
            Queue::schema_fields_end_at => null,
        ]);
    }

    /**
     * Make a provisioned transport Queue visible to the generic auto scanner.
     * Provision creates it with auto=0 so a crash before Delivery bind cannot
     * dispatch an unbound attempt.
     */
    public function activateTransportAttempt(int $queueId): bool
    {
        if ($queueId < 1) {
            return false;
        }

        $queue = $this->loadFreshQueue($queueId);
        if ((int)$queue->getId() < 1 || !$this->isCleanPendingQueue($queue) || $queue->getAuto()) {
            return false;
        }

        return $this->updateQueueSnapshotIf($queue, [
            Queue::schema_fields_auto => 1,
        ]);
    }

    /**
     * Validate the dispatch fence before a detached Worker records its PID.
     * A stale Worker receives null and must exit without invoking a consumer.
     */
    public function attachClaimedWorker(int $queueId, string $dispatchToken, int $pid): ?Queue
    {
        $dispatchToken = \strtolower(\trim($dispatchToken));
        if ($queueId < 1 || $pid < 1 || \preg_match('/^[a-f0-9]{64}$/', $dispatchToken) !== 1) {
            return null;
        }
        $queue = $this->loadFreshQueue($queueId);
        if ((int)$queue->getId() < 1
            || $queue->getStatus() !== Queue::status_running
            || !\hash_equals($queue->getDispatchToken(), $dispatchToken)
            || (int)$queue->getPid() !== 0) {
            return null;
        }
        $dispatchUntil = $queue->getDispatchUntil();
        if ($dispatchUntil !== '' && $dispatchUntil <= \gmdate('Y-m-d H:i:s')) {
            return null;
        }
        if (!$this->updateQueueSnapshotIf($queue, [
            Queue::schema_fields_pid => $pid,
            Queue::schema_fields_start_at => \gmdate('Y-m-d H:i:s'),
            Queue::schema_fields_DISPATCH_UNTIL => null,
        ])) {
            return null;
        }

        $attached = $this->loadFreshQueue($queueId);
        return (int)$attached->getPid() === $pid
            && \hash_equals($attached->getDispatchToken(), $dispatchToken)
            ? $attached
            : null;
    }

    /** Clear a completed/released Worker's fence without relying on Model save
     * change detection for nullable columns. */
    public function clearClaimedWorkerFence(int $queueId, string $dispatchToken): bool
    {
        $dispatchToken = \strtolower(\trim($dispatchToken));
        if ($queueId < 1 || !$this->isDispatchToken($dispatchToken)) {
            return false;
        }

        $queue = $this->loadFreshQueue($queueId);
        $queueDisplayName = (int)$queue->getId() > 0 ? $queue->getName() : '';
        if ((int)$queue->getId() < 1
            || $queue->getDispatchToken() === ''
            || !\hash_equals($dispatchToken, $queue->getDispatchToken())
            || !\in_array((int)$queue->getPid(), [0, (int)\getmypid()], true)
        ) {
            return false;
        }
        $cleared = $this->updateQueueSnapshotIf($queue, [
            Queue::schema_fields_pid => 0,
            Queue::schema_fields_DISPATCH_TOKEN => null,
            Queue::schema_fields_DISPATCH_UNTIL => null,
        ]);
        if ($queueDisplayName !== '') {
            $this->releaseClaimedWorkerLease(
                $queueId,
                $dispatchToken,
                (int)\getmypid(),
                $queueDisplayName,
            );
        }
        if ($cleared) {
            return true;
        }

        $fresh = $this->loadFreshQueue($queueId);
        return (int)$fresh->getId() > 0
            && (int)$fresh->getPid() === 0
            && $fresh->getDispatchToken() === '';
    }

    /** @return array{confirmed:bool,retryable:bool,error_code:string} */
    public function terminateClaimedQueue(int $queueId, string $dispatchToken): array
    {
        $dispatchToken = \strtolower(\trim($dispatchToken));
        if ($queueId < 1 || !$this->isDispatchToken($dispatchToken)) {
            return ['confirmed' => false, 'retryable' => false, 'error_code' => 'queue_fence_invalid'];
        }

        $releasedMessage = (string)__('Queue Transport 检测到 PID 已不属于当前 attempt，未发送终止信号并已释放过期 fence。');
        $terminatedMessage = (string)__('Queue Transport 已确认终止超时 Worker。');
        $result = $this->transitionQueueAfterProcessRelease(
            $queueId,
            Queue::status_error,
            static function (Queue $queue, array $release) use ($releasedMessage, $terminatedMessage): array {
                $message = !empty($release['released_without_signal'])
                    ? $releasedMessage
                    : $terminatedMessage;

                return [
                    Queue::schema_fields_end_at => \gmdate('Y-m-d H:i:s'),
                    Queue::schema_fields_result => \trim($queue->getResult() . PHP_EOL . $message),
                    Queue::schema_fields_process => \trim((string)$queue->getProcess() . PHP_EOL . $message),
                ];
            },
            $dispatchToken,
            [Queue::status_running, Queue::status_error],
            true,
            true,
        );

        return [
            'confirmed' => !empty($result['confirmed']),
            'retryable' => !empty($result['retryable']),
            'error_code' => (string)($result['error_code'] ?? ''),
        ];
    }

    /**
     * Pause one Queue only after its recorded attempt is proven released.
     *
     * @return array<string,mixed>
     */
    public function stopQueueSafely(int $queueId): array
    {
        if ($this->hasActiveQueueTransaction()) {
            return $this->queueControlFailure('queue_transaction_active', true);
        }
        $result = $this->transitionQueueAfterProcessRelease(
            $queueId,
            Queue::status_stop,
            [Queue::schema_fields_end_at => \gmdate('Y-m-d H:i:s')],
        );
        if (!empty($result['confirmed'])) {
            $result['message'] = !empty($result['terminated'])
                ? (string)__('队列已暂停，并确认对应 Worker 已退出。')
                : (string)__('队列已暂停。');
        }

        return $result;
    }

    /**
     * Return a clean, non-active Queue to pending without probing or signalling
     * any PID. reset/continue/retry all share this fence.
     *
     * @return array<string,mixed>
     */
    public function requeueQueueSafely(int $queueId): array
    {
        $result = $this->transitionQueueAfterProcessRelease(
            $queueId,
            Queue::status_pending,
            [Queue::schema_fields_finished => 0],
            null,
            [],
            false,
        );
        if (!empty($result['confirmed'])) {
            $result['message'] = (string)__('队列已安全恢复为 pending，等待后续调度。');
        }

        return $result;
    }

    /**
     * Update business fields only while the Queue is a clean pending row. The
     * complete attempt fence and every touched source value participate in CAS,
     * so an edit and a scheduler claim cannot both win from the same snapshot.
     *
     * @param array<string,mixed> $updates
     * @return array<string,mixed>
     */
    public function updatePendingQueueSafely(int $queueId, array $updates): array
    {
        if ($queueId < 1) {
            return $this->queueControlFailure('queue_not_found', false);
        }

        $unknown = \array_diff(\array_keys($updates), $this->pendingQueueBusinessFields());
        if ($unknown !== []) {
            return $this->queueControlFailure('queue_edit_field_forbidden', false);
        }

        $queue = $this->loadFreshQueue($queueId);
        if ((int)$queue->getId() < 1) {
            return $this->queueControlFailure('queue_not_found', false);
        }
        if (!$this->isCleanPendingQueue($queue)) {
            return $this->queueControlFailure('queue_edit_active', true, (int)$queue->getPid());
        }

        if ($updates === []) {
            return $this->queueControlSuccess($queue, (string)__('队列没有需要更新的业务字段。'));
        }

        $expected = $this->queueFenceSnapshot($queue);
        $expected[Queue::schema_fields_finished] = $queue->getData(Queue::schema_fields_finished);
        foreach ($updates as $field => $_value) {
            $expected[$field] = $queue->getData($field);
        }
        if (!$this->updateQueueIf($expected, $updates)) {
            $fresh = $this->loadFreshQueue($queueId);
            if ((int)$fresh->getId() > 0
                && $this->isCleanPendingQueue($fresh)
                && $this->queueHasValues($fresh, $updates)
            ) {
                return $this->queueControlSuccess($fresh, (string)__('队列业务字段已更新。'));
            }

            return $this->queueControlFailure('queue_state_changed', true, (int)$queue->getPid());
        }

        return $this->queueControlSuccess(
            $this->loadFreshQueue($queueId),
            (string)__('队列业务字段已安全更新。'),
        );
    }

    /**
     * Claim a clean pending or inactive terminal row for a foreground/manual
     * CLI Worker. Manual Workers intentionally have no managed dispatch token,
     * but terminal reopening, force preparation, and ownership acquisition all
     * happen in the same CAS so the scheduler never observes an intermediate
     * pending generation created by this operation.
     *
     * @return array<string,mixed>
     */
    public function claimQueueForManualRun(
        int $queueId,
        int $pid,
        array $updates = [],
        bool $prepareForceRebuild = false,
    ): array
    {
        if ($this->hasActiveQueueTransaction()) {
            return $this->queueControlFailure('queue_transaction_active', true, $pid);
        }
        if ($queueId < 1 || $pid < 1) {
            return $this->queueControlFailure('queue_manual_claim_unavailable', false, $pid);
        }
        if (\array_diff(\array_keys($updates), $this->pendingQueueBusinessFields()) !== []) {
            return $this->queueControlFailure('queue_edit_field_forbidden', false, $pid);
        }
        $queue = $this->loadFreshQueue($queueId);
        if ((int)$queue->getId() < 1) {
            return $this->queueControlFailure('queue_not_found', false);
        }
        if ($queue->isScopeQuarantined()) {
            return $this->queueControlFailure('queue_scope_quarantined', false, (int)$queue->getPid());
        }
        if (!$this->isManualClaimableQueue($queue)) {
            return $this->queueControlFailure('queue_manual_claim_unavailable', true, (int)$queue->getPid());
        }

        $forcePrepared = false;
        if ($prepareForceRebuild) {
            $content = \json_decode((string)$queue->getContent(), true);
            if (\is_array($content)) {
                $content['_force_rebuild'] = 1;
                $updates = \array_replace($updates, [
                    Queue::schema_fields_content => (string)(\json_encode(
                        $content,
                        \JSON_UNESCAPED_UNICODE | \JSON_INVALID_UTF8_SUBSTITUTE,
                    ) ?: (string)$queue->getContent()),
                    Queue::schema_fields_result => '',
                    Queue::schema_fields_process => '',
                ]);
                $forcePrepared = true;
            }
        }

        $expected = $this->queueFenceSnapshot($queue) + [
            Queue::schema_fields_finished => $queue->getData(Queue::schema_fields_finished),
            Queue::schema_fields_auto => $queue->getData(Queue::schema_fields_auto),
            Queue::schema_fields_name => $queue->getData(Queue::schema_fields_name),
            Queue::schema_fields_type_id => $queue->getData(Queue::schema_fields_type_id),
            Queue::schema_fields_start_at => $queue->getData(Queue::schema_fields_start_at),
            Queue::schema_fields_end_at => $queue->getData(Queue::schema_fields_end_at),
        ];
        foreach (\array_keys($updates) as $field) {
            $expected[$field] = $queue->getData($field);
        }
        $claimUpdates = $updates + [
            Queue::schema_fields_status => Queue::status_running,
            Queue::schema_fields_finished => 0,
            Queue::schema_fields_pid => $pid,
            Queue::schema_fields_start_at => \gmdate('Y-m-d H:i:s'),
            Queue::schema_fields_end_at => null,
            Queue::schema_fields_NOT_BEFORE => null,
        ];
        if (!$this->updateQueueIf($expected, $claimUpdates)) {
            return $this->queueControlFailure('queue_state_changed', true, $pid);
        }

        $fresh = $this->loadFreshQueue($queueId);
        if ((int)$fresh->getId() < 1
            || $fresh->getStatus() !== Queue::status_running
            || (int)$fresh->getPid() !== $pid
            || $fresh->getDispatchToken() !== ''
        ) {
            return $this->queueControlFailure('queue_state_changed', true, $pid);
        }

        $result = $this->queueControlSuccess($fresh, (string)__('队列已由当前 CLI Worker 安全认领。'));
        $result['force_prepared'] = $forcePrepared;

        return $result;
    }

    /** @return array<string,mixed> */
    public function markQueueWorkerExecutingSafely(
        int $queueId,
        string $dispatchToken,
        int $pid,
    ): array {
        return $this->updateOwnedQueueWorker(
            $queueId,
            $dispatchToken,
            $pid,
            static fn(Queue $queue): array => [
                Queue::schema_fields_result => \trim(
                    $queue->getResult() . PHP_EOL . (string)__('正在执行...')
                ),
            ],
        );
    }

    /**
     * Persist non-control telemetry for the exact active Worker generation.
     *
     * Runtime progress must not use updatePendingQueueSafely(): that operation
     * intentionally rejects running rows. This fence-aware path allows only
     * observer fields and cannot change Queue ownership or lifecycle state.
     *
     * @param array<string,mixed> $updates
     * @return array<string,mixed>
     */
    public function updateQueueWorkerTelemetrySafely(
        int $queueId,
        string $dispatchToken,
        int $pid,
        array $updates,
    ): array {
        if ($updates === []) {
            return $this->queueControlFailure('queue_worker_telemetry_empty', false, $pid);
        }

        $allowed = [
            Queue::schema_fields_content,
            Queue::schema_fields_result,
            Queue::schema_fields_process,
        ];
        $telemetry = [];
        foreach ($updates as $field => $value) {
            $field = (string)$field;
            if (!\in_array($field, $allowed, true)) {
                return $this->queueControlFailure(
                    'queue_worker_telemetry_field_forbidden',
                    false,
                    $pid,
                );
            }
            if (!\is_string($value)) {
                return $this->queueControlFailure(
                    'queue_worker_telemetry_value_invalid',
                    false,
                    $pid,
                );
            }
            $telemetry[$field] = $value;
        }

        return $this->updateOwnedQueueWorker(
            $queueId,
            $dispatchToken,
            $pid,
            static fn(Queue $queue): array => $queue->getStatus() === Queue::status_running
                && !$queue->isFinished()
                    ? $telemetry
                    : [],
        );
    }

    /** @return array<string,mixed> */
    public function completeQueueWorkerSafely(
        int $queueId,
        string $dispatchToken,
        int $pid,
        string $result,
    ): array {
        return $this->updateOwnedQueueWorker(
            $queueId,
            $dispatchToken,
            $pid,
            static function (Queue $queue) use ($result): array {
                $updates = [
                    Queue::schema_fields_pid => 0,
                    Queue::schema_fields_DISPATCH_TOKEN => null,
                    Queue::schema_fields_DISPATCH_UNTIL => null,
                    Queue::schema_fields_NOT_BEFORE => null,
                    Queue::schema_fields_result => \trim($queue->getResult() . PHP_EOL . $result),
                ];
                $preserve = !$queue->isFinished() && \in_array($queue->getStatus(), [
                    Queue::status_pending,
                    Queue::status_error,
                    Queue::status_stop,
                ], true);
                if (!$preserve) {
                    $updates[Queue::schema_fields_status] = Queue::status_done;
                    $updates[Queue::schema_fields_finished] = 1;
                }

                return $updates;
            },
            true,
        );
    }

    /**
     * Atomically release the current Worker fence and return the same Queue row
     * to Scheduler ownership. Consumers must use this finalizer instead of
     * persisting running -> pending while their PID/token generation is active.
     *
     * @return array<string,mixed>
     */
    public function deferQueueWorkerSafely(
        int $queueId,
        string $dispatchToken,
        int $pid,
        string $content,
        string $processMessage,
        string $result,
        string $notBefore,
    ): array {
        if (!$this->isValidNotBefore($notBefore)) {
            return $this->queueControlFailure('queue_deferred_not_before_invalid', false, $pid);
        }
        return $this->updateOwnedQueueWorker(
            $queueId,
            $dispatchToken,
            $pid,
            static function (Queue $queue) use ($content, $processMessage, $result, $notBefore): array {
                return [
                    Queue::schema_fields_status => Queue::status_pending,
                    Queue::schema_fields_finished => 0,
                    Queue::schema_fields_pid => 0,
                    Queue::schema_fields_DISPATCH_TOKEN => null,
                    Queue::schema_fields_DISPATCH_UNTIL => null,
                    Queue::schema_fields_NOT_BEFORE => $notBefore,
                    Queue::schema_fields_start_at => null,
                    Queue::schema_fields_end_at => null,
                    Queue::schema_fields_content => $content,
                    Queue::schema_fields_process => \trim(
                        $queue->getProcess() . PHP_EOL . $processMessage
                    ),
                    Queue::schema_fields_result => \trim(
                        $queue->getResult() . PHP_EOL . $result
                    ),
                ];
            },
            true,
        );
    }

    private function isValidNotBefore(string $notBefore): bool
    {
        if (\preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $notBefore) !== 1) {
            return false;
        }
        $value = \DateTimeImmutable::createFromFormat(
            '!Y-m-d H:i:s',
            $notBefore,
            new \DateTimeZone('UTC')
        );
        $errors = \DateTimeImmutable::getLastErrors();

        return $value instanceof \DateTimeImmutable
            && (!\is_array($errors)
                || (($errors['warning_count'] ?? 0) === 0 && ($errors['error_count'] ?? 0) === 0))
            && $value->format('Y-m-d H:i:s') === $notBefore;
    }

    /** @return array<string,mixed> */
    public function failQueueWorkerSafely(
        int $queueId,
        string $dispatchToken,
        int $pid,
        string $result,
        bool $prepend = false,
        string $processMessage = '',
    ): array {
        return $this->updateOwnedQueueWorker(
            $queueId,
            $dispatchToken,
            $pid,
            static function (Queue $queue) use ($result, $prepend, $processMessage): array {
                $updates = [
                    Queue::schema_fields_status => Queue::status_error,
                    Queue::schema_fields_pid => 0,
                    Queue::schema_fields_DISPATCH_TOKEN => null,
                    Queue::schema_fields_DISPATCH_UNTIL => null,
                    Queue::schema_fields_NOT_BEFORE => null,
                    Queue::schema_fields_end_at => \gmdate('Y-m-d H:i:s'),
                    Queue::schema_fields_result => $prepend
                        ? \trim($result . PHP_EOL . $queue->getResult())
                        : \trim($queue->getResult() . PHP_EOL . $result),
                ];
                $processMessage = \trim($processMessage);
                if ($processMessage !== '') {
                    $updates[Queue::schema_fields_process] = \trim(
                        $queue->getProcess() . PHP_EOL . $processMessage
                    );
                }

                return $updates;
            },
            true,
        );
    }

    /**
     * Release the current attempt and atomically return the Queue to pending.
     *
     * @return array<string,mixed>
     */
    public function takeoverQueueSafely(
        int $queueId,
        bool $force = true,
        string $owner = 'system_scheduler',
        string $reason = 'force_takeover',
        ?bool $auto = null,
        bool $markForceRebuild = true,
        bool $clearOutput = false,
    ): array {
        if ($this->hasActiveQueueTransaction()) {
            return $this->queueControlFailure('queue_transaction_active', true);
        }
        $owner = \in_array($owner, ['system_scheduler', 'manual_cli'], true)
            ? $owner
            : 'system_scheduler';
        $reason = \trim($reason) ?: 'force_takeover';
        $auto ??= $owner === 'system_scheduler';
        $message = $owner === 'manual_cli'
            ? (string)__('强制接管完成；队列归手动执行，不会自动派发。')
            : (string)__('强制接管完成；队列已重置为 pending，等待系统调度。');
        // Generate all fallible entropy before any Worker can be signalled.
        $takeoverToken = \bin2hex(\random_bytes(12));

        $result = $this->transitionQueueAfterProcessRelease(
            $queueId,
            Queue::status_pending,
            static function (Queue $queue) use (
                $owner,
                $reason,
                $auto,
                $markForceRebuild,
                $clearOutput,
                $message,
                $takeoverToken,
            ): array {
                $updates = [
                    Queue::schema_fields_finished => 0,
                    Queue::schema_fields_auto => $auto ? 1 : 0,
                    Queue::schema_fields_start_at => null,
                    Queue::schema_fields_end_at => null,
                ];
                $content = \json_decode((string)$queue->getContent(), true);
                if (\is_array($content) && $content !== []) {
                    if ($markForceRebuild) {
                        $content['_force_rebuild'] = 1;
                    }
                    $content['_queue_takeover'] = [
                        'token' => $takeoverToken,
                        'owner' => $owner,
                        'reason' => $reason,
                        'previous_pid' => (int)$queue->getPid(),
                        'previous_status' => $queue->getStatus(),
                        'execute_in_request' => false,
                        'taken_over_at' => \date('Y-m-d H:i:s'),
                    ];
                    $encodedContent = \json_encode(
                        $content,
                        \JSON_UNESCAPED_UNICODE
                        | \JSON_INVALID_UTF8_SUBSTITUTE
                        | \JSON_PARTIAL_OUTPUT_ON_ERROR,
                    );
                    if (\is_string($encodedContent)) {
                        $updates[Queue::schema_fields_content] = $encodedContent;
                    }
                }
                if ($clearOutput) {
                    $updates[Queue::schema_fields_result] = '';
                    $updates[Queue::schema_fields_process] = $message;
                } else {
                    $updates[Queue::schema_fields_process] = \trim((string)$queue->getProcess() . PHP_EOL . $message);
                }

                return $updates;
            },
            null,
            [],
            $force,
        );
        if (!empty($result['confirmed'])) {
            $result['message'] = $message;
        }

        return $result;
    }

    /**
     * Delete by a fenced snapshot. A running attempt is released only when
     * force=true; tokenless live Workers always fail closed.
     *
     * @return array<string,mixed>
     */
    public function deleteQueueSafely(int $queueId, bool $force = false): array
    {
        if ($this->hasActiveQueueTransaction()) {
            return $this->queueControlFailure('queue_transaction_active', true);
        }
        if ($queueId < 1) {
            return $this->queueControlFailure('queue_not_found', false);
        }

        for ($attempt = 0; $attempt < 2; $attempt++) {
            $queue = $this->loadFreshQueue($queueId);
            if ((int)$queue->getId() < 1) {
                return $this->queueControlFailure('queue_not_found', false);
            }
            $hasActiveAttempt = $this->hasActiveOrDirtyAttempt($queue);
            if ($hasActiveAttempt && !$force) {
                return $this->queueControlFailure('queue_force_required', false, (int)$queue->getPid());
            }

            $release = $hasActiveAttempt
                ? $this->releaseQueueAttempt($queue)
                : $this->releasedQueueAttempt($queue);
            if (empty($release['confirmed'])) {
                return $release;
            }

            $expected = $this->queueFenceSnapshot($queue);
            try {
                $deleted = $this->deleteQueueIf($expected, $queue);
            } finally {
                // Process release is independent from database deletion. Even
                // when EAV cleanup rolls back, remove only the exact old lease.
                $this->cleanupReleasedQueueLease($release);
            }
            if ($deleted) {
                return [
                    'confirmed' => true,
                    'success' => true,
                    'retryable' => false,
                    'error_code' => '',
                    'message' => (string)__('队列已删除。'),
                    'queue_id' => $queueId,
                    'pid' => (int)$queue->getPid(),
                    'terminated' => !empty($release['terminated']),
                    'released_without_signal' => !empty($release['released_without_signal']),
                    'data' => $queue->getData(),
                ];
            }
            if (!$this->sameClaimAttachedAfterConflict($queue, $this->loadFreshQueue($queueId))) {
                return $this->queueControlFailure('queue_state_changed', true, (int)$queue->getPid());
            }
        }

        return $this->queueControlFailure('queue_state_changed', true);
    }

    /**
     * Remove only this Worker's exact Processer lease. No OS signal is sent.
     */
    public function releaseClaimedWorkerLease(
        int $queueId,
        string $dispatchToken,
        int $pid = 0,
        string $queueDisplayName = '',
    ): bool {
        $dispatchToken = \strtolower(\trim($dispatchToken));
        $pid = $pid > 0 ? $pid : (int)\getmypid();
        if ($queueId < 1 || $pid < 1 || !$this->isDispatchToken($dispatchToken)) {
            return false;
        }
        if ($queueDisplayName === '') {
            $queue = $this->loadFreshQueue($queueId);
            if ((int)$queue->getId() < 1) {
                return false;
            }
            $queueDisplayName = $queue->getName();
        } else {
            $queue = null;
        }

        return $this->releaseClaimedWorkerLeaseByTaskName(
            $queueId,
            $dispatchToken,
            $pid,
            $this->normalizeQueueTaskName($queueDisplayName, $queueId),
        );
    }

    /**
     * Remove a Worker's exact lease using the canonical --name from argv. This
     * path deliberately requires no Queue row, so delete may win before the
     * child performs its first database load without leaving a managed lease.
     */
    public function releaseClaimedWorkerLeaseByTaskName(
        int $queueId,
        string $dispatchToken,
        int $pid,
        string $queueTaskName,
    ): bool {
        $dispatchToken = \strtolower(\trim($dispatchToken));
        $queueTaskName = \trim($queueTaskName);
        if ($queueId < 1 || $pid < 1 || $queueTaskName === '' || !$this->isDispatchToken($dispatchToken)) {
            return false;
        }

        try {
            return $this->removeManagedQueueProcessLease($pid, $queueTaskName, $dispatchToken);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @param array<string,mixed>|\Closure(Queue,array<string,mixed>):array<string,mixed> $updates
     * @param list<string> $allowedStatuses
     * @return array<string,mixed>
     */
    private function transitionQueueAfterProcessRelease(
        int $queueId,
        string $targetStatus,
        array|\Closure $updates = [],
        ?string $requiredDispatchToken = null,
        array $allowedStatuses = [],
        bool $allowActiveRelease = true,
        bool $allowTokenOnlyRelease = false,
    ): array {
        if ($this->hasActiveQueueTransaction()) {
            return $this->queueControlFailure('queue_transaction_active', true);
        }
        if ($queueId < 1) {
            return $this->queueControlFailure('queue_not_found', false);
        }

        for ($attempt = 0; $attempt < 2; $attempt++) {
            $queue = $this->loadFreshQueue($queueId);
            if ((int)$queue->getId() < 1) {
                return $this->queueControlFailure('queue_not_found', false);
            }
            $dispatchToken = $queue->getDispatchToken();
            if ($requiredDispatchToken !== null
                && ($dispatchToken === '' || !\hash_equals($dispatchToken, $requiredDispatchToken))) {
                return $this->queueControlFailure('queue_fence_mismatch', false, (int)$queue->getPid());
            }
            if ($allowedStatuses !== [] && !\in_array($queue->getStatus(), $allowedStatuses, true)) {
                return $this->queueControlFailure('queue_not_terminable', false, (int)$queue->getPid());
            }
            $hasActiveAttempt = $this->hasActiveOrDirtyAttempt($queue);
            if ($hasActiveAttempt && !$allowActiveRelease) {
                return $this->queueControlFailure('queue_force_required', false, (int)$queue->getPid());
            }

            $release = $hasActiveAttempt
                ? $this->releaseQueueAttempt($queue, $allowTokenOnlyRelease)
                : $this->releasedQueueAttempt($queue);
            if (empty($release['confirmed'])) {
                return $release;
            }

            try {
                $businessUpdates = $updates instanceof \Closure ? $updates($queue, $release) : $updates;
                $businessUpdates = $this->sanitizeQueueControlUpdates($businessUpdates);
                $expected = $this->queueFenceSnapshot($queue);
                foreach (\array_keys($businessUpdates) as $field) {
                    // Values such as content/process/result are derived from this exact
                    // snapshot. Include every touched source column in the CAS so a
                    // concurrent producer/Worker update can never be overwritten.
                    $expected[$field] = $queue->getData($field);
                }
                $businessUpdates[Queue::schema_fields_status] = $targetStatus;
                $businessUpdates[Queue::schema_fields_pid] = 0;
                $businessUpdates[Queue::schema_fields_DISPATCH_TOKEN] = null;
                $businessUpdates[Queue::schema_fields_DISPATCH_UNTIL] = null;
                $businessUpdates[Queue::schema_fields_NOT_BEFORE] = null;

                $updated = $this->updateQueueIf($expected, $businessUpdates);
                if ($updated) {
                    $fresh = $this->loadFreshQueue($queueId);

                    return [
                        'confirmed' => true,
                        'success' => true,
                        'retryable' => false,
                        'error_code' => '',
                        'message' => (string)__('队列状态已安全更新。'),
                        'queue_id' => $queueId,
                        'pid' => (int)$queue->getPid(),
                        'terminated' => !empty($release['terminated']),
                        'released_without_signal' => !empty($release['released_without_signal']),
                        'data' => (int)$fresh->getId() > 0 ? $fresh->getData() : [],
                    ];
                }
                $fresh = $this->loadFreshQueue($queueId);
                if ($this->queueReachedTransition($fresh, $targetStatus, $businessUpdates)) {
                    return [
                        'confirmed' => true,
                        'success' => true,
                        'retryable' => false,
                        'error_code' => '',
                        'message' => (string)__('队列状态已安全更新。'),
                        'queue_id' => $queueId,
                        'pid' => (int)$queue->getPid(),
                        'terminated' => !empty($release['terminated']),
                        'released_without_signal' => !empty($release['released_without_signal']),
                        'data' => $fresh->getData(),
                    ];
                }
                // A pid=0 claim can race only with attachClaimedWorker(). Reload once
                // and, if attach won, enter the managed PID path. Once a real PID was
                // already released, never follow a changed fence into a newer attempt.
                if (!$this->sameClaimAttachedAfterConflict($queue, $fresh)) {
                    return $this->queueControlFailure('queue_state_changed', true, (int)$queue->getPid());
                }
            } finally {
                // Once process ownership is released, remove only that generation's
                // exact managed lease even when business update calculation or CAS fails.
                $this->cleanupReleasedQueueLease($release);
            }
        }

        return $this->queueControlFailure('queue_state_changed', true);
    }

    /** @return array<string,mixed> */
    private function releaseQueueAttempt(Queue $queue, bool $allowTokenOnlyRelease = false): array
    {
        $queueId = (int)$queue->getId();
        $pid = (int)$queue->getPid();
        $dispatchToken = $queue->getDispatchToken();
        if ($pid < 1) {
            if ($queue->getStatus() === Queue::status_running && !$this->isDispatchToken($dispatchToken)) {
                // A tokenless running+pid=0 row has no generation fence. A
                // manual/legacy Worker could publish its PID after this read,
                // so neither a state transition nor deletion is linearizable.
                return $this->queueControlFailure('queue_process_unmanaged', true);
            }
            if ($this->isDispatchToken($dispatchToken)
                && $queue->getStatus() !== Queue::status_running
                && !$allowTokenOnlyRelease
            ) {
                // A non-running row that still owns a generation token but has
                // no PID cannot prove that the corresponding Worker exited.
                return $this->queueControlFailure('queue_process_unmanaged', true);
            }
            return $this->releasedQueueAttempt($queue);
        }
        if ($pid === $this->currentProcessId()) {
            return $this->queueControlFailure('queue_process_self', true, $pid);
        }
        if (!$this->isDispatchToken($dispatchToken)) {
            $state = $this->probeQueueProcessState($pid);
            if ($state === Processer::PROCESS_STATE_EXITED) {
                return $this->releasedQueueAttempt($queue);
            }
            if ($state === Processer::PROCESS_STATE_UNKNOWN) {
                return $this->queueControlFailure('queue_identity_unavailable', true, $pid);
            }

            return $this->queueControlFailure('queue_process_unmanaged', true, $pid);
        }

        $queueName = $this->normalizeQueueTaskName($queue->getName(), $queueId);
        $expectedPname = '--name=' . $queueName . ' --launch-id=' . $dispatchToken;
        try {
            $termination = $this->terminateManagedQueueProcess(
                $pid,
                $queueName,
                $dispatchToken,
                $expectedPname,
                [
                    'id' => (string)$queueId,
                    'name' => $queueName,
                    'launch-id' => $dispatchToken,
                    'dispatch-token' => $dispatchToken,
                ],
            );
        } catch (\Throwable) {
            return $this->queueControlFailure('queue_termination_failed', true, $pid);
        }
        if (empty($termination['released'])) {
            $errorCode = (string)($termination['state'] ?? Processer::PROCESS_STATE_UNKNOWN)
                === Processer::PROCESS_STATE_UNKNOWN
                ? 'queue_identity_unavailable'
                : 'queue_termination_unconfirmed';

            return $this->queueControlFailure($errorCode, true, $pid);
        }

        return [
            'confirmed' => true,
            'retryable' => false,
            'error_code' => '',
            'pid' => $pid,
            'terminated' => !empty($termination['terminated']),
            'released_without_signal' => empty($termination['terminated']),
            'expected_process_name' => $queueName,
            'dispatch_token' => $dispatchToken,
        ];
    }

    /** @return array<string,mixed> */
    private function releasedQueueAttempt(Queue $queue): array
    {
        $queueId = (int)$queue->getId();
        $pid = (int)$queue->getPid();
        $dispatchToken = $queue->getDispatchToken();
        $expectedProcessName = '';
        if ($pid > 0 && $this->isDispatchToken($dispatchToken)) {
            $queueName = $this->normalizeQueueTaskName($queue->getName(), $queueId);
            $expectedProcessName = $queueName;
        }

        return [
            'confirmed' => true,
            'retryable' => false,
            'error_code' => '',
            'pid' => $pid,
            'terminated' => false,
            'released_without_signal' => true,
            'expected_process_name' => $expectedProcessName,
            'dispatch_token' => $dispatchToken,
        ];
    }

    /** @return array<string,mixed> */
    private function queueControlFailure(string $errorCode, bool $retryable, int $pid = 0): array
    {
        $message = match ($errorCode) {
            'queue_not_found' => (string)__('队列不存在。'),
            'queue_fence_mismatch' => (string)__('队列派发令牌已变化，未覆盖新的执行代次。'),
            'queue_not_terminable' => (string)__('队列当前状态不允许终止。'),
            'queue_force_required' => (string)__('队列存在活动执行代次，请先暂停或显式使用 force。'),
            'queue_process_self' => (string)__('当前进程就是该队列 Worker，系统拒绝自我终止。'),
            'queue_process_unmanaged' => (string)__('队列由未携带派发令牌的手动或旧 Worker 运行；为避免误杀，系统已拒绝终止，请手动结束该进程后重试。'),
            'queue_identity_unavailable' => (string)__('暂时无法确认队列进程身份，系统未发送终止信号，请稍后重试。'),
            'queue_termination_failed' => (string)__('队列 Worker 终止操作失败，状态未变更。'),
            'queue_termination_unconfirmed' => (string)__('尚未确认队列 Worker 已退出，状态未变更，请稍后重试。'),
            'queue_state_changed' => (string)__('队列状态已被并发修改，系统未覆盖新状态，请重试。'),
            'queue_edit_active' => (string)__('只有没有活动执行代次的 pending 队列才能编辑。'),
            'queue_edit_field_forbidden' => (string)__('队列状态、PID、完成标记和 dispatch fence 只能通过专用控制操作修改。'),
            'queue_scope_quarantined' => (string)__('队列 Scope 已 quarantine，拒绝领取或执行。'),
            'queue_manual_claim_unavailable' => (string)__('队列当前不是可由 CLI 安全认领的 clean pending 或无活动执行代次的终态。'),
            'queue_transaction_active' => (string)__('当前请求已在数据库事务中；为避免外层回滚与 Worker 副作用失配，Queue 控制操作已拒绝。'),
            'queue_worker_fence_lost' => (string)__('Queue Worker 已失去当前执行代次 ownership，未写入任何状态。'),
            default => (string)__('队列进程控制失败。'),
        };

        return [
            'confirmed' => false,
            'success' => false,
            'retryable' => $retryable,
            'error_code' => $errorCode,
            'message' => $message,
            'pid' => $pid,
            'terminated' => false,
            'released_without_signal' => true,
        ];
    }

    /**
     * @param \Closure(Queue):array<string,mixed> $buildUpdates
     * @return array<string,mixed>
     */
    private function updateOwnedQueueWorker(
        int $queueId,
        string $dispatchToken,
        int $pid,
        \Closure $buildUpdates,
        bool $releaseManagedLease = false,
    ): array {
        $dispatchToken = \strtolower(\trim($dispatchToken));
        if ($queueId < 1 || $pid < 1 || ($dispatchToken !== '' && !$this->isDispatchToken($dispatchToken))) {
            return $this->queueControlFailure('queue_worker_fence_lost', false, $pid);
        }
        $queue = $this->loadFreshQueue($queueId);
        if ((int)$queue->getId() < 1) {
            return $this->queueControlFailure('queue_not_found', false, $pid);
        }
        $owned = (int)$queue->getPid() === $pid;
        if ($dispatchToken !== '') {
            $owned = $owned
                && $queue->getDispatchToken() !== ''
                && \hash_equals($dispatchToken, $queue->getDispatchToken());
        } else {
            $owned = $owned && $queue->getDispatchToken() === '';
        }
        if (!$owned) {
            return $this->queueControlFailure('queue_worker_fence_lost', true, $pid);
        }

        $updates = $buildUpdates($queue);
        if ($updates === [] || !$this->updateQueueSnapshotIf($queue, $updates)) {
            return $this->queueControlFailure('queue_state_changed', true, $pid);
        }
        if ($releaseManagedLease && $this->isDispatchToken($dispatchToken)) {
            $this->releaseClaimedWorkerLeaseByTaskName(
                $queueId,
                $dispatchToken,
                $pid,
                $this->normalizeQueueTaskName($queue->getName(), $queueId),
            );
        }

        return $this->queueControlSuccess(
            $this->loadFreshQueue($queueId),
            (string)__('Queue Worker 状态已按当前执行代次安全写入。'),
        );
    }

    /** @return array<string,mixed> */
    private function queueControlSuccess(Queue $queue, string $message): array
    {
        return [
            'confirmed' => true,
            'success' => true,
            'retryable' => false,
            'error_code' => '',
            'message' => $message,
            'queue_id' => (int)$queue->getId(),
            'pid' => (int)$queue->getPid(),
            'terminated' => false,
            'released_without_signal' => true,
            'data' => $queue->getData(),
        ];
    }

    private function hasActiveOrDirtyAttempt(Queue $queue): bool
    {
        $rawToken = $queue->getData(Queue::schema_fields_DISPATCH_TOKEN);
        $rawUntil = $queue->getData(Queue::schema_fields_DISPATCH_UNTIL);

        return $queue->getStatus() === Queue::status_running
            || (int)$queue->getPid() > 0
            || ($rawToken !== null && $rawToken !== '')
            || ($rawUntil !== null && $rawUntil !== '');
    }

    /** @return list<string> */
    private function pendingQueueBusinessFields(): array
    {
        return [
            Queue::schema_fields_type_id,
            Queue::schema_fields_name,
            Queue::schema_fields_module,
            Queue::schema_fields_BIZ_KEY,
            Queue::schema_fields_content,
            Queue::schema_fields_result,
            Queue::schema_fields_process,
            Queue::schema_fields_auto,
        ];
    }

    private function isCleanPendingQueue(Queue $queue): bool
    {
        return $queue->getStatus() === Queue::status_pending
            && !$queue->isFinished()
            && (int)$queue->getPid() === 0
            && $queue->getData(Queue::schema_fields_DISPATCH_TOKEN) === null
            && $queue->getData(Queue::schema_fields_DISPATCH_UNTIL) === null;
    }

    private function isManualClaimableQueue(Queue $queue): bool
    {
        if ((int)$queue->getPid() !== 0
            || $queue->getData(Queue::schema_fields_DISPATCH_TOKEN) !== null
            || $queue->getData(Queue::schema_fields_DISPATCH_UNTIL) !== null
        ) {
            return false;
        }

        if ($queue->getStatus() === Queue::status_pending) {
            return !$queue->isFinished();
        }

        return \in_array($queue->getStatus(), [
            Queue::status_done,
            Queue::status_error,
            Queue::status_stop,
        ], true);
    }

    /** @param array<string,mixed> $values */
    private function queueHasValues(Queue $queue, array $values): bool
    {
        foreach ($values as $field => $value) {
            if ($queue->getData($field) != $value) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string,mixed> $updates */
    private function queueReachedTransition(Queue $queue, string $targetStatus, array $updates): bool
    {
        return (int)$queue->getId() > 0
            && $queue->getStatus() === $targetStatus
            && (int)$queue->getPid() === 0
            && $queue->getData(Queue::schema_fields_DISPATCH_TOKEN) === null
            && $queue->getData(Queue::schema_fields_DISPATCH_UNTIL) === null
            && $this->queueHasValues($queue, $updates);
    }

    /** @return array<string,mixed> */
    private function queueFenceSnapshot(Queue $queue): array
    {
        return [
            Queue::schema_fields_ID => (int)$queue->getId(),
            Queue::schema_fields_type_id => $queue->getData(Queue::schema_fields_type_id),
            Queue::schema_fields_name => $queue->getData(Queue::schema_fields_name),
            Queue::schema_fields_module => $queue->getData(Queue::schema_fields_module),
            Queue::schema_fields_BIZ_KEY => $queue->getData(Queue::schema_fields_BIZ_KEY),
            Queue::schema_fields_status => $queue->getStatus(),
            Queue::schema_fields_pid => (int)$queue->getPid(),
            Queue::schema_fields_DISPATCH_TOKEN => $queue->getData(Queue::schema_fields_DISPATCH_TOKEN),
            Queue::schema_fields_DISPATCH_UNTIL => $queue->getData(Queue::schema_fields_DISPATCH_UNTIL),
            Queue::schema_fields_NOT_BEFORE => $queue->getData(Queue::schema_fields_NOT_BEFORE),
            Queue::schema_fields_finished => $queue->getData(Queue::schema_fields_finished),
            Queue::schema_fields_auto => $queue->getData(Queue::schema_fields_auto),
            Queue::schema_fields_content => $queue->getData(Queue::schema_fields_content),
            Queue::schema_fields_result => $queue->getData(Queue::schema_fields_result),
            Queue::schema_fields_process => $queue->getData(Queue::schema_fields_process),
        ];
    }

    /** @return array<string,mixed> */
    private function queueDispatchClaimSnapshot(Queue $queue): array
    {
        return [
            Queue::schema_fields_ID => (int)$queue->getId(),
            Queue::schema_fields_status => Queue::status_pending,
            Queue::schema_fields_finished => 0,
            Queue::schema_fields_auto => 1,
            Queue::schema_fields_pid => 0,
            Queue::schema_fields_DISPATCH_TOKEN => null,
            Queue::schema_fields_DISPATCH_UNTIL => null,
            Queue::schema_fields_NOT_BEFORE => $queue->getData(Queue::schema_fields_NOT_BEFORE),
            Queue::schema_fields_name => $queue->getData(Queue::schema_fields_name),
            Queue::schema_fields_type_id => $queue->getData(Queue::schema_fields_type_id),
        ];
    }

    /** @param array<string,mixed> $updates */
    private function updateQueueSnapshotIf(Queue $queue, array $updates): bool
    {
        $expected = $this->queueFenceSnapshot($queue);
        foreach (\array_keys($updates) as $field) {
            $expected[$field] = $queue->getData($field);
        }

        return $this->updateQueueIf($expected, $updates);
    }

    private function releaseReconciledQueueLease(Queue $queue, string $queueTaskName): bool
    {
        $dispatchToken = $queue->getDispatchToken();
        $pid = (int)$queue->getPid();
        if ($pid < 1 || !$this->isDispatchToken($dispatchToken)) {
            return false;
        }

        return $this->releaseClaimedWorkerLeaseByTaskName(
            (int)$queue->getId(),
            $dispatchToken,
            $pid,
            $queueTaskName,
        );
    }

    private function sameClaimAttachedAfterConflict(Queue $before, Queue $after): bool
    {
        $dispatchToken = $before->getDispatchToken();

        return (int)$before->getPid() === 0
            && $before->getStatus() === Queue::status_running
            && $this->isDispatchToken($dispatchToken)
            && (int)$after->getId() === (int)$before->getId()
            && $after->getStatus() === Queue::status_running
            && (int)$after->getPid() > 0
            && $after->getDispatchToken() !== ''
            && \hash_equals($dispatchToken, $after->getDispatchToken());
    }

    /** @param array<string,mixed> $updates @return array<string,mixed> */
    private function sanitizeQueueControlUpdates(array $updates): array
    {
        $allowed = [
            Queue::schema_fields_finished,
            Queue::schema_fields_auto,
            Queue::schema_fields_start_at,
            Queue::schema_fields_end_at,
            Queue::schema_fields_result,
            Queue::schema_fields_process,
            Queue::schema_fields_content,
        ];

        return \array_intersect_key($updates, \array_flip($allowed));
    }

    private function cleanupReleasedQueueLease(array $release): bool
    {
        $pid = (int)($release['pid'] ?? 0);
        $expectedProcessName = (string)($release['expected_process_name'] ?? '');
        $dispatchToken = (string)($release['dispatch_token'] ?? '');
        if ($pid < 1 || $expectedProcessName === '' || !$this->isDispatchToken($dispatchToken)) {
            return false;
        }

        try {
            return $this->removeManagedQueueProcessLease($pid, $expectedProcessName, $dispatchToken);
        } catch (\Throwable) {
            return false;
        }
    }

    private function isDispatchToken(string $dispatchToken): bool
    {
        return \preg_match('/^[a-f0-9]{64}$/', $dispatchToken) === 1;
    }

    protected function startQueueProcess(Queue $queue): bool
    {
        if (!$this->isDispatchable($queue)) {
            return false;
        }

        $queue = $this->claimQueueForDispatch($queue);
        if (!$queue instanceof Queue) {
            return false;
        }
        $dispatchToken = $queue->getDispatchToken();

        $queueName = $this->normalizeQueueTaskName($queue->getName(), (int)$queue->getId());
        $processName = $this->buildQueueRunProcessName(
            (int)$queue->getId(),
            $queueName,
            $queue,
            $dispatchToken,
        );
        // WLS workers reap/interfere with shell `nohup ... &` children from
        // Processer::create(), leaving a ghost PID and an empty worker log.
        // Detached argv + posix_setsid returns the real PHP PID and survives the
        // request worker lifecycle (same transport Master/shared sidecars use).
        $spawnError = '';
        try {
            $pid = $this->createDetachedQueueWorker(
                $this->buildQueueRunArgv((int)$queue->getId(), $queueName, $queue, $dispatchToken),
                $processName,
            );
        } catch (\Throwable $throwable) {
            $pid = 0;
            // Keep the claimed Queue snapshot immutable. Compensation must
            // compare against the values that were actually persisted.
            $spawnError = (string)__('创建脱离式 Queue Worker 异常：%{1}', $throwable->getMessage());
        }
        if (!$pid) {
            $output = $this->getQueueSpawnOutput($processName);
            $this->compensateSpawnFailure(
                $queue,
                $dispatchToken,
                \trim($spawnError . PHP_EOL . $output . PHP_EOL
                    . (string)__('创建 Queue Worker 失败，进程名：%{1}', [$processName])),
            );
            return false;
        }

        return true;
    }

    /** @param list<string> $argv */
    protected function createDetachedQueueWorker(array $argv, string $processName): int
    {
        return Processer::createDetachedPhpArgv($argv, BP, $processName, true);
    }

    protected function getQueueSpawnOutput(string $processName): string
    {
        return $this->getManagedProcessOutput($processName);
    }

    private function isDispatchable(Queue $queue): bool
    {
        return $queue->getAuto()
            && $this->isCleanPendingQueue($queue)
            && $this->hasReachedNotBefore($queue);
    }

    private function hasReachedNotBefore(Queue $queue): bool
    {
        $notBefore = $queue->getNotBefore();
        if ($notBefore === '') {
            return true;
        }
        if (\preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $notBefore) !== 1) {
            return false;
        }
        $utc = new \DateTimeZone('UTC');
        $due = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $notBefore, $utc);
        $errors = \DateTimeImmutable::getLastErrors();
        if (!$due instanceof \DateTimeImmutable
            || (\is_array($errors)
                && (($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0))
            || $due->format('Y-m-d H:i:s') !== $notBefore
        ) {
            return false;
        }

        return $due->getTimestamp() <= \time();
    }

    /** @return list<Queue> */
    protected function loadRunningAutoQueues(): array
    {
        return $this->queue->reset()
            ->where($this->queue::schema_fields_auto, 1)
            ->where($this->queue::schema_fields_status, $this->queue::status_running)
            ->select()
            ->fetch()
            ->getItems();
    }

    protected function isManagedQueueWorkerRunning(
        int $pid,
        string $queueTaskName,
        string $dispatchToken,
        string $processName,
    ): bool {
        return Processer::isManagedProcessRunning(
            $pid,
            $queueTaskName,
            $dispatchToken,
            $processName,
        );
    }

    protected function getQueueProcessCommandLine(int $pid): string
    {
        return Processer::getProcessCommandLine($pid, true);
    }

    protected function getQueueManagedProcessOutput(string $processName, int $pid): string
    {
        return $this->getManagedProcessOutput($processName, $pid);
    }

    protected function loadFreshQueue(int $queueId): Queue
    {
        $freshQueue = clone $this->queue;
        $freshQueue->clearData()->clearQuery()
            ->where(Queue::schema_fields_ID, $queueId)
            ->find()
            ->fetch();

        return $freshQueue;
    }

    protected function claimQueueForDispatch(Queue $queue): ?Queue
    {
        $queueId = (int)$queue->getId();
        if ($queueId < 1 || !$this->isDispatchable($queue)) {
            return null;
        }
        $dispatchToken = \bin2hex(\random_bytes(32));
        if (!$this->updateQueueIf($this->queueDispatchClaimSnapshot($queue), [
            Queue::schema_fields_status => Queue::status_running,
            Queue::schema_fields_pid => 0,
            Queue::schema_fields_DISPATCH_TOKEN => $dispatchToken,
            Queue::schema_fields_DISPATCH_UNTIL => \gmdate(
                'Y-m-d H:i:s',
                \time() + self::DISPATCH_CLAIM_SECONDS,
            ),
            Queue::schema_fields_NOT_BEFORE => null,
            Queue::schema_fields_start_at => null,
            Queue::schema_fields_end_at => null,
        ])) {
            return null;
        }

        $claimed = $this->loadFreshQueue($queueId);
        return $claimed->getStatus() === Queue::status_running
            && \hash_equals($claimed->getDispatchToken(), $dispatchToken)
            ? $claimed
            : null;
    }

    private function compensateSpawnFailure(Queue $queue, string $dispatchToken, string $error): void
    {
        if ($queue->getStatus() !== Queue::status_running
            || $queue->getDispatchToken() === ''
            || !\hash_equals($dispatchToken, $queue->getDispatchToken())
            || (int)$queue->getPid() !== 0
        ) {
            return;
        }
        $this->updateQueueSnapshotIf($queue, [
            Queue::schema_fields_status => Queue::status_pending,
            Queue::schema_fields_DISPATCH_TOKEN => null,
            Queue::schema_fields_DISPATCH_UNTIL => null,
            Queue::schema_fields_start_at => null,
            Queue::schema_fields_result => $this->appendProcessMessage($queue->getResult(), $error),
            Queue::schema_fields_process => $this->appendProcessMessage($queue->getProcess(), $error),
        ]);
    }

    private function releaseExpiredDispatchClaim(Queue $queue): void
    {
        $dispatchToken = $queue->getDispatchToken();
        if ($dispatchToken === '') {
            return;
        }
        $message = (string)__('Queue 派发 claim 已过期，已恢复为 pending。');
        $this->updateQueueSnapshotIf($queue, [
            Queue::schema_fields_status => Queue::status_pending,
            Queue::schema_fields_DISPATCH_TOKEN => null,
            Queue::schema_fields_DISPATCH_UNTIL => null,
            Queue::schema_fields_start_at => null,
            Queue::schema_fields_process => $this->appendProcessMessage($queue->getProcess(), $message),
        ]);
    }

    /** @param array<string,mixed> $expected @param array<string,mixed> $updates */
    protected function updateQueueIf(array $expected, array $updates): bool
    {
        $query = clone $this->queue;
        $query->clearData()->clearQuery();
        foreach ($expected as $field => $value) {
            $query->where((string)$field, $value);
        }
        $result = $query->getQuery()->update($updates)->fetch();
        if ($result === true || (\is_int($result) && $result === 1)) {
            return true;
        }

        // SQLite executes UPDATE successfully but exposes an empty result set as
        // false. Never treat that ambiguous adapter value as success by itself:
        // reload the exact queue and require the complete postimage to match.
        $queueId = (int)($expected[Queue::schema_fields_ID] ?? 0);
        if ($queueId < 1 || $updates === []) {
            return false;
        }
        $fresh = $this->loadFreshQueue($queueId);
        if ((int)$fresh->getId() !== $queueId) {
            return false;
        }
        foreach ($updates as $field => $value) {
            if (!$this->queueControlValueMatches($fresh->getData((string)$field), $value)) {
                return false;
            }
        }

        return true;
    }

    private function queueControlValueMatches(mixed $actual, mixed $expected): bool
    {
        if ($expected === null) {
            return $actual === null || $actual === '';
        }
        if (\is_bool($expected)) {
            return (bool)$actual === $expected;
        }
        if (\is_int($expected)) {
            return (int)$actual === $expected;
        }

        return (string)$actual === (string)$expected;
    }

    /** @param array<string,mixed> $expected */
    protected function deleteQueueIf(array $expected, Queue $queueSnapshot): bool
    {
        $query = clone $this->queue;
        $query->clearData()->clearQuery();
        $transactionQuery = $query->getQuery();
        if (!$transactionQuery instanceof WriteIntentQueryInterface) {
            throw new \RuntimeException('queue_delete_write_intent_unsupported');
        }
        $transactionQuery->beginWriteTransaction();
        try {
            // beginTransaction() may replace/reset the model query binding;
            // build the fenced predicate only after the transaction starts.
            foreach ($expected as $field => $value) {
                $query->where((string)$field, $value);
            }
            // Delete the fenced main row first while retaining the loaded
            // snapshot. Attribute cleanup then runs in the same transaction;
            // any cleanup failure restores both the row and its EAV values.
            $result = $query->getQuery()->delete()->fetch();
            $deleted = $result === true || (\is_int($result) && $result === 1);
            if (!$deleted) {
                $transactionQuery->rollBack();

                return false;
            }
            $this->deleteQueueAttributeValues($queueSnapshot);
            $transactionQuery->commit();

            return true;
        } catch (\Throwable $throwable) {
            try {
                $transactionQuery->rollBack();
            } catch (\Throwable) {
                // Preserve the original cleanup/delete failure.
            }
            throw $throwable;
        }
    }

    protected function deleteQueueAttributeValues(Queue $queueSnapshot): void
    {
        foreach ($queueSnapshot->getAllEavAttributes() as $attribute) {
            $code = \trim((string)$attribute->getCode());
            $attributeId = (int)$attribute->getAttributeId();
            if ($code === '' || $attributeId < 1) {
                throw new \RuntimeException((string)__('无法清理 Queue EAV 属性值：%{1}', $code));
            }
            // Use the already entity-scoped attribute id. Looking the
            // attribute up again by code can select another EAV entity that
            // happens to reuse the same code.
            $valueModel = clone $attribute->w_getValueModel();
            $valueModel->clearData()->clearQuery()
                ->where('attribute_id', $attributeId)
                ->where('entity_id', (int)$queueSnapshot->getId())
                ->delete()
                ->fetch();
        }
    }

    protected function normalizeQueueTaskName(string $queueDisplayName, int $queueId): string
    {
        return $this->processControl()->normalizeTaskName(
            'queue-' . $queueDisplayName . '-' . $queueId,
        );
    }

    protected function currentProcessId(): int
    {
        return (int)\getmypid();
    }

    protected function probeQueueProcessState(int $pid): string
    {
        return Processer::probeProcessState($pid, true);
    }

    protected function hasActiveQueueTransaction(): bool
    {
        try {
            return TransactionContext::transactionState(
                $this->queue->getConnection()->getConnector(),
            ) !== null;
        } catch (\Throwable) {
            // Identity/transaction inspection is itself part of the safety
            // boundary for operations with external process side effects.
            return true;
        }
    }

    /**
     * @param array<string,string> $requiredLiveArguments
     * @return array<string,mixed>
     */
    protected function terminateManagedQueueProcess(
        int $pid,
        string $expectedProcessName,
        string $expectedLaunchId,
        string $expectedPname,
        array $requiredLiveArguments,
    ): array {
        return Processer::terminateManagedProcessLease(
            $pid,
            $expectedProcessName,
            $expectedLaunchId,
            $expectedPname,
            true,
            $requiredLiveArguments,
        );
    }

    protected function removeManagedQueueProcessLease(
        int $pid,
        string $expectedProcessName,
        string $expectedLaunchId,
    ): bool {
        return Processer::removeManagedProcessLeaseRecord(
            $pid,
            $expectedProcessName,
            $expectedLaunchId,
        );
    }

    private function resolveMaxConcurrent(): int
    {
        $maxConcurrent = (int)(Env::get('queue.cron.max_concurrent', 4) ?: 4);
        if ($maxConcurrent < 1) {
            $maxConcurrent = 1;
        }

        return $maxConcurrent;
    }

    private function buildQueueRunProcessName(
        int $queueId,
        string $queueName,
        ?Queue $queue = null,
        string $dispatchToken = '',
    ): string
    {
        $bin = BP . 'bin' . DIRECTORY_SEPARATOR . 'w';
        $memoryLimit = $this->resolveWorkerMemoryLimit($queue);

        $processName = \escapeshellarg(PHP_BINARY)
            . ' -d memory_limit=' . \escapeshellarg($memoryLimit)
            . ' '
            . \escapeshellarg($bin)
            . ' queue:run --id=' . $queueId
            . ' --name=' . $queueName;
        if ($dispatchToken !== '') {
            $processName .= ' --launch-id=' . $dispatchToken;
        }

        return $processName;
    }

    /**
     * Argv form of {@see buildQueueRunProcessName()} for Processer::createDetachedPhpArgv().
     *
     * @return list<string>
     */
    private function buildQueueRunArgv(
        int $queueId,
        string $queueName,
        ?Queue $queue = null,
        string $dispatchToken = '',
    ): array
    {
        $bin = BP . 'bin' . DIRECTORY_SEPARATOR . 'w';
        $memoryLimit = $this->resolveWorkerMemoryLimit($queue);

        $argv = [
            PHP_BINARY,
            '-d',
            'memory_limit=' . $memoryLimit,
            $bin,
            'queue:run',
            '--id=' . $queueId,
            '--name=' . $queueName,
        ];
        if ($dispatchToken !== '') {
            $argv[] = '--launch-id=' . $dispatchToken;
            $argv[] = '--dispatch-token=' . $dispatchToken;
        }

        return $argv;
    }

    private function resolveWorkerMemoryLimit(?Queue $queue = null): string
    {
        $queueClass = $this->resolveQueueClass($queue);
        if ($queueClass !== '') {
            $configuredByClass = Env::get(
                'queue.worker.memory_limit_by_class.' . $queueClass,
                Env::get('queue.worker.memory_limit.' . $queueClass, null)
            );
            if ($configuredByClass !== null && $configuredByClass !== '') {
                return $this->normalizeMemoryLimit(
                    $configuredByClass,
                    self::DEFAULT_WORKER_MEMORY_LIMIT_BY_CLASS[$queueClass] ?? self::DEFAULT_WORKER_MEMORY_LIMIT
                );
            }

            if (isset(self::DEFAULT_WORKER_MEMORY_LIMIT_BY_CLASS[$queueClass])) {
                return $this->normalizeMemoryLimit(
                    self::DEFAULT_WORKER_MEMORY_LIMIT_BY_CLASS[$queueClass],
                    self::DEFAULT_WORKER_MEMORY_LIMIT
                );
            }
        }

        $configured = Env::get(
            'queue.worker.memory_limit',
            Env::get('queue.cron.memory_limit', self::DEFAULT_WORKER_MEMORY_LIMIT)
        );

        return $this->normalizeMemoryLimit($configured, self::DEFAULT_WORKER_MEMORY_LIMIT);
    }

    private function resolveQueueClass(?Queue $queue): string
    {
        if (!$queue instanceof Queue || (int)$queue->getTypeId() <= 0) {
            return '';
        }

        try {
            return \ltrim((string)$queue->getType()->getClass(), '\\');
        } catch (\Throwable) {
            return '';
        }
    }

    private function shouldRecoverDeadWorker(Queue $queue, int $deadPid, string $workerOutput): bool
    {
        $recoverableQueue = $this->resolveDeadWorkerRecoverableQueue($queue);

        return $recoverableQueue instanceof DeadWorkerRecoverableQueueInterface
            && $recoverableQueue->shouldRecoverDeadWorker($queue, $deadPid, $workerOutput);
    }

    /**
     * Resolve and validate the optional consumer payload patch before the
     * service enters its single full-snapshot recovery CAS.
     *
     * A null result rejects recovery. An empty array preserves the legacy
     * DeadWorkerRecoverableQueueInterface contract without adding writes.
     *
     * @return null|array<string,mixed>
     */
    private function deadWorkerRecoveryPatch(
        Queue $queue,
        int $deadPid,
        string $workerOutput,
    ): ?array {
        if (!$this->shouldRecoverDeadWorker($queue, $deadPid, $workerOutput)) {
            return null;
        }

        $recoverableQueue = $this->resolveDeadWorkerRecoverableQueue($queue);
        if (!$recoverableQueue instanceof DeadWorkerRecoveryPatchQueueInterface) {
            return [];
        }

        try {
            $patch = $recoverableQueue->deadWorkerRecoveryPatch($queue, $deadPid, $workerOutput);
        } catch (\Throwable) {
            return null;
        }
        if (\array_diff(\array_keys($patch), [Queue::schema_fields_content]) !== []
            || !\array_key_exists(Queue::schema_fields_content, $patch)
            || !\is_string($patch[Queue::schema_fields_content])
            || \trim($patch[Queue::schema_fields_content]) === ''
        ) {
            return null;
        }

        return [
            Queue::schema_fields_content => $patch[Queue::schema_fields_content],
        ];
    }

    private function deadWorkerRecoveryMessage(Queue $queue, int $deadPid, string $workerOutput): string
    {
        $recoverableQueue = $this->resolveDeadWorkerRecoverableQueue($queue);
        if ($recoverableQueue instanceof DeadWorkerRecoverableQueueInterface) {
            $message = $this->compactOperatorMessage(
                $recoverableQueue->deadWorkerRecoveryMessage($queue, $deadPid, $workerOutput)
            );
            if ($message !== '') {
                return $message;
            }
        }

        return 'Queue worker exited before terminal state; queue reset to pending for scheduler resume.';
    }

    protected function resolveDeadWorkerRecoverableQueue(Queue $queue): ?DeadWorkerRecoverableQueueInterface
    {
        $queueClass = $this->resolveQueueClass($queue);
        if ($queueClass === '' || !\class_exists($queueClass)) {
            return null;
        }

        try {
            $queueInstance = ObjectManager::getInstance($queueClass);
        } catch (\Throwable) {
            return null;
        }

        return $queueInstance instanceof DeadWorkerRecoverableQueueInterface ? $queueInstance : null;
    }

    private function compactOperatorMessage(string $message, int $limit = 512): string
    {
        $message = \trim((string)\preg_replace('/\s+/u', ' ', $message));
        if ($message === '' || \strlen($message) <= $limit) {
            return $message;
        }

        return \rtrim(\substr($message, 0, $limit - 3)) . '...';
    }

    private function normalizeMemoryLimit(mixed $value, string $default): string
    {
        if (\is_int($value) || \is_float($value)) {
            $value = (string)(int)$value;
        }

        $value = \strtoupper(\trim((string)$value));
        $default = \strtoupper(\trim($default)) ?: self::DEFAULT_WORKER_MEMORY_LIMIT;
        if ($value === '') {
            return $default;
        }
        if ($value === '-1') {
            return '-1';
        }
        if (\preg_match('/^[1-9]\d*$/', $value)) {
            return $value . 'M';
        }
        if (\preg_match('/^[1-9]\d*(?:K|M|G)$/', $value)) {
            return $value;
        }

        return $default;
    }

    private function getManagedProcessOutput(string $processName, int $pid = 0): string
    {
        try {
            if ($pid > 0) {
                $output = Processer::outputByPid($pid);
                if (\is_string($output) && $output !== '') {
                    return $output;
                }
            }

            $path = Processer::getLogFile($processName);
            if (\is_file($path)) {
                $output = \file_get_contents($path);
                if (\is_string($output)) {
                    return $output;
                }
            }
        } catch (\Throwable) {
            return '';
        }

        return '';
    }

    private function appendProcessMessage(mixed $current, string $message): string
    {
        return \trim((string)$current . PHP_EOL . $message);
    }

    private function prependResultMessage(string $current, string $output, string $message): string
    {
        return \trim($output . PHP_EOL . $message . PHP_EOL . $current);
    }

    private function hasQueueDoneMarker(string $output, Queue $queue): bool
    {
        $haystack = $output . PHP_EOL . (string)$queue->getResult() . PHP_EOL . (string)$queue->getProcess();

        return \str_contains($haystack, 'QUEUE_DONE');
    }

    private function processControl(): ProcessControlInterface
    {
        if ($this->processControl instanceof ProcessControlInterface) {
            return $this->processControl;
        }

        $provider = $this->runtimeProviders->resolve(ProcessControlInterface::class);
        if (!$provider instanceof ProcessControlInterface) {
            throw new \RuntimeException('cron_process_control_provider_unavailable');
        }

        return $this->processControl = $provider;
    }
}
