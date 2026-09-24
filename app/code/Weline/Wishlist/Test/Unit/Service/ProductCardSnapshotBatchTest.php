<?php

declare(strict_types=1);

namespace Weline\Wishlist\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Wishlist\Service\ProductCardSnapshotResolver;
use Weline\Wishlist\Service\WishlistService;
use Weline\Wishlist\Service\WishlistSessionStore;

final class ProductCardSnapshotBatchTest extends TestCase
{
    public function testListCountAndSingleShareBatchNormalizationAndKeepUnavailablePublishedProducts(): void
    {
        $resolver = new class extends ProductCardSnapshotResolver {
            public array $calls = [];
            protected function queryLiveOffers(array $ids): array {
                $this->calls[] = $ids;
                $out = [];
                foreach ($ids as $id) {
                    if ($id === 9) { continue; }
                    $out[$id] = ['product_id' => $id, 'name' => 'P' . $id, 'unit_price_minor' => 1234,
                        'currency' => 'USD', 'sellable' => false, 'slug' => 'p-' . $id];
                }
                return $out;
            }
        };
        self::assertTrue(method_exists($resolver, 'resolveMany'));
        self::assertSame([], $resolver->resolveMany([]));
        self::assertSame([], $resolver->calls);
        $store = new class extends WishlistSessionStore {
            public array $ids = [8, 9, 7];
            public function listIds(): array { return $this->ids; }
            public function saveIds(array $ids): void { $this->ids = $ids; }
        };
        $service = new WishlistService($store, $resolver);
        $page = $service->listPage();
        self::assertSame([[8, 9, 7]], $resolver->calls);
        self::assertSame([8, 7], array_column($page['items'], 'product_id'));
        self::assertSame([8, 7], $store->ids);
        self::assertFalse($page['items'][0]['sellable']);
        self::assertSame('USD 12.34', $page['items'][0]['formatted_price']);
        self::assertSame($page['items'][1], $resolver->resolve(7));
        $resolver->calls = [];
        $store->ids = range(100, 199);
        self::assertSame(100, $service->count()['wishlist_count']);
        self::assertSame([range(100, 199)], $resolver->calls);
        self::assertNull($resolver->resolve(0));
    }
}
