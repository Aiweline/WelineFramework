<?php
declare(strict_types=1);

namespace Weline\Server\Supervisor\Lease;

final class SlotLease
{
    public const STATE_LEASED = 'leased';
    public const STATE_READY = 'ready';
    public const STATE_DRAINING = 'draining';
    public const STATE_STOPPING = 'stopping';
    public const STATE_EXITED = 'exited';

    public function __construct(
        public readonly string $slotId,
        public readonly string $leaseId,
        public readonly int $generation,
        public readonly string $role,
        public readonly int $pid = 0,
        public readonly int $port = 0,
        public readonly string $launchNonce = '',
        public readonly string $state = self::STATE_LEASED,
        public readonly float $createdAt = 0.0,
        public readonly float $updatedAt = 0.0,
        public readonly int $heartbeatSeq = 0,
    ) {
    }

    public function isCurrent(string $leaseId, int $generation): bool
    {
        return $this->leaseId === $leaseId && $this->generation === $generation;
    }

    public function markReady(int $port, ?float $now = null): self
    {
        $now ??= \microtime(true);

        return new self(
            slotId: $this->slotId,
            leaseId: $this->leaseId,
            generation: $this->generation,
            role: $this->role,
            pid: $this->pid,
            port: $port,
            launchNonce: $this->launchNonce,
            state: self::STATE_READY,
            createdAt: $this->createdAt,
            updatedAt: $now,
            heartbeatSeq: $this->heartbeatSeq,
        );
    }

    public function markHeartbeat(int $seq, ?float $now = null): self
    {
        $now ??= \microtime(true);

        return new self(
            slotId: $this->slotId,
            leaseId: $this->leaseId,
            generation: $this->generation,
            role: $this->role,
            pid: $this->pid,
            port: $this->port,
            launchNonce: $this->launchNonce,
            state: $this->state,
            createdAt: $this->createdAt,
            updatedAt: $now,
            heartbeatSeq: $seq,
        );
    }

    /**
     * @return array<string, int|string|float>
     */
    public function toArray(): array
    {
        return [
            'slot_id' => $this->slotId,
            'lease_id' => $this->leaseId,
            'generation' => $this->generation,
            'role' => $this->role,
            'pid' => $this->pid,
            'port' => $this->port,
            'launch_nonce' => $this->launchNonce,
            'state' => $this->state,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
            'heartbeat_seq' => $this->heartbeatSeq,
        ];
    }
}
