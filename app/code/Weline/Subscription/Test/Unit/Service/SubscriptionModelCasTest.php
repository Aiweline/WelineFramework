<?php

declare(strict_types=1);

namespace Weline\Subscription\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Subscription\Model\SubscriptionState;
use Weline\Subscription\Service\SubscriptionConflictException;
use Weline\Subscription\Service\SubscriptionService;
use Weline\SystemConfig\Api\CommerceRolloutGateInterface;

/**
 * TASK-P4B-001 / TEST-P4B-01：Provider、重复创建、ownership、cancel CAS、version.
 */
final class SubscriptionModelCasTest extends TestCase
{
    private SubscriptionService $service;

    protected function setUp(): void
    {
        $this->service = SubscriptionService::forTesting();
        $this->service->rollout()->setMode(
            SubscriptionService::CAPABILITY,
            CommerceRolloutGateInterface::MODE_ALLOWLIST,
            ['website:0'],
        );
    }

    public function testCreateOnWebsiteZeroOpensFirstPeriod(): void
    {
        $created = $this->service->create([
            'customer_id' => 'cust-1',
            'website_id' => 0,
            'provider_code' => 'interval_monthly',
            'plan_code' => 'plan_basic',
            'idempotency_key' => 'idem-1',
            'environment' => 'sandbox',
        ]);

        self::assertSame(SubscriptionState::STATUS_ACTIVE, $created['status']);
        self::assertSame(0, $created['website_id']);
        self::assertSame(1, $created['current_period_index']);
        self::assertSame(2, $created['version']); // create=1 then period index bump
        self::assertSame('open', $created['period']['status']);
        self::assertStringContainsString('|p1|', $created['period']['period_key']);
        self::assertContains('interval_monthly', $this->service->providers()->codes());
    }

    public function testDuplicateOwnerPlanIsRejectedAndIdempotentReplayWorks(): void
    {
        $first = $this->service->create([
            'customer_id' => 'cust-2',
            'website_id' => 0,
            'provider_code' => 'interval_monthly',
            'plan_code' => 'plan_pro',
            'idempotency_key' => 'idem-2a',
        ]);

        try {
            $this->service->create([
                'customer_id' => 'cust-2',
                'website_id' => 0,
                'provider_code' => 'interval_monthly',
                'plan_code' => 'plan_pro',
                'idempotency_key' => 'idem-2b',
            ]);
            self::fail('expected duplicate');
        } catch (SubscriptionConflictException $e) {
            self::assertSame('subscription_already_exists', $e->errorCode);
        }

        $replay = $this->service->create([
            'customer_id' => 'cust-2',
            'website_id' => 0,
            'provider_code' => 'interval_monthly',
            'plan_code' => 'plan_pro',
            'idempotency_key' => 'idem-2a',
        ]);
        self::assertTrue($replay['replayed'] ?? false);
        self::assertSame($first['subscription_id'], $replay['subscription_id']);
        self::assertSame(1, $this->service->store()->count());
    }

    public function testCancelCasWinsAndLoserGetsVersionConflict(): void
    {
        $created = $this->service->create([
            'customer_id' => 'cust-3',
            'website_id' => 0,
            'provider_code' => 'interval_monthly',
            'plan_code' => 'plan_cas',
            'idempotency_key' => 'idem-3',
        ]);
        $id = (string) $created['subscription_id'];
        $version = (int) $created['version'];

        $cancelled = $this->service->cancel($id, 'cust-3', $version);
        self::assertSame(SubscriptionState::STATUS_CANCELLED, $cancelled['status']);
        self::assertSame($version + 1, $cancelled['version']);
        self::assertNotNull($cancelled['cancelled_at']);

        try {
            $this->service->cancel($id, 'cust-3', $version);
            self::fail('expected version conflict');
        } catch (SubscriptionConflictException $e) {
            // Already cancelled is also acceptable if second call reads cancelled first;
            // with stale version, cancelCas hits already_cancelled after ownership.
            self::assertContains($e->errorCode, [
                'subscription_version_conflict',
                'subscription_already_cancelled',
            ]);
        }

        // Explicit race: two actors with same expected version before either commits —
        // recreate fresh and simulate via store version bump between reads.
        $created2 = $this->service->create([
            'customer_id' => 'cust-3b',
            'website_id' => 0,
            'provider_code' => 'interval_monthly',
            'plan_code' => 'plan_race',
            'idempotency_key' => 'idem-3b',
        ]);
        $id2 = (string) $created2['subscription_id'];
        $v2 = (int) $created2['version'];
        // Simulate concurrent renew bumping version before cancel.
        $this->service->store()->replaceWithVersionBump($id2, $v2, ['current_period_index' => 2]);
        try {
            $this->service->cancel($id2, 'cust-3b', $v2);
            self::fail('expected stale cancel denial');
        } catch (SubscriptionConflictException $e) {
            self::assertSame('subscription_version_conflict', $e->errorCode);
        }
        self::assertSame(SubscriptionState::STATUS_ACTIVE, $this->service->get($id2)['status']);
    }

    public function testOwnershipDeniedForForeignCustomer(): void
    {
        $created = $this->service->create([
            'customer_id' => 'cust-owner',
            'website_id' => 0,
            'provider_code' => 'interval_monthly',
            'plan_code' => 'plan_own',
            'idempotency_key' => 'idem-own',
        ]);

        $this->expectException(SubscriptionConflictException::class);
        $this->service->cancel(
            (string) $created['subscription_id'],
            'cust-other',
            (int) $created['version'],
        );
    }

    public function testPeriodKeyIsUniquePerSubscriptionIndex(): void
    {
        $created = $this->service->create([
            'customer_id' => 'cust-p',
            'website_id' => 0,
            'provider_code' => 'interval_monthly',
            'plan_code' => 'plan_period',
            'idempotency_key' => 'idem-p',
        ]);
        $provider = $this->service->providers()->get('interval_monthly');
        $key = $provider->periodKey((string) $created['subscription_id'], 1);
        $replay = $this->service->periods()->openPeriod([
            'subscription_id' => $created['subscription_id'],
            'period_index' => 1,
            'period_key' => $key,
            'website_id' => 0,
        ]);
        self::assertTrue($replay['replayed'] ?? false);

        try {
            $this->service->periods()->openPeriod([
                'subscription_id' => 'other-sub',
                'period_index' => 9,
                'period_key' => $key,
                'website_id' => 0,
            ]);
            self::fail('expected period key conflict');
        } catch (SubscriptionConflictException $e) {
            self::assertSame('subscription_period_exists', $e->errorCode);
        }
    }

    public function testModeOffBlocksCreateButAllowsRead(): void
    {
        $created = $this->service->create([
            'customer_id' => 'cust-off',
            'website_id' => 0,
            'provider_code' => 'interval_monthly',
            'plan_code' => 'plan_off',
            'idempotency_key' => 'idem-off',
        ]);
        $this->service->rollout()->setMode(
            SubscriptionService::CAPABILITY,
            CommerceRolloutGateInterface::MODE_OFF,
        );

        try {
            $this->service->create([
                'customer_id' => 'cust-off-2',
                'website_id' => 0,
                'provider_code' => 'interval_monthly',
                'plan_code' => 'plan_off_2',
                'idempotency_key' => 'idem-off-2',
            ]);
            self::fail('expected mode off');
        } catch (SubscriptionConflictException $e) {
            self::assertSame(SubscriptionService::ERROR_MODE_OFF, $e->errorCode);
        }

        $read = $this->service->get((string) $created['subscription_id']);
        self::assertSame(SubscriptionState::STATUS_ACTIVE, $read['status']);
    }
}
