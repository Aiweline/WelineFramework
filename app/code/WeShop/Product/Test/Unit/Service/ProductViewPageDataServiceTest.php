<?php

declare(strict_types=1);

namespace WeShop\Product\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use WeShop\Price\Service\PriceService;
use WeShop\Product\Helper\HanfuDemoOptionImageProvider;
use WeShop\Product\Model\Product;
use WeShop\Product\Service\ProductEavService;
use WeShop\Product\Service\ProductRecommendationService;
use WeShop\Product\Service\ProductService;
use WeShop\Product\Service\ProductViewPageDataService;
use WeShop\QA\Service\QAService;
use WeShop\Review\Service\ReviewRatingOptionService;
use WeShop\Review\Service\ReviewService;

class ProductViewPageDataServiceTest extends TestCase
{
    private ProductService $productService;
    private PriceService $priceService;
    private ProductEavService $productEavService;
    private ProductRecommendationService $productRecommendationService;
    private ReviewService $reviewService;
    private ReviewRatingOptionService $ratingOptionService;
    private QAService $qaService;
    private ProductViewPageDataService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->productService = $this->createMock(ProductService::class);
        $this->priceService = $this->createMock(PriceService::class);
        $this->productEavService = $this->createMock(ProductEavService::class);
        $this->productRecommendationService = $this->createMock(ProductRecommendationService::class);
        $this->reviewService = $this->createMock(ReviewService::class);
        $this->ratingOptionService = $this->createMock(ReviewRatingOptionService::class);
        $this->qaService = $this->createMock(QAService::class);

        $this->service = new ProductViewPageDataService(
            $this->productService,
            $this->priceService,
            $this->productEavService,
            $this->productRecommendationService,
            $this->reviewService,
            $this->qaService,
            null,
            $this->ratingOptionService
        );
    }

    public function testBuildReturnsNullWhenProductDoesNotExist(): void
    {
        $this->productService->expects($this->once())
            ->method('getProduct')
            ->with(42)
            ->willReturn(null);

        $this->assertNull($this->service->build(42));
    }

    public function testBuildReturnsNullWhenProductIsDisabled(): void
    {
        $product = $this->createProductMock([
            Product::schema_fields_status => 0,
        ]);

        $this->productService->expects($this->once())
            ->method('getProduct')
            ->with(42)
            ->willReturn($product);

        $this->assertNull($this->service->build(42));
    }

    public function testBuildMapsStorefrontPayload(): void
    {
        $product = $this->createProductMock([
            Product::schema_fields_name => 'Trail Jacket',
            Product::schema_fields_short_description => 'Lightweight shell',
            Product::schema_fields_description => '<p>All weather protection</p>',
            Product::schema_fields_price => 129.5,
            Product::schema_fields_cost => 80,
            Product::schema_fields_sku => 'TJ-001',
            Product::schema_fields_stock => 12,
            Product::schema_fields_weight => 1.25,
            Product::schema_fields_image => '/media/trail-main.jpg',
            Product::schema_fields_images => json_encode(['/media/trail-side.jpg', '/media/trail-main.jpg'], JSON_THROW_ON_ERROR),
            Product::schema_fields_status => 1,
            Product::schema_fields_meta_name => 'Trail Jacket Meta',
            Product::schema_fields_meta_description => 'Trail meta description',
            Product::schema_fields_meta_keywords => 'trail,jacket',
            'special_price' => 99.9,
            'brand' => 'Northwind',
            'brand_id' => 7,
            'highlights' => json_encode(['Windproof', 'Packable'], JSON_THROW_ON_ERROR),
            'options' => json_encode([['id' => 1, 'label' => 'Size', 'values' => [['id' => 'm', 'label' => 'M']]]], JSON_THROW_ON_ERROR),
        ], [
            [
                'category_id' => 7,
                'name' => 'Outerwear',
            ],
        ]);

        $this->productService->expects($this->once())
            ->method('getProduct')
            ->with(42)
            ->willReturn($product);
        $this->priceService->expects($this->once())
            ->method('resolveProduct')
            ->with($product)
            ->willReturn([
                'price' => 99.9,
                'original_price' => 129.5,
                'special_price' => 99.9,
                'has_discount' => true,
                'discount_amount' => 29.6,
                'discount_percent' => 23,
            ]);

        $this->productEavService->expects($this->once())
            ->method('getProductAttributesViewModel')
            ->with(42)
            ->willReturn([
                [
                    'group_name' => 'General',
                    'group_id' => 1,
                    'items' => [
                        ['label' => 'Material', 'value' => 'Nylon', 'code' => 'material'],
                        ['label' => 'Fit', 'value' => 'Regular', 'code' => 'fit'],
                    ],
                ],
            ]);

        $this->productRecommendationService->expects($this->once())
            ->method('getRecommendations')
            ->with([42], 4)
            ->willReturn([
                [
                    'product_id' => 88,
                    'name' => 'Storm Cap',
                    'price' => 19.9,
                    'image' => '/media/storm-cap.jpg',
                ],
            ]);

        $this->reviewService->expects($this->once())
            ->method('getProductReviews')
            ->with(42, 1, 5)
            ->willReturn([
                'items' => [
                    ['rating' => 5],
                    ['rating' => 5],
                    ['rating' => 4],
                ],
                'total' => 3,
            ]);

        $this->reviewService->expects($this->once())
            ->method('getAverageRating')
            ->with(42)
            ->willReturn(4.7);
        $this->reviewService->method('decodeMediaItems')->willReturn([]);
        $this->reviewService->method('decodeRatingScores')->willReturn([]);

        $this->ratingOptionService->expects($this->once())
            ->method('getEnabledOptions')
            ->willReturn([
                ['code' => 'quality', 'label' => '商品质量'],
            ]);

        $this->qaService->expects($this->once())
            ->method('getProductQuestions')
            ->with(42)
            ->willReturn([
                ['question' => 'Is it waterproof?'],
            ]);

        $result = $this->service->build(42);

        $this->assertIsArray($result);
        $this->assertSame('Trail Jacket', $result['product']['name']);
        $this->assertSame(99.9, $result['product']['price']);
        $this->assertSame(129.5, $result['product']['original_price']);
        $this->assertSame(23, $result['product']['discount_percent']);
        $this->assertSame('/media/trail-main.jpg', $result['product']['main_image']);
        $this->assertSame(3, $result['product']['review_count']);
        $this->assertSame(4.7, $result['product']['rating']);
        $this->assertSame(67, $result['product']['rating_distribution'][5]);
        $this->assertSame(33, $result['product']['rating_distribution'][4]);
        $this->assertCount(2, $result['product_images']);
        $this->assertSame('catalog/category/view?id=7', $result['breadcrumbs'][0]['url']);
        $this->assertSame('Nylon', $result['product']['specifications'][0]['value']);
        $this->assertSame('Trail Jacket Meta', $result['meta_title']);
        $this->assertCount(1, $result['related_products']);
        $this->assertSame([['code' => 'quality', 'label' => '商品质量']], $result['rating_options']);
        $this->assertCount(1, $result['qa']);
    }

    public function testDemoCategoryFallbackOptionsIncludeImageStyleChoices(): void
    {
        $product = $this->createProductMock([
            Product::schema_fields_name => 'Demo Category Jacket',
            Product::schema_fields_short_description => 'Demo storefront product',
            Product::schema_fields_description => '<p>Demo details</p>',
            Product::schema_fields_price => 59.9,
            Product::schema_fields_sku => 'DEMO-CAT-0081',
            Product::schema_fields_stock => 121,
            Product::schema_fields_image => '/media/demo-main.jpg',
            Product::schema_fields_images => json_encode(['/media/demo-side.jpg', '/media/demo-detail.jpg'], JSON_THROW_ON_ERROR),
            Product::schema_fields_status => 1,
        ]);

        $this->productService->expects($this->once())
            ->method('getProduct')
            ->with(81)
            ->willReturn($product);
        $this->priceService->expects($this->once())
            ->method('resolveProduct')
            ->with($product)
            ->willReturn([
                'price' => 59.9,
                'original_price' => 59.9,
                'special_price' => null,
                'has_discount' => false,
                'discount_amount' => 0,
                'discount_percent' => 0,
            ]);
        $this->productEavService->expects($this->once())
            ->method('getProductAttributesViewModel')
            ->with(81)
            ->willReturn([]);
        $this->reviewService->expects($this->once())
            ->method('getProductReviews')
            ->with(81, 1, 5)
            ->willReturn(['items' => [], 'total' => 0]);
        $this->reviewService->expects($this->once())
            ->method('getAverageRating')
            ->with(81)
            ->willReturn(0.0);
        $this->qaService->expects($this->once())
            ->method('getProductQuestions')
            ->with(81)
            ->willReturn([]);
        $this->productRecommendationService->expects($this->once())
            ->method('getRecommendations')
            ->with([81], 4)
            ->willReturn([]);
        $this->ratingOptionService->expects($this->once())
            ->method('getEnabledOptions')
            ->willReturn([]);

        $result = $this->service->build(81);

        $this->assertIsArray($result);
        $attributes = $result['configurable_options']['attributes'] ?? [];
        $this->assertSame(['color', 'size', 'style'], array_column($attributes, 'code'));

        $color = array_values(array_filter(
            $attributes,
            static fn(array $attribute): bool => ($attribute['code'] ?? '') === 'color'
        ))[0] ?? null;
        $this->assertIsArray($color);
        $colorCodes = array_column($color['options'], 'code');
        $this->assertGreaterThan(12, count($colorCodes));
        $this->assertSame(['red', 'pink', 'green'], array_slice($colorCodes, 0, 3));
        $this->assertContains('navy', $colorCodes);
        $this->assertSame('color', $color['options'][0]['swatch_type']);
        $this->assertSame(HanfuDemoOptionImageProvider::imageFor('red', 'classic'), $color['options'][0]['option_image']);

        $style = array_values(array_filter(
            $attributes,
            static fn(array $attribute): bool => ($attribute['code'] ?? '') === 'style'
        ))[0] ?? null;
        $this->assertIsArray($style);
        $this->assertCount(3, $style['options']);
        $this->assertSame('image', $style['options'][0]['swatch_type']);
        $this->assertSame(HanfuDemoOptionImageProvider::imageFor('red', 'classic'), $style['options'][0]['swatch_value']);
        $this->assertSame(HanfuDemoOptionImageProvider::imageFor('red', 'lifestyle'), $style['options'][1]['option_image']);
        $this->assertSame(HanfuDemoOptionImageProvider::imageMatrix(), $result['configurable_options']['image_matrix']);
        $this->assertCount(count($colorCodes), $result['configurable_options']['image_matrix']);
        foreach ($colorCodes as $colorCode) {
            $this->assertArrayHasKey($colorCode, $result['configurable_options']['image_matrix']);
            $this->assertNotEmpty($result['configurable_options']['image_matrix'][$colorCode]['classic'] ?? '');
        }
        $this->assertSame(HanfuDemoOptionImageProvider::defaultImage(), $result['product']['main_image']);
    }

    /**
     * @param array<string, mixed> $data
     * @param array<int, array<string, mixed>> $categories
     */
    private function createProductMock(array $data, array $categories = []): Product
    {
        $product = $this->getMockBuilder(Product::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getId', 'getData', 'getCategoriesWithLocale'])
            ->getMock();

        $product->method('getId')->willReturn(42);
        $product->method('getData')->willReturnCallback(
            static fn(string $key): mixed => $data[$key] ?? null
        );
        $product->method('getCategoriesWithLocale')->willReturn($categories);

        return $product;
    }
}
