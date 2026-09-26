<?php

declare(strict_types=1);

namespace Weline\Shipping\Service;

use Weline\Framework\Http\Cookie;
use Symfony\Component\Intl\Countries as IntlCountries;

class AddressFormatter
{
    /**
     * 国家选择器把「邮编命中的地点」当作提示拼进国家显示名，形如 "United States · San Francisco"。
     * 该提示只应存在于 UI 菜单；一旦被当作 country 值提交，就会落库成脏国家名。
     * country_code 才是权威，故这里统一剥掉「 · <地点>」尾巴。
     */
    public const PLACE_HINT_SEPARATOR = '·';

    public function __construct(
        private AddressSchemaProvider $schemaProvider,
        private RegionLocalNameResolver $localNames,
    ) {
    }

    /**
     * 去掉国家名里的「 · <地点>」UI 提示尾巴；无提示时原样返回。
     */
    public static function canonicalCountryName(string $country): string
    {
        $country = trim(preg_replace('/\s+/u', ' ', $country) ?: '');
        if ($country === '' || !str_contains($country, self::PLACE_HINT_SEPARATOR)) {
            return $country;
        }

        $head = trim(explode(self::PLACE_HINT_SEPARATOR, $country, 2)[0]);

        return $head !== '' ? $head : $country;
    }

    /**
     * 就地清洗地址里的国家显示名（country / country_name），返回新数组。
     *
     * @param array<string, mixed> $address
     * @return array<string, mixed>
     */
    public static function canonicalizeCountryFields(array $address): array
    {
        foreach (['country', 'country_name'] as $key) {
            if (!isset($address[$key]) || !is_string($address[$key])) {
                continue;
            }
            $address[$key] = self::canonicalCountryName($address[$key]);
        }

        return $address;
    }

    /**
     * @param array<string, mixed> $address
     * @return array<string, mixed>
     */
    public function normalize(array $address, bool $localize = false): array
    {
        $countryCode = $this->schemaProvider->inferCountryCode($address);
        $address['country_code'] = $countryCode;
        // 剥掉国家选择器的「 · <邮编命中地点>」提示，避免 UI 提示被当作国家名落库。
        $address['country'] = self::canonicalCountryName((string)($address['country'] ?? ''))
            ?: $this->countryName($countryCode);

        foreach (['province', 'city', 'district', 'street', 'postal_code', 'contact_name', 'contact_phone'] as $field) {
            $address[$field] = $this->clean((string)($address[$field] ?? ''));
        }

        if ($localize) {
            $address = $this->localNames->localizeAddressFields($address);
        }

        return $address;
    }

    public function formatSingleLine(array $address): string
    {
        $address = $this->normalize($address, true);
        $schema = $this->schemaProvider->getSchema($address['country_code']);
        $parts = [];
        foreach ($schema['format']['single_line'] as $field) {
            $value = $this->clean((string)($address[$field] ?? ''));
            if ($value !== '') {
                $parts[] = $value;
            }
        }

        return implode(' / ', $parts);
    }

    public function formatTokens(array $address): array
    {
        $address = $this->normalize($address, true);
        $schema = $this->schemaProvider->getSchema($address['country_code']);
        $icons = [
            'country' => 'country',
            'province' => 'region',
            'city' => 'city',
            'district' => 'district',
            'street' => 'street',
            'postal_code' => 'postal',
        ];
        $tokens = [];

        foreach ($schema['format']['tokens'] as $field) {
            $value = $this->clean((string)($address[$field] ?? ''));
            if ($value === '') {
                continue;
            }

            $tokens[] = [
                'field' => $field,
                'icon' => $icons[$field] ?? 'street',
                'label' => $schema['labels'][$field] ?? $field,
                'value' => $value,
            ];
        }

        return $tokens;
    }

    public function toPayload(array $address): array
    {
        $address = $this->normalize($address, true);
        $address['full_address'] = $this->formatSingleLine($address);
        $address['address_tokens'] = $this->formatTokens($address);
        $address['address_schema'] = $this->schemaProvider->getSchema($address['country_code']);
        return $address;
    }

    private function clean(string $value): string
    {
        return trim(preg_replace('/\s+/u', ' ', $value) ?: '');
    }

    private function countryName(string $countryCode): string
    {
        $countryCode = strtoupper(trim($countryCode));
        if ($countryCode === '') {
            return '';
        }

        try {
            $locale = Cookie::getLangLocal() ?: 'en_US';
            if ($locale === 'zh_Hans_CN') {
                $intl = 'zh_Hans';
            } elseif ($locale === 'zh_Hant_TW') {
                $intl = 'zh_Hant';
            } else {
                $intl = $locale;
            }

            return IntlCountries::getName($countryCode, $intl);
        } catch (\Throwable) {
            return match ($countryCode) {
                'CN' => 'China',
                'US' => 'United States',
                'GB' => 'United Kingdom',
                'JP' => 'Japan',
                default => $countryCode,
            };
        }
    }
}
