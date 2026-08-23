<?php

declare(strict_types=1);

namespace Weline\Review\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestContext;
use Weline\Review\Model\ProductReview;

final class ReviewService
{
    public function __construct(
        private readonly ReviewTypeRegistry $types,
        private readonly ReviewMediaService $media,
        private readonly ReviewCurrentCustomerResolver $customers,
    ) {
    }

    /** @return array<string,mixed> */
    public function form(string $typeCode, string $externalEntityUuid): array
    {
        $type = $this->types->get($typeCode);
        $entity = $type->resolveEntity($externalEntityUuid);
        if ($entity === null) {
            throw new \InvalidArgumentException((string)__('未找到对应的评论对象。'));
        }
        return [
            'success' => true,
            'type_code' => $type->typeCode(),
            'entity_uuid' => $entity['entity_uuid'],
            'schema_version' => 1,
            'fields' => $type->fields(),
            'authenticated' => $this->customers->currentCustomerId() !== null,
            'media' => ['image_max_files' => 6, 'video_max_files' => 2],
        ];
    }

    /** @return array<string,mixed> */
    public function create(string $typeCode, string $externalEntityUuid, array $values, array $mediaTokens): array
    {
        $type = $this->types->get($typeCode);
        $entity = $type->resolveEntity($externalEntityUuid);
        if ($entity === null) {
            throw new \InvalidArgumentException((string)__('未找到对应的评论对象。'));
        }
        $customerId = $this->customers->currentCustomerId();
        $normalized = $type->normalizeValues($values, $customerId);
        $mediaRows = $this->media->assertAttachable($type->typeCode(), $entity['entity_uuid'], $mediaTokens);
        $now = date('Y-m-d H:i:s');
        /** @var ProductReview $review */
        $review = ObjectManager::getInstance(ProductReview::class);
        $review->clear()->setData([
            ProductReview::schema_fields_ENTITY_ID => $entity['entity_id'],
            ProductReview::schema_fields_ENTITY_UUID => $entity['entity_uuid'],
            ProductReview::schema_fields_WEBSITE_ID => max(0, RequestContext::getWelineWebsiteId()),
            ProductReview::schema_fields_STORE_ID => max(0, RequestContext::getWelineStoreId()),
            ProductReview::schema_fields_CUSTOMER_ID => $customerId,
            ProductReview::schema_fields_REVIEWER_NAME => $normalized['reviewer_name'],
            ProductReview::schema_fields_REVIEWER_EMAIL => $normalized['reviewer_email'],
            ProductReview::schema_fields_IS_ANONYMOUS => $normalized['is_anonymous'] ? 1 : 0,
            ProductReview::schema_fields_RATING => $normalized['rating'],
            ProductReview::schema_fields_TITLE => $normalized['title'],
            ProductReview::schema_fields_CONTENT => $normalized['content'],
            ProductReview::schema_fields_EXTRA => json_encode($normalized['extra'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            ProductReview::schema_fields_STATUS => ProductReview::STATUS_PENDING,
            ProductReview::schema_fields_SCHEMA_VERSION => 1,
            ProductReview::schema_fields_CREATED_AT => $now,
            ProductReview::schema_fields_UPDATED_AT => $now,
        ])->save();
        $reviewId = (int)($review->getId() ?? 0);
        if ($reviewId <= 0) {
            throw new \RuntimeException((string)__('评论写入失败。'));
        }
        $this->media->attach($reviewId, $mediaRows);
        return [
            'success' => true,
            'review_id' => $reviewId,
            'status' => ProductReview::STATUS_PENDING,
            'message' => __('评论已提交，审核通过后会公开显示。'),
        ];
    }

    /** @return array<string,mixed> */
    public function list(string $typeCode, string $externalEntityUuid, int $page, int $pageSize): array
    {
        $type = $this->types->get($typeCode);
        $entity = $type->resolveEntity($externalEntityUuid);
        if ($entity === null) {
            throw new \InvalidArgumentException((string)__('未找到对应的评论对象。'));
        }
        $page = max(1, $page);
        $pageSize = max(1, min(20, $pageSize));
        /** @var ProductReview $review */
        $review = ObjectManager::getInstance(ProductReview::class);
        $review->clear()
            ->where(ProductReview::schema_fields_ENTITY_UUID, $entity['entity_uuid'])
            ->where(ProductReview::schema_fields_STATUS, ProductReview::STATUS_APPROVED)
            ->order(ProductReview::schema_fields_CREATED_AT, 'DESC')
            ->pagination($page, $pageSize);
        $rows = $review->select()->fetchArray();
        $items = [];
        $ratingSum = 0;
        foreach ($rows as $row) {
            $rating = max(1, min(5, (int)($row[ProductReview::schema_fields_RATING] ?? 5)));
            $ratingSum += $rating;
            $anonymous = (bool)($row[ProductReview::schema_fields_IS_ANONYMOUS] ?? false);
            $extra = json_decode((string)($row[ProductReview::schema_fields_EXTRA] ?? ''), true);
            $extra = is_array($extra) ? $extra : [];
            $items[] = [
                'review_id' => (int)($row[ProductReview::schema_fields_ID] ?? 0),
                'rating' => $rating,
                'title' => (string)($row[ProductReview::schema_fields_TITLE] ?? ''),
                'content' => (string)($row[ProductReview::schema_fields_CONTENT] ?? ''),
                'reviewer' => $anonymous ? (string)__('匿名用户') : (string)($row[ProductReview::schema_fields_REVIEWER_NAME] ?? __('已认证买家')),
                'created_at' => (string)($row[ProductReview::schema_fields_CREATED_AT] ?? ''),
                'extra' => $extra,
                'media' => $this->media->forReview((int)($row[ProductReview::schema_fields_ID] ?? 0)),
            ];
        }
        $pagination = $review->getPaginationState();
        $total = (int)($pagination['totalSize'] ?? count($items));
        return [
            'success' => true,
            'items' => $items,
            'total' => $total,
            'average_rating' => $items === [] ? 0.0 : round($ratingSum / count($items), 1),
            'pagination' => $pagination,
        ];
    }

    /** @return array<string,mixed> */
    public function upload(string $typeCode, string $externalEntityUuid, string $mediaKind, array $upload): array
    {
        $type = $this->types->get($typeCode);
        $entity = $type->resolveEntity($externalEntityUuid);
        if ($entity === null) {
            throw new \InvalidArgumentException((string)__('未找到对应的评论对象。'));
        }
        return ['success' => true, 'media' => $this->media->stage($type->typeCode(), $entity['entity_uuid'], $mediaKind, $upload)];
    }
}
