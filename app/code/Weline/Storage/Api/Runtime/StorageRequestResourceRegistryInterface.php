<?php

declare(strict_types=1);

namespace Weline\Storage\Api\Runtime;

use Weline\Framework\Runtime\RequestResourceInterface;

/** Request/Fiber-owned registry; implementations must never be process shared. */
interface StorageRequestResourceRegistryInterface
{
    public function register(RequestResourceInterface $resource): void;

    public function release(RequestResourceInterface $resource): void;

    public function activeCount(): int;

    public function deferCleanupFailure(\Throwable $throwable): void;

    public function closeAll(): void;
}
