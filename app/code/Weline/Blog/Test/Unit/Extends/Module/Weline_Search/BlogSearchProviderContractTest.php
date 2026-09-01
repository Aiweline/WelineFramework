<?php

declare(strict_types=1);

namespace Weline\Blog\Test\Unit\Extends\Module\Weline_Search;

use PHPUnit\Framework\TestCase;

final class BlogSearchProviderContractTest extends TestCase
{
    public function testProviderPinsBlogSearchTemplate(): void
    {
        $path = dirname(__DIR__, 5) . '/extends/module/Weline_Search/Searcher/BlogSearchProvider.php';
        self::assertFileExists($path);
        $source = (string)file_get_contents($path);
        self::assertStringContainsString("return 'blog';", $source);
        self::assertStringContainsString('Weline_Blog::templates/frontend/search/hit.phtml', $source);
        self::assertStringContainsString('SearchProviderIndexService', $source);
        self::assertStringContainsString('documentsForIndex', $source);
        self::assertStringContainsString('indexService->search', $source);
        self::assertStringContainsString('SearchScopeOptionsProviderInterface', $source);
        self::assertStringContainsString('listScopeOptions', $source);
        self::assertStringContainsString('BlogSearchCategoryScopeService', $source);
        self::assertStringContainsString("'category_id'", $source);
        self::assertStringNotContainsString('executeDirect', $source);
        self::assertStringNotContainsString('blog_projection', $source);
        self::assertStringNotContainsString('BlogSearchQueryService', $source);
        self::assertStringContainsString('content_kind', (string)file_get_contents(dirname(__DIR__, 5) . '/Service/BlogSearchHitPresenter.php'));
    }

    public function testCategoryScopeServiceExposesCategoryIdParams(): void
    {
        $path = dirname(__DIR__, 5) . '/Service/BlogSearchCategoryScopeService.php';
        self::assertFileExists($path);
        $source = (string)file_get_contents($path);
        self::assertStringContainsString('listForSearch', $source);
        self::assertStringContainsString("'category_id'", $source);
        self::assertStringContainsString('demoScopes', $source);
    }
}
