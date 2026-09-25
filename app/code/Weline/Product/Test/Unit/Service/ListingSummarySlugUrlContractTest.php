<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Cart\Api\Data\CartItemSnapshot;
use Weline\Cart\Api\Data\OfferIdentity;

/**
 * Summary catalog cards must emit /product/{slug} when the product has a slug.
 * Regression: includeListingDetails=false dropped slug from the snapshot allowlist,
 * so cards fell back to /product/{id}.
 */
final class ListingSummarySlugUrlContractTest extends TestCase
{
    public function testSnapshotAllowlistIncludesSlugAttributes(): void
    {
        $source = (string)file_get_contents(
            dirname(__DIR__, 3)
            . '/extends/module/Weline_Cart/CartItemSnapshotProvider/ProductCatalogCartItemSnapshotResolver.php',
        );
        self::assertNotSame('', $source);
        self::assertStringContainsString(
            "in_array(\$code, ['name', 'product_type', 'quote_only', 'slug', 'source_slug'], true)",
            $source,
        );
        self::assertStringContainsString("slug: (string)(\$facts['slug'] ?? '')", $source);
        self::assertStringContainsString("\$attributeRowsByProductAndCode[\$productId]['source_slug']", $source);
    }

    public function testCatalogRowMapsSnapshotSlugAndBustsSummaryCache(): void
    {
        $source = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/StorefrontCatalogViewService.php',
        );
        self::assertStringContainsString("'slug' => \$this->resolvePublicCatalogSlug(", $source);
        self::assertStringContainsString("'summary-slug2'", $source);
        self::assertStringContainsString('publicSlugFromSku', $source);
    }

    public function testCartItemSnapshotExposesSlugInToArray(): void
    {
        $snapshotSource = (string)file_get_contents(
            dirname(__DIR__, 4) . '/Cart/Api/Data/CartItemSnapshot.php',
        );
        self::assertStringContainsString('public readonly string $slug = \'\'', $snapshotSource);
        self::assertStringContainsString("'slug' => trim(\$this->slug)", $snapshotSource);

        $snapshot = new CartItemSnapshot(
            offer: new OfferIdentity('product', 'offer-slug-113', 113),
            name: 'Demo',
            slug: 'demo-product-slug',
        );
        $row = $snapshot->toArray();
        self::assertSame('demo-product-slug', $row['slug'] ?? null);
    }

    public function testCardRendererPrefersSlugPathOverNumericId(): void
    {
        $source = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/ProductCardRenderer.php',
        );
        self::assertStringContainsString("\$urlPath = trim((string)(\$product['url_path'] ?? ''))", $source);
        self::assertStringContainsString("\$route === '' && \$urlPath !== ''", $source);
        self::assertStringContainsString("\$slug = strtolower(trim((string)(\$product['slug'] ?? '')))", $source);
        self::assertStringContainsString("'product/' . ltrim(\$slug, '/')", $source);
        self::assertStringContainsString("'product/' . \$productId", $source);
    }

    public function testRecentlyViewedCardsPreferSlugPathOverNumericId(): void
    {
        $source = (string)file_get_contents(
            dirname(__DIR__, 4)
            . '/RecentlyViewed/Service/RecentlyViewedService.php',
        );
        self::assertStringContainsString("\$offer['slug'] ?? \$offer['source_slug']", $source);
        self::assertStringContainsString("'product/' . \$slug", $source);
        self::assertStringContainsString("'product/' . \$productId", $source);
        self::assertStringContainsString("'slug' => \$slug", $source);
    }
}
