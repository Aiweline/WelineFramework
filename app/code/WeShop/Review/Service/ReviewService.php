<?php

declare(strict_types=1);

namespace WeShop\Review\Service;

use Weline\Framework\Event\EventsManager;
use Weline\Framework\Manager\ObjectManager;
use WeShop\Review\Model\Review;

/**
 * 评价服务
 */
class ReviewService
{
    public const FRONTEND_ROUTE = 'review/frontend/review';
    public const FRONTEND_CREATE_ROUTE = 'review/create';

    public function __construct(
        private readonly ?EventsManager $eventsManager = null
    ) {
    }

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
            ->setData(Review::schema_fields_MEDIA_ITEMS, $this->encodeJson($this->normalizeMediaItems($reviewData['media_items'] ?? [])))
            ->setData(Review::schema_fields_RATING_SCORES, $this->encodeJson($this->normalizeRatingScores($reviewData['rating_scores'] ?? [])))
            ->setData(Review::schema_fields_STATUS, Review::STATUS_PENDING)
            ->save();

        $eventData = [
            'review' => $review,
            'review_id' => (int) ($review->getId() ?? 0),
            'product_id' => (int) ($review->getData(Review::schema_fields_PRODUCT_ID) ?? 0),
            'customer_id' => (int) ($review->getData(Review::schema_fields_CUSTOMER_ID) ?? 0),
            'rating' => (float) ($review->getData(Review::schema_fields_RATING) ?? 0),
            'status' => (string) ($review->getData(Review::schema_fields_STATUS) ?? Review::STATUS_PENDING),
        ];
        $this->getEventsManager()->dispatch('WeShop_Review::review_created', $eventData);
        
        return $review;
    }

    /**
     * @return array<string, mixed>
     */
    public function buildClientReviewPayload(Review $review): array
    {
        $customerId = (int) ($review->getData(Review::schema_fields_CUSTOMER_ID) ?? 0);
        $status = (string) ($review->getData(Review::schema_fields_STATUS) ?? Review::STATUS_PENDING);
        $createdAt = (string) ($review->getData(Review::schema_fields_CREATED_AT) ?? '');

        return [
            'review_id' => (int) ($review->getId() ?? $review->getData(Review::schema_fields_ID) ?? 0),
            'product_id' => (int) ($review->getData(Review::schema_fields_PRODUCT_ID) ?? 0),
            'customer_id' => $customerId,
            'customer_name' => (string) __('匿名用户'),
            'rating' => (float) ($review->getData(Review::schema_fields_RATING) ?? 0),
            'title' => (string) ($review->getData(Review::schema_fields_TITLE) ?? ''),
            'content' => (string) ($review->getData(Review::schema_fields_CONTENT) ?? ''),
            'media_items' => $this->decodeMediaItems($review->getData(Review::schema_fields_MEDIA_ITEMS) ?? ''),
            'rating_scores' => $this->decodeRatingScores($review->getData(Review::schema_fields_RATING_SCORES) ?? ''),
            'created_at' => $createdAt !== '' ? $createdAt : date('Y-m-d H:i:s'),
            'status' => $status,
            'pending' => $status === Review::STATUS_PENDING,
            'verified_purchase' => $customerId > 0,
        ];
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

        $total = (int) (clone $review)->clear()
            ->where(Review::schema_fields_PRODUCT_ID, $productId)
            ->where(Review::schema_fields_STATUS, Review::STATUS_APPROVED)
            ->count();

        $review->clear()
            ->where(Review::schema_fields_PRODUCT_ID, $productId)
            ->where(Review::schema_fields_STATUS, Review::STATUS_APPROVED)
            ->order(Review::schema_fields_CREATED_AT, 'DESC')
            ->order(Review::schema_fields_ID, 'DESC')
            ->pagination($page, $pageSize, [], 1000, $total);
        
        $items = $review->select()->fetchArray();
        $pagination = $review->getPaginationData();
        
        return [
            'items' => $items,
            'total' => $total,
            'pagination' => $pagination,
        ];
    }

    public function resolveReviewPage(int $productId, int $reviewId, int $pageSize = 20, int $defaultPage = 1): int
    {
        $pageSize = max(1, min(50, $pageSize));
        $defaultPage = max(1, $defaultPage);
        if ($productId <= 0 || $reviewId <= 0) {
            return $defaultPage;
        }

        /** @var Review $target */
        $target = ObjectManager::getInstance(Review::class);
        $target->load($reviewId);
        if (!$target->getId()
            || (int) $target->getData(Review::schema_fields_PRODUCT_ID) !== $productId
            || (string) $target->getData(Review::schema_fields_STATUS) !== Review::STATUS_APPROVED
        ) {
            return $defaultPage;
        }

        $createdAt = (string) $target->getData(Review::schema_fields_CREATED_AT);
        $aheadCount = 0;
        if ($createdAt !== '') {
            $aheadCount += $this->baseApprovedProductReviewQuery($productId)
                ->where(Review::schema_fields_CREATED_AT, $createdAt, '>')
                ->count();
            $aheadCount += $this->baseApprovedProductReviewQuery($productId)
                ->where(Review::schema_fields_CREATED_AT, $createdAt)
                ->where(Review::schema_fields_ID, $reviewId, '>')
                ->count();
        } else {
            $aheadCount += $this->baseApprovedProductReviewQuery($productId)
                ->where(Review::schema_fields_ID, $reviewId, '>')
                ->count();
        }

        return (int) floor($aheadCount / $pageSize) + 1;
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
        
        $oldStatus = (string) ($review->getData(Review::schema_fields_STATUS) ?? '');
        $review->setData(Review::schema_fields_STATUS, $status)->save();

        $eventData = [
            'review' => $review,
            'review_id' => (int) ($review->getId() ?? 0),
            'product_id' => (int) ($review->getData(Review::schema_fields_PRODUCT_ID) ?? 0),
            'customer_id' => (int) ($review->getData(Review::schema_fields_CUSTOMER_ID) ?? 0),
            'old_status' => $oldStatus,
            'new_status' => $status,
        ];
        $this->getEventsManager()->dispatch('WeShop_Review::review_status_changed', $eventData);
        
        return $review;
    }

    /**
     * @return float
     */
    public function getAverageRating(int $productId): float
    {
        /** @var Review $review */
        $review = ObjectManager::getInstance(Review::class);
        $review->clear()
            ->where(Review::schema_fields_PRODUCT_ID, $productId)
            ->where(Review::schema_fields_STATUS, Review::STATUS_APPROVED);

        $rows = $review->select(Review::schema_fields_RATING)->fetchArray();
        if (!$rows) {
            return 0.0;
        }

        $sum = 0;
        $count = 0;
        foreach ($rows as $row) {
            $sum += (float) ($row[Review::schema_fields_RATING] ?? 0);
            ++$count;
        }

        return $count > 0 ? $sum / $count : 0.0;
    }

    /**
     * @return array<int, array{type: string, url: string, label: string}>
     */
    public function normalizeMediaItems(mixed $rawMediaItems): array
    {
        $items = $this->decodeJsonValue($rawMediaItems);
        if (is_string($items)) {
            $items = $this->splitLines($items);
        }

        if (!is_array($items)) {
            return [];
        }

        $normalized = [];
        foreach ($items as $item) {
            $url = '';
            $type = '';
            $label = '';

            if (is_string($item)) {
                $url = trim($item);
            } elseif (is_array($item)) {
                $url = trim((string) ($item['url'] ?? $item['src'] ?? ''));
                $type = strtolower(trim((string) ($item['type'] ?? '')));
                $label = trim((string) ($item['label'] ?? $item['alt'] ?? ''));
            }

            if ($url === '' || !$this->isSafeMediaUrl($url)) {
                continue;
            }

            $type = $this->normalizeMediaType($type, $url);
            if ($type === '') {
                continue;
            }

            $normalized[] = [
                'type' => $type,
                'url' => mb_substr($url, 0, 1000),
                'label' => mb_substr($label, 0, 120),
            ];

            if (count($normalized) >= 12) {
                break;
            }
        }

        return $normalized;
    }

    /**
     * @return array<string, int>
     */
    public function normalizeRatingScores(mixed $rawScores): array
    {
        $scores = $this->decodeJsonValue($rawScores);
        if (!is_array($scores)) {
            return [];
        }

        /** @var ReviewRatingOptionService $ratingOptionService */
        $ratingOptionService = ObjectManager::getInstance(ReviewRatingOptionService::class);
        $enabledOptions = $ratingOptionService->getEnabledOptionMap();
        if ($enabledOptions === []) {
            return [];
        }

        $normalized = [];
        foreach ($enabledOptions as $code => $option) {
            if (!array_key_exists($code, $scores)) {
                continue;
            }

            $score = (int) $scores[$code];
            if ($score <= 0) {
                continue;
            }

            $normalized[$code] = max(1, min(5, $score));
        }

        return $normalized;
    }

    public function decodeMediaItems(mixed $storedValue): array
    {
        return $this->normalizeMediaItems($storedValue);
    }

    /**
     * @return array<string, int>
     */
    public function decodeRatingScores(mixed $storedValue): array
    {
        $decoded = $this->decodeJsonValue($storedValue);
        if (!is_array($decoded)) {
            return [];
        }

        $scores = [];
        foreach ($decoded as $code => $score) {
            $code = (string) $code;
            $score = (int) $score;
            if ($code !== '' && $score > 0) {
                $scores[$code] = max(1, min(5, $score));
            }
        }

        return $scores;
    }

    private function decodeJsonValue(mixed $value): mixed
    {
        if (!is_string($value)) {
            return $value;
        }

        $trimmed = trim($value);
        if ($trimmed === '') {
            return [];
        }

        $decoded = json_decode($trimmed, true);
        return json_last_error() === JSON_ERROR_NONE ? $decoded : $trimmed;
    }

    private function encodeJson(array $value): string
    {
        if ($value === []) {
            return '';
        }

        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
    }

    /**
     * @return array<int, string>
     */
    private function splitLines(string $value): array
    {
        $parts = preg_split('/[\r\n,]+/', $value) ?: [];
        $items = [];
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part !== '') {
                $items[] = $part;
            }
        }

        return $items;
    }

    private function isSafeMediaUrl(string $url): bool
    {
        if (preg_match('/^\s*javascript:/i', $url)) {
            return false;
        }

        if (preg_match('/^[a-z][a-z0-9+.-]*:/i', $url)) {
            return (bool) preg_match('/^https?:\/\//i', $url);
        }

        return true;
    }

    private function normalizeMediaType(string $type, string $url): string
    {
        if (in_array($type, ['image', 'video'], true)) {
            return $type;
        }

        $path = parse_url($url, PHP_URL_PATH);
        $extension = strtolower(pathinfo((string) $path, PATHINFO_EXTENSION));

        if (in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif'], true)) {
            return 'image';
        }

        if (in_array($extension, ['mp4', 'webm', 'ogg', 'mov', 'm4v'], true)) {
            return 'video';
        }

        return '';
    }

    private function baseApprovedProductReviewQuery(int $productId): Review
    {
        /** @var Review $review */
        $review = ObjectManager::getInstance(Review::class);
        return $review->clear()
            ->where(Review::schema_fields_PRODUCT_ID, $productId)
            ->where(Review::schema_fields_STATUS, Review::STATUS_APPROVED);
    }

    private function getEventsManager(): EventsManager
    {
        return $this->eventsManager ?? ObjectManager::getInstance(EventsManager::class);
    }
}
