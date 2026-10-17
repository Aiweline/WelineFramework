<?php

declare(strict_types=1);

namespace Weline\Blog\Test\Unit\Extends\Module\Weline_Search;

use PHPUnit\Framework\TestCase;


/**
 * Aggregated contract suite (merged from 1 *ContractTest files).
 * Observe one overall pass/fail via testAllBlogWeline_SearchContractsPass().
 */
final class BlogWelineSearchContractsTest extends TestCase
{
    public function testAllBlogWeline_SearchContractsPass(): void
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
            'BlogSearchProvider::testProviderPinsBlogSearchTemplate' => function () {
            $this->assert_BlogSearchProvider_testProviderPinsBlogSearchTemplate();
        },
            'BlogSearchProvider::testCategoryScopeServiceExposesCategoryIdParams' => function () {
            $this->assert_BlogSearchProvider_testCategoryScopeServiceExposesCategoryIdParams();
        },
        ];
    }

private function assert_BlogSearchProvider_testProviderPinsBlogSearchTemplate(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Blog' . '/extends/module/Weline_Search/Searcher/BlogSearchProvider.php';
    self::assertFileExists($path);
    $source = (string) file_get_contents($path);
    self::assertStringContainsString("return 'blog';", $source);
    self::assertStringContainsString("return '博客';", $source);
    self::assertStringNotContainsString("__('博客')", $source);
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
    self::assertStringContainsString('content_kind', (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Blog' . '/Service/BlogSearchHitPresenter.php'));
}

private function assert_BlogSearchProvider_testCategoryScopeServiceExposesCategoryIdParams(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Blog' . '/Service/BlogSearchCategoryScopeService.php';
    self::assertFileExists($path);
    $source = (string) file_get_contents($path);
    self::assertStringContainsString('listForSearch', $source);
    self::assertStringContainsString("'category_id'", $source);
    self::assertStringContainsString('demoScopes', $source);
}
}
