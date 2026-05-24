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
    public function __construct(
        private readonly ReviewService $reviewService,
        private readonly ReviewRatingOptionService $ratingOptionService,
        private readonly ?ReviewReplyService $reviewReplyService = null
    ) {
    }

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
        $this->applyFilters($reviewModel, $sanitizedFilters);

        $reviewModel->order(Review::schema_fields_CREATED_AT, 'DESC')
            ->pagination($page, $pageSize);

        $ratingOptions = $this->ratingOptionService->getAllOptions();
        $items = $this->decorateReviews($reviewModel->select()->fetchArray(), $ratingOptions);

        return [
            'reviews' => $items,
            'summary' => $this->getReviewSummary($sanitizedFilters),
            'filters' => $sanitizedFilters,
            'pagination' => $reviewModel->getPagination(),
            'statusOptions' => $this->getStatusOptions(),
            'ratingOptions' => $ratingOptions,
            'ratingOptionMap' => $this->buildRatingOptionMap($ratingOptions),
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

        $ratingOptions = $this->ratingOptionService->getAllOptions();
        $reviewData = $this->decorateReview($reviewModel->getData(), $ratingOptions);

        return [
            'review' => $reviewData,
            'replies' => $this->getReviewReplyService()->getRepliesForReview($reviewId, false),
            'statusOptions' => $this->getStatusOptions(),
            'ratingOptions' => $this->getRatingOptions(),
            'ratingItemOptions' => $ratingOptions,
            'ratingOptionMap' => $this->buildRatingOptionMap($ratingOptions),
        ];
    }

    /**
     * 获取评价统计摘要
     *
     * @return array
     */
    public function getReviewSummary(array $filters = []): array
    {
        /** @var Review $reviewModel */
        $reviewModel = ObjectManager::getInstance(Review::class);

        $baseFilters = $this->sanitizeFilters([
            'product_id' => $filters['product_id'] ?? '',
            'customer_id' => $filters['customer_id'] ?? '',
            'rating' => $filters['rating'] ?? '',
        ]);

        $totalModel = $reviewModel->clear();
        $this->applyFilters($totalModel, $baseFilters);
        $total = (int) $totalModel->count();

        $pendingModel = $reviewModel->clear();
        $this->applyFilters($pendingModel, $baseFilters + ['status' => Review::STATUS_PENDING]);
        $pendingCount = (int) $pendingModel->count();

        $approvedModel = $reviewModel->clear();
        $this->applyFilters($approvedModel, $baseFilters + ['status' => Review::STATUS_APPROVED]);
        $approvedCount = (int) $approvedModel->count();

        $rejectedModel = $reviewModel->clear();
        $this->applyFilters($rejectedModel, $baseFilters + ['status' => Review::STATUS_REJECTED]);
        $rejectedCount = (int) $rejectedModel->count();

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

    /**
     * @param array<string, mixed> $filters
     */
    private function applyFilters(Review $reviewModel, array $filters): void
    {
        if (!empty($filters['product_id'])) {
            $reviewModel->where(Review::schema_fields_PRODUCT_ID, (int) $filters['product_id']);
        }

        if (!empty($filters['customer_id'])) {
            $reviewModel->where(Review::schema_fields_CUSTOMER_ID, (int) $filters['customer_id']);
        }

        if (!empty($filters['status']) && in_array($filters['status'], [Review::STATUS_PENDING, Review::STATUS_APPROVED, Review::STATUS_REJECTED], true)) {
            $reviewModel->where(Review::schema_fields_STATUS, $filters['status']);
        }

        if (!empty($filters['rating'])) {
            $reviewModel->where(Review::schema_fields_RATING, (int) $filters['rating']);
        }
    }

    /**
     * @param array<int, array<string, mixed>> $reviews
     * @param array<int, array<string, mixed>> $ratingOptions
     * @return array<int, array<string, mixed>>
     */
    private function decorateReviews(array $reviews, array $ratingOptions): array
    {
        $decorated = [];
        foreach ($reviews as $review) {
            if (is_array($review)) {
                $decorated[] = $this->decorateReview($review, $ratingOptions);
            }
        }

        return $decorated;
    }

    /**
     * @param array<string, mixed> $review
     * @param array<int, array<string, mixed>> $ratingOptions
     * @return array<string, mixed>
     */
    private function decorateReview(array $review, array $ratingOptions): array
    {
        $mediaItems = $this->reviewService->decodeMediaItems($review[Review::schema_fields_MEDIA_ITEMS] ?? '');
        $ratingScores = $this->reviewService->decodeRatingScores($review[Review::schema_fields_RATING_SCORES] ?? '');
        $ratingOptionMap = $this->buildRatingOptionMap($ratingOptions);

        $review['media_items'] = $mediaItems;
        $review['rating_scores'] = $ratingScores;
        $review['rating_score_labels'] = $this->buildRatingScoreLabels($ratingScores, $ratingOptionMap);
        $review['media_count'] = count($mediaItems);
        $review['image_count'] = count(array_filter($mediaItems, static fn(array $item): bool => ($item['type'] ?? '') === 'image'));
        $review['video_count'] = count(array_filter($mediaItems, static fn(array $item): bool => ($item['type'] ?? '') === 'video'));

        return $review;
    }

    /**
     * @param array<int, array<string, mixed>> $ratingOptions
     * @return array<string, array<string, mixed>>
     */
    private function buildRatingOptionMap(array $ratingOptions): array
    {
        $map = [];
        foreach ($ratingOptions as $option) {
            $code = (string) ($option['code'] ?? '');
            if ($code !== '') {
                $map[$code] = $option;
            }
        }

        return $map;
    }

    /**
     * @param array<string, int> $ratingScores
     * @param array<string, array<string, mixed>> $ratingOptionMap
     * @return array<int, array{code: string, label: string, score: int}>
     */
    private function buildRatingScoreLabels(array $ratingScores, array $ratingOptionMap): array
    {
        $labels = [];
        foreach ($ratingScores as $code => $score) {
            $labels[] = [
                'code' => (string) $code,
                'label' => (string) ($ratingOptionMap[$code]['label'] ?? $code),
                'score' => (int) $score,
            ];
        }

        return $labels;
    }

    private function getReviewReplyService(): ReviewReplyService
    {
        return $this->reviewReplyService ?? ObjectManager::getInstance(ReviewReplyService::class);
    }
}
