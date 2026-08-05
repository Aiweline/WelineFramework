<?php

declare(strict_types=1);

namespace Weline\Vendor\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\SystemConfig\Api\CommerceRolloutGateInterface;
use Weline\Vendor\Service\VendorMigrationService;

/**
 * TASK-MIG-P4A: checkpoint/fresh verify/allowlist/mode-off unit contract.
 */
final class VendorMigrationServiceTest extends TestCase
{
    private VendorMigrationService $service;

    protected function setUp(): void
    {
        $this->service = VendorMigrationService::forTesting();
        $this->seedWindow($this->service);
    }

    public function testPreflightIsReadOnlyAndApplyRequiresIsolatedClone(): void
    {
        $preflight = $this->service->preflight(
            $this->cloneDb('mig_clone_p4avendor_unit'),
            0,
            901,
        );
        self::assertTrue($preflight['ok'], json_encode($preflight['diffs']));
        self::assertTrue($preflight['apply_ready']);
        self::assertSame(CommerceRolloutGateInterface::MODE_OFF, $preflight['mode']);
        self::assertSame(2, $preflight['row_counts']['products']);
        self::assertSame(2, $preflight['row_counts']['snapshots']);
        self::assertSame(64, strlen($preflight['row_hashes']['combined']));
        self::assertSame(
            CommerceRolloutGateInterface::MODE_OFF,
            $this->service->rollout()->mode(VendorMigrationService::CAPABILITY),
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(VendorMigrationService::ERROR_SHARED_DB);
        $this->service->apply(null, 0, 901);
    }

    public function testApplyStaysShadowThenFreshVerifyEnablesExactAllowlist(): void
    {
        $target = $this->cloneDb('mig_clone_p4avendor_unit');
        $apply = $this->service->apply($target, 0, 901);

        self::assertTrue($apply['ok'], json_encode($apply));
        self::assertSame(CommerceRolloutGateInterface::MODE_SHADOW, $apply['mode']);
        self::assertSame([], $apply['allowlist']);
        self::assertSame(0, $apply['business_rows_written']);
        self::assertTrue($apply['fresh_verify_required']);
        self::assertSame(2, $apply['row_counts']['snapshots']);
        self::assertSame(0, $apply['report']['unclassified_diff_count']);

        $applyReplay = $this->service->apply($target, 0, 901);
        self::assertTrue($applyReplay['ok'], json_encode($applyReplay));
        self::assertSame(CommerceRolloutGateInterface::MODE_SHADOW, $applyReplay['mode']);
        self::assertSame([], $applyReplay['allowlist']);
        self::assertSame(0, $applyReplay['business_rows_written']);
        self::assertSame($apply['fact_hash'], $applyReplay['fact_hash']);
        self::assertSame($apply['row_counts'], $applyReplay['row_counts']);

        $verify = $this->service->verify($target, $apply['checkpoint_id']);
        self::assertTrue($verify['ok'], json_encode($verify['diffs']));
        self::assertSame(CommerceRolloutGateInterface::MODE_SHADOW, $verify['mode']);
        self::assertTrue($verify['fresh_journal']['ok']);
        self::assertSame('p4a_vendor_shadow_applied', $verify['fresh_journal']['last_event']);
        self::assertSame($apply['fact_hash'], $verify['fact_hash']);

        $allowlist = $this->service->allowlist(
            $target,
            $apply['checkpoint_id'],
            0,
            901,
        );
        self::assertTrue($allowlist['ok']);
        self::assertSame(CommerceRolloutGateInterface::MODE_ALLOWLIST, $allowlist['mode']);
        self::assertSame(
            [['website_id' => 0, 'store_id' => 901]],
            $allowlist['allowlist'],
        );
        self::assertFalse($allowlist['production_on']);

        $replay = $this->service->allowlist(
            $target,
            $apply['checkpoint_id'],
            0,
            901,
        );
        self::assertTrue($replay['ok']);
        self::assertSame($allowlist['allowlist'], $replay['allowlist']);

        $fresh = $this->service->verify($target, $apply['checkpoint_id']);
        self::assertTrue($fresh['ok'], json_encode($fresh['diffs']));
        self::assertSame(CommerceRolloutGateInterface::MODE_ALLOWLIST, $fresh['mode']);
    }

    public function testShadowMismatchFailsClosedBeforeAllowlist(): void
    {
        $this->service->forceShadowMismatchForTesting();
        $target = $this->cloneDb('mig_clone_p4avendor_diff');
        $result = $this->service->apply($target, 0, 901);

        self::assertFalse($result['ok']);
        self::assertSame(VendorMigrationService::ERROR_SHADOW_DIFF, $result['error']);
        self::assertSame(CommerceRolloutGateInterface::MODE_OFF, $result['mode']);
        self::assertGreaterThan(0, $result['report']['unclassified_diff_count']);
        self::assertSame(0, $result['business_rows_written']);

        $verify = $this->service->verify($target, $result['checkpoint_id']);
        self::assertFalse($verify['ok']);
        self::assertContains(
            ['code' => 'mig_p4a_vendor_apply_journal_missing'],
            $verify['diffs'],
        );
    }

    public function testFactDriftIsRejectedByFreshVerify(): void
    {
        $target = $this->cloneDb('mig_clone_p4avendor_drift');
        $apply = $this->service->apply($target, 0, 901);
        self::assertTrue($apply['ok']);

        $this->service->seedPayable([
            'order_ref' => 'ord-after-checkpoint',
            'payment_ref' => 'pay-after-checkpoint',
            'gross_minor' => 7000,
        ]);
        $verify = $this->service->verify($target, $apply['checkpoint_id']);

        self::assertFalse($verify['ok']);
        self::assertContains(['code' => 'snapshots_count_changed'], $verify['diffs']);
        self::assertContains(['code' => 'combined_hash_changed'], $verify['diffs']);
    }

    public function testModeOffRollbackRetainsFactsAndIsIdempotent(): void
    {
        $target = $this->cloneDb('mig_clone_p4avendor_rollback');
        $apply = $this->service->apply($target, 0, 901);
        self::assertTrue($apply['ok']);
        self::assertTrue($this->service->allowlist(
            $target,
            $apply['checkpoint_id'],
            0,
            901,
        )['ok']);

        $rollback = $this->service->rollbackToModeOff(
            $target,
            $apply['checkpoint_id'],
        );
        self::assertTrue($rollback['ok']);
        self::assertSame(CommerceRolloutGateInterface::MODE_OFF, $rollback['mode']);
        self::assertTrue($rollback['settlement_facts_retained']);
        self::assertTrue($rollback['continue_existing_settlement']);
        self::assertTrue($rollback['new_split_blocked']);
        self::assertSame(2, $rollback['row_counts']['snapshots']);
        self::assertSame(2, $rollback['row_counts']['payouts']);

        $replay = $this->service->rollbackToModeOff(
            $target,
            $apply['checkpoint_id'],
        );
        self::assertTrue($replay['ok']);
        self::assertSame($rollback['fact_hash'], $replay['fact_hash']);

        $verify = $this->service->verify($target, $apply['checkpoint_id']);
        self::assertTrue($verify['ok'], json_encode($verify['diffs']));
        self::assertSame(CommerceRolloutGateInterface::MODE_OFF, $verify['mode']);
    }

    public function testScopeMismatchAndMissingSamplesAreRejected(): void
    {
        $target = $this->cloneDb('mig_clone_p4avendor_scope');
        $apply = $this->service->apply($target, 0, 901);
        self::assertTrue($apply['ok']);

        $wrong = $this->service->allowlist(
            $target,
            $apply['checkpoint_id'],
            0,
            902,
        );
        self::assertFalse($wrong['ok']);
        self::assertSame(VendorMigrationService::ERROR_SCOPE_MISMATCH, $wrong['error']);

        $empty = VendorMigrationService::forTesting();
        $preflight = $empty->preflight(
            $this->cloneDb('mig_clone_p4avendor_empty'),
            0,
            901,
        );
        self::assertFalse($preflight['ok']);
        self::assertFalse($preflight['apply_ready']);
        $result = $empty->apply(
            $this->cloneDb('mig_clone_p4avendor_empty'),
            0,
            901,
        );
        self::assertFalse($result['ok']);
        self::assertSame(VendorMigrationService::ERROR_NO_SAMPLE, $result['error']);
    }

    private function seedWindow(VendorMigrationService $service): void
    {
        $service->seedBinding(['product_sku' => 'SKU-MIG-A']);
        $service->seedBinding(['product_sku' => 'SKU-MIG-B']);
        $service->seedPayable([
            'order_ref' => 'ord-mig-a',
            'payment_ref' => 'pay-mig-a',
            'gross_minor' => 10000,
        ]);
        $service->seedPayable([
            'order_ref' => 'ord-mig-b',
            'payment_ref' => 'pay-mig-b',
            'gross_minor' => 5000,
        ]);
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
