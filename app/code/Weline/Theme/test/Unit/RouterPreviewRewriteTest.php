<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit;

use Weline\Framework\Http\Request;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Test\TestCore;
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

    public function testDefaultThemePublicProductsRouteDefersToInstalledProductModule(): void
    {
        self::initRequest('/products');

        /** @var Request $request */
        $request = ObjectManager::getInstance(Request::class);
        $_SERVER['REQUEST_URI'] = '/products';

        $path = 'products';
        $rule = [];

        Router::rewriteDefaultThemePublicPage($path, $rule);

        $this->assertSame('products', $path);
        $this->assertSame('', (string)$request->getParam('layout_type', ''));
        $this->assertSame([], $rule);
    }

    public function testDefaultThemeNumericProductRouteDefersToInstalledProductModule(): void
    {
        self::initRequest('/product/17');

        $path = 'product/17';
        $rule = [];

        Router::rewriteDefaultThemePublicPage($path, $rule);

        $this->assertSame('product/17', $path);
        $this->assertSame([], $rule);
    }

    public function testDefaultThemePublicPageDefersWhenCurrentWebsiteIsPageBuilderOwned(): void
    {
        $source = (string)\file_get_contents(BP . '/app/code/Weline/Theme/Controller/Router.php');

        $this->assertStringContainsString('isPageBuilderOwnedCurrentWebsite', $source);
        $this->assertStringContainsString('isInstalledModuleOwnedPublicRoute', $source);
        $this->assertStringContainsString('pagebuilder_ai_site', $source);
        $this->assertStringContainsString('page_builder', $source);
        $this->assertMatchesRegularExpression(
            '/rewriteDefaultThemePublicPage[\s\S]*isPageBuilderOwnedCurrentWebsite\(\)/',
            $source
        );
    }
}
