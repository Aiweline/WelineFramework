<?php

declare(strict_types=1);

namespace Weline\Theme\Api\Runtime;

use Weline\Framework\Cache\Contract\MemoryStoreInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\ProcessCacheResetContext;
use Weline\Framework\Runtime\ProcessCacheResetterInterface;
use Weline\Theme\Block\Partials;
use Weline\Theme\Helper\LayoutDependencyTracker;
use Weline\Theme\Helper\ThemeData;
use Weline\Theme\Observer\ControllerFetchFileBefore;
use Weline\Theme\Service\RuntimeTemplateMaterializer;
use Weline\Theme\Service\SlotRendererService;
use Weline\Theme\Taglib\ThemeTemplate;

final class ProcessCacheResetter implements ProcessCacheResetterInterface, MemoryStoreInterface
{
    public function resetProcessCaches(ProcessCacheResetContext $context): int
    {
        // Soft pressure: keep process-local chrome HTML output.
        // Explicit cache clear: wipe everything including partial HTML.
        if ($context->isExplicitCacheClear()) {
            Partials::clearAllCaches();
            ObjectManager::getInstance(SlotRendererService::class)->clearCache();
            ControllerFetchFileBefore::clearRuntimeCache();
            ThemeData::clearCache();
            ThemeTemplate::clearProcessCache();
            LayoutDependencyTracker::clearCache();
            RuntimeTemplateMaterializer::clearProcessCache();
            return 7;
        }

        Partials::clearMetaCache();
        return 1;
    }

    public function getMemoryUsage(): int
    {
        // Entries contain small metadata arrays or bounded HTML/template strings.
        // Expose a conservative estimate without serializing live values or request data.
        return min($this->getMaxMemory(), $this->getMemoryItemCount() * 1024);
    }

    public function getMemoryItemCount(): int
    {
        return ThemeData::processCacheItemCount()
            + ControllerFetchFileBefore::processCacheItemCount()
            + Partials::processCacheItemCount()
            + SlotRendererService::processCacheItemCount()
            + ThemeTemplate::processCacheItemCount()
            + LayoutDependencyTracker::processCacheItemCount()
            + RuntimeTemplateMaterializer::processCacheItemCount();
    }

    public function getMaxItems(): int
    {
        return 8192;
    }

    public function getMaxMemory(): int
    {
        return 64 * 1024 * 1024;
    }

    public function evict(int $count): int
    {
        if ($count < 1) {
            return 0;
        }
        $before = $this->getMemoryItemCount();
        $this->clearMemory();
        return $before - $this->getMemoryItemCount();
    }

    public function clearMemory(): void
    {
        ThemeData::clearProcessMemoryCache();
        ControllerFetchFileBefore::clearRuntimeCache();
        Partials::clearAllCaches();
        SlotRendererService::clearProcessMemoryCache();
        ThemeTemplate::clearProcessCache();
        LayoutDependencyTracker::clearCache();
        RuntimeTemplateMaterializer::clearProcessCache();
    }

    public function warmUp(int $limit = 1000): int
    {
        return 0;
    }
}
