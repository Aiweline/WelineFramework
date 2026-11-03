<?php

declare(strict_types=1);

namespace Weline\Theme\Integration\Framework;

use Weline\Framework\Runtime\ThemeRuntimeCacheResetInterface;
use Weline\Theme\Observer\ControllerFetchFileBefore;
use Weline\Theme\Service\SlotRendererService;

/**
 * Resets theme runtime caches on behalf of cross-module invalidation flows.
 */
final class ThemeRuntimeCacheReset implements ThemeRuntimeCacheResetInterface
{
    public function clearRuntimeCaches(): void
    {
        ControllerFetchFileBefore::clearRuntimeCache();
        SlotRendererService::clearProcessMemoryCache();
    }
}
