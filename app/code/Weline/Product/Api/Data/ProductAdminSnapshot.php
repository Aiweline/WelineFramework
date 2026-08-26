<?php

declare(strict_types=1);

namespace Weline\Product\Api\Data;

/** Immutable, UI-ready Product aggregate for one Website projection. */
final readonly class ProductAdminSnapshot
{
    /**
     * @param array<string, mixed> $identity
     * @param array<string, mixed> $product
     * @param list<array<string, mixed>> $offers
     * @param list<array<string, mixed>> $attributes
     * @param list<array<string, mixed>> $attributeCatalog
     * @param list<array<string, mixed>> $prices
     * @param list<array<string, mixed>> $categories
     * @param list<array<string, mixed>> $media
     * @param list<array<string, mixed>> $stores
     * @param array<string, mixed> $provider
     * @param array<string, mixed> $diagnostics
     * @param array<string, bool> $permissions
     * @param array<string, mixed> $offerMatrix
     * @param list<array<string, mixed>> $audit
     * @param list<array<string, mixed>> $categoryAssignments
     * @param list<array<string, mixed>> $mediaAssignments
     * @param list<array<string, mixed>> $storeCategoryOverrides
     * @param list<array<string, mixed>> $storeMediaOverrides
     * @param array<string, mixed> $inventory
     */
    public function __construct(
        public int $websiteId,
        public array $identity,
        public array $product,
        public array $offers,
        public array $attributes,
        public array $attributeCatalog,
        public array $prices,
        public array $categories,
        public array $media,
        public array $stores,
        public array $provider,
        public array $diagnostics,
        public array $permissions,
        public array $offerMatrix = [],
        public array $audit = [],
        public array $categoryAssignments = [],
        public array $mediaAssignments = [],
        public array $storeCategoryOverrides = [],
        public array $storeMediaOverrides = [],
        public readonly array $inventory = [],
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'website_id' => $this->websiteId,
            'identity' => $this->identity,
            'product' => $this->product,
            'offers' => $this->offers,
            'attributes' => $this->attributes,
            'attribute_catalog' => $this->attributeCatalog,
            'prices' => $this->prices,
            'categories' => $this->categories,
            'media' => $this->media,
            'stores' => $this->stores,
            'provider' => $this->provider,
            'diagnostics' => $this->diagnostics,
            'permissions' => $this->permissions,
            'offer_matrix' => $this->offerMatrix,
            'audit' => $this->audit,
            'category_assignments' => $this->categoryAssignments,
            'media_assignments' => $this->mediaAssignments,
            'store_category_overrides' => $this->storeCategoryOverrides,
            'store_media_overrides' => $this->storeMediaOverrides,
            'inventory' => $this->inventory,
        ];
    }
}
