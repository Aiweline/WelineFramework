<?php

declare(strict_types=1);

namespace Weline\Product\Api\Data;

/**
 * Neutral catalog offer facts for a feed adapter.
 *
 * Prices stay in minor units. `id` is the offer UUID. `itemGroupId` is set
 * only for a real variant and is the parent product id.
 */
final readonly class CatalogFeedOffer
{
    public const AVAILABILITY_IN_STOCK = 'in_stock';
    public const AVAILABILITY_OUT_OF_STOCK = 'out_of_stock';

    /**
     * @param list<string> $additionalImageLinks
     * @param list<array{code:string,label:string,value:string}> $attributes
     */
    public function __construct(
        public string $id,
        public int $offerId,
        public int $productId,
        public string $globalOfferUuid,
        public string $sku,
        public string $title,
        public string $description,
        public string $brand,
        public string $link,
        public string $imageLink,
        public array $additionalImageLinks,
        public string $availability,
        public string $currency,
        public int $priceMinor,
        public ?int $salePriceMinor,
        public ?string $gtin,
        public ?string $mpn,
        public ?string $itemGroupId,
        public array $attributes,
    ) {
    }
}
