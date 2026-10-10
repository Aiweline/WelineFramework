<?php

declare(strict_types=1);

namespace Weline\Payment\Service;

/**
 * Per-payment-attempt entry codes (not order.checkout_entry).
 *
 * One unpaid order may carry express → failed, then continue_pay → paid;
 * each PaymentTransaction / Attempt row keeps its own payment_entry.
 */
final class PaymentEntry
{
    public const CHECKOUT = 'checkout';
    public const EXPRESS = 'express';
    public const CONTINUE_PAY = 'continue_pay';
    public const QUICK_BUY = 'quick_buy';
    public const HELP_PAY = 'helppay';
    public const UNKNOWN = 'unknown';

    /** @return list<string> */
    public static function codes(): array
    {
        return [
            self::CHECKOUT,
            self::EXPRESS,
            self::CONTINUE_PAY,
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
            self::CONTINUE_PAY => (string) __('继续支付'),
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
            self::CONTINUE_PAY => 'warning',
            self::QUICK_BUY => 'success',
            self::HELP_PAY => 'warning',
            default => 'muted',
        };
    }

    /**
     * Resolve entry from create/pay context or stored request_data / snapshot.
     *
     * @param array<string, mixed> $context
     */
    public static function resolveFromContext(array $context): string
    {
        $explicit = self::normalize((string) ($context['payment_entry'] ?? ''), self::UNKNOWN);
        if ($explicit !== self::UNKNOWN) {
            return $explicit;
        }

        $metadata = \is_array($context['metadata'] ?? null) ? $context['metadata'] : [];
        $metaEntry = self::normalize((string) ($metadata['payment_entry'] ?? ''), self::UNKNOWN);
        if ($metaEntry !== self::UNKNOWN) {
            return $metaEntry;
        }

        $paymentMode = strtolower(trim((string) (
            $context['payment_mode']
            ?? $metadata['payment_mode']
            ?? ''
        )));
        if (
            $paymentMode === self::CONTINUE_PAY
            || !empty($context['continue_pay'])
            || !empty($metadata['continue_pay'])
        ) {
            return self::CONTINUE_PAY;
        }

        if (
            !empty($context['express_checkout'])
            || !empty($metadata['express_checkout'])
        ) {
            return self::EXPRESS;
        }

        $mode = strtolower(trim((string) ($metadata['mode'] ?? $context['mode'] ?? '')));
        if ($mode === 'quick_pay_self' || $mode === self::QUICK_BUY) {
            return self::QUICK_BUY;
        }
        if ($mode === 'help_pay' || $mode === self::HELP_PAY) {
            return self::HELP_PAY;
        }

        $tags = $context['business_tags'] ?? $metadata['business_tags'] ?? [];
        if (\is_array($tags)) {
            $tagSet = [];
            foreach ($tags as $tag) {
                $tagSet[strtolower(trim((string) $tag))] = true;
            }
            if (isset($tagSet['quick_pay_self'])) {
                return self::QUICK_BUY;
            }
            if (isset($tagSet['help_pay']) || isset($tagSet['helppay'])) {
                return self::HELP_PAY;
            }
        }

        $checkoutEntry = self::normalize(
            (string) ($context['checkout_entry'] ?? $metadata['checkout_entry'] ?? ''),
            self::UNKNOWN,
        );
        if ($checkoutEntry !== self::UNKNOWN && $checkoutEntry !== self::CONTINUE_PAY) {
            // checkout_entry is order-level; map known storefront entries when payment_entry absent.
            if (\in_array($checkoutEntry, [self::CHECKOUT, self::EXPRESS, self::QUICK_BUY, self::HELP_PAY], true)) {
                return $checkoutEntry;
            }
        }

        return self::UNKNOWN;
    }

    /**
     * Stamp payment_entry onto create context (top-level + metadata).
     *
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public static function stampContext(array $context, string $default = self::UNKNOWN): array
    {
        $entry = self::resolveFromContext($context);
        if ($entry === self::UNKNOWN && $default !== self::UNKNOWN) {
            $entry = self::normalize($default, self::UNKNOWN);
        }
        $context['payment_entry'] = $entry;
        $metadata = \is_array($context['metadata'] ?? null) ? $context['metadata'] : [];
        $metadata['payment_entry'] = $entry;
        $context['metadata'] = $metadata;

        return $context;
    }
}
