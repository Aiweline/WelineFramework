<?php

declare(strict_types=1);

namespace Weline\CustomerAsset\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\CustomerAsset\Model\AssetAccount;
use Weline\CustomerAsset\Model\AssetLedger;
use Weline\CustomerAsset\Model\AssetReservation;
use Weline\CustomerAsset\Service\CustomerAssetMigrationService;
use Weline\CustomerAsset\Service\CustomerAssetRolloutGate;
use Weline\SystemConfig\Api\CommerceRolloutGateInterface;

/**
 * TASK-MIG-P4D: conservation, checkpoint, fresh verify and safe cutover.
 */
final class CustomerAssetMigrationServiceTest extends TestCase
{
    private CustomerAssetMigrationService $service;

    protected function setUp(): void
    {
        $this->service = CustomerAssetMigrationService::forTesting();
        $this->seedValidFacts($this->service);
    }

    public function testPreflightReplaysLedgerWithoutBusinessWrites(): void
    {
        $preflight = $this->service->preflight(
            $this->cloneDb('mig_clone_p4dasset_preflight'),
            0,
        );

        self::assertTrue($preflight['ok'], json_encode($preflight));
        self::assertTrue($preflight['apply_ready']);
        self::assertSame('pgsql', $preflight['database_type']);
        self::assertSame(1, $preflight['row_counts']['accounts']);
        self::assertSame(2, $preflight['row_counts']['ledger']);
        self::assertSame(1, $preflight['row_counts']['reservations']);
        self::assertSame(3, $preflight['sample_count']);
        self::assertSame(0, $preflight['diff_count']);
        self::assertSame(0, $preflight['business_writes']);
        self::assertSame(CommerceRolloutGateInterface::MODE_OFF, $preflight['mode']);
    }

    public function testApplyRequiresPostgresqlIsolatedClone(): void
    {
        try {
            $this->service->apply(null, 0);
            self::fail('Expected isolated database rejection');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString(
                CustomerAssetMigrationService::ERROR_SHARED_DB,
                $exception->getMessage(),
            );
        }

        $sqlite = $this->cloneDb('mig_clone_p4dasset_sqlite');
        $sqlite['type'] = 'sqlite';
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(
            CustomerAssetMigrationService::ERROR_POSTGRESQL,
        );
        $this->service->apply($sqlite, 0);
    }

    public function testApplyVerifyAllowlistAndRollbackRemainCheckpointBound(): void
    {
        $target = $this->cloneDb('mig_clone_p4dasset_cutover');
        $apply = $this->service->apply($target, 0);

        self::assertTrue($apply['ok'], json_encode($apply));
        self::assertSame(CommerceRolloutGateInterface::MODE_SHADOW, $apply['mode']);
        self::assertSame([], $apply['allowlist']);
        self::assertTrue($apply['fresh_verify_required']);
        self::assertFalse($apply['tender_allowlisted']);
        self::assertFalse($apply['production_on']);
        self::assertSame(0, $apply['business_writes']);

        $checkpoint = (string) $apply['checkpoint_id'];
        $verify = $this->service->verify($target, $checkpoint);
        self::assertTrue($verify['ok'], json_encode($verify['diffs']));
        self::assertTrue($verify['fresh_journal']['ok']);
        self::assertGreaterThanOrEqual(4, $verify['fresh_journal']['journal_count']);
        self::assertSame($apply['fact_hash'], $verify['fact_hash']);
        self::assertSame($apply['obligation_hash'], $verify['obligation_hash']);

        $wrongScope = $this->service->allowlist($target, $checkpoint, 1);
        self::assertFalse($wrongScope['ok']);
        self::assertSame(
            CustomerAssetMigrationService::ERROR_SCOPE_MISMATCH,
            $wrongScope['error'],
        );

        $allowlist = $this->service->allowlist($target, $checkpoint, 0);
        self::assertTrue($allowlist['ok'], json_encode($allowlist));
        self::assertSame(CommerceRolloutGateInterface::MODE_ALLOWLIST, $allowlist['mode']);
        self::assertSame([['website_id' => 0]], $allowlist['allowlist']);
        self::assertTrue($allowlist['fresh_verify']);
        self::assertTrue($allowlist['tender_allowlisted']);
        self::assertFalse($allowlist['production_on']);
        self::assertTrue($this->service->allowlist($target, $checkpoint, 0)['ok']);

        $rollback = $this->service->rollbackToModeOff($target, $checkpoint);
        self::assertTrue($rollback['ok'], json_encode($rollback));
        self::assertSame(CommerceRolloutGateInterface::MODE_OFF, $rollback['mode']);
        self::assertSame([], $rollback['allowlist']);
        self::assertTrue($rollback['ledger_retained']);
        self::assertTrue($rollback['obligations_retained']);
        self::assertTrue($rollback['existing_settlement_continues']);
        self::assertTrue($rollback['new_tender_closed']);
        self::assertSame($apply['fact_hash'], $rollback['fact_hash']);
        self::assertTrue($this->service->rollbackToModeOff($target, $checkpoint)['ok']);
    }

    public function testFreshVerifyRejectsDifferentTargetFingerprint(): void
    {
        $apply = $this->service->apply(
            $this->cloneDb('mig_clone_p4dasset_target_a'),
            0,
        );
        self::assertTrue($apply['ok']);

        $verify = $this->service->verify(
            $this->cloneDb('mig_clone_p4dasset_target_b'),
            (string) $apply['checkpoint_id'],
        );
        self::assertFalse($verify['ok']);
        self::assertContains(
            ['code' => CustomerAssetMigrationService::ERROR_FINGERPRINT],
            $verify['diffs'],
        );
    }

    public function testConservationMismatchFailsClosed(): void
    {
        $service = CustomerAssetMigrationService::forTesting();
        [$accounts, $ledger, $reservations] = $this->validFacts();
        $accounts[0]['available_minor'] = 999;
        $service->seedFacts($accounts, $ledger, $reservations);

        $result = $service->apply(
            $this->cloneDb('mig_clone_p4dasset_invalid'),
            0,
        );

        self::assertFalse($result['ok']);
        self::assertSame(
            CustomerAssetMigrationService::ERROR_INTEGRITY,
            $result['error'],
        );
        self::assertContains(
            [
                'code' => 'account_terminal_balance_mismatch',
                'account_id' => 'acct-asset-1',
            ],
            $result['diffs'],
        );
        self::assertSame(CommerceRolloutGateInterface::MODE_OFF, $service->rollout()->mode(
            CustomerAssetMigrationService::CAPABILITY,
        ));
    }

    public function testCommittedReturnObligationReplaysToCurrentBalance(): void
    {
        $service = CustomerAssetMigrationService::forTesting();
        [$accounts, $ledger, $reservations] = $this->committedFacts();
        $service->seedFacts($accounts, $ledger, $reservations);

        $result = $service->preflight(
            $this->cloneDb('mig_clone_p4dasset_committed'),
            0,
        );

        self::assertTrue($result['ok'], json_encode($result));
        self::assertTrue($result['apply_ready']);
        self::assertSame(1, $result['row_counts']['accounts']);
        self::assertSame(4, $result['row_counts']['ledger']);
        self::assertSame(1, $result['row_counts']['reservations']);
        self::assertSame(5, $result['sample_count']);
    }

    public function testShadowMismatchFailsClosedAndCannotAllowlist(): void
    {
        $target = $this->cloneDb('mig_clone_p4dasset_shadow_diff');
        $this->service->forceShadowMismatchForTesting();
        $result = $this->service->apply($target, 0);

        self::assertFalse($result['ok']);
        self::assertSame(
            CustomerAssetMigrationService::ERROR_SHADOW_DIFF,
            $result['error'],
        );
        self::assertSame(CommerceRolloutGateInterface::MODE_OFF, $result['mode']);
        self::assertSame(0, $result['business_writes']);

        $allowlist = $this->service->allowlist(
            $target,
            (string) $result['checkpoint_id'],
            0,
        );
        self::assertFalse($allowlist['ok']);
        self::assertSame(
            CustomerAssetMigrationService::ERROR_VERIFY,
            $allowlist['error'],
        );
    }

    public function testApplyWithoutDurableSamplesIsRejected(): void
    {
        $empty = CustomerAssetMigrationService::forTesting();
        $result = $empty->apply(
            $this->cloneDb('mig_clone_p4dasset_empty'),
            0,
        );

        self::assertFalse($result['ok']);
        self::assertSame(
            CustomerAssetMigrationService::ERROR_NO_SAMPLE,
            $result['error'],
        );
        self::assertSame(0, $result['sample_count']);
    }

    public function testRolloutGateRejectsBroadOrImplicitProductionEnablement(): void
    {
        $gate = CustomerAssetRolloutGate::forTestingConfiguration();
        self::assertSame(
            CommerceRolloutGateInterface::MODE_OFF,
            $gate->mode(CustomerAssetMigrationService::CAPABILITY),
        );

        $gate->setMode(
            CustomerAssetMigrationService::CAPABILITY,
            CommerceRolloutGateInterface::MODE_ALLOWLIST,
            ['website:0'],
        );
        self::assertTrue($gate->isEffectivelyOn(
            CustomerAssetMigrationService::CAPABILITY,
            CustomerAssetRolloutGate::scopeKey(0),
        ));
        self::assertFalse($gate->isEffectivelyOn(
            CustomerAssetMigrationService::CAPABILITY,
            CustomerAssetRolloutGate::scopeKey(1),
        ));

        try {
            $gate->setMode(
                CustomerAssetMigrationService::CAPABILITY,
                CommerceRolloutGateInterface::MODE_ALLOWLIST,
                ['website:*'],
            );
            self::fail('Expected exact Website subject rejection');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringContainsString(
                'customer_asset_rollout_subject_invalid',
                $exception->getMessage(),
            );
        }

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('commerce_rollout_on_requires_explicit_token');
        $gate->setMode(
            CustomerAssetMigrationService::CAPABILITY,
            CommerceRolloutGateInterface::MODE_ON,
        );
    }

    private function seedValidFacts(CustomerAssetMigrationService $service): void
    {
        [$accounts, $ledger, $reservations] = $this->validFacts();
        $service->seedFacts($accounts, $ledger, $reservations);
    }

    /**
     * @return array{
     *   0:list<array<string,mixed>>,
     *   1:list<array<string,mixed>>,
     *   2:list<array<string,mixed>>
     * }
     */
    private function validFacts(): array
    {
        $identity = [
            'account_id' => 'acct-asset-1',
            'customer_id' => 'customer-1',
            'website_id' => 0,
            'asset_code' => 'credit',
            'namespace' => AssetAccount::NS_LIVE,
        ];

        return [
            [[
                ...$identity,
                'available_minor' => 1000,
                'reserved_minor' => 300,
                'version' => 2,
                'cas_token' => str_repeat('a', 64),
            ]],
            [
                [
                    'entry_id' => 'entry-credit-1',
                    'event_id' => 'event-credit-1',
                    ...$identity,
                    'event_type' => AssetLedger::TYPE_CREDIT,
                    'amount_minor' => 1000,
                    'reservation_id' => null,
                    'request_hash' => str_repeat('b', 64),
                    'balance_after_available' => 1000,
                    'balance_after_reserved' => 0,
                    'account_version' => 1,
                    'meta_json' => '{}',
                ],
                [
                    'entry_id' => 'entry-reserve-1',
                    'event_id' => 'event-reserve-1',
                    ...$identity,
                    'event_type' => AssetLedger::TYPE_RESERVE,
                    'amount_minor' => 300,
                    'reservation_id' => 'reservation-1',
                    'request_hash' => str_repeat('c', 64),
                    'balance_after_available' => 1000,
                    'balance_after_reserved' => 300,
                    'account_version' => 2,
                    'meta_json' => '{}',
                ],
            ],
            [[
                'reservation_id' => 'reservation-1',
                ...$identity,
                'reserve_event_id' => 'event-reserve-1',
                'reserve_request_hash' => str_repeat('c', 64),
                'amount_minor' => 300,
                'returned_amount_minor' => 0,
                'status' => AssetReservation::STATUS_RESERVED,
                'version' => 1,
                'cas_token' => str_repeat('d', 64),
                'terminal_event_id' => null,
                'terminal_request_hash' => null,
            ]],
        ];
    }

    /**
     * @return array{
     *   0:list<array<string,mixed>>,
     *   1:list<array<string,mixed>>,
     *   2:list<array<string,mixed>>
     * }
     */
    private function committedFacts(): array
    {
        [$accounts, $ledger, $reservations] = $this->validFacts();
        $identity = [
            'account_id' => 'acct-asset-1',
            'customer_id' => 'customer-1',
            'website_id' => 0,
            'asset_code' => 'credit',
            'namespace' => AssetAccount::NS_LIVE,
        ];
        $accounts[0]['available_minor'] = 800;
        $accounts[0]['reserved_minor'] = 0;
        $accounts[0]['version'] = 4;
        $ledger[] = [
            'entry_id' => 'entry-commit-1',
            'event_id' => 'event-commit-1',
            ...$identity,
            'event_type' => AssetLedger::TYPE_COMMIT,
            'amount_minor' => 300,
            'reservation_id' => 'reservation-1',
            'request_hash' => str_repeat('d', 64),
            'balance_after_available' => 700,
            'balance_after_reserved' => 0,
            'account_version' => 3,
            'meta_json' => '{}',
        ];
        $ledger[] = [
            'entry_id' => 'entry-return-1',
            'event_id' => 'event-return-1',
            ...$identity,
            'event_type' => AssetLedger::TYPE_RETURN,
            'amount_minor' => 100,
            'reservation_id' => 'reservation-1',
            'request_hash' => str_repeat('e', 64),
            'balance_after_available' => 800,
            'balance_after_reserved' => 0,
            'account_version' => 4,
            'meta_json' => '{}',
        ];
        $reservations[0]['status'] = AssetReservation::STATUS_COMMITTED;
        $reservations[0]['returned_amount_minor'] = 100;
        $reservations[0]['version'] = 3;
        $reservations[0]['cas_token'] = str_repeat('f', 64);
        $reservations[0]['terminal_event_id'] = 'event-commit-1';
        $reservations[0]['terminal_request_hash'] = str_repeat('d', 64);

        return [$accounts, $ledger, $reservations];
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
