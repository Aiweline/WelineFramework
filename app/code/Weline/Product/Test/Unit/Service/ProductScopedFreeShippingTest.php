<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Product\Service\ProductScopedFreeShipping;

final class ProductScopedFreeShippingTest extends TestCase
{
    public function testLineQualifiesOnlyWhenEnabledAndThresholdMet(): void
    {
        self::assertFalse(ProductScopedFreeShipping::lineQualifies([
            'row_total_minor' => 10000,
        ]));

        self::assertTrue(ProductScopedFreeShipping::lineQualifies([
            'is_free_shipping' => 1,
            'free_shipping_min_amount' => 0,
            'row_total_minor' => 100,
        ]));

        self::assertFalse(ProductScopedFreeShipping::lineQualifies([
            'is_free_shipping' => 1,
            'free_shipping_min_amount' => 50,
            'row_total_minor' => 4999,
        ], 2));

        self::assertTrue(ProductScopedFreeShipping::lineQualifies([
            'is_free_shipping' => 1,
            'free_shipping_min_amount' => 50,
            'row_total_minor' => 5000,
        ], 2));
    }

    public function testPartitionKeepsBillableLinesSeparate(): void
    {
        [$billable, $waived] = ProductScopedFreeShipping::partition([
            [
                'sku' => 'PAID',
                'row_total_minor' => 9000,
            ],
            [
                'sku' => 'FREE',
                'is_free_shipping' => 1,
                'free_shipping_min_amount' => 0,
                'row_total_minor' => 1000,
            ],
        ], 2);

        self::assertCount(1, $billable);
        self::assertSame('PAID', $billable[0]['sku']);
        self::assertCount(1, $waived);
        self::assertSame('FREE', $waived[0]['sku']);
    }

    public function testShelfBadgeRespectsThreshold(): void
    {
        self::assertFalse(ProductScopedFreeShipping::shelfBadgeVisible([
            'price' => 80,
        ]));
        self::assertTrue(ProductScopedFreeShipping::shelfBadgeVisible([
            'is_free_shipping' => 1,
            'free_shipping_min_amount' => 0,
            'price' => 10,
        ]));
        self::assertFalse(ProductScopedFreeShipping::shelfBadgeVisible([
            'is_free_shipping' => 1,
            'free_shipping_min_amount' => 49,
            'price' => 40,
        ]));
        self::assertTrue(ProductScopedFreeShipping::shelfBadgeVisible([
            'is_free_shipping' => 1,
            'free_shipping_min_amount' => 49,
            'price' => 49,
        ]));
    }
}
