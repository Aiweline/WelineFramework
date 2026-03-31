<?php

declare(strict_types=1);

namespace WeShop\Tax\Extends\Module\Weline_Framework\Query;

use WeShop\Tax\Service\TaxService;
use Weline\Framework\Service\Query\Provider\QueryProviderInterface;

class TaxQueryProvider implements QueryProviderInterface
{
    public function __construct(
        private readonly TaxService $taxService
    ) {
    }

    public function getProviderName(): string
    {
        return 'tax';
    }

    public function execute(string $operation, array $params = []): mixed
    {
        return match ($operation) {
            'calculateTax' => $this->taxService->calculateTax(
                (float) ($params['subtotal'] ?? 0.0),
                $this->stringOrNull($params['country'] ?? null),
                $this->stringOrNull($params['region'] ?? null),
                $this->extractContext($params)
            ),
            'calculateTaxBreakdown' => $this->taxService->calculateTaxBreakdown(
                (float) ($params['subtotal'] ?? 0.0),
                $this->stringOrNull($params['country'] ?? null),
                $this->stringOrNull($params['region'] ?? null),
                $this->extractContext($params)
            ),
            'getTaxRate' => $this->taxService->getTaxRate(
                $this->stringOrNull($params['country'] ?? null),
                $this->stringOrNull($params['region'] ?? null),
                $this->extractContext($params)
            ),
            default => throw new \InvalidArgumentException(
                (string) __('Tax query provider does not support operation: %{1}', [$operation])
            ),
        };
    }

    public function getDescriptor(): array
    {
        return [
            'provider' => 'tax',
            'name' => __('Tax Query'),
            'description' => __('Provides checkout tax-rate lookup and tax calculation access.'),
            'module' => 'WeShop_Tax',
            'operations' => [
                ['name' => 'calculateTax', 'description' => __('Calculate tax for a checkout/order subtotal.')],
                ['name' => 'calculateTaxBreakdown', 'description' => __('Return total tax plus included and chargeable tax breakdown data.')],
                ['name' => 'getTaxRate', 'description' => __('Resolve the tax rate for a country/region context.')],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function extractContext(array $params): array
    {
        $context = is_array($params['context'] ?? null) ? $params['context'] : [];
        foreach ([
            'discount',
            'shipping_amount',
            'apply_to_shipping',
            'default_rate',
            'country_rates',
            'region_rates',
            'prices_include_tax',
            'price_includes_tax',
            'shipping_includes_tax',
        ] as $key) {
            if (array_key_exists($key, $params) && !array_key_exists($key, $context)) {
                $context[$key] = $params[$key];
            }
        }

        return $context;
    }

    private function stringOrNull(mixed $value): ?string
    {
        $value = trim((string) $value);
        return $value === '' ? null : $value;
    }
}
