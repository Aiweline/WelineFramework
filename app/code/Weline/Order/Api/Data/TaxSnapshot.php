<?php

declare(strict_types=1);

namespace Weline\Order\Api\Data;

/**
 * Immutable tax snapshot on CheckoutGroup / Order / OrderItem / Invoice.
 * It carries the exact scope, rule version and line results used at quote time
 * and is copied downstream without recalculation.
 *
 * Buyer tax identity (VAT ID etc.) belongs on CheckoutGroup / Order header snapshots only;
 * OrderItem line snapshots keep buyer_* empty by default.
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
        public readonly string $buyerTaxId = '',
        public readonly string $buyerTaxIdType = '',
        public readonly string $buyerTaxCountry = '',
        public readonly string $buyerCompanyName = '',
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
            'buyer_tax_id' => $this->buyerTaxId,
            'buyer_tax_id_type' => $this->buyerTaxIdType,
            'buyer_tax_country' => $this->buyerTaxCountry,
            'buyer_company_name' => $this->buyerCompanyName,
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
            buyerTaxId: (string) ($data['buyer_tax_id'] ?? ''),
            buyerTaxIdType: (string) ($data['buyer_tax_id_type'] ?? ''),
            buyerTaxCountry: (string) ($data['buyer_tax_country'] ?? ''),
            buyerCompanyName: (string) ($data['buyer_company_name'] ?? ''),
        );
    }

    /**
     * Copy buyer identity onto a new snapshot (order header); leave amount/rules from $base.
     */
    public function withBuyerIdentity(
        string $buyerTaxId,
        string $buyerTaxIdType = '',
        string $buyerTaxCountry = '',
        string $buyerCompanyName = '',
    ): self {
        return new self(
            taxAmountMinor: $this->taxAmountMinor,
            mode: $this->mode,
            note: $this->note,
            ruleSchemaVersion: $this->ruleSchemaVersion,
            ruleSetHash: $this->ruleSetHash,
            engine: $this->engine,
            lines: $this->lines,
            jurisdictionKey: $this->jurisdictionKey,
            currency: $this->currency,
            scopeKey: $this->scopeKey,
            websiteId: $this->websiteId,
            storeId: $this->storeId,
            buyerTaxId: $buyerTaxId,
            buyerTaxIdType: $buyerTaxIdType,
            buyerTaxCountry: $buyerTaxCountry,
            buyerCompanyName: $buyerCompanyName,
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
