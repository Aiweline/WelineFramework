<?php

declare(strict_types=1);

namespace WeShop\Frontend\Controller;

use WeShop\Frontend\Controller\BaseController;
use WeShop\Product\Service\ProductService;
use WeShop\Catalog\Service\CategoryService;
use WeShop\Promotion\Service\PromotionService;
use Weline\Framework\Manager\ObjectManager;

/**
 * 首页控制器
 * 
 * 支持6种布局变体：
 * - e_commerce_home_page_1
 * - e_commerce_home_page_2
 * - e_commerce_home_page_3
 * - e_commerce_home_page_4
 * - e_commerce_home_page_5
 * - e_commerce_home_page_6
 * 
 * 布局变体通过主题配置设置：layouts.homepage = e_commerce_home_page_1
 */
class Index extends BaseController
{
    /**
     * 布局类型
     * Theme模块会根据此类型从主题配置中加载对应的布局
     */
    protected ?string $layoutType = 'homepage';
    
    /**
     * 首页
     */
    public function index(): string
    {
        /** @var ProductService $productService */
        $productService = ObjectManager::getInstance(ProductService::class);
        
        /** @var CategoryService $categoryService */
        $categoryService = ObjectManager::getInstance(CategoryService::class);
        
        // 获取分类列表（顶级分类）
        $categories = $categoryService->getCategoryTree(0);
        $formattedCategories = [];
        foreach ($categories as $category) {
            $formattedCategories[] = [
                'category_id' => $category['category_id'] ?? 0,
                'name' => $category['name'] ?? '',
                'url' => $this->getUrl('weshop/product/list', ['category_id' => $category['category_id'] ?? 0]),
                'image' => $category['image'] ?? '',
                'children' => $category['children'] ?? [],
            ];
        }
        
        // 获取推荐商品（最新上架的商品）
        $recommendedResult = $productService->getProducts([
            'status' => 'enabled',
            'order_by' => 'product_id',
            'order_dir' => 'DESC',
        ], 1, 12);
        
        $recommendedProducts = [];
        foreach ($recommendedResult['items'] as $product) {
            $recommendedProducts[] = [
                'product_id' => $product['product_id'] ?? $product[\WeShop\Product\Model\Product::schema_fields_ID] ?? 0,
                'name' => $product['name'] ?? $product[\WeShop\Product\Model\Product::schema_fields_name] ?? '',
                'short_description' => $product['short_description'] ?? $product[\WeShop\Product\Model\Product::schema_fields_short_description] ?? '',
                'price' => $product['price'] ?? $product[\WeShop\Product\Model\Product::schema_fields_price] ?? 0,
                'image' => $product['image'] ?? $product[\WeShop\Product\Model\Product::schema_fields_image] ?? '',
                'sku' => $product['sku'] ?? $product[\WeShop\Product\Model\Product::schema_fields_sku] ?? '',
                'in_stock' => ($product['stock'] ?? $product[\WeShop\Product\Model\Product::schema_fields_stock] ?? 0) > 0,
            ];
        }
        
        // 获取今日特价商品（可以通过促销模块获取，或按价格筛选）
        $dealsResult = $productService->getProducts([
            'status' => 'enabled',
            'order_by' => 'price',
            'order_dir' => 'ASC',
        ], 1, 8);
        
        $deals = [];
        foreach ($dealsResult['items'] as $product) {
            $deals[] = [
                'product_id' => $product['product_id'] ?? $product[\WeShop\Product\Model\Product::schema_fields_ID] ?? 0,
                'name' => $product['name'] ?? $product[\WeShop\Product\Model\Product::schema_fields_name] ?? '',
                'price' => $product['price'] ?? $product[\WeShop\Product\Model\Product::schema_fields_price] ?? 0,
                'image' => $product['image'] ?? $product[\WeShop\Product\Model\Product::schema_fields_image] ?? '',
                'sku' => $product['sku'] ?? $product[\WeShop\Product\Model\Product::schema_fields_sku] ?? '',
            ];
        }
        
        // 获取热销商品（可以通过销量排序，这里暂时使用最新商品）
        $bestsellersResult = $productService->getProducts([
            'status' => 'enabled',
            'order_by' => 'product_id',
            'order_dir' => 'DESC',
        ], 1, 8);
        
        $bestsellers = [];
        foreach ($bestsellersResult['items'] as $product) {
            $bestsellers[] = [
                'product_id' => $product['product_id'] ?? $product[\WeShop\Product\Model\Product::schema_fields_ID] ?? 0,
                'name' => $product['name'] ?? $product[\WeShop\Product\Model\Product::schema_fields_name] ?? '',
                'price' => $product['price'] ?? $product[\WeShop\Product\Model\Product::schema_fields_price] ?? 0,
                'image' => $product['image'] ?? $product[\WeShop\Product\Model\Product::schema_fields_image] ?? '',
                'sku' => $product['sku'] ?? $product[\WeShop\Product\Model\Product::schema_fields_sku] ?? '',
            ];
        }
        
        // 获取轮播图/Banner数据（可以从CMS或配置中获取，这里使用示例数据）
        $banners = [
            [
                'title' => __('新品上市'),
                'subtitle' => __('发现最新潮流'),
                'image' => $this->getStaticUrl('assets/images/banner-1.jpg'),
                'link' => $this->getUrl('weshop/product/list'),
            ],
            [
                'title' => __('限时特惠'),
                'subtitle' => __('超值优惠等你来'),
                'image' => $this->getStaticUrl('assets/images/banner-2.jpg'),
                'link' => $this->getUrl('weshop/promotion'),
            ],
            [
                'title' => __('品质保证'),
                'subtitle' => __('正品保障，放心购物'),
                'image' => $this->getStaticUrl('assets/images/banner-3.jpg'),
                'link' => $this->getUrl('weshop'),
            ],
        ];
        
        // 准备模板数据
        $this->assign('categories', $formattedCategories);
        $this->assign('recommended_products', $recommendedProducts);
        $this->assign('deals', $deals);
        $this->assign('bestsellers', $bestsellers);
        $this->assign('banners', $banners);
        
        // 设置页面标题
        $this->assign('title', __('首页'));
        
        // Theme模块会自动根据 layoutType 和主题配置加载对应的布局
        // 布局文件路径：app/design/WeShop/default/frontend/layouts/homepage/e_commerce_home_page_{variant}.phtml
        return $this->fetch();
    }
}
