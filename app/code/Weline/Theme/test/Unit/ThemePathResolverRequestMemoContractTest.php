<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Cache\Service\StorefrontScopeHotCache;
use Weline\Framework\Manager\ObjectManager;
use Weline\Theme\Helper\Interface\ThemeChainResolverInterface;
use Weline\Theme\Helper\ThemePathResolver;
use Weline\Theme\Model\WelineTheme;
use Weline\Theme\Observer\TemplateFetchFile;
use Weline\Theme\Service\StorefrontThemeCacheCoordinator;
use Weline\Theme\Service\ThemeDirectoryResolver;

\defined('DS') || \define('DS', \DIRECTORY_SEPARATOR);
\defined('BP') || \define('BP', \dirname(__DIR__, 6) . \DIRECTORY_SEPARATOR);
\defined('APP_PATH') || \define('APP_PATH', BP . 'app' . DS);
\defined('APP_CODE_PATH') || \define('APP_CODE_PATH', APP_PATH . 'code' . DS);

/**
 * wave4-4b：模板路径解析升格 CachePolicy rememberPolicy(deps=theme)；禁平行 static。
 */
final class ThemePathResolverRequestMemoContractTest extends TestCase
{
    private mixed $originalDirectoryResolver = null;

    protected function setUp(): void
    {
        parent::setUp();
        StorefrontScopeHotCache::resetProcessCache();
    }

    protected function tearDown(): void
    {
        if ($this->originalDirectoryResolver !== null) {
            ObjectManager::setInstance(ThemeDirectoryResolver::class, $this->originalDirectoryResolver);
            $this->originalDirectoryResolver = null;
        } else {
            ObjectManager::removeInstance(ThemeDirectoryResolver::class);
        }
        StorefrontScopeHotCache::resetProcessCache();
        parent::tearDown();
    }

    public function testSourceUsesRememberPolicyWithThemeDeps(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 2) . '/Helper/ThemePathResolver.php'
        );

        self::assertStringContainsString('rememberPolicy(', $src);
        self::assertStringContainsString('themePathResolvePolicy()', $src);
        self::assertStringContainsString('theme.path.resolve', $src);
        self::assertStringContainsString('StorefrontScopeHotCache', $src);
        self::assertStringNotContainsString('private static array $', $src);
        self::assertStringNotContainsString('rememberForRequest(', $src);

        $policy = StorefrontThemeCacheCoordinator::themePathResolvePolicy();
        self::assertSame('theme.path.resolve', $policy->resource);
        self::assertSame(StorefrontThemeCacheCoordinator::THEME_PATH_RESOLVE_POOL, $policy->pool);
        self::assertSame('global', $policy->scope);
        self::assertSame([], $policy->vary);
        self::assertSame(['theme'], $policy->dependencies);
    }

    public function testTemplateFetchFileReusesPathResolver(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 2) . '/Observer/TemplateFetchFile.php'
        );

        self::assertStringContainsString('ThemePathResolverInterface', $src);
        self::assertStringContainsString('$this->themePathResolver->resolveThemeFile(', $src);
        self::assertSame(
            1,
            substr_count($src, 'resolveThemeFile('),
            'TemplateFetchFile must resolve via ThemePathResolver only (no parallel path scan).'
        );
        self::assertTrue(class_exists(TemplateFetchFile::class));
    }

    public function testSecondResolveHitsPolicyWithoutRescan(): void
    {
        $counter = new ThemePathResolverDirectoryResolveCounter();
        $instances = ObjectManager::getInstances();
        $this->originalDirectoryResolver = $instances[ThemeDirectoryResolver::class] ?? null;
        ObjectManager::setInstance(ThemeDirectoryResolver::class, $counter);

        $hotCache = new StorefrontScopeHotCache();
        $chain = $this->createStub(ThemeChainResolverInterface::class);
        $resolver = new ThemePathResolver($chain, $hotCache);

        $theme = new WelineTheme();
        $theme->setData(WelineTheme::schema_fields_ID, 42);
        $theme->setData(WelineTheme::schema_fields_PATH, sys_get_temp_dir() . DS . 'weline-theme-path-policy' . DS);

        $modulePath = APP_CODE_PATH . 'Weline' . DS . 'Theme' . DS . 'view' . DS . 'theme' . DS
            . 'frontend' . DS . 'partials' . DS . 'header' . DS . 'default.phtml';

        $first = $resolver->resolveThemeFile($modulePath, $theme);
        $second = $resolver->resolveThemeFile($modulePath, $theme);

        self::assertSame($first, $second);
        self::assertSame(1, $counter->calls, 'Second resolve must hit rememberPolicy L1/L2.');
    }

    public function testWithoutThemeIdSkipsPolicyAndRescans(): void
    {
        $counter = new ThemePathResolverDirectoryResolveCounter();
        $instances = ObjectManager::getInstances();
        $this->originalDirectoryResolver = $instances[ThemeDirectoryResolver::class] ?? null;
        ObjectManager::setInstance(ThemeDirectoryResolver::class, $counter);

        $hotCache = new StorefrontScopeHotCache();
        $chain = $this->createStub(ThemeChainResolverInterface::class);
        $resolver = new ThemePathResolver($chain, $hotCache);

        $theme = new WelineTheme();
        $theme->setData(WelineTheme::schema_fields_PATH, sys_get_temp_dir() . DS . 'weline-theme-path-policy' . DS);

        $modulePath = APP_CODE_PATH . 'Weline' . DS . 'Theme' . DS . 'view' . DS . 'theme' . DS
            . 'frontend' . DS . 'partials' . DS . 'header' . DS . 'default.phtml';

        $resolver->resolveThemeFile($modulePath, $theme);
        $resolver->resolveThemeFile($modulePath, $theme);

        self::assertSame(2, $counter->calls);
        self::assertFalse((bool)$theme->getId());
    }
}

/**
 * Counts ThemeDirectoryResolver hits without subclassing final HotCache.
 *
 * @method string resolveThemeTemplatePath(string $modulePath, WelineTheme $theme)
 */
final class ThemePathResolverDirectoryResolveCounter
{
    public int $calls = 0;

    public function resolveThemeTemplatePath(string $modulePath, WelineTheme $theme): string
    {
        $this->calls++;
        // Force fallthrough to inheritance-chain is_file (same as "no design hit").
        return $modulePath;
    }
}
