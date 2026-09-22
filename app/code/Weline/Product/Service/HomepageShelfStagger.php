<?php

declare(strict_types=1);

namespace Weline\Product\Service;

/**
 * Homepage three-shelf SKU stagger (WO-HP-P1-02).
 *
 * Priority: Deals → Hot → Featured (Featured yields on conflict).
 * Hard rules: pairwise top-4 disjoint; Featured top-8 ≠ Hot top-8;
 * never fill one shelf from another shelf's top-N; never invent cross-shelf copies.
 */
final class HomepageShelfStagger
{
    public const MIN_DEAL_DISCOUNT_PERCENT = 15.0;

    /**
     * @param list<array<string, mixed>> $featuredCandidates
     * @param list<array<string, mixed>> $dealsCandidates already filtered to real deals when possible
     * @param list<array<string, mixed>> $hotCandidates heat-ranked
     * @return array{
     *     featured: list<array<string, mixed>>,
     *     deals: list<array<string, mixed>>,
     *     hot: list<array<string, mixed>>
     * }
     */
    public static function select(
        array $featuredCandidates,
        array $dealsCandidates,
        array $hotCandidates,
        int $featuredLimit = 8,
        int $dealsLimit = 4,
        int $hotLimit = 8,
    ): array {
        $featuredLimit = max(1, min(24, $featuredLimit));
        $dealsLimit = max(1, min(24, $dealsLimit));
        $hotLimit = max(1, min(24, $hotLimit));

        $deals = self::takeUnique(
            self::filterAndSortDeals($dealsCandidates),
            $dealsLimit,
            [],
        );
        $dealsTop4 = self::ids(array_slice($deals, 0, 4));

        $hot = self::takeUnique($hotCandidates, $hotLimit, $dealsTop4);
        $hotTop8 = self::ids(array_slice($hot, 0, 8));
        $hotTop4 = self::ids(array_slice($hot, 0, 4));

        // Featured yields: exclude Deals top 4 + Hot top 8 (covers top-4 pairwise + set inequality).
        $featuredExclude = $dealsTop4 + $hotTop8;
        $featured = self::takeUnique($featuredCandidates, $featuredLimit, $featuredExclude);

        // Safety: drop any residual top-4 collisions without borrowing from other shelves.
        $featured = self::dropIntersectingPrefix($featured, 4, $dealsTop4 + $hotTop4);

        return [
            'featured' => array_values($featured),
            'deals' => array_values($deals),
            'hot' => array_values($hot),
        ];
    }

    /**
     * @param list<array<string, mixed>> $cards
     * @return list<array<string, mixed>>
     */
    public static function filterAndSortDeals(array $cards): array
    {
        $qualified = [];
        foreach ($cards as $card) {
            if (!is_array($card)) {
                continue;
            }
            $percent = self::discountPercent($card);
            if ($percent < self::MIN_DEAL_DISCOUNT_PERCENT && empty($card['is_limited_deal'])) {
                continue;
            }
            $card['discount_percent'] = (int)round($percent);
            $card['is_sale'] = 1;
            $qualified[] = $card;
        }

        usort(
            $qualified,
            static function (array $left, array $right): int {
                $byDiscount = self::discountPercent($right) <=> self::discountPercent($left);
                if ($byDiscount !== 0) {
                    return $byDiscount;
                }
                $byReviews = (int)($right['review_count'] ?? 0) <=> (int)($left['review_count'] ?? 0);
                if ($byReviews !== 0) {
                    return $byReviews;
                }

                return (int)($right['product_id'] ?? $right['id'] ?? 0)
                    <=> (int)($left['product_id'] ?? $left['id'] ?? 0);
            },
        );

        return $qualified;
    }

    /**
     * @param array<string, mixed> $card
     */
    public static function discountPercent(array $card): float
    {
        if (isset($card['discount_percent']) && is_numeric($card['discount_percent'])) {
            $explicit = (float)$card['discount_percent'];
            if ($explicit > 0) {
                return $explicit;
            }
        }
        $price = (float)($card['price'] ?? 0);
        $original = (float)($card['original_price'] ?? 0);
        if ($original <= $price || $price <= 0 || $original <= 0) {
            return 0.0;
        }

        return (1.0 - ($price / $original)) * 100.0;
    }

    /**
     * @param list<array<string, mixed>> $cards
     * @param array<int, true> $excludeProductIds
     * @return list<array<string, mixed>>
     */
    private static function takeUnique(array $cards, int $limit, array $excludeProductIds): array
    {
        $selected = [];
        $seen = $excludeProductIds;
        foreach ($cards as $card) {
            if (!is_array($card)) {
                continue;
            }
            $productId = max(0, (int)($card['product_id'] ?? $card['id'] ?? 0));
            if ($productId <= 0 || isset($seen[$productId])) {
                continue;
            }
            $seen[$productId] = true;
            $card['product_id'] = $productId;
            $card['id'] = $productId;
            $selected[] = $card;
            if (count($selected) >= $limit) {
                break;
            }
        }

        return $selected;
    }

    /**
     * @param list<array<string, mixed>> $cards
     * @param array<int, true> $forbidden
     * @return list<array<string, mixed>>
     */
    private static function dropIntersectingPrefix(array $cards, int $prefixLen, array $forbidden): array
    {
        if ($forbidden === [] || $cards === []) {
            return $cards;
        }
        $kept = [];
        foreach ($cards as $index => $card) {
            $productId = max(0, (int)($card['product_id'] ?? $card['id'] ?? 0));
            if ($index < $prefixLen && isset($forbidden[$productId])) {
                continue;
            }
            $kept[] = $card;
        }

        return $kept;
    }

    /**
     * @param list<array<string, mixed>> $cards
     * @return array<int, true>
     */
    private static function ids(array $cards): array
    {
        $ids = [];
        foreach ($cards as $card) {
            $productId = max(0, (int)($card['product_id'] ?? $card['id'] ?? 0));
            if ($productId > 0) {
                $ids[$productId] = true;
            }
        }

        return $ids;
    }
}
