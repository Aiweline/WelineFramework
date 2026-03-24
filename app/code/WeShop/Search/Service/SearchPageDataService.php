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
            'pagination' => '',
            'engine' => 'mysql',
        ];

        if ($keyword !== '') {
            $result = $this->searchService->searchProducts($keyword, $filters, $page, $pageSize);
        }

        $total = (int) ($result['total'] ?? 0);

        return [
            'keyword' => $keyword,
            'has_keyword' => $keyword !== '',
            'products' => is_array($result['items'] ?? null) ? $result['items'] : [],
            'pagination' => (string) ($result['pagination'] ?? ''),
            'popular_keywords' => $this->searchService->getPopularKeywords(10),
            'filters' => $filters,
            'active_filters' => $this->buildActiveFilters($filters),
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
     * @param array<string, mixed> $filters
     * @return array<int, array{label:string,value:string}>
     */
    private function buildActiveFilters(array $filters): array
    {
        $activeFilters = [];

        if (!empty($filters['category_id'])) {
            $activeFilters[] = [
                'label' => (string) __('Category'),
                'value' => (string) $filters['category_id'],
            ];
        }

        if (($filters['price_min'] ?? null) !== null && $filters['price_min'] !== '') {
            $activeFilters[] = [
                'label' => (string) __('Min Price'),
                'value' => (string) $filters['price_min'],
            ];
        }

        if (($filters['price_max'] ?? null) !== null && $filters['price_max'] !== '') {
            $activeFilters[] = [
                'label' => (string) __('Max Price'),
                'value' => (string) $filters['price_max'],
            ];
        }

        if (!empty($filters['order_by'])) {
            $direction = strtoupper((string) ($filters['order_dir'] ?? 'DESC'));
            $activeFilters[] = [
                'label' => (string) __('Sort'),
                'value' => trim((string) $filters['order_by'] . ' ' . $direction),
            ];
        }

        return $activeFilters;
    }

    /**
     * @return array<string, int|string>
     */
    private function buildSummary(string $keyword, int $page, int $pageSize, int $total): array
    {
        if ($keyword === '' || $total <= 0) {
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
}
