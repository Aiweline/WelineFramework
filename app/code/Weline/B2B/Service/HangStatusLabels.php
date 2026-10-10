<?php

declare(strict_types=1);

namespace Weline\B2B\Service;

use Weline\B2B\Model\B2BOrderHang;

/** Shared hang_status labels for checkout + backend lifecycle stage. */
final class HangStatusLabels
{
    public static function label(string $status, bool $backendPaymentComplete = false): string
    {
        $status = strtolower(trim($status));

        return match ($status) {
            B2BOrderHang::STATUS_AWAITING_DEPOSIT => (string)__('待付定金'),
            B2BOrderHang::STATUS_AWAITING_MERCHANT_APPROVAL => (string)__('待商家确认'),
            B2BOrderHang::STATUS_AWAITING_BALANCE => (string)__('待付尾款'),
            B2BOrderHang::STATUS_COMPLETED => $backendPaymentComplete
                ? (string)__('支付完成')
                : (string)__('挂单已完成'),
            B2BOrderHang::STATUS_REJECTED => (string)__('已驳回'),
            B2BOrderHang::STATUS_EXPIRED => (string)__('已过期'),
            default => $status,
        };
    }

    public static function tone(string $status): string
    {
        $status = strtolower(trim($status));

        return match ($status) {
            B2BOrderHang::STATUS_COMPLETED => 'success',
            B2BOrderHang::STATUS_REJECTED, B2BOrderHang::STATUS_EXPIRED => 'danger',
            B2BOrderHang::STATUS_AWAITING_DEPOSIT,
            B2BOrderHang::STATUS_AWAITING_MERCHANT_APPROVAL,
            B2BOrderHang::STATUS_AWAITING_BALANCE => 'warning',
            default => 'muted',
        };
    }
}
