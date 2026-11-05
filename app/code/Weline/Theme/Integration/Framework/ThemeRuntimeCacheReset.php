<?php

declare(strict_types=1);

namespace Weline\Theme\Integration\Framework;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\ThemeRuntimeCacheResetInterface;
use Weline\Theme\Model\WelineTheme;
use Weline\Theme\Observer\ControllerFetchFileBefore;
use Weline\Theme\Service\LayoutEntity\RequiredDefaultInjectionRuntimeSafetyNet;
use Weline\Theme\Service\SlotRendererService;
use Weline\Theme\Service\Version\ThemeScopeVersionWidgetDecisionService;
use Weline\Theme\Service\WidgetDefaultInjectionService;
use Weline\Widget\Service\DefaultInjectionPlanRepository;

/**
 * Resets theme runtime caches on behalf of cross-module invalidation flows.
 */
final class ThemeRuntimeCacheReset implements ThemeRuntimeCacheResetInterface
{
    public function clearRuntimeCaches(): void
    {
        ControllerFetchFileBefore::clearRuntimeCache();
        SlotRendererService::clearProcessMemoryCache();

        // Default-injection read paths held in process memory. Each of these
        // answers from a snapshot loaded on first use, so a layout/theme change
        // must drop them or the next render serves the previous version.
        RequiredDefaultInjectionRuntimeSafetyNet::clearProcessCaches();
        WidgetDefaultInjectionService::clearProcessCaches();
        ThemeScopeVersionWidgetDecisionService::clearProcessCaches();
        // Theme catalogue rows + runtime theme rows live in process memory too; a
        // layout/theme invalidation must drop them so the next render re-reads.
        WelineTheme::clearCatalogRowsCache();
        try {
            ObjectManager::getInstance(DefaultInjectionPlanRepository::class)->clearMemo();
        } catch (\Throwable) {
            // Widget module may be absent on non-storefront deployments.
        }
    }
}
