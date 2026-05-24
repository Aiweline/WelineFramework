<?php

declare(strict_types=1);

namespace WeShop\Review\Test\Unit\Extends\Module\Weline_Seo\StructuredDataProvider;

use PHPUnit\Framework\TestCase;
use WeShop\Review\Extends\Module\Weline_Seo\StructuredDataProvider\ReviewStructuredDataProvider;

class ReviewStructuredDataProviderTest extends TestCase
{
    public function testProvideStructuredDataBuildsReviewNodesLinkedToProduct(): void
    {
        $context = [
            'page_type' => 'product',
            'canonical_url' => 'https://shop.test/product/view?id=123',
            'review_seo' => [
                'aggregate' => [
                    'best_rating' => 5,
                    'worst_rating' => 1,
                ],
                'items' => [
                    [
                        'id' => 501,
                        'author_name' => 'Ada',
                        'rating' => 4.5,
                        'title' => 'Great fit',
                        'body' => 'Fast shipping and solid quality.',
                        'created_at' => '2026-05-20T10:00:00+08:00',
                        'media' => [
                            ['type' => 'image', 'url' => '/media/review/a.jpg', 'label' => 'Front'],
                            ['type' => 'video', 'url' => 'https://cdn.shop.test/review/a.mp4', 'label' => 'Demo'],
                            ['type' => 'image', 'url' => '//evil.test/review/a.jpg', 'label' => 'Unsafe'],
                        ],
                        'rating_scores' => [
                            ['code' => 'quality', 'name' => '商品质量', 'value' => 5],
                        ],
                    ],
                ],
            ],
        ];

        $nodes = (new ReviewStructuredDataProvider())->provideStructuredData(null, $context);

        self::assertCount(1, $nodes);
        $node = $nodes[0];
        self::assertSame('Review', $node['@type']);
        self::assertSame('https://shop.test/product/view?id=123#review-501', $node['@id']);
        self::assertSame(['@id' => 'https://shop.test/product/view?id=123#product'], $node['itemReviewed']);
        self::assertSame(['@type' => 'Person', 'name' => 'Ada'], $node['author']);
        self::assertSame('4.5', $node['reviewRating']['ratingValue']);
        self::assertSame('Great fit', $node['name']);
        self::assertSame('Fast shipping and solid quality.', $node['reviewBody']);
        self::assertSame('2026-05-20T10:00:00+08:00', $node['datePublished']);
        self::assertSame(['https://shop.test/media/review/a.jpg'], $node['image']);
        self::assertSame('VideoObject', $node['video'][0]['@type']);
        self::assertSame('https://cdn.shop.test/review/a.mp4', $node['video'][0]['contentUrl']);
        self::assertSame('PropertyValue', $node['additionalProperty'][0]['@type']);
        self::assertSame('quality', $node['additionalProperty'][0]['propertyID']);
        self::assertSame('5', $node['additionalProperty'][0]['value']);
    }

    public function testProvideStructuredDataReturnsEmptyForNonProductOrMissingItems(): void
    {
        $provider = new ReviewStructuredDataProvider();

        self::assertSame([], $provider->provideStructuredData(null, ['page_type' => 'web_page']));
        self::assertSame([], $provider->provideStructuredData(null, [
            'page_type' => 'product',
            'canonical_url' => 'https://shop.test/product/view?id=123',
            'review_seo' => ['items' => []],
        ]));
    }
}
