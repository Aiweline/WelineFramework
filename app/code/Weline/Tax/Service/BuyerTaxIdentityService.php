<?php

declare(strict_types=1);

namespace Weline\Tax\Service;

use Weline\Tax\Api\TaxIdentitySchemaProviderInterface;

/**
 * Buyer tax identity (EU VAT phase-1): schema + normalize + TaxSnapshot merge.
 * Phase-1: optional for all EU countries (no hard block); format gate only.
 * Writes formal buyer_tax_* keys so Order\TaxSnapshot::fromArray preserves them.
 */
final class BuyerTaxIdentityService implements TaxIdentitySchemaProviderInterface
{
    /** Session / API payload key (plan). Legacy alias: buyer_tax_identity. */
    public const PAYLOAD_KEY = 'tax_identity';
    public const LEGACY_PAYLOAD_KEY = 'buyer_tax_identity';

    /** ISO countries treated as EU for VAT UI. */
    public const EU_COUNTRIES = [
        'AT', 'BE', 'BG', 'HR', 'CY', 'CZ', 'DK', 'EE', 'FI', 'FR', 'DE', 'GR', 'HU',
        'IE', 'IT', 'LV', 'LT', 'LU', 'MT', 'NL', 'PL', 'PT', 'RO', 'SK', 'SI', 'ES', 'SE',
    ];

    /**
     * @param array<string,mixed> $address
     * @return array{
     *   visibility: 'hidden'|'optional'|'recommended'|'required',
     *   tax_id_type: string,
     *   label: string,
     *   pattern: string,
     *   country_code: string,
     *   fields: list<array{code:string,label:string,required:bool}>,
     *   visible: bool,
     *   required: bool,
     *   errors: list<string>
     * }
     */
    public function schemaForAddress(array $address): array
    {
        $country = strtoupper(trim((string)($address['country_code'] ?? $address['country'] ?? '')));
        $isEu = $country !== '' && \in_array($country, self::EU_COUNTRIES, true);
        if (!$isEu) {
            return [
                'visibility' => 'hidden',
                'tax_id_type' => '',
                'label' => '',
                'pattern' => '',
                'country_code' => $country,
                'fields' => [],
                'visible' => false,
                'required' => false,
                'errors' => [],
            ];
        }

        return [
            'visibility' => 'optional',
            'tax_id_type' => 'eu_vat',
            'label' => 'EU VAT ID',
            'pattern' => '^[A-Z]{2}[A-Z0-9]{8,12}$',
            'country_code' => $country,
            'fields' => [[
                'code' => 'tax_id',
                'label' => 'EU VAT ID (optional B2B)',
                'required' => false,
            ]],
            'visible' => true,
            'required' => false,
            'errors' => [],
        ];
    }

    /**
     * @param array<string,mixed> $address
     * @param array<string,mixed> $identity
     * @return array{visible:bool,required:bool,fields:list<array{code:string,label:string,required:bool}>,errors:list<string>,visibility:string,country_code:string}
     */
    public function describeForAddress(array $address, array $identity = [], bool $forceRequired = false): array
    {
        $schema = $this->schemaForAddress($address);
        $errors = [];
        $normalized = $this->normalize($identity);
        $taxId = (string)($normalized['tax_id'] ?? '');
        if ($taxId !== '' && !$this->isPlausibleEuVat($taxId)) {
            $errors[] = 'buyer_tax_vat_invalid';
        }
        $required = $forceRequired || ($schema['visibility'] === 'required');
        if ($required && $schema['visible'] && $taxId === '') {
            $errors[] = 'buyer_tax_vat_required';
        }
        $fields = $schema['fields'];
        if ($forceRequired && $fields !== []) {
            $fields[0]['required'] = true;
        }

        return [
            'visible' => (bool)$schema['visible'],
            'required' => $required && (bool)$schema['visible'],
            'fields' => $fields,
            'errors' => $errors,
            'visibility' => (string)$schema['visibility'],
            'country_code' => (string)$schema['country_code'],
        ];
    }

    /**
     * @param array<string,mixed> $identity
     * @return array{tax_id?:string,tax_id_type?:string,country_code?:string,company_name?:string}
     */
    public function normalize(array $identity): array
    {
        $taxId = strtoupper(preg_replace('/\s+/', '', (string)(
            $identity['tax_id']
            ?? $identity['vat_id']
            ?? ''
        )) ?? '');
        $type = trim((string)($identity['tax_id_type'] ?? $identity['kind'] ?? ''));
        if ($taxId !== '' && $type === '') {
            $type = 'eu_vat';
        }
        $country = strtoupper(trim((string)($identity['country_code'] ?? '')));
        $company = trim((string)($identity['company_name'] ?? ''));

        $out = [];
        if ($taxId !== '') {
            $out['tax_id'] = $taxId;
        }
        if ($type !== '') {
            $out['tax_id_type'] = $type;
        }
        if ($country !== '') {
            $out['country_code'] = $country;
        }
        if ($company !== '') {
            $out['company_name'] = $company;
        }

        return $out;
    }

    public function isPlausibleEuVat(string $vat): bool
    {
        $vat = strtoupper(preg_replace('/\s+/', '', $vat) ?? '');

        return (bool)preg_match('/^[A-Z]{2}[A-Z0-9]{8,12}$/', $vat);
    }

    /**
     * @param array<string,mixed> $taxSnapshot
     * @param array<string,mixed> $identity
     * @return array<string,mixed>
     */
    public function mergeIntoTaxSnapshot(array $taxSnapshot, array $identity): array
    {
        $normalized = $this->normalize($identity);
        $taxId = (string)($normalized['tax_id'] ?? '');
        unset(
            $taxSnapshot[self::PAYLOAD_KEY],
            $taxSnapshot[self::LEGACY_PAYLOAD_KEY],
        );
        if ($taxId === '') {
            unset(
                $taxSnapshot['buyer_tax_id'],
                $taxSnapshot['buyer_tax_id_type'],
                $taxSnapshot['buyer_tax_country'],
                $taxSnapshot['buyer_company_name'],
            );

            return $taxSnapshot;
        }

        $taxSnapshot['buyer_tax_id'] = $taxId;
        $taxSnapshot['buyer_tax_id_type'] = (string)($normalized['tax_id_type'] ?? 'eu_vat');
        $taxSnapshot['buyer_tax_country'] = (string)($normalized['country_code'] ?? '');
        $taxSnapshot['buyer_company_name'] = (string)($normalized['company_name'] ?? '');
        $taxSnapshot[self::PAYLOAD_KEY] = $normalized;

        return $taxSnapshot;
    }

    /**
     * @param array<string,mixed> $bag
     * @return array<string,mixed>
     */
    public static function extractFromBag(array $bag): array
    {
        if (\is_array($bag[self::PAYLOAD_KEY] ?? null)) {
            return $bag[self::PAYLOAD_KEY];
        }
        if (\is_array($bag[self::LEGACY_PAYLOAD_KEY] ?? null)) {
            return $bag[self::LEGACY_PAYLOAD_KEY];
        }

        return [];
    }
}
