<?php

declare(strict_types=1);

namespace Weline\Faq\Extends\Module\Weline_Search\Searcher;

use Weline\Faq\Service\FaqSearchIndexDocumentBuilder;
use Weline\Search\Dto\SearchRequest;
use Weline\Search\Dto\SearchResult;
use Weline\Search\Service\AbstractSearchProvider;
use Weline\Search\Service\SearchExpression;
use Weline\Search\Service\SearchProviderIndexService;

final class FaqSearchProvider extends AbstractSearchProvider
{
    public function __construct(
        private readonly FaqSearchIndexDocumentBuilder $indexBuilder,
        private readonly SearchProviderIndexService $indexService,
    ) {
    }

    public function code(): string
    {
        return 'faq';
    }

    public function label(): string
    {
        // Stable Chinese source — storefront WidgetI18n / type-filter translate at render.
        // Prefer 常问问题 over bare FAQ: el_GR+ packs translate the Chinese key; FAQ often sticks English.
        return '常问问题';
    }

    public function sortOrder(): int
    {
        return 35;
    }

    public function allowedClientParams(): array
    {
        return [];
    }

    public function expression(SearchRequest $request): SearchExpression
    {
        return SearchExpression::of($request)->match(['title', 'keywords', 'question', 'answer']);
    }

    public function documentsForIndex(SearchRequest $request): array
    {
        return $this->indexBuilder->buildForRequest($request);
    }

    public function execute(SearchRequest $request, SearchExpression $expression): SearchResult
    {
        return $this->indexService->search($request, $expression, $this);
    }
}
