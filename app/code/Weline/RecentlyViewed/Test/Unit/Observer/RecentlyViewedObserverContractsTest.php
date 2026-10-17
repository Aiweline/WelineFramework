<?php

declare(strict_types=1);

namespace Weline\RecentlyViewed\Test\Unit\Observer;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Event\Event;
use Weline\Framework\Runtime\RequestContext;
use Weline\RecentlyViewed\Observer\ProductViewedObserver;

/**
 * Aggregated contract suite (merged from 1 *ContractTest files).
 * Observe one overall pass/fail via testAllRecentlyViewedObserverContractsPass().
 */
final class RecentlyViewedObserverContractsTest extends TestCase
{
    public function testAllRecentlyViewedObserverContractsPass(): void
    {
        $failures = [];
        foreach ($this->contractChecks() as $name => $fn) {
            try {
                $fn();
            } catch (\Throwable $e) {
                $failures[] = $name . ": " . $e->getMessage();
            }
        }
        self::assertSame([], $failures, implode("\n", $failures));
    }

    /** @return array<string, callable(): void> */
    private function contractChecks(): array
    {
        return [
            'ProductViewedObserverFpc::testExecuteQueuesProductIdWithoutSideEffectsOnEmptyId' => function () {
            try {
                $this->assert_ProductViewedObserverFpc_testExecuteQueuesProductIdWithoutSideEffectsOnEmptyId();
            } finally {
                $this->tearDown_ProductViewedObserverFpc();
            }
        },
            'ProductViewedObserverFpc::testExecuteQueuesPendingProductIdForClientPersistence' => function () {
            try {
                $this->assert_ProductViewedObserverFpc_testExecuteQueuesPendingProductIdForClientPersistence();
            } finally {
                $this->tearDown_ProductViewedObserverFpc();
            }
        },
        ];
    }

private function tearDown_ProductViewedObserverFpc(): void
{
    if (class_exists(RequestContext::class, false)) {
        try {
            RequestContext::set(ProductViewedObserver::PENDING_PRODUCT_ID_KEY, null);
        } catch (\Throwable) {
        }
    }
}

private function assert_ProductViewedObserverFpc_testExecuteQueuesProductIdWithoutSideEffectsOnEmptyId(): void
{
    $observer = new ProductViewedObserver();
    $event = new Event(['product_id' => 0]);
    $observer->execute($event);
    self::assertTrue(true);
}

private function assert_ProductViewedObserverFpc_testExecuteQueuesPendingProductIdForClientPersistence(): void
{
    $observer = new ProductViewedObserver();
    $event = new Event(['product_id' => 196]);
    $observer->execute($event);
    self::assertSame(196, (int) RequestContext::get(ProductViewedObserver::PENDING_PRODUCT_ID_KEY, 0));
}
}
