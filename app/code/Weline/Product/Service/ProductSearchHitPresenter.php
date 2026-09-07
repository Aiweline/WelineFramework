<?php

declare(strict_types=1);

namespace Weline\Product\Service;

/**
 * Enriches Product search hits with storefront offer rows for card rendering.
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
}
