<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Product\Service\StorefrontCategoryLinkIndex;

final class StorefrontCategoryLinkIndexTest extends TestCase
{
    public function testFilterByCategoryIds(): void
    {
        $rows = [
            [
                'link_id' => 1,
                'category_id' => 10,
                'product_id' => 100,
                'store_id' => 0,
                'scope_state' => 'explicit',
                'selected' => true,
                'position' => 0,
            ],
            [
                'link_id' => 2,
                'category_id' => 20,
                'product_id' => 200,
                'store_id' => 0,
                'scope_state' => 'explicit',
                'selected' => true,
                'position' => 1,
            ],
        ];

        $filtered = $this->filter($rows, [10], [], [0]);

        self::assertCount(1, $filtered);
        self::assertSame(100, $filtered[0]['product_id']);
    }

    public function testFilterByProductIdsAndStore(): void
    {
        $rows = [
            [
                'link_id' => 1,
                'category_id' => 10,
                'product_id' => 100,
                'store_id' => 0,
                'scope_state' => 'explicit',
                'selected' => true,
                'position' => 0,
            ],
            [
                'link_id' => 2,
                'category_id' => 10,
                'product_id' => 100,
                'store_id' => 5,
                'scope_state' => 'explicit',
                'selected' => true,
                'position' => 0,
            ],
        ];

        $filtered = $this->filter($rows, [], [100], [5]);

        self::assertCount(1, $filtered);
        self::assertSame(5, $filtered[0]['store_id']);
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @param list<int> $categoryIds
     * @param list<int> $productIds
     * @param list<int> $storeIds
     * @return list<array<string, mixed>>
     */
    private function filter(array $rows, array $categoryIds, array $productIds, array $storeIds): array
    {
        $index = (new \ReflectionClass(StorefrontCategoryLinkIndex::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(StorefrontCategoryLinkIndex::class, 'filter');
        $method->setAccessible(true);

        /** @var list<array<string, mixed>> $filtered */
        $filtered = $method->invoke($index, $rows, $categoryIds, $productIds, $storeIds);

        return $filtered;
    }
}
