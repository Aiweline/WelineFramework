<?php

declare(strict_types=1);

namespace Weline\Framework\Database\Test\Unit;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Database\Connection\Api\ConnectorInterface;
use Weline\Framework\Database\Connection\Api\PhysicalTableIdentity;
use Weline\Framework\Database\Connection\Api\PhysicalTableIdentityProviderInterface;
use Weline\Framework\Database\Connection\Api\PhysicalTableMetadataInterface;
use Weline\Framework\Database\Connection\Api\PhysicalTableSnapshotInterface;
use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Database\Schema\ColumnDefinition;
use Weline\Framework\Database\Schema\SchemaDiffOp;
use Weline\Framework\Database\Schema\SchemaMigrationExecutor;
use Weline\Framework\Database\Service\BackupService;
use Weline\Framework\Event\EventsManager;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Setup\Model\Migration;
use Weline\Framework\Setup\Model\MigrationBackup;

final class SchemaMigrationExecutorAtomicPgsqlTest extends TestCase
{
    public function testAuditTriggerCatalogDriftIsRejectedInsideLockBeforeBackupEventAndDdl(): void
    {
        $factory = ObjectManager::getInstance(ConnectionFactory::class);
        $connector = $factory->getConnector();
        self::assertInstanceOf(PhysicalTableMetadataInterface::class, $connector);
        self::assertInstanceOf(PhysicalTableIdentityProviderInterface::class, $connector);
        self::assertInstanceOf(PhysicalTableSnapshotInterface::class, $connector);
        $module = 'Weline_LockedCatalogFingerprintTest';
        $schema = 'weline_catalog_fingerprint_' . bin2hex(random_bytes(5));
        $logicalTable = $schema . '.unit_probe';
        $identity = $connector->resolvePhysicalTableIdentity($logicalTable);
        $quotedSchema = $connector->quoteIdentifier($schema);
        $quotedTable = $connector->quotePhysicalTable($identity);
        $migration = ObjectManager::getInstance(Migration::class, [], false);
        $backup = ObjectManager::getInstance(BackupService::class, [], false);
        $expectedFingerprint = '';
        $events = [];
        $trigger = 'weline_catalog_drift_' . bin2hex(random_bytes(4));
        $function = $trigger . '_fn';

        $connector->query("CREATE SCHEMA {$quotedSchema}")->fetch();
        $connector->query(
            "CREATE TABLE {$quotedTable} (id INTEGER PRIMARY KEY, legacy_value TEXT NULL)",
        )->fetch();
        $expectedFingerprint = $connector->physicalTableCatalogFingerprint($identity);
        $this->createCatalogDriftTrigger($trigger, $function, $module, $quotedTable);
        $eventsManager = $this->createMock(EventsManager::class);
        $eventsManager->method('dispatch')->willReturnCallback(
            static function (string $event) use (&$events): void {
                $events[] = $event;
            },
        );

        try {
            $failure = null;
            try {
                (new SchemaMigrationExecutor($eventsManager, $migration, $backup))->execute(
                    $connector,
                    [new SchemaDiffOp(
                        SchemaDiffOp::KIND_DROP_COLUMN,
                        $logicalTable,
                        new ColumnDefinition('legacy_value', 'text', null, true),
                        'Weline\\LockedCatalogFingerprintTest\\Model\\UnitProbe',
                    )],
                    ['physical_table_fingerprints' => [$logicalTable => $expectedFingerprint]],
                );
            } catch (\Throwable $exception) {
                $failure = $exception;
            }

            self::assertInstanceOf(\RuntimeException::class, $failure);
            self::assertStringContainsString('catalog fingerprint', $failure->getMessage());
            self::assertSame([], $events);
            $columns = array_column($connector->getPhysicalTableColumns($identity), 'name');
            self::assertContains('legacy_value', $columns);
            self::assertContains('intruder', $columns);
            $records = (clone $migration)->reset()
                ->where(Migration::schema_fields_MODULE, $module)
                ->select()
                ->fetch()
                ->getItems();
            self::assertCount(1, $records);
            self::assertSame(Migration::STATUS_FAILED, $records[0]->getData(Migration::schema_fields_STATUS));
            self::assertSame(0, ObjectManager::getInstance(MigrationBackup::class, [], false)
                ->reset()
                ->where(MigrationBackup::schema_fields_MIGRATION_ID, (int)$records[0]->getId())
                ->total());
        } finally {
            $this->dropMigrationTrigger($trigger, $function);
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
            $connector->query("DROP SCHEMA {$quotedSchema} CASCADE")->fetch();
        }
    }

    public function testConcurrentWriterCannotCommitAfterBackupBeforeDropColumn(): void
    {
        $factory = ObjectManager::getInstance(ConnectionFactory::class);
        $connector = $factory->getConnector();
        self::assertSame('pgsql', strtolower($connector->getConfigProvider()->getDbType()));
        self::assertInstanceOf(PhysicalTableMetadataInterface::class, $connector);
        self::assertInstanceOf(PhysicalTableIdentityProviderInterface::class, $connector);
        self::assertInstanceOf(PhysicalTableSnapshotInterface::class, $connector);
        $metadata = $connector;
        $module = 'Weline_AtomicSchemaExecutorTest';
        $schema = 'weline_schema_executor_atomic_' . bin2hex(random_bytes(5));
        $logicalTable = $schema . '.unit_probe';
        $identity = $connector->resolvePhysicalTableIdentity($logicalTable);
        $quotedSchema = $connector->quoteIdentifier($schema);
        $migration = ObjectManager::getInstance(Migration::class, [], false);
        $delegate = ObjectManager::getInstance(BackupService::class, [], false);
        $process = null;
        $pipes = [];
        $migrationIds = [];

        $connector->query("CREATE SCHEMA {$quotedSchema}")->fetch();
        $connector->query(
            'CREATE TABLE ' . $metadata->quotePhysicalTable($identity)
            . ' (id INTEGER PRIMARY KEY, legacy_value TEXT NULL)',
        )->fetch();
        $connector->query(
            'INSERT INTO ' . $metadata->quotePhysicalTable($identity)
            . " (id, legacy_value) VALUES (1, 'before')",
        )->fetch();
        $expectedFingerprint = $connector->physicalTableCatalogFingerprint($identity);
        [$process, $pipes] = $this->startConcurrentUpdate($connector, $metadata, $identity);

        $completedBeforeDdl = false;
        $childResult = null;
        $signal = function () use ($pipes, &$completedBeforeDdl, &$childResult): void {
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
        $backup = $this->getMockBuilder(BackupService::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['backupColumnData', 'backupPhysicalColumnData'])
            ->getMock();
        $backup->method('backupColumnData')->willReturnCallback(
            function (...$unused) use ($signal): array {
                $signal();
                return [];
            },
        );
        $backup->method('backupPhysicalColumnData')->willReturnCallback(
            function (...$arguments) use ($delegate, $signal, &$migrationIds): array {
                $migrationIds[] = (int)($arguments[2] ?? 0);
                $result = $delegate->backupPhysicalColumnData(...$arguments);
                $signal();
                return $result;
            },
        );
        $executor = new SchemaMigrationExecutor(
            $this->createMock(EventsManager::class),
            $migration,
            $backup,
        );

        try {
            $executor->execute($connector, [new SchemaDiffOp(
                SchemaDiffOp::KIND_DROP_COLUMN,
                $logicalTable,
                new ColumnDefinition('legacy_value', 'text', null, true),
                'Weline\\AtomicSchemaExecutorTest\\Model\\UnitProbe',
            )], ['physical_table_fingerprints' => [$logicalTable => $expectedFingerprint]]);
            if ($childResult === null) {
                stream_set_timeout($pipes[1], 5);
                $childResult = trim((string)fgets($pipes[1]));
            }
            $stderr = stream_get_contents($pipes[2]);
            $status = proc_close($process);
            $process = null;

            self::assertFalse(
                $completedBeforeDdl,
                'concurrent write committed after executor backup but before DROP COLUMN',
            );
            self::assertSame('error', $childResult, (string)$stderr);
            self::assertSame(0, $status, (string)$stderr);
            self::assertNotContains(
                'legacy_value',
                array_column($metadata->getPhysicalTableColumns($identity), 'name'),
            );
        } finally {
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
                $delegate->cleanupBackupData((int)$record->getId());
            }
            (clone $migration)->reset()
                ->where(Migration::schema_fields_MODULE, $module)
                ->delete()
                ->fetch();
            $connector->query("DROP SCHEMA {$quotedSchema} CASCADE")->fetch();
        }
    }

    public function testBeforeObserverCannotCommitBackupAndDdlTransaction(): void
    {
        $factory = ObjectManager::getInstance(ConnectionFactory::class);
        $connector = $factory->getConnector();
        self::assertInstanceOf(PhysicalTableMetadataInterface::class, $connector);
        self::assertInstanceOf(PhysicalTableIdentityProviderInterface::class, $connector);
        self::assertInstanceOf(PhysicalTableSnapshotInterface::class, $connector);
        $metadata = $connector;
        $module = 'Weline_AtomicSchemaObserverTest';
        $schema = 'weline_schema_observer_atomic_' . bin2hex(random_bytes(5));
        $logicalTable = $schema . '.unit_probe';
        $identity = $connector->resolvePhysicalTableIdentity($logicalTable);
        $quotedSchema = $connector->quoteIdentifier($schema);
        $migration = ObjectManager::getInstance(Migration::class, [], false);
        $backup = ObjectManager::getInstance(BackupService::class, [], false);
        $events = [];

        $connector->query("CREATE SCHEMA {$quotedSchema}")->fetch();
        $connector->query(
            'CREATE TABLE ' . $metadata->quotePhysicalTable($identity)
            . ' (id INTEGER PRIMARY KEY, legacy_value TEXT NULL)',
        )->fetch();
        $expectedFingerprint = $connector->physicalTableCatalogFingerprint($identity);
        $eventsManager = $this->createMock(EventsManager::class);
        $eventsManager->method('dispatch')->willReturnCallback(
            function (string $event) use ($connector, &$events): void {
                $events[] = $event;
                if ($event === SchemaMigrationExecutor::EVENT_TABLE_DDL_BEFORE) {
                    $connector->getQuery()->commit();
                }
            },
        );
        $executor = new SchemaMigrationExecutor($eventsManager, $migration, $backup);

        try {
            $failure = null;
            try {
                $executor->execute($connector, [new SchemaDiffOp(
                    SchemaDiffOp::KIND_DROP_COLUMN,
                    $logicalTable,
                    new ColumnDefinition('legacy_value', 'text', null, true),
                    'Weline\\AtomicSchemaObserverTest\\Model\\UnitProbe',
                )], ['physical_table_fingerprints' => [$logicalTable => $expectedFingerprint]]);
            } catch (\Throwable $exception) {
                $failure = $exception;
            }

            self::assertInstanceOf(\Throwable::class, $failure);
            self::assertStringContainsString('不能直接commit', $failure->getMessage());
            self::assertSame([SchemaMigrationExecutor::EVENT_TABLE_DDL_BEFORE], $events);
            self::assertContains(
                'legacy_value',
                array_column($metadata->getPhysicalTableColumns($identity), 'name'),
            );
            $records = (clone $migration)->reset()
                ->where(Migration::schema_fields_MODULE, $module)
                ->select()
                ->fetch()
                ->getItems();
            self::assertCount(1, $records);
            self::assertSame(Migration::STATUS_FAILED, $records[0]->getData(Migration::schema_fields_STATUS));
            self::assertSame(0, ObjectManager::getInstance(MigrationBackup::class, [], false)
                ->reset()
                ->where(MigrationBackup::schema_fields_MIGRATION_ID, (int)$records[0]->getId())
                ->total());
        } finally {
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
            $connector->query("DROP SCHEMA {$quotedSchema} CASCADE")->fetch();
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

    private function createCatalogDriftTrigger(
        string $trigger,
        string $function,
        string $module,
        string $quotedTable,
    ): void {
        $connector = ObjectManager::getInstance(Migration::class, [], false)->getConnection()->getConnector();
        $migrationTable = $connector->quoteTable($connector->formatTableName(Migration::schema_table));
        $quotedTrigger = $connector->quoteIdentifier($trigger);
        $quotedFunction = $connector->quoteIdentifier($function);
        $moduleLiteral = $connector->getWrappedConnection()->getPdo()->quote($module);
        $connector->getWrappedConnection()->execute(
            "CREATE FUNCTION {$quotedFunction}() RETURNS trigger LANGUAGE plpgsql AS "
            . "\$\$ BEGIN EXECUTE 'ALTER TABLE {$quotedTable} ADD COLUMN intruder INTEGER'; "
            . "RETURN NEW; END \$\$",
        );
        $connector->getWrappedConnection()->execute(
            "CREATE TRIGGER {$quotedTrigger} AFTER INSERT ON {$migrationTable} "
            . "FOR EACH ROW WHEN (NEW.module_name = {$moduleLiteral} AND NEW.status = 'running') "
            . "EXECUTE FUNCTION {$quotedFunction}()",
        );
    }

    private function dropMigrationTrigger(string $trigger, string $function): void
    {
        $connector = ObjectManager::getInstance(Migration::class, [], false)->getConnection()->getConnector();
        $migrationTable = $connector->quoteTable($connector->formatTableName(Migration::schema_table));
        $connector->getWrappedConnection()->execute(
            'DROP TRIGGER IF EXISTS ' . $connector->quoteIdentifier($trigger) . " ON {$migrationTable}",
        );
        $connector->getWrappedConnection()->execute(
            'DROP FUNCTION IF EXISTS ' . $connector->quoteIdentifier($function) . '()',
        );
    }
}
