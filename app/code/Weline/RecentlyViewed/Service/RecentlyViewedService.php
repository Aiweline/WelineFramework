<?php

declare(strict_types=1);

namespace Weline\RecentlyViewed\Service;

use Weline\Framework\Manager\ObjectManager;

/**
 * Recently-viewed read/write facade. Storage is cookie MRU for all shoppers.
 */
class RecentlyViewedService
{
    public function __construct(
        private readonly RecentlyViewedSessionStore $store,
    ) {
    }

    public function record(int $productId): void
    {
        $this->store->record($productId);
    }

    /**
     * @return list<int>
     */
    public function listIds(int $limit = 6, int $excludeProductId = 0): array
    {
        $limit = max(1, min(6, $limit));
        $excludeProductId = max(0, $excludeProductId);
        $out = [];
        foreach ($this->store->listIds() as $productId) {
            if ($excludeProductId > 0 && $productId === $excludeProductId) {
                continue;
            }
            $out[] = $productId;
            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }

    /**
     * Storefront cards via Product QueryProvider batch (targeted catalog).
     * Never calls livePublishedOffersForProduct per id (PDP N+1 storm).
     *
     * @return list<array<string, mixed>>
     */
    public function cards(int $limit = 6, int $excludeProductId = 0): array
    {
        $limit = max(1, min(6, $limit));
        $ids = $this->listIds($limit, $excludeProductId);
        if ($ids === []) {
            return [];
        }

        try {
            $result = $this->queryStorefrontCards($ids, $limit);
            if (!\is_array($result)) {
                return [];
            }

            $cards = [];
            foreach ($result as $card) {
                if (!\is_array($card)) {
                    continue;
                }
                if ((int)($card['id'] ?? 0) <= 0) {
                    continue;
                }
                $cards[] = $card;
                if (count($cards) >= $limit) {
                    break;
                }
            }

            return $cards;
        } catch (\Throwable $e) {
            // Keep the shelf empty rather than N+1-fallback via live; surface
            // the failure for operators without collapsing the PDP.
            if (\function_exists('error_log')) {
                \error_log('recently_viewed.cards_query_failed: ' . $e->getMessage());
            }

            return [];
        }
    }

    /**
     * @param list<int> $productIds
     * @return list<array<string, mixed>>|mixed
     */
    protected function queryStorefrontCards(array $productIds, int $limit): mixed
    {
        if (\function_exists('w_query')) {
            return \w_query('product_storefront', 'cardsByProductIds', [
                'product_ids' => $productIds,
                'limit' => $limit,
            ], 'frontend');
        }

        /** @var \Weline\Framework\Service\Query\FrameworkQueryService $query */
        $query = ObjectManager::getInstance(\Weline\Framework\Service\Query\FrameworkQueryService::class);

        return $query->execute('product_storefront', 'cardsByProductIds', [
            'product_ids' => $productIds,
            'limit' => $limit,
        ], 'frontend');
    }
}
