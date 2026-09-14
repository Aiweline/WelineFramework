<?php

declare(strict_types=1);

namespace Weline\Shipping\Service;

/**
 * Incoterm → checkout duty notice (no amount change).
 */
final class ShippingIncotermService
{
    public const DDP = 'ddp';
    public const DDU = 'ddu';
    public const DAP = 'dap';

    public function normalize(string $raw): string
    {
        $v = strtolower(trim($raw));

        return match ($v) {
            self::DDP, self::DAP => $v,
            default => self::DDU,
        };
    }

    public function dutyNotice(string $incoterm): string
    {
        return match ($this->normalize($incoterm)) {
            self::DDP => 'duty_included_by_seller_estimate_separate',
            self::DAP => 'duties_may_apply_at_delivery',
            default => 'duties_taxes_not_included_in_shipping',
        };
    }
}
