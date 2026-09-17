<?php

declare(strict_types=1);

namespace Weline\Admin\Test\Unit\Service;

\defined('BP') || \define('BP', \dirname(__DIR__, 7) . \DIRECTORY_SEPARATOR);
require_once BP . 'app/bootstrap.php';

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Weline\Admin\Model\MenuAccessLog;
use Weline\Admin\Service\MenuRenderService;

final class MenuRenderServiceCrossLocaleSearchContractTest extends TestCase
{
    public function testBuildCrossLocaleSearchTextIncludesSourceAndLocaleTranslations(): void
    {
        $service = new MenuRenderService($this->createStub(MenuAccessLog::class));

        $prop = new \ReflectionProperty(MenuRenderService::class, 'moduleLocaleWords');
        $prop->setAccessible(true);
        $prop->setValue($service, [
            'Weline_Product|zh_Hans_CN' => ['Products' => '商品'],
            'Weline_Product|en_US' => ['Products' => 'Products'],
            'Weline_Product|ja_JP' => ['Products' => '商品'],
        ]);

        $localeProp = new \ReflectionProperty(MenuRenderService::class, 'activeLocaleCodes');
        $localeProp->setAccessible(true);
        $localeProp->setValue($service, ['zh_Hans_CN', 'en_US', 'ja_JP']);

        $text = $service->buildCrossLocaleSearchText('Products', 'Weline_Product::catalog');
        $lower = mb_strtolower($text);

        self::assertStringContainsString('products', $lower);
        self::assertStringContainsString('商品', $text);
    }

    public function testBuildCrossLocaleSearchTextFallsBackToSourceWhenUntranslated(): void
    {
        $service = new MenuRenderService($this->createStub(MenuAccessLog::class));
        $localeProp = new \ReflectionProperty(MenuRenderService::class, 'activeLocaleCodes');
        $localeProp->setAccessible(true);
        $localeProp->setValue($service, ['en_US', 'zh_Hans_CN']);

        $prop = new \ReflectionProperty(MenuRenderService::class, 'moduleLocaleWords');
        $prop->setAccessible(true);
        $prop->setValue($service, [
            'Weline_Demo|en_US' => [],
            'Weline_Demo|zh_Hans_CN' => [],
        ]);

        $text = $service->buildCrossLocaleSearchText('UniqueMenuKeyXYZ', 'Weline_Demo::x');
        self::assertSame('UniqueMenuKeyXYZ', $text);
    }

    public function testRenderMenuNodeEmitsDataSearchText(): void
    {
        $service = new MenuRenderService($this->createStub(MenuAccessLog::class));
        $localeProp = new \ReflectionProperty(MenuRenderService::class, 'activeLocaleCodes');
        $localeProp->setAccessible(true);
        $localeProp->setValue($service, ['zh_Hans_CN', 'en_US']);

        $words = new \ReflectionProperty(MenuRenderService::class, 'moduleLocaleWords');
        $words->setAccessible(true);
        $words->setValue($service, [
            'Weline_Product|zh_Hans_CN' => ['Products' => '商品'],
            'Weline_Product|en_US' => ['Products' => 'Products'],
        ]);

        $backend = new \ReflectionProperty(MenuRenderService::class, 'cachedBackendUrlPrefix');
        $backend->setAccessible(true);
        $backend->setValue($service, '/backend');
        $frontend = new \ReflectionProperty(MenuRenderService::class, 'cachedFrontendUrlPrefix');
        $frontend->setAccessible(true);
        $frontend->setValue($service, '/');
        $current = new \ReflectionProperty(MenuRenderService::class, 'cachedCurrentUrl');
        $current->setAccessible(true);
        $current->setValue($service, '');

        $method = new ReflectionMethod(MenuRenderService::class, 'renderMenuNode');
        $method->setAccessible(true);
        $html = (string)$method->invoke($service, [
            'source_id' => 'Weline_Product::catalog',
            'source_name' => 'Products',
            'route' => 'product/backend/catalog/index',
            'icon' => 'circle',
            'is_enable' => 1,
            'type' => 'menus',
            'nodes' => [],
            'is_backend' => true,
        ], false);

        self::assertStringContainsString('data-search-text="', $html);
        self::assertMatchesRegularExpression('/data-search-text="[^"]*商品[^"]*"/u', $html);
        self::assertMatchesRegularExpression('/data-search-text="[^"]*Products[^"]*"/', $html);
    }

    public function testCollectNavigableMenuSearchItemsKeepsCrossLocaleText(): void
    {
        $service = new MenuRenderService($this->createStub(MenuAccessLog::class));
        $localeProp = new \ReflectionProperty(MenuRenderService::class, 'activeLocaleCodes');
        $localeProp->setAccessible(true);
        $localeProp->setValue($service, ['zh_Hans_CN', 'en_US']);
        $words = new \ReflectionProperty(MenuRenderService::class, 'moduleLocaleWords');
        $words->setAccessible(true);
        $words->setValue($service, [
            'Weline_Product|zh_Hans_CN' => ['Products' => '商品'],
            'Weline_Product|en_US' => ['Products' => 'Products'],
        ]);

        $items = $service->collectNavigableMenuSearchItems([
            [
                'source_id' => 'Weline_Product::catalog',
                'source_name' => 'Products',
                'route' => 'product/backend/catalog/index',
                'is_enable' => 1,
                'type' => 'menus',
                'nodes' => [],
                'is_backend' => true,
            ],
            [
                'source_id' => 'Weline_Product::group',
                'source_name' => 'Catalog',
                'route' => '',
                'is_enable' => 1,
                'type' => 'menus',
                'nodes' => [],
                'is_backend' => true,
            ],
        ]);

        self::assertCount(1, $items);
        self::assertSame('Weline_Product::catalog', $items[0]['source_id']);
        self::assertStringContainsString('商品', $items[0]['search_text']);
        self::assertStringContainsString('Products', $items[0]['search_text']);
    }
}
