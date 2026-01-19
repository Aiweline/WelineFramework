<?php

declare(strict_types=1);

namespace WeShop\Review\Controller\Frontend\Review;

use WeShop\Frontend\Controller\BaseController;
use WeShop\Review\Service\ReviewService;
use Weline\Framework\Manager\ObjectManager;

/**
 * 评论页控制器
 * 
 * 支持1种布局变体：
 * - review_page_1
 * 
 * 布局变体通过主题配置设置：layouts.review = review_page_1
 */
class Index extends BaseController
{
    /**
     * 布局类型
     * Theme模块会根据此类型从主题配置中加载对应的布局
     */
    protected ?string $layoutType = 'review';
    
    /**
     * 评论页
     */
    public function index(): string
    {
        $productId = (int)($this->request->getParam('product_id') ?? 0);
        
        if (!$productId) {
            $this->getMessageManager()->addError(__('产品ID不能为空'));
            return $this->redirect('weshop/product/list');
        }
        
        $page = (int)($this->request->getParam('page') ?? 1);
        $pageSize = (int)($this->request->getParam('page_size') ?? 20);
        
        // 获取评论列表
        $reviews = [];
        $total = 0;
        $averageRating = 0;
        
        try {
            /** @var ReviewService $reviewService */
            $reviewService = ObjectManager::getInstance(ReviewService::class);
            // TODO: 调用评论服务获取评论列表
            // $result = $reviewService->getProductReviews($productId, $page, $pageSize);
            // $reviews = $result['items'];
            // $total = $result['total'];
            // $averageRating = $reviewService->getAverageRating($productId);
        } catch (\Throwable $e) {
            // 评论服务不存在，使用示例数据
            $reviews = [
                [
                    'review_id' => 1,
                    'customer_name' => __('用户A'),
                    'rating' => 5,
                    'title' => __('非常满意'),
                    'content' => __('产品质量很好，物流也很快，非常满意！'),
                    'created_at' => date('Y-m-d H:i:s', strtotime('-3 days')),
                    'verified_purchase' => true,
                ],
                [
                    'review_id' => 2,
                    'customer_name' => __('用户B'),
                    'rating' => 4,
                    'title' => __('还不错'),
                    'content' => __('产品还可以，就是包装有点简单。'),
                    'created_at' => date('Y-m-d H:i:s', strtotime('-7 days')),
                    'verified_purchase' => true,
                ],
            ];
            $total = 2;
            $averageRating = 4.5;
        }
        
        // 准备模板数据
        $this->assign('product_id', $productId);
        $this->assign('reviews', $reviews);
        $this->assign('total', $total);
        $this->assign('average_rating', $averageRating);
        $this->assign('page', $page);
        $this->assign('page_size', $pageSize);
        
        // 设置页面标题
        $this->assign('title', __('商品评论'));
        
        // Theme模块会自动根据 layoutType 和主题配置加载对应的布局
        // 布局文件路径：app/design/WeShop/default/frontend/layouts/review/review_page_1.phtml
        return $this->fetch();
    }
}
