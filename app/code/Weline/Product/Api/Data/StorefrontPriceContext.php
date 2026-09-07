<?php

declare(strict_types=1);

namespace Weline\Product\Api\Data;

/**
 * Input for storefront unit-price assembly (catalog offer → PriceView).
 */
final readonly class StorefrontPriceContext
{
    public function __construct(
        public int $productId,
        public int $catalogPriceMinor,
        public string $currency = 'CNY',
        public int $websiteId = 0,
        public int $storeId = 0,
        public string $locale = '',
        public string $sku = '',
        public string $globalOfferUuid = '',
        /** @var array<string, scalar|null> */
        public array $selection = [],
        public string|int|null $customerId = null,
        /** toc|tob — empty means unspecified retail path */
        public string $sellingMode = '',
    ) {
    }

    public static function fromCatalogMinor(
        int $productId,
        int $catalogPriceMinor,
        string $currency = 'CNY',
    ): self {
        return new self(
            productId: max(0, $productId),
            catalogPriceMinor: max(0, $catalogPriceMinor),
            currency: strtoupper(trim($currency)) ?: 'CNY',
        );
    }

    public function normalizedSellingMode(): string
    {
        return strtolower(trim($this->sellingMode));
    }

    public function customerIdString(): string
    {
        if ($this->customerId === null) {
            return '';
        }

        return trim((string)$this->customerId);
    }
}
