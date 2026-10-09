<?php

declare(strict_types=1);

namespace Weline\Shipping\Api;

/**
 * 开关①：按履约仓国过滤可售 offer（分页前 / 回填前）。
 */
interface StorefrontDestinationOfferFilterInterface
{
    /**
     * @param list<int> $offerIds
     * @return list<int>
     */
    public function filterSellableOfferIds(
        array $offerIds,
        string $fulfillmentCountryCode,
        int $websiteId = -1,
        int $storeId = -1,
    ): array;

    public function isOfferSellable(
        int $offerId,
        string $fulfillmentCountryCode,
        int $websiteId = -1,
        int $storeId = -1,
    ): bool;
}
