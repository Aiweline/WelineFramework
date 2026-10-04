<?php
declare(strict_types=1);

namespace Weline\Framework\Cache\Contract;

/** Writes whose success means the authoritative storage accepted the value. */
interface FreshCacheWriteInterface extends CacheAdapterInterface
{
    public function setFresh(string $key, mixed $value, int $ttl = 0): bool;
}
