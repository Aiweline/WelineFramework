<?php

declare(strict_types=1);

namespace Weline\Marketing\Api\Quote;

/** Minor-unit discount quote request aligned with Checkout freeze output. */
final class DiscountQuoteRequest
{
    /**
     * @param list<array<string, mixed>> $lines
     * @param list<array<string, mixed>> $orders bucketed orders from checkout freeze
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $address
     */
    public function __construct(
        public readonly array $scope,
        public readonly array $address,
        public readonly array $lines = [],
        public readonly array $orders = [],
        public readonly string $currency = 'CNY',
        public readonly int $currencyPrecision = 2,
        public readonly ?int $customerId = null,
        public readonly int $shippingAmountMinor = 0,
        public readonly ?string $couponCode = null,
        public readonly ?string $paymentMethod = null,
        public readonly string $cartHash = '',
    ) {
    }

    public function requestHash(): string
    {
        $payload = [
            'scope' => $this->scope,
            'address' => $this->canonical($this->address),
            'lines' => $this->canonicalLines($this->lines),
            'orders' => $this->canonicalLines($this->orders),
            'currency' => $this->currency,
            'currency_precision' => $this->currencyPrecision,
            'customer_id' => $this->customerId,
            'shipping_amount_minor' => $this->shippingAmountMinor,
            'coupon_code' => strtoupper(trim((string)($this->couponCode ?? ''))),
            'payment_method' => (string)($this->paymentMethod ?? ''),
            'cart_hash' => $this->cartHash,
        ];

        return hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private function canonical(array $data): array
    {
        ksort($data);
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = $this->canonical($value);
            }
        }

        return $data;
    }

    /**
     * @param list<array<string, mixed>> $lines
     * @return list<array<string, mixed>>
     */
    private function canonicalLines(array $lines): array
    {
        $out = [];
        foreach ($lines as $line) {
            $out[] = $this->canonical($line);
        }
        usort($out, static fn (array $a, array $b): int => strcmp(
            (string)($a['line_uuid'] ?? $a['sku'] ?? ''),
            (string)($b['line_uuid'] ?? $b['sku'] ?? ''),
        ));

        return $out;
    }
}
