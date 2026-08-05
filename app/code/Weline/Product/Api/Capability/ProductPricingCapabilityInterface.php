<?php

declare(strict_types=1);

namespace Weline\Product\Api\Capability;

/**
 * Pricing capability SPI — metadata + currency support only in P2A.
 * Amount resolution stays on Product PriceRepository / overlay.
 */
interface ProductPricingCapabilityInterface
{
    /**
     * @return list<string> ISO currency codes (uppercase)
     */
    public function supportedCurrencies(): array;

    public function supportsCurrency(string $currency): bool;

    public function allowsClearedPrice(): bool;
}
