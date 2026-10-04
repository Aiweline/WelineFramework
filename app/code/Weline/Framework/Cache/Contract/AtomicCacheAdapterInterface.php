<?php

declare(strict_types=1);

namespace Weline\Framework\Cache\Contract;

/**
 * Optional atomic capability for cache adapters used by cross-process locks.
 */
interface AtomicCacheAdapterInterface extends CacheAdapterInterface
{
    /**
     * @throws \Weline\Framework\Cache\Exception\AtomicWriteOutcomeUnknownException 已发送写入但未确认本次结果；调用方不得按普通冲突重放消费。
     */
    public function compareAndSet(
        string $key,
        mixed $expected,
        mixed $value,
        int $ttl = 0,
    ): bool;
}
