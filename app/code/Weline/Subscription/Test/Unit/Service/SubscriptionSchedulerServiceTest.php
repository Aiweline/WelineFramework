<?php

declare(strict_types=1);

namespace Weline\Subscription\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Subscription\Model\SubscriptionState;
use Weline\Subscription\Service\ArraySubscriptionOrderPort;
use Weline\Subscription\Service\ArraySubscriptionPaymentPort;
use Weline\Subscription\Service\SubscriptionConflictException;
use Weline\Subscription\Service\SubscriptionSchedulerService;
use Weline\Subscription\Service\SubscriptionService;
use Weline\Subscription\Service\SubscriptionStoreEligibilityService;
use Weline\SystemConfig\Api\CommerceRolloutGateInterface;

/**
 * TASK-P4B-002 / TEST-P4B-02, TEST-P4B-03, TEST-P4B-04 and TEST-P4B-05：
 * dual worker lease、周期漏跑恢复、unknown 查询恢复、tombstone 旧义务与 mode off.
 */
final class SubscriptionSchedulerServiceTest extends TestCase
{
    private SubscriptionSchedulerService $scheduler;
    private ArraySubscriptionOrderPort $orders;
    private ArraySubscriptionPaymentPort $payments;
    private SubscriptionStoreEligibilityService $storeEligibility;

    protected function setUp(): void
    {
        $subs = SubscriptionService::forTesting();
        $subs->rollout()->setMode(
            SubscriptionService::CAPABILITY,
            CommerceRolloutGateInterface::MODE_ALLOWLIST,
            ['website:0'],
        );
        $this->orders = ArraySubscriptionOrderPort::forTesting();
        $this->payments = ArraySubscriptionPaymentPort::forTesting();
        $this->storeEligibility = SubscriptionStoreEligibilityService::forTesting();
        $this->scheduler = SubscriptionSchedulerService::forTesting(
            $subs,
            $this->orders,
            $subs->rollout(),
            $this->payments,
            $this->storeEligibility,
        );
    }

    private function createActive(): array
    {
        $suffix = str_replace('.', '', uniqid('', true));
        return $this->scheduler->subscriptions()->create([
            'customer_id' => 'cust-sched',
            'website_id' => 0,
            'provider_code' => 'interval_monthly',
            'plan_code' => 'plan_sched_' . $suffix,
            'idempotency_key' => 'idem-sched-' . $suffix,
        ]);
    }

    public function testDualWorkerOnlyOneHoldsLease(): void
    {
        $sub = $this->createActive();
        $id = (string) $sub['subscription_id'];

        $leaseA = $this->scheduler->leases()->acquire($id, 'worker-a', 60);
        self::assertTrue($leaseA['ok']);
        $leaseB = $this->scheduler->leases()->acquire($id, 'worker-b', 60);
        self::assertFalse($leaseB['ok']);
        self::assertSame('subscription_scheduler_lease_held', $leaseB['error']);

        $this->scheduler->leases()->release($id, 'worker-a', (string) $leaseA['token']);
        $tick = $this->scheduler->tick($id, 'worker-b');
        self::assertTrue($tick['ok']);
        self::assertSame(1, $this->orders->orderCount());
    }

    public function testEachPeriodCreatesExactlyOneNewOrder(): void
    {
        $sub = $this->createActive();
        $id = (string) $sub['subscription_id'];

        $t1 = $this->scheduler->tick($id, 'worker-1');
        $t1b = $this->scheduler->tick($id, 'worker-1'); // bills next period
        self::assertTrue($t1['ok']);
        self::assertTrue($t1b['ok']);
        self::assertNotSame($t1['order_ref'], $t1b['order_ref']);
        self::assertSame(2, $this->orders->orderCount());

        // Re-tick same due period after both billed: opens/bills period 3
        $t3 = $this->scheduler->tick($id, 'worker-1');
        self::assertTrue($t3['ok']);
        self::assertSame(3, $this->orders->orderCount());
        self::assertCount(3, array_unique($this->orders->orderRefs()));
    }

    public function testFailedTickMarksMissedAndRecoverCreatesSingleOrder(): void
    {
        $sub = $this->createActive();
        $id = (string) $sub['subscription_id'];
        $this->orders->failNext(true);

        try {
            $this->scheduler->tick($id, 'worker-fail');
            self::fail('expected failure');
        } catch (SubscriptionConflictException $e) {
            self::assertSame('subscription_order_port_failed', $e->errorCode);
        }

        self::assertSame(0, $this->orders->orderCount());
        self::assertSame(1, $this->scheduler->missed()->watermark($id));
        $periods = $this->scheduler->subscriptions()->periods()->listForSubscription($id);
        self::assertSame(SubscriptionState::PERIOD_MISSED, $periods[0]['status']);

        $recovered = $this->scheduler->recover($id, 'worker-recover', 1);
        self::assertTrue($recovered['ok']);
        self::assertTrue($recovered['recovered']);
        self::assertSame(1, $this->orders->orderCount());
        self::assertSame(SubscriptionState::PERIOD_BILLED, $recovered['period']['status']);
    }

    public function testModeOffBlocksNewTickButAllowsRecover(): void
    {
        $sub = $this->createActive();
        $id = (string) $sub['subscription_id'];
        $this->orders->failNext(true);
        try {
            $this->scheduler->tick($id, 'worker-x');
        } catch (SubscriptionConflictException) {
            // expected
        }

        $this->scheduler->rollout()->setMode(
            SubscriptionService::CAPABILITY,
            CommerceRolloutGateInterface::MODE_OFF,
        );

        try {
            $this->scheduler->tick($id, 'worker-y');
            self::fail('expected mode off');
        } catch (SubscriptionConflictException $e) {
            self::assertSame(SubscriptionSchedulerService::ERROR_MODE_OFF, $e->errorCode);
        }

        $recovered = $this->scheduler->recover($id, 'worker-z', 1);
        self::assertTrue($recovered['ok']);
        self::assertSame(1, $this->orders->orderCount());
    }

    public function testCancelledSubscriptionCannotTick(): void
    {
        $sub = $this->createActive();
        $id = (string) $sub['subscription_id'];
        $this->scheduler->subscriptions()->cancel($id, 'cust-sched', (int) $sub['version']);

        $this->expectException(SubscriptionConflictException::class);
        $this->scheduler->tick($id, 'worker-c');
    }

    public function testCancelAndSchedulerUseSameVersionFence(): void
    {
        $cancelFirst = $this->createActive();
        $cancelFirstId = (string) $cancelFirst['subscription_id'];
        $this->scheduler->subscriptions()->cancel(
            $cancelFirstId,
            'cust-sched',
            (int) $cancelFirst['version'],
        );
        $ordersBefore = $this->orders->orderCount();
        try {
            $this->scheduler->tick($cancelFirstId, 'worker-cancel-first');
            self::fail('cancel winner must block scheduler');
        } catch (SubscriptionConflictException $conflict) {
            self::assertSame(SubscriptionSchedulerService::ERROR_CANCELLED, $conflict->errorCode);
        }
        self::assertSame($ordersBefore, $this->orders->orderCount());

        $schedulerFirst = $this->createActive();
        $schedulerFirstId = (string) $schedulerFirst['subscription_id'];
        $this->scheduler->tick($schedulerFirstId, 'worker-scheduler-first');
        try {
            $this->scheduler->subscriptions()->cancel(
                $schedulerFirstId,
                'cust-sched',
                (int) $schedulerFirst['version'],
            );
            self::fail('stale cancel must lose after scheduler fence');
        } catch (SubscriptionConflictException $conflict) {
            self::assertSame('subscription_version_conflict', $conflict->errorCode);
        }
        $current = $this->scheduler->subscriptions()->get($schedulerFirstId);
        $this->scheduler->subscriptions()->cancel(
            $schedulerFirstId,
            'cust-sched',
            (int) $current['version'],
        );
        $ordersAfterCancel = $this->orders->orderCount();
        try {
            $this->scheduler->tick($schedulerFirstId, 'worker-after-cancel');
            self::fail('cancelled subscription must not open another obligation');
        } catch (SubscriptionConflictException $conflict) {
            self::assertSame(SubscriptionSchedulerService::ERROR_CANCELLED, $conflict->errorCode);
        }
        self::assertSame($ordersAfterCancel, $this->orders->orderCount());
    }

    public function testUnknownPaymentOccupiesAttemptAndReentryOnlyQueries(): void
    {
        $sub = $this->createActive();
        $id = (string) $sub['subscription_id'];
        $this->payments->setNextResult([
            'status' => 'unknown',
            'terminal' => false,
            'error_code' => 'provider_result_unknown',
        ]);

        $first = $this->scheduler->tick($id, 'worker-unknown-a');
        self::assertSame('unknown', $first['attempt_status']);
        self::assertSame(1, $this->orders->orderCount());
        self::assertSame(1, $this->payments->startCallCount());
        self::assertSame(1, $this->scheduler->attempts()->count());

        $second = $this->scheduler->tick($id, 'worker-unknown-b');
        self::assertSame('unknown', $second['attempt_status']);
        self::assertTrue($second['replayed']);
        self::assertSame(1, $this->orders->orderCount());
        self::assertSame(1, $this->payments->startCallCount());
        self::assertSame(1, $this->payments->queryCallCount());
        self::assertSame(1, $this->scheduler->attempts()->count());

        $this->payments->setOrderResult((string) $first['order_ref'], [
            'status' => 'succeeded',
            'terminal' => true,
            'intent_code' => $first['payment_intent_code'],
        ]);
        $settled = $this->scheduler->tick($id, 'worker-unknown-c');
        self::assertSame('succeeded', $settled['attempt_status']);
        self::assertSame(2, $this->scheduler->subscriptions()->get($id)['current_period_index']);
        self::assertSame(1, $this->payments->startCallCount());
    }

    public function testTwoMissedPeriodsRecoverIndependently(): void
    {
        $sub = $this->createActive();
        $id = (string) $sub['subscription_id'];
        $this->orders->failNext();
        try {
            $this->scheduler->tick($id, 'worker-miss-1');
            self::fail('period 1 must fail');
        } catch (SubscriptionConflictException) {
        }
        self::assertSame(1, $this->scheduler->missed()->watermark($id));
        $period1 = $this->scheduler->recover($id, 'worker-recover-1', 1);

        $this->orders->failNext();
        try {
            $this->scheduler->tick($id, 'worker-miss-2');
            self::fail('period 2 must fail');
        } catch (SubscriptionConflictException) {
        }
        self::assertSame(2, $this->scheduler->missed()->watermark($id));
        $period2 = $this->scheduler->recover($id, 'worker-recover-2', 2);

        self::assertNotSame($period1['order_ref'], $period2['order_ref']);
        self::assertSame(2, $this->orders->orderCount());
        self::assertSame(4, $this->scheduler->attempts()->count());
    }

    public function testTombstoneStoreBlocksNewRenewalButKeepsExistingPeriod(): void
    {
        $this->storeEligibility->setStoreState(9, 0, true, 'active');
        $sub = $this->scheduler->subscriptions()->create([
            'customer_id' => 'cust-store',
            'website_id' => 0,
            'store_id' => 9,
            'provider_code' => 'interval_monthly',
            'plan_code' => 'plan_store',
            'idempotency_key' => 'idem-store-' . uniqid('', true),
        ]);
        $id = (string) $sub['subscription_id'];
        $first = $this->scheduler->tick($id, 'worker-store-active');
        self::assertSame(SubscriptionState::PERIOD_BILLED, $first['period']['status']);

        $this->storeEligibility->setStoreState(9, 0, false, 'tombstone', gmdate('Y-m-d H:i:s'));
        $ordersBefore = $this->orders->orderCount();
        $attemptsBefore = $this->scheduler->attempts()->count();
        try {
            $this->scheduler->tick($id, 'worker-store-tombstone');
            self::fail('tombstone store must block the next renewal');
        } catch (SubscriptionConflictException $conflict) {
            self::assertSame('subscription_store_not_renewable', $conflict->errorCode);
        }
        self::assertSame($ordersBefore, $this->orders->orderCount());
        self::assertSame($attemptsBefore, $this->scheduler->attempts()->count());
        self::assertSame(
            (string) $first['order_ref'],
            (string) $this->scheduler->subscriptions()->periods()
                ->getByKey((string) $first['period']['period_key'])['order_ref'],
        );
    }
}
