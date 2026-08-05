<?php

declare(strict_types=1);

namespace Weline\Subscription\Test\Unit\Service;

use PDO;
use PHPUnit\Framework\TestCase;
use Weline\Framework\Database\Connection\Api\ConnectorInterface;
use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Database\DbManager\ConfigProvider;
use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\SchemaParser;
use Weline\Framework\Database\Service\DatabaseTransactionRunner;
use Weline\Framework\Database\Transaction\TransactionCoordinator;
use Weline\Subscription\Model\Subscription;
use Weline\Subscription\Model\SubscriptionBillingAttempt;
use Weline\Subscription\Model\SubscriptionMissedWatermark;
use Weline\Subscription\Model\SubscriptionPeriod;
use Weline\Subscription\Model\SubscriptionSchedulerLease;
use Weline\Subscription\Service\ArraySubscriptionOrderPort;
use Weline\Subscription\Service\ArraySubscriptionPaymentPort;
use Weline\Subscription\Service\SubscriptionBillingAttemptStore;
use Weline\Subscription\Service\SubscriptionCancelCasService;
use Weline\Subscription\Service\SubscriptionMissedWatermarkStore;
use Weline\Subscription\Service\SubscriptionOwnershipService;
use Weline\Subscription\Service\SubscriptionPeriodStore;
use Weline\Subscription\Service\SubscriptionProviderRegistry;
use Weline\Subscription\Service\SubscriptionSchedulerLeaseStore;
use Weline\Subscription\Service\SubscriptionSchedulerService;
use Weline\Subscription\Service\SubscriptionService;
use Weline\Subscription\Service\SubscriptionStore;
use Weline\Subscription\Service\SubscriptionStoreEligibilityService;
use Weline\SystemConfig\Api\CommerceRolloutGateInterface;
use Weline\Subscription\Service\SubscriptionRolloutGate;

/** TEST-P4B-01/04: fresh-instance lease/Attempt persistence and unknown query-only recovery. */
final class SubscriptionSchedulerPersistenceIntegrationTest extends TestCase
{
    public function testFreshSchedulersShareLeaseAndUnknownAttemptWithoutReplacement(): void
    {
        self::assertContains('sqlite', PDO::getAvailableDrivers());
        foreach ([
            SubscriptionSchedulerLease::class,
            SubscriptionBillingAttempt::class,
            SubscriptionMissedWatermark::class,
        ] as $modelClass) {
            self::assertNotNull((new SchemaParser())->parse($modelClass));
        }

        $dbPath = sys_get_temp_dir()
            . DIRECTORY_SEPARATOR
            . 'weline_p4b002_scheduler_'
            . bin2hex(random_bytes(8))
            . '.sqlite';
        $connectionFactory = ConnectionFactory::getInstance(new ConfigProvider([
            'type' => 'sqlite',
            'database' => '',
            'path' => $dbPath,
            'persistent' => false,
        ]));
        $connector = $connectionFactory->getConnector();
        $this->createTables($connector);
        $transactions = new DatabaseTransactionRunner(new TransactionCoordinator());
        $orders = ArraySubscriptionOrderPort::forTesting();
        $payments = ArraySubscriptionPaymentPort::forTesting();
        $eligibility = SubscriptionStoreEligibilityService::forTesting();

        try {
            $schedulerA = $this->runtime(
                $connectionFactory,
                $transactions,
                $orders,
                $payments,
                $eligibility,
            );
            $schedulerB = $this->runtime(
                $connectionFactory,
                $transactions,
                $orders,
                $payments,
                $eligibility,
            );
            $created = $schedulerA->subscriptions()->create([
                'customer_id' => 'cust-persistent-scheduler',
                'website_id' => 0,
                'store_id' => 0,
                'provider_code' => 'interval_monthly',
                'plan_code' => 'plan-persistent-scheduler',
                'idempotency_key' => 'idem-persistent-scheduler',
            ]);
            $subscriptionId = (string) $created['subscription_id'];

            $leaseA = $schedulerA->leases()->acquire($subscriptionId, 'worker-a', 60, 1000);
            self::assertTrue($leaseA['ok']);
            $leaseB = $schedulerB->leases()->acquire($subscriptionId, 'worker-b', 60, 1000);
            self::assertFalse($leaseB['ok']);
            self::assertSame(SubscriptionSchedulerLeaseStore::ERROR_HELD, $leaseB['error']);
            self::assertTrue($schedulerA->leases()->release(
                $subscriptionId,
                'worker-a',
                (string) $leaseA['token'],
            ));

            $payments->setNextResult([
                'status' => 'unknown',
                'terminal' => false,
                'error_code' => 'provider_result_unknown',
            ]);
            $first = $schedulerA->tick($subscriptionId, 'worker-a');
            self::assertSame('unknown', $first['attempt_status']);
            self::assertSame(1, $orders->orderCount());
            self::assertSame(1, $payments->startCallCount());

            $replayed = $schedulerB->tick($subscriptionId, 'worker-b');
            self::assertSame('unknown', $replayed['attempt_status']);
            self::assertTrue($replayed['replayed']);
            self::assertSame(1, $orders->orderCount());
            self::assertSame(1, $payments->startCallCount());
            self::assertSame(1, $payments->queryCallCount());
            self::assertSame(1, $schedulerB->attempts()->count());
            self::assertSame(
                (string) $first['payment_intent_code'],
                (string) $replayed['payment_intent_code'],
            );

            $payments->setOrderResult((string) $first['order_ref'], [
                'status' => 'succeeded',
                'terminal' => true,
                'intent_code' => $first['payment_intent_code'],
            ]);
            $settled = $schedulerB->tick($subscriptionId, 'worker-b');
            self::assertSame('succeeded', $settled['attempt_status']);
            self::assertSame(
                2,
                $schedulerA->subscriptions()->get($subscriptionId)['current_period_index'],
            );
            self::assertSame(1, $orders->orderCount());
            self::assertSame(1, $schedulerA->attempts()->count());
        } finally {
            $connector->close();
            $connectionFactory->close();
            if (is_file($dbPath)) {
                unlink($dbPath);
            }
        }
        self::assertFileDoesNotExist($dbPath);
    }

    private function runtime(
        ConnectionFactory $connectionFactory,
        DatabaseTransactionRunner $transactions,
        ArraySubscriptionOrderPort $orders,
        ArraySubscriptionPaymentPort $payments,
        SubscriptionStoreEligibilityService $eligibility,
    ): SubscriptionSchedulerService {
        $store = new SubscriptionStore($this->factory($connectionFactory, Subscription::class));
        $periods = new SubscriptionPeriodStore($this->factory($connectionFactory, SubscriptionPeriod::class));
        $ownership = new SubscriptionOwnershipService($store);
        $gate = SubscriptionRolloutGate::forTestingConfiguration();
        $gate->setMode(
            SubscriptionService::CAPABILITY,
            CommerceRolloutGateInterface::MODE_ALLOWLIST,
            ['website:0'],
        );
        $subscriptions = new SubscriptionService(
            new SubscriptionProviderRegistry(),
            $store,
            $periods,
            $ownership,
            new SubscriptionCancelCasService($store, $ownership),
            $gate,
            $transactions,
        );

        return new SubscriptionSchedulerService(
            $subscriptions,
            new SubscriptionSchedulerLeaseStore(
                $this->factory($connectionFactory, SubscriptionSchedulerLease::class),
            ),
            new SubscriptionBillingAttemptStore(
                $this->factory($connectionFactory, SubscriptionBillingAttempt::class),
            ),
            new SubscriptionMissedWatermarkStore(
                $this->factory($connectionFactory, SubscriptionMissedWatermark::class),
            ),
            $orders,
            $payments,
            $eligibility,
            $gate,
        );
    }

    /**
     * @template T of Model
     * @param class-string<T> $modelClass
     * @return \Closure(): T
     */
    private function factory(ConnectionFactory $connectionFactory, string $modelClass): \Closure
    {
        return static function () use ($connectionFactory, $modelClass): Model {
            $model = new $modelClass();
            $model->setConnection($connectionFactory);
            $model->__init();
            return $model;
        };
    }

    private function createTables(ConnectorInterface $connector): void
    {
        $queries = [
            'CREATE TABLE weline_subscription ('
                . 'subscription_row_id INTEGER PRIMARY KEY AUTOINCREMENT, '
                . 'subscription_id VARCHAR(64) NOT NULL UNIQUE, customer_id VARCHAR(64) NOT NULL, '
                . 'website_id INTEGER NOT NULL, store_id INTEGER NOT NULL DEFAULT 0, '
                . 'provider_code VARCHAR(64) NOT NULL, plan_code VARCHAR(128) NOT NULL, '
                . 'environment VARCHAR(16) NOT NULL, status VARCHAR(16) NOT NULL, '
                . 'version INTEGER NOT NULL, cas_token VARCHAR(64) NOT NULL, '
                . 'current_period_index INTEGER NOT NULL, idempotency_key VARCHAR(128) NOT NULL UNIQUE, '
                . 'request_hash VARCHAR(64) NOT NULL, created_at DATETIME NOT NULL, '
                . 'updated_at DATETIME NOT NULL, cancelled_at DATETIME NULL, '
                . 'UNIQUE(customer_id, website_id, plan_code))',
            'CREATE TABLE weline_subscription_period ('
                . 'period_row_id INTEGER PRIMARY KEY AUTOINCREMENT, period_key VARCHAR(160) NOT NULL UNIQUE, '
                . 'subscription_id VARCHAR(64) NOT NULL, period_index INTEGER NOT NULL, '
                . 'website_id INTEGER NOT NULL, status VARCHAR(16) NOT NULL, '
                . 'period_version INTEGER NOT NULL, cas_token VARCHAR(64) NOT NULL, '
                . 'order_ref VARCHAR(64) NULL, missed_reason VARCHAR(255) NULL, '
                . 'opened_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, '
                . 'UNIQUE(subscription_id, period_index))',
            'CREATE TABLE weline_subscription_scheduler_lease ('
                . 'lease_row_id INTEGER PRIMARY KEY AUTOINCREMENT, '
                . 'subscription_id VARCHAR(64) NOT NULL UNIQUE, worker_id VARCHAR(128) NOT NULL, '
                . 'lease_token VARCHAR(64) NOT NULL, lease_version INTEGER NOT NULL, '
                . 'expires_at_epoch INTEGER NOT NULL, updated_at DATETIME NOT NULL)',
            'CREATE TABLE weline_subscription_billing_attempt ('
                . 'attempt_row_id INTEGER PRIMARY KEY AUTOINCREMENT, attempt_id VARCHAR(64) NOT NULL UNIQUE, '
                . 'period_key VARCHAR(160) NOT NULL, subscription_id VARCHAR(64) NOT NULL, '
                . 'attempt_no INTEGER NOT NULL, worker_id VARCHAR(128) NOT NULL, '
                . 'status VARCHAR(16) NOT NULL, active_guard VARCHAR(16) NULL, '
                . 'order_ref VARCHAR(64) NULL, payment_intent_code VARCHAR(64) NULL, '
                . 'payment_attempt_code VARCHAR(64) NULL, payment_status VARCHAR(32) NULL, '
                . 'error_code VARCHAR(128) NULL, attempt_version INTEGER NOT NULL, '
                . 'cas_token VARCHAR(64) NOT NULL, started_at DATETIME NOT NULL, '
                . 'updated_at DATETIME NOT NULL, finished_at DATETIME NULL, '
                . 'UNIQUE(period_key, attempt_no), UNIQUE(period_key, active_guard))',
            'CREATE TABLE weline_subscription_missed_watermark ('
                . 'watermark_row_id INTEGER PRIMARY KEY AUTOINCREMENT, '
                . 'subscription_id VARCHAR(64) NOT NULL UNIQUE, period_index INTEGER NOT NULL, '
                . 'period_key VARCHAR(160) NOT NULL, reason VARCHAR(255) NOT NULL, '
                . 'watermark_version INTEGER NOT NULL, cas_token VARCHAR(64) NOT NULL, '
                . 'updated_at DATETIME NOT NULL)',
        ];
        foreach ($queries as $query) {
            $connector->query($query)->fetch();
        }
    }
}
