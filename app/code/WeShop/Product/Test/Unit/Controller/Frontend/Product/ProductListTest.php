<?php

declare(strict_types=1);

namespace WeShop\Product\Test\Unit\Controller\Frontend\Product;

use PHPUnit\Framework\TestCase;
use WeShop\Catalog\Model\Category;
use WeShop\Catalog\Service\CategoryService;
use WeShop\Product\Controller\Frontend\Product\ProductList;
use WeShop\Product\Service\ProductService;
use Weline\Framework\Http\Request;

class ProductListTest extends TestCase
{
    private const CONTENT_TEMPLATE = 'WeShop_Product::templates/frontend/product/list/index.phtml';

    public function testControllerClassExists(): void
    {
        $this->assertTrue(class_exists(ProductList::class));
    }

    public function testControllerExtendsBaseController(): void
    {
        $reflection = new \ReflectionClass(ProductList::class);
        $this->assertTrue($reflection->isSubclassOf(\WeShop\Frontend\Controller\BaseController::class));
    }

    public function testControllerHasIndexMethod(): void
    {
        $reflection = new \ReflectionClass(ProductList::class);
        $this->assertTrue($reflection->hasMethod('index'));
    }

    public function testLayoutTypeIsProductList(): void
    {
        $reflection = new \ReflectionClass(ProductList::class);
        $property = $reflection->getProperty('layoutType');
        $property->setAccessible(true);

        $controller = new ProductList(
            $this->createMock(ProductService::class),
            $this->createMock(CategoryService::class)
        );

        $this->assertSame('product_list', $property->getValue($controller));
    }

    public function testIndexBuildsDefaultProductListingQuery(): void
    {
        $productService = $this->createMock(ProductService::class);
        $productService->expects($this->once())
            ->method('getProducts')
            ->with(
                [
                    'order_by' => 'product_id',
                    'order_dir' => 'DESC',
                    'status' => 'enabled',
                ],
                1,
                20
            )
            ->willReturn($this->buildResult([
                [
                    'product_id' => 10,
                    'name' => 'Starter Camera',
                    'price' => 99.5,
                    'stock' => 7,
                    'image' => '/camera.jpg',
                    'sku' => 'CAM-10',
                ],
            ]));

        $categoryService = $this->createMock(CategoryService::class);
        $categoryService->expects($this->never())->method('getCategory');

        $request = $this->createMock(Request::class);
        $request->method('getParam')->willReturnCallback($this->requestParams([
            'page' => null,
            'page_size' => null,
            'category_id' => null,
            'q' => null,
            'search' => null,
            'min_price' => null,
            'max_price' => null,
            'order_by' => null,
            'order_dir' => null,
        ]));

        $assigned = [];
        $controller = $this->createController($productService, $categoryService, $request, $assigned);

        $this->assertSame('html', $controller->index());
        $this->assertSame('Starter Camera', $assigned['products'][0]['name']);
        $this->assertSame(99.5, $assigned['products'][0]['original_price']);
        $this->assertSame(0, $assigned['products'][0]['discount_percent']);
        $this->assertTrue($assigned['products'][0]['in_stock']);
        $this->assertNull($assigned['category']);
        $this->assertSame('', $assigned['search']);
        $this->assertSame('product_id', $assigned['order_by']);
        $this->assertSame('DESC', $assigned['order_dir']);
        $this->assertSame(1, $assigned['page']);
        $this->assertSame(20, $assigned['page_size']);
    }

    public function testIndexAppliesCategorySearchAndPriceFilters(): void
    {
        $productService = $this->createMock(ProductService::class);
        $productService->expects($this->once())
            ->method('getProducts')
            ->with(
                [
                    'category_id' => 8,
                    'name' => 'helmet',
                    'min_price' => 50.0,
                    'max_price' => 200.0,
                    'order_by' => 'price',
                    'order_dir' => 'ASC',
                    'status' => 'enabled',
                ],
                3,
                12
            )
            ->willReturn($this->buildResult());

        $categoryService = $this->createMock(CategoryService::class);
        $categoryService->expects($this->once())
            ->method('getCategory')
            ->with(8)
            ->willReturn(
                $this->createConfiguredMock(Category::class, [
                    'getData' => ['category_id' => 8, 'name' => 'Helmets'],
                ])
            );

        $request = $this->createMock(Request::class);
        $request->method('getParam')->willReturnCallback($this->requestParams([
            'page' => 3,
            'page_size' => 12,
            'category_id' => 8,
            'q' => 'helmet',
            'search' => 'legacy-search',
            'min_price' => '50',
            'max_price' => '200',
            'order_by' => 'price',
            'order_dir' => 'asc',
        ]));

        $assigned = [];
        $controller = $this->createController($productService, $categoryService, $request, $assigned);

        $this->assertSame('html', $controller->index());
        $this->assertSame('helmet', $assigned['search']);
        $this->assertInstanceOf(Category::class, $assigned['category']);
        $this->assertSame(8, $assigned['filters']['category_id']);
        $this->assertSame(50.0, $assigned['filters']['min_price']);
        $this->assertSame(200.0, $assigned['filters']['max_price']);
        $this->assertSame('price', $assigned['order_by']);
        $this->assertSame('ASC', $assigned['order_dir']);
        $this->assertSame(3, $assigned['page']);
        $this->assertSame(12, $assigned['page_size']);
    }

    public function testIndexFallsBackToSafeSortingWhenRequestValuesAreInvalid(): void
    {
        $productService = $this->createMock(ProductService::class);
        $productService->expects($this->once())
            ->method('getProducts')
            ->with(
                [
                    'order_by' => 'product_id',
                    'order_dir' => 'DESC',
                    'status' => 'enabled',
                ],
                2,
                30
            )
            ->willReturn($this->buildResult());

        $categoryService = $this->createMock(CategoryService::class);
        $categoryService->expects($this->never())->method('getCategory');

        $request = $this->createMock(Request::class);
        $request->method('getParam')->willReturnCallback($this->requestParams([
            'page' => 2,
            'page_size' => 30,
            'category_id' => 0,
            'q' => '',
            'search' => '',
            'min_price' => 'abc',
            'max_price' => null,
            'order_by' => 'dangerous_column',
            'order_dir' => 'sideways',
        ]));

        $assigned = [];
        $controller = $this->createController($productService, $categoryService, $request, $assigned);

        $this->assertSame('html', $controller->index());
        $this->assertArrayNotHasKey('min_price', $assigned['filters']);
        $this->assertSame('product_id', $assigned['filters']['order_by']);
        $this->assertSame('DESC', $assigned['filters']['order_dir']);
        $this->assertSame(2, $assigned['page']);
        $this->assertSame(30, $assigned['page_size']);
    }

    private function createController(
        ProductService $productService,
        CategoryService $categoryService,
        Request $request,
        array &$assigned
    ): ProductList {
        $controller = $this->getMockBuilder(ProductList::class)
            ->setConstructorArgs([$productService, $categoryService])
            ->onlyMethods(['assign', 'fetch', 'getRequest'])
            ->getMock();
        $controller->method('getRequest')->willReturn($request);
        $controller->method('assign')->willReturnCallback(function (string $key, mixed $value) use (&$assigned, $controller): ProductList {
            $assigned[$key] = $value;
            return $controller;
        });
        $controller->expects($this->once())
            ->method('fetch')
            ->with(self::CONTENT_TEMPLATE)
            ->willReturn('html');

        return $controller;
    }

    /**
     * @param array<int,array<string,mixed>> $items
     * @return array<string,mixed>
     */
    private function buildResult(array $items = []): array
    {
        return [
            'items' => $items,
            'pagination' => ['current_page' => 1, 'total_pages' => 1],
            'total' => count($items),
        ];
    }

    /**
     * @param array<string,mixed> $params
     */
    private function requestParams(array $params): \Closure
    {
        return static fn(string $key, mixed $default = null): mixed => \array_key_exists($key, $params)
            ? $params[$key]
            : $default;
    }
}
