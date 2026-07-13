<?php

declare(strict_types=1);

namespace Weline\Framework\Service\Runtime;

use Weline\Framework\Model\Runtime\ResumableTask;
use Weline\Framework\Model\Runtime\ResumableTaskCheckpoint;
use Weline\Framework\Model\Runtime\ResumableTaskEffect;
use Weline\Framework\Model\Runtime\ResumableTaskEvent;
use Weline\Framework\Model\Runtime\ResumableTaskLease;
use Weline\Framework\Runtime\Resumable\ResumableTaskStateMachine;
use Weline\Framework\Runtime\Resumable\ResumableTaskStatus;
use Weline\Framework\Runtime\Resumable\TaskCheckpoint as RuntimeTaskCheckpoint;

/**
 * Persistence boundary for the Queue-independent resumable runtime.
 *
 * The service intentionally owns only durable state.  It does not execute a
 * handler, talk to EventSource, or know about Weline_Queue.  Every write from a
 * runner validates its fencing generation first, making a replaced process a
 * read-only stale observer instead of a second writer.
 */
final class ResumableTaskStore
{
    public const DEFAULT_EVENT_LIMIT = 50000;
    public const DEFAULT_EVENT_BYTES_LIMIT = 52428800;

    /** @var list<string> */
    private const TERMINAL_STATUSES = [
        'completed',
        'failed',
        'cancelled',
        'expired',
        'recovery_unsafe',
        'event_backlog_limit',
    ];

    public function __construct(
        private readonly ResumableTask $taskModel,
        private readonly ResumableTaskCheckpoint $checkpointModel,
        private readonly ResumableTaskEvent $eventModel,
        private readonly ResumableTaskLease $leaseModel,
        private readonly ResumableTaskEffect $effectModel,
    ) {
    }

    /**
     * @param array<string,mixed> $record
     * @return array<string,mixed>
     */
    public function createTask(array $record): array
    {
        $taskId = trim((string)($record['task_id'] ?? ''));
        if ($taskId === '') {
            throw new ResumableTaskStoreException('validation_error', 'Task ID is required.');
        }

        $task = $this->newTaskModel();
        $task->setData([
            ResumableTask::schema_fields_TASK_ID => $taskId,
            ResumableTask::schema_fields_TYPE_CODE => trim((string)($record['type_code'] ?? '')),
            ResumableTask::schema_fields_MODULE => trim((string)($record['module'] ?? '')),
            ResumableTask::schema_fields_BUSINESS_KEY => trim((string)($record['business_key'] ?? '')),
            ResumableTask::schema_fields_INPUT_JSON => $this->encodeJson($record['input'] ?? $record['input_json'] ?? []),
            ResumableTask::schema_fields_OWNER_AREA => trim((string)($record['owner_area'] ?? '')),
            ResumableTask::schema_fields_OWNER_PRINCIPAL => trim((string)($record['owner_principal'] ?? '')),
            ResumableTask::schema_fields_OWNER_SESSION => trim((string)($record['owner_session'] ?? '')),
            ResumableTask::schema_fields_WEBSITE_ID => max(0, (int)($record['website_id'] ?? 0)),
            ResumableTask::schema_fields_OWNER_WEBSITE_SCOPED => !empty($record['website_scoped']) ? 1 : 0,
            ResumableTask::schema_fields_TENANT_SCOPE => trim((string)($record['tenant_scope'] ?? '')),
            ResumableTask::schema_fields_OWNER_TENANT_SCOPED => !empty($record['tenant_scoped']) ? 1 : 0,
            ResumableTask::schema_fields_ACL_JSON => $this->encodeJson($record['acl'] ?? $record['acl_json'] ?? []),
            ResumableTask::schema_fields_POLICY_JSON => $this->encodeJson($record['policy'] ?? $record['policy_json'] ?? []),
            ResumableTask::schema_fields_STATUS => trim((string)($record['status'] ?? 'starting')) ?: 'starting',
            ResumableTask::schema_fields_MAX_ATTEMPTS => max(1, (int)($record['max_attempts'] ?? 4)),
            ResumableTask::schema_fields_ATTEMPT => max(0, (int)($record['attempt'] ?? 0)),
            ResumableTask::schema_fields_FENCING_GENERATION => max(0, (int)($record['fencing_generation'] ?? 0)),
            ResumableTask::schema_fields_CURRENT_CHECKPOINT_VERSION => 0,
            ResumableTask::schema_fields_LATEST_EVENT_SEQUENCE => 0,
            ResumableTask::schema_fields_TERMINAL_EVENT_SEQUENCE => 0,
            ResumableTask::schema_fields_EVENT_COUNT => 0,
            ResumableTask::schema_fields_EVENT_PAYLOAD_BYTES => 0,
            ResumableTask::schema_fields_COMPACTED_BEFORE_SEQUENCE => 0,
            ResumableTask::schema_fields_RUNNER_ID => '',
            ResumableTask::schema_fields_RUNNER_LAUNCH_ID => '',
            ResumableTask::schema_fields_RUNNER_PROCESS_NAME => '',
            ResumableTask::schema_fields_RECOVERY_STOP_REQUESTED => 0,
            ResumableTask::schema_fields_RUNNER_LEASE_RELEASED => 1,
        ]);
        $task->save();

        return $this->taskRow($task);
    }

    /** @return array<string,mixed>|null */
    public function findTask(string $taskId): ?array
    {
        $task = $this->findTaskModel($taskId);
        return $task === null ? null : $this->taskRow($task);
    }

    /**
     * @param array{area:string,principal:string,website_id?:int,tenant_scope?:string} $owner
     * @return array<string,mixed>|null
     */
    public function findTaskForOwner(string $taskId, array $owner): ?array
    {
        $task = $this->findTaskModel($taskId);
        if ($task === null || !$this->ownerMatches($task, $owner)) {
            return null;
        }
        return $this->taskRow($task);
    }

    /**
     * Return an existing deterministic start before a caller creates a second task.
     *
     * @param array{area:string,principal:string} $owner
     * @return array<string,mixed>|null
     */
    public function findTaskByBusinessKey(string $typeCode, string $businessKey, array $owner): ?array
    {
        if ($typeCode === '' || $businessKey === '' || ($owner['principal'] ?? '') === '') {
            return null;
        }
        $task = $this->newTaskModel();
        $task->where(ResumableTask::schema_fields_TYPE_CODE, $typeCode)
            ->where(ResumableTask::schema_fields_BUSINESS_KEY, $businessKey)
            ->where(ResumableTask::schema_fields_OWNER_PRINCIPAL, (string)$owner['principal'])
            ->where(ResumableTask::schema_fields_OWNER_AREA, (string)$owner['area'])
            ->find()
            ->fetch();

        return $task->getId() && $this->ownerMatches($task, $owner) ? $this->taskRow($task) : null;
    }

    /**
     * Claim a runner by issuing its next fencing generation.
     *
     * The caller must only invoke this after its Processer identity has been
     * generated.  The returned generation is required by every later mutation.
     *
     * @return array<string,mixed>
     */
    public function claimRunner(string $taskId, int $pid, string $runnerIdentity, int $leaseSeconds = 15): array
    {
        $task = $this->requireTaskModel($taskId);
        $status = (string)$task->getData(ResumableTask::schema_fields_STATUS);
        if (in_array($status, self::TERMINAL_STATUSES, true)) {
            throw new ResumableTaskStoreException('task_terminal', 'A terminal task cannot be claimed.');
        }

        $now = time();
        $leaseSeconds = max(1, $leaseSeconds);
        $attempt = (int)$task->getData(ResumableTask::schema_fields_ATTEMPT) + 1;
        $maxAttempts = (int)$task->getData(ResumableTask::schema_fields_MAX_ATTEMPTS);
        if ($maxAttempts > 0 && $attempt > $maxAttempts) {
            throw new ResumableTaskStoreException('recovery_exhausted', 'Task recovery attempts are exhausted.');
        }

        $generation = (int)$task->getData(ResumableTask::schema_fields_FENCING_GENERATION) + 1;
        $task->setData([
            ResumableTask::schema_fields_FENCING_GENERATION => $generation,
            ResumableTask::schema_fields_ATTEMPT => $attempt,
            ResumableTask::schema_fields_RUNNER_PID => max(0, $pid),
            ResumableTask::schema_fields_RUNNER_IDENTITY => trim($runnerIdentity),
            ResumableTask::schema_fields_EXECUTION_LEASE_UNTIL => date('Y-m-d H:i:s', $now + $leaseSeconds),
            ResumableTask::schema_fields_HEARTBEAT_AT => date('Y-m-d H:i:s', $now),
            ResumableTask::schema_fields_STATUS => $status === 'recovering' ? 'recovering' : 'running',
            ResumableTask::schema_fields_STARTED_AT => $task->getData(ResumableTask::schema_fields_STARTED_AT) ?: date('Y-m-d H:i:s', $now),
        ]);
        $task->save();

        return $this->taskRow($task);
    }

    /**
     * Reserve the next fencing generation before a child process is started.
     *
     * A reservation has no PID yet.  It is deliberately durable so the
     * command line can carry only opaque identity tokens and an old delayed
     * process cannot claim a newer generation.
     *
     * @return array<string,mixed>
     */
    public function reserveRunner(
        string $taskId,
        string $runnerId,
        string $launchId,
        string $processName,
        bool $recovery = false,
    ): array {
        $task = $this->requireTaskModel($taskId);
        $status = (string)$task->getData(ResumableTask::schema_fields_STATUS);
        if (in_array($status, self::TERMINAL_STATUSES, true) || $status === 'cancel_requested') {
            throw new ResumableTaskStoreException('task_not_runnable', 'Task is not runnable.');
        }
        if ($recovery && $status !== 'recovering') {
            throw new ResumableTaskStoreException('invalid_state', 'Only a recovering task can reserve a recovery Runner.');
        }
        if (!$recovery && $status !== 'starting') {
            throw new ResumableTaskStoreException('invalid_state', 'Only a starting task can reserve its initial Runner.');
        }
        $runnerId = trim($runnerId);
        $launchId = trim($launchId);
        $processName = trim($processName);
        if ($runnerId === '' || $launchId === '' || $processName === '') {
            throw new ResumableTaskStoreException('validation_error', 'Runner reservation identity is required.');
        }

        $expectedGeneration = (int)$task->getData(ResumableTask::schema_fields_FENCING_GENERATION);
        $attempt = (int)$task->getData(ResumableTask::schema_fields_ATTEMPT) + 1;
        $maxAttempts = max(1, (int)$task->getData(ResumableTask::schema_fields_MAX_ATTEMPTS));
        if ($attempt > $maxAttempts) {
            throw new ResumableTaskStoreException('recovery_exhausted', 'Task recovery attempts are exhausted.');
        }

        $generation = $expectedGeneration + 1;
        $this->updateTaskCas($taskId, $expectedGeneration, $status, [
            ResumableTask::schema_fields_FENCING_GENERATION => $generation,
            ResumableTask::schema_fields_ATTEMPT => $attempt,
            ResumableTask::schema_fields_RUNNER_PID => 0,
            ResumableTask::schema_fields_RUNNER_IDENTITY => '',
            ResumableTask::schema_fields_RUNNER_ID => $runnerId,
            ResumableTask::schema_fields_RUNNER_LAUNCH_ID => $launchId,
            ResumableTask::schema_fields_RUNNER_PROCESS_NAME => $processName,
            ResumableTask::schema_fields_RUNNER_LIVE_COMMAND => null,
            ResumableTask::schema_fields_EXECUTION_LEASE_UNTIL => null,
            ResumableTask::schema_fields_HEARTBEAT_AT => null,
            ResumableTask::schema_fields_RUNNER_LEASE_RELEASED => 0,
            ResumableTask::schema_fields_STARTED_AT => $task->getData(ResumableTask::schema_fields_STARTED_AT) ?: date('Y-m-d H:i:s'),
        ]);

        $reserved = $this->requireTaskModel($taskId);
        if ((int)$reserved->getData(ResumableTask::schema_fields_FENCING_GENERATION) !== $generation
            || (string)$reserved->getData(ResumableTask::schema_fields_RUNNER_ID) !== $runnerId
            || (string)$reserved->getData(ResumableTask::schema_fields_RUNNER_LAUNCH_ID) !== $launchId) {
            throw new ResumableTaskStoreException('stale_runner', 'Runner reservation was superseded.');
        }
        return $this->taskRow($reserved);
    }

    /**
     * Bind a verified child PID to an existing reservation.  The command line
     * is stored only after Processer observed it, which prevents PID reuse
     * from becoming a termination authority.
     */
    public function recordRunnerLaunched(
        string $taskId,
        int $generation,
        string $runnerId,
        string $launchId,
        int $pid,
        string $liveCommand,
    ): bool {
        if ($pid < 1 || trim($liveCommand) === '') {
            return false;
        }
        $task = $this->findTaskModel($taskId);
        if ($task === null
            || (int)$task->getData(ResumableTask::schema_fields_FENCING_GENERATION) !== $generation
            || (string)$task->getData(ResumableTask::schema_fields_RUNNER_ID) !== trim($runnerId)
            || (string)$task->getData(ResumableTask::schema_fields_RUNNER_LAUNCH_ID) !== trim($launchId)
            || !in_array((string)$task->getData(ResumableTask::schema_fields_STATUS), ['starting', 'recovering'], true)) {
            return false;
        }
        try {
            $this->updateTaskCas($taskId, $generation, (string)$task->getData(ResumableTask::schema_fields_STATUS), [
                ResumableTask::schema_fields_RUNNER_PID => $pid,
                ResumableTask::schema_fields_RUNNER_LIVE_COMMAND => $liveCommand,
                ResumableTask::schema_fields_RUNNER_IDENTITY => hash('sha256', $liveCommand),
            ], $runnerId, $launchId);
            return true;
        } catch (ResumableTaskStoreException) {
            return false;
        }
    }

    /**
     * CLI-side exact reservation claim.  A process that did not originate
     * from the currently persisted reservation receives null and must exit.
     *
     * @return array<string,mixed>|null
     */
    public function acquireReservedRunner(
        string $taskId,
        int $generation,
        string $runnerId,
        string $launchId,
        int $pid,
        string $liveCommand,
        int $leaseSeconds = 15,
    ): ?array {
        $task = $this->findTaskModel($taskId);
        if ($task === null) {
            return null;
        }
        $status = (string)$task->getData(ResumableTask::schema_fields_STATUS);
        if (!in_array($status, ['starting', 'recovering'], true)
            || (int)$task->getData(ResumableTask::schema_fields_FENCING_GENERATION) !== $generation
            || (string)$task->getData(ResumableTask::schema_fields_RUNNER_ID) !== trim($runnerId)
            || (string)$task->getData(ResumableTask::schema_fields_RUNNER_LAUNCH_ID) !== trim($launchId)
            || $pid < 1 || trim($liveCommand) === '') {
            return null;
        }

        $now = time();
        try {
            $this->updateTaskCas($taskId, $generation, $status, [
                ResumableTask::schema_fields_STATUS => 'running',
                ResumableTask::schema_fields_RUNNER_PID => $pid,
                ResumableTask::schema_fields_RUNNER_LIVE_COMMAND => $liveCommand,
                ResumableTask::schema_fields_RUNNER_IDENTITY => hash('sha256', $liveCommand),
                ResumableTask::schema_fields_HEARTBEAT_AT => date('Y-m-d H:i:s', $now),
                ResumableTask::schema_fields_EXECUTION_LEASE_UNTIL => date('Y-m-d H:i:s', $now + max(1, $leaseSeconds)),
                ResumableTask::schema_fields_RECOVERY_STOP_REQUESTED => 0,
                ResumableTask::schema_fields_STOP_DEADLINE_AT => null,
            ], $runnerId, $launchId);
        } catch (ResumableTaskStoreException) {
            return null;
        }

        return $this->findTask($taskId);
    }

    /**
     * Revoke an unclaimed/failed reservation before any replacement can be
     * scheduled.  Advancing the fence is what makes a delayed child harmless.
     */
    public function revokeRunnerReservation(
        string $taskId,
        int $generation,
        string $runnerId,
        string $launchId,
        bool $keepRecoverable = true,
    ): bool
    {
        $task = $this->findTaskModel($taskId);
        if ($task === null
            || (int)$task->getData(ResumableTask::schema_fields_FENCING_GENERATION) !== $generation
            || (string)$task->getData(ResumableTask::schema_fields_RUNNER_ID) !== trim($runnerId)
            || (string)$task->getData(ResumableTask::schema_fields_RUNNER_LAUNCH_ID) !== trim($launchId)) {
            return false;
        }
        try {
            $status = (string)$task->getData(ResumableTask::schema_fields_STATUS);
            $this->updateTaskCas($taskId, $generation, $status, [
                ResumableTask::schema_fields_FENCING_GENERATION => $generation + 1,
                ResumableTask::schema_fields_STATUS => $keepRecoverable && $status === 'starting' ? 'recovering' : $status,
                ResumableTask::schema_fields_RUNNER_PID => 0,
                ResumableTask::schema_fields_RUNNER_IDENTITY => '',
                ResumableTask::schema_fields_RUNNER_ID => '',
                ResumableTask::schema_fields_RUNNER_LAUNCH_ID => '',
                ResumableTask::schema_fields_RUNNER_PROCESS_NAME => '',
                ResumableTask::schema_fields_RUNNER_LIVE_COMMAND => null,
                ResumableTask::schema_fields_EXECUTION_LEASE_UNTIL => null,
                ResumableTask::schema_fields_RUNNER_LEASE_RELEASED => 1,
            ], $runnerId, $launchId);
            return true;
        } catch (ResumableTaskStoreException) {
            return false;
        }
    }

    /** Mark a verified Runner generation as no longer live after its CLI exits. */
    public function releaseRunnerLease(string $taskId, int $generation, string $runnerId): bool
    {
        $task = $this->findTaskModel($taskId);
        if ($task === null
            || (int)$task->getData(ResumableTask::schema_fields_FENCING_GENERATION) !== $generation
            || (string)$task->getData(ResumableTask::schema_fields_RUNNER_ID) !== $runnerId) {
            return false;
        }
        try {
            $this->updateTaskCas($taskId, $generation, (string)$task->getData(ResumableTask::schema_fields_STATUS), [
                ResumableTask::schema_fields_RUNNER_PID => 0,
                ResumableTask::schema_fields_RUNNER_LIVE_COMMAND => null,
                ResumableTask::schema_fields_EXECUTION_LEASE_UNTIL => null,
                ResumableTask::schema_fields_RUNNER_LEASE_RELEASED => 1,
            ], $runnerId);
            return true;
        } catch (ResumableTaskStoreException) {
            return false;
        }
    }

    /** @return array<string,mixed> */
    public function heartbeat(string $taskId, int $generation, int $leaseSeconds = 15, ?string $runnerId = null): array
    {
        $task = $this->requireFence($taskId, $generation, $runnerId);
        $now = time();
        $this->updateTaskCas($taskId, $generation, (string)$task->getData(ResumableTask::schema_fields_STATUS), [
            ResumableTask::schema_fields_HEARTBEAT_AT => date('Y-m-d H:i:s', $now),
            ResumableTask::schema_fields_EXECUTION_LEASE_UNTIL => date('Y-m-d H:i:s', $now + max(1, $leaseSeconds)),
        ], $runnerId);
        return $this->findTask($taskId) ?? throw new ResumableTaskStoreException('not_found', 'Runtime task was not found.');
    }

    /** @return array<string,mixed> */
    public function saveCheckpoint(
        string $taskId,
        int $generation,
        string $cursor,
        array $state,
        int $schemaVersion = 1,
        ?string $runnerId = null,
    ): array {
        $task = $this->requireFence($taskId, $generation, $runnerId);
        $version = (int)$task->getData(ResumableTask::schema_fields_CURRENT_CHECKPOINT_VERSION) + 1;
        $now = time();
        $checkpointData = new RuntimeTaskCheckpoint(
            taskId: $taskId,
            version: $version,
            cursor: trim($cursor),
            state: $state,
            schemaVersion: max(1, $schemaVersion),
            attempt: (int)$task->getData(ResumableTask::schema_fields_ATTEMPT),
            fencingGeneration: $generation,
            createdAt: $now,
        );
        $stateJson = $this->encodeJson($checkpointData->state);

        $checkpoint = $this->newCheckpointModel();
        $checkpoint->setData([
            ResumableTaskCheckpoint::schema_fields_TASK_ID => $taskId,
            ResumableTaskCheckpoint::schema_fields_VERSION => $version,
            ResumableTaskCheckpoint::schema_fields_CURSOR => $checkpointData->cursor,
            ResumableTaskCheckpoint::schema_fields_STATE_JSON => $stateJson,
            ResumableTaskCheckpoint::schema_fields_SCHEMA_VERSION => $checkpointData->schemaVersion,
            ResumableTaskCheckpoint::schema_fields_CHECKSUM => $checkpointData->checksum,
            ResumableTaskCheckpoint::schema_fields_ATTEMPT => $checkpointData->attempt,
            ResumableTaskCheckpoint::schema_fields_FENCING_GENERATION => $generation,
            ResumableTaskCheckpoint::schema_fields_CREATED_AT => date('Y-m-d H:i:s', $now),
        ]);
        $checkpoint->save();

        try {
            $this->updateTaskCas($taskId, $generation, (string)$task->getData(ResumableTask::schema_fields_STATUS), [
                ResumableTask::schema_fields_CURRENT_CHECKPOINT_VERSION => $version,
            ], $runnerId);
        } catch (ResumableTaskStoreException $exception) {
            $this->newCheckpointModel()
                ->where(ResumableTaskCheckpoint::schema_fields_TASK_ID, $taskId)
                ->where(ResumableTaskCheckpoint::schema_fields_VERSION, $version)
                ->where(ResumableTaskCheckpoint::schema_fields_FENCING_GENERATION, $generation)
                ->delete();
            throw $exception;
        }

        return $this->checkpointRow($checkpoint);
    }

    /** @return array<string,mixed>|null */
    public function latestCheckpoint(string $taskId): ?array
    {
        $checkpoint = $this->newCheckpointModel();
        $checkpoint->where(ResumableTaskCheckpoint::schema_fields_TASK_ID, $taskId)
            ->order(ResumableTaskCheckpoint::schema_fields_VERSION, 'DESC')
            ->limit(1)
            ->find()
            ->fetch();
        return $checkpoint->getId() ? $this->checkpointRow($checkpoint) : null;
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public function appendEvent(
        string $taskId,
        int $generation,
        string $event,
        array $payload,
        ?string $coalesceKey = null,
        bool $compressible = false,
        int $eventLimit = self::DEFAULT_EVENT_LIMIT,
        int $bytesLimit = self::DEFAULT_EVENT_BYTES_LIMIT,
        ?string $runnerId = null,
    ): array {
        $task = $this->requireFence($taskId, $generation, $runnerId);
        $event = trim($event);
        if ($event === '' || !preg_match('/^[A-Za-z][A-Za-z0-9_-]*$/', $event)) {
            throw new ResumableTaskStoreException('validation_error', 'Invalid runtime event name.');
        }

        $payloadJson = $this->encodeJson($payload);
        $payloadBytes = strlen($payloadJson);
        $count = (int)$task->getData(ResumableTask::schema_fields_EVENT_COUNT);
        $totalBytes = (int)$task->getData(ResumableTask::schema_fields_EVENT_PAYLOAD_BYTES);
        if ($count >= max(1, $eventLimit) || ($totalBytes + $payloadBytes) > max(1, $bytesLimit)) {
            throw new ResumableTaskStoreException('event_backlog_limit', 'Runtime event backlog limit was reached.');
        }

        $sequence = (int)$task->getData(ResumableTask::schema_fields_LATEST_EVENT_SEQUENCE) + 1;
        $record = $this->newEventModel();
        $record->setData([
            ResumableTaskEvent::schema_fields_TASK_ID => $taskId,
            ResumableTaskEvent::schema_fields_SEQUENCE => $sequence,
            ResumableTaskEvent::schema_fields_EVENT => $event,
            ResumableTaskEvent::schema_fields_PAYLOAD_JSON => $payloadJson,
            ResumableTaskEvent::schema_fields_PAYLOAD_BYTES => $payloadBytes,
            ResumableTaskEvent::schema_fields_CHECKPOINT_VERSION => (int)$task->getData(ResumableTask::schema_fields_CURRENT_CHECKPOINT_VERSION),
            ResumableTaskEvent::schema_fields_ATTEMPT => (int)$task->getData(ResumableTask::schema_fields_ATTEMPT),
            ResumableTaskEvent::schema_fields_FENCING_GENERATION => $generation,
            ResumableTaskEvent::schema_fields_COALESCE_KEY => trim((string)$coalesceKey),
            ResumableTaskEvent::schema_fields_IS_COMPRESSIBLE => $compressible ? 1 : 0,
            ResumableTaskEvent::schema_fields_CREATED_AT => date('Y-m-d H:i:s'),
        ]);
        $record->save();

        try {
            $this->updateTaskCas($taskId, $generation, (string)$task->getData(ResumableTask::schema_fields_STATUS), [
                ResumableTask::schema_fields_LATEST_EVENT_SEQUENCE => $sequence,
                ResumableTask::schema_fields_EVENT_COUNT => $count + 1,
                ResumableTask::schema_fields_EVENT_PAYLOAD_BYTES => $totalBytes + $payloadBytes,
            ], $runnerId);
        } catch (ResumableTaskStoreException $exception) {
            $this->newEventModel()
                ->where(ResumableTaskEvent::schema_fields_TASK_ID, $taskId)
                ->where(ResumableTaskEvent::schema_fields_SEQUENCE, $sequence)
                ->where(ResumableTaskEvent::schema_fields_FENCING_GENERATION, $generation)
                ->delete();
            throw $exception;
        }

        return $this->eventRow($record);
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function eventsAfter(string $taskId, int $afterSequence, int $limit = 200): array
    {
        $rows = $this->newEventModel()
            ->where(ResumableTaskEvent::schema_fields_TASK_ID, $taskId)
            ->where(ResumableTaskEvent::schema_fields_SEQUENCE, max(0, $afterSequence), '>')
            ->order(ResumableTaskEvent::schema_fields_SEQUENCE, 'ASC')
            ->limit(min(500, max(1, $limit)))
            ->select()
            ->fetchArray();

        $events = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $event = $this->newEventModel();
            $event->setData($row);
            $events[] = $this->eventRow($event);
        }
        return $events;
    }

    /** @return array<string,mixed>|null */
    public function snapshotEventAfter(string $taskId, int $afterSequence): ?array
    {
        $event = $this->newEventModel()
            ->where(ResumableTaskEvent::schema_fields_TASK_ID, $taskId)
            ->where(ResumableTaskEvent::schema_fields_EVENT, 'runtime_snapshot')
            ->where(ResumableTaskEvent::schema_fields_SEQUENCE, max(0, $afterSequence), '>')
            ->order(ResumableTaskEvent::schema_fields_SEQUENCE, 'ASC')
            ->limit(1)
            ->find()
            ->fetch();
        return $event->getId() ? $this->eventRow($event) : null;
    }

    /** @return array<string,mixed>|null */
    public function eventBySequence(string $taskId, int $sequence): ?array
    {
        if ($sequence < 1) {
            return null;
        }
        $event = $this->newEventModel()
            ->where(ResumableTaskEvent::schema_fields_TASK_ID, $taskId)
            ->where(ResumableTaskEvent::schema_fields_SEQUENCE, $sequence)
            ->find()
            ->fetch();
        return $event->getId() ? $this->eventRow($event) : null;
    }

    /**
     * Persist exactly one terminal event for a terminal status. The task row
     * reserves the sequence first, so concurrent cancel/watchdog/runner paths
     * cannot create a second terminal business event.
     *
     * @param array<string,mixed> $payload
     * @return array<string,mixed>|null Null means another writer currently
     *         owns the short reservation; callers should simply retry/poll.
     */
    public function appendTerminalEventOnce(
        string $taskId,
        int $generation,
        string $event,
        array $payload,
        int $eventLimit = self::DEFAULT_EVENT_LIMIT,
        int $bytesLimit = self::DEFAULT_EVENT_BYTES_LIMIT,
        ?string $runnerId = null,
    ): ?array {
        $task = $this->requireFence($taskId, $generation, $runnerId);
        $status = (string)$task->getData(ResumableTask::schema_fields_STATUS);
        if (!in_array($status, self::TERMINAL_STATUSES, true)) {
            throw new ResumableTaskStoreException('invalid_state', 'A terminal event requires a terminal task state.');
        }
        $terminalSequence = (int)$task->getData(ResumableTask::schema_fields_TERMINAL_EVENT_SEQUENCE);
        if ($terminalSequence > 0) {
            return $this->eventBySequence($taskId, $terminalSequence);
        }
        if ($terminalSequence < 0) {
            return null;
        }

        $event = trim($event);
        if ($event === '' || !preg_match('/^[A-Za-z][A-Za-z0-9_-]*$/', $event)) {
            throw new ResumableTaskStoreException('validation_error', 'Invalid runtime terminal event name.');
        }
        $payloadJson = $this->encodeJson($payload);
        $payloadBytes = strlen($payloadJson);
        $count = (int)$task->getData(ResumableTask::schema_fields_EVENT_COUNT);
        $totalBytes = (int)$task->getData(ResumableTask::schema_fields_EVENT_PAYLOAD_BYTES);
        // Terminal visibility is never sacrificed to the normal progress/log
        // backlog cap. This can exceed the limit by one durable terminal
        // record, after which cleanup/retention owns the remaining bound.
        $sequence = (int)$task->getData(ResumableTask::schema_fields_LATEST_EVENT_SEQUENCE) + 1;
        try {
            $this->updateTaskCas($taskId, $generation, $status, [
                ResumableTask::schema_fields_LATEST_EVENT_SEQUENCE => $sequence,
                ResumableTask::schema_fields_TERMINAL_EVENT_SEQUENCE => -$sequence,
                ResumableTask::schema_fields_EVENT_COUNT => $count + 1,
                ResumableTask::schema_fields_EVENT_PAYLOAD_BYTES => $totalBytes + $payloadBytes,
            ], $runnerId, null, 0);
        } catch (ResumableTaskStoreException) {
            $current = $this->findTask($taskId);
            $currentSequence = (int)($current['terminal_event_sequence'] ?? 0);
            return $currentSequence > 0 ? $this->eventBySequence($taskId, $currentSequence) : null;
        }

        $record = $this->newEventModel();
        try {
            $record->setData([
                ResumableTaskEvent::schema_fields_TASK_ID => $taskId,
                ResumableTaskEvent::schema_fields_SEQUENCE => $sequence,
                ResumableTaskEvent::schema_fields_EVENT => $event,
                ResumableTaskEvent::schema_fields_PAYLOAD_JSON => $payloadJson,
                ResumableTaskEvent::schema_fields_PAYLOAD_BYTES => $payloadBytes,
                ResumableTaskEvent::schema_fields_CHECKPOINT_VERSION => (int)$task->getData(ResumableTask::schema_fields_CURRENT_CHECKPOINT_VERSION),
                ResumableTaskEvent::schema_fields_ATTEMPT => (int)$task->getData(ResumableTask::schema_fields_ATTEMPT),
                ResumableTaskEvent::schema_fields_FENCING_GENERATION => $generation,
                ResumableTaskEvent::schema_fields_COALESCE_KEY => '',
                ResumableTaskEvent::schema_fields_IS_COMPRESSIBLE => 0,
                ResumableTaskEvent::schema_fields_CREATED_AT => date('Y-m-d H:i:s'),
            ]);
            $record->save();
            $this->updateTaskCas($taskId, $generation, $status, [
                ResumableTask::schema_fields_TERMINAL_EVENT_SEQUENCE => $sequence,
            ], $runnerId, null, -$sequence);
        } catch (\Throwable $throwable) {
            // The reservation remains negative as an integrity signal rather
            // than claiming a terminal event exists. The watchdog can surface
            // it as a failed durable runtime rather than silently duplicating.
            throw new ResumableTaskStoreException('terminal_event_write_failed', $throwable->getMessage());
        }

        return $this->eventRow($record);
    }

    /**
     * Delete only explicitly compressible events and leave an authoritative
     * snapshot/checkpoint for a reconnecting client.
     */
    public function compactEventsBefore(string $taskId, int $beforeSequence): void
    {
        if ($beforeSequence <= 0) {
            return;
        }
        $rows = $this->newEventModel()
            ->where(ResumableTaskEvent::schema_fields_TASK_ID, $taskId)
            ->where(ResumableTaskEvent::schema_fields_SEQUENCE, $beforeSequence, '<')
            ->where(ResumableTaskEvent::schema_fields_IS_COMPRESSIBLE, 1)
            ->select()
            ->fetchArray();
        $removedCount = 0;
        $removedBytes = 0;
        foreach (is_array($rows) ? $rows : [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $removedCount++;
            $removedBytes += max(0, (int)($row[ResumableTaskEvent::schema_fields_PAYLOAD_BYTES] ?? 0));
        }
        $this->newEventModel()
            ->where(ResumableTaskEvent::schema_fields_TASK_ID, $taskId)
            ->where(ResumableTaskEvent::schema_fields_SEQUENCE, $beforeSequence, '<')
            ->where(ResumableTaskEvent::schema_fields_IS_COMPRESSIBLE, 1)
            ->delete();

        $task = $this->requireTaskModel($taskId);
        $task->setData([
            ResumableTask::schema_fields_COMPACTED_BEFORE_SEQUENCE => max(
                (int)$task->getData(ResumableTask::schema_fields_COMPACTED_BEFORE_SEQUENCE),
                $beforeSequence,
            ),
            ResumableTask::schema_fields_EVENT_COUNT => max(
                0,
                (int)$task->getData(ResumableTask::schema_fields_EVENT_COUNT) - $removedCount,
            ),
            ResumableTask::schema_fields_EVENT_PAYLOAD_BYTES => max(
                0,
                (int)$task->getData(ResumableTask::schema_fields_EVENT_PAYLOAD_BYTES) - $removedBytes,
            ),
        ]);
        $task->save();
    }

    /**
     * Persist a real snapshot event before removing any compressible history.
     * A replay cursor before the resulting boundary can then reset safely
     * without fabricating an SSE sequence.
     *
     * @return array<string,mixed>|null Null when no durable checkpoint exists.
     */
    public function compactWithSnapshot(
        string $taskId,
        int $generation,
        string $runnerId,
        int $eventLimit,
        int $bytesLimit,
    ): ?array {
        $task = $this->requireFence($taskId, $generation, $runnerId);
        $checkpoint = $this->latestCheckpoint($taskId);
        if ($checkpoint === null) {
            return null;
        }
        $snapshot = $this->appendEvent(
            taskId: $taskId,
            generation: $generation,
            event: 'runtime_snapshot',
            payload: [
                'task_id' => $taskId,
                'checkpoint' => [
                    'version' => $checkpoint['version'],
                    'cursor' => $checkpoint['cursor'],
                    'schema_version' => $checkpoint['schema_version'],
                    'state' => $checkpoint['state'],
                ],
                'status' => (string)$task->getData(ResumableTask::schema_fields_STATUS),
            ],
            coalesceKey: null,
            compressible: false,
            eventLimit: $eventLimit,
            bytesLimit: $bytesLimit,
            runnerId: $runnerId,
        );
        $this->compactEventsBefore($taskId, (int)$snapshot['sequence']);
        return $snapshot;
    }

    /**
     * @return array<string,mixed>
     */
    public function createOrRenewLease(
        string $taskId,
        array $owner,
        string $leaseId,
        string $subscriptionId = '',
        int $ttlSeconds = 600,
    ): array {
        $task = $this->requireTaskModel($taskId);
        if (!$this->ownerMatches($task, $owner)) {
            throw new ResumableTaskStoreException('not_found', 'Runtime task was not found.');
        }
        $leaseId = trim($leaseId);
        if ($leaseId === '') {
            throw new ResumableTaskStoreException('validation_error', 'Lease ID is required.');
        }

        $lease = $this->newLeaseModel();
        $lease->where(ResumableTaskLease::schema_fields_TASK_ID, $taskId)
            ->where(ResumableTaskLease::schema_fields_LEASE_ID, $leaseId)
            ->find()
            ->fetch();
        if ($lease->getId() && ((string)$lease->getData(ResumableTaskLease::schema_fields_OWNER_PRINCIPAL) !== (string)($owner['principal'] ?? '')
            || (string)$lease->getData(ResumableTaskLease::schema_fields_OWNER_AREA) !== (string)($owner['area'] ?? ''))) {
            throw new ResumableTaskStoreException('not_found', 'Runtime task was not found.');
        }
        $subscriptionId = trim($subscriptionId);
        if ($subscriptionId === '' && $lease->getId()) {
            $subscriptionId = (string)$lease->getData(ResumableTaskLease::schema_fields_SUBSCRIPTION_ID);
        }
        if ($subscriptionId === '') {
            $subscriptionId = $leaseId;
        }

        $now = time();
        $lease->setData([
            ResumableTaskLease::schema_fields_TASK_ID => $taskId,
            ResumableTaskLease::schema_fields_LEASE_ID => $leaseId,
            ResumableTaskLease::schema_fields_OWNER_AREA => (string)($owner['area'] ?? ''),
            ResumableTaskLease::schema_fields_OWNER_PRINCIPAL => (string)($owner['principal'] ?? ''),
            ResumableTaskLease::schema_fields_SUBSCRIPTION_ID => $subscriptionId,
            ResumableTaskLease::schema_fields_LAST_SEEN_AT => date('Y-m-d H:i:s', $now),
            ResumableTaskLease::schema_fields_EXPIRES_AT => date('Y-m-d H:i:s', $now + max(1, $ttlSeconds)),
        ]);
        $lease->save();
        return $this->leaseRow($lease);
    }

    public function hasActiveLeases(string $taskId, ?int $now = null): bool
    {
        $rows = $this->newLeaseModel()
            ->where(ResumableTaskLease::schema_fields_TASK_ID, $taskId)
            ->where(ResumableTaskLease::schema_fields_EXPIRES_AT, date('Y-m-d H:i:s', $now ?? time()), '>')
            ->limit(1)
            ->select()
            ->fetchArray();
        return is_array($rows) && $rows !== [];
    }

    /**
     * Read a lease without renewing it. SSE connects are subscriptions, not
     * liveness proof; only the explicit touch resource calls renew().
     *
     * @return array<string,mixed>|null
     */
    public function findActiveLeaseForOwner(string $taskId, string $leaseId, array $owner, ?int $now = null): ?array
    {
        $task = $this->findTaskModel($taskId);
        if ($task === null || !$this->ownerMatches($task, $owner)) {
            return null;
        }
        $lease = $this->newLeaseModel()
            ->where(ResumableTaskLease::schema_fields_TASK_ID, $taskId)
            ->where(ResumableTaskLease::schema_fields_LEASE_ID, trim($leaseId))
            ->find()
            ->fetch();
        if (!$lease->getId()
            || (string)$lease->getData(ResumableTaskLease::schema_fields_OWNER_AREA) !== (string)($owner['area'] ?? '')
            || (string)$lease->getData(ResumableTaskLease::schema_fields_OWNER_PRINCIPAL) !== (string)($owner['principal'] ?? '')
            || strtotime((string)$lease->getData(ResumableTaskLease::schema_fields_EXPIRES_AT)) <= ($now ?? time())) {
            return null;
        }
        return $this->leaseRow($lease);
    }

    /**
     * @return array<string,mixed>
     */
    public function requestCancel(string $taskId, array $owner, string $intentId, string $reason = ''): array
    {
        $task = $this->requireTaskModel($taskId);
        if (!$this->ownerMatches($task, $owner)) {
            throw new ResumableTaskStoreException('not_found', 'Runtime task was not found.');
        }
        if (in_array((string)$task->getData(ResumableTask::schema_fields_STATUS), self::TERMINAL_STATUSES, true)) {
            return $this->taskRow($task);
        }

        $intentId = trim($intentId);
        if ($intentId === '') {
            throw new ResumableTaskStoreException('validation_error', 'Cancellation intent ID is required.');
        }
        $existingIntent = (string)$task->getData(ResumableTask::schema_fields_CANCEL_INTENT_ID);
        if ($existingIntent !== '' && $existingIntent !== $intentId) {
            throw new ResumableTaskStoreException('cancel_conflict', 'A different cancellation intent is already pending.');
        }
        $status = (string)$task->getData(ResumableTask::schema_fields_STATUS);
        $nextStatus = $status === 'starting' ? 'cancelled' : 'cancel_requested';
        $now = time();
        $task->setData([
            ResumableTask::schema_fields_STATUS => $nextStatus,
            ResumableTask::schema_fields_CANCEL_INTENT_ID => $intentId,
            ResumableTask::schema_fields_CANCEL_REASON => trim($reason),
            ResumableTask::schema_fields_CANCEL_REQUESTED_AT => date('Y-m-d H:i:s', $now),
        ]);
        if ($nextStatus === 'cancelled') {
            $task->setData([
                ResumableTask::schema_fields_FINISHED_AT => date('Y-m-d H:i:s', $now),
                ResumableTask::schema_fields_EXECUTION_LEASE_UNTIL => null,
            ]);
        }
        $task->save();
        return $this->taskRow($task);
    }

    /** @return array<string,mixed> */
    public function transition(string $taskId, int $generation, string $status, array $patch = [], ?string $runnerId = null): array
    {
        $task = $this->requireFence($taskId, $generation, $runnerId);
        try {
            $from = ResumableTaskStatus::from((string)$task->getData(ResumableTask::schema_fields_STATUS));
            $to = ResumableTaskStatus::from(trim($status));
            ResumableTaskStateMachine::assertTransition($from, $to);
        } catch (\ValueError $exception) {
            throw new ResumableTaskStoreException('invalid_state', $exception->getMessage());
        }
        $data = [ResumableTask::schema_fields_STATUS => trim($status)];
        foreach ([
            'result_json' => ResumableTask::schema_fields_RESULT_JSON,
            'failure_code' => ResumableTask::schema_fields_FAILURE_CODE,
            'failure_message' => ResumableTask::schema_fields_FAILURE_MESSAGE,
            'termination_reason' => ResumableTask::schema_fields_TERMINATION_REASON,
            'retain_until' => ResumableTask::schema_fields_RETAIN_UNTIL,
        ] as $input => $field) {
            if (array_key_exists($input, $patch)) {
                $data[$field] = $input === 'result_json' && is_array($patch[$input])
                    ? $this->encodeJson($patch[$input])
                    : $patch[$input];
            }
        }
        if (in_array($status, self::TERMINAL_STATUSES, true)) {
            $data[ResumableTask::schema_fields_FINISHED_AT] = date('Y-m-d H:i:s');
            $data[ResumableTask::schema_fields_EXECUTION_LEASE_UNTIL] = null;
            $data[ResumableTask::schema_fields_RUNNER_LEASE_RELEASED] = 1;
        }
        $this->updateTaskCas(
            $taskId,
            $generation,
            (string)$task->getData(ResumableTask::schema_fields_STATUS),
            $data,
            $runnerId,
        );
        return $this->findTask($taskId) ?? throw new ResumableTaskStoreException('not_found', 'Runtime task was not found.');
    }

    /** @return array<string,mixed> */
    public function setTerminalRetention(string $taskId, int $retainUntil): array
    {
        $task = $this->requireTaskModel($taskId);
        $status = (string)$task->getData(ResumableTask::schema_fields_STATUS);
        if (!in_array($status, self::TERMINAL_STATUSES, true)) {
            throw new ResumableTaskStoreException('invalid_state', 'Only terminal tasks can receive a retention deadline.');
        }
        $task->setData(ResumableTask::schema_fields_RETAIN_UNTIL, date('Y-m-d H:i:s', max(time(), $retainUntil)));
        $task->save();
        return $this->taskRow($task);
    }

    /** @return array<string,mixed> */
    public function reserveEffect(string $taskId, int $generation, string $effectKey, ?string $runnerId = null): array
    {
        $task = $this->requireFence($taskId, $generation, $runnerId);
        $effectKey = trim($effectKey);
        if ($effectKey === '') {
            throw new ResumableTaskStoreException('validation_error', 'Effect key is required.');
        }

        $effect = $this->findEffectModel($taskId, $effectKey);
        if ($effect !== null) {
            return $this->effectRow($effect) + ['already_existed' => true];
        }

        $effect = $this->newEffectModel();
        $effect->setData([
            ResumableTaskEffect::schema_fields_TASK_ID => $taskId,
            ResumableTaskEffect::schema_fields_EFFECT_KEY => $effectKey,
            ResumableTaskEffect::schema_fields_STATUS => 'reserved',
            ResumableTaskEffect::schema_fields_EXTERNAL_IDEMPOTENCY_KEY => $taskId . ':' . $effectKey,
            ResumableTaskEffect::schema_fields_ATTEMPT => (int)$task->getData(ResumableTask::schema_fields_ATTEMPT),
            ResumableTaskEffect::schema_fields_FENCING_GENERATION => $generation,
        ]);
        try {
            $effect->save();
        } catch (\Throwable $throwable) {
            // A unique-key race means another runner reserved it first.  Do not
            // issue the external operation again; return the durable ledger row.
            $existing = $this->findEffectModel($taskId, $effectKey);
            if ($existing !== null) {
                return $this->effectRow($existing) + ['already_existed' => true];
            }
            throw $throwable;
        }
        return $this->effectRow($effect) + ['already_existed' => false];
    }

    /** @return array<string,mixed> */
    public function completeEffect(
        string $taskId,
        int $generation,
        string $effectKey,
        array $result = [],
        string $externalReference = '',
        ?string $runnerId = null,
    ): array
    {
        $this->requireFence($taskId, $generation, $runnerId);
        $effect = $this->findEffectModel($taskId, $effectKey);
        if ($effect === null) {
            throw new ResumableTaskStoreException('effect_missing', 'Effect reservation was not found.');
        }
        $effect->setData([
            ResumableTaskEffect::schema_fields_STATUS => 'applied',
            ResumableTaskEffect::schema_fields_RESULT_JSON => $this->encodeJson($result),
            ResumableTaskEffect::schema_fields_EXTERNAL_REFERENCE => trim($externalReference),
            ResumableTaskEffect::schema_fields_FENCING_GENERATION => $generation,
        ]);
        $effect->save();
        return $this->effectRow($effect);
    }

    /** @return array<string,mixed> */
    public function markEffectUnknown(string $taskId, string $effectKey): array
    {
        $effect = $this->findEffectModel($taskId, $effectKey);
        if ($effect === null) {
            throw new ResumableTaskStoreException('effect_missing', 'Effect reservation was not found.');
        }
        $effect->setData(ResumableTaskEffect::schema_fields_STATUS, 'unknown');
        $effect->save();
        return $this->effectRow($effect);
    }

    /**
     * Remove terminal task state after its retention deadline.  Active work is
     * intentionally never selected by this method.
     */
    public function purgeExpiredTerminal(int $limit = 100): int
    {
        $rows = $this->newTaskModel()
            ->where(ResumableTask::schema_fields_STATUS, self::TERMINAL_STATUSES, 'IN')
            ->where(ResumableTask::schema_fields_RETAIN_UNTIL, date('Y-m-d H:i:s'), '<')
            ->order(ResumableTask::schema_fields_RETAIN_UNTIL, 'ASC')
            ->limit(min(500, max(1, $limit)))
            ->select()
            ->fetchArray();
        $purged = 0;
        foreach (is_array($rows) ? $rows : [] as $row) {
            $taskId = is_array($row) ? trim((string)($row[ResumableTask::schema_fields_TASK_ID] ?? '')) : '';
            if ($taskId === '') {
                continue;
            }
            $this->deleteTask($taskId);
            $purged++;
        }
        return $purged;
    }

    /** @return list<array<string,mixed>> */
    public function watchdogCandidates(int $limit = 100): array
    {
        $rows = $this->newTaskModel()
            ->where(ResumableTask::schema_fields_STATUS, self::TERMINAL_STATUSES, 'not in')
            ->order(ResumableTask::schema_fields_UPDATED_AT, 'ASC')
            ->limit(min(1000, max(1, $limit)))
            ->select()
            ->fetchArray();
        $tasks = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $task = $this->newTaskModel();
            $task->setData($row);
            $tasks[] = $this->taskRow($task);
        }
        return $tasks;
    }

    /** @return array<string,mixed> */
    public function requestCooperativeStop(string $taskId, string $reason, int $deadlineAt): array
    {
        $task = $this->requireTaskModel($taskId);
        $status = (string)$task->getData(ResumableTask::schema_fields_STATUS);
        if (in_array($status, self::TERMINAL_STATUSES, true)) {
            return $this->taskRow($task);
        }
        $now = time();
        if ($status === 'starting') {
            $task->setData([
                ResumableTask::schema_fields_STATUS => 'expired',
                ResumableTask::schema_fields_TERMINATION_REASON => $reason,
                ResumableTask::schema_fields_CANCEL_REASON => $reason,
                ResumableTask::schema_fields_CANCEL_REQUESTED_AT => date('Y-m-d H:i:s', $now),
                ResumableTask::schema_fields_FINISHED_AT => date('Y-m-d H:i:s', $now),
                ResumableTask::schema_fields_EXECUTION_LEASE_UNTIL => null,
                ResumableTask::schema_fields_RUNNER_LEASE_RELEASED => 1,
            ]);
            $task->save();
            return $this->taskRow($task);
        }
        if ($status !== 'cancel_requested') {
            $this->updateTaskCas($taskId, (int)$task->getData(ResumableTask::schema_fields_FENCING_GENERATION), $status, [
                ResumableTask::schema_fields_STATUS => 'cancel_requested',
                ResumableTask::schema_fields_CANCEL_REASON => $reason,
                ResumableTask::schema_fields_CANCEL_REQUESTED_AT => date('Y-m-d H:i:s', $now),
                ResumableTask::schema_fields_STOP_DEADLINE_AT => date('Y-m-d H:i:s', max($now, $deadlineAt)),
                ResumableTask::schema_fields_RECOVERY_STOP_REQUESTED => 0,
            ], (string)$task->getData(ResumableTask::schema_fields_RUNNER_ID) ?: null);
        }
        return $this->findTask($taskId) ?? throw new ResumableTaskStoreException('not_found', 'Runtime task was not found.');
    }

    /** @return array<string,mixed> */
    public function requestRecoveryStop(string $taskId, int $deadlineAt): array
    {
        $task = $this->requireTaskModel($taskId);
        $status = (string)$task->getData(ResumableTask::schema_fields_STATUS);
        if ($status !== 'running') {
            return $this->taskRow($task);
        }
        $now = time();
        $this->updateTaskCas($taskId, (int)$task->getData(ResumableTask::schema_fields_FENCING_GENERATION), $status, [
            ResumableTask::schema_fields_STATUS => 'recovering',
            ResumableTask::schema_fields_RECOVERY_STOP_REQUESTED => 1,
            ResumableTask::schema_fields_STOP_DEADLINE_AT => date('Y-m-d H:i:s', max($now, $deadlineAt)),
        ], (string)$task->getData(ResumableTask::schema_fields_RUNNER_ID) ?: null);
        return $this->findTask($taskId) ?? throw new ResumableTaskStoreException('not_found', 'Runtime task was not found.');
    }

    /** @return array<string,mixed> */
    public function markRecoveryUnsafe(string $taskId, string $reason, int $retainUntil): array
    {
        $task = $this->requireTaskModel($taskId);
        $status = (string)$task->getData(ResumableTask::schema_fields_STATUS);
        if (in_array($status, self::TERMINAL_STATUSES, true)) {
            return $this->taskRow($task);
        }
        $generation = (int)$task->getData(ResumableTask::schema_fields_FENCING_GENERATION);
        if ($generation < 1) {
            $task->setData([
                ResumableTask::schema_fields_STATUS => 'recovery_unsafe',
                ResumableTask::schema_fields_TERMINATION_REASON => $reason,
                ResumableTask::schema_fields_FINISHED_AT => date('Y-m-d H:i:s'),
                ResumableTask::schema_fields_RETAIN_UNTIL => date('Y-m-d H:i:s', $retainUntil),
            ]);
            $task->save();
            return $this->taskRow($task);
        }
        $this->transition($taskId, $generation, 'recovery_unsafe', [
            'termination_reason' => $reason,
            'retain_until' => date('Y-m-d H:i:s', $retainUntil),
        ], (string)$task->getData(ResumableTask::schema_fields_RUNNER_ID) ?: null);
        return $this->findTask($taskId) ?? throw new ResumableTaskStoreException('not_found', 'Runtime task was not found.');
    }

    private function deleteTask(string $taskId): void
    {
        $this->newCheckpointModel()->where(ResumableTaskCheckpoint::schema_fields_TASK_ID, $taskId)->delete();
        $this->newEventModel()->where(ResumableTaskEvent::schema_fields_TASK_ID, $taskId)->delete();
        $this->newLeaseModel()->where(ResumableTaskLease::schema_fields_TASK_ID, $taskId)->delete();
        $this->newEffectModel()->where(ResumableTaskEffect::schema_fields_TASK_ID, $taskId)->delete();
        $this->newTaskModel()->where(ResumableTask::schema_fields_TASK_ID, $taskId)->delete();
    }

    /**
     * Atomic task mutation guarded by the durable fencing generation and,
     * when supplied, the opaque process reservation tokens.  All runner-side
     * mutations use this instead of Model::save(), because a stale in-memory
     * model would otherwise be able to overwrite a replacement Runner.
     *
     * @param array<string,mixed> $data
     */
    private function updateTaskCas(
        string $taskId,
        int $generation,
        string $status,
        array $data,
        ?string $runnerId = null,
        ?string $launchId = null,
        ?int $expectedTerminalEventSequence = null,
    ): void {
        $data[ResumableTask::schema_fields_UPDATED_AT] = date('Y-m-d H:i:s');
        $update = $this->newTaskModel()
            ->where(ResumableTask::schema_fields_TASK_ID, $taskId)
            ->where(ResumableTask::schema_fields_FENCING_GENERATION, $generation)
            ->where(ResumableTask::schema_fields_STATUS, $status);
        if ($runnerId !== null) {
            $update->where(ResumableTask::schema_fields_RUNNER_ID, $runnerId);
        }
        if ($launchId !== null) {
            $update->where(ResumableTask::schema_fields_RUNNER_LAUNCH_ID, $launchId);
        }
        if ($expectedTerminalEventSequence !== null) {
            $update->where(ResumableTask::schema_fields_TERMINAL_EVENT_SEQUENCE, $expectedTerminalEventSequence);
        }
        $update->update($data)->fetch();

        // Affected-row information is not portable across the framework's
        // MySQL/PgSQL/SQLite adapters. Re-read and verify the mandatory
        // predicates instead; a lost CAS is always surfaced as stale_runner.
        $after = $this->findTaskModel($taskId);
        if ($after === null
            || (int)$after->getData(ResumableTask::schema_fields_FENCING_GENERATION)
                !== (int)($data[ResumableTask::schema_fields_FENCING_GENERATION] ?? $generation)
            || (string)$after->getData(ResumableTask::schema_fields_STATUS)
                !== (string)($data[ResumableTask::schema_fields_STATUS] ?? $status)
            || ($runnerId !== null
                && (string)$after->getData(ResumableTask::schema_fields_RUNNER_ID)
                    !== (string)($data[ResumableTask::schema_fields_RUNNER_ID] ?? $runnerId))
            || ($launchId !== null
                && (string)$after->getData(ResumableTask::schema_fields_RUNNER_LAUNCH_ID)
                    !== (string)($data[ResumableTask::schema_fields_RUNNER_LAUNCH_ID] ?? $launchId))
            || ($expectedTerminalEventSequence !== null
                && (int)$after->getData(ResumableTask::schema_fields_TERMINAL_EVENT_SEQUENCE)
                    !== (int)($data[ResumableTask::schema_fields_TERMINAL_EVENT_SEQUENCE] ?? $expectedTerminalEventSequence))) {
            throw new ResumableTaskStoreException('stale_runner', 'Task fencing compare-and-swap was lost.');
        }
    }

    private function requireFence(string $taskId, int $generation, ?string $runnerId = null): ResumableTask
    {
        $task = $this->requireTaskModel($taskId);
        if ((int)$task->getData(ResumableTask::schema_fields_FENCING_GENERATION) !== $generation) {
            throw new ResumableTaskStoreException('stale_runner', 'Runner fencing generation is stale.');
        }
        if ($runnerId !== null
            && (string)$task->getData(ResumableTask::schema_fields_RUNNER_ID) !== $runnerId) {
            throw new ResumableTaskStoreException('stale_runner', 'Runner reservation identity is stale.');
        }
        return $task;
    }

    private function requireTaskModel(string $taskId): ResumableTask
    {
        $task = $this->findTaskModel($taskId);
        if ($task === null) {
            throw new ResumableTaskStoreException('not_found', 'Runtime task was not found.');
        }
        return $task;
    }

    private function findTaskModel(string $taskId): ?ResumableTask
    {
        $taskId = trim($taskId);
        if ($taskId === '') {
            return null;
        }
        $task = $this->newTaskModel();
        $task->where(ResumableTask::schema_fields_TASK_ID, $taskId)->find()->fetch();
        return $task->getId() ? $task : null;
    }

    private function findEffectModel(string $taskId, string $effectKey): ?ResumableTaskEffect
    {
        $effect = $this->newEffectModel();
        $effect->where(ResumableTaskEffect::schema_fields_TASK_ID, $taskId)
            ->where(ResumableTaskEffect::schema_fields_EFFECT_KEY, $effectKey)
            ->find()
            ->fetch();
        return $effect->getId() ? $effect : null;
    }

    /** @param array<string,mixed> $owner */
    private function ownerMatches(ResumableTask $task, array $owner): bool
    {
        if ((string)$task->getData(ResumableTask::schema_fields_OWNER_AREA) !== (string)($owner['area'] ?? '')) {
            return false;
        }
        if ((string)$task->getData(ResumableTask::schema_fields_OWNER_PRINCIPAL) !== (string)($owner['principal'] ?? '')) {
            return false;
        }
        $taskWebsiteScoped = (bool)$task->getData(ResumableTask::schema_fields_OWNER_WEBSITE_SCOPED);
        $ownerWebsiteScoped = array_key_exists('website_scoped', $owner)
            ? (bool)$owner['website_scoped']
            : array_key_exists('website_id', $owner) && $owner['website_id'] !== null;
        if ($taskWebsiteScoped !== $ownerWebsiteScoped) {
            return false;
        }
        if ($taskWebsiteScoped
            && (int)$task->getData(ResumableTask::schema_fields_WEBSITE_ID) !== max(0, (int)($owner['website_id'] ?? 0))) {
            return false;
        }
        $taskTenantScoped = (bool)$task->getData(ResumableTask::schema_fields_OWNER_TENANT_SCOPED);
        $ownerTenantScoped = array_key_exists('tenant_scoped', $owner)
            ? (bool)$owner['tenant_scoped']
            : array_key_exists('tenant_scope', $owner) && $owner['tenant_scope'] !== null;
        if ($taskTenantScoped !== $ownerTenantScoped) {
            return false;
        }
        if ($taskTenantScoped
            && (string)$task->getData(ResumableTask::schema_fields_TENANT_SCOPE) !== (string)($owner['tenant_scope'] ?? '')) {
            return false;
        }
        $taskAcl = $this->decodeJson((string)$task->getData(ResumableTask::schema_fields_ACL_JSON));
        $ownerAcl = $owner['acl'] ?? null;
        if (is_array($ownerAcl)) {
            sort($taskAcl, SORT_STRING);
            sort($ownerAcl, SORT_STRING);
            if ($taskAcl !== $ownerAcl) {
                return false;
            }
        }
        $principal = (string)($owner['principal'] ?? '');
        if (str_starts_with($principal, 'session:')
            && (string)$task->getData(ResumableTask::schema_fields_OWNER_SESSION) !== (string)($owner['session'] ?? $owner['session_id'] ?? '')) {
            return false;
        }
        return true;
    }

    /** @return array<string,mixed> */
    private function taskRow(ResumableTask $task): array
    {
        return [
            'task_id' => (string)$task->getData(ResumableTask::schema_fields_TASK_ID),
            'type_code' => (string)$task->getData(ResumableTask::schema_fields_TYPE_CODE),
            'module' => (string)$task->getData(ResumableTask::schema_fields_MODULE),
            'business_key' => (string)$task->getData(ResumableTask::schema_fields_BUSINESS_KEY),
            'input' => $this->decodeJson((string)$task->getData(ResumableTask::schema_fields_INPUT_JSON)),
            'owner_area' => (string)$task->getData(ResumableTask::schema_fields_OWNER_AREA),
            'owner_principal' => (string)$task->getData(ResumableTask::schema_fields_OWNER_PRINCIPAL),
            'owner_session' => (string)$task->getData(ResumableTask::schema_fields_OWNER_SESSION),
            'website_id' => (int)$task->getData(ResumableTask::schema_fields_WEBSITE_ID),
            'website_scoped' => (bool)$task->getData(ResumableTask::schema_fields_OWNER_WEBSITE_SCOPED),
            'tenant_scope' => (string)$task->getData(ResumableTask::schema_fields_TENANT_SCOPE),
            'tenant_scoped' => (bool)$task->getData(ResumableTask::schema_fields_OWNER_TENANT_SCOPED),
            'acl' => $this->decodeJson((string)$task->getData(ResumableTask::schema_fields_ACL_JSON)),
            'policy' => $this->decodeJson((string)$task->getData(ResumableTask::schema_fields_POLICY_JSON)),
            'status' => (string)$task->getData(ResumableTask::schema_fields_STATUS),
            'result' => $this->decodeJson((string)$task->getData(ResumableTask::schema_fields_RESULT_JSON)),
            'failure_code' => (string)$task->getData(ResumableTask::schema_fields_FAILURE_CODE),
            'failure_message' => (string)$task->getData(ResumableTask::schema_fields_FAILURE_MESSAGE),
            'termination_reason' => (string)$task->getData(ResumableTask::schema_fields_TERMINATION_REASON),
            'runner_pid' => (int)$task->getData(ResumableTask::schema_fields_RUNNER_PID),
            'runner_identity' => (string)$task->getData(ResumableTask::schema_fields_RUNNER_IDENTITY),
            'runner_id' => (string)$task->getData(ResumableTask::schema_fields_RUNNER_ID),
            'runner_launch_id' => (string)$task->getData(ResumableTask::schema_fields_RUNNER_LAUNCH_ID),
            'runner_process_name' => (string)$task->getData(ResumableTask::schema_fields_RUNNER_PROCESS_NAME),
            'runner_live_command' => (string)$task->getData(ResumableTask::schema_fields_RUNNER_LIVE_COMMAND),
            'execution_lease_until' => (string)$task->getData(ResumableTask::schema_fields_EXECUTION_LEASE_UNTIL),
            'heartbeat_at' => (string)$task->getData(ResumableTask::schema_fields_HEARTBEAT_AT),
            'runner_lease_released' => (bool)$task->getData(ResumableTask::schema_fields_RUNNER_LEASE_RELEASED),
            'fencing_generation' => (int)$task->getData(ResumableTask::schema_fields_FENCING_GENERATION),
            'attempt' => (int)$task->getData(ResumableTask::schema_fields_ATTEMPT),
            'max_attempts' => (int)$task->getData(ResumableTask::schema_fields_MAX_ATTEMPTS),
            'checkpoint_version' => (int)$task->getData(ResumableTask::schema_fields_CURRENT_CHECKPOINT_VERSION),
            'latest_event_sequence' => (int)$task->getData(ResumableTask::schema_fields_LATEST_EVENT_SEQUENCE),
            'terminal_event_sequence' => (int)$task->getData(ResumableTask::schema_fields_TERMINAL_EVENT_SEQUENCE),
            'event_count' => (int)$task->getData(ResumableTask::schema_fields_EVENT_COUNT),
            'event_payload_bytes' => (int)$task->getData(ResumableTask::schema_fields_EVENT_PAYLOAD_BYTES),
            'compacted_before_sequence' => (int)$task->getData(ResumableTask::schema_fields_COMPACTED_BEFORE_SEQUENCE),
            'cancel_intent_id' => (string)$task->getData(ResumableTask::schema_fields_CANCEL_INTENT_ID),
            'cancel_reason' => (string)$task->getData(ResumableTask::schema_fields_CANCEL_REASON),
            'cancel_requested_at' => (string)$task->getData(ResumableTask::schema_fields_CANCEL_REQUESTED_AT),
            'recovery_stop_requested' => (bool)$task->getData(ResumableTask::schema_fields_RECOVERY_STOP_REQUESTED),
            'stop_deadline_at' => (string)$task->getData(ResumableTask::schema_fields_STOP_DEADLINE_AT),
            'started_at' => (string)$task->getData(ResumableTask::schema_fields_STARTED_AT),
            'finished_at' => (string)$task->getData(ResumableTask::schema_fields_FINISHED_AT),
            'retain_until' => (string)$task->getData(ResumableTask::schema_fields_RETAIN_UNTIL),
            'created_at' => (string)$task->getData(ResumableTask::schema_fields_CREATED_AT),
            'updated_at' => (string)$task->getData(ResumableTask::schema_fields_UPDATED_AT),
        ];
    }

    /** @return array<string,mixed> */
    private function checkpointRow(ResumableTaskCheckpoint $checkpoint): array
    {
        return [
            'task_id' => (string)$checkpoint->getData(ResumableTaskCheckpoint::schema_fields_TASK_ID),
            'version' => (int)$checkpoint->getData(ResumableTaskCheckpoint::schema_fields_VERSION),
            'cursor' => (string)$checkpoint->getData(ResumableTaskCheckpoint::schema_fields_CURSOR),
            'state' => $this->decodeJson((string)$checkpoint->getData(ResumableTaskCheckpoint::schema_fields_STATE_JSON)),
            'schema_version' => (int)$checkpoint->getData(ResumableTaskCheckpoint::schema_fields_SCHEMA_VERSION),
            'checksum' => (string)$checkpoint->getData(ResumableTaskCheckpoint::schema_fields_CHECKSUM),
            'attempt' => (int)$checkpoint->getData(ResumableTaskCheckpoint::schema_fields_ATTEMPT),
            'fencing_generation' => (int)$checkpoint->getData(ResumableTaskCheckpoint::schema_fields_FENCING_GENERATION),
            'created_at' => $this->timestamp((string)$checkpoint->getData(ResumableTaskCheckpoint::schema_fields_CREATED_AT)),
        ];
    }

    /** @return array<string,mixed> */
    private function eventRow(ResumableTaskEvent $event): array
    {
        return [
            'id' => (int)$event->getData(ResumableTaskEvent::schema_fields_ID),
            'task_id' => (string)$event->getData(ResumableTaskEvent::schema_fields_TASK_ID),
            'sequence' => (int)$event->getData(ResumableTaskEvent::schema_fields_SEQUENCE),
            'event' => (string)$event->getData(ResumableTaskEvent::schema_fields_EVENT),
            'data' => $this->decodeJson((string)$event->getData(ResumableTaskEvent::schema_fields_PAYLOAD_JSON)),
            'payload_bytes' => (int)$event->getData(ResumableTaskEvent::schema_fields_PAYLOAD_BYTES),
            'checkpoint_version' => (int)$event->getData(ResumableTaskEvent::schema_fields_CHECKPOINT_VERSION),
            'attempt' => (int)$event->getData(ResumableTaskEvent::schema_fields_ATTEMPT),
            'fencing_generation' => (int)$event->getData(ResumableTaskEvent::schema_fields_FENCING_GENERATION),
            'coalesce_key' => (string)$event->getData(ResumableTaskEvent::schema_fields_COALESCE_KEY),
            'compressible' => (bool)$event->getData(ResumableTaskEvent::schema_fields_IS_COMPRESSIBLE),
            'created_at' => (string)$event->getData(ResumableTaskEvent::schema_fields_CREATED_AT),
        ];
    }

    /** @return array<string,mixed> */
    private function leaseRow(ResumableTaskLease $lease): array
    {
        return [
            'task_id' => (string)$lease->getData(ResumableTaskLease::schema_fields_TASK_ID),
            'lease_id' => (string)$lease->getData(ResumableTaskLease::schema_fields_LEASE_ID),
            'subscription_id' => (string)$lease->getData(ResumableTaskLease::schema_fields_SUBSCRIPTION_ID),
            'last_seen_at' => (string)$lease->getData(ResumableTaskLease::schema_fields_LAST_SEEN_AT),
            'expires_at' => (string)$lease->getData(ResumableTaskLease::schema_fields_EXPIRES_AT),
        ];
    }

    /** @return array<string,mixed> */
    private function effectRow(ResumableTaskEffect $effect): array
    {
        return [
            'task_id' => (string)$effect->getData(ResumableTaskEffect::schema_fields_TASK_ID),
            'effect_key' => (string)$effect->getData(ResumableTaskEffect::schema_fields_EFFECT_KEY),
            'status' => (string)$effect->getData(ResumableTaskEffect::schema_fields_STATUS),
            'external_idempotency_key' => (string)$effect->getData(ResumableTaskEffect::schema_fields_EXTERNAL_IDEMPOTENCY_KEY),
            'external_reference' => (string)$effect->getData(ResumableTaskEffect::schema_fields_EXTERNAL_REFERENCE),
            'result' => $this->decodeJson((string)$effect->getData(ResumableTaskEffect::schema_fields_RESULT_JSON)),
            'attempt' => (int)$effect->getData(ResumableTaskEffect::schema_fields_ATTEMPT),
            'fencing_generation' => (int)$effect->getData(ResumableTaskEffect::schema_fields_FENCING_GENERATION),
        ];
    }

    private function newTaskModel(): ResumableTask
    {
        $model = clone $this->taskModel;
        return $model->clearData()->clearQuery();
    }

    private function newCheckpointModel(): ResumableTaskCheckpoint
    {
        $model = clone $this->checkpointModel;
        return $model->clearData()->clearQuery();
    }

    private function newEventModel(): ResumableTaskEvent
    {
        $model = clone $this->eventModel;
        return $model->clearData()->clearQuery();
    }

    private function newLeaseModel(): ResumableTaskLease
    {
        $model = clone $this->leaseModel;
        return $model->clearData()->clearQuery();
    }

    private function newEffectModel(): ResumableTaskEffect
    {
        $model = clone $this->effectModel;
        return $model->clearData()->clearQuery();
    }

    private function encodeJson(mixed $value): string
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $value;
            }
        }
        try {
            return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (\JsonException $exception) {
            throw new ResumableTaskStoreException('checkpoint_invalid', 'Runtime data must be JSON serializable: ' . $exception->getMessage());
        }
    }

    /** @return array<string,mixed> */
    private function decodeJson(string $json): array
    {
        if ($json === '') {
            return [];
        }
        try {
            $decoded = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
            return is_array($decoded) ? $decoded : [];
        } catch (\JsonException) {
            return [];
        }
    }

    private function timestamp(string $value): int
    {
        $timestamp = strtotime($value);
        return $timestamp === false ? time() : $timestamp;
    }
}
