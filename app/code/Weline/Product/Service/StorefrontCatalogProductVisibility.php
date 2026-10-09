<?php

declare(strict_types=1);

namespace Weline\Product\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestContext;
use Weline\Product\Api\StorefrontCatalogVisibilityFilterInterface;

/**
 * Product-id gate for storefront widgets that do not already load via catalog offers.
 *
 * Prefer {@see StorefrontCatalogViewService} / {@see StorefrontProductWidgetCatalog}
 * for shelves; use this when only product_id / PDP links are known (buyer looks, CTAs).
 */
final class StorefrontCatalogProductVisibility
{
    public function __construct(
        private readonly ?StorefrontCatalogViewService $catalog = null,
    ) {
    }

    /**
     * @param list<int> $productIds
     * @return list<int>
     */
    public function filterSellableProductIds(
        array $productIds,
        int $websiteId = -1,
        int $storeId = -1,
        int $channelId = -1,
    ): array {
        $productIds = array_values(array_unique(array_filter(
            array_map('intval', $productIds),
            static fn(int $id): bool => $id > 0,
        )));
        if ($productIds === []) {
            return [];
        }

        $websiteId = $websiteId >= 0 ? $websiteId : max(0, RequestContext::getWelineWebsiteId());
        $storeId = $storeId >= 0 ? $storeId : max(0, RequestContext::getWelineStoreId());
        $channelId = $channelId >= 0 ? $channelId : max(0, RequestContext::getWelineChannelId());

        try {
            $catalog = $this->catalog();
            if ($catalog === null) {
                return $productIds;
            }
            $offers = $catalog->publishedOffersForProductIds(
                $productIds,
                max(count($productIds) * 4, 16),
                false,
            );
            $allowed = [];
            foreach ($offers as $offer) {
                if (!is_array($offer)) {
                    continue;
                }
                $pid = (int)($offer['product_id'] ?? 0);
                if ($pid > 0) {
                    $allowed[$pid] = true;
                }
            }

            // publishedOffersForProductIds already applies catalog visibility SPI.
            // Keep order of the caller list.
            $out = [];
            foreach ($productIds as $productId) {
                if (isset($allowed[$productId])) {
                    $out[] = $productId;
                }
            }

            return $out;
        } catch (\Throwable) {
            // Fail closed when a visibility filter is registered for this request.
            if ($this->hasVisibilityFilter()) {
                return [];
            }

            return $productIds;
        }
    }

    public function isProductSellable(
        int $productId,
        int $websiteId = -1,
        int $storeId = -1,
        int $channelId = -1,
    ): bool {
        if ($productId <= 0) {
            return false;
        }

        return in_array(
            $productId,
            $this->filterSellableProductIds([$productId], $websiteId, $storeId, $channelId),
            true,
        );
    }

    private function catalog(): ?StorefrontCatalogViewService
    {
        if ($this->catalog instanceof StorefrontCatalogViewService) {
            return $this->catalog;
        }
        try {
            $catalog = ObjectManager::getInstance(StorefrontCatalogViewService::class);

            return $catalog instanceof StorefrontCatalogViewService ? $catalog : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function hasVisibilityFilter(): bool
    {
        if (!interface_exists(StorefrontCatalogVisibilityFilterInterface::class)) {
            return false;
        }
        try {
            $filter = ObjectManager::getInstance(StorefrontCatalogVisibilityFilterInterface::class);

            return $filter instanceof StorefrontCatalogVisibilityFilterInterface;
        } catch (\Throwable) {
            return false;
        }
    }
}
