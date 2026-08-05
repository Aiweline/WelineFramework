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
use Weline\Subscription\Model\SubscriptionPeriod;
use Weline\Subscription\Model\SubscriptionState;
use Weline\Subscription\Service\SubscriptionCancelCasService;
use Weline\Subscription\Service\SubscriptionConflictException;
use Weline\Subscription\Service\SubscriptionOwnershipService;
use Weline\Subscription\Service\SubscriptionPeriodStore;
use Weline\Subscription\Service\SubscriptionProviderRegistry;
use Weline\Subscription\Service\SubscriptionService;
use Weline\Subscription\Service\SubscriptionStore;
use Weline\SystemConfig\Api\CommerceRolloutGateInterface;
use Weline\Subscription\Service\SubscriptionRolloutGate;

/** TASK-P4B-001: durable Subscription/Period identity, transaction and cross-instance CAS. */
final class SubscriptionDurableModelCasIntegrationTest extends TestCase
{
    public function testRowsSurviveFreshInstancesAndStaleCancelLoses(): void
    {
        self::assertContains('sqlite', PDO::getAvailableDrivers());
        self::assertNotNull((new SchemaParser())->parse(Subscription::class));
        self::assertNotNull((new SchemaParser())->parse(SubscriptionPeriod::class));

        $dbPath = sys_get_temp_dir()
            . DIRECTORY_SEPARATOR
            . 'weline_p4b001_subscription_'
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
        $subscriptionFactory = $this->factory($connectionFactory, Subscription::class);
        $periodFactory = $this->factory($connectionFactory, SubscriptionPeriod::class);
        $transactions = new DatabaseTransactionRunner(new TransactionCoordinator());

        try {
            $runtime = $this->runtime($subscriptionFactory, $periodFactory, $transactions);
            $created = $runtime->create([
                'customer_id' => 'cust-durable',
                'website_id' => 0,
                'provider_code' => 'interval_monthly',
                'plan_code' => 'plan-durable',
                'idempotency_key' => 'idem-durable',
                'environment' => SubscriptionState::ENV_SANDBOX,
            ]);
            self::assertSame(SubscriptionState::STATUS_ACTIVE, $created['status']);
            self::assertSame(2, $created['version']);
            self::assertSame(1, $created['current_period_index']);
            self::assertSame(1, $created['period']['period_version']);
            self::assertSame(1, $runtime->store()->count());
            self::assertSame(1, $runtime->periods()->count());

            $fresh = $this->runtime($subscriptionFactory, $periodFactory, $transactions);
            $read = $fresh->get((string) $created['subscription_id']);
            self::assertSame('cust-durable', $read['customer_id']);
            self::assertSame(2, $read['version']);
            $periods = $fresh->periods()->listForSubscription((string) $created['subscription_id']);
            self::assertCount(1, $periods);
            self::assertSame('open', $periods[0]['status']);

            $replay = $fresh->create([
                'customer_id' => 'cust-durable',
                'website_id' => 0,
                'provider_code' => 'interval_monthly',
                'plan_code' => 'plan-durable',
                'idempotency_key' => 'idem-durable',
                'environment' => SubscriptionState::ENV_SANDBOX,
            ]);
            self::assertTrue($replay['replayed']);
            self::assertSame($created['subscription_id'], $replay['subscription_id']);
            self::assertSame($created['period']['period_key'], $replay['period']['period_key']);
            self::assertSame(1, $fresh->store()->count());
            self::assertSame(1, $fresh->periods()->count());

            $otherActor = $this->runtime($subscriptionFactory, $periodFactory, $transactions);
            $cancelled = $fresh->cancel(
                (string) $created['subscription_id'],
                'cust-durable',
                2,
            );
            self::assertSame(SubscriptionState::STATUS_CANCELLED, $cancelled['status']);
            self::assertSame(3, $cancelled['version']);
            self::assertNotNull($cancelled['cancelled_at']);

            try {
                $otherActor->cancel(
                    (string) $created['subscription_id'],
                    'cust-durable',
                    2,
                );
                self::fail('expected stale cancel conflict');
            } catch (SubscriptionConflictException $exception) {
                self::assertContains($exception->errorCode, [
                    'subscription_version_conflict',
                    'subscription_already_cancelled',
                ]);
            }
            self::assertSame(
                SubscriptionState::STATUS_CANCELLED,
                $otherActor->get((string) $created['subscription_id'])['status'],
            );

            try {
                $otherActor->assertOwner((string) $created['subscription_id'], 'foreign-customer');
                self::fail('expected ownership denial');
            } catch (SubscriptionConflictException $exception) {
                self::assertSame('subscription_not_owner', $exception->errorCode);
            }

            $provider = $otherActor->providers()->get('interval_monthly');
            try {
                $otherActor->periods()->openPeriod([
                    'subscription_id' => $created['subscription_id'],
                    'period_index' => 1,
                    'period_key' => $provider->periodKey((string) $created['subscription_id'], 2),
                    'website_id' => 0,
                ]);
                self::fail('expected subscription/index uniqueness conflict');
            } catch (SubscriptionConflictException $exception) {
                self::assertSame('subscription_period_exists', $exception->errorCode);
            }

            $beforeFailedCreate = $otherActor->store()->count();
            $failingPeriodFactory = $this->failingPeriodFactory($connectionFactory);
            $failing = $this->runtime($subscriptionFactory, $failingPeriodFactory, $transactions);
            try {
                $failing->create([
                    'customer_id' => 'cust-rollback',
                    'website_id' => 0,
                    'provider_code' => 'interval_monthly',
                    'plan_code' => 'plan-rollback',
                    'idempotency_key' => 'idem-rollback',
                ]);
                self::fail('expected period persistence failure');
            } catch (\RuntimeException $exception) {
                self::assertSame('forced_subscription_period_failure', $exception->getMessage());
            }
            self::assertSame($beforeFailedCreate, $otherActor->store()->count());
            self::assertSame(1, $otherActor->periods()->count());
        } finally {
            $connector->close();
            $connectionFactory->close();
            if (is_file($dbPath)) {
                unlink($dbPath);
            }
        }
        self::assertFileDoesNotExist($dbPath);
    }

    /**
     * @param callable(): Subscription $subscriptionFactory
     * @param callable(): SubscriptionPeriod $periodFactory
     */
    private function runtime(
        callable $subscriptionFactory,
        callable $periodFactory,
        DatabaseTransactionRunner $transactions,
    ): SubscriptionService {
        $store = new SubscriptionStore($subscriptionFactory);
        $periods = new SubscriptionPeriodStore($periodFactory);
        $ownership = new SubscriptionOwnershipService($store);
        $cancel = new SubscriptionCancelCasService($store, $ownership);
        $rollout = SubscriptionRolloutGate::forTestingConfiguration();
        $rollout->setMode(
            SubscriptionService::CAPABILITY,
            CommerceRolloutGateInterface::MODE_ALLOWLIST,
            ['website:0'],
        );
        return new SubscriptionService(
            new SubscriptionProviderRegistry(),
            $store,
            $periods,
            $ownership,
            $cancel,
            $rollout,
            $transactions,
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

    /** @return \Closure(): SubscriptionPeriod */
    private function failingPeriodFactory(ConnectionFactory $connectionFactory): \Closure
    {
        return static function () use ($connectionFactory): SubscriptionPeriod {
            $model = new class extends SubscriptionPeriod {
                public function save_before(): void
                {
                    throw new \RuntimeException('forced_subscription_period_failure');
                }
            };
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
                . 'provider_code VARCHAR(64) NOT NULL, '
                . 'plan_code VARCHAR(128) NOT NULL, environment VARCHAR(16) NOT NULL, '
                . 'status VARCHAR(16) NOT NULL, version INTEGER NOT NULL, cas_token VARCHAR(64) NOT NULL, '
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
        ];
        foreach ($queries as $query) {
            $connector->query($query)->fetch();
        }
    }
}
