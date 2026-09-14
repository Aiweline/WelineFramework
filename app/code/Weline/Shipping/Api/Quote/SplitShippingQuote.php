<?php

declare(strict_types=1);

namespace Weline\Shipping\Api\Quote;

/** 多仓拆单报价结果（金额合计 + 分仓明细 + hash）。 */
final class SplitShippingQuote
{
    /**
     * @param list<array<string,mixed>> $packages
     */
    public function __construct(
        public readonly string $familyCode,
        public readonly array $packages,
        public readonly int $totalAmountMinor,
        public readonly string $currency,
        public readonly int $currencyPrecision,
        public readonly string $configVersion,
        public readonly string $requestHash,
        public readonly bool $isFree = false,
        public readonly string $freeReason = '',
        public readonly string $quoteId = '',
    ) {
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'family_code' => $this->familyCode,
            'packages' => $this->packages,
            'total_amount_minor' => $this->totalAmountMinor,
            'currency' => $this->currency,
            'currency_precision' => $this->currencyPrecision,
            'config_version' => $this->configVersion,
            'request_hash' => $this->requestHash,
            'is_free' => $this->isFree,
            'free_reason' => $this->freeReason,
            'quote_id' => $this->quoteId,
        ];
    }

    /**
     * @param list<array<string,mixed>> $packages
     */
    public static function buildRequestHash(
        ShippingQuoteRequest $request,
        string $familyCode,
        array $packages,
        int $totalAmountMinor,
    ): string {
        $canonicalPackages = [];
        foreach ($packages as $pkg) {
            if (!is_array($pkg)) {
                continue;
            }
            $lines = $pkg['lines'] ?? [];
            if (!is_array($lines)) {
                $lines = [];
            }
            $lineKeys = [];
            foreach ($lines as $line) {
                if (!is_array($line)) {
                    continue;
                }
                $lineKeys[] = [
                    'line_uuid' => (string)($line['line_uuid'] ?? ''),
                    'sku' => (string)($line['sku'] ?? ''),
                    'offer_id' => (int)($line['offer_id'] ?? $line['product_offer_id'] ?? 0),
                    'qty' => $line['qty'] ?? $line['qty_minor'] ?? null,
                ];
            }
            usort($lineKeys, static fn (array $a, array $b): int => strcmp(
                $a['line_uuid'] . '|' . $a['sku'],
                $b['line_uuid'] . '|' . $b['sku'],
            ));
            $canonicalPackages[] = [
                'split_key' => (string)($pkg['split_key'] ?? ''),
                'warehouse_id' => (int)($pkg['warehouse_id'] ?? 0),
                'service_code' => (string)($pkg['service_code'] ?? ''),
                'amount_minor' => (int)($pkg['amount_minor'] ?? 0),
                'lines' => $lineKeys,
            ];
        }
        usort($canonicalPackages, static fn (array $a, array $b): int => ($a['warehouse_id'] <=> $b['warehouse_id']));
        $payload = [
            'base' => $request->requestHash(),
            'family_code' => $familyCode,
            'packages' => $canonicalPackages,
            'total_amount_minor' => $totalAmountMinor,
            'currency' => $request->currency,
        ];

        return hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');
    }
}
