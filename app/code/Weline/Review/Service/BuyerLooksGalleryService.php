<?php

declare(strict_types=1);

namespace Weline\Review\Service;

use Weline\Framework\Cache\Service\StorefrontScopeHotCache;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestContext;
use Weline\Review\Api\BuyerLooksGalleryInterface;
use Weline\Review\Model\ProductReview;

/**
 * Aggregates approved review images for Theme image-gallery variant=looks.
 */
final class BuyerLooksGalleryService implements BuyerLooksGalleryInterface
{
    private const CACHE_POOL = 'review.buyer_looks';
    private const FRESH_TTL_SECONDS = 120;
    private const STALE_TTL_SECONDS = 600;

    public function __construct(
        private readonly ReviewMediaService $media,
        private readonly ?StorefrontScopeHotCache $hotCache = null,
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

        // Channel/store must be in the key: b2b display_type scopes hide retail PDP links.
        $storeId = 0;
        $channelId = 0;
        try {
            $storeId = max(0, RequestContext::getWelineStoreId());
            $channelId = max(0, RequestContext::getWelineChannelId());
        } catch (\Throwable) {
            // keep 0
        }
        $logicalKey = 'w' . $websiteId . ':s' . $storeId . ':c' . $channelId . ':l' . $limit . ':e' . $entityUuid;
        $hotCache = $this->hotCache();

        try {
            /** @var list<array<string,mixed>> $items */
            $items = $hotCache->rememberForRequest(
                self::CACHE_POOL . '.request',
                $logicalKey,
                fn(): array => $hotCache->remember(
                    self::CACHE_POOL,
                    $logicalKey,
                    self::FRESH_TTL_SECONDS,
                    fn(): array => $this->buildGalleryItems($limit, $websiteId, $entityUuid),
                    ['website' => true],
                    self::STALE_TTL_SECONDS,
                ),
            );
            if (\is_array($items)) {
                return $items;
            }
        } catch (\Throwable) {
            // Fail open to uncached build — storefront must still render.
        }

        return $this->buildGalleryItems($limit, $websiteId, $entityUuid);
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function buildGalleryItems(int $limit, int $websiteId, string $entityUuid): array
    {
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

        $candidateRows = [];
        $reviewIds = [];
        foreach ($rows as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $reviewId = (int)($row[ProductReview::schema_fields_ID] ?? 0);
            if ($reviewId <= 0) {
                continue;
            }
            $title = trim((string)($row[ProductReview::schema_fields_TITLE] ?? ''));
            $content = trim((string)($row[ProductReview::schema_fields_CONTENT] ?? ''));
            // 框架/E2E 验收夹具不得进买家秀图墙（会露出「[框架验收]…」与坏图）
            if (!$this->isStorefrontLooksSafe($title, $content)) {
                continue;
            }
            $candidateRows[] = $row;
            $reviewIds[] = $reviewId;
        }

        $mediaByReview = $this->media->forReviews($reviewIds);
        $entityUuids = [];
        foreach ($candidateRows as $row) {
            $uuid = trim((string)($row[ProductReview::schema_fields_ENTITY_UUID] ?? ''));
            if ($uuid !== '') {
                $entityUuids[$uuid] = $uuid;
            }
        }
        $productIdByUuid = $this->resolveStorefrontProductIds(\array_values($entityUuids), $websiteId);
        $sellableProductIds = $this->filterSellableProductIds(\array_values($productIdByUuid));

        $items = [];
        $seenImages = [];
        foreach ($candidateRows as $row) {
            $reviewId = (int)($row[ProductReview::schema_fields_ID] ?? 0);
            $mediaRows = $mediaByReview[$reviewId] ?? [];
            $imageUrl = '';
            foreach ($mediaRows as $mediaRow) {
                if (!\is_array($mediaRow)) {
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

            $rowEntityUuid = trim((string)($row[ProductReview::schema_fields_ENTITY_UUID] ?? ''));
            $title = trim((string)($row[ProductReview::schema_fields_TITLE] ?? ''));
            $content = trim((string)($row[ProductReview::schema_fields_CONTENT] ?? ''));
            if ($title === '') {
                $title = $this->truncate($content, 24);
            }
            if ($title === '') {
                $title = (string)__('买家秀');
            }

            // 店面 PDP 用 product_id（或 slug），不是 identity registry_id（entity_id）
            $productId = max(0, (int)($productIdByUuid[$rowEntityUuid] ?? 0));
            // b2b（及其它 catalog visibility）范围：不可售商品不进买家秀墙，避免露出零售 PDP。
            if ($productId > 0 && !isset($sellableProductIds[$productId])) {
                continue;
            }
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
                'entity_uuid' => $rowEntityUuid,
                'product_id' => $productId,
            ];
            if (\count($items) >= $limit) {
                break;
            }
        }

        return $items;
    }

    /**
     * @param list<int> $productIds
     * @return array<int, true>
     */
    private function filterSellableProductIds(array $productIds): array
    {
        $productIds = \array_values(\array_unique(\array_filter(
            \array_map('intval', $productIds),
            static fn(int $id): bool => $id > 0,
        )));
        if ($productIds === []) {
            return [];
        }
        if (!\class_exists(\Weline\Product\Service\StorefrontCatalogProductVisibility::class)) {
            return \array_fill_keys($productIds, true);
        }
        try {
            /** @var \Weline\Product\Service\StorefrontCatalogProductVisibility $gate */
            $gate = ObjectManager::getInstance(
                \Weline\Product\Service\StorefrontCatalogProductVisibility::class
            );
            $allowed = $gate->filterSellableProductIds($productIds);

            return \array_fill_keys($allowed, true);
        } catch (\Throwable) {
            // Fail closed: do not advertise retail PDP links when the gate cannot run.
            return [];
        }
    }

    /**
     * Map review entity_uuid (global_product_uuid) → storefront product_id.
     * Soft-deps Product; missing catalog keeps link empty (no wrong /product/{registry_id}).
     *
     * @param list<string> $entityUuids
     * @return array<string, int>
     */
    private function resolveStorefrontProductIds(array $entityUuids, int $websiteId): array
    {
        $normalized = [];
        foreach ($entityUuids as $uuid) {
            $uuid = trim((string)$uuid);
            if ($uuid !== '') {
                $normalized[$uuid] = $uuid;
            }
        }
        if ($normalized === [] || !\class_exists(\Weline\Product\Repository\ProductRepository::class)) {
            return [];
        }

        $out = [];
        try {
            /** @var \Weline\Product\Repository\ProductRepository $products */
            $products = ObjectManager::getInstance(\Weline\Product\Repository\ProductRepository::class);
            foreach ($normalized as $uuid) {
                // Never fall back to website 0 — that re-links grocery/other scopes
                // to default-site (汉服) PDP ids when Review rows somehow cross-bind.
                $product = $products->findByGlobalUuid($websiteId, $uuid);
                if ($product === null) {
                    continue;
                }
                $out[$uuid] = max(0, (int)$product->getId());
            }
        } catch (\Throwable) {
            return [];
        }

        return $out;
    }

    private function hotCache(): StorefrontScopeHotCache
    {
        return $this->hotCache ?? ObjectManager::getInstance(StorefrontScopeHotCache::class);
    }

    private function truncate(string $value, int $maxChars): string
    {
        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
        if ($value === '') {
            return '';
        }
        if (\function_exists('mb_strlen') && \function_exists('mb_substr')) {
            if (mb_strlen($value, 'UTF-8') <= $maxChars) {
                return $value;
            }

            return rtrim(mb_substr($value, 0, $maxChars, 'UTF-8')) . '…';
        }
        if (\strlen($value) <= $maxChars) {
            return $value;
        }

        return rtrim(\substr($value, 0, $maxChars)) . '…';
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
