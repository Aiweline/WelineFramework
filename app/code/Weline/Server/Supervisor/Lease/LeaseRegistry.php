<?php
declare(strict_types=1);

namespace Weline\Server\Supervisor\Lease;

final class LeaseRegistry
{
    /**
     * @var array<string, SlotLease>
     */
    private array $leases = [];

    /**
     * @var array<string, int>
     */
    private array $generations = [];

    /**
     * @param null|\Closure(string, int): string $leaseIdFactory
     */
    public function __construct(
        private readonly ?\Closure $leaseIdFactory = null,
    ) {
    }

    public function assign(
        string $slotId,
        string $role,
        int $pid = 0,
        int $port = 0,
        string $launchNonce = '',
        ?float $now = null
    ): SlotLease {
        $now ??= \microtime(true);
        $generation = ($this->generations[$slotId] ?? 0) + 1;
        $this->generations[$slotId] = $generation;

        $leaseId = $this->createLeaseId($slotId, $generation);
        $lease = new SlotLease(
            slotId: $slotId,
            leaseId: $leaseId,
            generation: $generation,
            role: $role,
            pid: $pid,
            port: $port,
            launchNonce: $launchNonce,
            createdAt: $now,
            updatedAt: $now,
        );

        $this->leases[$slotId] = $lease;

        return $lease;
    }

    public function get(string $slotId): ?SlotLease
    {
        return $this->leases[$slotId] ?? null;
    }

    public function isCurrent(string $slotId, string $leaseId, int $generation): bool
    {
        $lease = $this->get($slotId);

        return $lease !== null && $lease->isCurrent($leaseId, $generation);
    }

    public function markReady(string $slotId, string $leaseId, int $generation, int $port, ?float $now = null): ?SlotLease
    {
        $lease = $this->get($slotId);
        if ($lease === null || !$lease->isCurrent($leaseId, $generation)) {
            return null;
        }

        $lease = $lease->markReady($port, $now);
        $this->leases[$slotId] = $lease;

        return $lease;
    }

    public function heartbeat(string $slotId, string $leaseId, int $generation, int $seq, ?float $now = null): ?SlotLease
    {
        $lease = $this->get($slotId);
        if ($lease === null || !$lease->isCurrent($leaseId, $generation)) {
            return null;
        }

        $lease = $lease->markHeartbeat($seq, $now);
        $this->leases[$slotId] = $lease;

        return $lease;
    }

    public function release(string $slotId, string $leaseId, int $generation): bool
    {
        if (!$this->isCurrent($slotId, $leaseId, $generation)) {
            return false;
        }

        unset($this->leases[$slotId]);

        return true;
    }

    /**
     * @return array<string, SlotLease>
     */
    public function all(): array
    {
        return $this->leases;
    }

    private function createLeaseId(string $slotId, int $generation): string
    {
        if ($this->leaseIdFactory !== null) {
            return ($this->leaseIdFactory)($slotId, $generation);
        }

        return $slotId . '-l' . $generation . '-' . \bin2hex(\random_bytes(6));
    }
}
