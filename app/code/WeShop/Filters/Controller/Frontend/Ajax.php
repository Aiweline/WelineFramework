<?php

declare(strict_types=1);

namespace WeShop\Filters\Controller\Frontend;

use Weline\Framework\Controller\PcController;
use WeShop\Filters\Service\FilterService;
use WeShop\Filters\Service\FilterUrlService;
use WeShop\Filters\Service\FilterCountService;
use WeShop\Product\Model\Product;
use WeShop\Product\Model\ProductCategory;
use Weline\Framework\Manager\ObjectManager;

/**
 * 前台AJAX筛选控制器
 */
class Ajax extends PcController
{
    /**
     * @var FilterService
     */
    private FilterService $filterService;
    
    /**
     * @var FilterUrlService
     */
    private FilterUrlService $urlService;
    
    /**
     * @var FilterCountService
     */
    private FilterCountService $countService;
    
    public function __construct(
        FilterService $filterService,
        FilterUrlService $urlService,
        FilterCountService $countService
    ) {
        $this->filterService = $filterService;
        $this->urlService = $urlService;
        $this->countService = $countService;
    }
    
    /**
     * 获取筛选结果
     */
    public function filter(): string
    {
        $categoryId = (int)$this->request->getParam('category_id', 0);
        $page = max(1, (int)$this->request->getParam('page', 1));
        $limit = min(100, max(1, (int)$this->request->getParam('limit', 24)));
        
        if ($categoryId <= 0) {
            return $this->jsonError(__('无效的分类ID'));
        }
        
        // 获取分类的产品ID
        $productIds = $this->getCategoryProductIds($categoryId);
        
        if (empty($productIds)) {
            return $this->jsonSuccess([
                'products' => [],
                'filters' => [],
                'pagination' => [
                    'total' => 0,
                    'page' => $page,
                    'limit' => $limit,
                    'pages' => 0,
                ],
            ]);
        }
        
        // 获取筛选参数
        $filterParams = $this->urlService->getFilterParams();
        
        // 执行筛选
        $result = $this->filterService->getFilterResult($categoryId, $productIds, $filterParams);
        
        // 分页处理
        $filteredIds = $result->getProductIds();
        $total = count($filteredIds);
        $pages = ceil($total / $limit);
        $offset = ($page - 1) * $limit;
        
        $pageProductIds = array_slice($filteredIds, $offset, $limit);
        
        // 获取产品详情
        $products = $this->getProductDetails($pageProductIds);
        
        return $this->jsonSuccess([
            'products' => $products,
            'filters' => $result->getFilters(),
            'applied_filters' => $result->getAppliedFilters(),
            'pagination' => [
                'total' => $total,
                'page' => $page,
                'limit' => $limit,
                'pages' => $pages,
            ],
            'clear_all_url' => $result->getClearAllUrl(),
        ]);
    }
    
    /**
     * 获取筛选选项（用于异步加载）
     */
    public function options(): string
    {
        $categoryId = (int)$this->request->getParam('category_id', 0);
        $filterCode = $this->request->getParam('filter_code', '');
        
        if ($categoryId <= 0 || empty($filterCode)) {
            return $this->jsonError(__('参数无效'));
        }
        
        $productIds = $this->getCategoryProductIds($categoryId);
        $filterParams = $this->urlService->getFilterParams();
        
        $options = $this->filterService->getFilterOptions(
            $filterCode,
            $categoryId,
            $productIds,
            $filterParams
        );
        
        return $this->jsonSuccess([
            'filter_code' => $filterCode,
            'options' => $options,
        ]);
    }
    
    /**
     * 获取筛选计数（用于异步更新）
     */
    public function counts(): string
    {
        $categoryId = (int)$this->request->getParam('category_id', 0);
        $filterCodes = $this->request->getParam('filter_codes', '');
        
        if ($categoryId <= 0) {
            return $this->jsonError(__('参数无效'));
        }
        
        $productIds = $this->getCategoryProductIds($categoryId);
        $filterParams = $this->urlService->getFilterParams();
        
        $codes = !empty($filterCodes) ? explode(',', $filterCodes) : [];
        
        if (empty($codes)) {
            $counts = $this->countService->getAllCounts($categoryId, $productIds, $filterParams);
        } else {
            $counts = $this->countService->getBatchCounts($codes, $categoryId, $productIds, $filterParams);
        }
        
        return $this->jsonSuccess([
            'counts' => $counts,
        ]);
    }
    
    /**
     * 获取分类的产品ID
     */
    private function getCategoryProductIds(int $categoryId): array
    {
        // 获取当前分类及所有子分类的ID（通过 catalog 查询器）
        $categoryIds = w_query('catalog', 'getAllDescendantCategoryIds', ['category_id' => $categoryId]);
        
        /** @var ProductCategory $productCategory */
        $productCategory = ObjectManager::getInstance(ProductCategory::class);
        // 使用框架默认的表别名 main_table，避免 PostgreSQL SQL 语法问题
        $productCategory->reset()
            ->fields('main_table.' . ProductCategory::schema_fields_product_id)
            ->where('main_table.' . ProductCategory::schema_fields_category_id, $categoryIds, 'in')
            ->joinProduct()
            ->where('product.' . Product::schema_fields_status, 1)
            ->groupBy('main_table.' . ProductCategory::schema_fields_product_id);
        
        $results = $productCategory->select()->fetchArray();
        
        return array_column($results, ProductCategory::schema_fields_product_id);
    }
    
    /**
     * 获取产品详情
     */
    private function getProductDetails(array $productIds): array
    {
        if (empty($productIds)) {
            return [];
        }
        
        /** @var Product $productModel */
        $productModel = ObjectManager::getInstance(Product::class);
        $productModel->reset()
            ->where(Product::schema_fields_ID, $productIds, 'in')
            ->where(Product::schema_fields_status, 1);
        
        $results = $productModel->select()->fetchArray();
        
        $products = [];
        foreach ($results as $row) {
            $products[] = [
                'product_id' => $row[Product::schema_fields_ID],
                'name' => $row[Product::schema_fields_name] ?? '',
                'price' => (float)($row[Product::schema_fields_price] ?? 0),
                'image' => $row[Product::schema_fields_image] ?? '',
                'sku' => $row[Product::schema_fields_sku] ?? '',
                'handle' => $row[Product::schema_fields_HANDLE] ?? '',
                'stock' => (int)($row[Product::schema_fields_stock] ?? 0),
                'in_stock' => ((int)($row[Product::schema_fields_stock] ?? 0)) > 0,
            ];
        }
        
        return $products;
    }
    
    /**
     * 返回成功JSON
     */
    private function jsonSuccess(array $data): string
    {
        return json_encode([
            'success' => true,
            'data' => $data,
        ]);
    }
    
    /**
     * 返回错误JSON
     */
    private function jsonError(string $message): string
    {
        return json_encode([
            'success' => false,
            'message' => $message,
        ]);
    }
}
