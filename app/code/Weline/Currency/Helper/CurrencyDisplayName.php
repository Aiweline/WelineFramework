<?php

declare(strict_types=1);

namespace Weline\Currency\Helper;

/**
 * Resolve a storefront currency display name for the active locale.
 *
 * Prefer LocalDescription override → PHP intl ICU (locale chain) → en_US ICU
 * for non-Chinese locales. Catalog Chinese base names must not leak into EL/EN/….
 *
 * Process-memoized until clearProcessCache / process_cache_resetter.
 */
final class CurrencyDisplayName
{
    /** @var array<string, string> locale|CODE|override → name */
    private static array $processNameByKey = [];

    public static function forCode(string $currencyCode, string $locale, string $localOverride = ''): string
    {
        $code = strtoupper(trim($currencyCode));
        if ($code === '') {
            return '';
        }

        $locale = trim($locale);
        if ($locale === '') {
            $locale = 'zh_Hans_CN';
        }
        $localOverride = trim($localOverride);
        $memoKey = strtolower($locale) . '|' . $code . '|' . $localOverride;
        if (isset(self::$processNameByKey[$memoKey])) {
            return self::$processNameByKey[$memoKey];
        }

        if ($localOverride !== '') {
            return self::$processNameByKey[$memoKey] = $localOverride;
        }

        $icu = self::intlName($code, self::localeCandidates($locale));
        if ($icu !== '') {
            return self::$processNameByKey[$memoKey] = $icu;
        }

        if (!self::isChineseFamily($locale)) {
            $en = self::intlName($code, ['en_US', 'en']);
            if ($en !== '') {
                return self::$processNameByKey[$memoKey] = $en;
            }
        }

        return self::$processNameByKey[$memoKey] = '';
    }

    public static function clearProcessCache(): void
    {
        self::$processNameByKey = [];
    }

    public static function isChineseFamily(string $locale): bool
    {
        $normalized = strtolower(str_replace('-', '_', trim($locale)));
        if ($normalized === '') {
            return true;
        }

        return str_starts_with($normalized, 'zh');
    }

    /**
     * @return list<string>
     */
    public static function localeCandidates(string $locale): array
    {
        $locale = trim(str_replace('-', '_', $locale));
        if ($locale === '') {
            return [];
        }

        $candidates = [$locale];
        $parts = explode('_', $locale);
        if (count($parts) > 1 && $parts[0] !== '') {
            $candidates[] = $parts[0];
        }

        $seen = [];
        $out = [];
        foreach ($candidates as $candidate) {
            $key = strtolower($candidate);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $candidate;
        }

        return $out;
    }

    /**
     * @param list<string> $locales
     */
    private static function intlName(string $currencyCode, array $locales): string
    {
        if (!class_exists(\ResourceBundle::class)) {
            return '';
        }

        foreach ($locales as $locale) {
            $locale = trim((string)$locale);
            if ($locale === '') {
                continue;
            }

            try {
                $bundle = \ResourceBundle::create($locale, 'ICUDATA-curr', false);
            } catch (\Throwable) {
                continue;
            }
            if (!$bundle instanceof \ResourceBundle) {
                continue;
            }

            try {
                $currencies = $bundle->get('Currencies');
            } catch (\Throwable) {
                continue;
            }
            if (!$currencies instanceof \ResourceBundle) {
                continue;
            }

            try {
                $entry = $currencies->get($currencyCode);
            } catch (\Throwable) {
                continue;
            }
            if (!$entry instanceof \ResourceBundle) {
                continue;
            }

            try {
                $name = $entry->get(1);
            } catch (\Throwable) {
                continue;
            }
            $name = trim((string)$name);
            if ($name !== '' && strcasecmp($name, $currencyCode) !== 0) {
                return $name;
            }
        }

        return '';
    }
}
