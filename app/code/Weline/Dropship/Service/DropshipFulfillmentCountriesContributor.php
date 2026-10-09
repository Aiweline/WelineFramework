<?php

declare(strict_types=1);

namespace Weline\Dropship\Service;

use Weline\Dropship\Model\DropshipListing;
use Weline\Framework\Manager\ObjectManager;
use Weline\Shipping\Api\StorefrontFulfillmentCountriesContributorInterface;
use Weline\Shipping\Service\StorefrontOfferOriginCountryService;

/**
 * 贡献：本站店有 active 代发 listing 且 remote_country 非空的履约仓国。
 */
final class DropshipFulfillmentCountriesContributor implements StorefrontFulfillmentCountriesContributorInterface
{
    public function listCountryCodes(int $websiteId, int $storeId): array
    {
        $websiteId = max(0, $websiteId);
        $storeId = max(0, $storeId);
        try {
            /** @var DropshipListing $model */
            $model = ObjectManager::getInstance(DropshipListing::class);
            $query = $model->clear()
                ->where(DropshipListing::schema_fields_WEBSITE_ID, $websiteId)
                ->where(DropshipListing::schema_fields_SYNC_STATUS, DropshipListing::STATUS_ACTIVE)
                ->where(DropshipListing::schema_fields_REMOTE_COUNTRY, '', '!=');
            if ($storeId > 0) {
                $query->where(DropshipListing::schema_fields_STORE_ID, $storeId);
            }
            $rows = $query->select()->fetchArray();
        } catch (\Throwable) {
            return [];
        }

        $set = [];
        foreach ((array)$rows as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $cc = StorefrontOfferOriginCountryService::normalizeCountryCode(
                (string)($row[DropshipListing::schema_fields_REMOTE_COUNTRY] ?? '')
            );
            if ($cc !== '') {
                $set[$cc] = true;
            }
        }
        $list = array_keys($set);
        sort($list);

        return $list;
    }
}
