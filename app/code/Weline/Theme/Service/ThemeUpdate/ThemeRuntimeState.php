<?php

declare(strict_types=1);

namespace Weline\Theme\Service\ThemeUpdate;

use Weline\Framework\Deploy\ThemeRuntimeStateInterface;

/**
 * Adapts the theme deploy runtime to the Framework-owned contract.
 */
final class ThemeRuntimeState implements ThemeRuntimeStateInterface
{
    public function pointerCacheGeneration(): string
    {
        return ThemeUpdateRuntime::pointerCacheGeneration();
    }

    public function pageGeneration(): string
    {
        return ThemeUpdateRuntime::pageGeneration();
    }

    public function cacheGeneration(): string
    {
        return ThemeUpdateRuntime::cacheGeneration();
    }

    public function pairSegment(): string
    {
        return ThemeUpdateRuntime::pairSegment();
    }

    public function pinnedStaticBuild(): string
    {
        return ThemeUpdateRuntime::pinnedStaticBuild();
    }

    public function activeThemePrefix(): string
    {
        return ThemeUpdateRuntime::activeThemePrefix();
    }

    public function staticPrefixForPath(string $urlPath): ?string
    {
        return ThemeUpdateRuntime::staticPrefixForPath($urlPath);
    }

    public function backfillThemePair(string $pubStatic, string $newDeployId): int
    {
        return ThemeUpdateBatchService::backfillThemePairForNewDeploy($pubStatic, $newDeployId);
    }
}
