<?php

declare(strict_types=1);

namespace Weline\Dropship\Service;

use Weline\Dropship\Model\DropshipListing;
use Weline\Framework\Manager\ObjectManager;
use Weline\Shipping\Service\LocationSellConfig;
use Weline\Shipping\Service\StorefrontOfferOriginCountryService;

/**
 * Trade gate: dropship offers must have active listing + enabled platform + warehouse map.
 */
class DropshipSellGate
{
    public function __construct(
        private readonly DropshipSettings $settings,
        private readonly DropshipWarehouseMapService $warehouseMap,
    ) {
    }

    /**
     * @return array{ok:bool,reason?:string}
     */
    public function assertOfferSellable(
        int $offerId,
        string $storageScope = 'default.default.default',
        ?string $fulfillmentCountry = null,
    ): array {
        if ($offerId <= 0) {
            return ['ok' => true];
        }
        /** @var DropshipListing $model */
        $model = ObjectManager::getInstance(DropshipListing::class);
        $listing = $model->clear()
            ->where(DropshipListing::schema_fields_LOCAL_OFFER_ID, $offerId)
            ->find()
            ->fetch();
        if (!$listing || !$listing->getId()) {
            return ['ok' => true];
        }
        $provider = (string)$listing->getData(DropshipListing::schema_fields_PROVIDER_CODE);
        if (!$this->settings->isPlatformEnabled($provider, $storageScope)) {
            return ['ok' => false, 'reason' => 'dropship_platform_disabled:' . $provider];
        }
        if ((string)$listing->getData(DropshipListing::schema_fields_SYNC_STATUS) === DropshipListing::STATUS_DISABLED) {
            return ['ok' => false, 'reason' => 'dropship_listing_disabled'];
        }
        try {
            $this->warehouseMap->resolve(
                $provider,
                (int)$listing->getData(DropshipListing::schema_fields_WEBSITE_ID),
                (int)$listing->getData(DropshipListing::schema_fields_STORE_ID),
            );
        } catch (\Throwable $e) {
            return ['ok' => false, 'reason' => $e->getMessage()];
        }

        $wantCountry = StorefrontOfferOriginCountryService::normalizeCountryCode((string)$fulfillmentCountry);
        if ($wantCountry !== '' && $this->locationSellCountryGateActive($storageScope)) {
            $remote = StorefrontOfferOriginCountryService::normalizeCountryCode(
                (string)$listing->getData(DropshipListing::schema_fields_REMOTE_COUNTRY)
            );
            if ($remote === '' || $remote !== $wantCountry) {
                return ['ok' => false, 'reason' => 'dropship_remote_country_mismatch:' . $wantCountry];
            }
        }

        return ['ok' => true];
    }

    private function locationSellCountryGateActive(string $storageScope): bool
    {
        if (!class_exists(LocationSellConfig::class)) {
            return false;
        }
        try {
            /** @var LocationSellConfig $config */
            $config = ObjectManager::getInstance(LocationSellConfig::class);

            return $config->isLocationSellFilterEnabled($storageScope)
                || $config->isTradeGateActive($storageScope);
        } catch (\Throwable) {
            return false;
        }
    }
}
