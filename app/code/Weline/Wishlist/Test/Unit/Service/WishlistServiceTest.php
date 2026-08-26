<?php

declare(strict_types=1);

namespace Weline\Wishlist\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Wishlist\Service\WishlistService;
use Weline\Wishlist\Service\WishlistSessionStore;

final class WishlistServiceTest extends TestCase
{
    protected function setUp(): void
    {
        if (!\function_exists('__')) {
            require_once dirname(__DIR__) . '/bootstrap.php';
        }
    }

    public function testAddToggleAndRemove(): void
    {
        $store = new class extends WishlistSessionStore {
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
        $snapshots = new class extends \Weline\Wishlist\Service\ProductCardSnapshotResolver {
            public function resolve(int $productId): ?array
            {
                return $productId > 0 ? ['product_id' => $productId, 'name' => 'Demo'] : null;
            }
        };
        $service = new WishlistService($store, $snapshots);

        $added = $service->add(12);
        self::assertTrue($added['success']);
        self::assertSame(1, $added['wishlist_count']);

        $toggled = $service->toggle(12);
        self::assertFalse($toggled['active']);
        self::assertSame(0, $toggled['wishlist_count']);

        $service->add(8);
        $removed = $service->remove(8);
        self::assertTrue($removed['success']);
        self::assertSame(0, $removed['wishlist_count']);

        $page = $service->listPage();
        self::assertTrue($page['success']);
        self::assertSame(0, $page['wishlist_count']);
        self::assertSame([], $page['items']);
    }
}
