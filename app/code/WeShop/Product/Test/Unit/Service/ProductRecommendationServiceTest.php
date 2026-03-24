<?php

declare(strict_types=1);

namespace WeShop\Product\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use WeShop\Product\Model\Product;
use WeShop\Product\Service\ProductRecommendationService;
use WeShop\Product\Service\ProductService;

class ProductRecommendationServiceTest extends TestCase
{
    public function testGetRecommendationsUsesSeedCategoriesExcludesSeedProductsAndFallsBack(): void
    {
        $seedProduct = $this->createConfiguredMock(Product::class, [
            'getData' => 8,
        ]);

        $productService = $this->createMock(ProductService::class);
        $productService->expects($this->exactly(2))
            ->method('getProduct')
            ->willReturnMap([
                [10, $seedProduct],
                [20, null],
            ]);
        $productService->expects($this->exactly(2))
            ->method('getProducts')
            ->willReturnMap([
                [[
                    'category_id' => 8,
                    'status' => 1,
                    'order_by' => Product::schema_fields_ID,
                    'order_dir' => 'DESC',
                ], 1, 6, [
                    'items' => [
                        ['product_id' => 10, 'name' => 'Seed Product', 'price' => 10],
                        ['product_id' => 30, 'name' => 'Travel Bottle', 'price' => 19.5, 'stock' => 8],
                    ],
                ]],
                [[
                    'status' => 1,
                    'order_by' => Product::schema_fields_ID,
                    'order_dir' => 'DESC',
                ], 1, 6, [
                    'items' => [
                        ['product_id' => 30, 'name' => 'Travel Bottle', 'price' => 19.5, 'stock' => 8],
                        ['product_id' => 40, 'name' => 'Packing Cube', 'price' => 9.9, 'stock' => 2],
                    ],
                ]],
            ]);

        $service = new ProductRecommendationService($productService);
        $result = $service->getRecommendations([10, 20], 3);

        $this->assertSame([30, 40], array_column($result, 'product_id'));
        $this->assertTrue((bool) ($result[0]['in_stock'] ?? false));
        $this->assertSame(0, $result[0]['review_count']);
    }

    public function testGetRecommendationsFallsBackWhenNoSeedProductsAreProvided(): void
    {
        $productService = $this->createMock(ProductService::class);
        $productService->expects($this->never())->method('getProduct');
        $productService->expects($this->once())
            ->method('getProducts')
            ->with([
                'status' => 1,
                'order_by' => Product::schema_fields_ID,
                'order_dir' => 'DESC',
            ], 1, 4)
            ->willReturn([
                'items' => [
                    ['product_id' => 91, 'name' => 'Desk Lamp', 'price' => 26],
                    ['product_id' => 92, 'name' => 'Notebook Stand', 'price' => 35],
                ],
            ]);

        $service = new ProductRecommendationService($productService);

        $result = $service->getRecommendations([], 2);

        $this->assertSame([91, 92], array_column($result, 'product_id'));
    }
}
