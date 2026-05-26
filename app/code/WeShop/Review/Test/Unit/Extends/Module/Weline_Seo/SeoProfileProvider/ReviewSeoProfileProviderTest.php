<?php

declare(strict_types=1);

namespace WeShop\Review\Test\Unit\Extends\Module\Weline_Seo\SeoProfileProvider;

use PHPUnit\Framework\TestCase;
use WeShop\Review\Extends\Module\Weline_Seo\SeoProfileProvider\ReviewSeoProfileProvider;
use WeShop\Review\Service\ReviewSeoDataService;

class ReviewSeoProfileProviderTest extends TestCase
{
    public function testProvideSeoProfileBuildsProductReviewNodesFromReviewSeo(): void
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
                            ['code' => 'quality', 'name' => 'Product quality', 'value' => 5],
                        ],
                    ],
                ],
            ],
        ];

        $profile = (new ReviewSeoProfileProvider())->provideSeoProfile(null, $context);

        self::assertArrayNotHasKey('page_type', $profile);
        self::assertArrayHasKey('schema_nodes', $profile);
        self::assertCount(1, $profile['schema_nodes']);
        $node = $profile['schema_nodes'][0];
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

    public function testProvideSeoProfileBuildsStandaloneReviewPageProfile(): void
    {
        $template = new ReviewSeoProfileTemplateStub([
            'reviews' => [
                [
                    'customer_name' => 'Grace',
                    'rating' => 5,
                    'body' => 'The product exceeded expectations.',
                    'created_at' => '2026-05-21 08:00:00',
                ],
            ],
        ]);

        $profile = (new ReviewSeoProfileProvider())->provideSeoProfile($template, [
            'product' => ['name' => 'Summer Dress'],
        ]);

        self::assertSame('review_page', $profile['page_type']);
        self::assertSame('review', $profile['geo']['type']);
        self::assertCount(1, $profile['schema_nodes']);
        self::assertSame('Review', $profile['schema_nodes'][0]['@type']);
        self::assertSame('Summer Dress', $profile['schema_nodes'][0]['itemReviewed']['name']);
        self::assertSame('Grace', $profile['schema_nodes'][0]['author']['name']);
    }

    public function testProvideSeoProfileReturnsEmptyWithoutReviewData(): void
    {
        $provider = new ReviewSeoProfileProvider();

        self::assertSame([], $provider->provideSeoProfile(null, ['page_type' => 'web_page']));
        self::assertSame([], $provider->provideSeoProfile(null, [
            'page_type' => 'product',
            'canonical_url' => 'https://shop.test/product/view?id=123',
            'review_seo' => ['items' => []],
        ]));
    }

    public function testProvideSeoProfileEnrichesProductContextWithReviewSeo(): void
    {
        $seoDataService = $this->createMock(ReviewSeoDataService::class);
        $reviewSeo = [
            'aggregate' => [
                'rating_value' => 4.67,
                'review_count' => 12,
                'best_rating' => 5,
                'worst_rating' => 1,
            ],
            'items' => [
                ['id' => 1, 'author_name' => 'Ada', 'rating' => 5],
            ],
        ];

        $seoDataService->expects($this->once())
            ->method('getProductReviewSeo')
            ->with(123, 'en_US', 5)
            ->willReturn($reviewSeo);

        $profile = (new ReviewSeoProfileProvider($seoDataService))->provideSeoProfile(new ReviewSeoProfileTemplateStub(), [
            'page_type' => 'product',
            'locale' => 'en_US',
            'url' => 'https://example.test/product/view?id=123',
            'canonical_url' => 'https://example.test/product/view',
            'product' => [
                'product_id' => 123,
                'name' => 'Sample Product',
            ],
        ]);

        self::assertSame(4.67, $profile['product']['rating']);
        self::assertSame(12, $profile['product']['review_count']);
        self::assertSame('Sample Product', $profile['product']['name']);
        self::assertSame($reviewSeo, $profile['review_seo']);
        self::assertSame('Review', $profile['schema_nodes'][0]['@type']);
    }

    public function testReviewListingPageGetsNoindexAndProductCanonical(): void
    {
        $seoDataService = $this->createMock(ReviewSeoDataService::class);
        $seoDataService->expects($this->never())->method('getProductReviewSeo');

        $profile = (new ReviewSeoProfileProvider($seoDataService))
            ->provideSeoProfile(new ReviewSeoProfileTemplateStub(), [
                'page_type' => 'product',
                'url' => 'https://example.test/review/frontend/review?product_id=123&page=2',
                'canonical_url' => 'https://example.test/review/frontend/review',
                'product' => ['product_id' => 123, 'name' => 'Sample Product'],
                'review_seo' => ['items' => [['id' => 1]]],
            ]);

        self::assertSame('noindex,follow', $profile['robots']);
        self::assertSame('https://example.test/product/view?id=123', $profile['canonical_url']);
        self::assertNull($profile['review_seo']);
        self::assertFalse($profile['sitemap']['include']);
        self::assertFalse($profile['geo']['include']);
    }

    public function testServiceExceptionLeavesProductReviewProfileEmpty(): void
    {
        $seoDataService = $this->createMock(ReviewSeoDataService::class);
        $seoDataService->expects($this->once())
            ->method('getProductReviewSeo')
            ->willThrowException(new \RuntimeException('SEO unavailable'));

        $profile = (new ReviewSeoProfileProvider($seoDataService))->provideSeoProfile(new ReviewSeoProfileTemplateStub(), [
            'page_type' => 'product',
            'product' => ['product_id' => 123, 'name' => 'Sample Product'],
        ]);

        self::assertSame([], $profile);
    }
}

class ReviewSeoProfileTemplateStub
{
    /**
     * @param array<string, mixed> $data
     */
    public function __construct(private readonly array $data = [])
    {
    }

    public function getData(string $key): mixed
    {
        return $this->data[$key] ?? null;
    }

    /**
     * @param array<string, mixed> $params
     */
    public function getUrl(string $path, array $params = []): string
    {
        return '/' . ltrim($path, '/') . '?' . http_build_query($params);
    }
}
