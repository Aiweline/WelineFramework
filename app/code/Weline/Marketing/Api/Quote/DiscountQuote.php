<?php

declare(strict_types=1);

namespace Weline\Marketing\Api\Quote;

/** Immutable marketing discount quote in minor units. */
final class DiscountQuote
{
    /**
     * @param list<array<string, mixed>> $lines
     * @param list<int> $appliedRuleIds
     * @param list<array<string, mixed>> $actionPayloads
     */
    public function __construct(
        public readonly string $discountQuoteToken,
        public readonly int $amountMinor,
        public readonly string $currency,
        public readonly int $currencyPrecision,
        public readonly string $requestHash,
        public readonly array $lines = [],
        public readonly array $appliedRuleIds = [],
        public readonly string $couponCode = '',
        public readonly array $actionPayloads = [],
        public readonly bool $freeShipping = false,
        public readonly int $shippingDiscountMinor = 0,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'discount_quote_token' => $this->discountQuoteToken,
            'amount_minor' => $this->amountMinor,
            'currency' => $this->currency,
            'currency_precision' => $this->currencyPrecision,
            'request_hash' => $this->requestHash,
            'lines' => $this->lines,
            'applied_rule_ids' => $this->appliedRuleIds,
            'coupon_code' => $this->couponCode,
            'action_payloads' => $this->actionPayloads,
            'free_shipping' => $this->freeShipping,
            'shipping_discount_minor' => $this->shippingDiscountMinor,
        ];
    }
}
