<?php

declare(strict_types=1);

namespace WeShop\Product\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use WeShop\Product\Model\Product;
use WeShop\Product\Service\ProductEavService;
use WeShop\Product\Service\ProductRecommendationService;
use WeShop\Product\Service\ProductService;
use WeShop\Product\Service\ProductViewPageDataService;
use WeShop\QA\Service\QAService;
use WeShop\Review\Service\ReviewService;

class ProductViewPageDataServiceTest extends TestCase
{
    private ProductService $productService;
    private ProductEavService $productEavService;
    private ProductRecommendationService $productRecommendationService;
    private ReviewService $reviewService;
    private QAService $qaService;
    private ProductViewPageDataService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->productService = $this->createMock(ProductService::class);
        $this->productEavService = $this->createMock(ProductEavService::class);
        $this->productRecommendationService = $this->createMock(ProductRecommendationService::class);
        $this->reviewService = $this->createMock(ReviewService::class);
        $this->qaService = $this->createMock(QAService::class);

        $this->service = new ProductViewPageDataService(
            $this->productService,
            $this->productEavService,
            $this->productRecommendationService,
            $this->reviewService,
            $this->qaService
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

        $this->qaService->expects($this->once())
            ->method('getProductQuestions')
            ->with(42)
            ->willReturn([
                ['question' => 'Is it waterproof?'],
            ]);

        $result = $this->service->build(42);

        $this->assertIsArray($result);
        $this->assertSame('Trail Jacket', $result['product']['name']);
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
        $this->assertCount(1, $result['qa']);
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
