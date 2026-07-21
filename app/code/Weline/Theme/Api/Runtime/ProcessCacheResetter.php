<?php

declare(strict_types=1);

namespace Weline\Theme\Api\Runtime;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\ProcessCacheResetContext;
use Weline\Framework\Runtime\ProcessCacheResetterInterface;
use Weline\Theme\Block\Partials;
use Weline\Theme\Helper\ThemeData;
use Weline\Theme\Observer\ControllerFetchFileBefore;
use Weline\Theme\Service\SlotRendererService;

final class ProcessCacheResetter implements ProcessCacheResetterInterface
{
    public function resetProcessCaches(ProcessCacheResetContext $context): int
    {
        // Soft pressure: keep chrome HTML output (process + theme_runtime handle).
        // Explicit cache clear: wipe everything including partial HTML.
        if ($context->isExplicitCacheClear()) {
            Partials::clearAllCaches();
            ObjectManager::getInstance(SlotRendererService::class)->clearCache();
            ControllerFetchFileBefore::clearRuntimeCache();
            ThemeData::clearCache();
            return 4;
        }

        Partials::clearMetaCache();
        return 1;
    }
}
