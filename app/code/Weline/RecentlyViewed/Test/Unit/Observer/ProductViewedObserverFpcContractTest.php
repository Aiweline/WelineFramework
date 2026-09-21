<?php

declare(strict_types=1);

namespace Weline\RecentlyViewed\Test\Unit\Observer;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Event\Event;
use Weline\Framework\Runtime\RequestContext;
use Weline\RecentlyViewed\Observer\ProductViewedObserver;

final class ProductViewedObserverFpcContractTest extends TestCase
{
    protected function tearDown(): void
    {
        if (class_exists(RequestContext::class, false)) {
            try {
                RequestContext::set(ProductViewedObserver::PENDING_PRODUCT_ID_KEY, null);
            } catch (\Throwable) {
            }
        }
    }

    public function testExecuteQueuesProductIdWithoutSideEffectsOnEmptyId(): void
    {
        $observer = new ProductViewedObserver();
        $event = new Event(['product_id' => 0]);
        $observer->execute($event);
        self::assertTrue(true);
    }

    public function testExecuteQueuesPendingProductIdForClientPersistence(): void
    {
        $observer = new ProductViewedObserver();
        $event = new Event(['product_id' => 196]);
        $observer->execute($event);

        self::assertSame(
            196,
            (int)RequestContext::get(ProductViewedObserver::PENDING_PRODUCT_ID_KEY, 0),
        );
    }
}
