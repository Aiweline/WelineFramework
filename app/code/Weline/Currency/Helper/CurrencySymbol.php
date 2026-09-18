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

    public static function forCode(?string $currencyCode): string
    {
        $code = strtoupper(trim((string)$currencyCode));
        if ($code === '') {
            $code = 'CNY';
        }

        $configured = self::configuredSymbol($code);
        if (self::isUsableGlyph($configured, $code)) {
            return $configured;
        }

        if (isset(self::GLYPHS[$code])) {
            return self::GLYPHS[$code];
        }

        $intl = self::intlSymbol($code);
        if (self::isUsableGlyph($intl, $code)) {
            return $intl;
        }

        return $code;
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

    private static function configuredSymbol(string $code): string
    {
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
        try {
            $currency = CurrencyData::getCurrency($code);
            if (is_array($currency)) {
                $position = strtolower(trim((string)($currency['position'] ?? 'left')));
                if ($position === 'right') {
                    return 'right';
                }
            }
        } catch (\Throwable) {
        }

        return $code === 'EUR' ? 'right' : 'left';
    }
}
