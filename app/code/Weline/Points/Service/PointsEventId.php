<?php

declare(strict_types=1);

namespace Weline\Points\Service;

/**
 * Stable idempotency keys. Replaying the same order/payment/refund must reuse
 * the same key.
 */
final class PointsEventId
{
    public static function earn(
        int $websiteId,
        string $namespace,
        int $orderId,
        string $paymentKey,
    ): string {
        return 'points:earn:' . self::segment((string) $websiteId)
            . ':' . self::segment($namespace)
            . ':' . self::segment((string) $orderId)
            . ':' . self::segment($paymentKey);
    }

    public static function clawback(
        int $websiteId,
        string $namespace,
        int $orderId,
        string $refundKey,
    ): string {
        return 'points:clawback:' . self::segment((string) $websiteId)
            . ':' . self::segment($namespace)
            . ':' . self::segment((string) $orderId)
            . ':' . self::segment($refundKey);
    }

    private static function segment(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return 'none';
        }

        return preg_replace('/[^A-Za-z0-9_.-]+/', '-', $value) ?? 'none';
    }
}
