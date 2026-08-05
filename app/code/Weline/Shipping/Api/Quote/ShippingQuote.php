<?php

declare(strict_types=1);

namespace Weline\Shipping\Api\Quote;

/** Immutable shipping quote in minor units. */
final class ShippingQuote
{
    public function __construct(
        public readonly string $quoteId,
        public readonly string $serviceCode,
        public readonly int $amountMinor,
        public readonly string $currency,
        public readonly int $currencyPrecision,
        public readonly string $configVersion,
        public readonly string $requestHash,
        public readonly bool $isFree = false,
        public readonly string $freeReason = '',
        public readonly ?string $expiresAt = null,
        public readonly string $scopeVersion = '1',
        public readonly string $ruleVersion = '1',
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'quote_id' => $this->quoteId,
            'service_code' => $this->serviceCode,
            'amount_minor' => $this->amountMinor,
            'currency' => $this->currency,
            'currency_precision' => $this->currencyPrecision,
            'config_version' => $this->configVersion,
            'request_hash' => $this->requestHash,
            'is_free' => $this->isFree,
            'free_reason' => $this->freeReason,
            'expires_at' => $this->expiresAt,
            'scope_version' => $this->scopeVersion,
            'rule_version' => $this->ruleVersion,
        ];
    }
}
