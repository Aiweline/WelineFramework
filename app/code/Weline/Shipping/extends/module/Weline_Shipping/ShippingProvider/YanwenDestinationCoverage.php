<?php

declare(strict_types=1);

namespace Weline\Shipping\Extends\Module\Weline_Shipping\ShippingProvider;

use Weline\Shipping\Model\CarrierRegion;

/**
 * Yanwen reachable destinations — country-level only (Open API countryId / ISO alpha-2).
 * Not province/city: common.country.getlist + calc.list use country, not admin subdivisions.
 *
 * Cross-border destinations (excludes CN; domestic lanes stay on local carriers).
 * Hardcoded catalog — do not invent province rows here.
 */
final class YanwenDestinationCoverage
{
    /**
     * ISO-3166 alpha-2 destination countries Yanwen provider advertises by default.
     *
     * @return list<string>
     */
    public static function countryCodes(): array
    {
        return [
            // Greater China (outbound destinations)
            'HK', 'MO', 'TW',
            // Americas
            'US', 'CA', 'MX', 'BR', 'AR', 'CL', 'CO', 'PE',
            // Europe
            'GB', 'DE', 'FR', 'IE', 'IT', 'ES', 'NL', 'BE', 'AT', 'CH',
            'SE', 'NO', 'DK', 'FI', 'PT', 'PL', 'CZ', 'HU', 'RO', 'GR',
            // Asia Pacific
            'JP', 'KR', 'SG', 'MY', 'TH', 'VN', 'ID', 'PH', 'IN', 'AU', 'NZ',
            // Middle East & Africa
            'AE', 'SA', 'IL', 'TR', 'EG', 'ZA', 'NG', 'KE',
            // Other
            'RU', 'KZ', 'UA', 'BY',
        ];
    }

    /**
     * @return list<array{region_type:string,country_code:string,region_id:null,region_code:string,street_id:null}>
     */
    public static function defaultCoverageRegions(): array
    {
        $rows = [];
        foreach (self::countryCodes() as $cc) {
            $cc = strtoupper(trim($cc));
            if ($cc === '' || !preg_match('/^[A-Z]{2}$/', $cc)) {
                continue;
            }
            $rows[] = [
                'region_type' => CarrierRegion::TYPE_COUNTRY,
                'country_code' => $cc,
                'region_id' => null,
                'region_code' => $cc,
                'street_id' => null,
            ];
        }

        return $rows;
    }

    public static function coversCountry(string $countryCode): bool
    {
        $cc = strtoupper(trim($countryCode));

        return $cc !== '' && \in_array($cc, self::countryCodes(), true);
    }
}
