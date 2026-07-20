<?php

declare(strict_types=1);

namespace Weline\Framework\Cache\Contract;

/**
 * Optional health contract for shared-cache implementations used by critical
 * runtime coordination. A failed compare-and-set is not itself a health signal.
 */
interface SharedCacheStateHealthInterface
{
    public function isSharedCacheAvailable(): bool;
}
