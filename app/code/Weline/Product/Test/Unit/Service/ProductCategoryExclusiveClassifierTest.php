<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Product\Service\ProductCategoryExclusiveClassifier;

final class ProductCategoryExclusiveClassifierTest extends TestCase
{
    public function testStoreScopedOutsideLinkIsNotExclusive(): void
    {
        $map = ProductCategoryExclusiveClassifier::classify(
            [101],
            [10 => true],
            [
                ['category_id' => 10, 'product_id' => 101, 'store_id' => 0, 'selected' => true, 'scope_state' => 'explicit'],
                ['category_id' => 99, 'product_id' => 101, 'store_id' => 5, 'selected' => true, 'scope_state' => 'explicit'],
            ],
        );
        self::assertFalse($map[101]);
    }

    public function testExclusiveWhenAllSelectedLinksStayInSubtree(): void
    {
        $map = ProductCategoryExclusiveClassifier::classify(
            [202],
            [10 => true, 11 => true],
            [
                ['category_id' => 10, 'product_id' => 202, 'store_id' => 0, 'selected' => true, 'scope_state' => 'explicit'],
                ['category_id' => 11, 'product_id' => 202, 'store_id' => 2, 'selected' => true, 'scope_state' => 'explicit'],
            ],
        );
        self::assertTrue($map[202]);
    }

    public function testIntersectSelectedDropsOutsideProductIds(): void
    {
        $selected = ProductCategoryExclusiveClassifier::intersectSelected(
            [303, 304, 999],
            [303 => true, 304 => true],
        );
        self::assertSame([303, 304], $selected);
    }

    public function testTocTouOutsideLinkDowngradesExclusive(): void
    {
        $map = ProductCategoryExclusiveClassifier::classify(
            [505],
            [10 => true],
            [
                ['category_id' => 10, 'product_id' => 505, 'store_id' => 0, 'selected' => true, 'scope_state' => 'explicit'],
                ['category_id' => 90, 'product_id' => 505, 'store_id' => 4, 'selected' => true, 'scope_state' => 'explicit'],
            ],
        );
        self::assertFalse($map[505]);
    }

    public function testClearedOrUnselectedLinksDoNotCountAsMounts(): void
    {
        self::assertFalse(ProductCategoryExclusiveClassifier::isActiveMount([
            'selected' => false, 'scope_state' => 'explicit',
        ]));
        self::assertFalse(ProductCategoryExclusiveClassifier::isActiveMount([
            'selected' => true, 'scope_state' => 'cleared',
        ]));
        self::assertTrue(ProductCategoryExclusiveClassifier::isActiveMount([
            'selected' => true, 'scope_state' => 'explicit',
        ]));
    }
}
