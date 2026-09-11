<?php

declare(strict_types=1);

namespace Weline\Dropship\Service;

use Weline\Dropship\Api\Data\DropshipCatalogSnapshot;
use Weline\Dropship\Interface\DropshipCatalogProviderInterface;
use Weline\Dropship\Model\DropshipListing;
use Weline\Framework\Manager\ObjectManager;
use Weline\Inventory\Service\InventoryService;

class DropshipFollowService
{
    public function __construct(
        private readonly DropshipChannelManager $channels,
        private readonly DropshipPricingService $pricing,
        private readonly DropshipSettings $settings,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function syncListing(int $listingId, string $storageScope = 'default.default.default'): array
    {
        if (!$this->settings->followEnabled($storageScope)) {
            return ['ok' => false, 'message' => 'follow_disabled'];
        }

        /** @var DropshipListing $model */
        $model = ObjectManager::getInstance(DropshipListing::class);
        $listing = $model->clear()->where(DropshipListing::schema_fields_ID, $listingId)->find()->fetch();
        if (!$listing || !$listing->getId()) {
            return ['ok' => false, 'message' => 'listing_not_found'];
        }

        $providerCode = (string)$listing->getData(DropshipListing::schema_fields_PROVIDER_CODE);
        $provider = $this->channels->getProvider($providerCode);
        if (!$provider instanceof DropshipCatalogProviderInterface) {
            return ['ok' => false, 'message' => 'provider_no_catalog'];
        }

        $snapshot = $provider->syncCatalogSnapshot($listing->getData());
        if (!$snapshot instanceof DropshipCatalogSnapshot) {
            return ['ok' => false, 'message' => 'snapshot_empty'];
        }

        $uplift = $this->settings->upliftPercent($storageScope);
        $prev = (int)$listing->getData(DropshipListing::schema_fields_ORIGIN_PRICE_MINOR);
        $sale = (int)$listing->getData(DropshipListing::schema_fields_SALE_PRICE_MINOR);
        $lock = (int)$listing->getData(DropshipListing::schema_fields_PRICE_LOCK) === 1;
        $price = $this->pricing->applyRemoteOrigin($prev, $snapshot->originPriceMinor, $sale, $uplift, $lock);

        $data = [
            DropshipListing::schema_fields_ORIGIN_PRICE_PREV_MINOR => $price['origin_prev'],
            DropshipListing::schema_fields_ORIGIN_PRICE_MINOR => $price['origin'],
            DropshipListing::schema_fields_ORIGIN_CURRENCY => $snapshot->originCurrency,
            DropshipListing::schema_fields_PRICE_DIRECTION => $price['direction'],
            DropshipListing::schema_fields_PRICE_DROP_TIP => $price['tip'],
            DropshipListing::schema_fields_REMOTE_QTY => $snapshot->qty,
            DropshipListing::schema_fields_UPLIFT_PERCENT => $uplift,
            DropshipListing::schema_fields_LAST_SYNCED_AT => date('Y-m-d H:i:s'),
            DropshipListing::schema_fields_UPDATED_AT => date('Y-m-d H:i:s'),
        ];
        if ($price['sale_minor'] !== null) {
            $data[DropshipListing::schema_fields_SALE_PRICE_MINOR] = $price['sale_minor'];
        }
        if ($snapshot->shelfStatus === 'delisted' || $snapshot->qty <= 0) {
            $data[DropshipListing::schema_fields_SYNC_STATUS] = DropshipListing::STATUS_DISABLED;
        } else {
            $data[DropshipListing::schema_fields_SYNC_STATUS] = DropshipListing::STATUS_ACTIVE;
        }

        $listing->setData($data)->save();
        $this->applyInventoryProjection($listing, $snapshot->qty);

        return ['ok' => true, 'listing_id' => $listingId, 'price' => $price, 'qty' => $snapshot->qty];
    }

    private function applyInventoryProjection(mixed $listing, int $qty): void
    {
        $offerId = (int)$listing->getData(DropshipListing::schema_fields_LOCAL_OFFER_ID);
        if ($offerId <= 0 || !class_exists(InventoryService::class)) {
            return;
        }
        try {
            $websiteId = (int)$listing->getData(DropshipListing::schema_fields_WEBSITE_ID);
            $storeId = (int)$listing->getData(DropshipListing::schema_fields_STORE_ID);
            $key = 'dropship:onhand:' . $listing->getId() . ':' . $qty . ':' . date('YmdHi');
            /** @var InventoryService $inventory */
            $inventory = ObjectManager::getInstance(InventoryService::class);
            $inventory->setOnHand(
                $websiteId,
                $storeId,
                $offerId,
                max(0, $qty),
                $key,
                hash('sha256', $key),
            );
        } catch (\Throwable $e) {
            w_log_error('dropship setOnHand failed: ' . $e->getMessage());
        }
    }
}
