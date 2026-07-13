<?php

declare(strict_types=1);

namespace Weline\Framework\Runtime\Resumable;

/**
 * Ledger record used to make an external side effect idempotent across a
 * runner crash. UNKNOWN deliberately requires reconciliation, never retry.
 */
final class TaskEffectReservation
{
    public readonly string $taskId;
    public readonly string $effectKey;
    public readonly TaskEffectState $state;
    public readonly bool $alreadyExisted;
    public readonly ?string $externalReference;
    /** @var array<string|int, mixed> */
    public readonly array $result;
    public readonly int $attempt;
    public readonly int $fencingGeneration;

    /**
     * @param array<string|int, mixed> $result
     */
    public function __construct(
        string $taskId,
        string $effectKey,
        TaskEffectState $state,
        bool $alreadyExisted = false,
        ?string $externalReference = null,
        array $result = [],
        int $attempt = 1,
        int $fencingGeneration = 1,
    ) {
        if (\preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$/', $taskId) !== 1) {
            throw new \InvalidArgumentException('Task effect task id is invalid.');
        }
        if (\preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,159}$/', $effectKey) !== 1) {
            throw new \InvalidArgumentException('Task effect key is invalid.');
        }
        if ($attempt < 1 || $fencingGeneration < 1) {
            throw new \InvalidArgumentException('Task effect attempt and fencing generation must be positive.');
        }
        if ($externalReference !== null && \trim($externalReference) === '') {
            throw new \InvalidArgumentException('Task effect external reference cannot be blank.');
        }

        $this->taskId = $taskId;
        $this->effectKey = $effectKey;
        $this->state = $state;
        $this->alreadyExisted = $alreadyExisted;
        $this->externalReference = $externalReference;
        $this->result = CheckpointCodec::normalize($result);
        $this->attempt = $attempt;
        $this->fencingGeneration = $fencingGeneration;
    }

    public function externalIdempotencyKey(): string
    {
        return $this->taskId . ':' . $this->effectKey;
    }

    public function requiresManualRecovery(): bool
    {
        return $this->state === TaskEffectState::UNKNOWN;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'task_id' => $this->taskId,
            'effect_key' => $this->effectKey,
            'state' => $this->state->value,
            'already_existed' => $this->alreadyExisted,
            'external_idempotency_key' => $this->externalIdempotencyKey(),
            'external_reference' => $this->externalReference,
            'result' => $this->result,
            'attempt' => $this->attempt,
            'fencing_generation' => $this->fencingGeneration,
        ];
    }
}
