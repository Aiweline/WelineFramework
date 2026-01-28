<?php

declare(strict_types=1);

namespace WeShop\Product\Controller\Frontend\Product;

use WeShop\Frontend\Controller\BaseController;
use WeShop\Product\Service\ProductEavService;
use WeShop\Product\Service\ProductService;
use WeShop\Review\Service\ReviewService;
use WeShop\QA\Service\QAService;
use Weline\Framework\Manager\ObjectManager;

/**
 * 产品详情页控制器
 * 
 * 支持9种布局变体：
 * - product_detail_page_1
 * - product_detail_page_2
 * - product_detail_page_3
 * - product_detail_page_4
 * - product_detail_page_5
 * - product_detail_page_6
 * - product_detail_page_7
 * - product_detail_page_8
 * - product_detail_page_9
 * 
 * 布局变体通过主题配置设置：layouts.product = product_detail_page_1
 */
class View extends BaseController
{
    /**
     * 布局类型
     * Theme模块会根据此类型从主题配置中加载对应的布局
     */
    protected ?string $layoutType = 'product';
    
    /**
     * 产品详情页
     */
    public function index(): string
    {
        $productId = (int)($this->request->getParam('id') ?? $this->request->getParam('product_id') ?? 0);
        
        if (!$productId) {
            $this->getMessageManager()->addError(__('产品ID不能为空'));
            return $this->redirect('weshop/product/list');
        }
        
        /** @var ProductService $productService */
        $productService = ObjectManager::getInstance(ProductService::class);
        
        // 获取产品信息
        $product = $productService->getProduct($productId);
        
        if (!$product || !$product->getId()) {
            $this->getMessageManager()->addError(__('产品不存在'));
            return $this->redirect('weshop/product/list');
        }
        
        // 检查产品状态（status=1 表示启用）
        $status = $product->getData(\WeShop\Product\Model\Product::fields_status);
        if ($status != 1 && $status !== 'enabled') {
            $this->getMessageManager()->addError(__('产品已下架'));
            return $this->redirect('weshop/product/list');
        }
        
        // 格式化产品数据
        $productData = [
            'product_id' => $product->getId(),
            'name' => $product->getData(\WeShop\Product\Model\Product::fields_name) ?? '',
            'short_description' => $product->getData(\WeShop\Product\Model\Product::fields_short_description) ?? '',
            'description' => $product->getData(\WeShop\Product\Model\Product::fields_description) ?? '',
            'price' => (float)($product->getData(\WeShop\Product\Model\Product::fields_price) ?? 0),
            'cost' => (float)($product->getData(\WeShop\Product\Model\Product::fields_cost) ?? 0),
            'sku' => $product->getData(\WeShop\Product\Model\Product::fields_sku) ?? '',
            'stock' => (int)($product->getData(\WeShop\Product\Model\Product::fields_stock) ?? 0),
            'weight' => (float)($product->getData(\WeShop\Product\Model\Product::fields_weight) ?? 0),
            'image' => $product->getData(\WeShop\Product\Model\Product::fields_image) ?? '',
            'images' => $product->getData(\WeShop\Product\Model\Product::fields_images) ?? '',
            'in_stock' => (int)($product->getData(\WeShop\Product\Model\Product::fields_stock) ?? 0) > 0,
            'stock_status' => (int)($product->getData(\WeShop\Product\Model\Product::fields_stock) ?? 0) > 0 ? 'in_stock' : 'out_of_stock',
        ];
        
        // 处理产品图片
        $images = [];
        if (!empty($productData['image'])) {
            $images[] = $productData['image'];
        }
        if (!empty($productData['images'])) {
            $additionalImages = is_string($productData['images']) ? json_decode($productData['images'], true) : $productData['images'];
            if (is_array($additionalImages)) {
                $images = array_merge($images, $additionalImages);
            }
        }
        $productData['images'] = array_unique($images);
        
        // 获取产品属性（EAV属性）
        $attributes = [];
        try {
            /** @var ProductEavService $productEavService */
            $productEavService = ObjectManager::getInstance(ProductEavService::class);
            $attributes = $productEavService->getProductAttributesViewModel($productId);
        } catch (\Throwable $e) {
            // EAV 服务不可用，忽略
        }
        
        // 获取相关产品（同分类的其他产品）
        $relatedProducts = [];
        $categoryId = $product->getData('category_id');
        if ($categoryId) {
            $relatedResult = $productService->getProducts([
                'category_id' => $categoryId,
                'status' => 'enabled',
            ], 1, 8);
            
            foreach ($relatedResult['items'] as $relatedProduct) {
                if ($relatedProduct['product_id'] == $productId) {
                    continue; // 排除当前产品
                }
                $relatedProducts[] = [
                    'product_id' => $relatedProduct['product_id'] ?? $relatedProduct[\WeShop\Product\Model\Product::fields_ID] ?? 0,
                    'name' => $relatedProduct['name'] ?? $relatedProduct[\WeShop\Product\Model\Product::fields_name] ?? '',
                    'price' => $relatedProduct['price'] ?? $relatedProduct[\WeShop\Product\Model\Product::fields_price] ?? 0,
                    'image' => $relatedProduct['image'] ?? $relatedProduct[\WeShop\Product\Model\Product::fields_image] ?? '',
                    'sku' => $relatedProduct['sku'] ?? $relatedProduct[\WeShop\Product\Model\Product::fields_sku] ?? '',
                ];
            }
        }
        
        // 获取产品评论
        $reviews = [];
        try {
            /** @var ReviewService $reviewService */
            $reviewService = ObjectManager::getInstance(ReviewService::class);
            // TODO: 调用评论服务获取评论列表
        } catch (\Throwable $e) {
            // 评论服务不存在，忽略
        }
        
        // 获取产品问答
        $qa = [];
        try {
            /** @var QAService $qaService */
            $qaService = ObjectManager::getInstance(QAService::class);
            // TODO: 调用问答服务获取问答列表
        } catch (\Throwable $e) {
            // 问答服务不存在，忽略
        }
        
        // 准备模板数据
        $this->assign('product', $productData);
        $this->assign('attributes', $attributes);
        $this->assign('related_products', $relatedProducts);
        $this->assign('reviews', $reviews);
        $this->assign('qa', $qa);
        
        // SEO数据
        $this->assign('title', $productData['name']);
        $this->assign('meta_title', $product->getData(\WeShop\Product\Model\Product::fields_meta_name) ?? $productData['name']);
        $this->assign('meta_description', $product->getData(\WeShop\Product\Model\Product::fields_meta_description) ?? $productData['short_description']);
        $this->assign('meta_keywords', $product->getData(\WeShop\Product\Model\Product::fields_meta_keywords) ?? '');
        
        // Theme模块会自动根据 layoutType 和主题配置加载对应的布局
        // 布局文件路径：app/design/WeShop/default/frontend/layouts/product/product_detail_page_{variant}.phtml
        return $this->fetch();
    }
}
