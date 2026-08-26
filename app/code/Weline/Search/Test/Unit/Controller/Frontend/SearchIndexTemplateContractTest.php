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
        self::assertStringContainsString('RequestContext::scopeMetadata()', $controller);
        self::assertStringNotContainsString('website_id\' => $this->request', $controller);
    }

    public function testSearchTemplateRendersQueryHitsAndLocaleAwareProductLinks(): void
    {
        $template = (string)file_get_contents(
            BP . 'app/code/Weline/Search/view/templates/frontend/index.phtml',
        );

        self::assertStringContainsString('data-testid="storefront-search"', $template);
        self::assertStringContainsString('data-testid="storefront-search-empty"', $template);
        self::assertStringContainsString('storefront-search__results', $template);
        self::assertStringNotContainsString('search-layout__grid', $template);
        self::assertStringContainsString('$this->getUrl($productPath)', $template);
        self::assertStringNotContainsString('href="/product/', $template);
        self::assertStringNotContainsString('website_id', $template);
    }
}
