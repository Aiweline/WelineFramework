<?php

declare(strict_types=1);

namespace Weline\Server\Shared\Contract;

interface ConnectionPoolInterface
{
    public function acquire(float $timeoutSec = 0.05): ?PooledConnectionInterface;

    public function release(PooledConnectionInterface $connection): void;

    public function invalidate(PooledConnectionInterface $connection): void;

    public function healthCheck(): bool;

    public function shutdown(): void;
}
