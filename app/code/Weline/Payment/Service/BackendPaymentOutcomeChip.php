<?php

declare(strict_types=1);

namespace Weline\Payment\Service;

/**
 * 后台支付记录状态四色芯片：已支付绿 / 待支付黄 / 失败红 / 已退款蓝。
 */
final class BackendPaymentOutcomeChip
{
    /**
     * @return array{tone: string, icon: string, label: string, kind: string, raw: string}
     */
    public static function present(string $raw): array
    {
        $code = strtolower(trim($raw));
        if ($code === '') {
            return [
                'tone' => '',
                'icon' => '',
                'label' => '',
                'kind' => 'empty',
                'raw' => '',
            ];
        }

        return match (true) {
            self::isPaid($code) => [
                'tone' => 'success',
                'icon' => 'check-circle',
                'label' => (string)\__('已支付'),
                'kind' => 'paid',
                'raw' => $code,
            ],
            self::isRefunded($code) => [
                'tone' => 'info',
                'icon' => 'refresh',
                'label' => (string)\__('已退款'),
                'kind' => 'refunded',
                'raw' => $code,
            ],
            self::isFailed($code) => [
                'tone' => 'danger',
                'icon' => 'x-circle',
                'label' => (string)\__('失败'),
                'kind' => 'failed',
                'raw' => $code,
            ],
            self::isPending($code) => [
                'tone' => 'warning',
                'icon' => 'clock',
                'label' => (string)\__('待支付'),
                'kind' => 'pending',
                'raw' => $code,
            ],
            default => [
                'tone' => 'secondary',
                'icon' => 'circle',
                'label' => $raw,
                'kind' => 'other',
                'raw' => $code,
            ],
        };
    }

    private static function isPaid(string $code): bool
    {
        return \in_array($code, ['paid', 'succeeded', 'success', 'captured', 'completed', 'done'], true);
    }

    private static function isRefunded(string $code): bool
    {
        return \in_array($code, ['refunded', 'partially_refunded', 'partial_refund'], true);
    }

    private static function isFailed(string $code): bool
    {
        return \in_array($code, ['failed', 'fail', 'error', 'canceled', 'cancelled', 'rejected'], true);
    }

    private static function isPending(string $code): bool
    {
        return \in_array($code, ['pending', 'processing', 'requires_action', 'submitted', 'open'], true);
    }
}
