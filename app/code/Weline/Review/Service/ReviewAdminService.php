<?php

declare(strict_types=1);

namespace Weline\Review\Service;

use Weline\Review\Model\ProductReview;

final class ReviewAdminService
{
    private const FILTER_STATUSES = [
        ProductReview::STATUS_PENDING,
        ProductReview::STATUS_AI_PENDING_BLOCKED,
        ProductReview::STATUS_APPROVED,
        ProductReview::STATUS_REJECTED,
    ];

    public function __construct(
        private readonly ProductReview $reviews,
        private readonly ReviewMediaService $media,
        private readonly ReviewTypeRegistry $types,
    ) {
    }

    /**
     * @return array{items:list<array<string,mixed>>,pagination:array<string,mixed>,total:int}
     */
    public function listing(
        string $status,
        int $page,
        int $pageSize = 30,
        ?int $websiteId = null,
        ?int $storeId = null,
    ): array {
        $status = strtolower(trim($status));
        if ($status !== '' && !in_array($status, self::FILTER_STATUSES, true)) {
            $status = '';
        }

        $query = $this->reviews->reset();
        if ($status !== '') {
            $query->where(ProductReview::schema_fields_STATUS, $status);
        }
        if ($websiteId !== null) {
            $query->where(ProductReview::schema_fields_WEBSITE_ID, max(0, $websiteId));
        }
        if ($storeId !== null) {
            $query->where(ProductReview::schema_fields_STORE_ID, max(0, $storeId));
        }
        $query = $query
            ->order(ProductReview::schema_fields_CREATED_AT, 'DESC')
            ->pagination(max(1, $page), max(1, min(100, $pageSize)))
            ->select()
            ->fetch();

        $pagination = $query->getPaginationState();
        $items = [];
        foreach ($query->getItems() as $review) {
            if (!$review instanceof ProductReview) {
                continue;
            }
            $reviewId = (int)($review->getId() ?? 0);
            $anonymous = (bool)$review->getData(ProductReview::schema_fields_IS_ANONYMOUS);
            $extra = json_decode((string)$review->getData(ProductReview::schema_fields_EXTRA), true);
            $extra = is_array($extra) ? $extra : [];
            $rating = max(1, min(5, (int)$review->getData(ProductReview::schema_fields_RATING)));
            $items[] = [
                'review_id' => $reviewId,
                'type_code' => 'product',
                'entity_uuid' => (string)$review->getData(ProductReview::schema_fields_ENTITY_UUID),
                'website_id' => (int)$review->getData(ProductReview::schema_fields_WEBSITE_ID),
                'store_id' => (int)$review->getData(ProductReview::schema_fields_STORE_ID),
                'rating' => $rating,
                'ratings' => $this->ratingValues('product', $rating, $extra),
                'extra' => $extra,
                'title' => (string)$review->getData(ProductReview::schema_fields_TITLE),
                'content' => (string)$review->getData(ProductReview::schema_fields_CONTENT),
                'reviewer' => $anonymous
                    ? (string)__('匿名用户')
                    : (string)($review->getData(ProductReview::schema_fields_REVIEWER_NAME) ?: __('游客')),
                'reviewer_email' => (string)$review->getData(ProductReview::schema_fields_REVIEWER_EMAIL),
                'is_anonymous' => $anonymous,
                'status' => (string)$review->getData(ProductReview::schema_fields_STATUS),
                'created_at' => (string)$review->getData(ProductReview::schema_fields_CREATED_AT),
                'updated_at' => (string)$review->getData(ProductReview::schema_fields_UPDATED_AT),
                'media' => $this->media->forReview($reviewId),
            ];
        }

        return [
            'items' => $items,
            'pagination' => $pagination,
            'total' => (int)($pagination['totalSize'] ?? count($items)),
        ];
    }

    public function moderate(int $reviewId, string $targetStatus): void
    {
        $targetStatus = strtolower(trim($targetStatus));
        if (!in_array($targetStatus, [ProductReview::STATUS_APPROVED, ProductReview::STATUS_REJECTED], true)) {
            throw new \InvalidArgumentException((string)__('审核状态无效。'));
        }
        if ($reviewId <= 0) {
            throw new \InvalidArgumentException((string)__('评论不存在。'));
        }

        $this->reviews->reset()->load($reviewId);
        if ((int)($this->reviews->getId() ?? 0) !== $reviewId) {
            throw new \InvalidArgumentException((string)__('评论不存在。'));
        }

        $this->reviews
            ->setData(ProductReview::schema_fields_STATUS, $targetStatus)
            ->setData(ProductReview::schema_fields_UPDATED_AT, date('Y-m-d H:i:s'))
            ->save();
    }

    /** @return list<string> */
    public function statuses(): array
    {
        return self::FILTER_STATUSES;
    }

    /**
     * @param array<string,mixed> $extra
     * @return list<array{key:string,label:string,value:int,max:int}>
     */
    private function ratingValues(string $typeCode, int $overallRating, array $extra): array
    {
        $ratings = [];
        foreach ($this->types->get($typeCode)->fields() as $field) {
            if (($field['type'] ?? '') !== 'rating') {
                continue;
            }
            $key = trim((string)($field['key'] ?? ''));
            if ($key === '') {
                continue;
            }
            $max = max(1, (int)($field['max'] ?? 5));
            $value = $key === 'rating' ? $overallRating : (int)($extra[$key] ?? 0);
            if ($value < 1 || $value > $max) {
                continue;
            }
            $ratings[] = [
                'key' => $key,
                'label' => (string)($field['label'] ?? $key),
                'value' => $value,
                'max' => $max,
            ];
        }
        return $ratings;
    }
}
