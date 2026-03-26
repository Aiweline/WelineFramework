<?php

declare(strict_types=1);

namespace WeShop\Search\Service;

class SearchPageDataService
{
    public function __construct(
        private readonly SearchService $searchService
    ) {
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function build(string $keyword, array $filters = [], int $page = 1, int $pageSize = 20): array
    {
        $keyword = trim($keyword);
        $page = max(1, $page);
        $pageSize = max(1, min(48, $pageSize));

        $result = [
            'items' => [],
            'total' => 0,
            'pagination' => [],
            'facets' => [],
            'applied_filters' => [],
            'clear_all_url' => '/search',
            'engine' => 'mysql',
        ];

        if ($keyword !== '' || $filters !== []) {
            $result = $this->searchService->browseProducts($keyword, $filters, $page, $pageSize, 'default', $this->normalizeCategoryIds($filters['category_id'] ?? []), true);
        }

        $total = (int) ($result['total'] ?? 0);
        $appliedFilters = is_array($result['applied_filters'] ?? null) ? $result['applied_filters'] : [];

        return [
            'keyword' => $keyword,
            'has_keyword' => $keyword !== '',
            'products' => is_array($result['items'] ?? null) ? $result['items'] : [],
            'pagination' => (string) ($result['pagination_html'] ?? ''),
            'pagination_data' => is_array($result['pagination'] ?? null) ? $result['pagination'] : [],
            'pagination_html' => (string) ($result['pagination_html'] ?? ''),
            'popular_keywords' => $this->searchService->getPopularKeywords(10),
            'filters' => is_array($result['facets'] ?? null) ? $result['facets'] : [],
            'active_filters' => $this->buildActiveFilters($appliedFilters, $filters),
            'applied_filters' => $appliedFilters,
            'clear_all_url' => (string) ($result['clear_all_url'] ?? '/search'),
            'total' => $total,
            'page' => $page,
            'page_size' => $pageSize,
            'engine' => (string) ($result['engine'] ?? 'mysql'),
            'search_summary' => $this->buildSummary($keyword, $page, $pageSize, $total),
            'search_url' => '/search',
            'suggest_url' => '/search/suggest',
        ];
    }

    /**
     * @return array<string, int|string>
     */
    private function buildSummary(string $keyword, int $page, int $pageSize, int $total): array
    {
        if ($total <= 0) {
            return [
                'label' => $keyword === ''
                    ? (string) __('Search for products, brands, and categories.')
                    : (string) __('No results found yet.'),
                'from' => 0,
                'to' => 0,
            ];
        }

        $from = (($page - 1) * $pageSize) + 1;
        $to = min($page * $pageSize, $total);

        return [
            'label' => (string) __('Showing %{1}-%{2} of %{3} results', [$from, $to, $total]),
            'from' => $from,
            'to' => $to,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $appliedFilters
     * @param array<string, mixed> $requestFilters
     * @return array<int, array<string, string>>
     */
    private function buildActiveFilters(array $appliedFilters, array $requestFilters): array
    {
        $activeFilters = [];

        foreach ($appliedFilters as $filter) {
            if (!is_array($filter)) {
                continue;
            }

            $activeFilters[] = [
                'label' => (string) ($filter['filter_name'] ?? $filter['label'] ?? ''),
                'value' => (string) ($filter['label'] ?? $filter['value'] ?? ''),
                'remove_url' => (string) ($filter['remove_url'] ?? ''),
            ];
        }

        $orderBy = trim((string) ($requestFilters['order_by'] ?? ''));
        if ($orderBy !== '') {
            $activeFilters[] = [
                'label' => (string) __('Sort'),
                'value' => trim($orderBy . ' ' . strtoupper((string) ($requestFilters['order_dir'] ?? 'desc'))),
                'remove_url' => '',
            ];
        }

        return $activeFilters;
    }

    /**
     * @return array<int, int>
     */
    private function normalizeCategoryIds(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        if (!is_array($value)) {
            $value = str_contains((string) $value, ',') ? explode(',', (string) $value) : [$value];
        }

        return array_values(array_unique(array_filter(array_map('intval', $value))));
    }
}
