<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit;

use Weline\Framework\Http\Request;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\UnitTest\TestCore;
use Weline\Theme\Controller\Router;

class RouterPreviewRewriteTest extends TestCore
{
    public function testLegacyPreviewRewriteSyncsExplicitThemeIntoRequest(): void
    {
        self::initRequest('/CNY/zh_Hans_CN/index/index');

        /** @var Request $request */
        $request = ObjectManager::getInstance(Request::class);
        $request->setGet('frontend_theme_id', 10);
        $request->setData('params', [
            'preview_theme' => 11,
            'frontend_theme_id' => 10,
        ]);

        $_GET['preview_theme'] = 11;
        $_SERVER['REQUEST_URI'] = '/CNY/zh_Hans_CN/index/index?preview_theme=11';

        $path = 'index/index';
        $rule = [];

        Router::rewritePreviewThemeQuery($path, $rule);

        $this->assertSame('theme/frontend/theme-preview/gateway', $path);
        $this->assertSame(11, (int)$_GET['frontend_theme_id']);
        $this->assertSame(11, (int)$request->getParam('frontend_theme_id', 0));
        $this->assertSame(11, (int)($request->getParams()['frontend_theme_id'] ?? 0));
        $this->assertSame('homepage', (string)$request->getParam('page_type', ''));
    }

    public function testDefaultThemePublicProductsRouteFallsBackToProductListLayout(): void
    {
        self::initRequest('/products');

        /** @var Request $request */
        $request = ObjectManager::getInstance(Request::class);
        $_SERVER['REQUEST_URI'] = '/products';

        $path = 'products';
        $rule = [];

        Router::rewriteDefaultThemePublicPage($path, $rule);

        $this->assertSame('theme/frontend/policy', $path);
        $this->assertSame('product_list', (string)$request->getParam('layout_type', ''));
        $this->assertSame('default', (string)$request->getParam('layout_option', ''));
        $this->assertSame('products', (string)$request->getParam('theme_public_route', ''));
    }
}
