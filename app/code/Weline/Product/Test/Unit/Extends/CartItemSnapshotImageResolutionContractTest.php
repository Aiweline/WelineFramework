<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\Extends;

use PHPUnit\Framework\TestCase;

final class CartItemSnapshotImageResolutionContractTest extends TestCase
{
    public function testResolverSourceUsesStorefrontMediaUrlBoundary(): void
    {
        $path = dirname(__DIR__, 3)
            . '/extends/module/Weline_Cart/CartItemSnapshotProvider/ProductCatalogCartItemSnapshotResolver.php';
        self::assertFileExists($path);
        $source = (string)file_get_contents($path);
        self::assertStringContainsString('StorefrontProductMediaUrlResolver', $source);
        self::assertStringContainsString('resolveReference(', $source);
        self::assertStringContainsString('never emit FileManager asset://', $source);
        self::assertStringContainsString('image(int $websiteId, int $productId, ScopeIdentity $scope, string $locale)', $source);
        self::assertStringContainsString(
            '$sku = trim((string)$offer->getData(Offer::schema_fields_SKU));',
            $source,
        );
        self::assertStringContainsString('$sku = $productSku;', $source);
        self::assertLessThan(
            strpos($source, '$sku = $productSku;'),
            strpos($source, '$sku = trim((string)$offer->getData(Offer::schema_fields_SKU));'),
        );
    }

    public function testResolverOptionSwatchesUseProductScopedEavCatalog(): void
    {
        $path = dirname(__DIR__, 3)
            . '/extends/module/Weline_Cart/CartItemSnapshotProvider/ProductCatalogCartItemSnapshotResolver.php';
        $source = (string)file_get_contents($path);
        self::assertStringContainsString('catalogForProduct($entity, $productId)', $source);
        self::assertStringContainsString("eavOptionSwatches(array_keys(\$selection), \$productId)", $source);
        self::assertStringContainsString("\$eavSwatches['images']", $source);
        self::assertStringContainsString("\$eavSwatches['colors']", $source);
        self::assertStringContainsString('swatch_image', $source);
    }
}
