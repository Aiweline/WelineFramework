<?php

declare(strict_types=1);

namespace Weline\Order\Api\Data;

/**
 * Frozen post-payment extension context.
 *
 * All monetary values are integer minor units copied from the persisted Order
 * snapshot; hook implementations must not recompute or mutate them.
 */
final class OrderPaidContext
{
    /** @param array<string, mixed> $metadata */
    public function __construct(
        public readonly string $orderUuid,
        public readonly MoneySnapshot $money,
        public readonly ScopeSnapshot $scope,
        public readonly DisplayNumberRef $displayNumber,
        public readonly array $metadata = [],
    ) {
        $this->assertValid();
    }

    /** @param array<string, mixed> $metadata */
    public static function fromOrderRead(
        OrderReadResult $order,
        array $metadata = [],
    ): self {
        $displayNumber = trim((string)$order->displayNumber);
        if ($displayNumber === '') {
            throw new \InvalidArgumentException('order_paid_context_display_number_required');
        }
        $scope = ScopeSnapshot::fromArray([
            'website_id' => $order->websiteId,
            'store_id' => $order->storeId,
            'currency' => $order->currency,
            ...$order->scope,
        ]);

        return new self(
            orderUuid: $order->orderUuid,
            money: MoneySnapshot::fromArray($order->money),
            scope: $scope,
            displayNumber: new DisplayNumberRef(
                $order->numberKind,
                $displayNumber,
                $order->orderUuid,
                $order->websiteId,
                $order->storeId,
            ),
            metadata: $metadata,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'order_uuid' => $this->orderUuid,
            'money' => $this->money->toArray(),
            'scope' => $this->scope->toArray(),
            'display_number' => $this->displayNumber->toArray(),
            'metadata' => $this->metadata,
        ];
    }

    private function assertValid(): void
    {
        if (trim($this->orderUuid) === ''
            || $this->displayNumber->entityUuid !== $this->orderUuid
        ) {
            throw new \InvalidArgumentException('order_paid_context_order_identity_invalid');
        }
        if ($this->displayNumber->websiteId !== $this->scope->websiteId
            || $this->displayNumber->storeId !== $this->scope->storeId
        ) {
            throw new \InvalidArgumentException('order_paid_context_scope_mismatch');
        }
        if (\preg_match('/^[A-Z]{3}$/D', $this->money->currency) !== 1
            || $this->money->currency !== $this->scope->currency
        ) {
            throw new \InvalidArgumentException('order_paid_context_currency_mismatch');
        }
        $amounts = [
            $this->money->subtotalMinor,
            $this->money->shippingAmountMinor,
            $this->money->taxAmountMinor,
            $this->money->discountAmountMinor,
            $this->money->grandTotalMinor,
        ];
        foreach ($amounts as $amount) {
            if ($amount < 0) {
                throw new \InvalidArgumentException('order_paid_context_minor_amount_invalid');
            }
        }
        $gross = $this->safeAdd(
            $this->safeAdd(
                $this->money->subtotalMinor,
                $this->money->shippingAmountMinor,
            ),
            $this->money->taxAmountMinor,
        );
        if ($this->money->discountAmountMinor > $gross
            || $gross - $this->money->discountAmountMinor !== $this->money->grandTotalMinor
        ) {
            throw new \InvalidArgumentException('order_paid_context_grand_total_mismatch');
        }
    }

    private function safeAdd(int $left, int $right): int
    {
        if ($right > PHP_INT_MAX - $left) {
            throw new \InvalidArgumentException('order_paid_context_minor_amount_overflow');
        }

        return $left + $right;
    }
}
