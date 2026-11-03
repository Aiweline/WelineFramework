<?php

declare(strict_types=1);

namespace Weline\Framework\Runtime;

/**
 * Optional theme/widget runtime cache resetter supplied by the theme module,
 * invoked by cross-module cache invalidation flows.
 */
interface ThemeRuntimeCacheResetInterface
{
    /**
     * Clear the theme runtime render caches for the current process.
     */
    public function clearRuntimeCaches(): void;
}
