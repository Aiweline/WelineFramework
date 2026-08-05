<?php

declare(strict_types=1);

namespace Weline\Product\Service\Capability;

use Weline\Product\Api\Capability\ProductPricingCapabilityInterface;

final class DefaultProductPricingCapability implements ProductPricingCapabilityInterface
{
    /** @param list<string> $currencies */
    public function __construct(
        private readonly array $currencies = ['CNY', 'USD'],
        private readonly bool $allowsCleared = true,
    ) {
    }

    public function supportedCurrencies(): array
    {
        return array_values(array_map(
            static fn (string $c): string => strtoupper(trim($c)),
            $this->currencies,
        ));
    }

    public function supportsCurrency(string $currency): bool
    {
        return in_array(strtoupper(trim($currency)), $this->supportedCurrencies(), true);
    }

    public function allowsClearedPrice(): bool
    {
        return $this->allowsCleared;
    }
}
