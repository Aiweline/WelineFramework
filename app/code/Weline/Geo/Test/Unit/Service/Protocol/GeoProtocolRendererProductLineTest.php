<?php

declare(strict_types=1);

namespace Weline\Geo\Test\Unit\Service\Protocol;

use PHPUnit\Framework\TestCase;
use Weline\Geo\Model\FeedItem;
use Weline\Geo\Service\Protocol\GeoProtocolRenderer;

class GeoProtocolRendererProductLineTest extends TestCase
{
    public function testProductLineIncludesCommerceMetadataForLlmsOutput(): void
    {
        $renderer = (new \ReflectionClass(GeoProtocolRenderer::class))->newInstanceWithoutConstructor();
        $isProduct = new \ReflectionMethod(GeoProtocolRenderer::class, 'isProductItem');
        $isProduct->setAccessible(true);
        $productLine = new \ReflectionMethod(GeoProtocolRenderer::class, 'productLine');
        $productLine->setAccessible(true);

        $item = [
            FeedItem::schema_fields_ITEM_TYPE => 'product',
            FeedItem::schema_fields_TITLE => 'Summer Dress',
            FeedItem::schema_fields_URL => 'https://shop.test/product/summer-dress',
            FeedItem::schema_fields_CONTENT => '<p>Lightweight dress for summer.</p>',
            FeedItem::schema_fields_METADATA => json_encode([
                'type' => 'product',
                'brand' => 'WeShop',
                'sku' => 'DRESS-001',
                'category_path' => 'Women > Dresses',
                'price' => '28.50',
                'currency' => 'USD',
                'availability' => 'https://schema.org/InStock',
                'rating' => '4.7',
                'review_count' => 18,
            ]),
        ];

        self::assertTrue($isProduct->invoke($renderer, $item));

        $line = $productLine->invoke($renderer, $item, false);

        self::assertStringContainsString('[Summer Dress](https://shop.test/product/summer-dress)', $line);
        self::assertStringContainsString('Brand: WeShop', $line);
        self::assertStringContainsString('SKU: DRESS-001', $line);
        self::assertStringContainsString('Category: Women > Dresses', $line);
        self::assertStringContainsString('Price: 28.50 USD', $line);
        self::assertStringContainsString('Availability: InStock', $line);
        self::assertStringContainsString('Rating: 4.7 (18 reviews)', $line);
        self::assertStringContainsString('Lightweight dress for summer.', $line);
    }
}
