<?php

declare(strict_types=1);

namespace Weline\Dropship\Service;

use Weline\Dropship\Model\DropshipListing;
use Weline\Framework\Manager\ObjectManager;

/**
 * 结账行 enrichment：把 Dropship listing.local_warehouse_id 写成 preferred_warehouse_id，
 * 供 Inventory FulfillmentSplitPlan 优先分仓（不绕过 Store 授权）。
 */
final class DropshipPreferredWarehouseBinder
{
    public function __construct(
        private readonly ObjectManager $objectManager,
    ) {
    }

    /**
     * @param list<array<string, mixed>> $lines
     * @return list<array<string, mixed>>
     */
    public function bind(array $lines, int $websiteId, int $storeId): array
    {
        $websiteId = max(0, $websiteId);
        $storeId = max(0, $storeId);
        if ($lines === []) {
            return $lines;
        }

        $offerIds = [];
        foreach ($lines as $line) {
            if (!is_array($line)) {
                continue;
            }
            $offerId = (int)($line['offer_id'] ?? $line['product_offer_id'] ?? 0);
            if ($offerId > 0) {
                $offerIds[$offerId] = true;
            }
        }
        if ($offerIds === []) {
            return $lines;
        }

        $map = $this->loadPreferredByOffer(array_keys($offerIds), $websiteId, $storeId);
        if ($map === []) {
            return $lines;
        }

        $out = [];
        foreach ($lines as $line) {
            if (!is_array($line)) {
                continue;
            }
            $offerId = (int)($line['offer_id'] ?? $line['product_offer_id'] ?? 0);
            $preferred = (int)($map[$offerId] ?? 0);
            if ($preferred > 0) {
                $existing = (int)($line['preferred_warehouse_id'] ?? $line['warehouse_id'] ?? 0);
                if ($existing <= 0) {
                    $line['preferred_warehouse_id'] = $preferred;
                }
            }
            $out[] = $line;
        }

        return $out;
    }

    /**
     * @param list<int> $offerIds
     * @return array<int, int> offer_id => warehouse_id
     */
    private function loadPreferredByOffer(array $offerIds, int $websiteId, int $storeId): array
    {
        /** @var DropshipListing $model */
        $model = $this->objectManager->getInstance(DropshipListing::class, [], false);
        $items = $model->reset()
            ->where(DropshipListing::schema_fields_WEBSITE_ID, $websiteId)
            ->where(DropshipListing::schema_fields_STORE_ID, $storeId)
            ->where(DropshipListing::schema_fields_LOCAL_OFFER_ID, $offerIds, 'IN')
            ->select()
            ->fetch()
            ->getItems();

        $map = [];
        if (!is_array($items)) {
            return $map;
        }
        foreach ($items as $item) {
            if (!$item instanceof DropshipListing) {
                continue;
            }
            $offerId = (int)$item->getData(DropshipListing::schema_fields_LOCAL_OFFER_ID);
            $wid = (int)$item->getData(DropshipListing::schema_fields_LOCAL_WAREHOUSE_ID);
            if ($offerId > 0 && $wid > 0) {
                $map[$offerId] = $wid;
            }
        }

        return $map;
    }
}
