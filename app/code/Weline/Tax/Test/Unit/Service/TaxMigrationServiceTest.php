<?php

declare(strict_types=1);

namespace Weline\Tax\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\SystemConfig\Api\CommerceRolloutGateInterface;
use Weline\Tax\Service\TaxEngine;
use Weline\Tax\Service\TaxLkgStore;
use Weline\Tax\Service\TaxMigrationService;
use Weline\Tax\Service\TaxRolloutGate;

/**
 * TASK-MIG-P3B: durable checkpoint/shadow/verify/allowlist/rollback.
 */
final class TaxMigrationServiceTest extends TestCase
{
    private string $journalDir;
    private TaxRolloutGate $rollout;
    private TaxLkgStore $lkg;
    private TaxMigrationService $service;

    protected function setUp(): void
    {
        $this->journalDir = sys_get_temp_dir() . '/tax-mig-test-' . bin2hex(random_bytes(5));
        $this->rollout = TaxRolloutGate::forTestingConfiguration([
            'mode' => CommerceRolloutGateInterface::MODE_SHADOW,
            'allowlist' => [],
            'shadow_sample_bp' => 10000,
        ]);
        $this->lkg = TaxLkgStore::forTesting();
        $this->service = TaxMigrationService::forTesting(
            $this->journalDir,
            $this->rollout,
            $this->lkg,
        );
        $this->seedObservationWindow($this->service, 100);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->journalDir . '/*.json') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->journalDir);
    }

    public function testPreflightRequiresIsolatedCloneAndExactScope(): void
    {
        try {
            $this->service->preflight(null, 0, 1, 1);
            self::fail('shared database preflight was accepted');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString(
                TaxMigrationService::ERROR_SHARED_DB,
                $exception->getMessage(),
            );
        }

        $preflight = $this->service->preflight(
            $this->cloneDb('mig_clone_p3btax_preflight'),
            0,
            1,
            1,
        );
        self::assertTrue($preflight['ok']);
        self::assertSame(100, $preflight['sample_count']);
        self::assertSame(100, $preflight['required_sample_count']);
        self::assertSame(CommerceRolloutGateInterface::MODE_SHADOW, $preflight['mode']);
        self::assertTrue($preflight['apply_ready']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $preflight['request_set_hash']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $preflight['rule_snapshot_hash']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(TaxMigrationService::ERROR_SCOPE);
        $this->service->preflight(
            $this->cloneDb('mig_clone_p3btax_bad_scope'),
            0,
            0,
            1,
        );
    }

    public function testApplyStaysShadowThenFreshServiceVerifiesAndAllowlists(): void
    {
        $db = $this->cloneDb('mig_clone_p3btax_fresh');
        $apply = $this->service->apply($db, 0, 1, 1);

        self::assertTrue($apply['ok']);
        self::assertSame(CommerceRolloutGateInterface::MODE_SHADOW, $apply['mode']);
        self::assertFalse($apply['allowlist_ready']);
        self::assertSame(100, $apply['report']['quote_count']);
        self::assertSame(0, $apply['report']['classified_diff_count']);
        self::assertSame(0, $apply['report']['unclassified_diff_count']);
        self::assertTrue($apply['report']['conserved']);
        self::assertLessThanOrEqual(1, $apply['report']['max_line_rounding_drift']);
        self::assertNotNull($apply['report']['lkg_id']);
        self::assertNotSame('', $apply['checkpoint_id']);

        $fresh = TaxMigrationService::forTesting(
            $this->journalDir,
            $this->rollout,
            $this->lkg,
        );
        $this->seedObservationWindow($fresh, 100);
        $verify = $fresh->verify($db, $apply['checkpoint_id']);
        self::assertTrue($verify['ok'], json_encode($verify['diffs']));
        self::assertSame(0, $verify['diff_count']);
        self::assertSame(CommerceRolloutGateInterface::MODE_SHADOW, $verify['mode']);
        self::assertTrue($verify['lkg_retained']);
        self::assertTrue($verify['snapshots_immutable']);

        $allowlist = $fresh->allowlist($db, $apply['checkpoint_id'], 0, 1, 1);
        self::assertTrue($allowlist['ok']);
        self::assertSame(CommerceRolloutGateInterface::MODE_ALLOWLIST, $allowlist['mode']);
        self::assertSame(
            [['website_id' => 0, 'store_id' => 1, 'channel_id' => 1]],
            $allowlist['allowlist'],
        );
        self::assertTrue($this->rollout->isEffectivelyOn('tax', '0:1:1'));
        self::assertFalse($this->rollout->isEffectivelyOn('tax', '0:1:2'));
    }

    public function testAllowlistRejectsScopeDifferentFromCheckpoint(): void
    {
        $db = $this->cloneDb('mig_clone_p3btax_scope');
        $apply = $this->service->apply($db, 0, 1, 1);
        self::assertTrue($apply['ok']);

        $result = $this->service->allowlist($db, $apply['checkpoint_id'], 0, 1, 2);
        self::assertFalse($result['ok']);
        self::assertSame(TaxMigrationService::ERROR_SCOPE_MISMATCH, $result['error']);
        self::assertSame(CommerceRolloutGateInterface::MODE_SHADOW, $this->rollout->mode('tax'));
    }

    public function testFreshVerifyFailsClosedWithoutSharedVerifiedLkg(): void
    {
        $db = $this->cloneDb('mig_clone_p3btax_no_lkg');
        $apply = $this->service->apply($db, 0, 1, 1);
        self::assertTrue($apply['ok']);

        $fresh = TaxMigrationService::forTesting(
            $this->journalDir,
            $this->rollout,
            TaxLkgStore::forTesting(),
        );
        $this->seedObservationWindow($fresh, 100);
        $verify = $fresh->verify($db, $apply['checkpoint_id']);

        self::assertFalse($verify['ok']);
        self::assertContains(['code' => TaxMigrationService::ERROR_LKG], $verify['diffs']);
    }

    public function testTamperedCheckpointFailsFreshVerification(): void
    {
        $db = $this->cloneDb('mig_clone_p3btax_tamper');
        $apply = $this->service->apply($db, 0, 1, 1);
        self::assertTrue($apply['ok']);

        $path = $this->journalDir . '/' . $apply['checkpoint_id'] . '.json';
        $row = json_decode((string)file_get_contents($path), true, 128, JSON_THROW_ON_ERROR);
        $row['manifest']['row_hashes']['tax_shadow_request_set'] = str_repeat('0', 64);
        file_put_contents(
            $path,
            json_encode($row, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR),
        );

        $fresh = TaxMigrationService::forTesting(
            $this->journalDir,
            $this->rollout,
            $this->lkg,
        );
        $this->seedObservationWindow($fresh, 100);
        $verify = $fresh->verify($db, $apply['checkpoint_id']);

        self::assertFalse($verify['ok']);
        self::assertSame('migration_manifest_tampered', $verify['error']);
    }

    public function testRollbackPersistsOffAndRetainsLkgSnapshotsCheckpoint(): void
    {
        $db = $this->cloneDb('mig_clone_p3btax_rollback');
        $apply = $this->service->apply($db, 0, 1, 1);
        self::assertTrue($apply['ok']);
        self::assertTrue(
            $this->service->allowlist($db, $apply['checkpoint_id'], 0, 1, 1)['ok'],
        );

        $rollback = $this->service->rollbackToModeOff($db, $apply['checkpoint_id']);
        self::assertTrue($rollback['ok']);
        self::assertSame(CommerceRolloutGateInterface::MODE_OFF, $rollback['mode']);
        self::assertSame([], $rollback['allowlist']);
        self::assertTrue($rollback['lkg_retained']);
        self::assertTrue($rollback['snapshots_immutable']);
        self::assertTrue($rollback['checkpoint_retained']);
        self::assertFileExists($this->journalDir . '/' . $apply['checkpoint_id'] . '.json');
    }

    public function testApplyWithoutCompleteUniqueObservationWindowIsRejected(): void
    {
        $empty = TaxMigrationService::forTesting();
        $result = $empty->apply(
            $this->cloneDb('mig_clone_p3btax_empty'),
            0,
            1,
            1,
        );
        self::assertFalse($result['ok']);
        self::assertSame(TaxMigrationService::ERROR_NO_SAMPLE, $result['error']);

        $duplicate = TaxMigrationService::forTesting();
        $request = $this->request(1);
        for ($index = 0; $index < 100; $index++) {
            $duplicate->seedShadowQuote($request);
        }
        $result = $duplicate->apply(
            $this->cloneDb('mig_clone_p3btax_duplicate'),
            0,
            1,
            1,
        );
        self::assertFalse($result['ok']);
        self::assertSame(TaxMigrationService::ERROR_MIXED_WINDOW, $result['error']);
    }

    private function seedObservationWindow(TaxMigrationService $service, int $count): void
    {
        for ($index = 0; $index < $count; $index++) {
            $service->seedShadowQuote($this->request($index));
        }
    }

    /** @return array<string,mixed> */
    private function request(int $index): array
    {
        $class = $index % 2 === 0 ? 'standard' : 'reduced';
        $jurisdiction = $index % 5 === 0 ? 'US|CA' : 'CN|';
        if ($jurisdiction === 'US|CA') {
            $class = 'standard';
        }

        return [
            'website_id' => 0,
            'store_id' => 1,
            'currency' => $jurisdiction === 'US|CA' ? 'USD' : 'CNY',
            'jurisdiction_key' => $jurisdiction,
            'rule_schema_version' => TaxEngine::SCHEMA_VERSION,
            'lines' => [[
                'line_id' => 'mig-' . $index,
                'tax_class_code' => $class,
                'taxable_amount_minor' => 1000 + ($index * 13),
            ]],
        ];
    }

    /** @return array<string,string> */
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
