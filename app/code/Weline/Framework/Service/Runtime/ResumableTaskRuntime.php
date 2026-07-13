<?php

declare(strict_types=1);

namespace Weline\Framework\Service\Runtime;

use Weline\Framework\Runtime\Resumable\CheckpointCodec;
use Weline\Framework\Runtime\Resumable\ResumableTaskAccessDeniedException;
use Weline\Framework\Runtime\Resumable\ResumableTaskEventStreamInterface;
use Weline\Framework\Runtime\Resumable\ResumableTaskRuntimeInterface;
use Weline\Framework\Runtime\Resumable\ResumableTaskRuntimeUnavailableException;
use Weline\Framework\Runtime\Resumable\ResumableTaskStatus;
use Weline\Framework\Runtime\Resumable\TaskCheckpoint;
use Weline\Framework\Runtime\Resumable\TaskEvent;
use Weline\Framework\Runtime\Resumable\TaskEventReplay;
use Weline\Framework\Runtime\Resumable\TaskHandle;
use Weline\Framework\Runtime\Resumable\TaskLease;
use Weline\Framework\Runtime\Resumable\TaskOwner;
use Weline\Framework\Runtime\Resumable\TaskPolicy;
use Weline\Framework\Runtime\Resumable\TaskResult;
use Weline\Framework\Runtime\Resumable\TaskSnapshot;

/**
 * Durable application runtime behind resumable tasks.
 *
 * SSE accesses this class only through the event replay contract. `start()`
 * immediately launches an isolated CLI Runner; it never invokes a handler in
 * the request Fiber and it has no Queue dependency.
 */
final class ResumableTaskRuntime implements ResumableTaskRuntimeInterface, ResumableTaskEventStreamInterface
{
    public function __construct(
        private readonly ResumableTaskStore $store,
        private readonly ResumableTaskHandlerRegistry $handlers,
        private readonly ResumableTaskRunnerLauncherInterface $runnerLauncher,
    ) {
    }

    public function start(
        string $typeCode,
        array $input,
        TaskOwner $owner,
        TaskPolicy $policy,
        string $businessKey,
    ): TaskHandle {
        $typeCode = trim($typeCode);
        $businessKey = trim($businessKey);
        if ($businessKey === '' || strlen($businessKey) > 191) {
            throw new \InvalidArgumentException('Resumable task business key is invalid.');
        }
        $definition = $this->handlers->definition($typeCode);
        $ownerRow = $this->ownerRow($owner);
        $existing = $this->store->findTaskByBusinessKey($typeCode, $businessKey, $ownerRow);
        if ($existing !== null) {
            $existingPolicy = ResumableTaskPolicyHydrator::fromArray((array)($existing['policy'] ?? []));
            $lease = $this->store->createOrRenewLease(
                taskId: (string)$existing['task_id'],
                owner: $ownerRow,
                leaseId: $this->identifier('lease'),
                subscriptionId: $this->identifier('tab'),
                ttlSeconds: $existingPolicy->leaseTtlSeconds,
            );
            return new TaskHandle(
                taskId: (string)$existing['task_id'],
                leaseId: (string)$lease['lease_id'],
                status: $this->statusFromRow($existing),
                leaseExpiresAt: $this->timestamp((string)$lease['expires_at']),
            );
        }

        try {
            $normalizedInput = CheckpointCodec::normalize($input);
        } catch (\Throwable $throwable) {
            throw new \InvalidArgumentException('Resumable task input must be JSON-safe checkpoint data.', previous: $throwable);
        }
        $taskId = $this->identifier('task');
        $row = $this->store->createTask([
            'task_id' => $taskId,
            'type_code' => $definition->typeCode,
            'module' => $definition->module,
            'business_key' => $businessKey,
            'input' => $normalizedInput,
            ...$ownerRow,
            'policy' => $policy->toArray(),
            'max_attempts' => $policy->maxRecoveries + 1,
        ]);
        $lease = $this->store->createOrRenewLease(
            taskId: $taskId,
            owner: $ownerRow,
            leaseId: $this->identifier('lease'),
            subscriptionId: $this->identifier('tab'),
            ttlSeconds: $policy->leaseTtlSeconds,
        );

        try {
            $this->runnerLauncher->launch($taskId);
        } catch (ResumableTaskRuntimeUnavailableException $exception) {
            // The task remains recoverable and discoverable through its
            // deterministic business key, but callers receive no unsafe
            // connection-bound fallback.
            throw $exception;
        } catch (\Throwable $throwable) {
            throw new ResumableTaskRuntimeUnavailableException('Resumable task Runner launcher is unavailable.', previous: $throwable);
        }

        $current = $this->store->findTask($taskId) ?? $row;
        return new TaskHandle(
            taskId: $taskId,
            leaseId: (string)$lease['lease_id'],
            status: $this->statusFromRow($current),
            leaseExpiresAt: $this->timestamp((string)$lease['expires_at']),
        );
    }

    public function status(string $taskId, TaskOwner $owner): TaskSnapshot
    {
        $row = $this->store->findTaskForOwner($taskId, $this->ownerRow($owner));
        if ($row === null) {
            throw new ResumableTaskAccessDeniedException('Runtime task was not found.');
        }
        return $this->snapshot($row);
    }

    public function renew(string $taskId, string $leaseId, TaskOwner $owner): TaskLease
    {
        $ownerRow = $this->ownerRow($owner);
        $row = $this->store->findTaskForOwner($taskId, $ownerRow);
        if ($row === null || $this->store->findActiveLeaseForOwner($taskId, $leaseId, $ownerRow) === null) {
            throw new ResumableTaskAccessDeniedException('Runtime task was not found.');
        }
        $policy = ResumableTaskPolicyHydrator::fromArray((array)($row['policy'] ?? []));
        $lease = $this->store->createOrRenewLease(
            $taskId,
            $ownerRow,
            $leaseId,
            ttlSeconds: $policy->leaseTtlSeconds,
        );
        return $this->lease($lease, $owner);
    }

    public function cancel(string $taskId, TaskOwner $owner, string $intentId, string $reason = ''): TaskSnapshot
    {
        $ownerRow = $this->ownerRow($owner);
        try {
            $row = $this->store->requestCancel($taskId, $ownerRow, $intentId, $reason);
        } catch (ResumableTaskStoreException $exception) {
            if ($exception->errorCode === 'not_found') {
                throw new ResumableTaskAccessDeniedException('Runtime task was not found.', previous: $exception);
            }
            throw $exception;
        }
        $policy = ResumableTaskPolicyHydrator::fromArray((array)($row['policy'] ?? []));
        if ($this->statusFromRow($row)->isTerminal()) {
            $row = $this->store->setTerminalRetention($taskId, time() + $policy->terminalRetentionSeconds);
            $eventName = $this->terminalEventName($this->statusFromRow($row));
            $this->store->appendTerminalEventOnce(
                taskId: $taskId,
                generation: (int)$row['fencing_generation'],
                event: $eventName,
                payload: [
                    'task_id' => $taskId,
                    'status' => $row['status'],
                    'reason' => (string)($row['cancel_reason'] ?? ''),
                ],
                eventLimit: $policy->maxEvents,
                bytesLimit: $policy->maxEventBacklogBytes,
            );
        }
        return $this->snapshot($this->store->findTaskForOwner($taskId, $ownerRow) ?? $row);
    }

    public function replay(
        string $taskId,
        string $leaseId,
        TaskOwner $owner,
        int $afterSequence,
        int $limit = 200,
    ): TaskEventReplay {
        if ($afterSequence < 0) {
            throw new ResumableTaskAccessDeniedException('Runtime task was not found.');
        }
        $ownerRow = $this->ownerRow($owner);
        if ($this->store->findActiveLeaseForOwner($taskId, $leaseId, $ownerRow) === null) {
            throw new ResumableTaskAccessDeniedException('Runtime task was not found.');
        }
        // A compaction can race a page read. Recheck its durable boundary after
        // reading snapshot/events and retry a small bounded number of times;
        // returning a mixed pre/post-compaction page could otherwise skip IDs.
        for ($readAttempt = 0; $readAttempt < 3; $readAttempt++) {
            $row = $this->store->findTaskForOwner($taskId, $ownerRow);
            if ($row === null) {
                throw new ResumableTaskAccessDeniedException('Runtime task was not found.');
            }
            $task = $this->snapshot($row);
            $boundary = max(0, (int)($row['compacted_before_sequence'] ?? 0));
            if ($afterSequence < $boundary) {
                // A retained snapshot predating the compaction boundary is not
                // a valid reset anchor, even when the cursor is much older.
                $snapshotRow = $this->store->snapshotEventAfter($taskId, max($afterSequence, $boundary - 1));
                if ($snapshotRow === null) {
                    throw new ResumableTaskRuntimeUnavailableException('The durable runtime snapshot is unavailable after event compaction.');
                }
                $snapshotEvent = $this->event($snapshotRow);
                $replay = new TaskEventReplay(
                    task: $task,
                    requestedAfterSequence: $afterSequence,
                    events: $this->events($this->store->eventsAfter($taskId, $snapshotEvent->sequence, $limit)),
                    resetRequired: true,
                    compactedBeforeSequence: $boundary,
                    snapshotEvent: $snapshotEvent,
                );
            } else {
                $replay = new TaskEventReplay(
                    task: $task,
                    requestedAfterSequence: $afterSequence,
                    events: $this->events($this->store->eventsAfter($taskId, $afterSequence, $limit)),
                );
            }
            $verified = $this->store->findTaskForOwner($taskId, $ownerRow);
            if ($verified !== null
                && (int)($verified['compacted_before_sequence'] ?? 0) === $boundary) {
                return $replay;
            }
        }
        throw new ResumableTaskRuntimeUnavailableException('Runtime event compaction changed during replay.');
    }

    /** @param array<string,mixed> $row */
    private function snapshot(array $row): TaskSnapshot
    {
        $status = $this->statusFromRow($row);
        $checkpointRow = $this->store->latestCheckpoint((string)$row['task_id']);
        $checkpoint = $checkpointRow === null ? null : TaskCheckpoint::fromArray($checkpointRow);
        $owner = new TaskOwner(
            area: (string)$row['owner_area'],
            principal: (string)$row['owner_principal'],
            sessionId: (string)($row['owner_session'] ?? '') ?: null,
            websiteId: !empty($row['website_scoped']) ? (int)$row['website_id'] : null,
            tenantId: !empty($row['tenant_scoped']) ? (string)$row['tenant_scope'] : null,
            acl: $this->acl($row['acl'] ?? []),
        );
        $result = null;
        if ($status->isTerminal() && ($row['result'] ?? []) !== []) {
            $result = new TaskResult(
                $status,
                (array)$row['result'],
                (string)($row['failure_code'] ?? '') ?: null,
                (string)($row['termination_reason'] ?? $row['cancel_reason'] ?? ''),
            );
        }
        return new TaskSnapshot(
            taskId: (string)$row['task_id'],
            typeCode: (string)$row['type_code'],
            status: $status,
            owner: $owner,
            policy: ResumableTaskPolicyHydrator::fromArray((array)($row['policy'] ?? [])),
            attempt: (int)$row['attempt'],
            maxAttempts: max(1, (int)$row['max_attempts']),
            fencingGeneration: (int)$row['fencing_generation'],
            checkpoint: $checkpoint,
            latestEventSequence: (int)$row['latest_event_sequence'],
            result: $result,
            errorCode: (string)($row['failure_code'] ?? '') ?: null,
            terminalReason: (string)($row['termination_reason'] ?? $row['cancel_reason'] ?? ''),
            createdAt: $this->timestamp((string)$row['created_at']),
            updatedAt: $this->timestamp((string)$row['updated_at']),
            completedAt: $this->nullableTimestamp((string)($row['finished_at'] ?? '')),
        );
    }

    /** @param array<string,mixed> $row */
    private function lease(array $row, TaskOwner $owner): TaskLease
    {
        return new TaskLease(
            leaseId: (string)$row['lease_id'],
            taskId: (string)$row['task_id'],
            owner: $owner,
            subscriptionId: (string)$row['subscription_id'],
            lastSeenAt: $this->timestamp((string)$row['last_seen_at']),
            expiresAt: $this->timestamp((string)$row['expires_at']),
        );
    }

    /** @param array<string,mixed> $row */
    private function event(array $row): TaskEvent
    {
        return new TaskEvent(
            taskId: (string)$row['task_id'],
            sequence: (int)$row['sequence'],
            event: (string)$row['event'],
            payload: (array)$row['data'],
            coalesceKey: (string)($row['coalesce_key'] ?? '') ?: null,
            checkpointVersion: (int)($row['checkpoint_version'] ?? 0) ?: null,
            attempt: (int)$row['attempt'],
            fencingGeneration: (int)$row['fencing_generation'],
            createdAt: $this->timestamp((string)$row['created_at']),
        );
    }

    /** @param list<array<string,mixed>> $rows @return list<TaskEvent> */
    private function events(array $rows): array
    {
        return array_map(fn(array $row): TaskEvent => $this->event($row), $rows);
    }

    /** @return array<string,mixed> */
    private function ownerRow(TaskOwner $owner): array
    {
        return [
            'area' => $owner->area,
            'principal' => $owner->principal,
            'owner_area' => $owner->area,
            'owner_principal' => $owner->principal,
            'owner_session' => $owner->sessionId ?? '',
            'session_id' => $owner->sessionId,
            'session' => $owner->sessionId,
            'website_id' => $owner->websiteId ?? 0,
            'website_scoped' => $owner->websiteId !== null,
            'tenant_scope' => $owner->tenantId ?? '',
            'tenant_scoped' => $owner->tenantId !== null,
            'acl' => $owner->acl,
        ];
    }

    /** @param mixed $acl @return list<string> */
    private function acl(mixed $acl): array
    {
        if (!is_array($acl)) {
            return [];
        }
        $list = [];
        foreach ($acl as $permission) {
            if (is_string($permission) && trim($permission) !== '') {
                $list[] = $permission;
            }
        }
        sort($list, SORT_STRING);
        return array_values(array_unique($list));
    }

    /** @param array<string,mixed> $row */
    private function statusFromRow(array $row): ResumableTaskStatus
    {
        try {
            return ResumableTaskStatus::from((string)($row['status'] ?? ''));
        } catch (\ValueError $exception) {
            throw new ResumableTaskRuntimeUnavailableException('Persisted resumable task status is invalid.', previous: $exception);
        }
    }

    private function terminalEventName(ResumableTaskStatus $status): string
    {
        return match ($status) {
            ResumableTaskStatus::COMPLETED => 'completed',
            ResumableTaskStatus::FAILED => 'failed',
            ResumableTaskStatus::CANCELLED => 'cancelled',
            ResumableTaskStatus::EXPIRED => 'expired',
            ResumableTaskStatus::RECOVERY_UNSAFE => 'recovery_unsafe',
            ResumableTaskStatus::EVENT_BACKLOG_LIMIT => 'event_backlog_limit',
            default => throw new \LogicException('Task is not terminal.'),
        };
    }

    private function identifier(string $prefix): string
    {
        return $prefix . '-' . bin2hex(random_bytes(16));
    }

    private function timestamp(string $value): int
    {
        $timestamp = strtotime($value);
        return $timestamp === false ? time() : $timestamp;
    }

    private function nullableTimestamp(string $value): ?int
    {
        return trim($value) === '' ? null : $this->timestamp($value);
    }
}
