<?php

declare(strict_types=1);

namespace Weline\Shipping\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestContext;
use Weline\SystemConfig\Model\SystemConfig;

/**
 * 地点跟随售卖双开关（范围继承）。
 */
class LocationSellConfig
{
    public const MODULE = 'Weline_Shipping';
    public const AREA = 'frontend';

    public const KEY_LOCATION_SELL_FILTER = 'shipping/storefront/location_sell_filter';
    public const KEY_SELL_ONLY_FULFILLMENT_COUNTRIES = 'shipping/storefront/sell_only_fulfillment_countries';

    public function isLocationSellFilterEnabled(?string $storageScope = null): bool
    {
        return $this->readBool(self::KEY_LOCATION_SELL_FILTER, $storageScope);
    }

    public function isSellOnlyFulfillmentCountriesEnabled(?string $storageScope = null): bool
    {
        return $this->readBool(self::KEY_SELL_ONLY_FULFILLMENT_COUNTRIES, $storageScope);
    }

    /** 任一开关开 → 车锁/税 origin/旁路拦截生效。 */
    public function isTradeGateActive(?string $storageScope = null): bool
    {
        return $this->isLocationSellFilterEnabled($storageScope)
            || $this->isSellOnlyFulfillmentCountriesEnabled($storageScope);
    }

    public function currentStorageScope(): string
    {
        try {
            $scope = RequestContext::scopeIdentity();
            if ($scope !== null) {
                $s = strtolower(trim($scope->toLegacyScopeString()));
                if ($s !== '') {
                    return $s;
                }
            }
        } catch (\Throwable) {
        }

        return 'default.default.default';
    }

    private function readBool(string $key, ?string $storageScope): bool
    {
        $scope = $storageScope !== null && trim($storageScope) !== ''
            ? strtolower(trim($storageScope))
            : $this->currentStorageScope();
        try {
            /** @var SystemConfig $config */
            $config = ObjectManager::getInstance(SystemConfig::class);
            $value = $config->getConfig($key, self::MODULE, self::AREA, '0', $scope);

            return (int)$value === 1 || $value === true || $value === '1' || $value === 'true';
        } catch (\Throwable) {
            return false;
        }
    }
}
