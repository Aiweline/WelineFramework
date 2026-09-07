<?php

declare(strict_types=1);

namespace Weline\Shipping\Service;

use Weline\Framework\Http\Cookie;
use Symfony\Component\Intl\Countries as IntlCountries;

class AddressFormatter
{
    public function __construct(
        private AddressSchemaProvider $schemaProvider,
        private RegionLocalNameResolver $localNames,
    ) {
    }

    /**
     * @param array<string, mixed> $address
     * @return array<string, mixed>
     */
    public function normalize(array $address, bool $localize = false): array
    {
        $countryCode = $this->schemaProvider->inferCountryCode($address);
        $address['country_code'] = $countryCode;
        $address['country'] = $this->clean((string)($address['country'] ?? '')) ?: $this->countryName($countryCode);

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
