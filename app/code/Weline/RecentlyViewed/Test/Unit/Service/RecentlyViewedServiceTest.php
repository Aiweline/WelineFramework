<?php

declare(strict_types=1);

namespace Weline\RecentlyViewed\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
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
        self::assertCount(2, $service->listIds(24), 'listIds clamps to 6');
    }

    public function testCardsUsesSingleQueryProviderBatchWithoutLiveNPlusOne(): void
    {
        $store = new class extends RecentlyViewedSessionStore {
            public function listIds(): array
            {
                return [11, 22, 33, 44, 55, 66, 77];
            }
        };

        $service = new class ($store) extends RecentlyViewedService {
            public int $calls = 0;

            /** @var list<int> */
            public array $lastIds = [];

            public int $lastLimit = 0;

            protected function queryStorefrontCards(array $productIds, int $limit): mixed
            {
                $this->calls++;
                $this->lastIds = $productIds;
                $this->lastLimit = $limit;

                return [
                    [
                        'id' => 11,
                        'product_id' => 11,
                        'name' => 'A',
                        'slug' => 'a-slug',
                        'currency' => 'USD',
                        'price' => 10.0,
                    ],
                    [
                        'id' => 22,
                        'product_id' => 22,
                        'name' => 'B',
                        'slug' => 'b-slug',
                        'currency' => 'USD',
                        'price' => 20.0,
                    ],
                ];
            }
        };

        $cards = $service->cards(6);
        self::assertSame(1, $service->calls);
        self::assertSame(6, $service->lastLimit);
        self::assertSame([11, 22, 33, 44, 55, 66], $service->lastIds);
        self::assertCount(2, $cards);
        self::assertSame(11, (int)($cards[0]['id'] ?? 0));
        self::assertSame('USD', $cards[0]['currency'] ?? null);

        $path = dirname(__DIR__, 3) . '/Service/RecentlyViewedService.php';
        $source = (string)file_get_contents($path);
        self::assertStringContainsString('cardsByProductIds', $source);
        self::assertStringNotContainsString('->livePublishedOffersForProduct(', $source);
        self::assertStringNotContainsString('foreach ($ids as $lookupId)', $source);
    }

    public function testServiceSourceRoutesThroughProductStorefrontQuery(): void
    {
        $path = dirname(__DIR__, 3) . '/Service/RecentlyViewedService.php';
        $source = (string)file_get_contents($path);
        self::assertStringContainsString("w_query('product_storefront', 'cardsByProductIds'", $source);
        self::assertStringContainsString('min(6, $limit)', $source);
    }
}
