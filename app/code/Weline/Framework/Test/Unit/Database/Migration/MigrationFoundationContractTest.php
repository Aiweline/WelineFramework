<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Database\Migration;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Console\Console\Mig\Foundation;
use Weline\Framework\Database\Migration\MigrationCloneHandle;
use Weline\Framework\Database\Migration\MigrationManifest;
use Weline\Framework\Database\Migration\Service\DatabaseFingerprintGuard;
use Weline\Framework\Database\Migration\Service\MigrationCheckpointJournalStore;
use Weline\Framework\Database\Migration\Service\MigrationCheckpointService;
use Weline\Framework\Database\Migration\Service\MigrationCloneRegistry;
use Weline\Framework\Database\Migration\Service\MigrationCloneService;
use Weline\SystemConfig\Api\CommerceRolloutGateInterface;
use Weline\SystemConfig\Service\CommerceRolloutGate;

/**
 * TEST-MIG-FOUNDATION-01 / TEST-MIG-FOUNDATION-02 核心语义（无共享库写入）。
 */
final class MigrationFoundationContractTest extends TestCase
{
    public function testFoundationCliFailureReturnsNonZeroStatus(): void
    {
        $command = \Weline\Framework\Manager\ObjectManager::getInstance(Foundation::class);

        self::assertSame(2, $command->execute(['mig:foundation', 'journal-verify']));
    }

    public function testSharedWelineDatabaseIsHardRejected(): void
    {
        $guard = new DatabaseFingerprintGuard();
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('migration_db_denied');
        $guard->assertIsolatedDatabase([
            'type' => 'pgsql',
            'hostname' => '127.0.0.1',
            'hostport' => '5432',
            'database' => 'weline',
            'username' => 'weline',
        ]);
    }

    public function testCloneDatabaseNameIsAcceptedWhenAllowlisted(): void
    {
        $config = [
            'type' => 'pgsql',
            'hostname' => '127.0.0.1',
            'hostport' => '5432',
            'database' => 'mig_clone_p1b_20260724',
            'username' => 'weline',
        ];
        $guard = new DatabaseFingerprintGuard();
        $fp = $guard->fingerprint($config);
        $guard = new DatabaseFingerprintGuard(allowlistFingerprints: [$fp]);
        self::assertSame($fp, $guard->assertIsolatedDatabase($config));
    }

    public function testTamperedManifestRejectsApplyWithZeroBusinessWrite(): void
    {
        $svc = new MigrationCheckpointService(new DatabaseFingerprintGuard());
        $clone = [
            'type' => 'pgsql',
            'hostname' => '127.0.0.1',
            'hostport' => '5432',
            'database' => 'mig_clone_foundation_test',
            'username' => 'weline',
        ];
        $fp = (new DatabaseFingerprintGuard())->fingerprint($clone);
        // 无 allowlist 时只要命名合规即可通过 assertIsolated
        $manifest = MigrationManifest::fromArray([
            'checkpoint_id' => 'cp-1',
            'phase' => 'p1a-preflight',
            'repo' => 'framework',
            'branch' => 'master',
            'commit' => 'abc',
            'connector_fingerprint' => $fp,
            'schema_fingerprints' => ['t' => 'h1'],
            'row_counts' => ['t' => 1],
            'row_hashes' => ['t' => 'rh1'],
            'watermarks' => ['queue' => 0],
            'backup_ref' => 'none',
            'created_at' => '2026-07-24T00:00:00Z',
        ]);
        $hash = $svc->checkpoint($manifest);
        self::assertNotSame('', $hash);

        $tampered = $manifest->withTamper('commit', 'evil');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('migration_manifest_tampered');
        $svc->applyGuard($clone, 'cp-1', $tampered);
    }

    public function testValidManifestApplyGuardAppendsJournal(): void
    {
        $svc = new MigrationCheckpointService(new DatabaseFingerprintGuard());
        $clone = [
            'type' => 'pgsql',
            'hostname' => '127.0.0.1',
            'hostport' => '5432',
            'database' => 'test_mig_foundation',
            'username' => 'weline',
        ];
        $fp = (new DatabaseFingerprintGuard())->fingerprint($clone);
        $manifest = MigrationManifest::fromArray([
            'checkpoint_id' => 'cp-2',
            'phase' => 'p1a-apply',
            'repo' => 'framework',
            'branch' => 'master',
            'commit' => 'def',
            'connector_fingerprint' => $fp,
            'schema_fingerprints' => [],
            'row_counts' => [],
            'row_hashes' => [],
            'watermarks' => [],
            'backup_ref' => 'bak-1',
            'created_at' => '2026-07-24T01:00:00Z',
        ]);
        $svc->checkpoint($manifest);
        $svc->applyGuard($clone, 'cp-2', $manifest);
        $journal = $svc->journal('cp-2');
        self::assertGreaterThanOrEqual(2, \count($journal));
        self::assertSame('apply_guard_passed', $journal[\count($journal) - 1]['event']);
    }

    public function testPersistentJournalSurvivesFreshConnection(): void
    {
        $dir = \sys_get_temp_dir() . '/mig_journal_' . \uniqid('', true);
        $store = new MigrationCheckpointJournalStore($dir);
        $guard = new DatabaseFingerprintGuard();
        $svc = new MigrationCheckpointService($guard, $store);
        $clone = [
            'type' => 'pgsql',
            'hostname' => '127.0.0.1',
            'hostport' => '5432',
            'database' => 'mig_clone_journal_unit',
            'username' => 'weline',
        ];
        $fp = $guard->fingerprint($clone);
        $manifest = MigrationManifest::fromArray([
            'checkpoint_id' => 'cp-persist-1',
            'phase' => 'foundation-journal',
            'repo' => 'framework',
            'branch' => 'master',
            'commit' => 'persist',
            'connector_fingerprint' => $fp,
            'schema_fingerprints' => ['t' => 'h'],
            'row_counts' => ['t' => 2],
            'row_hashes' => ['t' => 'rh'],
            'watermarks' => [],
            'backup_ref' => 'bak',
            'created_at' => '2026-07-24T02:00:00Z',
        ]);
        $hash = $svc->checkpoint($manifest);
        $svc->applyGuard($clone, 'cp-persist-1', $manifest);

        $fresh = new MigrationCheckpointService($guard, new MigrationCheckpointJournalStore($dir));
        self::assertTrue($fresh->hasCheckpoint('cp-persist-1'));
        self::assertSame($hash, $fresh->manifestHash('cp-persist-1'));
        $fresh->assertManifestUntampered('cp-persist-1', $manifest);
        $journal = $fresh->journal('cp-persist-1');
        self::assertGreaterThanOrEqual(2, \count($journal));

        $verify = $svc->verifyFresh('cp-persist-1');
        self::assertTrue($verify['ok']);
        self::assertSame($hash, $verify['manifest_hash']);
        self::assertSame('apply_guard_passed', $verify['last_event']);

        foreach (\glob($dir . '/*') ?: [] as $f) {
            @\unlink($f);
        }
        @\rmdir($dir);
    }

    public function testTamperedPersistedManifestFailsFreshVerify(): void
    {
        $dir = \sys_get_temp_dir() . '/mig_journal_' . \uniqid('', true);
        $store = new MigrationCheckpointJournalStore($dir);
        $guard = new DatabaseFingerprintGuard();
        $svc = new MigrationCheckpointService($guard, $store);
        $manifest = MigrationManifest::fromArray([
            'checkpoint_id' => 'cp-tamper-1',
            'phase' => 'x',
            'repo' => 'r',
            'branch' => 'b',
            'commit' => 'c1',
            'connector_fingerprint' => 'fp',
            'schema_fingerprints' => [],
            'row_counts' => [],
            'row_hashes' => [],
            'watermarks' => [],
            'backup_ref' => '',
            'created_at' => '2026-07-24T00:00:00Z',
        ]);
        $svc->checkpoint($manifest);
        $path = $dir . '/cp-tamper-1.json';
        $raw = \file_get_contents($path);
        self::assertNotFalse($raw);
        $data = \json_decode($raw, true, 64, \JSON_THROW_ON_ERROR);
        $data['manifest']['commit'] = 'evil';
        \file_put_contents($path, \json_encode($data, \JSON_THROW_ON_ERROR));

        $verify = $svc->verifyFresh('cp-tamper-1');
        self::assertFalse($verify['ok']);
        self::assertSame('migration_manifest_tampered', $verify['error']);

        foreach (\glob($dir . '/*') ?: [] as $f) {
            @\unlink($f);
        }
        @\rmdir($dir);
    }

    public function testRolloutGateDefaultsOffAndRejectsUnknownMode(): void
    {
        $gate = new CommerceRolloutGate();
        self::assertSame(CommerceRolloutGateInterface::MODE_OFF, $gate->mode('queue_envelope'));
        $this->expectException(\RuntimeException::class);
        $gate->assertMutable('queue_envelope');
    }

    public function testRolloutOnRequiresExplicitToken(): void
    {
        $gate = new CommerceRolloutGate();
        $this->expectException(\InvalidArgumentException::class);
        $gate->setMode('payment', CommerceRolloutGateInterface::MODE_ON);
    }

    public function testManifestHashChangesWhenFieldChanges(): void
    {
        $base = MigrationManifest::fromArray([
            'checkpoint_id' => 'cp-h',
            'phase' => 'x',
            'repo' => 'r',
            'branch' => 'b',
            'commit' => 'c1',
            'connector_fingerprint' => 'fp',
            'schema_fingerprints' => [],
            'row_counts' => [],
            'row_hashes' => [],
            'watermarks' => [],
            'backup_ref' => '',
            'created_at' => '2026-07-24T00:00:00Z',
        ]);
        $other = $base->withTamper('commit', 'c2');
        self::assertNotSame($base->hash(), $other->hash());
    }

    public function testDestroyRefusesSharedDatabaseName(): void
    {
        $svc = new MigrationCloneService(
            new MigrationCloneRegistry(\sys_get_temp_dir() . '/mig_clone_reg_' . \uniqid()),
            new DatabaseFingerprintGuard(),
        );
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('migration_clone_destroy_refused');
        $svc->destroy('weline');
    }

    public function testFullCloneStreamsDumpThroughTemporaryFile(): void
    {
        $root = \sys_get_temp_dir() . '/mig_clone_stream_' . \uniqid('', true);
        $bin = $root . '/bin';
        $registryDir = $root . '/registry';
        $restoreMarker = $root . '/restore.marker';
        self::assertTrue(\mkdir($bin, 0777, true));

        $scripts = [
            'createdb' => <<<'SH'
#!/bin/sh
exit 0
SH,
            'pg_dump' => <<<'SH'
#!/bin/sh
dump_file=''
while [ "$#" -gt 0 ]; do
    if [ "$1" = '--file' ] && [ "$#" -gt 1 ]; then
        shift
        dump_file="$1"
    fi
    shift
done
if [ -z "$dump_file" ]; then
    echo 'stream_file_required' >&2
    exit 41
fi
printf 'streamed-dump' > "$dump_file"
SH,
            'psql' => <<<'SH'
#!/bin/sh
dump_file=''
while [ "$#" -gt 0 ]; do
    if [ "$1" = '-f' ] && [ "$#" -gt 1 ]; then
        shift
        dump_file="$1"
    fi
    shift
done
if [ ! -f "$dump_file" ]; then
    echo 'restore_file_missing' >&2
    exit 42
fi
cp "$dump_file" "$MIG_TEST_RESTORE_MARKER"
SH,
            'dropdb' => <<<'SH'
#!/bin/sh
exit 0
SH,
        ];
        foreach ($scripts as $name => $script) {
            $path = $bin . '/' . $name;
            self::assertNotFalse(\file_put_contents($path, $script));
            self::assertTrue(\chmod($path, 0755));
        }

        $previousEnvPath = $_ENV['PATH'] ?? null;
        $previousServerPath = $_SERVER['PATH'] ?? null;
        $previousProcessPath = \getenv('PATH');
        $previousMarker = $_ENV['MIG_TEST_RESTORE_MARKER'] ?? null;
        $previousServerMarker = $_SERVER['MIG_TEST_RESTORE_MARKER'] ?? null;
        $_ENV['PATH'] = $bin . ':' . (string)($previousEnvPath ?? \getenv('PATH'));
        $_SERVER['PATH'] = $_ENV['PATH'];
        \putenv('PATH=' . $_ENV['PATH']);
        $_ENV['MIG_TEST_RESTORE_MARKER'] = $restoreMarker;
        $_SERVER['MIG_TEST_RESTORE_MARKER'] = $restoreMarker;

        $registry = new MigrationCloneRegistry($registryDir);
        try {
            $handle = (new MigrationCloneService($registry, new DatabaseFingerprintGuard()))->create(
                [
                    'type' => 'pgsql',
                    'hostname' => '127.0.0.1',
                    'hostport' => '5432',
                    'database' => 'weline',
                    'username' => 'weline',
                ],
                MigrationCloneService::MODE_FULL,
                'streamtest',
                'phpunit',
            );

            self::assertSame('streamed-dump', \file_get_contents($restoreMarker));
            self::assertSame(MigrationCloneService::MODE_FULL, $handle->mode);
        } finally {
            if ($previousEnvPath === null) {
                unset($_ENV['PATH']);
            } else {
                $_ENV['PATH'] = $previousEnvPath;
            }
            if ($previousServerPath === null) {
                unset($_SERVER['PATH']);
            } else {
                $_SERVER['PATH'] = $previousServerPath;
            }
            $previousProcessPath === false
                ? \putenv('PATH')
                : \putenv('PATH=' . $previousProcessPath);
            if ($previousMarker === null) {
                unset($_ENV['MIG_TEST_RESTORE_MARKER']);
            } else {
                $_ENV['MIG_TEST_RESTORE_MARKER'] = $previousMarker;
            }
            if ($previousServerMarker === null) {
                unset($_SERVER['MIG_TEST_RESTORE_MARKER']);
            } else {
                $_SERVER['MIG_TEST_RESTORE_MARKER'] = $previousServerMarker;
            }
            foreach (\glob($registryDir . '/*') ?: [] as $file) {
                @\unlink($file);
            }
            foreach (\glob($bin . '/*') ?: [] as $file) {
                @\unlink($file);
            }
            @\unlink($restoreMarker);
            @\rmdir($registryDir);
            @\rmdir($bin);
            @\rmdir($root);
        }
    }

    public function testDestroyFailureIsReportedAndKeepsRegistryEntry(): void
    {
        $dir = \sys_get_temp_dir() . '/mig_clone_reg_' . \uniqid('', true);
        $registry = new MigrationCloneRegistry($dir);
        $database = 'test_mig_destroy_failure_' . \bin2hex(\random_bytes(2));
        $config = [
            'type' => 'pgsql',
            'hostname' => '127.0.0.1',
            'hostport' => '1',
            'database' => $database,
            'username' => 'weline',
        ];
        $registry->register(new MigrationCloneHandle(
            cloneId: 'mig_destroy_failure',
            database: $database,
            fingerprint: (new DatabaseFingerprintGuard())->fingerprint($config),
            mode: 'schema',
            sourceDatabase: 'weline',
            createdAt: \gmdate('c'),
            owner: 'phpunit',
            config: $config,
            createCommand: 'createdb',
            destroyCommand: 'dropdb',
        ));
        $svc = new MigrationCloneService($registry, new DatabaseFingerprintGuard());

        try {
            $svc->destroy($database);
            self::fail('Destroying through an unreachable PostgreSQL endpoint must fail.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('migration_clone_destroy_failed:' . $database, $e->getMessage());
            self::assertNotNull($registry->get($database));
        } finally {
            $registry->forget($database);
            @\rmdir($dir);
        }
    }

    public function testRegistryAllowlistUnlocksGuardedPreflight(): void
    {
        $dir = \sys_get_temp_dir() . '/mig_clone_reg_' . \uniqid('', true);
        $registry = new MigrationCloneRegistry($dir);
        $config = [
            'type' => 'pgsql',
            'hostname' => '127.0.0.1',
            'hostport' => '5432',
            'database' => 'mig_clone_unit_' . \bin2hex(\random_bytes(2)),
            'username' => 'weline',
        ];
        $fp = (new DatabaseFingerprintGuard())->fingerprint($config);
        $registry->register(new MigrationCloneHandle(
            cloneId: 'mig_unit',
            database: $config['database'],
            fingerprint: $fp,
            mode: 'schema',
            sourceDatabase: 'weline',
            createdAt: \gmdate('c'),
            owner: 'phpunit',
            config: $config,
            createCommand: 'createdb',
            destroyCommand: 'dropdb',
        ));
        $svc = new MigrationCloneService($registry, new DatabaseFingerprintGuard());
        $preflight = (new MigrationCheckpointService($svc->guardedFingerprint()))->preflight($config);
        self::assertTrue($preflight['ok']);
        self::assertSame($fp, $preflight['fingerprint']);
        $registry->forget($config['database']);
        @\rmdir($dir);
    }
}
