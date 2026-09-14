<?php

declare(strict_types=1);

namespace Weline\Order\Service;

use Weline\Order\Model\Order;

/**
 * Sanitize admin "customer adjust" POSTs: contact, notes, methods, address JSON.
 * Never accepts topology money/line mutations.
 */
final class BackendOrderCustomerAdjustService
{
    private const ALLOWED_SCALAR = [
        Order::schema_fields_CUSTOMER_ID,
        Order::schema_fields_CUSTOMER_NAME,
        Order::schema_fields_CUSTOMER_EMAIL,
        Order::schema_fields_CUSTOMER_PHONE,
        Order::schema_fields_PAYMENT_METHOD,
        Order::schema_fields_SHIPPING_METHOD,
        Order::schema_fields_NOTES,
    ];

    /**
     * Flatten shipping/billing JSON into form seed values.
     *
     * @param array<string, mixed> $address
     * @return array{
     *     name: string,
     *     phone: string,
     *     email: string,
     *     country_code: string,
     *     country: string,
     *     province: string,
     *     city: string,
     *     district: string,
     *     address1: string,
     *     address2: string,
     *     postcode: string,
     *     province_region_id: string,
     *     city_region_id: string,
     *     district_region_id: string
     * }
     */
    public function addressFormValues(array $address): array
    {
        return [
            'name' => $this->pick($address, ['name', 'fullname_name', 'contact_name']),
            'phone' => $this->pick($address, ['phone', 'telephone', 'mobile', 'contact_phone']),
            'email' => $this->pick($address, ['email']),
            'country_code' => strtoupper($this->pick($address, ['country_code', 'country_id'])),
            'country' => $this->pick($address, ['country', 'country_name']),
            'province' => $this->pick($address, ['province', 'region', 'state']),
            'city' => $this->pick($address, ['city', 'city_name']),
            'district' => $this->pick($address, ['district', 'area', 'county']),
            'address1' => $this->pick($address, ['address1', 'street', 'street1', 'detail', 'detailed_address', 'address']),
            'address2' => $this->pick($address, ['address2', 'street2']),
            'postcode' => $this->pick($address, ['postcode', 'postal_code', 'zip']),
            'province_region_id' => (string)($address['province_region_id'] ?? ''),
            'city_region_id' => (string)($address['city_region_id'] ?? ''),
            'district_region_id' => (string)($address['district_region_id'] ?? ''),
        ];
    }

    /**
     * @param array<string, mixed> $post
     * @param array<string, mixed>|null $existingShipping
     * @param array<string, mixed>|null $existingBilling
     * @return array<string, mixed>
     */
    public function normalizeUpdatePayload(
        array $post,
        ?array $existingShipping = null,
        ?array $existingBilling = null,
    ): array {
        $out = [];
        foreach (self::ALLOWED_SCALAR as $field) {
            if (!\array_key_exists($field, $post)) {
                continue;
            }
            $value = $post[$field];
            if ($field === Order::schema_fields_CUSTOMER_ID) {
                $out[$field] = ($value === '' || $value === null) ? null : (int)$value;
                continue;
            }
            $out[$field] = is_scalar($value) ? trim((string)$value) : '';
        }

        $shipping = $this->buildAddressFromPost($post, 'shipping', $existingShipping ?? []);
        if ($shipping !== []) {
            $out[Order::schema_fields_SHIPPING_ADDRESS] = json_encode($shipping, JSON_UNESCAPED_UNICODE);
            if (($out[Order::schema_fields_CUSTOMER_NAME] ?? '') === '' && ($shipping['name'] ?? '') !== '') {
                $out[Order::schema_fields_CUSTOMER_NAME] = (string)$shipping['name'];
            }
            if (($out[Order::schema_fields_CUSTOMER_PHONE] ?? '') === '' && ($shipping['phone'] ?? '') !== '') {
                $out[Order::schema_fields_CUSTOMER_PHONE] = (string)$shipping['phone'];
            }
            if (($out[Order::schema_fields_CUSTOMER_EMAIL] ?? '') === '' && ($shipping['email'] ?? '') !== '') {
                $out[Order::schema_fields_CUSTOMER_EMAIL] = (string)$shipping['email'];
            }
        }

        $billing = $this->buildAddressFromPost($post, 'billing', $existingBilling ?? []);
        if ($billing !== []) {
            $out[Order::schema_fields_BILLING_ADDRESS] = json_encode($billing, JSON_UNESCAPED_UNICODE);
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $post
     * @param array<string, mixed> $existing
     * @return array<string, mixed>
     */
    private function buildAddressFromPost(array $post, string $prefix, array $existing): array
    {
        $get = static function (string $key) use ($post, $prefix): string {
            $full = $prefix . '_' . $key;
            if (!\array_key_exists($full, $post)) {
                return '';
            }
            $value = $post[$full];

            return is_scalar($value) ? trim((string)$value) : '';
        };

        $merged = $existing;
        $map = [
            'name' => $get('name'),
            'phone' => $get('phone'),
            'email' => $get('email'),
            'country_code' => strtoupper($get('country_code') !== '' ? $get('country_code') : $get('country')),
            'country' => $get('country_label') !== '' ? $get('country_label') : $get('country'),
            'province' => $get('province'),
            'city' => $get('city'),
            'district' => $get('district'),
            'address1' => $get('address1') !== '' ? $get('address1') : $get('street'),
            'address2' => $get('address2'),
            'postcode' => $get('postcode') !== '' ? $get('postcode') : $get('postal_code'),
        ];
        foreach ($map as $key => $value) {
            if ($value !== '') {
                $merged[$key] = $value;
            }
        }

        foreach (['province_region_id', 'city_region_id', 'district_region_id'] as $idKey) {
            $raw = $get($idKey);
            if ($raw !== '' && ctype_digit($raw)) {
                $merged[$idKey] = (int)$raw;
            }
        }

        // Drop empty noise but keep meaningful keys.
        $meaningful = false;
        foreach (['name', 'phone', 'email', 'address1', 'province', 'city', 'district', 'country_code', 'country'] as $key) {
            if (trim((string)($merged[$key] ?? '')) !== '') {
                $meaningful = true;
                break;
            }
        }

        return $meaningful ? $merged : [];
    }

    /**
     * @param array<string, mixed> $address
     * @param list<string> $keys
     */
    private function pick(array $address, array $keys): string
    {
        foreach ($keys as $key) {
            $value = $address[$key] ?? null;
            if (is_array($value)) {
                $value = implode(' ', array_map('strval', $value));
            }
            if (!is_scalar($value)) {
                continue;
            }
            $trimmed = trim((string)$value);
            if ($trimmed !== '') {
                return $trimmed;
            }
        }

        return '';
    }
}
