<?php

declare(strict_types=1);

namespace Weline\Shipping\Api;

/**
 * Dropship / Inventory 等贡献「有可售 offer 的履约仓国」。
 */
interface StorefrontFulfillmentCountriesContributorInterface
{
    /**
     * @return list<string> ISO-2
     */
    public function listCountryCodes(int $websiteId, int $storeId): array;
}
