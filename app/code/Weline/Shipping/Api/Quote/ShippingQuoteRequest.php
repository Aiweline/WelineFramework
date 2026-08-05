<?php

declare(strict_types=1);

namespace Weline\Shipping\Api\Quote;

/**
 * Minor-unit shipping quote request（MOD-P2E-002）.
 */
final class ShippingQuoteRequest
{
    /**
     * @param array<string, mixed> $address
     * @param list<array<string, mixed>> $lines shippable lines (qty/weight/volume optional)
     * @param array<string, mixed> $scope website/store/channel codes + currency
     */
    public function __construct(
        public readonly array $scope,
        public readonly array $address,
        public readonly array $lines = [],
        public readonly string $currency = 'CNY',
        public readonly int $currencyPrecision = 2,
        public readonly string $configVersion = '1',
        public readonly ?string $serviceCode = null,
    ) {
    }

    public function requestHash(): string
    {
        $payload = [
            'scope' => $this->scope,
            'address' => $this->canonical($this->address),
            'lines' => $this->canonicalLines($this->lines),
            'currency' => $this->currency,
            'currency_precision' => $this->currencyPrecision,
            'config_version' => $this->configVersion,
            'service_code' => $this->serviceCode,
        ];
        return hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private function canonical(array $data): array
    {
        ksort($data);
        foreach ($data as $k => $v) {
            if (is_array($v)) {
                $data[$k] = $this->canonical($v);
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
