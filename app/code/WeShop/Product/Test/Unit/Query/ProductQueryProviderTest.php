<?php

declare(strict_types=1);

namespace WeShop\Product\Test\Unit\Query;

use ArrayObject;
use PHPUnit\Framework\TestCase;
use WeShop\Product\Extends\Module\Weline_Framework\Query\ProductQueryProvider;
use WeShop\Product\Model\Product;

class ProductQueryProviderTest extends TestCase
{
    public function testSearchProductsBuildsSafeKeywordAndPriceFilters(): void
    {
        $calls = new ArrayObject();
        $product = $this->createFakeProduct($calls, [
            ['product_id' => 8, 'name' => 'Travel Bag'],
        ], 1, '<nav>...</nav>');

        $provider = new ProductQueryProvider($product);
        $result = $provider->execute('searchProducts', [
            'keyword' => 'bag',
            'filters' => [
                'price_min' => 10,
                'price_max' => 99,
                'order_by' => 'price',
                'order_dir' => 'asc',
            ],
            'page' => 2,
            'page_size' => 12,
        ]);

        $this->assertSame(
            ['where', "(name LIKE '%bag%' OR sku LIKE '%bag%' OR short_description LIKE '%bag%' OR description LIKE '%bag%')", null, '=', 'AND', 'AND'],
            $calls[1]
        );
        $this->assertSame(
            ['where', Product::schema_fields_price, 10.0, '>=', 'AND', 'AND'],
            $calls[2]
        );
        $this->assertSame(
            ['where', Product::schema_fields_price, 99.0, '<=', 'AND', 'AND'],
            $calls[3]
        );
        $this->assertSame(
            ['where', Product::schema_fields_status, 1, '=', 'AND', 'AND'],
            $calls[4]
        );
        $this->assertSame(['order', 'price', 'ASC'], $calls[5]);
        $this->assertSame(['pagination', 2, 12], $calls[6]);
        $this->assertSame(1, $result['total']);
        $this->assertSame('Travel Bag', $result['items'][0]['name']);
        $this->assertSame('<nav>...</nav>', $result['pagination']);
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

        $provider = new ProductQueryProvider($product);
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
                $this->calls[] = ['pagination', $page, $pageSize];

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
}
