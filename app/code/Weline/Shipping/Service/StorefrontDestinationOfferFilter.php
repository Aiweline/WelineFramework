<?php

declare(strict_types=1);

namespace Weline\Shipping\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestContext;
use Weline\Shipping\Api\StorefrontDestinationOfferFilterInterface;

/**
 * 按履约仓国过滤 offer；非货源走仓国解析，货源另可叠 DropshipSellGate。
 */
final class StorefrontDestinationOfferFilter implements StorefrontDestinationOfferFilterInterface
{
    public function __construct(
        private readonly ObjectManager $objectManager,
        private readonly StorefrontOfferOriginCountryService $originCountryService,
        private readonly LocationSellConfig $locationSellConfig,
    ) {
    }

    public function filterSellableOfferIds(
        array $offerIds,
        string $fulfillmentCountryCode,
        int $websiteId = -1,
        int $storeId = -1,
    ): array {
        if (!$this->locationSellConfig->isLocationSellFilterEnabled()) {
            return array_values(array_unique(array_map('intval', $offerIds)));
        }
        $cc = StorefrontOfferOriginCountryService::normalizeCountryCode($fulfillmentCountryCode);
        if ($cc === '') {
            return [];
        }
        $websiteId = $websiteId >= 0 ? $websiteId : max(0, (int)RequestContext::getWelineWebsiteId());
        $storeId = $storeId >= 0 ? $storeId : max(0, (int)RequestContext::getWelineStoreId());
        $out = [];
        foreach ($offerIds as $id) {
            $offerId = (int)$id;
            if ($offerId > 0 && $this->isOfferSellable($offerId, $cc, $websiteId, $storeId)) {
                $out[] = $offerId;
            }
        }

        return $out;
    }

    public function isOfferSellable(
        int $offerId,
        string $fulfillmentCountryCode,
        int $websiteId = -1,
        int $storeId = -1,
    ): bool {
        $offerId = max(0, $offerId);
        if ($offerId <= 0) {
            return false;
        }
        $cc = StorefrontOfferOriginCountryService::normalizeCountryCode($fulfillmentCountryCode);
        if ($cc === '') {
            return false;
        }
        $websiteId = $websiteId >= 0 ? $websiteId : max(0, (int)RequestContext::getWelineWebsiteId());
        $storeId = $storeId >= 0 ? $storeId : max(0, (int)RequestContext::getWelineStoreId());

        if (!$this->passDropshipGate($offerId, $cc)) {
            return false;
        }

        $origin = $this->originCountryService->resolveForOffer($offerId, $websiteId, $storeId);

        return $origin !== '' && $origin === $cc;
    }

    private function passDropshipGate(int $offerId, string $fulfillmentCountryCode = ''): bool
    {
        if (!class_exists(\Weline\Dropship\Service\DropshipSellGate::class)) {
            return true;
        }
        try {
            /** @var \Weline\Dropship\Service\DropshipSellGate $gate */
            $gate = $this->objectManager->getInstance(\Weline\Dropship\Service\DropshipSellGate::class);
            $result = $gate->assertOfferSellable(
                $offerId,
                $this->locationSellConfig->currentStorageScope(),
                $fulfillmentCountryCode !== '' ? $fulfillmentCountryCode : null,
            );

            return !empty($result['ok']);
        } catch (\Throwable) {
            return true;
        }
    }
}
