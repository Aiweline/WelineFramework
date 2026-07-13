<?php

declare(strict_types=1);

namespace Weline\Framework\Runtime\Resumable;

/**
 * Capability surface available to a detached task runner.
 */
interface ResumableTaskContextInterface
{
    public function taskId(): string;

    public function attempt(): int;

    public function checkpoint(): ?TaskCheckpoint;

    /**
     * @param array<string|int, mixed> $state
     */
    public function saveCheckpoint(string $cursor, array $state, int $schemaVersion = 1): TaskCheckpoint;

    /**
     * @param array<string|int, mixed> $payload
     */
    public function emit(string $event, array $payload, ?string $coalesceKey = null): int;

    public function reserveEffect(string $effectKey): TaskEffectReservation;

    /**
     * @param array<string|int, mixed> $result
     */
    public function completeEffect(string $effectKey, array $result = []): void;

    public function isStopRequested(): bool;

    /**
     * @throws TaskStopRequestedException
     */
    public function throwIfStopRequested(): void;

    public function heartbeat(): void;
}
