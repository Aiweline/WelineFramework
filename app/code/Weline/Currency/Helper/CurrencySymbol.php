<?php

declare(strict_types=1);

namespace Weline\Currency\Helper;

use Weline\Currency\Data\CurrencyData;
use Weline\Websites\Data\WebsiteData;

/**
 * Resolve a display currency glyph for storefront prices.
 *
 * Prefer configured symbol, then a curated glyph map, then ICU. Never inflate
 * buy-box / card prices with a bare ISO code when a real glyph exists.
 *
 * Resolved glyphs are process-memoized until clearProcessCache / clearCache /
 * process_cache_resetter — same website currency reads are static in-process.
 */
final class CurrencySymbol
{
    /**
     * Well-known glyphs for currencies where ICU may return the ISO code.
     *
     * @var array<string, string>
     */
    private const GLYPHS = [
        'CNY' => '¥',
        'RMB' => '¥',
        'USD' => '$',
        'EUR' => '€',
        'GBP' => '£',
        'JPY' => '¥',
        'AUD' => 'A$',
        'CAD' => 'C$',
        'HKD' => 'HK$',
        'SGD' => 'S$',
        'NZD' => 'NZ$',
        'KRW' => '₩',
        'INR' => '₹',
        'THB' => '฿',
        'RUB' => '₽',
        'BRL' => 'R$',
        'MXN' => 'MX$',
        'PHP' => '₱',
        'VND' => '₫',
        'TRY' => '₺',
        'ILS' => '₪',
        'PLN' => 'zł',
        'SEK' => 'kr',
        'NOK' => 'kr',
        'DKK' => 'kr',
        'CHF' => 'Fr',
        'ZAR' => 'R',
        'AED' => 'د.إ',
        'SAR' => '﷼',
        'MYR' => 'RM',
        'IDR' => 'Rp',
    ];

    /** @var array<string, string> ISO → glyph */
    private static array $processGlyphByCode = [];

    /** @var array<string, string> ISO → left|right */
    private static array $processPositionByCode = [];

    public static function forCode(?string $currencyCode): string
    {
        $code = strtoupper(trim((string)$currencyCode));
        if ($code === '') {
            $code = 'CNY';
        }

        if (isset(self::$processGlyphByCode[$code])) {
            return self::$processGlyphByCode[$code];
        }

        $configured = self::configuredSymbol($code);
        if (self::isUsableGlyph($configured, $code)) {
            return self::$processGlyphByCode[$code] = $configured;
        }

        if (isset(self::GLYPHS[$code])) {
            return self::$processGlyphByCode[$code] = self::GLYPHS[$code];
        }

        $intl = self::intlSymbol($code);
        if (self::isUsableGlyph($intl, $code)) {
            return self::$processGlyphByCode[$code] = $intl;
        }

        return self::$processGlyphByCode[$code] = $code;
    }

    /**
     * Compact map for frontend JS (ISO → glyph).
     *
     * @return array<string, string>
     */
    public static function clientMap(): array
    {
        $map = self::GLYPHS;
        try {
            foreach (CurrencyData::getCurrencies() as $currency) {
                if (!is_array($currency)) {
                    continue;
                }
                $code = strtoupper(trim((string)($currency['code'] ?? '')));
                if ($code === '') {
                    continue;
                }
                $map[$code] = self::forCode($code);
            }
        } catch (\Throwable) {
            // Seed map alone is enough for offline / cold boot.
        }

        return $map;
    }

    public static function formatAmount(float $amount, ?string $currencyCode = null, int $decimals = 2): string
    {
        $code = strtoupper(trim((string)($currencyCode ?? 'CNY'))) ?: 'CNY';
        $symbol = self::forCode($code);
        $formatted = number_format($amount, max(0, $decimals), '.', ',');
        $position = self::positionFor($code);

        return $position === 'right'
            ? $formatted . $symbol
            : $symbol . $formatted;
    }

    public static function clearProcessCache(): void
    {
        self::$processGlyphByCode = [];
        self::$processPositionByCode = [];
    }

    private static function configuredSymbol(string $code): string
    {
        // Website request/process snapshot first — already memoized for this site.
        try {
            if (WebsiteData::hasCurrencySnapshot()) {
                $symbol = trim((string)(WebsiteData::getCurrencySymbol($code) ?? ''));
                if ($symbol !== '') {
                    return $symbol;
                }
            }
        } catch (\Throwable) {
        }

        try {
            $currency = CurrencyData::getCurrency($code);
            if (is_array($currency)) {
                $symbol = trim((string)($currency['symbol'] ?? $currency['icon'] ?? ''));
                if ($symbol !== '') {
                    return $symbol;
                }
            }
        } catch (\Throwable) {
        }

        try {
            $symbol = trim((string)(WebsiteData::getCurrencySymbol($code) ?? ''));
            if ($symbol !== '') {
                return $symbol;
            }
        } catch (\Throwable) {
        }

        return '';
    }

    private static function intlSymbol(string $code): string
    {
        if (!class_exists(\Symfony\Component\Intl\Currencies::class)) {
            return '';
        }

        try {
            return trim((string)\Symfony\Component\Intl\Currencies::getSymbol($code));
        } catch (\Throwable) {
            return '';
        }
    }

    private static function isUsableGlyph(string $symbol, string $code): bool
    {
        $symbol = trim($symbol);
        if ($symbol === '') {
            return false;
        }

        // Bare ISO code is not a price glyph (USD / GBP next to 28px whole).
        if (strcasecmp($symbol, $code) === 0) {
            return false;
        }

        return true;
    }

    private static function positionFor(string $code): string
    {
        if (isset(self::$processPositionByCode[$code])) {
            return self::$processPositionByCode[$code];
        }

        try {
            if (WebsiteData::hasCurrencySnapshot()) {
                $position = strtolower(trim((string)(WebsiteData::getCurrencyPosition($code) ?? '')));
                if ($position === 'right' || $position === 'left') {
                    return self::$processPositionByCode[$code] = $position;
                }
            }
        } catch (\Throwable) {
        }

        try {
            $currency = CurrencyData::getCurrency($code);
            if (is_array($currency)) {
                $position = strtolower(trim((string)($currency['position'] ?? 'left')));
                if ($position === 'right') {
                    return self::$processPositionByCode[$code] = 'right';
                }
                if ($position === 'left') {
                    return self::$processPositionByCode[$code] = 'left';
                }
            }
        } catch (\Throwable) {
        }

        return self::$processPositionByCode[$code] = ($code === 'EUR' ? 'right' : 'left');
    }
}
