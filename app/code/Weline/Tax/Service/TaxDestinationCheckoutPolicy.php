<?php

declare(strict_types=1);

namespace Weline\Tax\Service;

/**
 * Checkout charge policy for CN-origin (or catalog-home) storefronts.
 *
 * Catalog amounts are typically VAT-inclusive at the home jurisdiction.
 * Cross-border payable tax is therefore import/customs oriented by default,
 * not a second destination sales-tax layer on top of 价内税.
 */
final class TaxDestinationCheckoutPolicy
{
    public const PROFILE_DOMESTIC_INCLUSIVE = 'domestic_inclusive';
    public const PROFILE_IMPORT_AT_BORDER = 'import_at_border';
    public const PROFILE_DUTY_ONLY = 'duty_only';
    public const PROFILE_COLLECT_DEST_TAX = 'collect_destination_tax';
    public const PROFILE_NO_EXTRA = 'no_extra_tax';

    /**
     * Countries where merchant may collect destination sales/GST at checkout
     * (IOSS / LVG / nexus). Comma-list override via config.
     *
     * Generic seed default empty (rates still seeded). Fill only after IOSS/GST/nexus obligation.
     */
    public const KEY_COLLECT_SALES_TAX_COUNTRIES = 'tax/general/collect_sales_tax_countries';

    /**
     * @param list<string>|string|null $collectSalesTaxCountries ISO2 allowlist override
     * @return array{
     *   profile:string,
     *   charge_sales_tax:bool,
     *   charge_customs_duty:bool,
     *   charge_import_vat:bool,
     *   note:string,
     *   destination_country:string,
     *   origin_country:string
     * }
     */
    public function resolve(
        string $destinationCountry,
        string $originCountry = 'CN',
        bool $pricesIncludeTax = true,
        array|string|null $collectSalesTaxCountries = null,
    ): array {
        $dest = $this->iso2($destinationCountry);
        $origin = $this->iso2($originCountry) ?: 'CN';
        $collect = $this->normalizeCountryList($collectSalesTaxCountries);

        if ($dest === '') {
            return [
                'profile' => self::PROFILE_NO_EXTRA,
                'charge_sales_tax' => false,
                'charge_customs_duty' => false,
                'charge_import_vat' => false,
                'note' => 'destination_country_missing',
                'destination_country' => '',
                'origin_country' => $origin,
            ];
        }

        if ($dest === $origin) {
            if ($pricesIncludeTax) {
                return [
                    'profile' => self::PROFILE_DOMESTIC_INCLUSIVE,
                    'charge_sales_tax' => false,
                    'charge_customs_duty' => false,
                    'charge_import_vat' => false,
                    'note' => CheckoutTaxAdvisor::NOTE_PRICES_INCLUDE_TAX,
                    'destination_country' => $dest,
                    'origin_country' => $origin,
                ];
            }

            return [
                'profile' => self::PROFILE_COLLECT_DEST_TAX,
                'charge_sales_tax' => true,
                'charge_customs_duty' => false,
                'charge_import_vat' => false,
                'note' => 'domestic_exclusive_catalog',
                'destination_country' => $dest,
                'origin_country' => $origin,
            ];
        }

        // Explicit collect allowlist (IOSS / LVG / US nexus): sales tax yes, import VAT no.
        if (isset($collect[$dest])) {
            return [
                'profile' => self::PROFILE_COLLECT_DEST_TAX,
                'charge_sales_tax' => true,
                'charge_customs_duty' => true,
                'charge_import_vat' => false,
                'note' => 'collect_destination_sales_tax',
                'destination_country' => $dest,
                'origin_country' => $origin,
            ];
        }

        // US/CA federal-ish: no destination sales tax by default (nexus opt-in); DDU customs (± GST).
        if ($dest === 'US') {
            return [
                'profile' => self::PROFILE_DUTY_ONLY,
                'charge_sales_tax' => false,
                'charge_customs_duty' => true,
                'charge_import_vat' => false,
                'note' => 'us_no_nexus_duty_only',
                'destination_country' => $dest,
                'origin_country' => $origin,
            ];
        }

        if ($this->isImportVatAtBorderCountry($dest)) {
            return [
                'profile' => self::PROFILE_IMPORT_AT_BORDER,
                'charge_sales_tax' => false,
                'charge_customs_duty' => true,
                'charge_import_vat' => true,
                'note' => 'import_vat_via_duty_estimate',
                'destination_country' => $dest,
                'origin_country' => $origin,
            ];
        }

        // Unknown foreign: never invent sales tax; allow duty table if present.
        return [
            'profile' => self::PROFILE_DUTY_ONLY,
            'charge_sales_tax' => false,
            'charge_customs_duty' => true,
            'charge_import_vat' => false,
            'note' => 'foreign_duty_only_default',
            'destination_country' => $dest,
            'origin_country' => $origin,
        ];
    }

    private function isImportVatAtBorderCountry(string $iso2): bool
    {
        // EU/EEA/UK/CH + common GST/VAT import destinations for CN export B2C.
        static $set = [
            'AT' => true, 'BE' => true, 'BG' => true, 'HR' => true, 'CY' => true, 'CZ' => true,
            'DK' => true, 'EE' => true, 'FI' => true, 'FR' => true, 'DE' => true, 'GR' => true,
            'HU' => true, 'IE' => true, 'IT' => true, 'LV' => true, 'LT' => true, 'LU' => true,
            'MT' => true, 'NL' => true, 'PL' => true, 'PT' => true, 'RO' => true, 'SK' => true,
            'SI' => true, 'ES' => true, 'SE' => true,
            'GB' => true, 'CH' => true, 'NO' => true, 'IS' => true, 'LI' => true,
            'CA' => true, 'AU' => true, 'NZ' => true, 'JP' => true, 'KR' => true, 'SG' => true,
            'IN' => true, 'AE' => true, 'SA' => true, 'TR' => true, 'IL' => true, 'ZA' => true,
            'BR' => true, 'MX' => true, 'CL' => true, 'AR' => true, 'TH' => true, 'MY' => true,
            'ID' => true, 'PH' => true, 'VN' => true,
        ];

        return isset($set[$iso2]);
    }

    /**
     * @param list<string>|string|null $raw
     * @return array<string,true>
     */
    private function normalizeCountryList(array|string|null $raw): array
    {
        if ($raw === null || $raw === '') {
            return [];
        }
        if (is_array($raw)) {
            $parts = $raw;
        } else {
            $split = preg_split('/[\s,;]+/', (string)$raw);
            $parts = is_array($split) ? $split : [];
        }
        $out = [];
        foreach ($parts as $part) {
            $iso = $this->iso2((string)$part);
            if ($iso !== '') {
                $out[$iso] = true;
            }
        }

        return $out;
    }

    private function iso2(string $raw): string
    {
        $v = strtoupper(trim($raw));

        return preg_match('/^[A-Z]{2}$/', $v) === 1 ? $v : '';
    }
}
