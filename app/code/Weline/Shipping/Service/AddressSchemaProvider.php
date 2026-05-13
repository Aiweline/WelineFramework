<?php

declare(strict_types=1);

namespace Weline\Shipping\Service;

class AddressSchemaProvider
{
    private const DEFAULT_SCHEMA = [
        'country_code' => 'DEFAULT',
        'locale' => 'en_US',
        'field_order' => ['contact_name', 'street', 'district', 'city', 'province', 'postal_code', 'country'],
        'required_fields' => ['contact_name', 'contact_phone', 'street', 'city', 'province'],
        'labels' => [
            'country' => 'Country/Region',
            'province' => 'State/Province',
            'city' => 'City',
            'district' => 'District',
            'street' => 'Street address',
            'postal_code' => 'Postal code',
            'contact_name' => 'Recipient',
            'contact_phone' => 'Phone',
        ],
        'postal_code_pattern' => '/^[A-Za-z0-9][A-Za-z0-9\\-\\s]{1,11}$/',
        'phone_pattern' => '/^[0-9+\\-\\s()]{6,32}$/',
        'format' => [
            'single_line' => ['country', 'province', 'city', 'district', 'street'],
            'tokens' => ['country', 'province', 'city', 'district', 'street'],
        ],
    ];

    private const COUNTRY_SCHEMAS = [
        'CN' => [
            'country_code' => 'CN',
            'locale' => 'zh_Hans_CN',
            'field_order' => ['country', 'province', 'city', 'district', 'street', 'postal_code'],
            'required_fields' => ['contact_name', 'contact_phone', 'province', 'city', 'street'],
            'labels' => [
                'country' => '国家/地区',
                'province' => '省份',
                'city' => '城市',
                'district' => '区县',
                'street' => '详细地址',
                'postal_code' => '邮政编码',
                'contact_name' => '收货人',
                'contact_phone' => '联系电话',
            ],
            'postal_code_pattern' => '/^\\d{6}$/',
            'phone_pattern' => '/^[0-9+\\-\\s()]{6,32}$/',
            'format' => [
                'single_line' => ['country', 'province', 'city', 'district', 'street'],
                'tokens' => ['street', 'city', 'province', 'postal_code', 'country'],
            ],
        ],
        'US' => [
            'country_code' => 'US',
            'locale' => 'en_US',
            'field_order' => ['contact_name', 'street', 'city', 'province', 'postal_code', 'country'],
            'required_fields' => ['contact_name', 'contact_phone', 'street', 'city', 'province'],
            'labels' => [
                'country' => 'Country',
                'province' => 'State',
                'city' => 'City',
                'district' => 'County',
                'street' => 'Street address',
                'postal_code' => 'ZIP code',
                'contact_name' => 'Recipient',
                'contact_phone' => 'Phone',
            ],
            'postal_code_pattern' => '/^\\d{5}(-\\d{4})?$/',
            'phone_pattern' => '/^[0-9+\\-\\s()]{7,32}$/',
            'format' => [
                'single_line' => ['street', 'city', 'province', 'postal_code', 'country'],
                'tokens' => ['street', 'city', 'province', 'postal_code', 'country'],
            ],
        ],
        'GB' => [
            'country_code' => 'GB',
            'locale' => 'en_GB',
            'field_order' => ['contact_name', 'street', 'city', 'province', 'postal_code', 'country'],
            'required_fields' => ['contact_name', 'contact_phone', 'street', 'city'],
            'labels' => [
                'country' => 'Country',
                'province' => 'County',
                'city' => 'Town/City',
                'district' => 'District',
                'street' => 'Address line',
                'postal_code' => 'Postcode',
                'contact_name' => 'Recipient',
                'contact_phone' => 'Phone',
            ],
            'postal_code_pattern' => '/^[A-Z]{1,2}\\d[A-Z\\d]?\\s*\\d[A-Z]{2}$/i',
            'phone_pattern' => '/^[0-9+\\-\\s()]{7,32}$/',
            'format' => [
                'single_line' => ['street', 'city', 'province', 'postal_code', 'country'],
                'tokens' => ['postal_code', 'province', 'city', 'district', 'street', 'country'],
            ],
        ],
        'JP' => [
            'country_code' => 'JP',
            'locale' => 'ja_JP',
            'field_order' => ['postal_code', 'province', 'city', 'district', 'street', 'country'],
            'required_fields' => ['contact_name', 'contact_phone', 'province', 'city', 'street'],
            'labels' => [
                'country' => 'Country',
                'province' => 'Prefecture',
                'city' => 'City/Ward',
                'district' => 'District',
                'street' => 'Street address',
                'postal_code' => 'Postal code',
                'contact_name' => 'Recipient',
                'contact_phone' => 'Phone',
            ],
            'postal_code_pattern' => '/^\\d{3}-?\\d{4}$/',
            'phone_pattern' => '/^[0-9+\\-\\s()]{7,32}$/',
            'format' => [
                'single_line' => ['postal_code', 'province', 'city', 'district', 'street', 'country'],
                'tokens' => ['country', 'province', 'city', 'district', 'street'],
            ],
        ],
    ];

    public function getSchema(?string $countryCode): array
    {
        $countryCode = strtoupper(trim((string)$countryCode));
        $schema = self::COUNTRY_SCHEMAS[$countryCode] ?? self::DEFAULT_SCHEMA;
        $schema['labels'] = array_replace(self::DEFAULT_SCHEMA['labels'], $schema['labels'] ?? []);
        $schema['format'] = array_replace(self::DEFAULT_SCHEMA['format'], $schema['format'] ?? []);
        return $schema;
    }

    public function inferCountryCode(array $address): string
    {
        $code = strtoupper(trim((string)($address['country_code'] ?? '')));
        if ($code !== '') {
            return $code;
        }

        $country = trim((string)($address['country'] ?? ''));
        return match ($country) {
            '中国', 'China', 'CN' => 'CN',
            '美国', 'United States', 'USA', 'US' => 'US',
            '英国', 'United Kingdom', 'Great Britain', 'GB', 'UK' => 'GB',
            '日本', 'Japan', 'JP' => 'JP',
            default => $country !== '' && strlen($country) === 2 ? strtoupper($country) : 'CN',
        };
    }
}
