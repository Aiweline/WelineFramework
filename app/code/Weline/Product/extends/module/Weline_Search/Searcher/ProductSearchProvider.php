<?php

declare(strict_types=1);

namespace Weline\Product\Extends\Module\Weline_Search\Searcher;

use Weline\Product\Service\ProductSearchCategoryScopeService;
use Weline\Product\Service\ProductSearchHitPresenter;
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
        private readonly ProductSearchHitPresenter $hitPresenter,
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
        $expression = SearchExpression::of($request)->match(['title', 'sku', 'keywords']);
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

        foreach ($rows as $index => $row) {
            if (!\is_array($row)) {
                continue;
            }
            $rows[$index]['title'] = $this->resolveDisplayTitle($row, $request->locale);
        }

        $rows = $this->hitPresenter->prepareRows($rows);

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
        return 'Weline_Product::templates/frontend/search/hit.phtml';
    }

    /**
     * @param array<string,mixed> $row
     */
    private function resolveDisplayTitle(array $row, string $locale): string
    {
        $localized = $row['localized_titles'] ?? null;
        if (\is_array($localized)) {
            $locale = \trim($locale);
            if ($locale !== '' && isset($localized[$locale])) {
                $localizedTitle = \trim((string)$localized[$locale]);
                if ($localizedTitle !== '') {
                    return $localizedTitle;
                }
            }
            if (isset($localized[''])) {
                $neutralTitle = \trim((string)$localized['']);
                if ($neutralTitle !== '') {
                    return $neutralTitle;
                }
            }
            foreach ($localized as $value) {
                $candidate = \trim((string)$value);
                if ($candidate !== '') {
                    return $candidate;
                }
            }
        }

        return \trim((string)($row['title'] ?? $row['name'] ?? ''));
    }
}
