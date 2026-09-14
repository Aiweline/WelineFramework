<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\Extends\Cart;

use PHPUnit\Framework\TestCase;

final class ProductCatalogCartWeightMinorContractTest extends TestCase
{
    public function testSnapshotResolverMapsCatalogWeightKgToWeightMinor(): void
    {
        $path = dirname(__DIR__, 4)
            . '/extends/module/Weline_Cart/CartItemSnapshotProvider/ProductCatalogCartItemSnapshotResolver.php';
        self::assertFileExists($path);
        $content = (string)file_get_contents($path);
        self::assertStringContainsString('function weightMinorFromCatalog(', $content);
        self::assertStringContainsString("'weight_kg'", $content);
        self::assertStringContainsString('weightMinor: $weightMinor', $content);
        self::assertStringContainsString('round($kg * 1000)', $content);
    }
}
