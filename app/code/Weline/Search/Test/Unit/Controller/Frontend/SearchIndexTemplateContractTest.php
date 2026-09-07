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
        self::assertStringContainsString("setGet('theme_public_route', 'search')", $controller);
        self::assertStringContainsString('getParam(\'q\'', $controller);
        self::assertStringContainsString('SearchParamGuard', $controller);
        self::assertStringNotContainsString('website_id\' => $this->request', $controller);

        $guard = (string)file_get_contents(
            BP . 'app/code/Weline/Search/Service/SearchParamGuard.php',
        );
        self::assertStringContainsString('RequestContext::scopeMetadata()', $guard);
    }

    public function testSearchTemplateRendersQueryHitsAndLocaleAwareProductLinks(): void
    {
        $template = (string)file_get_contents(
            BP . 'app/code/Weline/Search/view/templates/frontend/index.phtml',
        );
        $controller = (string)file_get_contents(
            BP . 'app/code/Weline/Search/Controller/Frontend/Index.php',
        );

        self::assertStringContainsString('data-testid="storefront-search"', $template);
        self::assertStringContainsString('data-testid="storefront-search-empty"', $template);
        self::assertStringContainsString('storefront-search__results', $template);
        self::assertStringNotContainsString('search-layout__grid', $template);
        self::assertStringContainsString('search_hit_templates', $template);
        self::assertStringContainsString('hit-card.phtml', $template);
        self::assertStringContainsString('$this->fetch(', $template);
        self::assertStringContainsString("storefront-search__grid--' . \$resolved", $template);
        self::assertStringContainsString("\$resolved === 'product' || \$resolved === 'blog'", $template);
        self::assertStringNotContainsString("include __DIR__ . '/partials/hit-card.phtml'", $template);
        self::assertStringNotContainsString('website_id', $template);
        self::assertStringContainsString('search_hit_templates', $controller);
        self::assertStringContainsString('hitTemplateMap()', $controller);
        self::assertStringContainsString('listTypes(area: \'frontend\')', $controller);
        self::assertStringContainsString("if (\$q === '')", $controller);
        self::assertStringContainsString('skip provider fan-out', $controller);
    }
}
