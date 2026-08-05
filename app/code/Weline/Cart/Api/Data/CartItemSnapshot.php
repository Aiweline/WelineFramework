<?php

declare(strict_types=1);

namespace Weline\Cart\Api\Data;

/**
 * Immutable cart line snapshot from a V2 provider.
 */
final class CartItemSnapshot
{
    /**
     * @param array<string, scalar|null> $selection Canonical selection
     */
    public function __construct(
        public readonly OfferIdentity $offer,
        public readonly string $name,
        public readonly string $sku = '',
        public readonly string $image = '',
        public readonly string $currency = 'CNY',
        public readonly int $unitPriceMinor = 0,
        public readonly bool $found = true,
        public readonly bool $sellable = true,
        public readonly ?int $stock = null,
        public readonly string $message = '',
        public readonly array $selection = [],
        public readonly string $productType = 'simple',
        public readonly string $sourceModule = '',
        public readonly string $sourceApp = '',
        public readonly ?int $offerId = null,
        public readonly ?int $productId = null,
        public readonly string $splitKey = 'default',
        public readonly string $legalEntity = 'default',
        public readonly ?bool $requiresShipping = null,
        public readonly int $weightMinor = 0,
        public readonly int $volumeMinor = 0,
        public readonly string $taxClassCode = 'standard',
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'offer' => $this->offer->toArray(),
            'name' => $this->name,
            'sku' => $this->sku,
            'image' => $this->image,
            'currency' => $this->currency,
            'unit_price_minor' => $this->unitPriceMinor,
            'found' => $this->found,
            'sellable' => $this->sellable,
            'stock' => $this->stock,
            'message' => $this->message,
            'selection' => $this->selection,
            'product_type' => $this->productType,
            'source_module' => $this->sourceModule,
            'source_app' => $this->sourceApp,
            'offer_id' => $this->offerId ?? 0,
            'product_id' => $this->productId ?? $this->offer->legacyProductId ?? 0,
            'split_key' => $this->splitKey,
            'legal_entity' => $this->legalEntity,
            'requires_shipping' => $this->requiresShipping,
            'weight_minor' => $this->weightMinor,
            'volume_minor' => $this->volumeMinor,
            'tax_class_code' => $this->taxClassCode,
            // legacy float bridge for existing CartService rows
            'price' => round($this->unitPriceMinor / 100, 2),
            'legacy_product_id' => $this->offer->legacyProductId ?? 0,
        ];
    }
}
