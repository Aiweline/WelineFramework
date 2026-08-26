<?php

declare(strict_types=1);

namespace Weline\Product\Extends\Module\Weline_Cart\CartItemSnapshotProviderV2;

use Weline\Cart\Api\CartItemSnapshotProviderV2Interface;
use Weline\Cart\Api\CartSelectionHash;
use Weline\Cart\Api\Data\CartItemSnapshot;
use Weline\Cart\Api\Data\OfferIdentity;
use Weline\Cart\Api\Development\CartV2HarnessCatalog;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\ScopeIdentity;

/**
 * Default Product catalog Cart V2 snapshot provider（MOD-P2E-001）.
 * Harness may inject catalog via forTesting(); production resolves the durable
 * Product Website shard through ProductCatalogCartItemSnapshotResolver.
 */
final class ProductCartItemSnapshotProvider implements CartItemSnapshotProviderV2Interface
{
    public const CODE = 'product';

    /**
     * @var array<string, array<string, mixed>> offer_uuid => catalog row
     */
    private array $catalog = [];

    /** @var (\Closure(OfferIdentity, ScopeIdentity, array): (?CartItemSnapshot))|null */
    private $resolver;

    private readonly ?ProductCatalogCartItemSnapshotResolver $catalogResolver;

    /**
     * @param array<string, array<string, mixed>> $catalog
     * @param (\Closure(OfferIdentity, ScopeIdentity, array): (?CartItemSnapshot))|null $resolver
     */
    public function __construct(
        array $catalog = [],
        ?callable $resolver = null,
        ?ProductCatalogCartItemSnapshotResolver $catalogResolver = null,
    ) {
        $this->catalog = $catalog;
        $this->resolver = $resolver;
        $this->catalogResolver = $catalogResolver;
    }

    /**
     * @param array<string, array<string, mixed>> $catalog
     */
    public static function forTesting(array $catalog = []): self
    {
        return new self($catalog);
    }

    public function getProviderCode(): string
    {
        return self::CODE;
    }

    public function resolveCartItemSnapshot(
        OfferIdentity $offer,
        ScopeIdentity $scope,
        array $selection = [],
    ): ?CartItemSnapshot {
        if ($this->normalize($offer->providerCode) !== self::CODE) {
            return null;
        }
        if ($this->resolver !== null) {
            return ($this->resolver)($offer, $scope, $selection);
        }

        $row = $this->catalog[$offer->globalOfferUuid] ?? null;
        if ($row === null) {
            $row = CartV2HarnessCatalog::get($offer->globalOfferUuid);
        }
        if ($row === null) {
            $catalogResolver = $this->catalogResolver ?? $this->productionResolver();
            return $catalogResolver?->resolve($offer, $scope, $selection);
        }

        $scopeKey = (string)($row['scope_key'] ?? '');
        if ($scopeKey !== '' && $scopeKey !== $scope->canonicalKey()) {
            return new CartItemSnapshot(
                offer: $offer,
                name: (string)($row['name'] ?? ''),
                found: false,
                sellable: false,
                message: (string)__('该 Offer 在当前 Scope 不可售'),
                selection: CartSelectionHash::normalizeSelection($selection),
                sourceModule: 'Weline_Product',
                sourceApp: 'Weline',
            );
        }

        return new CartItemSnapshot(
            offer: $offer,
            name: (string)($row['name'] ?? __('商品')),
            sku: (string)($row['sku'] ?? ''),
            image: (string)($row['image'] ?? ''),
            currency: (string)($row['currency'] ?? 'CNY'),
            unitPriceMinor: max(0, (int)($row['unit_price_minor'] ?? 0)),
            found: (bool)($row['found'] ?? true),
            sellable: (bool)($row['sellable'] ?? true),
            stock: array_key_exists('stock', $row) ? max(0, (int)$row['stock']) : null,
            message: (string)($row['message'] ?? ''),
            selection: CartSelectionHash::normalizeSelection($selection),
            productType: (string)($row['product_type'] ?? 'simple'),
            sourceModule: 'Weline_Product',
            sourceApp: 'Weline',
            offerId: isset($row['offer_id']) ? (int)$row['offer_id'] : $offer->legacyProductId,
            productId: isset($row['product_id']) ? (int)$row['product_id'] : $offer->legacyProductId,
            splitKey: (string)($row['split_key'] ?? 'default'),
            legalEntity: (string)($row['legal_entity'] ?? 'default'),
            requiresShipping: array_key_exists('requires_shipping', $row)
                ? (bool)$row['requires_shipping']
                : null,
            weightMinor: max(0, (int)($row['weight_minor'] ?? 0)),
            volumeMinor: max(0, (int)($row['volume_minor'] ?? 0)),
            taxClassCode: (string)($row['tax_class_code'] ?? 'standard'),
            fulfillmentMetadata: is_array($row['fulfillment_metadata'] ?? null)
                ? $row['fulfillment_metadata']
                : [],
        );
    }

    private function normalize(string $code): string
    {
        return strtolower(trim($code));
    }

    private function productionResolver(): ?ProductCatalogCartItemSnapshotResolver
    {
        try {
            $resolver = ObjectManager::getInstance(ProductCatalogCartItemSnapshotResolver::class);
            return $resolver instanceof ProductCatalogCartItemSnapshotResolver ? $resolver : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
