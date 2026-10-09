<?php

declare(strict_types=1);

namespace Weline\Shipping\Service;

use Weline\Framework\Runtime\RequestContext;
use Weline\Shipping\Api\StorefrontDestinationOfferFilterInterface;
use Weline\Shipping\Api\StorefrontFulfillmentCountriesInterface;

/**
 * 地点售卖交易闸：车锁仓国、写国拦截、结账断言。失败时提示改地址。
 */
final class LocationSellGate
{
    public const ERROR_CROSS_FULFILLMENT_COUNTRY = 'cart_cross_fulfillment_country_forbidden';
    public const ERROR_ITEM_NOT_SELLABLE = 'cart_item_not_sellable';
    public const ERROR_ADDRESS_NOT_FULFILLABLE = 'location_address_not_fulfillable';
    public const ERROR_CHANGE_BLOCKED_CART_LOCK = 'location_change_blocked_cart_lock';
    public const ERROR_COUNTRIES_EMPTY = 'fulfillment_countries_empty';
    public const ERROR_CROSS_SPLIT = 'checkout_cross_fulfillment_country_split_forbidden';

    private readonly StorefrontDestinationOfferFilterInterface $offerFilter;
    private readonly StorefrontFulfillmentCountriesInterface $fulfillmentCountries;

    public function __construct(
        private readonly LocationSellConfig $config,
        private readonly DestinationCountryReader $destinationReader,
        private readonly StorefrontOfferOriginCountryService $originCountryService,
        ?StorefrontDestinationOfferFilterInterface $offerFilter = null,
        ?StorefrontFulfillmentCountriesInterface $fulfillmentCountries = null,
    ) {
        // 具体类可 DI；接口需 provides——未升级时回退具体实现，避免 ObjectManager 无法构造。
        $this->offerFilter = $offerFilter ?? new StorefrontDestinationOfferFilter(
            \Weline\Framework\Manager\ObjectManager::getInstance(),
            $originCountryService,
            $config,
        );
        $this->fulfillmentCountries = $fulfillmentCountries
            ?? new StorefrontFulfillmentCountriesService(
                \Weline\Framework\Manager\ObjectManager::getInstance()
            );
    }

    public function isActive(?string $storageScope = null): bool
    {
        return $this->config->isTradeGateActive($storageScope);
    }

    public function currentDeliveryCountry(): string
    {
        return $this->destinationReader->currentCountryCode();
    }

    /**
     * @return list<string>
     */
    public function allowedFulfillmentCountries(int $websiteId = -1, int $storeId = -1): array
    {
        return $this->fulfillmentCountries->listCountryCodes($websiteId, $storeId);
    }

    /**
     * @return array{ok:bool,error_code?:string,message?:string,allowed?:list<string>,lock_country?:string}
     */
    public function assertCountryWritable(string $countryCode, ?string $cartLockCountry = null): array
    {
        if (!$this->config->isSellOnlyFulfillmentCountriesEnabled() && !$this->config->isTradeGateActive()) {
            return ['ok' => true];
        }
        $cc = StorefrontOfferOriginCountryService::normalizeCountryCode($countryCode);
        if ($cc === '') {
            return [
                'ok' => false,
                'error_code' => self::ERROR_ADDRESS_NOT_FULFILLABLE,
                'message' => (string)\__('请修改收货地址'),
            ];
        }

        if ($this->config->isSellOnlyFulfillmentCountriesEnabled()) {
            $allowed = $this->allowedFulfillmentCountries();
            if ($allowed === []) {
                return [
                    'ok' => false,
                    'error_code' => self::ERROR_COUNTRIES_EMPTY,
                    'message' => (string)\__('暂无可售国家，请稍后重试或联系客服'),
                    'allowed' => [],
                ];
            }
            if (!in_array($cc, $allowed, true)) {
                return [
                    'ok' => false,
                    'error_code' => self::ERROR_ADDRESS_NOT_FULFILLABLE,
                    'message' => (string)\__('请修改收货地址到可发货国家'),
                    'allowed' => $allowed,
                ];
            }
        }

        $lock = StorefrontOfferOriginCountryService::normalizeCountryCode((string)$cartLockCountry);
        if ($lock !== '' && $lock !== $cc) {
            return [
                'ok' => false,
                'error_code' => self::ERROR_CHANGE_BLOCKED_CART_LOCK,
                'message' => (string)\__(
                    '购物车商品发货国家为 %1，请将地址改回该国，或先结算/清空后再换地',
                    $lock
                ),
                'lock_country' => $lock,
            ];
        }

        return ['ok' => true];
    }

    /**
     * @return array{ok:bool,error_code?:string,message?:string,fulfillment_country?:string}
     */
    public function assertOfferAddable(int $offerId, ?string $cartLockCountry = null): array
    {
        if (!$this->isActive()) {
            return ['ok' => true];
        }
        $websiteId = max(0, (int)RequestContext::getWelineWebsiteId());
        $storeId = max(0, (int)RequestContext::getWelineStoreId());
        $delivery = $this->currentDeliveryCountry();
        if ($delivery === '') {
            return [
                'ok' => false,
                'error_code' => self::ERROR_ADDRESS_NOT_FULFILLABLE,
                'message' => (string)\__('请先选择配送国家'),
            ];
        }

        $write = $this->assertCountryWritable($delivery, null);
        if (empty($write['ok'])) {
            return $write;
        }

        $origin = $this->originCountryService->resolveForOffer($offerId, $websiteId, $storeId);
        if ($origin === '') {
            return [
                'ok' => false,
                'error_code' => self::ERROR_ITEM_NOT_SELLABLE,
                'message' => (string)\__('该商品暂不可在当前售卖地发货'),
            ];
        }
        if ($origin !== $delivery) {
            return [
                'ok' => false,
                'error_code' => self::ERROR_ITEM_NOT_SELLABLE,
                'message' => (string)\__('该商品暂不可在当前售卖地发货，请修改收货地址或更换商品'),
                'fulfillment_country' => $origin,
            ];
        }

        if ($this->config->isLocationSellFilterEnabled()
            && !$this->offerFilter->isOfferSellable($offerId, $delivery, $websiteId, $storeId)
        ) {
            return [
                'ok' => false,
                'error_code' => self::ERROR_ITEM_NOT_SELLABLE,
                'message' => (string)\__('该商品暂不可在当前售卖地发货'),
                'fulfillment_country' => $origin,
            ];
        }

        $lock = StorefrontOfferOriginCountryService::normalizeCountryCode((string)$cartLockCountry);
        if ($lock !== '' && $lock !== $origin) {
            return [
                'ok' => false,
                'error_code' => self::ERROR_CROSS_FULFILLMENT_COUNTRY,
                'message' => (string)\__('购物车已有其他发货国家的商品，请先结算或清空后再加'),
                'fulfillment_country' => $origin,
                'lock_country' => $lock,
            ];
        }

        return ['ok' => true, 'fulfillment_country' => $origin];
    }

    /**
     * @param list<string> $packageCountries
     * @return array{ok:bool,error_code?:string,message?:string}
     */
    public function assertCheckoutPackages(array $packageCountries, ?string $cartLockCountry = null): array
    {
        if (!$this->isActive()) {
            return ['ok' => true];
        }
        $delivery = $this->currentDeliveryCountry();
        $normalized = [];
        foreach ($packageCountries as $c) {
            $cc = StorefrontOfferOriginCountryService::normalizeCountryCode((string)$c);
            if ($cc !== '') {
                $normalized[$cc] = true;
            }
        }
        $countries = array_keys($normalized);
        if (count($countries) > 1) {
            return [
                'ok' => false,
                'error_code' => self::ERROR_CROSS_SPLIT,
                'message' => (string)\__('订单含多个发货国家，请修改收货地址或拆分结算'),
            ];
        }
        $only = $countries[0] ?? '';
        $lock = StorefrontOfferOriginCountryService::normalizeCountryCode((string)$cartLockCountry);
        $expect = $lock !== '' ? $lock : $delivery;
        if ($only === '' || ($expect !== '' && $only !== $expect)) {
            return [
                'ok' => false,
                'error_code' => self::ERROR_CROSS_SPLIT,
                'message' => (string)\__('发货国家与收货地址不一致，请修改收货地址'),
            ];
        }
        if ($delivery !== '' && $only !== $delivery) {
            return [
                'ok' => false,
                'error_code' => self::ERROR_ADDRESS_NOT_FULFILLABLE,
                'message' => (string)\__('请修改收货地址'),
            ];
        }

        return ['ok' => true];
    }

    public function resolveOfferFulfillmentCountry(int $offerId): string
    {
        $websiteId = max(0, (int)RequestContext::getWelineWebsiteId());
        $storeId = max(0, (int)RequestContext::getWelineStoreId());

        return $this->originCountryService->resolveForOffer($offerId, $websiteId, $storeId);
    }
}
