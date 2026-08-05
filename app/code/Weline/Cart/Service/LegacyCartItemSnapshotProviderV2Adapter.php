<?php

declare(strict_types=1);

namespace Weline\Cart\Service;

use Weline\Cart\Api\CartItemSnapshotProviderInterface;
use Weline\Cart\Api\CartItemSnapshotProviderV2Interface;
use Weline\Cart\Api\Data\CartItemSnapshot;
use Weline\Cart\Api\Data\OfferIdentity;
use Weline\Framework\Runtime\ScopeIdentity;

/**
 * Bridges V1 int productId providers when OfferIdentity carries legacyProductId.
 */
final class LegacyCartItemSnapshotProviderV2Adapter implements CartItemSnapshotProviderV2Interface
{
    public const CODE = 'legacy_product_id';

    public function __construct(
        private readonly ?CartItemSnapshotProviderRegistry $v1Registry = null,
        /** @var (\Closure(int, array): (?array))|null */
        private $resolver = null,
    ) {
    }

    private function v1Registry(): ?CartItemSnapshotProviderRegistry
    {
        if ($this->v1Registry !== null) {
            return $this->v1Registry;
        }
        try {
            $reg = \Weline\Framework\Manager\ObjectManager::getInstance(CartItemSnapshotProviderRegistry::class);
            return $reg instanceof CartItemSnapshotProviderRegistry ? $reg : null;
        } catch (\Throwable) {
            return null;
        }
    }

    public static function forTesting(callable $resolver): self
    {
        return new self(null, $resolver);
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
        if ($offer->legacyProductId === null) {
            return null;
        }
        $productId = (int)$offer->legacyProductId;
        $params = [
            'selection' => $selection,
            'scope' => $scope->toArray(),
            'offer' => $offer->toArray(),
        ];

        $raw = null;
        if ($this->resolver !== null) {
            $raw = ($this->resolver)($productId, $params);
        } elseif ($this->v1Registry() !== null) {
            $raw = $this->v1Registry()->resolve($productId, $params);
        }
        if (!is_array($raw)) {
            return null;
        }

        $unitMinor = array_key_exists('unit_price_minor', $raw)
            ? (int)$raw['unit_price_minor']
            : (int)round(((float)($raw['price'] ?? 0)) * 100);

        return new CartItemSnapshot(
            offer: $offer,
            name: (string)($raw['name'] ?? __('商品 #%{1}', [$productId])),
            sku: (string)($raw['sku'] ?? ''),
            image: (string)($raw['image'] ?? ''),
            currency: (string)($raw['currency'] ?? 'CNY'),
            unitPriceMinor: max(0, $unitMinor),
            found: (bool)($raw['found'] ?? true),
            sellable: (bool)($raw['sellable'] ?? true),
            stock: array_key_exists('stock', $raw) ? max(0, (int)$raw['stock']) : null,
            message: (string)($raw['message'] ?? ''),
            selection: CartSelectionHash::normalizeSelection($selection),
            productType: (string)($raw['product_type'] ?? 'simple'),
            sourceModule: (string)($raw['source_module'] ?? ''),
            sourceApp: (string)($raw['source_app'] ?? ''),
            offerId: isset($raw['offer_id']) ? (int)$raw['offer_id'] : $offer->legacyProductId,
            productId: isset($raw['product_id']) ? (int)$raw['product_id'] : $offer->legacyProductId,
            splitKey: (string)($raw['split_key'] ?? 'default'),
            legalEntity: (string)($raw['legal_entity'] ?? 'default'),
            requiresShipping: array_key_exists('requires_shipping', $raw)
                ? (bool)$raw['requires_shipping']
                : null,
            weightMinor: max(0, (int)($raw['weight_minor'] ?? 0)),
            volumeMinor: max(0, (int)($raw['volume_minor'] ?? 0)),
            taxClassCode: (string)($raw['tax_class_code'] ?? 'standard'),
        );
    }
}
