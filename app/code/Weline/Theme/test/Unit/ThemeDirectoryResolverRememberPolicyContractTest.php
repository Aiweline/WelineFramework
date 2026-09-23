<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit;

use PHPUnit\Framework\TestCase;
use Weline\Theme\Service\StorefrontThemeCacheCoordinator;
use Weline\Theme\Service\ThemeDirectoryResolver;
use Weline\Theme\Service\ThemeRuntimeCacheCleaner;

/**
 * wave4-4b：area 目录扫盘升格 CachePolicy rememberPolicy(deps=theme)；禁平行 areaDirectories 进程袋。
 */
final class ThemeDirectoryResolverRememberPolicyContractTest extends TestCase
{
    public function testSourceUsesRememberPolicyAndDropsAreaDirectoriesBag(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 2) . '/Service/ThemeDirectoryResolver.php'
        );

        self::assertStringContainsString('rememberPolicy(', $src);
        self::assertStringContainsString('themeAreaDirectoriesPolicy()', $src);
        self::assertStringContainsString('buildAreaDirectories(', $src);
        self::assertStringContainsString('StorefrontScopeHotCache', $src);
        self::assertStringNotContainsString('$areaDirectoriesCache', $src);
        self::assertStringNotContainsString('private static array $', $src);
    }

    public function testAreaDirectoriesPolicyIsGlobalThemeDeps(): void
    {
        $policy = StorefrontThemeCacheCoordinator::themeAreaDirectoriesPolicy();
        self::assertSame('theme.area.directories', $policy->resource);
        self::assertSame(StorefrontThemeCacheCoordinator::THEME_AREA_DIRECTORIES_POOL, $policy->pool);
        self::assertSame('global', $policy->scope);
        self::assertSame([], $policy->vary);
        self::assertSame(['theme'], $policy->dependencies);
        self::assertSame(0, $policy->staleTtlSeconds);
    }

    public function testRuntimeCleanerPurgesPathDirectoryPools(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 2) . '/Service/ThemeRuntimeCacheCleaner.php'
        );

        self::assertStringContainsString('purgeThemePathDirectoryHotCachePools', $src);
        self::assertStringContainsString('theme_path_directory_hot_cache', $src);
        self::assertStringContainsString('THEME_PATH_RESOLVE_POOL', $src);
        self::assertStringContainsString('THEME_AREA_DIRECTORIES_POOL', $src);
        self::assertTrue(class_exists(ThemeRuntimeCacheCleaner::class));
        self::assertTrue(class_exists(ThemeDirectoryResolver::class));
    }
}
