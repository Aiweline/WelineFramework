<?php

declare(strict_types=1);

namespace Weline\Product\Api\Data;

/**
 * One catalog offer before price assembly.
 *
 * `gtin` must already be a real identifier. Callers must not copy SKU into it.
 */
final readonly class CatalogFeedOfferCandidate
{
    /**
     * @param list<array{code:string,label:string,value:string}> $attributes
     */
    public function __construct(
        public int $offerId,
        public int $productId,
        public string $globalOfferUuid,
        public string $sku,
        public string $title,
        public string $description,
        public string $brand,
        public ?string $gtin,
        public ?string $mpn,
        public string $linkBase,
        public string $imageLink,
        public int $catalogPriceMinor,
        public bool $published,
        public bool $quoteOnly,
        public bool $variant,
        public ?int $stock = null,
        public ?bool $sellable = null,
        public array $attributes = [],
    ) {
    }
}
