<?php

declare(strict_types=1);

namespace Weline\I18n\Service;

/**
 * Curated display order for storefront/admin LanguageSwitcher catalogs.
 *
 * WebsiteLanguage / LocaleCatalogScope.codes remain the membership allowlist.
 * This service only ranks those codes for UI grouping (not DB association order).
 */
final class LocaleDisplayRankService
{
    private const WEIGHTS_FILE = 'etc/locale_display_rank.php';

    /** @var array{
     *   version: string,
     *   language_weights: array<string, int>,
     *   country_weights: array<string, int>,
     *   locale_overrides: array<string, int>,
     *   default_language_weight: int,
     *   default_country_weight: int
     * }|null
     */
    private static ?array $weights = null;

    public static function version(): string
    {
        $weights = self::weights();

        return (string)($weights['version'] ?? '0');
    }

    /**
     * Composite score: language importance dominates, then country/market size,
     * then optional locale override. Higher sorts first.
     */
    public static function score(string $localeCode, string $countryCode = ''): int
    {
        $localeCode = self::normalizeLocale($localeCode);
        if ($localeCode === '') {
            return 0;
        }

        $weights = self::weights();
        $language = self::extractLanguageKey($localeCode);
        $country = \strtoupper(\trim($countryCode));
        if ($country === '') {
            $country = self::extractCountryCode($localeCode);
        }

        $languageWeight = (int)($weights['language_weights'][$language]
            ?? $weights['default_language_weight']
            ?? 100);
        $countryWeight = (int)($weights['country_weights'][$country]
            ?? $weights['default_country_weight']
            ?? 50);
        $override = (int)($weights['locale_overrides'][$localeCode] ?? 0);

        // language * 1000 keeps language tier ahead of country (0–500) + overrides.
        return ($languageWeight * 1000) + $countryWeight + $override;
    }

    /**
     * Stable descending rank: higher score first, then locale code ASC.
     *
     * @param array<string, array<string, mixed>> $languages keyed by locale code
     * @return array<string, array<string, mixed>>
     */
    public static function sortLanguageMap(array $languages): array
    {
        if ($languages === [] || \count($languages) < 2) {
            return $languages;
        }

        $decorated = [];
        foreach ($languages as $code => $row) {
            $code = \trim((string)$code);
            if ($code === '') {
                continue;
            }
            $row = \is_array($row) ? $row : [];
            $country = (string)($row['country_code'] ?? '');
            $decorated[] = [
                'code' => $code,
                'score' => self::score($code, $country),
                'row' => $row,
            ];
        }

        \usort(
            $decorated,
            static function (array $left, array $right): int {
                $scoreCmp = $right['score'] <=> $left['score'];
                if ($scoreCmp !== 0) {
                    return $scoreCmp;
                }

                return \strcasecmp((string)$left['code'], (string)$right['code']);
            },
        );

        $ordered = [];
        foreach ($decorated as $item) {
            $ordered[(string)$item['code']] = $item['row'];
        }

        return $ordered;
    }

    /**
     * @param list<string> $codes
     * @return list<string>
     */
    public static function sortLocaleCodes(array $codes): array
    {
        $unique = [];
        foreach ($codes as $code) {
            $code = self::normalizeLocale((string)$code);
            if ($code !== '' && !isset($unique[\strtolower($code)])) {
                $unique[\strtolower($code)] = $code;
            }
        }
        if (\count($unique) < 2) {
            return \array_values($unique);
        }

        $list = \array_values($unique);
        \usort(
            $list,
            static function (string $left, string $right): int {
                $scoreCmp = self::score($right) <=> self::score($left);
                if ($scoreCmp !== 0) {
                    return $scoreCmp;
                }

                return \strcasecmp($left, $right);
            },
        );

        return $list;
    }

    public static function clearProcessCaches(): void
    {
        self::$weights = null;
    }

    /**
     * @return array{
     *   version: string,
     *   language_weights: array<string, int>,
     *   country_weights: array<string, int>,
     *   locale_overrides: array<string, int>,
     *   default_language_weight: int,
     *   default_country_weight: int
     * }
     */
    private static function weights(): array
    {
        if (self::$weights !== null) {
            return self::$weights;
        }

        $path = \dirname(__DIR__) . '/' . self::WEIGHTS_FILE;
        $loaded = \is_file($path) ? require $path : null;
        if (!\is_array($loaded)) {
            $loaded = [
                'version' => '0',
                'language_weights' => [],
                'country_weights' => [],
                'locale_overrides' => [],
                'default_language_weight' => 100,
                'default_country_weight' => 50,
            ];
        }

        self::$weights = [
            'version' => (string)($loaded['version'] ?? '0'),
            'language_weights' => self::normalizeStringIntMap($loaded['language_weights'] ?? []),
            'country_weights' => self::normalizeCountryWeightMap($loaded['country_weights'] ?? []),
            'locale_overrides' => self::normalizeLocaleOverrideMap($loaded['locale_overrides'] ?? []),
            'default_language_weight' => (int)($loaded['default_language_weight'] ?? 100),
            'default_country_weight' => (int)($loaded['default_country_weight'] ?? 50),
        ];

        return self::$weights;
    }

    /**
     * @param mixed $raw
     * @return array<string, int>
     */
    private static function normalizeStringIntMap(mixed $raw): array
    {
        if (!\is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $key => $value) {
            $key = \strtolower(\trim((string)$key));
            if ($key === '') {
                continue;
            }
            $out[$key] = (int)$value;
        }

        return $out;
    }

    /**
     * @param mixed $raw
     * @return array<string, int>
     */
    private static function normalizeCountryWeightMap(mixed $raw): array
    {
        if (!\is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $key => $value) {
            $key = \strtoupper(\trim((string)$key));
            if ($key === '') {
                continue;
            }
            $out[$key] = (int)$value;
        }

        return $out;
    }

    /**
     * @param mixed $raw
     * @return array<string, int>
     */
    private static function normalizeLocaleOverrideMap(mixed $raw): array
    {
        if (!\is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $key => $value) {
            $key = self::normalizeLocale((string)$key);
            if ($key === '') {
                continue;
            }
            $out[$key] = (int)$value;
        }

        return $out;
    }

    private static function normalizeLocale(string $localeCode): string
    {
        return \trim(\str_replace('-', '_', $localeCode));
    }

    private static function extractLanguageKey(string $localeCode): string
    {
        $parts = \preg_split('/_/', $localeCode, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $first = isset($parts[0]) ? \strtolower((string)$parts[0]) : '';

        return $first;
    }

    private static function extractCountryCode(string $localeCode): string
    {
        $parts = \preg_split('/_/', $localeCode, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $last = \end($parts);
        if (\is_string($last) && \strlen($last) === 2) {
            return \strtoupper($last);
        }

        return '';
    }
}
