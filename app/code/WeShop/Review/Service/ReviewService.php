<?php

declare(strict_types=1);

namespace WeShop\Review\Service;

use Weline\Framework\Manager\ObjectManager;
use WeShop\Review\Model\Review;

/**
 * 评价服务
 */
class ReviewService
{
    /**
     * 创建评价
     * 
     * @param array $reviewData 评价数据
     * @return Review
     */
    public function createReview(array $reviewData): Review
    {
        /** @var Review $review */
        $review = ObjectManager::getInstance(Review::class);
        
        $review->clearData()
            ->setData(Review::schema_fields_PRODUCT_ID, $reviewData['product_id'] ?? 0)
            ->setData(Review::schema_fields_CUSTOMER_ID, $reviewData['customer_id'] ?? 0)
            ->setData(Review::schema_fields_RATING, $reviewData['rating'] ?? 5)
            ->setData(Review::schema_fields_TITLE, $reviewData['title'] ?? '')
            ->setData(Review::schema_fields_CONTENT, $reviewData['content'] ?? '')
            ->setData(Review::schema_fields_STATUS, Review::STATUS_PENDING)
            ->save();
        
        return $review;
    }
    
    /**
     * 获取产品评价列表
     * 
     * @param int $productId 产品ID
     * @param int $page 页码
     * @param int $pageSize 每页数量
     * @return array
     */
    public function getProductReviews(int $productId, int $page = 1, int $pageSize = 20): array
    {
        /** @var Review $review */
        $review = ObjectManager::getInstance(Review::class);
        
        $review->clear()
            ->where(Review::schema_fields_PRODUCT_ID, $productId)
            ->where(Review::schema_fields_STATUS, Review::STATUS_APPROVED)
            ->order(Review::schema_fields_CREATED_AT, 'DESC')
            ->pagination($page, $pageSize);
        
        $items = $review->select()->fetchArray();
        
        return [
            'items' => $items,
            'total' => $review->getTotalCount(),
            'pagination' => $review->getPagination(),
        ];
    }
    
    /**
     * 审核评价
     * 
     * @param int $reviewId 评价ID
     * @param string $status 状态（approved/rejected）
     * @return Review
     */
    public function approveReview(int $reviewId, string $status = Review::STATUS_APPROVED): Review
    {
        /** @var Review $review */
        $review = ObjectManager::getInstance(Review::class);
        $review->load($reviewId);
        
        if (!$review->getId()) {
            throw new \Exception(__('评价不存在'));
        }
        
        $review->setData(Review::schema_fields_STATUS, $status)->save();
        
        return $review;
    }
}
