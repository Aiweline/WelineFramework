<?php

declare(strict_types=1);

namespace Weline\Order\Api\Data;

/**
 * Immutable tax snapshot on CheckoutGroup / Order / OrderItem / Invoice.
 * It carries the exact scope, rule version and line results used at quote time
 * and is copied downstream without recalculation.
 */
final class TaxSnapshot
{
    /**
     * @param list<array<string,mixed>> $lines
     */
    public function __construct(
        public readonly int $taxAmountMinor = 0,
        public readonly string $mode = 'stub_zero',
        public readonly string $note = 'server_written_zero_tax',
        public readonly string $ruleSchemaVersion = '',
        public readonly string $ruleSetHash = '',
        public readonly string $engine = 'none',
        public readonly array $lines = [],
        public readonly string $jurisdictionKey = '',
        public readonly string $currency = '',
        public readonly string $scopeKey = '',
        public readonly int $websiteId = 0,
        public readonly int $storeId = 0,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'tax_amount_minor' => $this->taxAmountMinor,
            'mode' => $this->mode,
            'note' => $this->note,
            'rule_schema_version' => $this->ruleSchemaVersion,
            'rule_set_hash' => $this->ruleSetHash,
            'engine' => $this->engine,
            'lines' => $this->lines,
            'jurisdiction_key' => $this->jurisdictionKey,
            'currency' => $this->currency,
            'scope_key' => $this->scopeKey,
            'website_id' => $this->websiteId,
            'store_id' => $this->storeId,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            taxAmountMinor: (int) ($data['tax_amount_minor'] ?? 0),
            mode: (string) ($data['mode'] ?? 'stub_zero'),
            note: (string) ($data['note'] ?? 'server_written_zero_tax'),
            ruleSchemaVersion: (string) ($data['rule_schema_version'] ?? ''),
            ruleSetHash: (string) ($data['rule_set_hash'] ?? ''),
            engine: (string) ($data['engine'] ?? 'none'),
            lines: is_array($data['lines'] ?? null)
                ? array_values(array_filter($data['lines'], 'is_array'))
                : [],
            jurisdictionKey: (string) ($data['jurisdiction_key'] ?? ''),
            currency: (string) ($data['currency'] ?? ''),
            scopeKey: (string) ($data['scope_key'] ?? ''),
            websiteId: (int) ($data['website_id'] ?? 0),
            storeId: (int) ($data['store_id'] ?? 0),
        );
    }

    public static function legacyFrozen(
        int $taxAmountMinor,
        string $currency = '',
        int $websiteId = 0,
        int $storeId = 0,
    ): self {
        return new self(
            taxAmountMinor: $taxAmountMinor,
            mode: 'legacy_frozen',
            note: 'legacy_money_snapshot',
            engine: 'none',
            currency: $currency,
            websiteId: $websiteId,
            storeId: $storeId,
        );
    }
}
