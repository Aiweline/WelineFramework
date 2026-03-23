<?php

declare(strict_types=1);

namespace WeShop\Address\Service;

use Weline\Shipping\Model\DeliveryAddress;
use Weline\Shipping\Service\DeliveryAddressService;

class AddressService
{
    public function __construct(
        private readonly DeliveryAddressService $deliveryAddressService
    ) {
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getAddress(int $addressId, ?int $customerId = null): ?array
    {
        $address = $this->deliveryAddressService->getById($addressId);
        if (!$address || !$address->getId()) {
            return null;
        }

        if ($customerId !== null && (int) $address->getCustomerId() !== $customerId) {
            return null;
        }

        return $this->normalizeAddressRow($address->getData());
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getCustomerAddresses(int $customerId): array
    {
        $addresses = $this->deliveryAddressService->getListByCustomer($customerId, [
            'is_enabled' => 1,
        ]);

        $result = [];
        foreach ($addresses as $address) {
            if (!is_array($address)) {
                continue;
            }

            $result[] = $this->normalizeAddressRow($address);
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $addressData
     * @return array<string, mixed>
     */
    public function saveAddress(array $addressData): array
    {
        $normalized = $this->normalizeAddressPayload($addressData);
        $customerId = (int) ($normalized[DeliveryAddress::schema_fields_CUSTOMER_ID] ?? 0);
        if ($customerId <= 0) {
            throw new \InvalidArgumentException((string) __('Please log in to continue.'));
        }

        $addressId = (int) ($normalized['address_id'] ?? 0);
        unset($normalized['address_id']);

        if ($addressId > 0) {
            $address = $this->deliveryAddressService->update($addressId, $normalized, $customerId);
        } else {
            $address = $this->deliveryAddressService->create($customerId, $normalized);
        }

        return $this->normalizeAddressRow($address->getData());
    }

    public function deleteAddress(int $addressId, int $customerId): bool
    {
        return $this->deliveryAddressService->delete($addressId, $customerId);
    }

    public function setDefaultAddress(int $addressId, int $customerId): bool
    {
        $this->deliveryAddressService->setDefault($addressId, $customerId);
        return true;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getDefaultAddress(int $customerId): ?array
    {
        $address = $this->deliveryAddressService->getDefaultByCustomer($customerId);
        if (!$address || !$address->getId()) {
            return null;
        }

        return $this->normalizeAddressRow($address->getData());
    }

    /**
     * @param array<string, mixed> $addressData
     * @return array<string, mixed>
     */
    protected function normalizeAddressPayload(array $addressData): array
    {
        $firstName = $this->readString($addressData, ['firstname', 'first_name']);
        $lastName = $this->readString($addressData, ['lastname', 'last_name']);
        $contactName = $this->readString($addressData, ['contact_name']);
        if ($contactName === '') {
            $contactName = trim($firstName . ' ' . $lastName);
        }
        if ($contactName === '') {
            $contactName = $this->readString($addressData, ['name']);
        }

        $telephone = $this->readString($addressData, ['telephone', 'phone', 'contact_phone']);
        $country = $this->normalizeCountry($this->readString($addressData, ['country_id', 'country']));
        $region = $this->readString($addressData, ['region', 'province', 'state']);
        $city = $this->readString($addressData, ['city']);
        $street = $this->readString($addressData, ['street']);
        $district = $this->readString($addressData, ['district']);
        $postcode = strtoupper($this->readString($addressData, ['postcode', 'postal_code']));
        $addressName = $this->readString($addressData, ['name']);

        if ($addressName === '') {
            $addressName = $contactName !== '' ? $contactName : trim(implode(' ', array_filter([$city, $street])));
        }

        return [
            'address_id' => (int) ($addressData['address_id'] ?? $addressData['delivery_address_id'] ?? 0),
            DeliveryAddress::schema_fields_CUSTOMER_ID => (int) ($addressData['customer_id'] ?? 0),
            DeliveryAddress::schema_fields_NAME => $addressName,
            DeliveryAddress::schema_fields_CONTACT_NAME => $contactName,
            DeliveryAddress::schema_fields_CONTACT_PHONE => $telephone,
            DeliveryAddress::schema_fields_COUNTRY => $country,
            DeliveryAddress::schema_fields_PROVINCE => $region,
            DeliveryAddress::schema_fields_CITY => $city,
            DeliveryAddress::schema_fields_DISTRICT => $district,
            DeliveryAddress::schema_fields_STREET => $street,
            DeliveryAddress::schema_fields_POSTAL_CODE => $postcode,
            DeliveryAddress::schema_fields_IS_DEFAULT => !empty($addressData['is_default']) ? 1 : 0,
            DeliveryAddress::schema_fields_IS_ENABLED => array_key_exists('is_enabled', $addressData)
                ? (!empty($addressData['is_enabled']) ? 1 : 0)
                : 1,
        ];
    }

    /**
     * @param array<string, mixed> $address
     * @return array<string, mixed>
     */
    protected function normalizeAddressRow(array $address): array
    {
        $addressId = (int) ($address[DeliveryAddress::schema_fields_ID] ?? $address['address_id'] ?? 0);
        $contactName = trim((string) ($address[DeliveryAddress::schema_fields_CONTACT_NAME] ?? $address['contact_name'] ?? ''));
        [$firstName, $lastName] = $this->splitContactName($contactName);
        $telephone = trim((string) ($address[DeliveryAddress::schema_fields_CONTACT_PHONE] ?? $address['telephone'] ?? $address['phone'] ?? ''));
        $country = $this->normalizeCountry((string) ($address[DeliveryAddress::schema_fields_COUNTRY] ?? $address['country'] ?? $address['country_id'] ?? ''));
        $region = trim((string) ($address[DeliveryAddress::schema_fields_PROVINCE] ?? $address['region'] ?? $address['province'] ?? $address['state'] ?? ''));
        $city = trim((string) ($address[DeliveryAddress::schema_fields_CITY] ?? $address['city'] ?? ''));
        $district = trim((string) ($address[DeliveryAddress::schema_fields_DISTRICT] ?? $address['district'] ?? ''));
        $street = trim((string) ($address[DeliveryAddress::schema_fields_STREET] ?? $address['street'] ?? ''));
        $postcode = strtoupper(trim((string) ($address[DeliveryAddress::schema_fields_POSTAL_CODE] ?? $address['postcode'] ?? $address['postal_code'] ?? '')));
        $name = trim((string) ($address[DeliveryAddress::schema_fields_NAME] ?? $address['name'] ?? $contactName));

        return [
            'address_id' => $addressId,
            'delivery_address_id' => $addressId,
            'customer_id' => (int) ($address[DeliveryAddress::schema_fields_CUSTOMER_ID] ?? $address['customer_id'] ?? 0),
            'name' => $name,
            'contact_name' => $contactName,
            'firstname' => $firstName,
            'lastname' => $lastName,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'telephone' => $telephone,
            'phone' => $telephone,
            'contact_phone' => $telephone,
            'country' => $country,
            'country_id' => $country,
            'region' => $region,
            'province' => $region,
            'state' => $region,
            'city' => $city,
            'district' => $district,
            'street' => $street,
            'postcode' => $postcode,
            'postal_code' => $postcode,
            'is_default' => !empty($address[DeliveryAddress::schema_fields_IS_DEFAULT] ?? $address['is_default'] ?? false),
            'is_enabled' => !empty($address[DeliveryAddress::schema_fields_IS_ENABLED] ?? $address['is_enabled'] ?? true),
            'created_at' => (string) ($address[DeliveryAddress::schema_fields_CREATED_AT] ?? $address['created_at'] ?? ''),
            'updated_at' => (string) ($address[DeliveryAddress::schema_fields_UPDATED_AT] ?? $address['updated_at'] ?? ''),
            'full_address' => trim(implode(', ', array_filter([$street, $district, $city, $region, $country, $postcode]))),
        ];
    }

    /**
     * @param array<string, mixed> $source
     * @param array<int, string> $keys
     */
    protected function readString(array $source, array $keys): string
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $source)) {
                continue;
            }

            $value = trim((string) $source[$key]);
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    protected function normalizeCountry(string $country): string
    {
        $country = trim($country);
        if ($country === '') {
            return '';
        }

        if (strlen($country) <= 3) {
            return strtoupper($country);
        }

        return $country;
    }

    /**
     * @return array{0:string,1:string}
     */
    protected function splitContactName(string $contactName): array
    {
        $contactName = trim($contactName);
        if ($contactName === '') {
            return ['', ''];
        }

        $parts = preg_split('/\s+/', $contactName) ?: [];
        if (count($parts) === 1) {
            return [$parts[0], ''];
        }

        $lastName = (string) array_pop($parts);
        return [trim(implode(' ', $parts)), $lastName];
    }
}
