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
    }
}
