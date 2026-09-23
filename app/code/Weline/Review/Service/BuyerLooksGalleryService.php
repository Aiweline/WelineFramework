<?php

declare(strict_types=1);

namespace Weline\Review\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestContext;
use Weline\Review\Api\BuyerLooksGalleryInterface;
use Weline\Review\Model\ProductReview;

/**
 * Aggregates approved review images for Theme image-gallery variant=looks.
 */
final class BuyerLooksGalleryService implements BuyerLooksGalleryInterface
{
    public function __construct(
        private readonly ReviewMediaService $media,
    ) {
    }

    public function galleryItems(int $limit = 6, ?int $websiteId = null, ?string $entityUuid = null): array
    {
        $limit = max(1, min(24, $limit));
        $entityUuid = $entityUuid !== null ? trim($entityUuid) : '';
        if ($websiteId === null) {
            try {
                $websiteId = max(0, RequestContext::getWelineWebsiteId());
            } catch (\Throwable) {
                $websiteId = 0;
            }
        } else {
            $websiteId = max(0, $websiteId);
        }

        // Oversample: many approved reviews may be text-only (no images).
        $oversample = min(80, max($limit * 5, $limit));

        /** @var ProductReview $review */
        $review = ObjectManager::getInstance(ProductReview::class);
        $query = $review->clear()
            ->where(ProductReview::schema_fields_STATUS, ProductReview::STATUS_APPROVED)
            ->where(ProductReview::schema_fields_WEBSITE_ID, $websiteId)
            ->order(ProductReview::schema_fields_CREATED_AT, 'DESC');
        if ($entityUuid !== '') {
            $query->where(ProductReview::schema_fields_ENTITY_UUID, $entityUuid);
        }
        $query->pagination(1, $oversample);
        $rows = $query->select()->fetchArray();

        $items = [];
        $seenImages = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $reviewId = (int)($row[ProductReview::schema_fields_ID] ?? 0);
            if ($reviewId <= 0) {
                continue;
            }
            $mediaRows = $this->media->forReview($reviewId);
            $imageUrl = '';
            foreach ($mediaRows as $mediaRow) {
                if (!is_array($mediaRow)) {
                    continue;
                }
                if ((string)($mediaRow['kind'] ?? '') !== 'image') {
                    continue;
                }
                $candidate = trim((string)($mediaRow['url'] ?? ''));
                if ($candidate === '' || isset($seenImages[$candidate])) {
                    continue;
                }
                $imageUrl = $candidate;
                break;
            }
            if ($imageUrl === '') {
                continue;
            }

            $entityUuid = trim((string)($row[ProductReview::schema_fields_ENTITY_UUID] ?? ''));
            $title = trim((string)($row[ProductReview::schema_fields_TITLE] ?? ''));
            $content = trim((string)($row[ProductReview::schema_fields_CONTENT] ?? ''));
            // 框架/E2E 验收夹具不得进买家秀图墙（会露出「[框架验收]…」与坏图）
            if (!$this->isStorefrontLooksSafe($title, $content)) {
                continue;
            }
            if ($title === '') {
                $title = $this->truncate($content, 24);
            }
            if ($title === '') {
                $title = (string)__('买家秀');
            }

            // 店面 PDP 用 product_id（或 slug），不是 identity registry_id（entity_id）
            $productId = $this->resolveStorefrontProductId($entityUuid, $websiteId);
            $link = $productId > 0
                ? '/product/' . $productId . '#product-reviews'
                : '';

            $seenImages[$imageUrl] = true;
            $items[] = [
                'image' => $imageUrl,
                'title' => $title,
                'text' => '',
                'link' => $link,
                'link_label' => (string)__('查看商品'),
                'review_id' => $reviewId,
                'entity_uuid' => $entityUuid,
                'product_id' => $productId,
            ];
            if (count($items) >= $limit) {
                break;
            }
        }

        return $items;
    }

    /**
     * Map review entity_uuid (global_product_uuid) → storefront product_id.
     * Soft-deps Product; missing catalog keeps link empty (no wrong /product/{registry_id}).
     */
    private function resolveStorefrontProductId(string $entityUuid, int $websiteId): int
    {
        $entityUuid = trim($entityUuid);
        if ($entityUuid === '' || !class_exists(\Weline\Product\Repository\ProductRepository::class)) {
            return 0;
        }
        try {
            /** @var \Weline\Product\Repository\ProductRepository $products */
            $products = ObjectManager::getInstance(\Weline\Product\Repository\ProductRepository::class);
            $product = $products->findByGlobalUuid($websiteId, $entityUuid);
            if ($product === null && $websiteId !== 0) {
                $product = $products->findByGlobalUuid(0, $entityUuid);
            }
            if ($product === null) {
                return 0;
            }

            return max(0, (int)$product->getId());
        } catch (\Throwable) {
            return 0;
        }
    }

    private function truncate(string $value, int $maxChars): string
    {
        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
        if ($value === '') {
            return '';
        }
        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            if (mb_strlen($value, 'UTF-8') <= $maxChars) {
                return $value;
            }

            return rtrim(mb_substr($value, 0, $maxChars, 'UTF-8')) . '…';
        }
        if (strlen($value) <= $maxChars) {
            return $value;
        }

        return rtrim(substr($value, 0, $maxChars)) . '…';
    }

    /**
     * Reject framework / E2E acceptance fixtures from buyer-looks storefront wall.
     */
    private function isStorefrontLooksSafe(string $title, string $content): bool
    {
        $blob = $title . "\n" . $content;
        if ($blob === "\n") {
            return true;
        }

        return !preg_match(
            '/\[?\s*框架验收\s*\]?|前后台可复查验收|待审核筛选|四项评分与图文视频|\bE2E\b|acceptance\s+fixture/iu',
            $blob
        );
    }
}
