<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Cache\Service\StorefrontScopeHotCache;
use Weline\Framework\Context;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestContext;
use Weline\Theme\Helper\Interface\ThemeChainResolverInterface;
use Weline\Theme\Helper\ThemePathResolver;
use Weline\Theme\Model\WelineTheme;
use Weline\Theme\Observer\TemplateFetchFile;
use Weline\Theme\Service\ThemeDirectoryResolver;

/**
 * P1：模板路径解析仅请求内 rememberForRequest memo，禁止平行私袋升格为跨请求永久 static。
 */
final class ThemePathResolverRequestMemoContractTest extends TestCase
{
    private mixed $originalDirectoryResolver = null;

    protected function tearDown(): void
    {
        if ($this->originalDirectoryResolver !== null) {
            ObjectManager::setInstance(ThemeDirectoryResolver::class, $this->originalDirectoryResolver);
            $this->originalDirectoryResolver = null;
        } else {
            ObjectManager::removeInstance(ThemeDirectoryResolver::class);
        }
        if (Context::hasCurrent()) {
            RequestContext::cleanup();
            Context::leave();
        }
        parent::tearDown();
    }

    public function testSourceUsesRememberForRequestOnly(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 2) . '/Helper/ThemePathResolver.php'
        );

        self::assertStringContainsString('rememberForRequest', $src);
        self::assertStringContainsString('theme.path.resolve', $src);
        self::assertStringContainsString('StorefrontScopeHotCache', $src);
        self::assertStringContainsString('Context::hasCurrent()', $src);
        self::assertStringNotContainsString('private static array $', $src);
        self::assertStringNotContainsString('rememberPolicy(', $src);
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

    public function testSameRequestSecondResolveDoesNotRescanDirectoryResolver(): void
    {
        if (Context::hasCurrent()) {
            RequestContext::cleanup();
            Context::leave();
        }
        Context::enter(new Context(['meta' => ['type' => 'request', 'mode' => 'fpm']]));
        RequestContext::setId('theme-path-memo-contract');

        $counter = new ThemePathResolverDirectoryResolveCounter();
        $instances = ObjectManager::getInstances();
        $this->originalDirectoryResolver = $instances[ThemeDirectoryResolver::class] ?? null;
        ObjectManager::setInstance(ThemeDirectoryResolver::class, $counter);

        $hotCache = new StorefrontScopeHotCache();
        $chain = $this->createStub(ThemeChainResolverInterface::class);
        $resolver = new ThemePathResolver($chain, $hotCache);

        $theme = new WelineTheme();
        $theme->setData(WelineTheme::schema_fields_ID, 42);
        $theme->setData(WelineTheme::schema_fields_PATH, sys_get_temp_dir() . DS . 'weline-theme-path-memo' . DS);

        $modulePath = APP_CODE_PATH . 'Weline' . DS . 'Theme' . DS . 'view' . DS . 'theme' . DS
            . 'frontend' . DS . 'partials' . DS . 'header' . DS . 'default.phtml';

        $first = $resolver->resolveThemeFile($modulePath, $theme);
        $second = $resolver->resolveThemeFile($modulePath, $theme);

        self::assertSame($first, $second);
        self::assertSame(1, $counter->calls, 'Second resolve in the same request must hit rememberForRequest memo.');
    }

    public function testWithoutContextSkipsRequestMemoAndRescans(): void
    {
        if (Context::hasCurrent()) {
            RequestContext::cleanup();
            Context::leave();
        }

        $counter = new ThemePathResolverDirectoryResolveCounter();
        $instances = ObjectManager::getInstances();
        $this->originalDirectoryResolver = $instances[ThemeDirectoryResolver::class] ?? null;
        ObjectManager::setInstance(ThemeDirectoryResolver::class, $counter);

        $hotCache = new StorefrontScopeHotCache();
        $chain = $this->createStub(ThemeChainResolverInterface::class);
        $resolver = new ThemePathResolver($chain, $hotCache);

        $theme = new WelineTheme();
        $theme->setData(WelineTheme::schema_fields_ID, 7);
        $theme->setData(WelineTheme::schema_fields_PATH, sys_get_temp_dir() . DS . 'weline-theme-path-memo' . DS);

        $modulePath = APP_CODE_PATH . 'Weline' . DS . 'Theme' . DS . 'view' . DS . 'theme' . DS
            . 'frontend' . DS . 'partials' . DS . 'header' . DS . 'default.phtml';

        $resolver->resolveThemeFile($modulePath, $theme);
        $resolver->resolveThemeFile($modulePath, $theme);

        self::assertSame(2, $counter->calls);
        self::assertFalse(Context::hasCurrent());
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
