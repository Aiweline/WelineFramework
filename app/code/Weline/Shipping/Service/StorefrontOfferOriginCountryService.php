<?php

declare(strict_types=1);

namespace Weline\Shipping\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestContext;
use Weline\Inventory\Api\DefaultWarehouseResolverInterface;
use Weline\Inventory\Api\FulfillmentSplitPlanInterface;
use Weline\Inventory\Model\Warehouse;
use Weline\Shipping\Api\WarehouseShippingOriginInterface;
use Weline\Shipping\Model\ShippingAddress;

/**
 * PDP 配送徽章用：解析 Offer 履约仓发货国（ISO-2 事实，不算徽章文案）。
 */
final class StorefrontOfferOriginCountryService
{
    public function __construct(
        private readonly ObjectManager $objectManager,
    ) {
    }

    public function resolveForOffer(int $offerId, int $websiteId = -1, int $storeId = -1, int $qty = 1): string
    {
        $offerId = max(0, $offerId);
        $websiteId = $websiteId >= 0 ? $websiteId : max(0, (int)RequestContext::getWelineWebsiteId());
        $storeId = $storeId >= 0 ? $storeId : max(0, (int)RequestContext::getWelineStoreId());
        $qty = max(1, $qty);

        $warehouseId = $this->resolveWarehouseId($websiteId, $storeId, $offerId, $qty);
        if ($warehouseId <= 0) {
            return '';
        }

        $fromOrigin = $this->countryFromShippingOrigin($websiteId, $warehouseId);
        if ($fromOrigin !== '') {
            return $fromOrigin;
        }

        return $this->countryFromWarehouseTree($warehouseId);
    }

    private function resolveWarehouseId(int $websiteId, int $storeId, int $offerId, int $qty): int
    {
        if (!interface_exists(FulfillmentSplitPlanInterface::class)) {
            return $this->defaultWarehouseId($websiteId, $storeId);
        }

        try {
            /** @var FulfillmentSplitPlanInterface $plan */
            $plan = $this->objectManager->getInstance(FulfillmentSplitPlanInterface::class);
            $packages = $plan->planPackages($websiteId, $storeId, [[
                'offer_id' => $offerId,
                'qty' => $qty,
                'requires_shipping' => true,
            ]]);
            $first = is_array($packages[0] ?? null) ? $packages[0] : null;
            $warehouseId = is_array($first) ? (int)($first['warehouse_id'] ?? 0) : 0;
            if ($warehouseId > 0) {
                return $warehouseId;
            }
        } catch (\Throwable) {
            // 分仓不可用时回退默认逻辑仓
        }

        return $this->defaultWarehouseId($websiteId, $storeId);
    }

    private function defaultWarehouseId(int $websiteId, int $storeId): int
    {
        if (!interface_exists(DefaultWarehouseResolverInterface::class)) {
            return 0;
        }
        try {
            /** @var DefaultWarehouseResolverInterface $resolver */
            $resolver = $this->objectManager->getInstance(DefaultWarehouseResolverInterface::class);
            $assignment = $resolver->resolveDefault($websiteId, $storeId);

            return max(0, (int)$assignment->warehouseId);
        } catch (\Throwable) {
            return 0;
        }
    }

    private function countryFromShippingOrigin(int $websiteId, int $warehouseId): string
    {
        try {
            /** @var WarehouseShippingOriginInterface $origins */
            $origins = $this->objectManager->getInstance(WarehouseShippingOriginInterface::class);
            $addressId = (int)($origins->findShippingAddressId($websiteId, $warehouseId) ?? 0);
            if ($addressId <= 0) {
                return '';
            }
            /** @var ShippingAddress $address */
            $address = $this->objectManager->getInstance(ShippingAddress::class, [], false)->load($addressId);
            if (!(int)$address->getId()) {
                return '';
            }

            return self::normalizeCountryCode((string)$address->getData(ShippingAddress::schema_fields_COUNTRY_CODE));
        } catch (\Throwable) {
            return '';
        }
    }

    private function countryFromWarehouseTree(int $warehouseId): string
    {
        if (!class_exists(Warehouse::class)) {
            return '';
        }
        try {
            /** @var Warehouse $warehouse */
            $warehouse = $this->objectManager->getInstance(Warehouse::class, [], false)->load($warehouseId);
            if (!(int)$warehouse->getId()) {
                return '';
            }

            return self::normalizeCountryCode((string)$warehouse->getData(Warehouse::schema_fields_COUNTRY_CODE));
        } catch (\Throwable) {
            return '';
        }
    }

    public static function normalizeCountryCode(string $raw): string
    {
        $code = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $raw) ?? '', 0, 2));

        return strlen($code) === 2 ? $code : '';
    }
}
