<?php

declare(strict_types=1);

namespace Weline\Inventory\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Inventory\Model\Warehouse;
use Weline\Inventory\Model\WarehouseQuota;
use Weline\Shipping\Api\StorefrontFulfillmentCountriesContributorInterface;
use Weline\Shipping\Service\StorefrontOfferOriginCountryService;

/**
 * 贡献：有正可售配额的叶子仓对应 ISO-2 国码（尽力查询；失败则回退本站已用仓国）。
 */
final class InventoryFulfillmentCountriesContributor implements StorefrontFulfillmentCountriesContributorInterface
{
    public function listCountryCodes(int $websiteId, int $storeId): array
    {
        $websiteId = max(0, $websiteId);
        unset($storeId); // 配额按 website；store 预留

        $fromQuota = $this->countriesFromPositiveQuotaLeaves($websiteId);
        if ($fromQuota !== []) {
            return $fromQuota;
        }

        return $this->countriesFromWebsiteWarehouses($websiteId);
    }

    /**
     * @return list<string>
     */
    private function countriesFromPositiveQuotaLeaves(int $websiteId): array
    {
        try {
            /** @var WarehouseQuota $quota */
            $quota = ObjectManager::getInstance(WarehouseQuota::class);
            $quotaRows = $quota->clear()
                ->where(WarehouseQuota::schema_fields_WEBSITE_ID, $websiteId)
                ->where(WarehouseQuota::schema_fields_QTY_MINOR, 0, '>')
                ->select()
                ->fetchArray();
            $warehouseIds = [];
            foreach ((array)$quotaRows as $row) {
                if (!\is_array($row)) {
                    continue;
                }
                $wid = (int)($row[WarehouseQuota::schema_fields_WAREHOUSE_ID] ?? 0);
                if ($wid > 0) {
                    $warehouseIds[$wid] = true;
                }
            }
            if ($warehouseIds === []) {
                return [];
            }

            /** @var Warehouse $warehouse */
            $warehouse = ObjectManager::getInstance(Warehouse::class);
            $rows = $warehouse->clear()
                ->where(Warehouse::schema_fields_WEBSITE_ID, $websiteId)
                ->where(Warehouse::schema_fields_ID, array_keys($warehouseIds), 'IN')
                ->where(Warehouse::schema_fields_NODE_KIND, Warehouse::NODE_WAREHOUSE)
                ->where(Warehouse::schema_fields_ENABLED, 1)
                ->select()
                ->fetchArray();

            return $this->uniqueCountryCodes((array)$rows);
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @return list<string>
     */
    private function countriesFromWebsiteWarehouses(int $websiteId): array
    {
        try {
            /** @var Warehouse $warehouse */
            $warehouse = ObjectManager::getInstance(Warehouse::class);
            $rows = $warehouse->clear()
                ->where(Warehouse::schema_fields_WEBSITE_ID, $websiteId)
                ->where(Warehouse::schema_fields_NODE_KIND, Warehouse::NODE_WAREHOUSE)
                ->where(Warehouse::schema_fields_ENABLED, 1)
                ->where(Warehouse::schema_fields_COUNTRY_CODE, '', 'neq')
                ->select()
                ->fetchArray();

            return $this->uniqueCountryCodes((array)$rows);
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @param list<mixed> $rows
     * @return list<string>
     */
    private function uniqueCountryCodes(array $rows): array
    {
        $set = [];
        foreach ($rows as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $cc = StorefrontOfferOriginCountryService::normalizeCountryCode(
                (string)($row[Warehouse::schema_fields_COUNTRY_CODE] ?? '')
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
