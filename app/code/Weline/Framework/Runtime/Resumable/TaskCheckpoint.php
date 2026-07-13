<?php

declare(strict_types=1);

namespace Weline\Framework\Runtime\Resumable;

/**
 * Application-level recovery point. It deliberately stores data only, never
 * a PHP Fiber, Closure, request, session or call stack.
 */
final class TaskCheckpoint
{
    public readonly string $taskId;
    public readonly int $version;
    public readonly string $cursor;
    /** @var array<string|int, mixed> */
    public readonly array $state;
    public readonly int $schemaVersion;
    public readonly int $attempt;
    public readonly int $fencingGeneration;
    public readonly string $checksum;
    public readonly int $createdAt;

    /**
     * @param array<string|int, mixed> $state
     */
    public function __construct(
        string $taskId,
        int $version,
        string $cursor,
        array $state,
        int $schemaVersion = 1,
        int $attempt = 1,
        int $fencingGeneration = 1,
        ?string $checksum = null,
        ?int $createdAt = null,
    ) {
        self::assertTaskId($taskId);
        if ($version < 1 || $schemaVersion < 1 || $attempt < 1 || $fencingGeneration < 1) {
            throw new \InvalidArgumentException('Task checkpoint versions, attempt and fencing generation must be positive.');
        }
        if (\trim($cursor) === '') {
            throw new \InvalidArgumentException('Task checkpoint cursor is required.');
        }
        $createdAt ??= \time();
        if ($createdAt < 1) {
            throw new \InvalidArgumentException('Task checkpoint creation time must be a Unix timestamp.');
        }

        $normalizedState = CheckpointCodec::normalize($state);
        $material = self::checksumMaterial(
            $taskId,
            $version,
            $cursor,
            $normalizedState,
            $schemaVersion,
            $attempt,
            $fencingGeneration,
        );
        $computedChecksum = CheckpointCodec::checksum($material);
        if ($checksum !== null && !\hash_equals($computedChecksum, \strtolower(\trim($checksum)))) {
            throw new CheckpointValidationException('Task checkpoint checksum mismatch.');
        }

        $this->taskId = $taskId;
        $this->version = $version;
        $this->cursor = $cursor;
        $this->state = $normalizedState;
        $this->schemaVersion = $schemaVersion;
        $this->attempt = $attempt;
        $this->fencingGeneration = $fencingGeneration;
        $this->checksum = $computedChecksum;
        $this->createdAt = $createdAt;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $state = $data['state'] ?? [];
        if (!\is_array($state)) {
            throw new CheckpointValidationException('Task checkpoint state must be an array.');
        }

        return new self(
            taskId: (string)($data['task_id'] ?? ''),
            version: (int)($data['version'] ?? 0),
            cursor: (string)($data['cursor'] ?? ''),
            state: $state,
            schemaVersion: (int)($data['schema_version'] ?? 1),
            attempt: (int)($data['attempt'] ?? 1),
            fencingGeneration: (int)($data['fencing_generation'] ?? 1),
            checksum: isset($data['checksum']) ? (string)$data['checksum'] : null,
            createdAt: isset($data['created_at']) ? (int)$data['created_at'] : null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'task_id' => $this->taskId,
            'version' => $this->version,
            'cursor' => $this->cursor,
            'state' => $this->state,
            'schema_version' => $this->schemaVersion,
            'attempt' => $this->attempt,
            'fencing_generation' => $this->fencingGeneration,
            'checksum' => $this->checksum,
            'created_at' => $this->createdAt,
        ];
    }

    /**
     * @param array<string|int, mixed> $state
     * @return array<string, mixed>
     */
    private static function checksumMaterial(
        string $taskId,
        int $version,
        string $cursor,
        array $state,
        int $schemaVersion,
        int $attempt,
        int $fencingGeneration,
    ): array {
        return [
            'task_id' => $taskId,
            'version' => $version,
            'cursor' => $cursor,
            'state' => $state,
            'schema_version' => $schemaVersion,
            'attempt' => $attempt,
            'fencing_generation' => $fencingGeneration,
        ];
    }

    private static function assertTaskId(string $taskId): void
    {
        if (\preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$/', $taskId) !== 1) {
            throw new \InvalidArgumentException('Task checkpoint task id is invalid.');
        }
    }
}
