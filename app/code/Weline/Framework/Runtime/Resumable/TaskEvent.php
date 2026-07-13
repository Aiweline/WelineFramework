<?php

declare(strict_types=1);

namespace Weline\Framework\Runtime\Resumable;

/**
 * Persisted event which is replayed through SSE. Sequence is the only SSE
 * cursor and must never be synthesized from a connection-local counter.
 */
final class TaskEvent
{
    public readonly string $taskId;
    public readonly int $sequence;
    public readonly string $event;
    /** @var array<string|int, mixed> */
    public readonly array $payload;
    public readonly ?string $coalesceKey;
    public readonly ?int $checkpointVersion;
    public readonly int $attempt;
    public readonly int $fencingGeneration;
    public readonly int $createdAt;

    /**
     * @param array<string|int, mixed> $payload
     */
    public function __construct(
        string $taskId,
        int $sequence,
        string $event,
        array $payload,
        ?string $coalesceKey = null,
        ?int $checkpointVersion = null,
        int $attempt = 1,
        int $fencingGeneration = 1,
        ?int $createdAt = null,
    ) {
        if (\preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$/', $taskId) !== 1) {
            throw new \InvalidArgumentException('Task event task id is invalid.');
        }
        if ($sequence < 1 || $attempt < 0 || $fencingGeneration < 0) {
            throw new \InvalidArgumentException('Task event sequence must be positive and execution counters cannot be negative.');
        }
        if (\preg_match('/^[A-Za-z][A-Za-z0-9_.:-]{0,127}$/', $event) !== 1) {
            throw new \InvalidArgumentException('Task event name is invalid.');
        }
        if ($coalesceKey !== null && (\trim($coalesceKey) === '' || \strlen($coalesceKey) > 128)) {
            throw new \InvalidArgumentException('Task event coalesce key is invalid.');
        }
        if ($checkpointVersion !== null && $checkpointVersion < 1) {
            throw new \InvalidArgumentException('Task event checkpoint version must be positive when present.');
        }
        $createdAt ??= \time();
        if ($createdAt < 1) {
            throw new \InvalidArgumentException('Task event creation time must be a Unix timestamp.');
        }

        $this->taskId = $taskId;
        $this->sequence = $sequence;
        $this->event = $event;
        $this->payload = CheckpointCodec::normalize($payload);
        $this->coalesceKey = $coalesceKey;
        $this->checkpointVersion = $checkpointVersion;
        $this->attempt = $attempt;
        $this->fencingGeneration = $fencingGeneration;
        $this->createdAt = $createdAt;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'task_id' => $this->taskId,
            'sequence' => $this->sequence,
            'event' => $this->event,
            'payload' => $this->payload,
            'coalesce_key' => $this->coalesceKey,
            'checkpoint_version' => $this->checkpointVersion,
            'attempt' => $this->attempt,
            'fencing_generation' => $this->fencingGeneration,
            'created_at' => $this->createdAt,
        ];
    }

    /**
     * @return array{id:int, event:string, data:array<string|int, mixed>}
     */
    public function toSseEvent(): array
    {
        return [
            'id' => $this->sequence,
            'event' => $this->event,
            'data' => $this->payload,
        ];
    }
}
