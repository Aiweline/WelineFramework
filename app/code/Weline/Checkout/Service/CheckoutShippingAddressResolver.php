<?php

declare(strict_types=1);

namespace Weline\Checkout\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Shipping\Model\DeliveryAddress;
use Weline\Shipping\Service\DeliveryAddressService;

/**
 * Shared shipping-address resolution for checkout getData / freeze / Express.
 *
 * Prefer shipping_address_id and delivery-context selected address over cascade
 * editor defaults (often CN after currency full-page reload).
 */
final class CheckoutShippingAddressResolver
{
    public function __construct(
        private readonly CheckoutDeliveryContextService $deliveryContextService,
    ) {
    }

    /**
     * @param array<string, mixed> $shippingAddress
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function resolve(array $shippingAddress, array $params = []): array
    {
        // Express / freeze often nest the snapshot under address.
        if ($shippingAddress === [] && \is_array($params['address'] ?? null)) {
            $shippingAddress = $params['address'];
        }

        $addressId = (int)(
            $shippingAddress['shipping_address_id']
            ?? $shippingAddress['address_id']
            ?? $shippingAddress['delivery_address_id']
            ?? $shippingAddress['id']
            ?? $params['shipping_address_id']
            ?? $params['address_id']
            ?? $params['delivery_address_id']
            ?? 0
        );
        if ($addressId > 0) {
            $fromBook = $this->fromDeliveryAddressId($addressId);
            if ($fromBook !== null) {
                return $fromBook + $shippingAddress;
            }
        }

        $clientCc = strtoupper(trim((string)($shippingAddress['country_code'] ?? '')));
        try {
            $ctx = $this->deliveryContextService->getContext($params);
            $selected = \is_array($ctx['selected'] ?? null) ? $ctx['selected'] : null;
            $checkoutAddr = \is_array($ctx['checkout_address'] ?? null) ? $ctx['checkout_address'] : null;
            $preferred = null;
            if (\is_array($selected)) {
                $selCc = strtoupper(trim((string)($selected['country_code'] ?? '')));
                if ($selCc !== '' && ($clientCc === '' || ($clientCc === 'CN' && $selCc !== 'CN'))) {
                    $preferred = $selected;
                }
            }
            if ($preferred === null && \is_array($checkoutAddr)) {
                $formCc = strtoupper(trim((string)($checkoutAddr['country_code'] ?? '')));
                if ($formCc !== '' && ($clientCc === '' || ($clientCc === 'CN' && $formCc !== 'CN'))) {
                    $preferred = $checkoutAddr;
                }
            }
            if (\is_array($preferred)) {
                return $this->mergePreferred($shippingAddress, $preferred);
            }
        } catch (\Throwable) {
            // Keep client payload.
        }

        return $shippingAddress;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fromDeliveryAddressId(int $addressId): ?array
    {
        try {
            $model = ObjectManager::getInstance(DeliveryAddressService::class)->getById($addressId);
            if (!$model instanceof DeliveryAddress || (int)$model->getId() !== $addressId) {
                return null;
            }
            $countryCode = strtoupper(trim((string)$model->getData(DeliveryAddress::schema_fields_COUNTRY_CODE)));
            if ($countryCode === '') {
                return null;
            }

            return [
                'address_id' => (string)$addressId,
                'shipping_address_id' => (string)$addressId,
                'delivery_address_id' => (string)$addressId,
                'id' => (string)$addressId,
                'name' => trim((string)$model->getData(DeliveryAddress::schema_fields_CONTACT_NAME)),
                'contact_name' => trim((string)$model->getData(DeliveryAddress::schema_fields_CONTACT_NAME)),
                'phone' => trim((string)$model->getData(DeliveryAddress::schema_fields_CONTACT_PHONE)),
                'contact_phone' => trim((string)$model->getData(DeliveryAddress::schema_fields_CONTACT_PHONE)),
                'country_code' => $countryCode,
                'country' => trim((string)$model->getData(DeliveryAddress::schema_fields_COUNTRY)),
                'province' => trim((string)$model->getData(DeliveryAddress::schema_fields_PROVINCE)),
                'province_code' => trim((string)($model->getData('province_code') ?? '')),
                'province_region_id' => (int)($model->getData('province_region_id') ?? 0),
                'city' => trim((string)$model->getData(DeliveryAddress::schema_fields_CITY)),
                'city_code' => trim((string)($model->getData('city_code') ?? '')),
                'city_region_id' => (int)($model->getData('city_region_id') ?? 0),
                'district' => trim((string)($model->getData(DeliveryAddress::schema_fields_DISTRICT) ?? '')),
                'district_code' => trim((string)($model->getData('district_code') ?? '')),
                'district_region_id' => (int)($model->getData('district_region_id') ?? 0),
                'address1' => trim((string)$model->getData(DeliveryAddress::schema_fields_STREET)),
                'street' => trim((string)$model->getData(DeliveryAddress::schema_fields_STREET)),
                'postal_code' => trim((string)$model->getData(DeliveryAddress::schema_fields_POSTAL_CODE)),
                'street_id' => (int)($model->getData('street_id') ?? 0),
            ];
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param array<string, mixed> $shippingAddress
     * @param array<string, mixed> $preferred
     * @return array<string, mixed>
     */
    private function mergePreferred(array $shippingAddress, array $preferred): array
    {
        $merged = $shippingAddress;
        foreach ([
            'country_code', 'country', 'province', 'province_code', 'province_region_id',
            'city', 'city_code', 'city_region_id', 'district', 'district_code',
            'district_region_id', 'address1', 'street', 'postal_code', 'street_id',
            'name', 'phone', 'email', 'contact_name', 'contact_phone',
        ] as $key) {
            $value = $preferred[$key]
                ?? $preferred[$key === 'name' ? 'contact_name' : $key]
                ?? $preferred[$key === 'phone' ? 'contact_phone' : $key]
                ?? $preferred[$key === 'address1' ? 'street' : $key]
                ?? null;
            if ($value !== null && $value !== '' && $value !== 0 && $value !== '0') {
                $merged[$key] = $value;
            }
        }
        $id = (string)($preferred['id'] ?? $preferred['delivery_address_id'] ?? $preferred['address_id'] ?? '');
        if ($id !== '') {
            $merged['id'] = $id;
            $merged['address_id'] = $id;
            $merged['shipping_address_id'] = $id;
            $merged['delivery_address_id'] = $id;
        }
        $merged['country_code'] = strtoupper(trim((string)($merged['country_code'] ?? '')));

        return $merged;
    }
}
