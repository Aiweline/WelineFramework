<?php

declare(strict_types=1);

namespace Weline\Product\Api\Data;

/**
 * Unified storefront unit-price view for cards / PDP / cart snapshot bake-in.
 */
final readonly class StorefrontOfferPriceView
{
    /**
     * @param list<StorefrontPriceAdjustment> $appliedAdjustments
     * @param array{label:string,url:string,badge:string,code:string,source_module:string,source_type:string,source_id:string}|null $primaryCampaign
     */
    public function __construct(
        public string $currency,
        public int $catalogPriceMinor,
        public int $finalPriceMinor,
        public int $compareAtMinor,
        public bool $hasDeal,
        public array $appliedAdjustments = [],
        public ?array $primaryCampaign = null,
    ) {
    }

    public function finalPrice(): float
    {
        return round($this->finalPriceMinor / 100, 2);
    }

    public function compareAtPrice(): float
    {
        return round($this->compareAtMinor / 100, 2);
    }

    public function catalogPrice(): float
    {
        return round($this->catalogPriceMinor / 100, 2);
    }

    public function campaignLabel(): string
    {
        return trim((string)($this->primaryCampaign['label'] ?? ''));
    }

    public function campaignUrl(): string
    {
        return trim((string)($this->primaryCampaign['url'] ?? ''));
    }

    /**
     * Flat array for templates / listing enrichment.
     *
     * @return array{
     *     currency:string,
     *     catalog_price_minor:int,
     *     final_price_minor:int,
     *     compare_at_minor:int,
     *     price:float,
     *     original_price:float,
     *     has_deal:bool,
     *     campaign_label:string,
     *     campaign_url:string,
     *     campaign_badge:string,
     *     primary_campaign:?array{label:string,url:string,badge:string,code:string,source_module:string,source_type:string,source_id:string}
     * }
     */
    public function toArray(): array
    {
        return [
            'currency' => $this->currency,
            'catalog_price_minor' => $this->catalogPriceMinor,
            'final_price_minor' => $this->finalPriceMinor,
            'compare_at_minor' => $this->compareAtMinor,
            'price' => $this->finalPrice(),
            'original_price' => $this->compareAtPrice(),
            'has_deal' => $this->hasDeal,
            'campaign_label' => $this->campaignLabel(),
            'campaign_url' => $this->campaignUrl(),
            'campaign_badge' => trim((string)($this->primaryCampaign['badge'] ?? '')),
            'primary_campaign' => $this->primaryCampaign,
        ];
    }
}
