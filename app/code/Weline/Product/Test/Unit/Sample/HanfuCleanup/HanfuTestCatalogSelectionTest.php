<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\Sample\HanfuCleanup;

use PHPUnit\Framework\TestCase;
use Weline\Product\Sample\HanfuCleanup\HanfuTestCatalogSelection;

final class HanfuTestCatalogSelectionTest extends TestCase
{
    public function testProductIdsAreTheApprovedFrozenSet(): void
    {
        self::assertTrue(
            class_exists(HanfuTestCatalogSelection::class),
            'HanfuTestCatalogSelection must expose the approved frozen catalog.',
        );

        $selection = new HanfuTestCatalogSelection();

        self::assertSame(
            [1, 2, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15, 16, 17, 18, 19, 20, 21, 22, 23, 24, 25, 26, 27, 28, 36, 39, 45],
            $selection->productIds(),
        );
    }

    public function testDigestIgnoresAssociativeKeyOrderButDetectsCatalogDrift(): void
    {
        $selection = new HanfuTestCatalogSelection();
        self::assertTrue(
            method_exists($selection, 'digest'),
            'HanfuTestCatalogSelection must digest normalized catalog snapshots.',
        );

        $left = [
            'website_id' => 0,
            'products' => [['product_id' => 1, 'sku' => 'R43-A']],
        ];
        $same = [
            'products' => [['sku' => 'R43-A', 'product_id' => 1]],
            'website_id' => 0,
        ];
        $changed = [
            'website_id' => 0,
            'products' => [['product_id' => 1, 'sku' => 'R43-B']],
        ];

        self::assertSame($selection->digest($left), $selection->digest($same));
        self::assertNotSame($selection->digest($left), $selection->digest($changed));
    }
}
