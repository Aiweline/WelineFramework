<?php

declare(strict_types=1);

namespace Weline\Product\Service;

use Weline\Cart\Api\Data\OfferIdentity;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\Product\Extends\Module\Weline_Cart\CartItemSnapshotProviderV2\ProductCatalogCartItemSnapshotResolver;
use Weline\Product\Model\Shard\Offer;
use Weline\Product\Model\Shard\Product;
use Weline\Product\Repository\OfferRepository;
use Weline\Product\Repository\ProductRepository;

/**
 * Read projection for the first-party storefront catalog.
 *
 * It deliberately delegates price, overlay and sellability resolution to the
 * same durable Product provider used by Cart V2, so the card and add-to-cart
 * command cannot disagree about the selected Offer.
 */
final class StorefrontCatalogViewService
{
    public function __construct(
        private readonly ProductRepository $products,
        private readonly OfferRepository $offers,
        private readonly ProductCatalogCartItemSnapshotResolver $snapshots,
    ) {
    }

    /** @return list<array<string, mixed>> */
    public function publishedOffers(int $limit = 100): array
    {
        $scope = $this->currentScope();
        $websiteId = (int)$scope->websiteId;
        $products = $this->products->listAll($websiteId);
        $publishedProductIds = [];
        foreach ($products as $product) {
            if (strtolower(trim((string)($product[Product::schema_fields_STATUS] ?? '')))
                !== Product::STATUS_PUBLISHED
            ) {
                continue;
            }
            $productId = (int)($product[Product::schema_fields_ID] ?? 0);
            if ($productId > 0) {
                $publishedProductIds[$productId] = true;
            }
        }
        if ($publishedProductIds === []) {
            return [];
        }

        $rows = [];
        foreach ($this->offers->listByProductIds($websiteId, array_keys($publishedProductIds)) as $offer) {
            if (count($rows) >= max(1, min(200, $limit))) {
                break;
            }
            if (strtolower(trim((string)($offer[Offer::schema_fields_STATUS] ?? ''))) !== 'published') {
                continue;
            }
            $offerUuid = trim((string)($offer[Offer::schema_fields_GLOBAL_OFFER_UUID] ?? ''));
            $productId = (int)($offer[Offer::schema_fields_PRODUCT_ID] ?? 0);
            if ($offerUuid === '' || $productId <= 0) {
                continue;
            }

            $snapshot = $this->snapshots->resolve(
                new OfferIdentity('product', $offerUuid, $productId),
                $scope,
            );
            if (!$snapshot->found) {
                continue;
            }
            $rows[] = [
                'product_id' => $snapshot->productId ?? $productId,
                'offer_id' => $snapshot->offerId ?? (int)($offer[Offer::schema_fields_ID] ?? 0),
                'provider_code' => $snapshot->offer->providerCode,
                'global_offer_uuid' => $snapshot->offer->globalOfferUuid,
                'name' => $snapshot->name,
                'sku' => $snapshot->sku,
                'image' => $snapshot->image,
                'currency' => $snapshot->currency,
                'unit_price_minor' => $snapshot->unitPriceMinor,
                'stock' => $snapshot->stock,
                'sellable' => $snapshot->sellable,
                'message' => $snapshot->message,
            ];
        }

        return $rows;
    }

    private function currentScope(): ScopeIdentity
    {
        $scope = RequestContext::scopeIdentity();
        if ($scope instanceof ScopeIdentity && !$scope->isGlobal() && $scope->websiteId !== null) {
            return $scope;
        }

        $websiteId = max(0, RequestContext::getWelineWebsiteId());
        $websiteCode = trim(RequestContext::getWelineWebsiteCode());

        return ScopeIdentity::website($websiteId, $websiteCode !== '' ? $websiteCode : 'default');
    }
}
