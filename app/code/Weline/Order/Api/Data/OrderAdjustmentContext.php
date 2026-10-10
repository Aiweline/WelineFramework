<?php

declare(strict_types=1);

namespace Weline\Order\Api\Data;

/**
 * 后台金额调整策略读上下文（只读投影，不含写权限）。
 */
final class OrderAdjustmentContext
{
    /**
     * @param array<string, mixed> $money
     * @param array<string, mixed> $typePayload
     */
    public function __construct(
        public readonly string $orderUuid,
        public readonly int $orderId,
        public readonly string $orderType,
        public readonly string $status,
        public readonly string $paymentStatus,
        public readonly array $money,
        public readonly array $typePayload,
        public readonly int $paidCapturedMinor,
        public readonly int $adminUserId,
    ) {
    }

    public function subtotalMinor(): int
    {
        return max(0, (int)($this->money['subtotal_minor'] ?? 0));
    }

    public function shippingMinor(): int
    {
        return max(0, (int)($this->money['shipping_amount_minor'] ?? 0));
    }

    public function taxMinor(): int
    {
        return max(0, (int)($this->money['tax_amount_minor'] ?? 0));
    }

    public function discountMinor(): int
    {
        return max(0, (int)($this->money['discount_amount_minor'] ?? 0));
    }

    public function codFeeMinor(): int
    {
        return max(0, (int)($this->money['cod_fee_amount_minor'] ?? 0));
    }

    public function grandTotalMinor(): int
    {
        return max(0, (int)($this->money['grand_total_minor'] ?? 0));
    }

    public function currency(): string
    {
        return strtoupper(trim((string)($this->money['currency'] ?? 'CNY'))) ?: 'CNY';
    }
}
