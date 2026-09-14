<?php

declare(strict_types=1);

namespace Weline\RecentlyViewed\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Weline\RecentlyViewed\Service\RecentlyViewedSessionStore;
use Weline\RecentlyViewed\Service\RecentlyViewedService;

final class RecentlyViewedServiceTest extends TestCase
{
    public function testRecordKeepsMruOrderAndDedupes(): void
    {
        $store = new class extends RecentlyViewedSessionStore {
            /** @var list<int> */
            private array $ids = [];

            public function listIds(): array
            {
                return $this->ids;
            }

            public function saveIds(array $ids): void
            {
                $this->ids = array_values($ids);
            }

            public function record(int $productId): void
            {
                $productId = max(0, $productId);
                if ($productId <= 0) {
                    return;
                }
                $ids = array_values(array_filter(
                    $this->listIds(),
                    static fn(int $id): bool => $id !== $productId,
                ));
                array_unshift($ids, $productId);
                $this->saveIds($ids);
            }
        };

        $service = new RecentlyViewedService($store);
        $service->record(10);
        $service->record(20);
        $service->record(10);

        self::assertSame([10, 20], $service->listIds(6));
        self::assertSame([20], $service->listIds(6, 10));
    }

    public function testMapOfferPrefersSlugOverNumericProductId(): void
    {
        $service = new RecentlyViewedService(new RecentlyViewedSessionStore());
        $map = new ReflectionMethod(RecentlyViewedService::class, 'mapOffer');
        $map->setAccessible(true);

        $withSlug = $map->invoke($service, [
            'product_id' => 113,
            'name' => 'Demo',
            'slug' => 'hanfu-demo-slug',
            'unit_price_minor' => 12800,
        ]);
        self::assertSame('hanfu-demo-slug', $withSlug['slug'] ?? null);
        self::assertSame('product/hanfu-demo-slug', $withSlug['url'] ?? null);
        self::assertStringNotContainsString('product/113', (string)($withSlug['url'] ?? ''));

        $fromSourceSlug = $map->invoke($service, [
            'product_id' => 117,
            'name' => 'Source',
            'source_slug' => 'from-source-slug',
            'unit_price_minor' => 9900,
        ]);
        self::assertSame('from-source-slug', $fromSourceSlug['slug'] ?? null);
        self::assertSame('product/from-source-slug', $fromSourceSlug['url'] ?? null);

        $fallback = $map->invoke($service, [
            'product_id' => 99,
            'name' => 'No slug',
            'unit_price_minor' => 100,
        ]);
        self::assertSame('', $fallback['slug'] ?? null);
        self::assertSame('product/99', $fallback['url'] ?? null);
    }
}
