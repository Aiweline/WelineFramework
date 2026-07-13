<?php

declare(strict_types=1);

namespace Weline\Framework\Service\Runtime;

use Weline\Framework\Runtime\Resumable\ResumableTaskContextInterface;
use Weline\Framework\Runtime\Resumable\TaskCheckpoint;
use Weline\Framework\Runtime\Resumable\TaskEffectReservation;
use Weline\Framework\Runtime\Resumable\TaskEffectState;
use Weline\Framework\Runtime\Resumable\TaskPolicy;
use Weline\Framework\Runtime\Resumable\Runner\RuntimeRunnerControl;

/** Context passed to business handlers inside the detached CLI Runner. */
final class ResumableTaskExecutionContext implements ResumableTaskContextInterface
{
    public function __construct(
        private readonly ResumableTaskStore $store,
        private readonly RuntimeRunnerControl $control,
        private readonly string $id,
        private readonly int $runnerGeneration,
        private readonly string $runnerId,
        private readonly int $runnerAttempt,
        private readonly TaskPolicy $policy,
        private ?TaskCheckpoint $currentCheckpoint,
    ) {
    }

    public function taskId(): string
    {
        return $this->id;
    }

    public function attempt(): int
    {
        return $this->runnerAttempt;
    }

    public function checkpoint(): ?TaskCheckpoint
    {
        return $this->currentCheckpoint;
    }

    public function saveCheckpoint(string $cursor, array $state, int $schemaVersion = 1): TaskCheckpoint
    {
        $this->throwIfStopRequested();
        $row = $this->store->saveCheckpoint(
            taskId: $this->id,
            generation: $this->runnerGeneration,
            cursor: $cursor,
            state: $state,
            schemaVersion: $schemaVersion,
            runnerId: $this->runnerId,
        );
        return $this->currentCheckpoint = TaskCheckpoint::fromArray($row);
    }

    public function emit(string $event, array $payload, ?string $coalesceKey = null): int
    {
        $this->throwIfStopRequested();
        $compressible = in_array($event, ['progress', 'log', 'token', 'chunk'], true);
        $row = $this->store->appendEvent(
            taskId: $this->id,
            generation: $this->runnerGeneration,
            event: $event,
            payload: $payload,
            coalesceKey: $coalesceKey,
            compressible: $compressible,
            eventLimit: $this->policy->maxEvents,
            bytesLimit: $this->policy->maxEventBacklogBytes,
            runnerId: $this->runnerId,
        );
        $this->compactIfApproachingBacklogLimit();
        return (int)$row['sequence'];
    }

    public function reserveEffect(string $effectKey): TaskEffectReservation
    {
        $this->throwIfStopRequested();
        $row = $this->store->reserveEffect($this->id, $this->runnerGeneration, $effectKey, $this->runnerId);
        return new TaskEffectReservation(
            taskId: (string)$row['task_id'],
            effectKey: (string)$row['effect_key'],
            state: TaskEffectState::from((string)$row['status']),
            alreadyExisted: (bool)($row['already_existed'] ?? false),
            externalReference: (string)($row['external_reference'] ?? '') ?: null,
            result: (array)($row['result'] ?? []),
            attempt: (int)$row['attempt'],
            fencingGeneration: (int)$row['fencing_generation'],
        );
    }

    public function completeEffect(string $effectKey, array $result = []): void
    {
        $this->throwIfStopRequested();
        $this->store->completeEffect(
            taskId: $this->id,
            generation: $this->runnerGeneration,
            effectKey: $effectKey,
            result: $result,
            runnerId: $this->runnerId,
        );
    }

    public function isStopRequested(): bool
    {
        return $this->control->isStopRequested();
    }

    public function throwIfStopRequested(): void
    {
        $this->control->throwIfStopRequested();
    }

    public function heartbeat(): void
    {
        $this->control->heartbeat();
    }

    private function compactIfApproachingBacklogLimit(): void
    {
        $task = $this->store->findTask($this->id);
        if ($task === null) {
            return;
        }
        $countThreshold = (int)ceil($this->policy->maxEvents * 0.8);
        $bytesThreshold = (int)ceil($this->policy->maxEventBacklogBytes * 0.8);
        if ((int)$task['event_count'] < $countThreshold
            && (int)$task['event_payload_bytes'] < $bytesThreshold) {
            return;
        }
        // A snapshot is emitted first and only then compressible progress/log
        // records are deleted. Without a checkpoint there is no safe reset
        // anchor, so the hard limit remains an explicit terminal protection.
        $this->store->compactWithSnapshot(
            taskId: $this->id,
            generation: $this->runnerGeneration,
            runnerId: $this->runnerId,
            eventLimit: $this->policy->maxEvents,
            bytesLimit: $this->policy->maxEventBacklogBytes,
        );
    }
}
