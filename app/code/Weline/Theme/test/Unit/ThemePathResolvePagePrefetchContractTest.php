<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit;

use PHPUnit\Framework\TestCase;
use Weline\Theme\Service\Storefront\ThemePathResolvePagePrefetch;
use Weline\Theme\Service\StorefrontThemeCacheCoordinator;

/**
 * theme.path.resolve 页级 prefetch：prefetchPolicy + vary=[]；collector 禁 resolveThemeFile。
 */
final class ThemePathResolvePagePrefetchContractTest extends TestCase
{
    public function testPolicyRemainsLanguageNeutral(): void
    {
        $policy = StorefrontThemeCacheCoordinator::themePathResolvePolicy();
        self::assertSame('theme.path.resolve', $policy->resource);
        self::assertSame(StorefrontThemeCacheCoordinator::THEME_PATH_RESOLVE_POOL, $policy->pool);
        self::assertSame('global', $policy->scope);
        self::assertSame([], $policy->vary);
        self::assertSame(['theme'], $policy->dependencies);
    }

    public function testPrefetchSourceUsesPrefetchPolicyAndLiteralFetches(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 2) . '/Service/Storefront/ThemePathResolvePagePrefetch.php'
        );
        self::assertStringContainsString('prefetchPolicy(', $src);
        self::assertStringContainsString('themePathResolvePolicy()', $src);
        self::assertStringContainsString('literalFetches(', $src);
        self::assertStringContainsString('getTemplateRealPath(', $src);
        self::assertStringContainsString(ThemePathResolvePagePrefetch::LATCH_KEY, $src);
        self::assertStringNotContainsString('resolveThemeFile(', $src);
        self::assertStringNotContainsString('private static array $', $src);
    }

    public function testFrameworkPagePrefetchSoftDepsThemePathResolve(): void
    {
        $fw = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Framework/Cache/Service/StorefrontHotCachePagePrefetch.php'
        );
        self::assertStringContainsString('ThemePathResolvePagePrefetch', $fw);
        self::assertStringContainsString('prefetchThemePathResolve', $fw);
        self::assertStringContainsString('?Template $template', $fw);
    }
}
