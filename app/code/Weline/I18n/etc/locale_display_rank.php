<?php

declare(strict_types=1);

/**
 * Curated storefront LanguageSwitcher display ranks.
 *
 * Membership of the switcher list still comes from WebsiteLanguage /
 * LocaleCatalogScope.codes. This table only decides display order
 * (language importance × country/market size ± locale overrides).
 *
 * Bump `version` whenever weights change so HotCache catalog keys invalidate.
 *
 * @return array{
 *   version: string,
 *   language_weights: array<string, int>,
 *   country_weights: array<string, int>,
 *   locale_overrides: array<string, int>,
 *   default_language_weight: int,
 *   default_country_weight: int
 * }
 */
return [
    'version' => '1',

    // Higher = more important globally for commerce chrome.
    'language_weights' => [
        'zh' => 1000,
        'en' => 950,
        'es' => 850,
        'ar' => 800,
        'fr' => 780,
        'de' => 760,
        'ja' => 740,
        'ko' => 720,
        'pt' => 700,
        'ru' => 680,
        'it' => 650,
        'hi' => 620,
        'id' => 600,
        'tr' => 580,
        'vi' => 560,
        'th' => 540,
        'pl' => 520,
        'nl' => 500,
        'uk' => 480,
        'sv' => 460,
        'cs' => 440,
        'ro' => 420,
        'hu' => 400,
        'el' => 380,
        'he' => 360,
        'ms' => 340,
        'bn' => 320,
        'fa' => 300,
        'bg' => 200,
    ],

    // Higher = larger / more strategic market for display grouping.
    'country_weights' => [
        'CN' => 500,
        'US' => 490,
        'JP' => 450,
        'DE' => 430,
        'GB' => 420,
        'FR' => 410,
        'IN' => 400,
        'KR' => 390,
        'BR' => 380,
        'CA' => 370,
        'AU' => 360,
        'IT' => 350,
        'ES' => 340,
        'MX' => 330,
        'ID' => 320,
        'TR' => 310,
        'SA' => 280,
        'NL' => 270,
        'PL' => 260,
        'TH' => 250,
        'VN' => 240,
        'MY' => 230,
        'PH' => 220,
        'AE' => 210,
        'SG' => 200,
        'HK' => 195,
        'TW' => 190,
        'RU' => 180,
        'UA' => 160,
        'SE' => 150,
        'CH' => 145,
        'AT' => 140,
        'BE' => 135,
        'PT' => 130,
        'CZ' => 125,
        'RO' => 120,
        'HU' => 115,
        'GR' => 110,
        'IL' => 105,
        'EG' => 100,
        'ZA' => 95,
        'NG' => 90,
        'AR' => 85,
        'CL' => 80,
        'CO' => 75,
        'PE' => 70,
        'NZ' => 65,
        'IE' => 60,
        'DK' => 55,
        'NO' => 50,
        'FI' => 48,
        'BG' => 40,
    ],

    // Optional fine-tunes on full locale codes (added to composite score).
    'locale_overrides' => [
        // Prefer Hans China over other zh variants when both appear.
        'zh_Hans_CN' => 20,
        'zh_Hant_TW' => 10,
        'zh_Hant_HK' => 5,
        'en_US' => 15,
        'en_GB' => 10,
    ],

    'default_language_weight' => 100,
    'default_country_weight' => 50,
];
