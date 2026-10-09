<?php

declare(strict_types=1);

namespace Weline\Product\Api;

/**
 * Post-cache storefront catalog visibility filter (Product-owned SPI).
 *
 * Aligns with Shipping StorefrontDestinationOfferFilterInterface shape.
 * Contributors may hide offers for the current Website/Store/Channel scope.
 */
interface StorefrontCatalogVisibilityFilterInterface
{
    /**
     * @param list<int> $offerIds
     * @return list<int>
     */
    public function filterSellableOfferIds(
        array $offerIds,
        int $websiteId,
        int $storeId = 0,
        int $channelId = 0,
    ): array;

    public function isOfferSellable(
        int $offerId,
        int $websiteId,
        int $storeId = 0,
        int $channelId = 0,
    ): bool;
}
