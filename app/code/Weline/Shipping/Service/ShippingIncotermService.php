<?php

declare(strict_types=1);

namespace Weline\Shipping\Service;

/**
 * Incoterm → checkout duty notice (no amount change).
 *
 * Machine codes stay in duty_notice for orders/harness; storefront copy uses {@see labelForDutyNoticeCode()}.
 */
final class ShippingIncotermService
{
    public const DDP = 'ddp';
    public const DDU = 'ddu';
    public const DAP = 'dap';

    public const NOTICE_DDP = 'duty_included_by_seller_estimate_separate';
    public const NOTICE_DAP = 'duties_may_apply_at_delivery';
    public const NOTICE_DDU = 'duties_taxes_not_included_in_shipping';

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
            self::DDP => self::NOTICE_DDP,
            self::DAP => self::NOTICE_DAP,
            default => self::NOTICE_DDU,
        };
    }

    /**
     * Human storefront source label for a duty_notice machine code.
     * Callers translate via __() / WidgetI18n::label (do not pre-translate here).
     */
    public function labelForDutyNoticeCode(string $code): string
    {
        $code = trim($code);
        if ($code === '') {
            return '';
        }

        return match ($code) {
            self::NOTICE_DDP => '运费已含卖家预估关税（可能另行列示）',
            self::NOTICE_DAP => '送达时可能另收关税',
            self::NOTICE_DDU => '运费不含关税与税费',
            default => $code,
        };
    }
}
