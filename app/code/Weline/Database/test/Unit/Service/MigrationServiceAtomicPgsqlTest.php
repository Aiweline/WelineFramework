<?php

declare(strict_types=1);

namespace Weline\Database\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Database\Model\Migration;
use Weline\Database\Service\BackupService;
use Weline\Database\Service\MigrationService;
use Weline\Framework\Database\Connection\Api\ConnectorInterface;
use Weline\Framework\Database\Connection\Api\PhysicalTableIdentity;
use Weline\Framework\Database\Connection\Api\PhysicalTableIdentityProviderInterface;
use Weline\Framework\Database\Connection\Api\PhysicalTableMetadataInterface;
use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Setup\Model\MigrationBackup;

final class MigrationServiceAtomicPgsqlTest extends TestCase
{
    private const CALLBACK_FIXTURE = 'atomic_callback_gate_fixture_20260811-v1.0.0.php';

    public function testDeclaredBackupAndInstallDdlShareOneExactTableLock(): void
    {
        $factory = ObjectManager::getInstance(ConnectionFactory::class);
        $connector = $factory->getConnector();
        self::assertSame('pgsql', strtolower($connector->getConfigProvider()->getDbType()));
        self::assertInstanceOf(PhysicalTableMetadataInterface::class, $connector);
        self::assertInstanceOf(PhysicalTableIdentityProviderInterface::class, $connector);
        $metadata = $connector;
        $module = 'Weline_AtomicMigrationServiceTest';
        $logicalTable = 'migration_atomic_' . bin2hex(random_bytes(5));
        $identity = $connector->resolvePhysicalTableIdentity($logicalTable);
        $fixtureDirectory = BP . 'app/code/Weline/Test/Setup/Db/Migration/';
        $fixture = $fixtureDirectory . 'atomic_backup_gap_fixture_20260811-v1.0.0.php';
        $fixtureClass = 'Weline\\Test\\Setup\\Db\\Migration\\AtomicBackupGapFixture20260811V100';
        $migration = ObjectManager::getInstance(Migration::class, [], false);
        $backup = ObjectManager::getInstance(BackupService::class, [], false);
        $process = null;
        $pipes = [];

        $connector->query(
            'CREATE TABLE ' . $metadata->quotePhysicalTable($identity)
            . ' (id INTEGER PRIMARY KEY, legacy_value TEXT NULL)',
        )->fetch();
        $connector->query(
            'INSERT INTO ' . $metadata->quotePhysicalTable($identity)
            . " (id, legacy_value) VALUES (1, 'before')",
        )->fetch();
        if (!is_dir($fixtureDirectory)) {
            mkdir($fixtureDirectory, 0755, true);
        }
        file_put_contents($fixture, <<<'PHP'
<?php
namespace Weline\Test\Setup\Db\Migration;

use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Database\Migration\AbstractMigration;

final class AtomicBackupGapFixture20260811V100 extends AbstractMigration
{
    public static mixed $beforeDdl = null;

    public function __construct(private ConnectionFactory $connectionFactory) {}

    public function install(): bool
    {
        if (is_callable(self::$beforeDdl)) {
            (self::$beforeDdl)();
        }
        $table = (string)getenv('WELINE_TEST_ATOMIC_MIGRATION_TABLE');
        $connector = $this->connectionFactory->getConnector();
        $connector->query($connector->buildAlterDropColumnSql(
            $connector->formatTableName($table),
            'legacy_value',
        ))->fetch();
        return true;
    }

    public function uninstall(): bool { return true; }
    public function getVersion(): string { return '1.0.0'; }
    public function getDescription(): string { return 'atomic backup gap fixture'; }
    public function requiresBackup(): bool { return true; }
    public function getAffectedTables(): array
    {
        return [(string)getenv('WELINE_TEST_ATOMIC_MIGRATION_TABLE')];
    }
    public function getBackupStrategy(): array
    {
        return [
            'strategy' => 'column',
            'tables' => [(string)getenv('WELINE_TEST_ATOMIC_MIGRATION_TABLE')],
            'columns' => ['legacy_value'],
        ];
    }
}
PHP
        );
        putenv('WELINE_TEST_ATOMIC_MIGRATION_TABLE=' . $logicalTable);
        require_once $fixture;
        [$process, $pipes] = $this->startConcurrentUpdate($connector, $metadata, $identity);
        $completedBeforeDdl = false;
        $childResult = null;
        $fixtureClass::$beforeDdl = function () use (
            $pipes,
            &$completedBeforeDdl,
            &$childResult,
        ): void {
            fwrite($pipes[0], "go\n");
            fflush($pipes[0]);
            $read = [$pipes[1]];
            $write = [];
            $except = [];
            if (stream_select($read, $write, $except, 0, 300_000) > 0) {
                $completedBeforeDdl = true;
                $childResult = trim((string)fgets($pipes[1]));
            }
        };

        try {
            $service = ObjectManager::getInstance(MigrationService::class, [], false);
            self::assertTrue($service->upgradeMigration($module, $fixture));
            if ($childResult === null) {
                stream_set_timeout($pipes[1], 5);
                $childResult = trim((string)fgets($pipes[1]));
            }
            $stderr = stream_get_contents($pipes[2]);
            $status = proc_close($process);
            $process = null;

            self::assertFalse(
                $completedBeforeDdl,
                'concurrent writer committed after migration backup but before install DDL',
            );
            self::assertSame('error', $childResult, (string)$stderr);
            self::assertSame(0, $status, (string)$stderr);
            self::assertNotContains(
                'legacy_value',
                array_column($metadata->getPhysicalTableColumns($identity), 'name'),
            );
        } finally {
            $fixtureClass::$beforeDdl = null;
            foreach ($pipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            if (is_resource($process)) {
                proc_terminate($process);
                proc_close($process);
            }
            $records = (clone $migration)->reset()
                ->where(Migration::schema_fields_MODULE, $module)
                ->select()
                ->fetch()
                ->getItems();
            foreach ($records as $record) {
                $backup->cleanupBackupData((int)$record->getId());
            }
            (clone $migration)->reset()
                ->where(Migration::schema_fields_MODULE, $module)
                ->delete()
                ->fetch();
            $connector->dropPhysicalTableIfExists($identity);
            putenv('WELINE_TEST_ATOMIC_MIGRATION_TABLE');
            if (is_file($fixture)) {
                unlink($fixture);
            }
        }
    }

    public function testInstallFalseThrowsInsideAtomicCallbackAndRollsBackDdlAndBackup(): void
    {
        [, $connector, $metadata, $identity, $logicalTable] = $this->createCallbackFixtureTable();
        $fixture = $this->writeCallbackFixture();
        $module = 'Weline_AtomicCallbackInstallFalse';
        putenv('WELINE_TEST_ATOMIC_CALLBACK_TABLE=' . $logicalTable);
        putenv('WELINE_TEST_ATOMIC_CALLBACK_MODE=install_false');

        try {
            $service = ObjectManager::getInstance(MigrationService::class, [], false);
            self::assertFalse($service->upgradeMigration($module, $fixture));
            self::assertContains(
                'legacy_value',
                array_column($metadata->getPhysicalTableColumns($identity), 'name'),
            );
            $records = $this->migrationRecords($module);
            self::assertCount(1, $records);
            self::assertSame(Migration::STATUS_FAILED, $records[0]->getData(Migration::schema_fields_STATUS));
            self::assertSame([], ObjectManager::getInstance(BackupService::class, [], false)
                ->getBackupsByMigrationId((int)$records[0]->getId()));
        } finally {
            $this->cleanupCallbackFixture($connector, $identity, $module, $fixture);
        }
    }

    public function testModifyTableWithoutAffectedIdentityFailsBeforeInstallCallback(): void
    {
        [, $connector, $metadata, $identity, $logicalTable] = $this->createCallbackFixtureTable();
        $fixture = $this->writeCallbackFixture();
        $module = 'Weline_AtomicCallbackMissingIdentity';
        putenv('WELINE_TEST_ATOMIC_CALLBACK_TABLE=' . $logicalTable);
        putenv('WELINE_TEST_ATOMIC_CALLBACK_MODE=missing_identity');

        try {
            $service = ObjectManager::getInstance(MigrationService::class, [], false);
            self::assertFalse($service->upgradeMigration($module, $fixture));
            self::assertContains(
                'legacy_value',
                array_column($metadata->getPhysicalTableColumns($identity), 'name'),
            );
        } finally {
            $this->cleanupCallbackFixture($connector, $identity, $module, $fixture);
        }
    }

    public function testStrategyNoneUninstallFalseRollsBackDdlInsideRootAtomicTransaction(): void
    {
        [, $connector, $metadata, $identity, $logicalTable] = $this->createCallbackFixtureTable();
        $fixture = $this->writeCallbackFixture();
        $module = 'Weline_AtomicCallbackUninstallFalse';
        $operationId = 'op-uninstall-false';
        putenv('WELINE_TEST_ATOMIC_CALLBACK_TABLE=' . $logicalTable);
        putenv('WELINE_TEST_ATOMIC_CALLBACK_MODE=uninstall_false');
        require_once $fixture;
        $migration = ObjectManager::getInstance(Migration::class, [], false);
        $migrationId = $migration->recordMigration([
            'module_name' => $module,
            'version' => '1.0.0',
            'migration_file' => self::CALLBACK_FIXTURE,
            'description' => 'callback gate fixture',
            'status' => Migration::STATUS_INSTALLED,
            'dependencies' => [],
            'checksum' => hash_file('sha256', $fixture),
            'migration_type' => 'script',
            'operation_kind' => 'modify_table',
            'operation_id' => '',
        ]);
        self::assertGreaterThan(0, $migrationId);

        try {
            $service = ObjectManager::getInstance(MigrationService::class, [], false);
            try {
                $service->executeRollbackPlan($module, [[
                    'file' => $fixture,
                    'filename' => self::CALLBACK_FIXTURE,
                    'migration_id' => $migrationId,
                    'checksum' => hash_file('sha256', $fixture),
                    'rollback_backup_strategy' => [
                        'strategy' => 'none',
                        'tables' => [],
                        'columns' => [],
                        'reason' => 'DDL callback transaction probe',
                        'requires_forward_backup' => false,
                        'forward_backup_types' => [],
                    ],
                ]], $operationId);
                self::fail('false uninstall callback must abort rollback plan');
            } catch (\RuntimeException $exception) {
                self::assertStringContainsString('回滚迁移', $exception->getMessage());
            }
            self::assertContains(
                'legacy_value',
                array_column($metadata->getPhysicalTableColumns($identity), 'name'),
            );
        } finally {
            $this->cleanupCallbackFixture($connector, $identity, $module, $fixture);
        }
    }

    public function testRollbackPlanUsesExactMigrationIdInsteadOfLatestMatchingFilename(): void
    {
        [, $connector, $metadata, $identity, $logicalTable] = $this->createCallbackFixtureTable();
        $fixture = $this->writeCallbackFixture();
        require_once $fixture;
        $module = 'Weline_AtomicExactRollbackId';
        $operationId = 'op-exact-migration-id';
        putenv('WELINE_TEST_ATOMIC_CALLBACK_TABLE=' . $logicalTable);
        putenv('WELINE_TEST_ATOMIC_CALLBACK_MODE=uninstall_true');
        $migration = ObjectManager::getInstance(Migration::class, [], false);
        $base = [
            'module_name' => $module,
            'version' => '1.0.0',
            'migration_file' => self::CALLBACK_FIXTURE,
            'description' => 'exact rollback id fixture',
            'status' => Migration::STATUS_INSTALLED,
            'dependencies' => [],
            'checksum' => hash_file('sha256', $fixture),
            'migration_type' => 'script',
            'operation_kind' => 'modify_table',
            'operation_id' => '',
        ];
        $plannedId = $migration->recordMigration($base);
        $decoyId = $migration->recordMigration($base);
        self::assertGreaterThan(0, $plannedId);
        self::assertGreaterThan($plannedId, $decoyId);

        try {
            $completed = ObjectManager::getInstance(MigrationService::class, [], false)
                ->executeRollbackPlan($module, [[
                    'file' => $fixture,
                    'filename' => self::CALLBACK_FIXTURE,
                    'migration_id' => $plannedId,
                    'checksum' => hash_file('sha256', $fixture),
                    'rollback_backup_strategy' => [
                        'strategy' => 'none',
                        'tables' => [],
                        'columns' => [],
                        'reason' => 'DDL callback transaction probe',
                        'requires_forward_backup' => false,
                        'forward_backup_types' => [],
                    ],
                ]], $operationId);
            self::assertCount(1, $completed);

            $planned = $this->freshMigration($plannedId);
            self::assertSame(Migration::STATUS_ROLLED_BACK, $planned->getData(Migration::schema_fields_STATUS));
            self::assertSame($operationId, $planned->getData(Migration::schema_fields_OPERATION_ID));
            $decoy = $this->freshMigration($decoyId);
            self::assertSame(Migration::STATUS_INSTALLED, $decoy->getData(Migration::schema_fields_STATUS));
            self::assertSame('', $decoy->getData(Migration::schema_fields_OPERATION_ID));
            self::assertNotContains(
                'legacy_value',
                array_column($metadata->getPhysicalTableColumns($identity), 'name'),
            );
        } finally {
            $this->cleanupCallbackFixture($connector, $identity, $module, $fixture);
        }
    }

    public function testInstallStatusCasMismatchRollsBackDdlBeforeAuditBecomesFailed(): void
    {
        [, $connector, $metadata, $identity, $logicalTable] = $this->createCallbackFixtureTable();
        $fixture = $this->writeCallbackFixture();
        $module = 'Weline_AtomicInstallStatusCas';
        $trigger = 'weline_install_status_cas_' . bin2hex(random_bytes(4));
        $function = $trigger . '_fn';
        putenv('WELINE_TEST_ATOMIC_CALLBACK_TABLE=' . $logicalTable);
        putenv('WELINE_TEST_ATOMIC_CALLBACK_MODE=install_true');
        $this->createInstallStatusTamperTrigger($trigger, $function, $module);

        try {
            self::assertFalse(
                ObjectManager::getInstance(MigrationService::class, [], false)
                    ->upgradeMigration($module, $fixture),
            );
            self::assertContains(
                'legacy_value',
                array_column($metadata->getPhysicalTableColumns($identity), 'name'),
            );
            $records = $this->migrationRecords($module);
            self::assertCount(1, $records);
            self::assertSame(Migration::STATUS_FAILED, $records[0]->getData(Migration::schema_fields_STATUS));
        } finally {
            $this->dropMigrationTrigger($trigger, $function);
            $this->cleanupCallbackFixture($connector, $identity, $module, $fixture);
        }
    }

    public function testUpgradeLocksCanonicalHistoricalRestoreTargetBeforeInstallCallback(): void
    {
        [, $connector, $metadata, $identity, $logicalTable] = $this->createCallbackFixtureTable();
        self::assertInstanceOf(PhysicalTableIdentityProviderInterface::class, $connector);
        $restoreLogicalTable = 'migration_restore_union_' . bin2hex(random_bytes(5));
        $restoreIdentity = $connector->resolvePhysicalTableIdentity($restoreLogicalTable);
        $connector->query(
            'CREATE TABLE ' . $metadata->quotePhysicalTable($restoreIdentity)
            . ' (id INTEGER PRIMARY KEY, legacy_value TEXT NULL)',
        )->fetch();
        $connector->query(
            'INSERT INTO ' . $metadata->quotePhysicalTable($restoreIdentity)
            . " (id, legacy_value) VALUES (1, 'before')",
        )->fetch();
        $fixture = $this->writeCallbackFixture();
        require_once $fixture;
        $fixtureClass = 'Weline\\Test\\Setup\\Db\\Migration\\AtomicCallbackGateFixture20260811V100';
        $module = 'Weline_AtomicCanonicalRestoreLockUnion';
        putenv('WELINE_TEST_ATOMIC_CALLBACK_TABLE=' . $logicalTable);
        putenv('WELINE_TEST_ATOMIC_CALLBACK_MODE=install_true');
        $migration = ObjectManager::getInstance(Migration::class, [], false);
        $historicalId = $migration->recordMigration([
            'module_name' => $module,
            'version' => '1.0.0',
            'migration_file' => self::CALLBACK_FIXTURE,
            'description' => 'canonical restore lock union fixture',
            'status' => Migration::STATUS_ROLLED_BACK,
            'dependencies' => [],
            'checksum' => hash_file('sha256', $fixture),
            'migration_type' => 'script',
            'operation_kind' => 'modify_table',
            'operation_id' => 'historical-rollback-op',
        ]);
        self::assertGreaterThan(0, $historicalId);
        ObjectManager::getInstance(BackupService::class, [], false)->backupPhysicalTableData(
            $restoreIdentity,
            $historicalId,
            MigrationBackup::SCOPE_ROLLBACK,
            'historical-rollback-op',
        );
        [$process, $pipes] = $this->startConcurrentUpdate($connector, $metadata, $restoreIdentity);
        $completedBeforeDdl = false;
        $childResult = null;
        $fixtureClass::$beforeDdl = static function () use (
            $pipes,
            &$completedBeforeDdl,
            &$childResult,
        ): void {
            fwrite($pipes[0], "go\n");
            fflush($pipes[0]);
            $read = [$pipes[1]];
            $write = [];
            $except = [];
            if (stream_select($read, $write, $except, 0, 300_000) > 0) {
                $completedBeforeDdl = true;
                $childResult = trim((string)fgets($pipes[1]));
            }
        };

        try {
            self::assertTrue(
                ObjectManager::getInstance(MigrationService::class, [], false)
                    ->upgradeMigration($module, $fixture),
            );
            if ($childResult === null) {
                stream_set_timeout($pipes[1], 5);
                $childResult = trim((string)fgets($pipes[1]));
            }
            $stderr = stream_get_contents($pipes[2]);
            $status = proc_close($process);
            $process = null;

            self::assertFalse(
                $completedBeforeDdl,
                'historical exact restore target was not included in the root lock set',
            );
            self::assertSame('ok', $childResult, (string)$stderr);
            self::assertSame(0, $status, (string)$stderr);
        } finally {
            $fixtureClass::$beforeDdl = null;
            foreach ($pipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            if (is_resource($process)) {
                proc_terminate($process);
                proc_close($process);
            }
            $connector->dropPhysicalTableIfExists($restoreIdentity);
            $this->cleanupCallbackFixture($connector, $identity, $module, $fixture);
        }
    }

    /** @return array{resource, array<int, resource>} */
    private function startConcurrentUpdate(
        ConnectorInterface $connector,
        PhysicalTableMetadataInterface $metadata,
        PhysicalTableIdentity $identity,
    ): array {
        $config = $connector->getConfigProvider();
        $dsn = 'pgsql:host=' . $config->getHostName()
            . ';port=' . $config->getHostPort()
            . ';dbname=' . $config->getDatabase();
        $sql = 'UPDATE ' . $metadata->quotePhysicalTable($identity)
            . " SET legacy_value = 'concurrent' WHERE id = 1";
        $script = <<<'PHP'
fgets(STDIN);
try {
    $pdo = new PDO(
        (string)getenv('WELINE_ATOMIC_TEST_DSN'),
        (string)getenv('WELINE_ATOMIC_TEST_USER'),
        (string)getenv('WELINE_ATOMIC_TEST_PASSWORD'),
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
    );
    $pdo->exec((string)base64_decode((string)getenv('WELINE_ATOMIC_TEST_SQL'), true));
    fwrite(STDOUT, "ok\n");
} catch (Throwable) {
    fwrite(STDOUT, "error\n");
}
PHP;
        $environment = array_merge(getenv() ?: [], [
            'WELINE_ATOMIC_TEST_DSN' => $dsn,
            'WELINE_ATOMIC_TEST_USER' => (string)$config->getUsername(),
            'WELINE_ATOMIC_TEST_PASSWORD' => (string)$config->getPassword(),
            'WELINE_ATOMIC_TEST_SQL' => base64_encode($sql),
        ]);
        $pipes = [];
        $process = proc_open(
            [PHP_BINARY, '-r', $script],
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            null,
            $environment,
        );
        if (!is_resource($process)) {
            self::fail('failed to start concurrent PostgreSQL writer');
        }
        return [$process, $pipes];
    }

    /** @return array{ConnectionFactory, ConnectorInterface, PhysicalTableMetadataInterface, PhysicalTableIdentity, string} */
    private function createCallbackFixtureTable(): array
    {
        $factory = ObjectManager::getInstance(ConnectionFactory::class);
        $connector = $factory->getConnector();
        self::assertInstanceOf(PhysicalTableMetadataInterface::class, $connector);
        self::assertInstanceOf(PhysicalTableIdentityProviderInterface::class, $connector);
        $logicalTable = 'migration_callback_' . bin2hex(random_bytes(5));
        $identity = $connector->resolvePhysicalTableIdentity($logicalTable);
        $connector->query(
            'CREATE TABLE ' . $connector->quotePhysicalTable($identity)
            . ' (id INTEGER PRIMARY KEY, legacy_value TEXT NULL)',
        )->fetch();
        $connector->query(
            'INSERT INTO ' . $connector->quotePhysicalTable($identity) . " VALUES (1, 'before')",
        )->fetch();
        return [$factory, $connector, $connector, $identity, $logicalTable];
    }

    private function writeCallbackFixture(): string
    {
        $directory = BP . 'app/code/Weline/Test/Setup/Db/Migration/';
        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }
        $fixture = $directory . self::CALLBACK_FIXTURE;
        file_put_contents($fixture, <<<'PHP'
<?php
namespace Weline\Test\Setup\Db\Migration;

use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Database\Migration\AbstractMigration;
use Weline\Framework\Database\Migration\RollbackBackupStrategyInterface;

final class AtomicCallbackGateFixture20260811V100 extends AbstractMigration implements RollbackBackupStrategyInterface
{
    public static mixed $beforeDdl = null;
    public function __construct(private ConnectionFactory $connectionFactory) {}
    public function install(): bool
    {
        if (is_callable(self::$beforeDdl)) {
            (self::$beforeDdl)();
        }
        $this->dropProbeColumn();
        return (string)getenv('WELINE_TEST_ATOMIC_CALLBACK_MODE') !== 'install_false';
    }
    public function uninstall(): bool
    {
        $this->dropProbeColumn();
        return (string)getenv('WELINE_TEST_ATOMIC_CALLBACK_MODE') === 'uninstall_true';
    }
    public function getVersion(): string { return '1.0.0'; }
    public function getDescription(): string { return 'callback gate fixture'; }
    public function getAffectedTables(): array
    {
        return (string)getenv('WELINE_TEST_ATOMIC_CALLBACK_MODE') === 'missing_identity'
            ? []
            : [(string)getenv('WELINE_TEST_ATOMIC_CALLBACK_TABLE')];
    }
    public function requiresBackup(): bool
    {
        return (string)getenv('WELINE_TEST_ATOMIC_CALLBACK_MODE') === 'install_false';
    }
    public function getBackupStrategy(): array
    {
        return [
            'strategy' => 'column',
            'tables' => [(string)getenv('WELINE_TEST_ATOMIC_CALLBACK_TABLE')],
            'columns' => ['legacy_value'],
        ];
    }
    public function getRollbackBackupStrategy(): array
    {
        return [
            'strategy' => 'none',
            'tables' => [],
            'columns' => [],
            'reason' => 'DDL callback transaction probe',
        ];
    }
    private function dropProbeColumn(): void
    {
        $table = (string)getenv('WELINE_TEST_ATOMIC_CALLBACK_TABLE');
        $connector = $this->connectionFactory->getConnector();
        $connector->query($connector->buildAlterDropColumnSql(
            $connector->formatTableName($table),
            'legacy_value',
        ))->fetch();
    }
}
PHP
        );
        return $fixture;
    }

    /** @return list<Migration> */
    private function migrationRecords(string $module): array
    {
        return ObjectManager::getInstance(Migration::class, [], false)
            ->reset()
            ->where(Migration::schema_fields_MODULE, $module)
            ->order(Migration::schema_fields_ID, 'ASC')
            ->select()
            ->fetch()
            ->getItems();
    }

    private function freshMigration(int $migrationId): Migration
    {
        $record = ObjectManager::getInstance(Migration::class, [], false)
            ->reset()
            ->where(Migration::schema_fields_ID, $migrationId);
        $record->find()->fetch();
        return $record;
    }

    private function createInstallStatusTamperTrigger(
        string $trigger,
        string $function,
        string $module,
    ): void {
        $connector = ObjectManager::getInstance(Migration::class, [], false)->getConnection()->getConnector();
        $table = $connector->quoteTable($connector->formatTableName(Migration::schema_table));
        $quotedTrigger = $connector->quoteIdentifier($trigger);
        $quotedFunction = $connector->quoteIdentifier($function);
        $quotedModule = $connector->getWrappedConnection()->getPdo()->quote($module);
        $connector->getWrappedConnection()->execute(
            "CREATE FUNCTION {$quotedFunction}() RETURNS trigger LANGUAGE plpgsql AS "
            . "\$\$ BEGIN NEW.status := 'running'; RETURN NEW; END \$\$",
        );
        $connector->getWrappedConnection()->execute(
            "CREATE TRIGGER {$quotedTrigger} BEFORE UPDATE OF "
            . $connector->quoteIdentifier(Migration::schema_fields_STATUS)
            . " ON {$table} FOR EACH ROW WHEN (OLD."
            . $connector->quoteIdentifier(Migration::schema_fields_MODULE)
            . " = {$quotedModule} AND NEW."
            . $connector->quoteIdentifier(Migration::schema_fields_STATUS)
            . " = 'installed') EXECUTE FUNCTION {$quotedFunction}()",
        );
    }

    private function dropMigrationTrigger(string $trigger, string $function): void
    {
        $connector = ObjectManager::getInstance(Migration::class, [], false)->getConnection()->getConnector();
        $table = $connector->quoteTable($connector->formatTableName(Migration::schema_table));
        $connector->getWrappedConnection()->execute(
            'DROP TRIGGER IF EXISTS ' . $connector->quoteIdentifier($trigger) . " ON {$table}",
        );
        $connector->getWrappedConnection()->execute(
            'DROP FUNCTION IF EXISTS ' . $connector->quoteIdentifier($function) . '()',
        );
    }

    private function cleanupCallbackFixture(
        ConnectorInterface $connector,
        PhysicalTableIdentity $identity,
        string $module,
        string $fixture,
    ): void {
        $backup = ObjectManager::getInstance(BackupService::class, [], false);
        foreach ($this->migrationRecords($module) as $record) {
            $backup->cleanupBackupData((int)$record->getId());
        }
        ObjectManager::getInstance(Migration::class, [], false)
            ->reset()
            ->where(Migration::schema_fields_MODULE, $module)
            ->delete()
            ->fetch();
        if ($connector instanceof PhysicalTableMetadataInterface) {
            $connector->dropPhysicalTableIfExists($identity);
        }
        putenv('WELINE_TEST_ATOMIC_CALLBACK_TABLE');
        putenv('WELINE_TEST_ATOMIC_CALLBACK_MODE');
        if (is_file($fixture)) {
            unlink($fixture);
        }
    }
}
