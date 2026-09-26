<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Service\Version;

use PHPUnit\Framework\TestCase;

/**
 * Task 5: injection decisions and cache keys belong to target ThemeScopeVersion (V),
 * not ThemeLayoutVersion / source-version borrow.
 */
final class ThemeVersionInjectionCacheContractTest extends TestCase
{
    public function testWidgetDecisionServiceResolvesTargetThemeScopeVersion(): void
    {
        $path = \dirname(__DIR__, 4) . '/Service/Version/ThemeScopeVersionWidgetDecisionService.php';
        $src = (string)\file_get_contents($path);

        self::assertStringContainsString('final class ThemeScopeVersionWidgetDecisionService', $src);
        self::assertStringContainsString('function resolveTargetThemeVersionId(', $src);
        self::assertStringContainsString('getCurrent(', $src);
        self::assertStringContainsString('getPublished(', $src);
        self::assertStringContainsString('ThemeScopeVersionWidgetDecision::DECISION_UNINSTALL', $src);
        self::assertStringContainsString('source_version_id is audit only', $src);
        self::assertStringContainsString('ThemeScopeVersionService', $src);
    }

    public function testWidgetDefaultInjectionUsesTargetThemeVersionResolver(): void
    {
        $path = \dirname(__DIR__, 4) . '/Service/WidgetDefaultInjectionService.php';
        $src = (string)\file_get_contents($path);

        self::assertStringContainsString('ThemeScopeVersionWidgetDecisionService', $src);
        self::assertStringContainsString('resolveTargetThemeVersionId(', $src);
        self::assertStringContainsString('function resolveEditingVersionId(', $src);
    }

    public function testPublishedRuntimeResolverUsesThemeScopeVersion(): void
    {
        $path = \dirname(__DIR__, 4) . '/Service/ThemePublishedVersionRuntimeResolver.php';
        $src = (string)\file_get_contents($path);

        self::assertStringContainsString('ThemeScopeVersion', $src);
        self::assertStringContainsString('getPublished(', $src);
        self::assertStringContainsString('not ThemeLayoutVersion page axis', $src);
    }

    public function testChromeHotCacheKeyIncludesEntityRenderBindingCacheKey(): void
    {
        $chrome = (string)\file_get_contents(
            \dirname(__DIR__, 4) . '/Service/LayoutEntity/ThemeLayoutEntityChrome.php'
        );
        $binding = (string)\file_get_contents(
            \dirname(__DIR__, 4) . '/Service/LayoutEntity/EntityRenderBinding.php'
        );

        self::assertStringContainsString("\$binding?->cacheKey()", $chrome);
        self::assertStringContainsString("\$this->identity->toArray()", $binding);
        self::assertStringContainsString('function cacheKey(', $binding);
    }

    public function testRuntimeCacheCleanerExposesOfflineOrphanSweep(): void
    {
        $path = \dirname(__DIR__, 4) . '/Service/ThemeRuntimeCacheCleaner.php';
        $src = (string)\file_get_contents($path);

        self::assertStringContainsString('function sweepOrphanLayoutEntityArtifacts(', $src);
        self::assertStringContainsString('offline_layout_entity_gc', $src);
        self::assertStringContainsString('protectedAbsolutePaths', $src);
    }

    public function testUpgradePurgeStillClearsEntityTreeWithoutRebake(): void
    {
        $observer = (string)\file_get_contents(
            \dirname(__DIR__, 4) . '/Observer/SetupUpgradeAfterPurgeLayoutEntities.php'
        );
        $service = (string)\file_get_contents(
            \dirname(__DIR__, 4) . '/Service/LayoutEntity/ThemeLayoutEntityUpgradePurgeService.php'
        );
        $paths = (string)\file_get_contents(
            \dirname(__DIR__, 4) . '/Service/LayoutEntity/ThemeLayoutEntityPaths.php'
        );

        self::assertStringContainsString('ThemeLayoutEntityUpgradePurgeService', $observer);
        self::assertStringContainsString('runOnce', $observer);
        self::assertStringNotContainsString('rebakeAfterInjectionCollect(', $observer);
        self::assertStringContainsString('purgeAllEntities', $service);
        self::assertStringContainsString('clearAllThemeRelatedCaches(', $service);
        self::assertStringContainsString('function purgeAllEntities', $paths);
        self::assertStringContainsString('function assertPurgeableEntityRoot', $paths);
    }
}
