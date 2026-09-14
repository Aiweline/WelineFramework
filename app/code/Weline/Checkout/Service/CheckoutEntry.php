<?php

declare(strict_types=1);

namespace Weline\Checkout\Service;

/**
 * Storefront checkout entry codes (session + order attribution).
 * Server-owned; whitelist only — never invent client authority for money.
 */
final class CheckoutEntry
{
    public const CHECKOUT = 'checkout';
    public const EXPRESS = 'express';
    public const QUICK_BUY = 'quick_buy';
    public const HELP_PAY = 'helppay';
    public const UNKNOWN = 'unknown';

    /** @return list<string> */
    public static function codes(): array
    {
        return [
            self::CHECKOUT,
            self::EXPRESS,
            self::QUICK_BUY,
            self::HELP_PAY,
            self::UNKNOWN,
        ];
    }

    public static function normalize(string $code, string $default = self::UNKNOWN): string
    {
        $code = strtolower(trim($code));
        if ($code === '') {
            return $default;
        }
        if (\in_array($code, self::codes(), true)) {
            return $code;
        }

        return $default;
    }

    public static function label(string $code): string
    {
        return match (self::normalize($code)) {
            self::CHECKOUT => (string) __('万能结账'),
            self::EXPRESS => (string) __('快捷支付'),
            self::QUICK_BUY => (string) __('快捷购买'),
            self::HELP_PAY => (string) __('找朋友代付'),
            default => (string) __('未标记'),
        };
    }

    public static function tone(string $code): string
    {
        return match (self::normalize($code)) {
            self::CHECKOUT => 'info',
            self::EXPRESS => 'primary',
            self::QUICK_BUY => 'success',
            self::HELP_PAY => 'warning',
            default => 'muted',
        };
    }

    /**
     * @return list<array{code:string,label:string}>
     */
    public static function filterRows(): array
    {
        $rows = [];
        foreach ([self::CHECKOUT, self::EXPRESS, self::QUICK_BUY, self::HELP_PAY] as $code) {
            $rows[] = ['code' => $code, 'label' => self::label($code)];
        }

        return $rows;
    }
}
