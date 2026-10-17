<?php

declare(strict_types=1);

namespace Weline\Daocharms3d\Service;

/**
 * Server-side catalog projection for the daocharms-3d town scene.
 * Owning module keeps Query I/O; design theme mounts the widget that consumes it.
 */
final class TownCatalogProjector
{
    /**
     * @return array<int, array<string, mixed>> product_id => card+offer row
     */
    public function projectCards(): array
    {
        $offers = [];
        $page = 1;
        do {
            $catalog = w_query('product_storefront', 'searchPublishedOffers', [
                'page' => $page,
                'page_size' => 48,
            ]);
            foreach (($catalog['items'] ?? []) as $offer) {
                if (!\is_array($offer)) {
                    continue;
                }
                $productId = (int)($offer['product_id'] ?? 0);
                if ($productId > 0) {
                    $offers[$productId] = $offer;
                }
            }
            $page++;
        } while ($page <= (int)($catalog['pagination']['pages'] ?? 1));

        $cards = [];
        foreach (\array_chunk(\array_keys($offers), 24) as $ids) {
            foreach (w_query('product_storefront', 'cardsByProductIds', [
                'product_ids' => $ids,
                'limit' => 24,
            ]) as $card) {
                if (!\is_array($card)) {
                    continue;
                }
                $id = (int)($card['id'] ?? 0);
                if ($id <= 0) {
                    continue;
                }
                $cards[$id] = $card + ($offers[$id] ?? []);
                if (\trim((string)($offers[$id]['image'] ?? '')) !== '') {
                    $cards[$id]['image'] = (string)($offers[$id]['image'] ?? '');
                }
                $cards[$id]['formatted_price'] = (string)($offers[$id]['formatted_price'] ?? '');
            }
        }

        return $cards;
    }
}
