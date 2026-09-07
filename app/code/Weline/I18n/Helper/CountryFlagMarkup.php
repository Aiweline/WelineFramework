<?php

declare(strict_types=1);

namespace Weline\I18n\Helper;

/**
 * Country-flag helpers for storefront lazy loading.
 *
 * SSR must only emit empty placeholders (data-country-flag). Heavy lipis SVG
 * payloads are served via i18n.getCountryFlags (CDN-cacheable binquery) and
 * hydrated in the browser local cache.
 */
final class CountryFlagMarkup
{
    public const ASSET_VERSION = 'flag-icons-4x3-v1';
    public const STATIC_BASE_PATH = '/Weline/I18n/view/statics/flags/4x3/';

    /** Inline SVG larger / denser than this is treated as unsafe for DOM embed. */
    private const INLINE_BYTE_BUDGET = 2048;
    private const INLINE_PATH_BUDGET = 24;

    public static function normalizeCountryCode(string $countryCode): string
    {
        $code = strtolower(trim($countryCode));
        if ($code === '' || preg_match('/^[a-z]{2}$/', $code) !== 1) {
            return '';
        }

        return $code;
    }

    public static function normalizeRatio(string $ratio): string
    {
        $ratio = strtolower(trim($ratio));
        return \in_array($ratio, ['4x3', '1x1'], true) ? $ratio : '4x3';
    }

    public static function staticUrl(string $countryCode, string $ratio = '4x3'): string
    {
        $code = self::normalizeCountryCode($countryCode);
        if ($code === '') {
            return '';
        }
        $ratio = self::normalizeRatio($ratio);

        return '/Weline/I18n/view/statics/flags/' . $ratio . '/' . $code . '.svg';
    }

    public static function staticFilePath(string $countryCode, string $ratio = '4x3'): string
    {
        $code = self::normalizeCountryCode($countryCode);
        if ($code === '') {
            return '';
        }
        $ratio = self::normalizeRatio($ratio);

        return BP . 'vendor' . DS . 'lipis' . DS . 'flag-icons' . DS . 'flags' . DS . $ratio . DS . $code . '.svg';
    }

    public static function staticFlagExists(string $countryCode, string $ratio = '4x3'): bool
    {
        $path = self::staticFilePath($countryCode, $ratio);
        return $path !== '' && is_file($path);
    }

    /**
     * Empty SSR slot — no SVG / img body. Client fills via binquery + local cache.
     */
    public static function placeholderHtml(string $countryCode): string
    {
        $code = self::normalizeCountryCode($countryCode);
        if ($code === '') {
            return '<span class="w-language-switcher__flag" data-country-flag="" aria-hidden="true"></span>';
        }

        $safe = htmlspecialchars($code, ENT_QUOTES, 'UTF-8');

        return '<span class="w-language-switcher__flag" data-country-flag="' . $safe . '" aria-hidden="true"></span>';
    }

    /**
     * Raw vendor SVG for CDN/binquery payloads (no width/height rewrite).
     */
    public static function readRawSvg(string $countryCode, string $ratio = '4x3'): string
    {
        $path = self::staticFilePath($countryCode, $ratio);
        if ($path === '' || !is_file($path)) {
            return '';
        }
        $svg = @file_get_contents($path);
        if (!\is_string($svg) || $svg === '') {
            return '';
        }
        // Strip XML declaration — safer for data-URI / JSON clients.
        $svg = (string)preg_replace('/<\\?xml[^?]*\\?>/i', '', $svg);

        return trim($svg);
    }

    /**
     * @return array{code: string, svg: string, sha256: string, version: string, ratio: string}|null
     */
    public static function payloadFor(string $countryCode, string $ratio = '4x3'): ?array
    {
        $code = self::normalizeCountryCode($countryCode);
        $ratio = self::normalizeRatio($ratio);
        if ($code === '') {
            return null;
        }
        $svg = self::readRawSvg($code, $ratio);
        if ($svg === '') {
            return null;
        }

        return [
            'code' => $code,
            'svg' => $svg,
            'sha256' => hash('sha256', $svg),
            'version' => self::ASSET_VERSION,
            'ratio' => $ratio,
        ];
    }

    /**
     * @param list<string> $countryCodes
     * @return array{flags: array<string, array{code: string, svg: string, sha256: string, version: string, ratio: string}>, missing: list<string>, version: string, ratio: string}
     */
    public static function payloadsFor(array $countryCodes, string $ratio = '4x3', int $maxItems = 16): array
    {
        $ratio = self::normalizeRatio($ratio);
        $maxItems = max(1, min(32, $maxItems));
        $codes = [];
        foreach ($countryCodes as $raw) {
            $code = self::normalizeCountryCode((string)$raw);
            if ($code === '' || isset($codes[$code])) {
                continue;
            }
            $codes[$code] = true;
        }
        $ordered = array_keys($codes);
        sort($ordered, SORT_STRING);
        $ordered = array_slice($ordered, 0, $maxItems);

        $flags = [];
        $missing = [];
        foreach ($ordered as $code) {
            $payload = self::payloadFor($code, $ratio);
            if ($payload === null) {
                $missing[] = $code;
                continue;
            }
            $flags[$code] = $payload;
        }

        return [
            'flags' => $flags,
            'missing' => $missing,
            'version' => self::ASSET_VERSION,
            'ratio' => $ratio,
        ];
    }

    public static function imgFromCountryCode(
        string $countryCode,
        int $width = 24,
        int $height = 18,
        bool $autoSize = false,
        string $class = 'w-flag-icon',
    ): string {
        $code = self::normalizeCountryCode($countryCode);
        if ($code === '' || !self::staticFlagExists($code)) {
            return '';
        }

        $url = htmlspecialchars(self::staticUrl($code), ENT_QUOTES, 'UTF-8');
        $classAttr = htmlspecialchars(trim($class) !== '' ? trim($class) : 'w-flag-icon', ENT_QUOTES, 'UTF-8');
        $alt = htmlspecialchars(strtoupper($code), ENT_QUOTES, 'UTF-8');

        if ($autoSize) {
            return '<img class="' . $classAttr . '" src="' . $url . '" alt="' . $alt . '"'
                . ' decoding="async" loading="lazy"'
                . ' style="width:auto;height:1.2em;max-height:20px;vertical-align:middle;display:inline-block;" />';
        }

        $width = $width > 0 ? $width : 24;
        $height = $height > 0 ? $height : 18;

        return '<img class="' . $classAttr . '" src="' . $url . '" alt="' . $alt . '"'
            . ' width="' . $width . '" height="' . $height . '"'
            . ' decoding="async" loading="lazy" />';
    }

    /**
     * Convert catalog / legacy inline SVG flag markup into a safe display HTML snippet.
     * Prefer placeholders for storefront chrome; this helper remains for admin/legacy.
     */
    public static function toDisplayHtml(
        string $markup,
        int $width = 24,
        int $height = 18,
        string $class = 'w-flag-icon',
    ): string {
        $markup = trim($markup);
        if ($markup === '') {
            return '';
        }

        if (preg_match('/^<img\b/i', $markup) === 1) {
            return $markup;
        }

        $code = self::detectCountryCodeFromMarkup($markup);
        if ($code !== null && self::staticFlagExists($code)) {
            return self::imgFromCountryCode($code, $width, $height, false, $class);
        }

        $pathCount = substr_count(strtolower($markup), '<path');
        if (strlen($markup) > self::INLINE_BYTE_BUDGET || $pathCount > self::INLINE_PATH_BUDGET) {
            return '';
        }

        return InlineSvgIdUniquifier::uniquify($markup);
    }

    public static function detectCountryCodeFromMarkup(string $markup): ?string
    {
        if (preg_match('/\bid=(["\'])flag-icons-([a-z]{2})(?:-[^"\']*)?\1/i', $markup, $match) === 1) {
            return self::normalizeCountryCode((string)$match[2]);
        }
        if (preg_match('/\bsrc=(["\'])[^"\']*\/flags\/(?:4x3|1x1)\/([a-z]{2})\.svg(?:\?[^"\']*)?\1/i', $markup, $match) === 1) {
            return self::normalizeCountryCode((string)$match[2]);
        }

        return null;
    }
}
