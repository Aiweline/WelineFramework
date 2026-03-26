<?php

declare(strict_types=1);

namespace WeShop\Catalog\Controller\Frontend\Category;

use WeShop\Catalog\Service\CategoryService;
use WeShop\Frontend\Controller\BaseController;
use Weline\Framework\Event\EventsManager;
use Weline\Framework\Manager\MessageManager;
use Weline\Framework\Manager\ObjectManager;

class View extends BaseController
{
    protected ?string $layoutType = 'category';

    public function index(): string
    {
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

        $categoryIds = $this->getAllDescendantCategoryIds($categoryService, (int) $category->getId());
        if (!in_array((int) $category->getId(), $categoryIds, true)) {
            $categoryIds[] = (int) $category->getId();
        }

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
        $breadcrumbs = [];
        $parentId = (int) ($categoryData['parent_id'] ?? 0);
        $pathSegments = [];

        while ($parentId > 0) {
            $parentCategory = $categoryService->getCategory($parentId);
            if (!$parentCategory || !$parentCategory->getId()) {
                break;
            }

            $handleValue = trim((string) ($parentCategory->getData(\WeShop\Catalog\Model\Category::schema_fields_HANDLE) ?? ''), '/');
            if ($handleValue !== '') {
                $pathSegments[] = $handleValue;
            }

            array_unshift($breadcrumbs, [
                'category_id' => $parentCategory->getId(),
                'name' => $parentCategory->getData(\WeShop\Catalog\Model\Category::schema_fields_NAME) ?? '',
                'handle' => $handleValue,
                'path' => implode('/', $pathSegments),
            ]);
            $parentId = (int) ($parentCategory->getData(\WeShop\Catalog\Model\Category::schema_fields_PARENT_ID) ?? 0);
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
            if (in_array($key, ['id', 'handle', 'page', 'page_size'], true)) {
                continue;
            }

            if ($value === null || $value === '') {
                continue;
            }

            $filters[$key] = $value;
        }

        return $filters;
    }

    private function getAllDescendantCategoryIds(CategoryService $categoryService, int $parentId): array
    {
        $ids = [$parentId];
        foreach ($categoryService->getChildCategories($parentId) as $child) {
            $childId = (int) ($child['category_id'] ?? 0);
            if ($childId <= 0) {
                continue;
            }

            $ids = array_merge($ids, $this->getAllDescendantCategoryIds($categoryService, $childId));
        }

        return array_values(array_unique($ids));
    }
}
