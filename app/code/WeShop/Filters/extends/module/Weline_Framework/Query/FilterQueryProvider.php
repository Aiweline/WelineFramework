<?php
declare(strict_types=1);

namespace WeShop\Filters\Extends\Module\Weline_Framework\Query;

use WeShop\Filters\Service\FilterCountService;
use WeShop\Filters\Service\FilterService;
use Weline\Framework\Service\Query\Provider\QueryProviderInterface;

class FilterQueryProvider implements QueryProviderInterface
{
    private const RESERVED_PARAMS = ['id', 'handle', 'q', 'page', 'page_size', 'limit', 'sort', 'order', 'category_id'];

    public function __construct(
        private readonly FilterService $filterService,
        private readonly FilterCountService $countService
    ) {
    }

    public function getProviderName(): string
    {
        return 'filter';
    }

    public function execute(string $operation, array $params = []): mixed
    {
        return match ($operation) {
            'filter' => $this->filter($params),
            'options' => $this->options($params),
            'counts' => $this->counts($params),
            default => throw new \InvalidArgumentException(
                (string)__('Unsupported filter provider operation: %{1}', $operation)
            ),
        };
    }

    private function filter(array $params): array
    {
        $categoryId = (int)($params['category_id'] ?? 0);
        if ($categoryId <= 0) {
            return $this->error((string)__('Invalid category ID.'));
        }

        $page = max(1, (int)($params['page'] ?? 1));
        $limit = min(100, max(1, (int)($params['page_size'] ?? $params['limit'] ?? 24)));
        $filters = $this->extractFilters($params);
        $browse = $this->browseProducts($filters, $page, $limit, $this->getBrowseCategoryIds($categoryId));
        $pagination = is_array($browse['pagination'] ?? null) ? $browse['pagination'] : [];

        return $this->success([
            'products' => is_array($browse['items'] ?? null) ? $browse['items'] : [],
            'filters' => is_array($browse['facets'] ?? null) ? $browse['facets'] : [],
            'applied_filters' => is_array($browse['applied_filters'] ?? null) ? $browse['applied_filters'] : [],
            'pagination' => [
                'total' => (int)($pagination['total'] ?? 0),
                'page' => (int)($pagination['page'] ?? $page),
                'limit' => $limit,
                'pages' => (int)($pagination['pages'] ?? 0),
            ],
            'clear_all_url' => (string)($browse['clear_all_url'] ?? ''),
        ]);
    }

    private function options(array $params): array
    {
        $categoryId = (int)($params['category_id'] ?? 0);
        $filterCode = trim((string)($params['filter_code'] ?? ''));
        if ($categoryId <= 0 || $filterCode === '') {
            return $this->error((string)__('Missing required filter parameters.'));
        }

        return $this->success([
            'filter_code' => $filterCode,
            'options' => $this->filterService->getFilterOptions(
                $filterCode,
                $categoryId,
                $this->getCategoryProductIds($categoryId),
                $this->extractFilters($params)
            ),
        ]);
    }

    private function counts(array $params): array
    {
        $categoryId = (int)($params['category_id'] ?? 0);
        if ($categoryId <= 0) {
            return $this->error((string)__('Missing required filter parameters.'));
        }

        $filterParams = $this->extractFilters($params);
        $filterCodes = (string)($params['filter_codes'] ?? '');
        $codes = $filterCodes !== '' ? explode(',', $filterCodes) : [];
        $productIds = $this->getCategoryProductIds($categoryId);

        $counts = $codes === []
            ? $this->countService->getAllCounts($categoryId, $productIds, $filterParams)
            : $this->countService->getBatchCounts($codes, $categoryId, $productIds, $filterParams);

        return $this->success(['counts' => $counts]);
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function extractFilters(array $params): array
    {
        if (is_array($params['filters'] ?? null)) {
            return $params['filters'];
        }

        $filters = [];
        foreach ($params as $key => $value) {
            if (in_array((string)$key, self::RESERVED_PARAMS, true)) {
                continue;
            }
            if ($value === '' || $value === null) {
                continue;
            }
            $filters[(string)$key] = is_array($value) ? $value : explode(',', (string)$value);
        }

        return $filters;
    }

    /**
     * @return array<int, int>
     */
    private function getBrowseCategoryIds(int $categoryId): array
    {
        $categoryIds = w_query('catalog', 'getAllDescendantCategoryIds', ['category_id' => $categoryId]);
        $categoryIds = is_array($categoryIds) ? array_values(array_unique(array_filter(array_map('intval', $categoryIds)))) : [];
        if (!in_array($categoryId, $categoryIds, true)) {
            $categoryIds[] = $categoryId;
        }

        return $categoryIds;
    }

    /**
     * @param array<string, mixed> $filters
     * @param array<int, int> $categoryIds
     * @return array<string, mixed>
     */
    private function browseProducts(array $filters, int $page, int $limit, array $categoryIds): array
    {
        $browse = w_query('search', 'browseProducts', [
            'keyword' => '',
            'filters' => $filters,
            'page' => $page,
            'page_size' => $limit,
            'category_ids' => $categoryIds,
            'include_facets' => true,
        ]);

        return is_array($browse) ? $browse : [];
    }

    /**
     * @return array<int, int>
     */
    private function getCategoryProductIds(int $categoryId): array
    {
        $productIds = w_query('product', 'getProductIdsByCategoryId', ['category_id' => $categoryId]);

        return is_array($productIds) ? array_values(array_map('intval', $productIds)) : [];
    }

    private function success(array $data): array
    {
        return ['success' => true, 'data' => $data];
    }

    private function error(string $message): array
    {
        return ['success' => false, 'message' => $message];
    }

    public function getDescriptor(): array
    {
        return [
            'provider' => 'filter',
            'name' => __('Filter Query'),
            'description' => __('Provides frontend category filter operations through the worker API.'),
            'module' => 'WeShop_Filters',
            'operations' => [
                [
                    'name' => 'filter',
                    'description' => __('Apply category filters and return products, facets, and pagination.'),
                    'frontend' => true,
                    'mode' => 'read',
                    'graph' => true,
                    'cost' => 4,
                    'cache_ttl' => 5,
                    'params' => [
                        'category_id' => ['type' => 'int', 'required' => true, 'min' => 1],
                        'page' => ['type' => 'int', 'required' => false, 'min' => 1, 'max' => 1000],
                        'page_size' => ['type' => 'int', 'required' => false, 'min' => 1, 'max' => 100],
                        'filters' => ['type' => 'map', 'required' => false, 'max_keys' => 50],
                    ],
                    'returns' => ['type' => 'array'],
                    'summary' => 'Apply category filters',
                ],
                [
                    'name' => 'options',
                    'description' => __('Return available options for one filter.'),
                    'frontend' => true,
                    'mode' => 'read',
                    'graph' => true,
                    'cost' => 2,
                    'cache_ttl' => 10,
                    'params' => [
                        'category_id' => ['type' => 'int', 'required' => true, 'min' => 1],
                        'filter_code' => ['type' => 'string', 'required' => true, 'max_length' => 80],
                    ],
                    'returns' => ['type' => 'array'],
                    'summary' => 'Filter options',
                ],
                [
                    'name' => 'counts',
                    'description' => __('Return filter option counts.'),
                    'frontend' => true,
                    'mode' => 'read',
                    'graph' => true,
                    'cost' => 2,
                    'cache_ttl' => 10,
                    'params' => [
                        'category_id' => ['type' => 'int', 'required' => true, 'min' => 1],
                        'filter_codes' => ['type' => 'string', 'required' => false, 'max_length' => 500],
                    ],
                    'returns' => ['type' => 'array'],
                    'summary' => 'Filter counts',
                ],
            ],
        ];
    }
}
