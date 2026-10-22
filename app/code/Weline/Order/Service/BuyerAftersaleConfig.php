<?php

declare(strict_types=1);

namespace Weline\Order\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestContext;
use Weline\SystemConfig\Model\SystemConfig;

/**
 * 店面买家售后双开关（范围继承）。读失败视为关。
 */
class BuyerAftersaleConfig
{
    public const MODULE = 'Weline_Order';
    public const AREA = 'frontend';

    public const KEY_BUYER_AFTERSALE_ENABLED = 'order/storefront/buyer_aftersale_enabled';
    public const KEY_BUYER_AFTERSALE_LINK_PAYMENT = 'order/storefront/buyer_aftersale_link_payment';
    public const KEY_RETURN_ADDRESS_CUSTOM_ENABLED = 'order/storefront/buyer_aftersale_return_address_custom_enabled';
    public const KEY_RETURN_ADDRESS_CUSTOM = 'order/storefront/buyer_aftersale_return_address';

    public function isBuyerAftersaleEnabled(?string $storageScope = null): bool
    {
        return $this->readBool(self::KEY_BUYER_AFTERSALE_ENABLED, $storageScope);
    }

    public function isLinkPaymentEnabled(?string $storageScope = null): bool
    {
        return $this->readBool(self::KEY_BUYER_AFTERSALE_LINK_PAYMENT, $storageScope);
    }

    public function isReturnAddressCustomEnabled(?string $storageScope = null): bool
    {
        return $this->readBool(self::KEY_RETURN_ADDRESS_CUSTOM_ENABLED, $storageScope);
    }

    /** 自定义开关关闭，或读失败时，当作没有自定义地址。 */
    public function customReturnAddress(?string $storageScope = null): string
    {
        if (!$this->isReturnAddressCustomEnabled($storageScope)) {
            return '';
        }
        $scope = $storageScope !== null && trim($storageScope) !== ''
            ? strtolower(trim($storageScope))
            : $this->currentStorageScope();
        try {
            /** @var SystemConfig $config */
            $config = ObjectManager::getInstance(SystemConfig::class);
            $value = $config->getConfig(self::KEY_RETURN_ADDRESS_CUSTOM, self::MODULE, self::AREA, '', $scope);

            return trim((string)$value);
        } catch (\Throwable) {
            return '';
        }
    }

    /** 总闸与支付联动闸都开，才允许派发 link_payment。 */
    public function isPaymentLinkActive(?string $storageScope = null): bool
    {
        return $this->isBuyerAftersaleEnabled($storageScope)
            && $this->isLinkPaymentEnabled($storageScope);
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
