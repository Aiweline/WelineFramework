<?php

declare(strict_types=1);

namespace Weline\Search\Test\Unit\Controller\Frontend;

use PHPUnit\Framework\TestCase;

final class SearchIndexTemplateContractTest extends TestCase
{
    public function testSearchControllerSelectsTheSearchThemeLayout(): void
    {
        $controller = (string)file_get_contents(
            BP . 'app/code/Weline/Search/Controller/Frontend/Index.php',
        );

        self::assertStringContainsString("\$this->layoutType = 'search'", $controller);
        self::assertStringContainsString("setGet('page_type', 'search')", $controller);
        self::assertStringNotContainsString('theme_public_route', $controller);
        self::assertStringNotContainsString('getParam(\'q\'', $controller);
        self::assertStringNotContainsString('website_id\' => $this->request', $controller);
        // FPC shell: never SSR SearchHub hits; path-only (no request q bake).
        self::assertStringNotContainsString('SearchHubService', $controller);
        self::assertStringNotContainsString('hub->search', $controller);
        self::assertStringContainsString("'search_async'", $controller);
        self::assertStringContainsString("assign('search_query', '')", $controller);
        self::assertStringContainsString('Path-only FPC', $controller);
    }

    public function testSearchTemplateIsFpcShellWithAsyncResultsSlot(): void
    {
        $template = (string)file_get_contents(
            BP . 'app/code/Weline/Search/view/templates/frontend/index.phtml',
        );
        $controller = (string)file_get_contents(
            BP . 'app/code/Weline/Search/Controller/Frontend/Index.php',
        );
        $js = (string)file_get_contents(
            BP . 'app/code/Weline/Search/view/statics/js/storefront-search-results.js',
        );
        $modules = (string)file_get_contents(
            BP . 'app/code/Weline/Search/view/statics/frontend/weline.modules.js',
        );
        $provider = (string)file_get_contents(
            BP . 'app/code/Weline/Search/extends/module/Weline_Framework/Query/SearchQueryProvider.php',
        );
        $guard = (string)file_get_contents(
            BP . 'app/code/Weline/Search/Service/SearchParamGuard.php',
        );

        self::assertStringContainsString('data-testid="storefront-search"', $template);
        self::assertStringContainsString('data-weline-load="storefrontSearchResults"', $template);
        self::assertStringContainsString('data-search-async="1"', $template);
        self::assertStringContainsString('data-search-hydrate-url="1"', $template);
        self::assertStringContainsString('data-search-results-host', $template);
        self::assertStringContainsString('data-search-title', $template);
        self::assertStringContainsString("\$keepPh('搜索：%{1}')", $template);
        self::assertStringContainsString("\$keepPh('共 %{1} 条')", $template);
        self::assertStringNotContainsString('search-layout__grid', $template);
        self::assertStringContainsString('search_hit_templates', $controller);
        self::assertStringContainsString('hitTemplateMap()', $controller);
        self::assertStringContainsString('listTypes(area: \'frontend\')', $controller);
        self::assertStringContainsString('mode: \'page\'', $js);
        self::assertStringContainsString('readUrlParams', $js);
        self::assertStringContainsString('hydrateFromUrl', $js);
        self::assertStringContainsString('data-search-product-filters-host', $js);
        self::assertStringContainsString("params.type === 'product'", $js);
        self::assertStringContainsString('data-testid="storefront-search-loading"', $js);
        self::assertStringContainsString('storefrontSearchResults', $modules);
        self::assertStringContainsString("Weline_Search::js/storefront-search-results.js", $modules);
        self::assertStringContainsString("\$mode !== 'page'", $provider);
        self::assertStringContainsString("'mode'", $guard);
        // Frontend worker whitelist must declare mode (else Unknown frontend worker param: mode).
        self::assertMatchesRegularExpression(
            "/BinQueryParam\\(name:\\s*'mode'/",
            $provider,
        );
        self::assertStringContainsString("['name' => 'mode'", $provider);

        $hub = (string)file_get_contents(
            BP . 'app/code/Weline/Search/Service/SearchHubService.php',
        );
        self::assertStringContainsString('SchedulerSystem::yield()', $hub);
        self::assertStringContainsString('searchAll', $hub);
    }

    public function testSearchLayoutDefaultsChromeOnAndWebsiteBodyClass(): void
    {
        $layout = (string)file_get_contents(
            dirname(__DIR__, 4) . '/view/theme/frontend/layouts/search/default.phtml',
        );

        self::assertStringContainsString(
            "\$showHeader = \$meta['showHeader'] ?? \$this->getData('showHeader') ?? true",
            $layout,
        );
        self::assertStringContainsString(
            "\$showFooter = \$meta['showFooter'] ?? \$this->getData('showFooter') ?? true",
            $layout,
        );
        self::assertStringContainsString('<?php if ($showHeader): ?>', $layout);
        self::assertStringContainsString('<?php if ($showFooter): ?>', $layout);
        self::assertStringNotContainsString('<if condition="meta.showHeader">', $layout);
        self::assertStringContainsString("\$websiteCode . '-storefront'", $layout);
        self::assertStringContainsString('search-layout-root', $layout);
        // Path-only shell: never SSR product filter hook (JS mounts host when type=product).
        self::assertStringNotContainsString(
            'Weline_Search::frontend::search::filters::product',
            $layout,
        );
        self::assertStringContainsString('never SSR product filter hook', $layout);
    }
}
