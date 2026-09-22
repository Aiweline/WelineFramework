<?php

declare(strict_types=1);

namespace Weline\Admin\Test\Unit\Service;

\defined('BP') || \define('BP', \dirname(__DIR__, 7) . \DIRECTORY_SEPARATOR);
require_once BP . 'app/bootstrap.php';

use PHPUnit\Framework\TestCase;
use Weline\Admin\Model\MenuAccessLog;
use Weline\Admin\Service\MenuRenderService;

final class MenuRenderServiceCrossLocaleSearchContractTest extends TestCase
{
    public function testBuildCrossLocaleSearchTextIncludesOtherLocales(): void
    {
        $service = new MenuRenderService($this->createStub(MenuAccessLog::class));
        $prop = new \ReflectionProperty(MenuRenderService::class, 'moduleLocaleWords');
        $prop->setAccessible(true);
        $prop->setValue($service, [
            'Weline_Product|en_US' => ['Products' => 'Products', '商品' => 'Products'],
            'Weline_Product|zh_Hans_CN' => ['Products' => '商品', '商品' => '商品'],
        ]);
        $localeProp = new \ReflectionProperty(MenuRenderService::class, 'activeLocaleCodes');
        $localeProp->setAccessible(true);
        $localeProp->setValue($service, ['zh_Hans_CN', 'en_US']);

        $text = $service->buildCrossLocaleSearchText('Products', 'Weline_Product::catalog');
        self::assertStringContainsString('Products', $text);
        self::assertStringContainsString('商品', $text);
    }

    public function testRenderMenuNodeDoesNotEmbedDataSearchText(): void
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
        $html = $method->invoke($service, [
            'type' => 'menus',
            'source_id' => 'Weline_Product::catalog',
            'source_name' => '商品',
            'route' => 'product/backend/catalog/index',
            'icon' => 'circle',
            'is_enable' => 1,
            'nodes' => [],
        ], true);

        self::assertStringNotContainsString('data-search-text=', $html);
        self::assertStringContainsString('data-source="Weline_Product::catalog"', $html);
    }

    public function testRenderMenuSourceDoesNotPrefetchOnHotPath(): void
    {
        $source = (string)file_get_contents((new \ReflectionClass(MenuRenderService::class))->getFileName() ?: '');
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

    public function testBuildIndexSearchItemsIncludesRouteAndSearchText(): void
    {
        $service = new MenuRenderService($this->createStub(MenuAccessLog::class));
        $localeProp = new \ReflectionProperty(MenuRenderService::class, 'activeLocaleCodes');
        $localeProp->setAccessible(true);
        $localeProp->setValue($service, ['zh_Hans_CN', 'en_US']);
        $words = new \ReflectionProperty(MenuRenderService::class, 'moduleLocaleWords');
        $words->setAccessible(true);
        $words->setValue($service, [
            'Weline_Product|en_US' => ['商品' => 'Products'],
            'Weline_Product|zh_Hans_CN' => ['商品' => '商品'],
        ]);
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
        $menus = [[
            'type' => 'menus',
            'source_id' => 'Weline_Product::catalog',
            'source_name' => '商品',
            'route' => 'product/backend/catalog/index',
            'is_enable' => 1,
            'nodes' => [],
        ]];
        $args = [$menus, &$items];
        $walk->invokeArgs($service, $args);

        self::assertCount(1, $items);
        self::assertSame('Weline_Product::catalog', $items[0]['source_id']);
        self::assertSame('product/backend/catalog/index', $items[0]['route']);
        self::assertStringContainsString('Products', $items[0]['search_text']);
    }
}
