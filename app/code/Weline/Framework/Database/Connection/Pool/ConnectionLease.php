<?php

declare(strict_types=1);

namespace Weline\Framework\Database\Connection\Pool;

use PDO;

/**
 * A single, strictly bounded checkout from ConnectionPool.
 *
 * The lease is the ownership token; keeping a bare PDO reference does not
 * extend the checkout. release()/discard() and destruction are idempotent.
 */
final class ConnectionLease
{
    private const STATE_ACTIVE = 0;
    private const STATE_RELEASED = 1;
    private const STATE_DISCARDED = 2;

    private int $state = self::STATE_ACTIVE;

    /** @internal Construct leases through ConnectionPool::acquire(). */
    public function __construct(
        private readonly PDO $connection,
        private readonly string $poolKey,
        private readonly string $ownerKey,
        private readonly int $leaseToken,
    ) {
    }

    public function getConnection(): PDO
    {
        return $this->connection;
    }

    public function isActive(): bool
    {
        return $this->state === self::STATE_ACTIVE;
    }

    public function release(): void
    {
        if (!$this->transitionTo(self::STATE_RELEASED)) {
            return;
        }

        ConnectionPool::releaseLease($this);
    }

    public function discard(): void
    {
        if (!$this->transitionTo(self::STATE_DISCARDED)) {
            return;
        }

        ConnectionPool::discardLease($this);
    }

    /** @internal Pool lifecycle cleanup invalidates a retained owner token. */
    public function finalizeFromPool(bool $discarded): void
    {
        $this->transitionTo($discarded ? self::STATE_DISCARDED : self::STATE_RELEASED);
    }

    /** @internal Immutable pool identity captured at checkout time. */
    public function getPoolKey(): string
    {
        return $this->poolKey;
    }

    /** @internal Request/Fiber owner identity captured at checkout time. */
    public function getOwnerKey(): string
    {
        return $this->ownerKey;
    }

    /** @internal Process-local logical checkout identity. */
    public function getLeaseToken(): int
    {
        return $this->leaseToken;
    }

    private function transitionTo(int $state): bool
    {
        if ($this->state !== self::STATE_ACTIVE) {
            return false;
        }

        $this->state = $state;
        return true;
    }

    private function __clone(): void
    {
    }

    public function __serialize(): array
    {
        throw new \LogicException('ConnectionLease cannot be serialized.');
    }

    public function __unserialize(array $data): void
    {
        unset($data);
        throw new \LogicException('ConnectionLease cannot be unserialized.');
    }

    public function __destruct()
    {
        $this->release();
    }
}
