<?php

declare(strict_types=1);

namespace Weline\Seo\Service;

/**
 * Canonical platform codes for SEO accounts.
 *
 * Google Search Console capabilities live on one Google account (shared SA);
 * legacy aliases normalize to {@see self::GOOGLE}.
 */
final class SeoPlatformCode
{
    public const GOOGLE = 'google';

    /** @var array<string, string> */
    private const ALIASES = [
        'google' => self::GOOGLE,
        'google_search_console' => self::GOOGLE,
        'google_indexing_api' => self::GOOGLE,
        'gsc' => self::GOOGLE,
    ];

    public static function canonicalize(string $platformOrProvider): string
    {
        $key = strtolower(trim($platformOrProvider));
        if ($key === '') {
            return '';
        }

        if (isset(self::ALIASES[$key])) {
            return self::ALIASES[$key];
        }

        foreach (self::ALIASES as $alias => $canonical) {
            if ($alias !== $canonical && str_contains($key, $alias)) {
                return $canonical;
            }
        }

        return $key;
    }

    public static function isGoogle(string $platformOrProvider): bool
    {
        return self::canonicalize($platformOrProvider) === self::GOOGLE;
    }

    /**
     * @return list<string>
     */
    public static function googleAliases(): array
    {
        return array_keys(array_filter(
            self::ALIASES,
            static fn (string $canonical): bool => $canonical === self::GOOGLE
        ));
    }
}
