<?php

declare(strict_types=1);

namespace Weline\ModuleRouter\Api;

use Weline\ModuleRouter\Observer\ProcessUrlBefore;

/** Public cache invalidation boundary for modules publishing route changes. */
final class RouteCache
{
    public static function clear(): void
    {
        ProcessUrlBefore::clearCache();
    }
}
