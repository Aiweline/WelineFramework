<?php

declare(strict_types=1);

namespace Weline\Geo\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Geo\Service\SeoProfileGeoMetadataNormalizer;

class SeoProfileGeoMetadataNormalizerTest extends TestCase
{
    public function testNormalizesProductProfileFactsForGeoFeed(): void
    {
        $normalizer = new SeoProfileGeoMetadataNormalizer();

        $item = $normalizer->toFeedItemData([
            'page_type' => 'product',
            'title' => 'Summer Dress',
            'description' => 'Lightweight dress.',
            'canonical_url' => 'https://shop.test/product/summer-dress',
            'image' => 'https://shop.test/media/dress.jpg',
            'robots' => 'index,follow',
            'product' => [
                'sku' => 'DRESS-001',
                'spu' => 'SPU-DRESS',
                'brand' => 'WeShop',
                'price' => '28.50',
                'price_currency' => 'USD',
                'availability' => 'https://schema.org/InStock',
                'category_path' => 'Women > Dresses',
            ],
        ]);

        self::assertSame('Summer Dress', $item['title']);
        self::assertSame('https://shop.test/product/summer-dress', $item['url']);
        self::assertSame(1, $item['is_published']);
        self::assertSame('product', $item['metadata']['type']);
        self::assertSame('DRESS-001', $item['metadata']['sku']);
        self::assertSame('28.50', $item['metadata']['price']);
        self::assertSame('USD', $item['metadata']['currency']);
        self::assertSame('Women > Dresses', $item['metadata']['category_path']);
    }

    public function testNoindexProfileIsNotPublishedToGeoFeed(): void
    {
        $item = (new SeoProfileGeoMetadataNormalizer())->toFeedItemData([
            'page_type' => 'checkout',
            'title' => 'Checkout',
            'canonical_url' => 'https://shop.test/checkout',
            'robots' => 'noindex,follow',
        ]);

        self::assertSame(0, $item['is_published']);
    }

    public function testArticleAuthorsBackfillScalarAuthorAndDepth(): void
    {
        $item = (new SeoProfileGeoMetadataNormalizer())->toFeedItemData([
            'page_type' => 'blog_post',
            'title' => 'Post',
            'description' => 'Body',
            'canonical_url' => 'https://shop.test/blog/a',
            'robots' => 'index,follow',
            'article' => [
                'headline' => 'Post',
                'authors' => [[
                    '@type' => 'Person',
                    'name' => 'Editor',
                    'url' => 'https://shop.test/about/editor',
                    'jobTitle' => 'Researcher',
                ]],
            ],
        ]);

        self::assertSame('Editor', $item['metadata']['author'] ?? null);
        self::assertSame('Editor', $item['metadata']['authors'][0]['name'] ?? null);
        self::assertSame('https://shop.test/about/editor', $item['metadata']['authors'][0]['url'] ?? null);
        self::assertSame('Researcher', $item['metadata']['authors'][0]['jobTitle'] ?? null);
    }
}
