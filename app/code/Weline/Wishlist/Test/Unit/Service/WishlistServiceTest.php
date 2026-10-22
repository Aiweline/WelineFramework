<?php

declare(strict_types=1);

namespace Weline\Wishlist\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Event\EventsManager;
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
            public function resolveMany(array $ids): array
            {
                $out = [];
                foreach ($ids as $id) {
                    if (($row = $this->resolve($id)) !== null) { $out[$id] = $row; }
                }
                return $out;
            }
            public function resolve(int $productId): ?array
            {
                return $productId > 0 ? ['product_id' => $productId, 'name' => 'Demo'] : null;
            }
        };
        $events = $this->events();
        $service = new WishlistService($store, $snapshots, $events);

        $added = $service->add(12);
        self::assertTrue($added['success']);
        self::assertSame(1, $added['wishlist_count']);
        self::assertSame(['Weline_Wishlist::add_to_wishlist_after'], $events->names);
        self::assertSame(12, $events->payloads[0]['product_id']);
        self::assertTrue($events->payloads[0]['created']);

        $again = $service->add(12);
        self::assertTrue($again['success']);
        self::assertCount(1, $events->names);

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
    public function testPrunesInvalidProductsOnReadPaths(): void
    {
        $store = new class extends WishlistSessionStore {
            /** @var list<int> */
            public array $ids = [10, 20, 30];

            public function listIds(): array
            {
                return $this->ids;
            }

            public function saveIds(array $ids): void
            {
                $this->ids = array_values($ids);
            }
        };
        $snapshots = new class extends \Weline\Wishlist\Service\ProductCardSnapshotResolver {
            public function resolveMany(array $ids): array
            {
                $out = [];
                foreach ($ids as $id) {
                    if (($row = $this->resolve($id)) !== null) { $out[$id] = $row; }
                }
                return $out;
            }
            public function resolve(int $productId): ?array
            {
                return $productId === 20
                    ? ['product_id' => $productId, 'name' => 'Keep']
                    : null;
            }
        };
        $service = new WishlistService($store, $snapshots, $this->events());

        $page = $service->listPage();
        self::assertTrue($page['success']);
        self::assertSame(1, $page['wishlist_count']);
        self::assertCount(1, $page['items']);
        self::assertSame([20], $store->ids);

        $store->ids = [10, 20, 30];
        $count = $service->count();
        self::assertSame(1, $count['wishlist_count']);
        self::assertSame([20], $store->ids);

        $store->ids = [10, 30];
        $listed = $service->list();
        self::assertSame(0, $listed['wishlist_count']);
        self::assertSame([], $listed['items']);
        self::assertSame([], $store->ids);

        $rejected = $service->add(99);
        self::assertFalse($rejected['success']);
        self::assertSame([], $store->ids);
    }

    private function events(): EventsManager
    {
        return new class extends EventsManager {
            /** @var list<string> */
            public array $names = [];

            /** @var list<array<string, mixed>> */
            public array $payloads = [];

            public function __construct()
            {
            }

            public function dispatch(string $eventName, mixed &$data = []): static
            {
                $this->names[] = $eventName;
                $this->payloads[] = is_array($data) ? $data : [];

                return $this;
            }
        };
    }
}
