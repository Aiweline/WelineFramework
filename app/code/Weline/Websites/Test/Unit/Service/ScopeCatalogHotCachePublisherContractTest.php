<?php

declare(strict_types=1);

namespace Weline\Websites\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Websites\Service\ScopeCatalogCacheWarmer;
use Weline\Websites\Service\ScopeCatalogHotCachePublisher;
use Weline\Websites\Service\ScopeResolver;

/**
 * Soft-clear cold hit1: freeze-after catalog L2 publish + control-plane warmer.
 */
final class ScopeCatalogHotCachePublisherContractTest extends TestCase
{
    public function testScopeResolverPublishesCatalogHotCacheAfterScopeDataInstall(): void
    {
        $src = (string)file_get_contents(
            (string)(new \ReflectionClass(ScopeResolver::class))->getFileName()
        );
        self::assertStringContainsString('ScopeData::install', $src);
        self::assertStringContainsString('publishScopeCatalogHotCache', $src);
        self::assertStringContainsString('ScopeCatalogHotCachePublisher', $src);
        $installPos = \strpos($src, 'ScopeData::install');
        $publishPos = \strpos($src, 'publishScopeCatalogHotCache');
        self::assertNotFalse($installPos);
        self::assertNotFalse($publishPos);
        self::assertGreaterThan($installPos, (int)$publishPos);
    }

    public function testPublisherRequiresFrozenScopeBeforeWork(): void
    {
        $src = (string)file_get_contents(
            (string)(new \ReflectionClass(ScopeCatalogHotCachePublisher::class))->getFileName()
        );
        self::assertStringContainsString('freezeCurrent', $src);
        self::assertStringContainsString('hasCompleteFrozenScope', $src);
        self::assertStringContainsString('rememberPolicy', $src);
        self::assertStringContainsString('byStore', $src);
    }

    public function testDefaultChannelBuilderDoesNotNestByCodeRememberPolicy(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/SalesChannelCatalog.php'
        );
        self::assertStringContainsString('ScopeCatalogHotCachePublisher', $src);
        // Fallback path must not call byCodeForStore inside default: builder.
        $methodStart = \strpos($src, 'function defaultChannelWithStore');
        self::assertNotFalse($methodStart);
        $methodEnd = \strpos($src, 'function byCodeForStore', $methodStart);
        self::assertNotFalse($methodEnd);
        $body = \substr($src, $methodStart, $methodEnd - $methodStart);
        self::assertStringNotContainsString('$this->byCodeForStore(', $body);
        self::assertStringContainsString('schema_fields_CODE', $body);
    }

    public function testModuleProvidesScopeCatalogCacheWarmer(): void
    {
        $module = require dirname(__DIR__, 3) . '/etc/module.php';
        self::assertIsArray($module);
        $provides = $module['provides'] ?? [];
        self::assertArrayHasKey('cache.warmer.websites.scope_catalog', $provides);
        self::assertSame(
            ScopeCatalogCacheWarmer::class,
            $provides['cache.warmer.websites.scope_catalog']
        );
        self::assertSame('website', (new ScopeCatalogCacheWarmer())->getTargetPool());
    }

    public function testCacheClearWarmsWebsitePoolAfterFpc(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 4) . '/Framework/Cache/Console/Cache/Clear.php'
        );
        self::assertStringContainsString("warmUp('fpc')", $src);
        self::assertStringContainsString("warmUp('website')", $src);
        $fpcPos = \strpos($src, "warmUp('fpc')");
        $webPos = \strpos($src, "warmUp('website')");
        self::assertNotFalse($fpcPos);
        self::assertNotFalse($webPos);
        self::assertGreaterThan((int)$fpcPos, (int)$webPos);
    }
}
