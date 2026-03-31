<?php

declare(strict_types=1);

namespace WeShop\Filters\Controller\Frontend;

use WeShop\Filters\Service\FilterCountService;
use WeShop\Filters\Service\FilterService;
use WeShop\Filters\Service\FilterUrlService;
use Weline\Framework\App\Controller\FrontendController;

class Ajax extends FrontendController
{
    public function __construct(
        private readonly FilterService $filterService,
        private readonly FilterUrlService $urlService,
        private readonly FilterCountService $countService
    ) {
    }

    protected function filter(): string
    {
        $categoryId = (int) $this->request->getParam('category_id', 0);
        $page = max(1, (int) $this->request->getParam('page', 1));
        $pageSize = (int) $this->request->getParam('page_size', 0);
        $limitParam = (int) $this->request->getParam('limit', 0);
        $resolved = $pageSize > 0 ? $pageSize : ($limitParam > 0 ? $limitParam : 24);
        $limit = min(100, max(1, $resolved));

        if ($categoryId <= 0) {
            return $this->jsonError((string) __('Invalid category ID.'));
        }

        $browse = $this->browseProducts(
            $this->urlService->getFilterParams(),
            $page,
            $limit,
            $this->getBrowseCategoryIds($categoryId)
        );
        $pagination = is_array($browse['pagination'] ?? null) ? $browse['pagination'] : [];

        return $this->jsonSuccess([
            'products' => is_array($browse['items'] ?? null) ? $browse['items'] : [],
            'filters' => is_array($browse['facets'] ?? null) ? $browse['facets'] : [],
            'applied_filters' => is_array($browse['applied_filters'] ?? null) ? $browse['applied_filters'] : [],
            'pagination' => [
                'total' => (int) ($pagination['total'] ?? 0),
                'page' => (int) ($pagination['page'] ?? $page),
                'limit' => $limit,
                'pages' => (int) ($pagination['pages'] ?? 0),
            ],
            'clear_all_url' => (string) ($browse['clear_all_url'] ?? ''),
        ]);
    }

    protected function options(): string
    {
        $categoryId = (int) $this->request->getParam('category_id', 0);
        $filterCode = (string) $this->request->getParam('filter_code', '');
        if ($categoryId <= 0 || $filterCode === '') {
            return $this->jsonError((string) __('Missing required filter parameters.'));
        }

        $options = $this->filterService->getFilterOptions(
            $filterCode,
            $categoryId,
            $this->getCategoryProductIds($categoryId),
            $this->urlService->getFilterParams()
        );

        return $this->jsonSuccess([
            'filter_code' => $filterCode,
            'options' => $options,
        ]);
    }

    protected function counts(): string
    {
        $categoryId = (int) $this->request->getParam('category_id', 0);
        if ($categoryId <= 0) {
            return $this->jsonError((string) __('Missing required filter parameters.'));
        }

        $filterParams = $this->urlService->getFilterParams();
        $filterCodes = (string) $this->request->getParam('filter_codes', '');
        $codes = $filterCodes !== '' ? explode(',', $filterCodes) : [];
        $productIds = $this->getCategoryProductIds($categoryId);

        $counts = $codes === []
            ? $this->countService->getAllCounts($categoryId, $productIds, $filterParams)
            : $this->countService->getBatchCounts($codes, $categoryId, $productIds, $filterParams);

        return $this->jsonSuccess(['counts' => $counts]);
    }

    /**
     * @return array<int, int>
     */
    protected function getBrowseCategoryIds(int $categoryId): array
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
    protected function browseProducts(array $filters, int $page, int $limit, array $categoryIds): array
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
    protected function getCategoryProductIds(int $categoryId): array
    {
        $productIds = w_query('product', 'getProductIdsByCategoryId', ['category_id' => $categoryId]);

        return is_array($productIds) ? array_values(array_map('intval', $productIds)) : [];
    }

    private function jsonSuccess(array $data): string
    {
        return json_encode(['success' => true, 'data' => $data]);
    }

    private function jsonError(string $message): string
    {
        return json_encode(['success' => false, 'message' => $message]);
    }
}
