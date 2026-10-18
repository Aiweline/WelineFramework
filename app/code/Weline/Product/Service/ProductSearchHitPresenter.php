<?php

declare(strict_types=1);

namespace Weline\Product\Service;

use Weline\Theme\Helper\StorefrontImagePlaceholder;

/**
 * Enriches Product search hits with storefront offer rows for card rendering.
 *
 * Hydration ladder (per page window):
 * 1) bulk {@see StorefrontCatalogViewService::publishedOffersForProductIds}
 * 2) single {@see StorefrontCatalogViewService::publishedOffer} for misses
 * 3) stub offer from search row (placeholder image) so UI never falls back to text-only hit cards
 */
final class ProductSearchHitPresenter
{
    public function __construct(
        private readonly StorefrontCatalogViewService $catalog,
    ) {
    }

    /**
     * Attach storefront offer data and dedupe by product_id (one card per SPU).
     *
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    public function prepareRows(array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        $productIds = [];
        foreach ($rows as $row) {
            $productId = (int)($row['product_id'] ?? 0);
            if ($productId > 0) {
                $productIds[$productId] = true;
            }
        }
        if ($productIds === []) {
            return $rows;
        }

        // Cap offer hydration to the incoming row set (callers should page first).
        $offers = $this->catalog->publishedOffersForProductIds(
            array_keys($productIds),
            max(1, count($productIds)),
        );
        $byProductId = [];
        $byOfferId = [];
        $byOfferUuid = [];
        foreach ($offers as $offer) {
            $productId = (int)($offer['product_id'] ?? 0);
            if ($productId > 0 && !isset($byProductId[$productId])) {
                $byProductId[$productId] = $offer;
            }
            $offerId = (int)($offer['offer_id'] ?? 0);
            if ($offerId > 0) {
                $byOfferId[$offerId] = $offer;
            }
            $offerUuid = trim((string)($offer['global_offer_uuid'] ?? ''));
            if ($offerUuid !== '') {
                $byOfferUuid[$offerUuid] = $offer;
            }
        }

        $seenProducts = [];
        $prepared = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $productId = (int)($row['product_id'] ?? 0);
            $offer = $this->resolveOffer($row, $byOfferUuid, $byOfferId, $byProductId);
            if ($offer === null && $productId > 0) {
                $offer = $this->catalog->publishedOffer($productId);
            }
            if ($offer === null) {
                $offer = $this->stubOfferFromSearchRow($row);
            }
            if ($offer !== null) {
                $row['storefront_offer'] = $offer;
            }
            if ($productId > 0) {
                if (isset($seenProducts[$productId])) {
                    continue;
                }
                $seenProducts[$productId] = true;
            }
            $prepared[] = $row;
        }

        return $prepared;
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, array<string, mixed>> $byOfferUuid
     * @param array<int, array<string, mixed>> $byOfferId
     * @param array<int, array<string, mixed>> $byProductId
     * @return array<string, mixed>|null
     */
    private function resolveOffer(
        array $row,
        array $byOfferUuid,
        array $byOfferId,
        array $byProductId,
    ): ?array {
        $offerUuid = trim((string)($row['global_offer_uuid'] ?? ''));
        if ($offerUuid !== '' && isset($byOfferUuid[$offerUuid])) {
            return $byOfferUuid[$offerUuid];
        }
        $offerId = (int)($row['offer_id'] ?? 0);
        if ($offerId > 0 && isset($byOfferId[$offerId])) {
            return $byOfferId[$offerId];
        }
        $productId = (int)($row['product_id'] ?? 0);
        if ($productId > 0 && isset($byProductId[$productId])) {
            return $byProductId[$productId];
        }

        return null;
    }

    /**
     * Minimal offer bag so Product hit template always paints a media card.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>|null
     */
    private function stubOfferFromSearchRow(array $row): ?array
    {
        $productId = (int)($row['product_id'] ?? 0);
        if ($productId <= 0) {
            return null;
        }
        $name = trim((string)($row['title'] ?? $row['name'] ?? ''));
        $sku = trim((string)($row['sku'] ?? ''));
        $currency = strtoupper(trim((string)($row['currency'] ?? $row['requested_currency'] ?? 'CNY')));
        if ($currency === '') {
            $currency = 'CNY';
        }
        $image = trim((string)($row['image'] ?? ''));
        if ($image === '' && isset($row['images']) && is_array($row['images'])) {
            foreach ($row['images'] as $candidate) {
                $candidate = trim((string)$candidate);
                if ($candidate !== '') {
                    $image = $candidate;
                    break;
                }
            }
        }
        if ($image === '') {
            $image = StorefrontImagePlaceholder::url($productId);
        }
        $slug = strtolower(trim((string)($row['slug'] ?? '')));
        $offerUuid = trim((string)($row['global_offer_uuid'] ?? ''));

        return [
            'product_id' => $productId,
            'offer_id' => max(0, (int)($row['offer_id'] ?? 0)),
            'provider_code' => trim((string)($row['provider_code'] ?? 'product')),
            'global_offer_uuid' => $offerUuid,
            'name' => $name !== '' ? $name : ($sku !== '' ? $sku : ('#' . $productId)),
            'sku' => $sku,
            'slug' => $slug,
            'image' => $image,
            'images' => $image !== '' ? [$image] : [],
            'currency' => $currency,
            'unit_price_minor' => 0,
            'stock' => 0,
            'sellable' => false,
            'quote_only' => true,
            'currency_unavailable' => false,
            'message' => '',
            'short_description' => '',
            'description' => '',
            'search_offer_stub' => true,
        ];
    }
}
