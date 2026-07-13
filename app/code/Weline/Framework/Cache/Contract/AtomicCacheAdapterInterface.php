<?php

declare(strict_types=1);

namespace Weline\Framework\Cache\Contract;

/**
 * Optional atomic capability for cache adapters used by cross-process locks.
 */
interface AtomicCacheAdapterInterface extends CacheAdapterInterface
{
    public function compareAndSet(
        string $key,
        mixed $expected,
        mixed $value,
        int $ttl = 0,
    ): bool;
}
