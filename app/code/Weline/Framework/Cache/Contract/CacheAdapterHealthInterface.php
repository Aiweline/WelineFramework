<?php

declare(strict_types=1);

namespace Weline\Framework\Cache\Contract;

/** Optional fail-closed availability signal for remote cache adapters. */
interface CacheAdapterHealthInterface
{
    public function isAvailable(): bool;
}
