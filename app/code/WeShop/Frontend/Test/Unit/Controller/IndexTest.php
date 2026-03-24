<?php

declare(strict_types=1);

namespace WeShop\Frontend\Test\Unit\Controller;

use PHPUnit\Framework\TestCase;
use WeShop\Catalog\Service\CategoryService;
use WeShop\Frontend\Controller\Index;
use WeShop\Product\Service\ProductService;

class IndexTest extends TestCase
{
    public function testControllerClassExists(): void
    {
        $this->assertTrue(class_exists(Index::class));
    }

    public function testControllerExtendsBaseController(): void
    {
        $reflection = new \ReflectionClass(Index::class);

        $this->assertTrue($reflection->isSubclassOf(\WeShop\Frontend\Controller\BaseController::class));
    }

    public function testControllerHasIndexMethod(): void
    {
        $reflection = new \ReflectionClass(Index::class);

        $this->assertTrue($reflection->hasMethod('index'));
    }

    public function testLayoutTypeIsHomepage(): void
    {
        $reflection = new \ReflectionClass(Index::class);
        $property = $reflection->getProperty('layoutType');
        $property->setAccessible(true);

        $controller = new Index(
            $this->createMock(ProductService::class),
            $this->createMock(CategoryService::class)
        );

        $this->assertSame('homepage', $property->getValue($controller));
    }

    public function testIndexReturnsString(): void
    {
        $categoryService = $this->createMock(CategoryService::class);
        $categoryService->expects($this->once())
            ->method('getCategoryTree')
            ->with(0)
            ->willReturn([]);

        $productService = $this->createMock(ProductService::class);
        $productService->expects($this->exactly(3))
            ->method('getProducts')
            ->willReturnOnConsecutiveCalls(
                ['items' => [], 'total' => 0, 'pagination' => ''],
                ['items' => [], 'total' => 0, 'pagination' => ''],
                ['items' => [], 'total' => 0, 'pagination' => '']
            );

        $assigned = [];
        $controller = $this->createControllerDouble($productService, $categoryService, $assigned, '<html>Test</html>');

        $this->assertSame('<html>Test</html>', $controller->index());
    }

    public function testIndexAssignsRequiredData(): void
    {
        $categoryService = $this->createMock(CategoryService::class);
        $categoryService->expects($this->once())
            ->method('getCategoryTree')
            ->with(0)
            ->willReturn([
                [
                    'category_id' => 12,
                    'name' => 'Shoes',
                    'image' => 'category.jpg',
                    'children' => [['category_id' => 13]],
                ],
            ]);

        $productService = $this->createMock(ProductService::class);
        $productService->expects($this->exactly(3))
            ->method('getProducts')
            ->willReturnOnConsecutiveCalls(
                [
                    'items' => [
                        [
                            'product_id' => 101,
                            'name' => 'Runner',
                            'short_description' => 'Fast and light',
                            'price' => 29.5,
                            'image' => 'runner.jpg',
                            'sku' => 'RUN-1',
                            'stock' => 3,
                        ],
                    ],
                    'total' => 1,
                    'pagination' => '',
                ],
                [
                    'items' => [
                        [
                            'product_id' => 202,
                            'name' => 'Deal Product',
                            'price' => 15.0,
                            'image' => 'deal.jpg',
                            'sku' => 'DEAL-1',
                        ],
                    ],
                    'total' => 1,
                    'pagination' => '',
                ],
                [
                    'items' => [
                        [
                            'product_id' => 303,
                            'name' => 'Best Seller',
                            'price' => 39.99,
                            'image' => 'best.jpg',
                            'sku' => 'BEST-1',
                        ],
                    ],
                    'total' => 1,
                    'pagination' => '',
                ]
            );

        $assigned = [];
        $controller = $this->createControllerDouble($productService, $categoryService, $assigned, 'rendered');

        $this->assertSame('rendered', $controller->index());
        $this->assertSame([
            [
                'category_id' => 12,
                'name' => 'Shoes',
                'url' => 'https://example.test/weshop-product-list?category_id=12',
                'image' => 'category.jpg',
                'children' => [['category_id' => 13]],
            ],
        ], $assigned['categories']);
        $this->assertSame([
            [
                'product_id' => 101,
                'name' => 'Runner',
                'short_description' => 'Fast and light',
                'price' => 29.5,
                'image' => 'runner.jpg',
                'sku' => 'RUN-1',
                'in_stock' => true,
            ],
        ], $assigned['recommended_products']);
        $this->assertSame([
            [
                'product_id' => 202,
                'name' => 'Deal Product',
                'price' => 15.0,
                'image' => 'deal.jpg',
                'sku' => 'DEAL-1',
            ],
        ], $assigned['deals']);
        $this->assertSame([
            [
                'product_id' => 303,
                'name' => 'Best Seller',
                'price' => 39.99,
                'image' => 'best.jpg',
                'sku' => 'BEST-1',
            ],
        ], $assigned['bestsellers']);
        $this->assertCount(3, $assigned['banners']);
        $this->assertSame('https://static.example.test/assets/images/banner-1.jpg', $assigned['banners'][0]['image']);
        $this->assertSame('https://example.test/weshop-product-list', $assigned['banners'][0]['link']);
        $this->assertNotEmpty((string) $assigned['title']);
    }

    public function testIndexHandlesEmptyData(): void
    {
        $categoryService = $this->createMock(CategoryService::class);
        $categoryService->expects($this->once())
            ->method('getCategoryTree')
            ->with(0)
            ->willReturn([]);

        $productService = $this->createMock(ProductService::class);
        $productService->expects($this->exactly(3))
            ->method('getProducts')
            ->willReturnOnConsecutiveCalls(
                ['items' => [], 'total' => 0, 'pagination' => ''],
                ['items' => [], 'total' => 0, 'pagination' => ''],
                ['items' => [], 'total' => 0, 'pagination' => '']
            );

        $assigned = [];
        $controller = $this->createControllerDouble($productService, $categoryService, $assigned, 'empty-rendered');

        $this->assertSame('empty-rendered', $controller->index());
        $this->assertSame([], $assigned['categories']);
        $this->assertSame([], $assigned['recommended_products']);
        $this->assertSame([], $assigned['deals']);
        $this->assertSame([], $assigned['bestsellers']);
        $this->assertCount(3, $assigned['banners']);
        $this->assertNotEmpty((string) $assigned['title']);
    }

    /**
     * @param array<string, mixed> $assigned
     */
    private function createControllerDouble(
        ProductService $productService,
        CategoryService $categoryService,
        array &$assigned,
        string $fetchResult
    ): Index {
        $controller = $this->getMockBuilder(Index::class)
            ->setConstructorArgs([$productService, $categoryService])
            ->onlyMethods(['getUrl', 'getStaticUrl', 'assign', 'fetch'])
            ->getMock();

        $controller->method('getUrl')
            ->willReturnCallback(static function (string $path, array $params = []): string {
                $url = 'https://example.test/' . str_replace('/', '-', trim($path, '/'));

                return $params === [] ? $url : $url . '?' . http_build_query($params);
            });
        $controller->method('getStaticUrl')
            ->willReturnCallback(static fn(string $path): string => 'https://static.example.test/' . ltrim($path, '/'));
        $controller->method('assign')
            ->willReturnCallback(function (string $key, mixed $value) use (&$assigned, $controller): Index {
                $assigned[$key] = $value;

                return $controller;
            });
        $controller->expects($this->once())
            ->method('fetch')
            ->with('WeShop_Frontend::templates/Index/index.phtml')
            ->willReturn($fetchResult);

        return $controller;
    }
}
