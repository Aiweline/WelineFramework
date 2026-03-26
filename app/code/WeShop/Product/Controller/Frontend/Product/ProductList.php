<?php

declare(strict_types=1);

namespace WeShop\Product\Controller\Frontend\Product;

use WeShop\Frontend\Controller\BaseController;
use WeShop\Product\Service\ProductService;
use WeShop\Catalog\Service\CategoryService;

/**
 * 产品列表页控制器
 * 
 * 支持6种布局变体：
 * - product_listing_page_1
 * - product_listing_page_2
 * - product_listing_page_3
 * - product_listing_page_4
 * - product_listing_page_5
 * - product_listing_page_6
 * 
 * 布局变体通过主题配置设置：layouts.product_list = product_listing_page_1
 */
class ProductList extends BaseController
{
    private const CONTENT_TEMPLATE = 'WeShop_Product::templates/frontend/product/list/index.phtml';

    /**
     * 布局类型
     * Theme模块会根据此类型从主题配置中加载对应的布局
     */
    protected ?string $layoutType = 'product_list';

    public function __construct(
        private readonly ProductService $productService,
        private readonly CategoryService $categoryService
    ) {
    }
    
    /**
     * 产品列表页
     */
    public function index(): string
    {
        $request = $this->getRequest();
        
        // 获取请求参数
        $page = (int)($request->getParam('page') ?? 1);
        $pageSize = (int)($request->getParam('page_size') ?? 20);
        $categoryId = (int)($request->getParam('category_id') ?? 0);
        $search = trim((string)($request->getParam('q') ?? $request->getParam('search') ?? ''));
        
        // 筛选条件
        $filters = [];
        
        // 分类筛选
        if ($categoryId > 0) {
            $filters['category_id'] = $categoryId;
        }
        
        // 搜索关键词
        if (!empty($search)) {
            $filters['name'] = $search;
        }
        
        // 价格筛选
        $minPrice = $request->getParam('min_price');
        $maxPrice = $request->getParam('max_price');
        if ($minPrice !== null && is_numeric($minPrice)) {
            $filters['min_price'] = (float)$minPrice;
        }
        if ($maxPrice !== null && is_numeric($maxPrice)) {
            $filters['max_price'] = (float)$maxPrice;
        }
        
        // 排序
        $orderBy = $request->getParam('order_by') ?? 'product_id';
        $orderDir = strtoupper($request->getParam('order_dir') ?? 'DESC');
        
        // 验证排序字段
        $allowedOrderFields = ['product_id', 'name', 'price', 'created_at', 'stock'];
        if (!in_array($orderBy, $allowedOrderFields)) {
            $orderBy = 'product_id';
        }
        
        // 验证排序方向
        if (!in_array($orderDir, ['ASC', 'DESC'])) {
            $orderDir = 'DESC';
        }
        
        $filters['order_by'] = $orderBy;
        $filters['order_dir'] = $orderDir;
        
        // 状态筛选（只显示上架商品）
        $filters['status'] = 'enabled';
        
        // 获取产品列表
        $result = $this->productService->getProducts($filters, $page, $pageSize);
        
        // 格式化产品数据
        $products = [];
        foreach ($result['items'] as $product) {
            $products[] = [
                'product_id' => $product['product_id'] ?? $product[\WeShop\Product\Model\Product::schema_fields_ID] ?? 0,
                'name' => $product['name'] ?? $product[\WeShop\Product\Model\Product::schema_fields_name] ?? '',
                'short_description' => $product['short_description'] ?? $product[\WeShop\Product\Model\Product::schema_fields_short_description] ?? '',
                'price' => $product['price'] ?? $product[\WeShop\Product\Model\Product::schema_fields_price] ?? 0,
                'original_price' => $product['original_price'] ?? $product['price'] ?? $product[\WeShop\Product\Model\Product::schema_fields_price] ?? 0,
                'special_price' => $product['special_price'] ?? null,
                'has_discount' => $product['has_discount'] ?? false,
                'discount_amount' => $product['discount_amount'] ?? 0,
                'discount_percent' => $product['discount_percent'] ?? 0,
                'image' => $product['image'] ?? $product[\WeShop\Product\Model\Product::schema_fields_image] ?? '',
                'sku' => $product['sku'] ?? $product[\WeShop\Product\Model\Product::schema_fields_sku] ?? '',
                'stock' => $product['stock'] ?? $product[\WeShop\Product\Model\Product::schema_fields_stock] ?? 0,
                'in_stock' => ($product['stock'] ?? $product[\WeShop\Product\Model\Product::schema_fields_stock] ?? 0) > 0,
            ];
        }
        
        // 获取分类信息（如果有分类ID）
        $category = null;
        if ($categoryId > 0) {
            $category = $this->categoryService->getCategory($categoryId);
        }
        
        // 准备模板数据
        $this->assign('products', $products);
        $this->assign('category', $category);
        $this->assign('search', $search);
        $this->assign('filters', $filters);
        $this->assign('pagination', $result['pagination']);
        $this->assign('total', $result['total']);
        $this->assign('page', $page);
        $this->assign('page_size', $pageSize);
        $this->assign('order_by', $orderBy);
        $this->assign('order_dir', $orderDir);
        
        // 排序选项
        $this->assign('sort_options', [
            'product_id' => __('最新'),
            'price' => __('价格'),
            'name' => __('名称'),
            'stock' => __('库存'),
        ]);
        
        // Theme模块会自动根据 layoutType 和主题配置加载对应的布局
        // 布局文件路径：app/design/WeShop/default/frontend/layouts/product_list/product_listing_page_{variant}.phtml
        return $this->fetch(self::CONTENT_TEMPLATE);
    }

}
