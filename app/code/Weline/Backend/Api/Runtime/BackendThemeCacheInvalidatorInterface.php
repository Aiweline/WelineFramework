<?php

declare(strict_types=1);

namespace Weline\Backend\Api\Runtime;

/** Optional cache invalidation contribution after a backend theme change. */
interface BackendThemeCacheInvalidatorInterface
{
    public function invalidate(): void;
}
