<?php

declare(strict_types=1);

namespace WeShop\Catalog\Controller\Frontend\Category;

use WeShop\Catalog\Service\CategoryService;
use WeShop\Filters\Service\FilterService;
use WeShop\Filters\Service\FilterUrlService;
use WeShop\Frontend\Controller\BaseController;
use WeShop\Product\Model\Product;
use WeShop\Product\Model\ProductCategory;
use Weline\Framework\Event\EventsManager;
use Weline\Framework\Manager\MessageManager;
use Weline\Framework\Manager\ObjectManager;

class View extends BaseController
{
    protected ?string $layoutType = 'category';

    public function index(): string
    {
        $this->request->addModule('WeShop_Catalog');
        $this->request->addModule('WeShop_Filters');

        /** @var CategoryService $categoryService */
        $categoryService = ObjectManager::getInstance(CategoryService::class);
        $categoriesCtx = $this->request->getData('categories') ?? null;
        $handle = $this->request->getParam('handle') ?? $this->request->getGet('handle');
        $categoryId = (int) ($this->request->getParam('id') ?? $this->request->getGet('id') ?? 0);
        $category = $handle ? $categoryService->getCategoryByHandle($handle) : null;
        if (!$category && $categoryId) {
            $category = $categoryService->getCategory($categoryId);
        }

        if (!$category || !$category->getId()) {
            MessageManager::error(__('Category not found.'));
            return $this->redirect('weshop') ?? '';
        }

        if ((int) ($category->getData(\WeShop\Catalog\Model\Category::schema_fields_IS_ACTIVE) ?? 0) !== 1) {
            MessageManager::error(__('Category is unavailable.'));
            return $this->redirect('weshop') ?? '';
        }

        $categoryData = [
            'category_id' => $category->getId(),
            'name' => $category->getData(\WeShop\Catalog\Model\Category::schema_fields_NAME) ?? '',
            'description' => $category->getData(\WeShop\Catalog\Model\Category::schema_fields_DESCRIPTION) ?? '',
            'handle' => $category->getData(\WeShop\Catalog\Model\Category::schema_fields_HANDLE) ?? '',
            'image' => $category->getData(\WeShop\Catalog\Model\Category::schema_fields_IMAGE) ?? '',
            'parent_id' => (int) ($category->getData(\WeShop\Catalog\Model\Category::schema_fields_PARENT_ID) ?? 0),
            'sort_order' => (int) ($category->getData(\WeShop\Catalog\Model\Category::schema_fields_SORT_ORDER) ?? 0),
        ];
        $categoryData['children'] = $categoryService->getChildCategories($category->getId());
        $categoryData['breadcrumbs'] = $this->buildBreadcrumbs($categoryService, $categoryData);

        if (!is_array($categoriesCtx) || empty($categoriesCtx['current']['category_id'])) {
            $this->hydrateCategoryContext($categoryData);
        }

        // Must match WeShop\Filters\Controller\Frontend\Ajax::getBrowseCategoryIds():
        // browse + /filters/filter use descendant category tree, not current node only.
        $categoryIds = $this->getBrowseCategoryIds((int) $category->getId());

        $filters = method_exists($this->request, 'getQuery') && is_array($this->request->getQuery())
            ? $this->collectBrowseFilters($this->request->getQuery())
            : [];

        $browse = w_query('search', 'browseProducts', [
            'keyword' => '',
            'filters' => $filters,
            'page' => max(1, (int) ($this->request->getParam('page') ?? 1)),
            'page_size' => max(1, (int) ($this->request->getParam('page_size') ?? 24)),
            'category_ids' => $categoryIds,
            'include_facets' => true,
        ]);
        $browse = is_array($browse) ? $browse : [];

        $products = is_array($browse['items'] ?? null) ? $browse['items'] : [];
        $appliedFilters = is_array($browse['applied_filters'] ?? null) ? $browse['applied_filters'] : [];
        $facetFilters = is_array($browse['facets'] ?? null) ? $browse['facets'] : [];
        if ($facetFilters === [] && $products !== []) {
            $facetFilters = $this->loadFacetFiltersViaFilterService(
                (int) $category->getId(),
                $categoryIds
            );
        }
        $clearAllUrl = (string) ($browse['clear_all_url'] ?? $this->getUrl('catalog/category/view', ['id' => $category->getId()]));
        $filteredProductIds = array_values(array_filter(array_map(
            static fn (array $item): int => (int) ($item['product_id'] ?? $item['entity_id'] ?? 0),
            array_filter($products, 'is_array')
        )));

        $this->assign('category', $categoryData);
        $this->assign('products', $products);
        $this->assign('filters', $facetFilters);
        $this->assign('applied_filters', $appliedFilters);
        $this->assign('clear_all_url', $clearAllUrl);
        $this->assign('category_id', $category->getId());
        $this->assign('filtered_product_ids', $filteredProductIds);
        $this->assign('pagination', (string) ($browse['pagination_html'] ?? ''));
        $this->assign('pagination_data', is_array($browse['pagination'] ?? null) ? $browse['pagination'] : []);

        $this->request->setData('category', $categoryData);
        $this->request->setData('products', $products);
        $this->request->setData('filters', $facetFilters);
        $this->request->setData('applied_filters', $appliedFilters);
        $this->request->setData('clear_all_url', $clearAllUrl);
        $this->request->setData('category_id', $category->getId());
        $this->request->setData('filtered_product_ids', $filteredProductIds);

        /** @var EventsManager $eventsManager */
        $eventsManager = ObjectManager::getInstance(EventsManager::class);
        $eventData = [
            'category_id' => $category->getId(),
            'product_ids' => $filteredProductIds,
        ];
        $eventPayload = ['data' => $eventData];
        $eventsManager->dispatch('WeShop_Catalog::category_load_after', $eventPayload);

        $this->assign('title', $categoryData['name']);
        $this->assign('meta_title', $category->getData('meta_title') ?? $categoryData['name']);
        $this->assign('meta_description', $category->getData('meta_description') ?? $categoryData['description']);
        $this->assign('meta_keywords', $category->getData('meta_keywords') ?? '');

        return $this->fetch('WeShop_Catalog::templates/Frontend/Category/content.phtml');
    }

    private function buildBreadcrumbs(CategoryService $categoryService, array $categoryData): array
    {
        $ancestors = [];
        $parentId = (int) ($categoryData['parent_id'] ?? 0);
        $visited = [];

        while ($parentId > 0) {
            if (isset($visited[$parentId])) {
                break;
            }
            $visited[$parentId] = true;

            $parentCategory = $categoryService->getCategory($parentId);
            if (!$parentCategory || !$parentCategory->getId()) {
                break;
            }

            $ancestors[] = [
                'category_id' => $parentCategory->getId(),
                'name' => $parentCategory->getData(\WeShop\Catalog\Model\Category::schema_fields_NAME) ?? '',
                'handle' => trim((string) ($parentCategory->getData(\WeShop\Catalog\Model\Category::schema_fields_HANDLE) ?? ''), '/'),
                'parent_id' => (int) ($parentCategory->getData(\WeShop\Catalog\Model\Category::schema_fields_PARENT_ID) ?? 0),
            ];
            $parentId = (int) ($parentCategory->getData(\WeShop\Catalog\Model\Category::schema_fields_PARENT_ID) ?? 0);
        }

        $breadcrumbs = [];
        $pathSegments = [];
        foreach (array_reverse($ancestors) as $ancestor) {
            $handle = (string) ($ancestor['handle'] ?? '');
            if ($handle !== '') {
                $pathSegments[] = $handle;
            }

            $breadcrumbs[] = [
                'category_id' => (int) ($ancestor['category_id'] ?? 0),
                'name' => (string) ($ancestor['name'] ?? ''),
                'handle' => $handle,
                'path' => implode('/', $pathSegments),
            ];
        }

        return $breadcrumbs;
    }

    private function hydrateCategoryContext(array $categoryData): void
    {
        $pathSegments = [];
        foreach (($categoryData['breadcrumbs'] ?? []) as $breadcrumb) {
            $handle = trim((string) ($breadcrumb['handle'] ?? ''), '/');
            if ($handle !== '') {
                $pathSegments[] = $handle;
            }
        }

        $currentHandle = trim((string) ($categoryData['handle'] ?? ''), '/');
        if ($currentHandle !== '') {
            $pathSegments[] = $currentHandle;
        }

        $this->request->setData('categories', [
            'current' => [
                'category_id' => $categoryData['category_id'],
                'name' => $categoryData['name'],
                'handle' => $currentHandle,
                'path' => implode('/', $pathSegments),
                'description' => $categoryData['description'],
                'image' => $categoryData['image'],
                'parent_id' => $categoryData['parent_id'],
                'sort_order' => $categoryData['sort_order'],
                'breadcrumbs' => $categoryData['breadcrumbs'],
            ],
            'breadcrumbs' => $categoryData['breadcrumbs'],
            'path' => implode('/', $pathSegments),
        ]);
    }

    /**
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    private function collectBrowseFilters(array $query): array
    {
        $filters = [];
        foreach ($query as $key => $value) {
            if (in_array($key, ['id', 'handle', 'page', 'page_size', 'limit', 'sort', 'order', 'q'], true)) {
                continue;
            }

            if ($value === null || $value === '') {
                continue;
            }

            if (is_string($value) && str_contains($value, ',')) {
                $parsed = array_values(array_filter(array_map('trim', explode(',', $value)), static fn (string $item): bool => $item !== ''));
                if ($parsed === []) {
                    continue;
                }
                $filters[$key] = $parsed;
                continue;
            }

            if (is_array($value)) {
                $parsed = array_values(array_filter(array_map(static fn (mixed $item): string => trim((string) $item), $value), static fn (string $item): bool => $item !== ''));
                if ($parsed === []) {
                    continue;
                }
                $filters[$key] = $parsed;
                continue;
            }

            $filters[$key] = $value;
        }

        return $filters;
    }

    /**
     * @return array<int, int>
     */
    private function getBrowseCategoryIds(int $categoryId): array
    {
        $categoryIds = w_query('catalog', 'getAllDescendantCategoryIds', ['category_id' => $categoryId]);
        $categoryIds = is_array($categoryIds)
            ? array_values(array_unique(array_filter(array_map('intval', $categoryIds))))
            : [];
        if (!in_array($categoryId, $categoryIds, true)) {
            $categoryIds[] = $categoryId;
        }

        return $categoryIds;
    }

    /**
     * When search browse returns no facets but the category has visible products, build the same
     * filter dimensions as the Filters sidebar (Motor / canonical filters.phtml rely on this shape).
     *
     * @param array<int, int> $categoryIds
     * @return array<int, mixed>
     */
    private function loadFacetFiltersViaFilterService(int $categoryId, array $categoryIds): array
    {
        if ($categoryId <= 0 || $categoryIds === []) {
            return [];
        }

        try {
            /** @var ProductCategory $productCategory */
            $productCategory = ObjectManager::getInstance(ProductCategory::class);
            $productCategory->reset()
                ->fields('main_table.' . ProductCategory::schema_fields_product_id)
                ->where('main_table.' . ProductCategory::schema_fields_category_id, $categoryIds, 'in')
                ->joinProduct()
                ->where('product.' . Product::schema_fields_status, 1)
                ->groupBy('main_table.' . ProductCategory::schema_fields_product_id);

            $results = $productCategory->select()->fetchArray();
            $productIds = array_values(array_filter(array_map(
                static fn (array $row): int => (int) ($row[ProductCategory::schema_fields_product_id] ?? 0),
                is_array($results) ? $results : []
            )));

            if ($productIds === []) {
                return [];
            }

            /** @var FilterUrlService $urlService */
            $urlService = ObjectManager::getInstance(FilterUrlService::class);
            /** @var FilterService $filterService */
            $filterService = ObjectManager::getInstance(FilterService::class);
            $filterResult = $filterService->getFilterResult($categoryId, $productIds, $urlService->getFilterParams());

            return $filterResult->getFilters();
        } catch (\Throwable) {
            return [];
        }
    }
}
