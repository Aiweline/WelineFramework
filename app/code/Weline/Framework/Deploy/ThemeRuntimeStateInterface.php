<?php

declare(strict_types=1);

namespace Weline\Framework\Deploy;

/**
 * Optional theme deploy-generation state supplied by the theme module.
 *
 * Framework deploy/cache code resolves this contract from the container; when no
 * provider is registered it falls back to its own static build id.
 */
interface ThemeRuntimeStateInterface
{
    public function pointerCacheGeneration(): string;

    public function pageGeneration(): string;

    public function cacheGeneration(): string;

    public function pairSegment(): string;

    public function pinnedStaticBuild(): string;

    public function activeThemePrefix(): string;

    public function staticPrefixForPath(string $urlPath): ?string;

    /**
     * Backfill theme pair directories after a new deploy lands; returns the
     * number of generations linked (0 when flat mode / nothing to do).
     */
    public function backfillThemePair(string $pubStatic, string $newDeployId): int;
}
