<?php

declare(strict_types=1);

namespace Weline\Order\Api\Data;

/** Immutable money snapshot (minor units). */
final class MoneySnapshot
{
    public function __construct(
        public readonly string $currency,
        public readonly int $subtotalMinor,
        public readonly int $shippingAmountMinor,
        public readonly int $taxAmountMinor,
        public readonly int $discountAmountMinor = 0,
        public readonly int $grandTotalMinor = 0,
    ) {
    }

    public function withComputedGrandTotal(): self
    {
        return new self(
            $this->currency,
            $this->subtotalMinor,
            $this->shippingAmountMinor,
            $this->taxAmountMinor,
            $this->discountAmountMinor,
            $this->subtotalMinor + $this->shippingAmountMinor + $this->taxAmountMinor - $this->discountAmountMinor,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'currency' => $this->currency,
            'subtotal_minor' => $this->subtotalMinor,
            'shipping_amount_minor' => $this->shippingAmountMinor,
            'tax_amount_minor' => $this->taxAmountMinor,
            'discount_amount_minor' => $this->discountAmountMinor,
            'grand_total_minor' => $this->grandTotalMinor,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            currency: (string)($data['currency'] ?? 'CNY'),
            subtotalMinor: (int)($data['subtotal_minor'] ?? 0),
            shippingAmountMinor: (int)($data['shipping_amount_minor'] ?? 0),
            taxAmountMinor: (int)($data['tax_amount_minor'] ?? 0),
            discountAmountMinor: (int)($data['discount_amount_minor'] ?? 0),
            grandTotalMinor: (int)($data['grand_total_minor'] ?? 0),
        );
    }
}
