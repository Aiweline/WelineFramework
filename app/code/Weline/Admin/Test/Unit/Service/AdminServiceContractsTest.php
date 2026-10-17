<?php

declare(strict_types=1);

namespace Weline\Admin\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use ReflectionClass as ReflectionClass;
use ReflectionMethod as ReflectionMethod;
use ReflectionProperty as ReflectionProperty;
use Weline\Admin\Api\Runtime\ProcessCacheResetter;
use Weline\Admin\Model\MenuAccessLog;
use Weline\Admin\Service\MenuRenderService;
use Weline\Framework\Runtime\ProcessCacheResetContext;

/**
 * Aggregated contract suite (merged from 3 *ContractTest files).
 * Observe one overall pass/fail via testAllAdminServiceContractsPass().
 */
final class AdminServiceContractsTest extends TestCase
{
    public function testAllAdminServiceContractsPass(): void
    {
        $failures = [];
        foreach ($this->contractChecks() as $name => $fn) {
            try {
                $fn();
            } catch (\Throwable $e) {
                $failures[] = $name . ": " . $e->getMessage();
            }
        }
        self::assertSame([], $failures, implode("\n", $failures));
    }

    /** @return array<string, callable(): void> */
    private function contractChecks(): array
    {
        return [
            'MenuRenderServiceBackendUrlLocale::testFormatMenuUrlCachedPreservesLocaleAwareBackendPrefix' => function () {
            $this->assert_MenuRenderServiceBackendUrlLocale_testFormatMenuUrlCachedPreservesLocaleAwareBackendPrefix();
        },
            'MenuRenderServiceBackendUrlLocale::testFormatMenuUrlCachedStripsQueryFromPrefixBeforeRouteConcat' => function () {
            $this->assert_MenuRenderServiceBackendUrlLocale_testFormatMenuUrlCachedStripsQueryFromPrefixBeforeRouteConcat();
        },
            'MenuRenderServiceCrossLocaleSearch::testBuildCrossLocaleSearchTextIncludesOtherLocales' => function () {
            $this->assert_MenuRenderServiceCrossLocaleSearch_testBuildCrossLocaleSearchTextIncludesOtherLocales();
        },
            'MenuRenderServiceCrossLocaleSearch::testRenderMenuNodeDoesNotEmbedDataSearchText' => function () {
            $this->assert_MenuRenderServiceCrossLocaleSearch_testRenderMenuNodeDoesNotEmbedDataSearchText();
        },
            'MenuRenderServiceCrossLocaleSearch::testRenderMenuSourceDoesNotPrefetchOnHotPath' => function () {
            $this->assert_MenuRenderServiceCrossLocaleSearch_testRenderMenuSourceDoesNotPrefetchOnHotPath();
        },
            'MenuRenderServiceCrossLocaleSearch::testBuildIndexSearchItemsIncludesRouteAndSearchText' => function () {
            $this->assert_MenuRenderServiceCrossLocaleSearch_testBuildIndexSearchItemsIncludesRouteAndSearchText();
        },
            'MenuRenderServiceRenderedMenuCache::testClearProcessCacheEmptiesRenderedAndFrequentCaches' => function () {
            try {
                $this->assert_MenuRenderServiceRenderedMenuCache_testClearProcessCacheEmptiesRenderedAndFrequentCaches();
            } finally {
                $this->tearDown_MenuRenderServiceRenderedMenuCache();
            }
        },
            'MenuRenderServiceRenderedMenuCache::testRememberRenderedMenuRespectsMaxAndDropsExpired' => function () {
            try {
                $this->assert_MenuRenderServiceRenderedMenuCache_testRememberRenderedMenuRespectsMaxAndDropsExpired();
            } finally {
                $this->tearDown_MenuRenderServiceRenderedMenuCache();
            }
        },
            'MenuRenderServiceRenderedMenuCache::testProcessCacheResetterClearsMenuRenderProcessCaches' => function () {
            try {
                $this->assert_MenuRenderServiceRenderedMenuCache_testProcessCacheResetterClearsMenuRenderProcessCaches();
            } finally {
                $this->tearDown_MenuRenderServiceRenderedMenuCache();
            }
        },
            'MenuRenderServiceRenderedMenuCache::testCacheKeyUsesActiveFingerprintNotRawCurrentUrl' => function () {
            try {
                $this->assert_MenuRenderServiceRenderedMenuCache_testCacheKeyUsesActiveFingerprintNotRawCurrentUrl();
            } finally {
                $this->tearDown_MenuRenderServiceRenderedMenuCache();
            }
        },
            'MenuRenderServiceRenderedMenuCache::testActiveFingerprintStableAcrossUrlsHittingSameLeaf' => function () {
            try {
                $this->assert_MenuRenderServiceRenderedMenuCache_testActiveFingerprintStableAcrossUrlsHittingSameLeaf();
            } finally {
                $this->tearDown_MenuRenderServiceRenderedMenuCache();
            }
        },
        ];
    }

private function assert_MenuRenderServiceBackendUrlLocale_testFormatMenuUrlCachedPreservesLocaleAwareBackendPrefix(): void
{
    $service = new MenuRenderService($this->createStub(MenuAccessLog::class));
    $backend = new \ReflectionProperty(MenuRenderService::class, 'cachedBackendUrlPrefix');
    $backend->setAccessible(true);
    $backend->setValue($service, 'https://example.test/backend/en_US');
    $method = new \ReflectionMethod(MenuRenderService::class, 'formatMenuUrlCached');
    $method->setAccessible(true);
    $url = (string) $method->invoke($service, ['is_backend' => true, 'route' => 'weline_product/backend/catalog/products']);
    $path = explode('?', $url, 2)[0];
    self::assertSame('https://example.test/backend/en_US/weline_product/backend/catalog/products', $path);
}

private function assert_MenuRenderServiceBackendUrlLocale_testFormatMenuUrlCachedStripsQueryFromPrefixBeforeRouteConcat(): void
{
    $service = new MenuRenderService($this->createStub(MenuAccessLog::class));
    $backend = new \ReflectionProperty(MenuRenderService::class, 'cachedBackendUrlPrefix');
    $backend->setAccessible(true);
    // Simulate getBackendUrl('/') after DEV auto-pierce polluted the compositional prefix.
    $backend->setValue(
        $service,
        'https://example.test/jRaxfEJaRUyO6ZBOA3wJX8bituje6oqH/?w_fpc_pierce=deadbeef'
    );
    $method = new \ReflectionMethod(MenuRenderService::class, 'formatMenuUrlCached');
    $method->setAccessible(true);
    $url = (string) $method->invoke($service, [
        'is_backend' => true,
        'route' => 'admin/system/full-page-cache',
    ]);
    $path = explode('?', $url, 2)[0];
    self::assertSame(
        'https://example.test/jRaxfEJaRUyO6ZBOA3wJX8bituje6oqH/admin/system/full-page-cache',
        $path,
        'route must be concatenated onto the path, never after pierce query'
    );
    self::assertStringNotContainsString('w_fpc_pierce=deadbeef/admin', $url);
}

private function assert_MenuRenderServiceCrossLocaleSearch_testBuildCrossLocaleSearchTextIncludesOtherLocales(): void
{
    $service = new MenuRenderService($this->createStub(MenuAccessLog::class));
    $prop = new \ReflectionProperty(MenuRenderService::class, 'moduleLocaleWords');
    $prop->setAccessible(true);
    $prop->setValue($service, ['Weline_Product|en_US' => ['Products' => 'Products', '商品' => 'Products'], 'Weline_Product|zh_Hans_CN' => ['Products' => '商品', '商品' => '商品']]);
    $localeProp = new \ReflectionProperty(MenuRenderService::class, 'activeLocaleCodes');
    $localeProp->setAccessible(true);
    $localeProp->setValue($service, ['zh_Hans_CN', 'en_US']);
    $text = $service->buildCrossLocaleSearchText('Products', 'Weline_Product::catalog');
    self::assertStringContainsString('Products', $text);
    self::assertStringContainsString('商品', $text);
}

private function assert_MenuRenderServiceCrossLocaleSearch_testRenderMenuNodeDoesNotEmbedDataSearchText(): void
{
    $service = new MenuRenderService($this->createStub(MenuAccessLog::class));
    $localeProp = new \ReflectionProperty(MenuRenderService::class, 'activeLocaleCodes');
    $localeProp->setAccessible(true);
    $localeProp->setValue($service, ['zh_Hans_CN', 'en_US']);
    $backend = new \ReflectionProperty(MenuRenderService::class, 'cachedBackendUrlPrefix');
    $backend->setAccessible(true);
    $backend->setValue($service, '/backend');
    $frontend = new \ReflectionProperty(MenuRenderService::class, 'cachedFrontendUrlPrefix');
    $frontend->setAccessible(true);
    $frontend->setValue($service, '/');
    $current = new \ReflectionProperty(MenuRenderService::class, 'cachedCurrentUrl');
    $current->setAccessible(true);
    $current->setValue($service, '/backend/dashboard');
    $method = new \ReflectionMethod(MenuRenderService::class, 'renderMenuNode');
    $method->setAccessible(true);
    $html = $method->invoke($service, ['type' => 'menus', 'source_id' => 'Weline_Product::catalog', 'source_name' => '商品', 'route' => 'product/backend/catalog/index', 'icon' => 'circle', 'is_enable' => 1, 'nodes' => []], true);
    self::assertStringNotContainsString('data-search-text=', $html);
    self::assertStringContainsString('data-source="Weline_Product::catalog"', $html);
}

private function assert_MenuRenderServiceCrossLocaleSearch_testRenderMenuSourceDoesNotPrefetchOnHotPath(): void
{
    $source = (string) file_get_contents((new \ReflectionClass(MenuRenderService::class))->getFileName() ?: '');
    self::assertStringContainsString('buildIndexSearchItems', $source);
    self::assertStringContainsString('交叉语种可搜词已迁至 Search DB 索引', $source);
    if (!preg_match('/public function renderMenu\(array \$menus\): string\s*\{(.*?)\n    public function /s', $source, $m)) {
        self::fail('无法截取 renderMenu 方法体');
    }
    self::assertStringNotContainsString('prefetchCrossLocaleMenuWords', $m[1]);
    self::assertStringContainsString('Parser::prefetchWords', $m[1]);
    self::assertStringContainsString('State::getLangLocal()', $m[1]);
    self::assertStringNotContainsString('data-search-text', $m[1]);
}

private function assert_MenuRenderServiceCrossLocaleSearch_testBuildIndexSearchItemsIncludesRouteAndSearchText(): void
{
    $service = new MenuRenderService($this->createStub(MenuAccessLog::class));
    $localeProp = new \ReflectionProperty(MenuRenderService::class, 'activeLocaleCodes');
    $localeProp->setAccessible(true);
    $localeProp->setValue($service, ['zh_Hans_CN', 'en_US']);
    $words = new \ReflectionProperty(MenuRenderService::class, 'moduleLocaleWords');
    $words->setAccessible(true);
    $words->setValue($service, ['Weline_Product|en_US' => ['商品' => 'Products'], 'Weline_Product|zh_Hans_CN' => ['商品' => '商品']]);
    $backend = new \ReflectionProperty(MenuRenderService::class, 'cachedBackendUrlPrefix');
    $backend->setAccessible(true);
    $backend->setValue($service, '/backend');
    $frontend = new \ReflectionProperty(MenuRenderService::class, 'cachedFrontendUrlPrefix');
    $frontend->setAccessible(true);
    $frontend->setValue($service, '/');
    $prefetch = new \ReflectionMethod(MenuRenderService::class, 'prefetchCrossLocaleMenuWords');
    $prefetch->setAccessible(true);
    $prefetch->invoke($service, ['商品']);
    $backend->setValue($service, '/backend');
    $frontend->setValue($service, '/');
    $walk = new \ReflectionMethod(MenuRenderService::class, 'walkNavigableMenuSearchItems');
    $walk->setAccessible(true);
    $items = [];
    $menus = [['type' => 'menus', 'source_id' => 'Weline_Product::catalog', 'source_name' => '商品', 'route' => 'product/backend/catalog/index', 'is_enable' => 1, 'nodes' => []]];
    $args = [$menus, &$items];
    $walk->invokeArgs($service, $args);
    self::assertCount(1, $items);
    self::assertSame('Weline_Product::catalog', $items[0]['source_id']);
    self::assertSame('product/backend/catalog/index', $items[0]['route']);
    self::assertStringContainsString('Products', $items[0]['search_text']);
}

private function tearDown_MenuRenderServiceRenderedMenuCache(): void
{
    MenuRenderService::clearProcessCache();
    parent::tearDown();
}

private function assert_MenuRenderServiceRenderedMenuCache_testClearProcessCacheEmptiesRenderedAndFrequentCaches(): void
{
    $rendered = new ReflectionProperty(MenuRenderService::class, 'renderedMenuCache');
    $frequent = new ReflectionProperty(MenuRenderService::class, 'frequentMenusCache');
    $rendered->setValue(null, ['a' => ['expires' => microtime(true) + 60, 'html' => '<li>a</li>']]);
    $frequent->setValue(null, ['1|20|7' => ['expires' => microtime(true) + 30, 'data' => ['recentMenus' => []]]]);
    MenuRenderService::clearProcessCache();
    self::assertSame([], $rendered->getValue());
    self::assertSame([], $frequent->getValue());
}

private function assert_MenuRenderServiceRenderedMenuCache_testRememberRenderedMenuRespectsMaxAndDropsExpired(): void
{
    $max = (new ReflectionClass(MenuRenderService::class))->getConstant('RENDERED_MENU_CACHE_MAX');
    self::assertIsInt($max);
    self::assertGreaterThan(0, $max);
    $service = new MenuRenderService($this->createStub(MenuAccessLog::class));
    $remember = new ReflectionMethod(MenuRenderService::class, 'rememberRenderedMenu');
    $remember->setAccessible(true);
    $rendered = new ReflectionProperty(MenuRenderService::class, 'renderedMenuCache');
    $now = microtime(true);
    $remember->invoke($service, 'expired', '<li>old</li>', $now - 120.0);
    for ($i = 0; $i < $max + 3; $i++) {
        $remember->invoke($service, 'k' . $i, '<li>' . $i . '</li>', $now);
    }
    $cache = $rendered->getValue();
    self::assertLessThanOrEqual($max, count($cache));
    self::assertArrayNotHasKey('expired', $cache);
}

private function assert_MenuRenderServiceRenderedMenuCache_testProcessCacheResetterClearsMenuRenderProcessCaches(): void
{
    $rendered = new ReflectionProperty(MenuRenderService::class, 'renderedMenuCache');
    $rendered->setValue(null, ['keep-until-reset' => ['expires' => microtime(true) + 60, 'html' => '<li>x</li>']]);
    $cleared = (new ProcessCacheResetter())->resetProcessCaches(new ProcessCacheResetContext(ProcessCacheResetContext::REASON_MEMORY_PRESSURE, false));
    self::assertGreaterThanOrEqual(2, $cleared);
    self::assertSame([], $rendered->getValue());
}

private function assert_MenuRenderServiceRenderedMenuCache_testCacheKeyUsesActiveFingerprintNotRawCurrentUrl(): void
{
    $src = (string) file_get_contents(BP . 'app/code/Weline/Admin/Service/MenuRenderService.php');
    self::assertStringContainsString('buildActiveMenuFingerprint', $src);
    self::assertStringContainsString('activeFingerprint', $src);
    self::assertStringContainsString('function clearProcessCache', $src);
    self::assertStringContainsString('RENDERED_MENU_CACHE_MAX', $src);
    self::assertStringNotContainsString("\$currentUrl,\n            md5(json_encode(\$menus", $src);
    $resetter = (string) file_get_contents(BP . 'app/code/Weline/Admin/Api/Runtime/ProcessCacheResetter.php');
    self::assertStringContainsString('MenuRenderService::clearProcessCache()', $resetter);
}

private function assert_MenuRenderServiceRenderedMenuCache_testActiveFingerprintStableAcrossUrlsHittingSameLeaf(): void
{
    $menus = [['type' => 'menus', 'source_id' => 'Weline_Demo::parent', 'source_name' => 'Parent', 'route' => '', 'is_backend' => true, 'nodes' => [['type' => 'menus', 'source_id' => 'Weline_Demo::leaf', 'source_name' => 'Leaf', 'route' => 'demo/backend/leaf/index', 'is_backend' => true, 'nodes' => []]]]];
    $service = new MenuRenderService($this->createStub(MenuAccessLog::class));
    $prefix = new ReflectionProperty(MenuRenderService::class, 'cachedBackendUrlPrefix');
    $frontend = new ReflectionProperty(MenuRenderService::class, 'cachedFrontendUrlPrefix');
    $current = new ReflectionProperty(MenuRenderService::class, 'cachedCurrentUrl');
    $urlActive = new ReflectionProperty(MenuRenderService::class, 'menuUrlActiveCache');
    $nodeActive = new ReflectionProperty(MenuRenderService::class, 'menuNodeActiveCache');
    $prefix->setAccessible(true);
    $frontend->setAccessible(true);
    $current->setAccessible(true);
    $urlActive->setAccessible(true);
    $nodeActive->setAccessible(true);
    $backendKey = trim((string) (\Weline\Framework\App\Env::getAreaRoutePrefix('backend') ?? 'backend'), '/');
    $prefix->setValue($service, $backendKey === '' ? '' : '/' . $backendKey);
    $frontend->setValue($service, '/');
    $build = new ReflectionMethod(MenuRenderService::class, 'buildActiveMenuFingerprint');
    $build->setAccessible(true);
    $urlActive->setValue($service, []);
    $nodeActive->setValue($service, []);
    $current->setValue($service, 'demo/backend/leaf/index');
    $fpExact = $build->invoke($service, $menus);
    $urlActive->setValue($service, []);
    $nodeActive->setValue($service, []);
    $current->setValue($service, 'demo/backend/leaf');
    $fpIndexOmitted = $build->invoke($service, $menus);
    $urlActive->setValue($service, []);
    $nodeActive->setValue($service, []);
    $current->setValue($service, 'demo/backend/other/index');
    $fpOther = $build->invoke($service, $menus);
    self::assertNotSame('none', $fpExact);
    self::assertSame($fpExact, $fpIndexOmitted);
    self::assertSame('none', $fpOther);
}
}
