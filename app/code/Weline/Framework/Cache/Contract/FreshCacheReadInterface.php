<?php
declare(strict_types=1);

namespace Weline\Framework\Cache\Contract;

/** Authoritative storage reads for transactions that cannot use a process snapshot. */
interface FreshCacheReadInterface extends CacheAdapterInterface
{
    public function getFresh(string $key): mixed;
}
