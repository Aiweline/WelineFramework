<?php

declare(strict_types=1);

namespace Weline\B2B\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\B2B\Service\B2BMigrationService;
use Weline\SystemConfig\Api\CommerceRolloutGateInterface;

/**
 * TASK-MIG-P4C: checkpoint, version mapping, fresh verify and safe cutover.
 */
final class B2BMigrationServiceTest extends TestCase
{
    private B2BMigrationService $service;

    protected function setUp(): void
    {
        $this->service = B2BMigrationService::forTesting();
        $this->service->seedGroup([
            'group_id' => 'g-dealer',
            'website_id' => 0,
            'code' => 'dealer',
            'customer_id' => 'cust-b2b',
        ]);
        $this->service->seedPriceList([
            'list_id' => 'pl-dealer',
            'group_id' => 'g-dealer',
            'website_id' => 0,
            'version' => 1,
            'sku_amounts' => ['SKU-A' => 800],
        ]);
        $this->service->seedPriceList([
            'list_id' => 'pl-dealer-ch',
            'group_id' => 'g-dealer',
            'website_id' => 0,
            'version' => 2,
            'sku_amounts' => ['SKU-A' => 750],
            'channel_id' => 'ch-pro',
        ]);
    }

    public function testPreflightDerivesVersionMappingAndSamplesWithoutBusinessWrites(): void
    {
        $preflight = $this->service->preflight(
            $this->cloneDb('mig_clone_p4cb2b_preflight'),
            0,
        );

        self::assertTrue($preflight['ok'], json_encode($preflight));
        self::assertTrue($preflight['apply_ready']);
        self::assertSame(2, $preflight['mapping_count']);
        self::assertSame(2, $preflight['sample_count']);
        self::assertSame(1, $preflight['row_counts']['groups']);
        self::assertSame(1, $preflight['row_counts']['memberships']);
        self::assertSame(2, $preflight['row_counts']['price_lists']);
        self::assertSame(2, $preflight['row_counts']['items']);
        self::assertSame(0, $preflight['row_counts']['quotes']);
        self::assertSame(0, $preflight['row_counts']['snapshots']);
        self::assertSame(0, $preflight['business_writes']);
        self::assertSame(CommerceRolloutGateInterface::MODE_OFF, $preflight['mode']);
    }

    public function testApplyRequiresIsolatedClone(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(B2BMigrationService::ERROR_SHARED_DB);
        $this->service->apply(null, 0);
    }

    public function testApplyVerifyAllowlistAndRollbackRemainCheckpointBound(): void
    {
        $target = $this->cloneDb('mig_clone_p4cb2b_cutover');
        $apply = $this->service->apply($target, 0);

        self::assertTrue($apply['ok'], json_encode($apply));
        self::assertSame(CommerceRolloutGateInterface::MODE_SHADOW, $apply['mode']);
        self::assertSame([], $apply['allowlist']);
        self::assertTrue($apply['fresh_verify_required']);
        self::assertFalse($apply['checkout_allowlisted']);
        self::assertFalse($apply['production_on']);
        self::assertSame(2, $apply['mapping_count']);
        self::assertSame(2, $apply['sample_count']);
        self::assertSame(2, $apply['report']['matched_count']);
        self::assertSame(0, $apply['report']['unclassified_diff_count']);
        self::assertSame(0, $apply['business_writes']);
        self::assertSame(0, $apply['row_counts']['quotes']);
        self::assertSame(0, $apply['row_counts']['snapshots']);

        $checkpoint = (string) $apply['checkpoint_id'];
        $verify = $this->service->verify($target, $checkpoint);
        self::assertTrue($verify['ok'], json_encode($verify['diffs']));
        self::assertTrue($verify['fresh_journal']['ok']);
        self::assertGreaterThanOrEqual(4, $verify['fresh_journal']['journal_count']);
        self::assertSame($apply['fact_hash'], $verify['fact_hash']);
        self::assertSame(0, $verify['business_writes']);

        $wrongScope = $this->service->allowlist($target, $checkpoint, 1);
        self::assertFalse($wrongScope['ok']);
        self::assertSame(B2BMigrationService::ERROR_SCOPE_MISMATCH, $wrongScope['error']);

        $allowlist = $this->service->allowlist($target, $checkpoint, 0);
        self::assertTrue($allowlist['ok'], json_encode($allowlist));
        self::assertSame(CommerceRolloutGateInterface::MODE_ALLOWLIST, $allowlist['mode']);
        self::assertSame([['website_id' => 0]], $allowlist['allowlist']);
        self::assertTrue($allowlist['fresh_verify']);
        self::assertTrue($allowlist['checkout_allowlisted']);
        self::assertFalse($allowlist['production_on']);
        self::assertTrue($this->service->allowlist($target, $checkpoint, 0)['ok']);

        $rollback = $this->service->rollbackToModeOff($target, $checkpoint);
        self::assertTrue($rollback['ok'], json_encode($rollback));
        self::assertSame(CommerceRolloutGateInterface::MODE_OFF, $rollback['mode']);
        self::assertSame([], $rollback['allowlist']);
        self::assertTrue($rollback['snapshots_retained']);
        self::assertTrue($rollback['retail_path_continues']);
        self::assertTrue($rollback['b2b_candidate_closed']);
        self::assertSame(0, $rollback['snapshot_count']);
        self::assertSame(0, $rollback['quote_count']);
        self::assertTrue($this->service->rollbackToModeOff($target, $checkpoint)['ok']);

        $retail = $this->service->b2b()->issueQuote([
            'customer_id' => 'cust-b2b',
            'website_id' => 0,
            'sku' => 'SKU-A',
            'retail_amount_minor' => 1000,
        ]);
        self::assertTrue($retail['ok']);
        self::assertSame(1000, $retail['token']['amount_minor']);
        self::assertNull($retail['token']['price_list_id']);
    }

    public function testFreshVerifyRejectsDifferentTargetFingerprint(): void
    {
        $apply = $this->service->apply(
            $this->cloneDb('mig_clone_p4cb2b_target_a'),
            0,
        );
        self::assertTrue($apply['ok']);

        $verify = $this->service->verify(
            $this->cloneDb('mig_clone_p4cb2b_target_b'),
            (string) $apply['checkpoint_id'],
        );
        self::assertFalse($verify['ok']);
        self::assertContains(
            ['code' => B2BMigrationService::ERROR_FINGERPRINT],
            $verify['diffs'],
        );
    }

    public function testShadowMismatchFailsClosedAndCannotAllowlist(): void
    {
        $target = $this->cloneDb('mig_clone_p4cb2b_diff');
        $this->service->forceShadowMismatchForTesting();
        $result = $this->service->apply($target, 0);

        self::assertFalse($result['ok']);
        self::assertSame(B2BMigrationService::ERROR_SHADOW_DIFF, $result['error']);
        self::assertSame(CommerceRolloutGateInterface::MODE_OFF, $result['mode']);
        self::assertSame(0, $result['business_writes']);

        $allowlist = $this->service->allowlist(
            $target,
            (string) $result['checkpoint_id'],
            0,
        );
        self::assertFalse($allowlist['ok']);
        self::assertSame(B2BMigrationService::ERROR_VERIFY, $allowlist['error']);
    }

    public function testApplyWithoutDurableSamplesIsRejected(): void
    {
        $empty = B2BMigrationService::forTesting();
        $result = $empty->apply($this->cloneDb('mig_clone_p4cb2b_empty'), 0);

        self::assertFalse($result['ok']);
        self::assertSame(B2BMigrationService::ERROR_NO_SAMPLE, $result['error']);
        self::assertSame(0, $result['sample_count']);
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
