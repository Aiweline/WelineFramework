<?php

declare(strict_types=1);

namespace WeShop\Review\Service;

use Weline\Framework\Manager\ObjectManager;
use WeShop\Review\Model\Review;

/**
 * 商品评价后台页面数据服务
 */
class ReviewAdminPageDataService
{
    /**
     * 获取评价列表数据
     *
     * @param int $page 页码
     * @param int $pageSize 每页数量
     * @param array $filters 筛选条件
     * @return array
     */
    public function getListData(int $page = 1, int $pageSize = 20, array $filters = []): array
    {
        $sanitizedFilters = $this->sanitizeFilters($filters);

        /** @var Review $reviewModel */
        $reviewModel = ObjectManager::getInstance(Review::class);
        $reviewModel->clear();

        if (!empty($sanitizedFilters['product_id'])) {
            $reviewModel->where(Review::schema_fields_PRODUCT_ID, (int) $sanitizedFilters['product_id']);
        }

        if (!empty($sanitizedFilters['customer_id'])) {
            $reviewModel->where(Review::schema_fields_CUSTOMER_ID, (int) $sanitizedFilters['customer_id']);
        }

        if (!empty($sanitizedFilters['status']) && in_array($sanitizedFilters['status'], [Review::STATUS_PENDING, Review::STATUS_APPROVED, Review::STATUS_REJECTED], true)) {
            $reviewModel->where(Review::schema_fields_STATUS, $sanitizedFilters['status']);
        }

        if (!empty($sanitizedFilters['rating'])) {
            $reviewModel->where(Review::schema_fields_RATING, (int) $sanitizedFilters['rating']);
        }

        $reviewModel->order(Review::schema_fields_CREATED_AT, 'DESC')
            ->pagination($page, $pageSize);

        $items = $reviewModel->select()->fetchArray();

        return [
            'reviews' => $items,
            'summary' => $this->getReviewSummary(),
            'filters' => $sanitizedFilters,
            'pagination' => $reviewModel->getPagination(),
            'statusOptions' => $this->getStatusOptions(),
        ];
    }

    /**
     * 获取评价详情数据
     *
     * @param int $reviewId 评价ID
     * @return array
     */
    public function getDetailData(int $reviewId): array
    {
        /** @var Review $reviewModel */
        $reviewModel = ObjectManager::getInstance(Review::class);
        $reviewModel->load($reviewId);

        if (!$reviewModel->getId()) {
            throw new \InvalidArgumentException(__('评价不存在'));
        }

        $reviewData = $reviewModel->getData();

        return [
            'review' => $reviewData,
            'statusOptions' => $this->getStatusOptions(),
            'ratingOptions' => $this->getRatingOptions(),
        ];
    }

    /**
     * 获取评价统计摘要
     *
     * @return array
     */
    public function getReviewSummary(): array
    {
        /** @var Review $reviewModel */
        $reviewModel = ObjectManager::getInstance(Review::class);

        $total = (int) $reviewModel->clear()->count();
        $pendingCount = (int) $reviewModel->clear()->where(Review::schema_fields_STATUS, Review::STATUS_PENDING)->count();
        $approvedCount = (int) $reviewModel->clear()->where(Review::schema_fields_STATUS, Review::STATUS_APPROVED)->count();
        $rejectedCount = (int) $reviewModel->clear()->where(Review::schema_fields_STATUS, Review::STATUS_REJECTED)->count();

        return [
            'total' => $total,
            'pending' => $pendingCount,
            'approved' => $approvedCount,
            'rejected' => $rejectedCount,
        ];
    }

    /**
     * 获取状态选项
     *
     * @return array<string, string>
     */
    public function getStatusOptions(): array
    {
        return [
            Review::STATUS_PENDING => __('Pending Review'),
            Review::STATUS_APPROVED => __('Approved'),
            Review::STATUS_REJECTED => __('Rejected'),
        ];
    }

    /**
     * 获取评分选项
     *
     * @return array<int, string>
     */
    public function getRatingOptions(): array
    {
        return [
            1 => __('1 Star'),
            2 => __('2 Stars'),
            3 => __('3 Stars'),
            4 => __('4 Stars'),
            5 => __('5 Stars'),
        ];
    }

    /**
     * 清理筛选条件
     *
     * @param array $filters
     * @return array
     */
    private function sanitizeFilters(array $filters): array
    {
        $sanitized = [];

        if (!empty($filters['product_id'])) {
            $sanitized['product_id'] = (int) $filters['product_id'];
        }

        if (!empty($filters['customer_id'])) {
            $sanitized['customer_id'] = (int) $filters['customer_id'];
        }

        if (!empty($filters['status']) && in_array($filters['status'], [Review::STATUS_PENDING, Review::STATUS_APPROVED, Review::STATUS_REJECTED], true)) {
            $sanitized['status'] = (string) $filters['status'];
        }

        if (!empty($filters['rating']) && $filters['rating'] >= 1 && $filters['rating'] <= 5) {
            $sanitized['rating'] = (int) $filters['rating'];
        }

        return $sanitized;
    }
}
