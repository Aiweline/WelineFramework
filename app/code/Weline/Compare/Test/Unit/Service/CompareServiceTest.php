<?php

declare(strict_types=1);

namespace Weline\Compare\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Compare\Service\CompareService;
use Weline\Compare\Service\CompareSessionStore;
use Weline\Compare\Service\ProductCardSnapshotResolver;

final class CompareServiceTest extends TestCase
{
    protected function setUp(): void
    {
        if (!\function_exists('__')) {
            require_once dirname(__DIR__) . '/bootstrap.php';
        }
    }

    public function testAddRespectsMaxItems(): void
    {
        $store = new class extends CompareSessionStore {
            /** @var list<int> */
            private array $ids = [];

            public function listIds(): array
            {
                return $this->ids;
            }

            public function saveIds(array $ids): void
            {
                $this->ids = $ids;
            }
        };
        $resolver = new class extends ProductCardSnapshotResolver {
            public function resolve(int $productId): ?array
            {
                return [
                    'product_id' => $productId,
                    'name' => 'Product ' . $productId,
                    'image' => '/img.jpg',
                    'formatted_price' => '¥1.00',
                    'url' => '/product/' . $productId,
                    'sku' => 'SKU',
                    'rating' => 4.5,
                    'review_count' => 1,
                    'attributes' => [],
                ];
            }
        };
        $service = new CompareService($store, $resolver);

        self::assertTrue($service->add(1)['success']);
        self::assertTrue($service->add(2)['success']);
        self::assertTrue($service->add(3)['success']);
        self::assertTrue($service->add(4)['success']);
        self::assertFalse($service->add(5)['success']);
        self::assertSame(4, $service->list()['compare_count']);
    }

    public function testQuickViewUsesFallback(): void
    {
        $store = new CompareSessionStore();
        $resolver = new class extends ProductCardSnapshotResolver {
            public function resolve(int $productId): ?array
            {
                return null;
            }
        };
        $service = new CompareService($store, $resolver);
        $result = $service->quickView(9, [
            'name' => 'Fallback Product',
            'price' => 88,
            'image' => '/demo.jpg',
            'url' => '/product/demo',
        ]);

        self::assertTrue($result['success']);
        self::assertSame('Fallback Product', $result['product']['name']);
        self::assertSame(9, $result['product']['product_id']);
    }
}
