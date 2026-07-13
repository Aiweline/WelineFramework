<?php

declare(strict_types=1);

namespace Weline\Framework\Runtime\Resumable;

/**
 * Read model returned by status, runner and watchdog operations.
 */
final readonly class TaskSnapshot
{
    public function __construct(
        public string $taskId,
        public string $typeCode,
        public ResumableTaskStatus $status,
        public TaskOwner $owner,
        public TaskPolicy $policy,
        public int $attempt,
        public int $maxAttempts,
        public int $fencingGeneration,
        public ?TaskCheckpoint $checkpoint,
        public int $latestEventSequence,
        public ?TaskResult $result,
        public ?string $errorCode,
        public string $terminalReason,
        public int $createdAt,
        public int $updatedAt,
        public ?int $completedAt = null,
    ) {
        if (\preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$/', $this->taskId) !== 1) {
            throw new \InvalidArgumentException('Task snapshot task id is invalid.');
        }
        if (\preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$/', $this->typeCode) !== 1) {
            throw new \InvalidArgumentException('Task snapshot type code is invalid.');
        }
        if ($this->attempt < 0 || $this->maxAttempts < 1 || $this->attempt > $this->maxAttempts
            || $this->fencingGeneration < 0 || $this->latestEventSequence < 0) {
            throw new \InvalidArgumentException('Task snapshot execution counters are invalid.');
        }
        if ($this->status !== ResumableTaskStatus::STARTING
            && ($this->attempt < 1 || $this->fencingGeneration < 1)) {
            throw new \InvalidArgumentException('Only an unclaimed starting task may have zero execution counters.');
        }
        if ($this->createdAt < 1 || $this->updatedAt < $this->createdAt
            || ($this->completedAt !== null && $this->completedAt < $this->createdAt)) {
            throw new \InvalidArgumentException('Task snapshot timestamps are invalid.');
        }
        if ($this->checkpoint !== null && $this->checkpoint->taskId !== $this->taskId) {
            throw new \InvalidArgumentException('Task snapshot checkpoint belongs to another task.');
        }
        if ($this->result !== null && $this->result->status !== $this->status) {
            throw new \InvalidArgumentException('Task snapshot terminal result status must match task status.');
        }
        if ($this->errorCode !== null && (\trim($this->errorCode) === '' || \strlen($this->errorCode) > 128)) {
            throw new \InvalidArgumentException('Task snapshot error code is invalid.');
        }
    }

    public function isTerminal(): bool
    {
        return $this->status->isTerminal();
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'task_id' => $this->taskId,
            'type_code' => $this->typeCode,
            'status' => $this->status->value,
            'owner' => $this->owner->toArray(),
            'policy' => $this->policy->toArray(),
            'attempt' => $this->attempt,
            'max_attempts' => $this->maxAttempts,
            'fencing_generation' => $this->fencingGeneration,
            'checkpoint' => $this->checkpoint?->toArray(),
            'latest_event_sequence' => $this->latestEventSequence,
            'result' => $this->result?->toArray(),
            'error_code' => $this->errorCode,
            'terminal_reason' => $this->terminalReason,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
            'completed_at' => $this->completedAt,
        ];
    }
}
