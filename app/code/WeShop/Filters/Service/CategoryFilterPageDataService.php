<?php

declare(strict_types=1);

namespace WeShop\Filters\Service;

class CategoryFilterPageDataService
{
    public function __construct(
        private readonly FilterService $filterService,
        private readonly FilterUrlService $filterUrlService
    ) {
    }

    public function build(int $categoryId, array $productIds): array
    {
        $payload = [
            'category_id' => $categoryId,
            'filters' => [],
            'applied_filters' => [],
            'clear_all_url' => $this->filterUrlService->getClearAllUrl($categoryId),
            'filtered_product_ids' => [],
        ];

        if ($categoryId <= 0) {
            return $payload;
        }

        if (empty($productIds)) {
            return $payload;
        }

        $filterParams = $this->filterUrlService->getFilterParams();
        $filterResult = $this->filterService->getFilterResult($categoryId, $productIds, $filterParams);
        $filteredProductIds = $filterResult->getProductIds();

        $payload['filters'] = $filterResult->getFilters();
        $payload['applied_filters'] = $filterResult->getAppliedFilters();
        $payload['clear_all_url'] = $filterResult->getClearAllUrl();
        $payload['filtered_product_ids'] = array_values(is_array($filteredProductIds) ? $filteredProductIds : []);

        return $payload;
    }
}
