<?php

declare(strict_types=1);

namespace Weline\Tax\Service;

/**
 * Production-ready static tax-rate seed matrix (offline, no network).
 *
 * Rates are destination statutory VAT/GST/sales-tax baselines in basis points.
 * Checkout charge behaviour is owned by TaxDestinationCheckoutPolicy — seeding a
 * rate does not by itself mean the storefront will add it on top of 价内税.
 */
final class TaxSeedRateCatalog
{
    public const SEED_REVISION = 4;

    public const CLASS_EXEMPT = 'exempt';

    /** @var list<array{class_code:string,name:string}> */
    public const CLASSES = [
        ['class_code' => 'standard', 'name' => 'Standard'],
        ['class_code' => 'reduced', 'name' => 'Reduced'],
        ['class_code' => self::CLASS_EXEMPT, 'name' => 'Exempt'],
    ];

    /**
     * @return list<array{class_code:string,jurisdiction_key:string,rate_bps:int}>
     */
    public static function rules(): array
    {
        $out = [];
        $exemptKeys = [];
        foreach (self::standardCountryRates() as $country => $bps) {
            $key = strtoupper($country) . '|';
            $out[] = [
                'class_code' => 'standard',
                'jurisdiction_key' => $key,
                'rate_bps' => $bps,
            ];
            $exemptKeys[$key] = true;
        }
        foreach (self::reducedCountryRates() as $country => $bps) {
            $out[] = [
                'class_code' => 'reduced',
                'jurisdiction_key' => strtoupper($country) . '|',
                'rate_bps' => $bps,
            ];
        }
        foreach (self::regionalStandardRates() as $jurisdiction => $bps) {
            $key = strtoupper($jurisdiction);
            $out[] = [
                'class_code' => 'standard',
                'jurisdiction_key' => $key,
                'rate_bps' => $bps,
            ];
            $exemptKeys[$key] = true;
        }
        foreach (array_keys($exemptKeys) as $key) {
            $out[] = [
                'class_code' => self::CLASS_EXEMPT,
                'jurisdiction_key' => $key,
                'rate_bps' => 0,
            ];
        }

        return $out;
    }

    /**
     * Standard VAT/GST/sales baselines (country|).
     *
     * @return array<string,int>
     */
    public static function standardCountryRates(): array
    {
        return [
            // Home catalog
            'CN' => 1300,
            // EU / EEA
            'AT' => 2000, 'BE' => 2100, 'BG' => 2000, 'HR' => 2500, 'CY' => 1900, 'CZ' => 2100,
            'DK' => 2500, 'EE' => 2200, 'FI' => 2550, 'FR' => 2000, 'DE' => 1900, 'GR' => 2400,
            'HU' => 2700, 'IE' => 2300, 'IT' => 2200, 'LV' => 2100, 'LT' => 2100, 'LU' => 1700,
            'MT' => 1800, 'NL' => 2100, 'PL' => 2300, 'PT' => 2300, 'RO' => 1900, 'SK' => 2300,
            'SI' => 2200, 'ES' => 2100, 'SE' => 2500,
            // UK / CH / Nordics extras
            'GB' => 2000, 'CH' => 810, 'NO' => 2500, 'IS' => 2400,
            // Americas
            'US' => 700, 'CA' => 500, 'MX' => 1600, 'BR' => 1700, 'CL' => 1900, 'AR' => 2100,
            // APAC
            'AU' => 1000, 'NZ' => 1500, 'JP' => 1000, 'KR' => 1000, 'SG' => 900, 'IN' => 1800,
            'TH' => 700, 'MY' => 800, 'ID' => 1100, 'PH' => 1200, 'VN' => 1000,
            // Middle East / Africa
            'AE' => 500, 'SA' => 1500, 'TR' => 2000, 'IL' => 1700, 'ZA' => 1500,
        ];
    }

    /**
     * Reduced VAT baselines where commonly published.
     *
     * @return array<string,int>
     */
    public static function reducedCountryRates(): array
    {
        return [
            'CN' => 900,
            'AT' => 1000, 'BE' => 1200, 'BG' => 900, 'HR' => 1300, 'CY' => 900, 'CZ' => 1200,
            'EE' => 900, 'FI' => 1400, 'FR' => 1000, 'DE' => 700, 'GR' => 1300, 'HU' => 1800,
            'IE' => 1350, 'IT' => 1000, 'LV' => 1200, 'LT' => 900, 'LU' => 800, 'MT' => 700,
            'NL' => 900, 'PL' => 800, 'PT' => 600, 'RO' => 900, 'SK' => 1000, 'SI' => 950,
            'ES' => 1000, 'SE' => 1200, 'GB' => 500, 'CH' => 260, 'NO' => 1500, 'IS' => 1100,
            'CA' => 0, 'AU' => 0, 'NZ' => 0, 'JP' => 800, 'SG' => 0, 'IN' => 500,
        ];
    }

    /**
     * Region-level sales-tax baselines (exact jurisdiction_key).
     *
     * @return array<string,int>
     */
    public static function regionalStandardRates(): array
    {
        return [
            // US combined-rate approximations for optional nexus collect mode
            'US|CA' => 725,
            'US|NY' => 800,
            'US|TX' => 625,
            'US|FL' => 600,
            'US|WA' => 650,
            'US|IL' => 625,
            'US|PA' => 600,
            'US|OH' => 575,
            'US|GA' => 700,
            'US|NC' => 475,
            // Canada HST/GST examples
            'CA|ON' => 1300,
            'CA|BC' => 1200,
            'CA|QC' => 1500,
            'CA|AB' => 500,
        ];
    }
}
