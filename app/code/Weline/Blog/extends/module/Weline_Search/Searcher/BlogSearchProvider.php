<?php

declare(strict_types=1);

namespace Weline\Blog\Extends\Module\Weline_Search\Searcher;

use Weline\Blog\Service\BlogSearchCategoryScopeService;
use Weline\Blog\Service\BlogSearchIndexDocumentBuilder;
use Weline\Search\Api\SearchScopeOptionsProviderInterface;
use Weline\Search\Dto\SearchRequest;
use Weline\Search\Dto\SearchResult;
use Weline\Search\Service\AbstractSearchProvider;
use Weline\Search\Service\SearchExpression;
use Weline\Search\Service\SearchProviderIndexService;

final class BlogSearchProvider extends AbstractSearchProvider implements SearchScopeOptionsProviderInterface
{
    public function __construct(
        private readonly BlogSearchIndexDocumentBuilder $indexBuilder,
        private readonly SearchProviderIndexService $indexService,
        private readonly BlogSearchCategoryScopeService $categoryScopes,
    ) {
    }

    public function code(): string
    {
        return 'blog';
    }

    public function label(): string
    {
        return (string)__('博客');
    }

    public function sortOrder(): int
    {
        return 20;
    }

    public function allowedClientParams(): array
    {
        return [
            // Align with Product: sidebar/breadcrumb share category_id when type=blog.
            'category_id' => ['type' => 'int', 'min' => 1],
            // Backward-compatible alias.
            'blog_category_id' => ['type' => 'int', 'min' => 1],
        ];
    }

    public function listScopeOptions(): array
    {
        return $this->categoryScopes->listForSearch();
    }

    public function hitTemplate(): string
    {
        return 'Weline_Blog::templates/frontend/search/hit.phtml';
    }

    public function expression(SearchRequest $request): SearchExpression
    {
        $expression = SearchExpression::of($request)->match(['title', 'excerpt', 'keywords', 'slug']);
        $categoryId = $this->resolveCategoryId($request);
        if ($categoryId > 0) {
            $expression->filter('category_id', $categoryId);
        }

        return $expression;
    }

    public function documentsForIndex(SearchRequest $request): array
    {
        return $this->indexBuilder->buildForRequest($request);
    }

    public function execute(SearchRequest $request, SearchExpression $expression): SearchResult
    {
        // 仅读 Provider 索引；禁止回退源表 SQL。索引由保存事件增量与 Cron 定时重建维护。
        return $this->indexService->search($request, $expression, $this);
    }

    private function resolveCategoryId(SearchRequest $request): int
    {
        if (isset($request->extras['category_id'])) {
            return max(0, (int)$request->extras['category_id']);
        }
        if (isset($request->extras['blog_category_id'])) {
            return max(0, (int)$request->extras['blog_category_id']);
        }

        return 0;
    }
}
