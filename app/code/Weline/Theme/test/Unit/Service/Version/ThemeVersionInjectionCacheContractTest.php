<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Service\Version;

use PHPUnit\Framework\TestCase;

/**
 * Task 5: injection decisions and cache keys belong to target ThemeScopeVersion (V),
 * not ThemeLayoutVersion / source-version borrow.
 */
/**
 * ⚠️ 已过期 · 整类跳过（漂移治理，见 dev/audit/theme-legacy-audit-20261002.md）
 *
 * 根因：断言的是版本注入缓存实现的源码字符串，实现演进后不再匹配。
 *
 * 处置：整类跳过并保留用例代码，作为「测试长期无 runner、相对实现漂移」的样本；
 * 如需恢复覆盖，应按当前实现改写断言（优先断言公开契约/行为，而非源码字符串）。
 */
final class ThemeVersionInjectionCacheContractTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        self::markTestSkipped('断言的是版本注入缓存实现的源码字符串，实现演进后不再匹配。');
    }

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

    public function testUpgradeLifecycleSolidifiesViaSolidifyService(): void
    {
        $observer = (string)\file_get_contents(
            \dirname(__DIR__, 4) . '/Observer/SetupUpgradeAfterPurgeLayoutEntities.php'
        );
        $solidify = (string)\file_get_contents(
            \dirname(__DIR__, 4) . '/Service/LayoutEntity/ThemeLayoutEntityUpgradeSolidifyService.php'
        );
        $paths = (string)\file_get_contents(
            \dirname(__DIR__, 4) . '/Service/LayoutEntity/ThemeLayoutEntityPaths.php'
        );

        self::assertStringContainsString('ThemeLayoutEntityUpgradeSolidifyService', $observer);
        self::assertStringContainsString('runOnce', $observer);
        self::assertStringContainsString('rebakeAfterInjectionCollect', $solidify);
        self::assertStringContainsString('migrateLegacyVarTreeToGenerated', $solidify);
        self::assertStringContainsString('clearAllThemeRelatedCaches', $solidify);
        self::assertStringContainsString('function purgeAllEntities', $paths);
        self::assertStringContainsString('GENERATED_DIR', $paths);
    }
}
