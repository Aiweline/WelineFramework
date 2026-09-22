<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Product\Service\HomepageShelfStagger;

/**
 * WO-HP-P1-02：三货架错开选题契约。
 */
final class HomepageShelfStaggerContractTest extends TestCase
{
    public function testTopFourPairwiseDisjointAndFeaturedDiffersFromHotTopEight(): void
    {
        $featuredPool = [];
        $dealsPool = [];
        $hotPool = [];
        // Shared IDs 1–12 would collide if shelves reused the same cards() order.
        for ($id = 1; $id <= 24; $id++) {
            $featuredPool[] = $this->card($id, 100.0, 100.0);
            $hotPool[] = $this->card($id, 100.0, 100.0, reviewCount: 100 - $id);
        }
        // Deals: deepest discounts on low IDs (overlap trap with featured/hot).
        for ($id = 1; $id <= 8; $id++) {
            $dealsPool[] = $this->card($id, 70.0, 100.0);
        }
        for ($id = 25; $id <= 32; $id++) {
            $dealsPool[] = $this->card($id, 80.0, 100.0);
        }

        $plan = HomepageShelfStagger::select($featuredPool, $dealsPool, $hotPool, 8, 4, 8);

        $featuredTop4 = $this->ids(array_slice($plan['featured'], 0, 4));
        $dealsTop4 = $this->ids(array_slice($plan['deals'], 0, 4));
        $hotTop4 = $this->ids(array_slice($plan['hot'], 0, 4));
        $featuredTop8 = $this->ids(array_slice($plan['featured'], 0, 8));
        $hotTop8 = $this->ids(array_slice($plan['hot'], 0, 8));

        self::assertSame([], array_values(array_intersect($featuredTop4, $dealsTop4)));
        self::assertSame([], array_values(array_intersect($featuredTop4, $hotTop4)));
        self::assertSame([], array_values(array_intersect($dealsTop4, $hotTop4)));
        self::assertNotSame($featuredTop8, $hotTop8);
        self::assertSame([], array_values(array_intersect(
            array_intersect($featuredTop8, $dealsTop4),
            $hotTop8,
        )), '禁止同 SKU 三刷');
    }

    public function testDealsDoNotBorrowFromOtherShelvesWhenPoolIsShort(): void
    {
        $featured = [$this->card(1), $this->card(2), $this->card(3), $this->card(4)];
        $hot = [$this->card(5), $this->card(6), $this->card(7), $this->card(8)];
        // Only two real deals (≥15%); must not pad from featured/hot.
        $deals = [
            $this->card(10, 70.0, 100.0),
            $this->card(11, 80.0, 100.0),
            $this->card(12, 95.0, 100.0), // 5% — below threshold
        ];

        $plan = HomepageShelfStagger::select($featured, $deals, $hot, 4, 4, 4);

        self::assertCount(2, $plan['deals']);
        self::assertSame([10, 11], $this->ids($plan['deals']));
        self::assertNotContains(1, $this->ids($plan['deals']));
        self::assertNotContains(5, $this->ids($plan['deals']));
    }

    public function testLimitedDealFlagQualifiesWithoutHighDiscount(): void
    {
        $deals = [
            array_merge($this->card(99, 95.0, 100.0), ['is_limited_deal' => true]),
        ];
        $plan = HomepageShelfStagger::select(
            [$this->card(1)],
            $deals,
            [$this->card(2)],
            1,
            1,
            1,
        );
        self::assertSame([99], $this->ids($plan['deals']));
    }

    /**
     * @return array<string, mixed>
     */
    private function card(
        int $id,
        float $price = 100.0,
        float $original = 100.0,
        int $reviewCount = 0,
    ): array {
        return [
            'id' => $id,
            'product_id' => $id,
            'name' => 'P' . $id,
            'price' => $price,
            'original_price' => $original,
            'review_count' => $reviewCount,
            'rating' => 4.0,
        ];
    }

    /**
     * @param list<array<string, mixed>> $cards
     * @return list<int>
     */
    private function ids(array $cards): array
    {
        return array_values(array_map(
            static fn(array $card): int => (int)($card['product_id'] ?? $card['id'] ?? 0),
            $cards,
        ));
    }
}
