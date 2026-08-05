<?php

declare(strict_types=1);

namespace Weline\Subscription\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Subscription\Service\SubscriptionConflictException;
use Weline\Subscription\Service\SubscriptionMigrationService;
use Weline\Subscription\Service\SubscriptionRolloutGate;
use Weline\Subscription\Service\SubscriptionSchedulerService;

/**
 * TASK-MIG-P4B: checkpoint, gap backfill, fresh verify and safe cutover.
 */
final class SubscriptionMigrationServiceTest extends TestCase
{
    private SubscriptionMigrationService $service;
    private string $gapSubscriptionId;

    protected function setUp(): void
    {
        $this->service = SubscriptionMigrationService::forTesting();
        // Period 1 exists; current due is 4. Historical periods 2 and 3 are
        // independent missed gaps. Period 4 is the next due slot.
        $this->gapSubscriptionId = $this->service->seedSubscription([
            'customer_id' => 'cust-mig-a',
            'plan_code' => 'plan_mig_a',
            'periods_to_bill' => 4,
        ]);
        $this->service->seedSubscription([
            'customer_id' => 'cust-mig-b',
            'plan_code' => 'plan_mig_b',
            'periods_to_bill' => 1,
        ]);
    }

    public function testPreflightPlansTwoIndependentMissedPeriodsWithoutExternalWrites(): void
    {
        $preflight = $this->service->preflight(
            $this->cloneDb('mig_clone_p4bsub_preflight'),
            0,
        );

        self::assertTrue($preflight['ok'], json_encode($preflight));
        self::assertTrue($preflight['apply_ready']);
        self::assertSame(2, $preflight['sample_count']);
        self::assertSame(2, $preflight['gap_period_count']);
        self::assertSame(2, $preflight['watermark_event_count']);
        self::assertSame(2, $preflight['current_row_counts']['periods']);
        self::assertSame(4, $preflight['expected_row_counts']['periods']);
        self::assertSame(1, $preflight['expected_row_counts']['watermarks']);
        self::assertSame(0, $preflight['external_order_writes']);
        self::assertSame(0, $preflight['external_payment_writes']);
        self::assertSame(SubscriptionRolloutGate::MODE_OFF, $preflight['mode']);
    }

    public function testApplyRequiresIsolatedClone(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(SubscriptionMigrationService::ERROR_SHARED_DB);
        $this->service->apply(null, 0);
    }

    public function testApplyVerifyAllowlistAndRollbackRemainCheckpointBound(): void
    {
        $target = $this->cloneDb('mig_clone_p4bsub_cutover');
        $apply = $this->service->apply($target, 0);

        self::assertTrue($apply['ok'], json_encode($apply));
        self::assertSame(SubscriptionRolloutGate::MODE_SHADOW, $apply['mode']);
        self::assertSame(2, $apply['backfill']['period_rows_written']);
        self::assertSame(2, $apply['backfill']['watermark_events_written']);
        self::assertSame(0, $apply['external_order_writes']);
        self::assertSame(0, $apply['external_payment_writes']);
        self::assertSame(0, $this->service->scheduler()->orders()->orderCount());
        self::assertSame(4, $apply['row_counts']['periods']);
        self::assertSame(1, $apply['row_counts']['watermarks']);
        self::assertSame(0, $apply['report']['active_lease_count']);
        self::assertSame(0, $apply['report']['unclassified_diff_count']);

        $checkpoint = (string) $apply['checkpoint_id'];
        $verify = $this->service->verify($target, $checkpoint);
        self::assertTrue($verify['ok'], json_encode($verify['diffs']));
        self::assertTrue($verify['fresh_journal']['ok']);
        self::assertGreaterThanOrEqual(4, $verify['fresh_journal']['journal_count']);
        self::assertSame($apply['fact_hash'], $verify['fact_hash']);

        $wrongScope = $this->service->allowlist($target, $checkpoint, 1);
        self::assertFalse($wrongScope['ok']);
        self::assertSame(
            SubscriptionMigrationService::ERROR_SCOPE_MISMATCH,
            $wrongScope['error'],
        );

        $allowlist = $this->service->allowlist($target, $checkpoint, 0);
        self::assertTrue($allowlist['ok']);
        self::assertSame(SubscriptionRolloutGate::MODE_ALLOWLIST, $allowlist['mode']);
        self::assertSame([['website_id' => 0]], $allowlist['allowlist']);
        self::assertFalse($allowlist['production_on']);
        self::assertTrue($this->service->allowlist($target, $checkpoint, 0)['ok']);

        // Post-cutover obligations may legitimately advance. They invalidate
        // strict snapshot verify but must never disable emergency mode-off.
        $period2 = $this->service->scheduler()->recover(
            $this->gapSubscriptionId,
            'mig-recover-2',
            2,
        );
        $period3 = $this->service->scheduler()->recover(
            $this->gapSubscriptionId,
            'mig-recover-3',
            3,
        );
        self::assertNotSame($period2['order_ref'], $period3['order_ref']);
        self::assertSame(2, $this->service->scheduler()->orders()->orderCount());
        self::assertFalse($this->service->verify($target, $checkpoint)['ok']);

        $rollback = $this->service->rollbackToModeOff($target, $checkpoint);
        self::assertTrue($rollback['ok'], json_encode($rollback));
        self::assertSame(SubscriptionRolloutGate::MODE_OFF, $rollback['mode']);
        self::assertTrue($rollback['periods_retained']);
        self::assertTrue($rollback['attempts_retained']);
        self::assertTrue($rollback['new_scheduler_ticks_blocked']);
        self::assertTrue($rollback['recover_still_allowed']);

        try {
            $this->service->scheduler()->tick($this->gapSubscriptionId, 'post-rollback');
            self::fail('expected mode off tick block');
        } catch (SubscriptionConflictException $exception) {
            self::assertSame(SubscriptionSchedulerService::ERROR_MODE_OFF, $exception->errorCode);
        }
        $replayed = $this->service->scheduler()->recover(
            $this->gapSubscriptionId,
            'post-rollback-recover',
            2,
        );
        self::assertSame($period2['order_ref'], $replayed['order_ref']);
        self::assertSame(2, $this->service->scheduler()->orders()->orderCount());
        self::assertTrue($this->service->rollbackToModeOff($target, $checkpoint)['ok']);
    }

    public function testActiveLeaseRejectsApplyBeforeCheckpointBackfill(): void
    {
        $lease = $this->service->scheduler()->leases()->acquire(
            $this->gapSubscriptionId,
            'active-migration-worker',
            60,
        );
        self::assertTrue($lease['ok']);

        $result = $this->service->apply(
            $this->cloneDb('mig_clone_p4bsub_lease'),
            0,
        );
        self::assertFalse($result['ok']);
        self::assertSame(SubscriptionMigrationService::ERROR_ACTIVE_LEASE, $result['error']);
        self::assertSame(2, $result['current_row_counts']['periods']);
        self::assertSame(0, $this->service->scheduler()->orders()->orderCount());
    }

    public function testShadowMismatchFailsClosedAndCannotAllowlist(): void
    {
        $target = $this->cloneDb('mig_clone_p4bsub_diff');
        $this->service->forceShadowMismatchForTesting();
        $result = $this->service->apply($target, 0);

        self::assertFalse($result['ok']);
        self::assertSame(SubscriptionMigrationService::ERROR_SHADOW_DIFF, $result['error']);
        self::assertSame(SubscriptionRolloutGate::MODE_OFF, $result['mode']);
        self::assertSame(0, $this->service->scheduler()->orders()->orderCount());

        $allowlist = $this->service->allowlist(
            $target,
            (string) $result['checkpoint_id'],
            0,
        );
        self::assertFalse($allowlist['ok']);
        self::assertSame(SubscriptionMigrationService::ERROR_VERIFY, $allowlist['error']);
    }

    public function testApplyWithoutDurableSamplesIsRejected(): void
    {
        $empty = SubscriptionMigrationService::forTesting();
        $result = $empty->apply($this->cloneDb('mig_clone_p4bsub_empty'), 0);

        self::assertFalse($result['ok']);
        self::assertSame(SubscriptionMigrationService::ERROR_NO_SAMPLE, $result['error']);
    }

    /**
     * @return array{type:string,hostname:string,hostport:string,database:string,username:string}
     */
    private function cloneDb(string $database): array
    {
        return [
            'type' => 'pgsql',
            'hostname' => '127.0.0.1',
            'hostport' => '5432',
            'database' => $database,
            'username' => 'weline',
        ];
    }
}
