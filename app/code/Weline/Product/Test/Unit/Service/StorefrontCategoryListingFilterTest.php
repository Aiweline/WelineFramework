<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Product\Service\StorefrontCategoryListingFilter;

final class StorefrontCategoryListingFilterTest extends TestCase
{
    private StorefrontCategoryListingFilter $filter;

    protected function setUp(): void
    {
        $this->filter = new StorefrontCategoryListingFilter();
    }

    public function testApplyFiltersByPriceBucketAndSortsByPriceAsc(): void
    {
        $offers = [
            ['name' => 'B', 'unit_price_minor' => 15000, 'quote_only' => false],
            ['name' => 'A', 'unit_price_minor' => 5000, 'quote_only' => false],
            ['name' => 'C', 'unit_price_minor' => 35000, 'quote_only' => false],
        ];

        $filtered = $this->filter->apply(
            $offers,
            StorefrontCategoryListingFilter::PRICE_100_299,
            StorefrontCategoryListingFilter::SORT_PRICE_ASC
        );

        self::assertCount(1, $filtered);
        self::assertSame('B', $filtered[0]['name']);
    }

    public function testPriceBucketsWithCountsSkipQuoteOnlyOffers(): void
    {
        $offers = [
            ['unit_price_minor' => 5000, 'quote_only' => false],
            ['unit_price_minor' => 8000, 'quote_only' => true],
            ['unit_price_minor' => 40000, 'quote_only' => false],
        ];

        $buckets = $this->filter->priceBucketsWithCounts($offers);
        $byCode = [];
        foreach ($buckets as $bucket) {
            $byCode[$bucket['code']] = (int)$bucket['count'];
        }

        self::assertSame(1, $byCode[StorefrontCategoryListingFilter::PRICE_0_99]);
        self::assertSame(0, $byCode[StorefrontCategoryListingFilter::PRICE_100_299]);
        self::assertSame(1, $byCode[StorefrontCategoryListingFilter::PRICE_300_UP]);
    }

    public function testBuildListingUrlOmitsDefaultSort(): void
    {
        $url = $this->filter->buildListingUrl('/category/food/drinks/coffee-tea', [
            'price' => StorefrontCategoryListingFilter::PRICE_0_99,
            'sort' => StorefrontCategoryListingFilter::SORT_DEFAULT,
        ]);

        self::assertSame('/category/food/drinks/coffee-tea?price=0-99', $url);
    }
}
