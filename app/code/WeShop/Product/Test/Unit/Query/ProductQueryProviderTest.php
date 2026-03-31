<?php

declare(strict_types=1);

namespace WeShop\Product\Test\Unit\Query;

use ArrayObject;
use PHPUnit\Framework\TestCase;
use Weline\Eav\Model\EavAttribute;
use Weline\Eav\Model\EavEntity;
use Weline\Eav\Model\EavAttribute\Type;
use WeShop\Price\Service\PriceService;
use WeShop\Product\Extends\Module\Weline_Framework\Query\ProductQueryProvider;
use WeShop\Product\Model\Product;
use WeShop\Product\Model\ProductCategory;

class ProductQueryProviderTest extends TestCase
{
    public function testSearchProductsFiltersAndSortsByEffectivePrice(): void
    {
        $calls = new ArrayObject();
        $product = $this->createFakeProduct($calls, [
            ['product_id' => 8, 'name' => 'Travel Bag', 'price' => 120],
            ['product_id' => 9, 'name' => 'Mini Bag', 'price' => 60],
            ['product_id' => 10, 'name' => 'Luxury Bag', 'price' => 200],
        ], 3, '<nav>...</nav>');

        $priceService = $this->createResolvingPriceService([
            8 => ['price' => 20.0, 'original_price' => 120.0],
            9 => ['price' => 40.0, 'original_price' => 60.0],
            10 => ['price' => 140.0, 'original_price' => 200.0],
        ]);

        $provider = new ProductQueryProvider($product, $priceService, $this->createFakeProductCategory());
        $result = $provider->execute('searchProducts', [
            'keyword' => 'bag',
            'filters' => [
                'price_min' => 10,
                'price_max' => 99,
                'order_by' => 'price',
                'order_dir' => 'asc',
            ],
            'page' => 1,
            'page_size' => 12,
        ]);

        $recordedCalls = iterator_to_array($calls);
        $this->assertSame(
            ['where', "(name LIKE '%bag%' OR sku LIKE '%bag%' OR short_description LIKE '%bag%' OR description LIKE '%bag%')", null, '=', 'AND', 'AND'],
            $recordedCalls[1]
        );
        $this->assertSame(
            ['where', Product::schema_fields_status, 1, '=', 'AND', 'AND'],
            $recordedCalls[2]
        );
        $this->assertNotContains(['where', Product::schema_fields_price, 10.0, '>=', 'AND', 'AND'], $recordedCalls);
        $this->assertNotContains(['where', Product::schema_fields_price, 99.0, '<=', 'AND', 'AND'], $recordedCalls);
        $this->assertSame(['pagination', 1, 12, 2], $recordedCalls[5]);
        $this->assertSame(2, $result['total']);
        $this->assertSame('Travel Bag', $result['items'][0]['name']);
        $this->assertSame(20.0, $result['items'][0]['price']);
        $this->assertSame('Mini Bag', $result['items'][1]['name']);
        $this->assertSame('<nav>...</nav>', $result['pagination']);
    }

    public function testSearchProductsResolvesCategoryFilterThroughProductCategoryMapping(): void
    {
        $productCalls = new ArrayObject();
        $categoryCalls = new ArrayObject();
        $product = $this->createFakeProduct($productCalls, [
            ['product_id' => 2, 'name' => 'MacBook Pro 14', 'price' => 1999],
        ], 1, '<nav>page</nav>');
        $productCategory = $this->createFakeProductCategory($categoryCalls, [
            [ProductCategory::schema_fields_product_id => 2],
            [ProductCategory::schema_fields_product_id => 2],
        ]);
        $priceService = $this->createResolvingPriceService([
            2 => ['price' => 1899.0, 'original_price' => 1999.0],
        ]);

        $provider = new ProductQueryProvider($product, $priceService, $productCategory);
        $result = $provider->execute('searchProducts', [
            'filters' => [
                'category_id' => 14,
            ],
            'page' => 1,
            'page_size' => 12,
        ]);

        $this->assertContains(
            ['where', ProductCategory::schema_fields_category_id, [14], 'in', 'AND', 'AND'],
            iterator_to_array($categoryCalls)
        );
        $this->assertContains(
            ['where', Product::schema_fields_ID, [2], 'in', 'AND', 'AND'],
            iterator_to_array($productCalls)
        );
        $this->assertNotContains(
            ['where', 'category_id', 14, '=', 'AND', 'AND'],
            iterator_to_array($productCalls)
        );
        $this->assertSame(1, $result['total']);
        $this->assertSame('MacBook Pro 14', $result['items'][0]['name']);
    }

    public function testGetAttributeInfoUsesRealAttributeIdFieldInsteadOfCompositeModelId(): void
    {
        $this->markTestSkipped('Covered by EAV integration regression after explicit attribute lookup rewrite.');

        $attribute = $this->createMock(EavAttribute::class);
        $typeModel = $this->createMock(Type::class);
        $typeModel->method('getCode')->willReturn('select_option');

        $attribute->method('getId')->willReturn(2);
        $attribute->method('getData')->willReturnMap([
            [EavAttribute::schema_fields_attribute_id, null, 8],
        ]);
        $attribute->method('getTypeModel')->willReturn($typeModel);
        $attribute->method('hasOption')->willReturn(true);
        $attribute->method('getName')->willReturn('品牌');
        $attribute->method('getCode')->willReturn('brand');
        $attribute->method('getMultipleValued')->willReturn(false);
        $attribute->method('isFilterable')->willReturn(true);
        $attribute->method('isSearchable')->willReturn(true);
        $attribute->method('isVisibleOnFront')->willReturn(false);
        $attribute->method('reset')->willReturnSelf();
        $attribute->method('clearData')->willReturnSelf();
        $attribute->method('where')->willReturnSelf();
        $attribute->method('find')->willReturnSelf();
        $attribute->method('fetch')->willReturnSelf();

        $entity = $this->createMock(EavEntity::class);
        $entity->method('reset')->willReturnSelf();
        $entity->method('clearData')->willReturnSelf();
        $entity->method('where')->willReturnSelf();
        $entity->method('find')->willReturnSelf();
        $entity->method('fetch')->willReturnSelf();
        $entity->method('getId')->willReturn(2);

        $provider = new ProductQueryProvider(
            $this->createFakeProduct(new ArrayObject()),
            $this->createMock(PriceService::class),
            $this->createFakeProductCategory(),
            $entity,
            $attribute
        );

        $result = $provider->execute('getAttributeInfo', ['attribute_code' => 'brand']);

        $this->assertSame(8, $result['attribute_id']);
        $this->assertSame('select_option', $result['type_code']);
        $this->assertTrue($result['has_option']);
        $this->assertSame('brand', $result['code']);
    }

    public function testGetPriceStatsUsesEffectivePricePayload(): void
    {
        $calls = new ArrayObject();
        $product = $this->createFakeProduct($calls, [
            ['product_id' => 8, 'price' => 120],
            ['product_id' => 9, 'price' => 90],
            ['product_id' => 10, 'price' => 200],
        ]);
        $priceService = $this->createResolvingPriceService([
            8 => ['price' => 80.0, 'original_price' => 120.0],
            9 => ['price' => 40.0, 'original_price' => 90.0],
            10 => ['price' => 120.0, 'original_price' => 200.0],
        ]);

        $provider = new ProductQueryProvider($product, $priceService, $this->createFakeProductCategory());
        $result = $provider->execute('getPriceStats', ['product_ids' => [8, 9, 10]]);

        $this->assertSame(40.0, $result['min']);
        $this->assertSame(120.0, $result['max']);
        $this->assertSame(80.0, $result['avg']);
    }

    public function testFilterByPriceRangeUsesEffectivePricePayload(): void
    {
        $calls = new ArrayObject();
        $product = $this->createFakeProduct($calls, [
            ['product_id' => 8, 'price' => 100],
            ['product_id' => 9, 'price' => 100],
            ['product_id' => 10, 'price' => 100],
        ]);
        $priceService = $this->createResolvingPriceService([
            8 => ['price' => 50.0, 'original_price' => 100.0],
            9 => ['price' => 120.0, 'original_price' => 100.0],
            10 => ['price' => 220.0, 'original_price' => 100.0],
        ]);

        $provider = new ProductQueryProvider($product, $priceService, $this->createFakeProductCategory());
        $result = $provider->execute('filterByPriceRange', [
            'product_ids' => [10, 9, 8],
            'ranges' => [
                ['min' => 0, 'max' => 100],
                ['min' => 200, 'max' => null],
            ],
        ]);

        $this->assertSame([10, 8], $result);
    }

    public function testCountByPriceRangeUsesEffectivePricePayload(): void
    {
        $calls = new ArrayObject();
        $product = $this->createFakeProduct($calls, [
            ['product_id' => 8, 'price' => 100],
            ['product_id' => 9, 'price' => 100],
            ['product_id' => 10, 'price' => 100],
        ]);
        $priceService = $this->createResolvingPriceService([
            8 => ['price' => 50.0, 'original_price' => 100.0],
            9 => ['price' => 120.0, 'original_price' => 100.0],
            10 => ['price' => 55.0, 'original_price' => 100.0],
        ]);

        $provider = new ProductQueryProvider($product, $priceService, $this->createFakeProductCategory());
        $result = $provider->execute('countByPriceRange', [
            'product_ids' => [8, 9, 10],
            'min_price' => 0,
            'max_price' => 60,
        ]);

        $this->assertSame(2, $result);
    }

    public function testGetProductSuggestionsUsesLikeConditionSignature(): void
    {
        $calls = new ArrayObject();
        $product = $this->createFakeProduct($calls, [
            [
                Product::schema_fields_ID => 5,
                Product::schema_fields_name => 'Desk Lamp',
            ],
        ]);

        $priceService = $this->createMock(PriceService::class);

        $provider = new ProductQueryProvider($product, $priceService, $this->createFakeProductCategory());
        $result = $provider->execute('getProductSuggestions', [
            'keyword' => 'lamp',
            'limit' => 7,
        ]);

        $this->assertSame(
            ['where', Product::schema_fields_name, '%lamp%', 'LIKE', 'AND', 'AND'],
            $calls[1]
        );
        $this->assertSame(
            ['where', Product::schema_fields_status, 1, '=', 'AND', 'AND'],
            $calls[2]
        );
        $this->assertSame(['limit', 5, 0], $calls[4]);
        $this->assertSame('Desk Lamp', $result[0]['text']);
        $this->assertSame('product', $result[0]['type']);
    }

    private function createFakeProduct(ArrayObject $calls, array $rows = [], int $total = 0, string $pagination = ''): Product
    {
        return new class($calls, $rows, $total, $pagination) extends Product {
            public function __construct(
                private readonly ArrayObject $calls,
                private readonly array $rows,
                private readonly int $total,
                private readonly string $paginationMarkup
            ) {
            }

            public function clear(bool $with_query = true): static
            {
                $this->calls[] = ['clear'];

                return $this;
            }

            public function where(array|string $field, mixed $value = null, string $condition = '=', string $where_logic = 'AND', string $array_where_logic_type = 'AND'): static
            {
                $this->calls[] = ['where', $field, $value, $condition, $where_logic, $array_where_logic_type];

                return $this;
            }

            public function order(string $field = '', string $sort = 'DESC'): static
            {
                $this->calls[] = ['order', $field, $sort];

                return $this;
            }

            public function pagination(int $page = 0, int $pageSize = 0, array $params = [], int $max_limit = 1000, int $total = 0): static
            {
                $this->calls[] = ['pagination', $page, $pageSize, $total];

                return $this;
            }

            public function limit(int $size, int $offset = 0): static
            {
                $this->calls[] = ['limit', $size, $offset];

                return $this;
            }

            public function select(string $fields = ''): static
            {
                $this->calls[] = ['select', $fields];

                return $this;
            }

            public function fetchArray(): array
            {
                $this->calls[] = ['fetchArray'];

                return $this->rows;
            }

            public function getTotalCount(): int
            {
                return $this->total;
            }

            public function getPagination(string $pagination_style = 'pagination-rounded', string $url_path = ''): string
            {
                return $this->paginationMarkup;
            }
        };
    }

    private function createFakeProductCategory(?ArrayObject $calls = null, array $rows = []): ProductCategory
    {
        return new class($calls ?? new ArrayObject(), $rows) extends ProductCategory {
            public function __construct(
                private readonly ArrayObject $calls,
                private readonly array $rows
            ) {
            }

            public function clear(bool $with_query = true): static
            {
                $this->calls[] = ['clear'];

                return $this;
            }

            public function where(array|string $field, mixed $value = null, string $condition = '=', string $where_logic = 'AND', string $array_where_logic_type = 'AND'): static
            {
                $this->calls[] = ['where', $field, $value, $condition, $where_logic, $array_where_logic_type];

                return $this;
            }

            public function select(string $fields = ''): static
            {
                $this->calls[] = ['select', $fields];

                return $this;
            }

            public function fetchArray(): array
            {
                $this->calls[] = ['fetchArray'];

                return $this->rows;
            }
        };
    }

    /**
     * @param array<int, array<string, mixed>> $resolvedById
     */
    private function createResolvingPriceService(array $resolvedById): PriceService
    {
        $priceService = $this->createMock(PriceService::class);
        $priceService->method('resolveProductData')
            ->willReturnCallback(static function (array $item) use ($resolvedById): array {
                $productId = (int) ($item['product_id'] ?? $item['id'] ?? 0);

                return array_merge($item, $resolvedById[$productId] ?? []);
            });

        return $priceService;
    }
}
