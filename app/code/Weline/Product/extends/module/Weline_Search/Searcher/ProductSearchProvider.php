<?php

declare(strict_types=1);

namespace Weline\Product\Extends\Module\Weline_Search\Searcher;

use Weline\Product\Service\ProductSearchCategoryScopeService;
use Weline\Search\Api\SearchScopeOptionsProviderInterface;
use Weline\Search\Dto\SearchRequest;
use Weline\Search\Dto\SearchResult;
use Weline\Search\Service\AbstractSearchProvider;
use Weline\Search\Service\SearchExpression;
use Weline\Search\Service\SearchQueryService;

/**
 * Product search provider — keeps P3C projection/direct path inside Product→Search dependency.
 */
final class ProductSearchProvider extends AbstractSearchProvider implements SearchScopeOptionsProviderInterface
{
    public function __construct(
        private readonly SearchQueryService $legacySearch,
        private readonly ProductSearchCategoryScopeService $categoryScopes,
    ) {
    }

    public function code(): string
    {
        return 'product';
    }

    public function label(): string
    {
        return (string)__('商品');
    }

    public function sortOrder(): int
    {
        return 10;
    }

    public function allowedClientParams(): array
    {
        return [
            'category_id' => ['type' => 'int', 'min' => 1],
        ];
    }

    public function listScopeOptions(): array
    {
        return $this->categoryScopes->listForSearch();
    }

    public function expression(SearchRequest $request): SearchExpression
    {
        $expression = SearchExpression::of($request)->match(['title', 'sku']);
        if (isset($request->extras['category_id'])) {
            $expression->filter('category_id', (int)$request->extras['category_id']);
        }

        return $expression;
    }

    public function execute(SearchRequest $request, SearchExpression $expression): SearchResult
    {
        $legacy = $this->legacySearch->search([
            'website_id' => $request->websiteId,
            'store_id' => $request->storeId,
            'channel_id' => $request->channelId,
            'locale' => $request->locale,
            'currency' => $request->currency,
            'q' => $request->q,
        ]);

        $rows = is_array($legacy['hits'] ?? null) ? $legacy['hits'] : [];
        if (isset($request->extras['category_id'])) {
            $categoryId = (int)$request->extras['category_id'];
            $rows = array_values(array_filter(
                $rows,
                static function (array $row) use ($categoryId): bool {
                    $rowCategory = (int)($row['category_id'] ?? $row['payload']['category_id'] ?? 0);
                    return $rowCategory === 0 || $rowCategory === $categoryId;
                },
            ));
        }

        $hits = $this->mapLegacyHits($rows, $this->code());
        $offset = $expression->getOffset();
        $limit = $expression->getLimit();
        $pageHits = array_slice($hits, $offset, $limit);

        return new SearchResult(
            ok: (bool)($legacy['ok'] ?? $legacy['success'] ?? true),
            type: $this->code(),
            hits: $pageHits,
            hitCount: count($hits),
            meta: [
                'source' => (string)($legacy['source'] ?? ''),
                'degraded' => (bool)($legacy['degraded'] ?? false),
            ],
            engine: 'mysql',
        );
    }

    public function hitTemplate(): string
    {
        return 'Weline_Search::templates/frontend/hits/product.phtml';
    }
}
