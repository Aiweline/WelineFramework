<?php

declare(strict_types=1);

namespace Weline\Feed\Api\Data;

/**
 * Neutral feed row. `id` is the offer UUID. `groupId` is the parent product id.
 * `link` is an opaque absolute URL; callers do not strip its query.
 */
final readonly class FeedItem
{
    /**
     * @param list<string> $additionalImageLinks
     * @param list<array{code:string,label:string,value:string}> $attributes
     */
    public function __construct(
        public string $id,
        public int $productId,
        public string $globalOfferUuid,
        public string $groupId,
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
        public array $attributes,
        public ?string $channelCategory = null,
        public ?string $channelCategoryName = null,
        public int $storeCategoryId = 0,
    ) {
    }

    public function withChannelCategory(?string $category, ?string $name, int $storeCategoryId): self
    {
        return new self(
            id: $this->id,
            productId: $this->productId,
            globalOfferUuid: $this->globalOfferUuid,
            groupId: $this->groupId,
            sku: $this->sku,
            title: $this->title,
            description: $this->description,
            brand: $this->brand,
            link: $this->link,
            imageLink: $this->imageLink,
            additionalImageLinks: $this->additionalImageLinks,
            availability: $this->availability,
            currency: $this->currency,
            priceMinor: $this->priceMinor,
            salePriceMinor: $this->salePriceMinor,
            gtin: $this->gtin,
            mpn: $this->mpn,
            attributes: $this->attributes,
            channelCategory: $category,
            channelCategoryName: $name,
            storeCategoryId: $storeCategoryId,
        );
    }
}
