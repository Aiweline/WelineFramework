<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit;

use Weline\Framework\Http\Request;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Test\TestCore;
use Weline\Theme\Controller\Router;

class RouterPreviewRewriteTest extends TestCore
{
    /**
     * Shared Request singleton keeps GET/params across tests; wipe preview keys.
     */
    private function resetPreviewRequestState(Request $request): void
    {
        foreach ([
            'preview_theme',
            'frontend_theme_id',
            'backend_theme_id',
            'page_type',
            'layout_type',
            'theme_public_route',
            'preview_mode',
            'status',
            'shell',
            'target_type',
            'target_value',
            'editor_area',
            'preview_area',
        ] as $key) {
            $request->setGet($key, null);
            unset($_GET[$key]);
        }
        $request->setData('params', []);
    }

    public function testLegacyPreviewRewriteSyncsExplicitThemeIntoRequest(): void
    {
        self::initRequest('/CNY/zh_Hans_CN/index/index');

        /** @var Request $request */
        $request = ObjectManager::getInstance(Request::class);
        $this->resetPreviewRequestState($request);
        $request->setGet('preview_theme', 11);
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
        $this->assertSame(11, (int)$request->getParam('frontend_theme_id', 0));
        $this->assertSame(11, (int)($request->getParams()['frontend_theme_id'] ?? 0));
        $this->assertSame('homepage', (string)$request->getParam('page_type', ''));
        $this->assertSame('index/index', (string)$request->getParam('theme_public_route', ''));
    }

    public function testDefaultThemePublicProductsRouteDefersToInstalledProductModule(): void
    {
        self::initRequest('/products');

        /** @var Request $request */
        $request = ObjectManager::getInstance(Request::class);
        $this->resetPreviewRequestState($request);
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

        /** @var Request $request */
        $request = ObjectManager::getInstance(Request::class);
        $this->resetPreviewRequestState($request);

        $path = 'product/17';
        $rule = [];

        Router::rewriteDefaultThemePublicPage($path, $rule);

        $this->assertSame('product/17', $path);
        $this->assertSame([], $rule);
    }

    public function testDefaultThemeSlugProductRouteDefersToInstalledProductModule(): void
    {
        self::initRequest('/product/benq-screenbar');

        /** @var Request $request */
        $request = ObjectManager::getInstance(Request::class);
        $this->resetPreviewRequestState($request);

        $path = 'product/benq-screenbar';
        $rule = [];

        Router::rewriteDefaultThemePublicPage($path, $rule);

        $this->assertSame('product/benq-screenbar', $path);
        $this->assertSame([], $rule);
    }

    public function testPreviewThemeQueryOnBareProductSlugRewritesToThemeGatewayAndKeepsPublicRoute(): void
    {
        self::initRequest('/product/benq-screenbar');

        /** @var Request $request */
        $request = ObjectManager::getInstance(Request::class);
        $this->resetPreviewRequestState($request);
        $request->setGet('preview_theme', 11);
        $request->setData('params', [
            'preview_theme' => 11,
        ]);

        $_GET['preview_theme'] = 11;
        $_SERVER['REQUEST_URI'] = '/product/benq-screenbar?preview_theme=11';

        $path = 'product/benq-screenbar';
        $rule = [];

        Router::rewritePreviewThemeQuery($path, $rule);

        $this->assertSame('theme/frontend/theme-preview/gateway', $path);
        $this->assertSame('product', (string)$request->getParam('page_type', ''));
        $this->assertSame('product/benq-screenbar', (string)$request->getParam('theme_public_route', ''));
        $this->assertSame(11, (int)$request->getParam('frontend_theme_id', 0));
    }

    public function testDefaultThemePublicSearchRouteDelegatesToInstalledSearchModule(): void
    {
        self::initRequest('/search');

        /** @var Request $request */
        $request = ObjectManager::getInstance(Request::class);
        $this->resetPreviewRequestState($request);
        $_SERVER['REQUEST_URI'] = '/search?q=R43-STORE-C1D7ADBA892E';

        $path = 'search';
        $rule = [];

        Router::rewriteDefaultThemePublicPage($path, $rule);

        $this->assertSame('search/frontend', $path);
        $this->assertSame('Weline_Search', $rule['module'] ?? null);
        $this->assertSame('', (string)$request->getParam('layout_type', ''));
    }

    public function testDefaultThemePublicCompareRouteDelegatesToInstalledCompareModule(): void
    {
        self::initRequest('/compare');

        /** @var Request $request */
        $request = ObjectManager::getInstance(Request::class);
        $this->resetPreviewRequestState($request);
        $_SERVER['REQUEST_URI'] = '/compare';

        $path = 'compare';
        $rule = [];

        Router::rewriteDefaultThemePublicPage($path, $rule);

        $this->assertSame('weline_compare/frontend', $path);
        $this->assertSame('Weline_Compare', $rule['module'] ?? null);
        $this->assertSame('', (string)$request->getParam('layout_type', ''));
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
