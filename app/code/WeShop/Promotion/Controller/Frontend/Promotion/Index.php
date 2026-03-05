<?php

declare(strict_types=1);

namespace WeShop\Promotion\Controller\Frontend\Promotion;

use WeShop\Frontend\Controller\BaseController;
use WeShop\Product\Service\ProductService;
use WeShop\Promotion\Service\PromotionService;
use Weline\Framework\Manager\ObjectManager;

/**
 * 活动优惠页控制器
 * 
 * 支持2种布局变体：
 * - promotion_page_1
 * - promotion_page_2
 * 
 * 布局变体通过主题配置设置：layouts.promotion = promotion_page_1
 */
class Index extends BaseController
{
    /**
     * 布局类型
     * Theme模块会根据此类型从主题配置中加载对应的布局
     */
    protected ?string $layoutType = 'promotion';
    
    /**
     * 活动优惠页
     */
    public function index(): string
    {
        /** @var ProductService $productService */
        $productService = ObjectManager::getInstance(ProductService::class);
        
        // 获取特价商品（按价格排序，取价格最低的）
        $dealsResult = $productService->getProducts([
            'status' => 'enabled',
            'order_by' => 'price',
            'order_dir' => 'ASC',
        ], 1, 20);
        
        $deals = [];
        foreach ($dealsResult['items'] as $product) {
            $deals[] = [
                'product_id' => $product['product_id'] ?? $product[\WeShop\Product\Model\Product::schema_fields_ID] ?? 0,
                'name' => $product['name'] ?? $product[\WeShop\Product\Model\Product::schema_fields_name] ?? '',
                'short_description' => $product['short_description'] ?? $product[\WeShop\Product\Model\Product::schema_fields_short_description] ?? '',
                'price' => $product['price'] ?? $product[\WeShop\Product\Model\Product::schema_fields_price] ?? 0,
                'image' => $product['image'] ?? $product[\WeShop\Product\Model\Product::schema_fields_image] ?? '',
                'sku' => $product['sku'] ?? $product[\WeShop\Product\Model\Product::schema_fields_sku] ?? '',
                'in_stock' => ($product['stock'] ?? $product[\WeShop\Product\Model\Product::schema_fields_stock] ?? 0) > 0,
            ];
        }
        
        // 获取活动列表（如果有PromotionService）
        $promotions = [];
        try {
            /** @var PromotionService $promotionService */
            $promotionService = ObjectManager::getInstance(PromotionService::class);
            // TODO: 调用促销服务获取活动列表
        } catch (\Throwable $e) {
            // 促销服务不存在，使用示例数据
            $promotions = [
                [
                    'title' => __('限时特惠'),
                    'subtitle' => __('超值优惠等你来'),
                    'image' => $this->getStaticUrl('assets/images/promotion-1.jpg'),
                    'link' => $this->getUrl('weshop/product/list'),
                ],
                [
                    'title' => __('新品上市'),
                    'subtitle' => __('发现最新潮流'),
                    'image' => $this->getStaticUrl('assets/images/promotion-2.jpg'),
                    'link' => $this->getUrl('weshop/product/list'),
                ],
            ];
        }
        
        // 准备模板数据
        $this->assign('deals', $deals);
        $this->assign('promotions', $promotions);
        
        // 设置页面标题
        $this->assign('title', __('活动优惠'));
        
        // Theme模块会自动根据 layoutType 和主题配置加载对应的布局
        // 布局文件路径：app/design/WeShop/default/frontend/layouts/promotion/promotion_page_{variant}.phtml
        return $this->fetch();
    }
}
