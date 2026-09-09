<?php

declare(strict_types=1);

namespace Weline\Framework\Cache\Contract;

/** 既有共享缓存域的可选批量读写能力。 */
interface SharedCacheBatchStateInterface extends SharedCacheStateInterface
{
    /** @param list<string> $keys @return array<string,mixed> */
    public function getCacheMultiple(string $poolIdentity, array $keys): array;

    /** @param array<string,mixed> $values */
    public function setCacheMultiple(string $poolIdentity, array $values, int $ttl = 0): bool;
}
