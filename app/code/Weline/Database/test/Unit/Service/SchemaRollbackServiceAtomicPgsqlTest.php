<?php

declare(strict_types=1);

namespace Weline\Database\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Database\Model\Migration;
use Weline\Database\Service\BackupService;
use Weline\Database\Service\SchemaRollbackService;
use Weline\Framework\Database\Connection\Api\ConnectorInterface;
use Weline\Framework\Database\Connection\Api\PhysicalTableIdentity;
use Weline\Framework\Database\Connection\Api\PhysicalTableIdentityProviderInterface;
use Weline\Framework\Database\Connection\Api\PhysicalTableMetadataInterface;
use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Database\Schema\SchemaDiffOp;
use Weline\Framework\Manager\ObjectManager;

final class SchemaRollbackServiceAtomicPgsqlTest extends TestCase
{
    public function testStatusCasReadbackMismatchRollsBackSchemaDdlAndBackup(): void
    {
        $factory = ObjectManager::getInstance(ConnectionFactory::class);
        $connector = $factory->getConnector();
        self::assertInstanceOf(PhysicalTableMetadataInterface::class, $connector);
        self::assertInstanceOf(PhysicalTableIdentityProviderInterface::class, $connector);
        $schema = 'weline_schema_status_cas_' . bin2hex(random_bytes(5));
        $logicalTable = $schema . '.unit_probe';
        $identity = $connector->resolvePhysicalTableIdentity($logicalTable);
        $quotedSchema = $connector->quoteIdentifier($schema);
        $forwardDdl = 'CREATE TABLE ' . $connector->quotePhysicalTable($identity)
            . ' (id INTEGER PRIMARY KEY, marker TEXT NOT NULL)';
        $rollbackDdl = 'DROP TABLE ' . $connector->quotePhysicalTable($identity);
        $migration = ObjectManager::getInstance(Migration::class, [], false);
        $backup = ObjectManager::getInstance(BackupService::class, [], false);
        $migrationId = 0;
        $trigger = 'weline_status_cas_' . bin2hex(random_bytes(4));
        $function = $trigger . '_fn';

        $connector->query("CREATE SCHEMA {$quotedSchema}")->fetch();
        $connector->query($forwardDdl)->fetch();
        $connector->query(
            'INSERT INTO ' . $connector->quotePhysicalTable($identity) . " VALUES (1, 'before')",
        )->fetch();
        $record = (clone $migration)->reset()->setData([
            Migration::schema_fields_MODULE => 'Weline_StatusCasRollbackTest',
            Migration::schema_fields_VERSION => '1.0.0',
            Migration::schema_fields_FILE => 'schema_diff',
            Migration::schema_fields_DESCRIPTION => 'status CAS rollback probe',
            Migration::schema_fields_STATUS => Migration::STATUS_INSTALLED,
            Migration::schema_fields_CHECKSUM => hash('sha256', $forwardDdl . "\0" . $rollbackDdl),
            Migration::schema_fields_FORWARD_DDL => $forwardDdl,
            Migration::schema_fields_ROLLBACK_DDL => $rollbackDdl,
            Migration::schema_fields_SCHEMA_TABLE_NAME => $logicalTable,
            Migration::schema_fields_MIGRATION_TYPE => 'schema_diff',
            Migration::schema_fields_OPERATION_KIND => SchemaDiffOp::KIND_CREATE_TABLE,
            Migration::schema_fields_OPERATION_PAYLOAD => '{}',
            Migration::schema_fields_OPERATION_ID => '',
        ]);
        $saved = $record->save();
        self::assertIsInt($saved);
        $migrationId = (int)$record->getId();
        self::assertGreaterThan(0, $migrationId);
        $this->createStatusTamperTrigger($trigger, $function, $migrationId);

        try {
            $failure = null;
            try {
                (new SchemaRollbackService($factory, $migration, $backup))->executeRollbackPlan([[
                    'migration_id' => $migrationId,
                    'operation_kind' => SchemaDiffOp::KIND_CREATE_TABLE,
                    'table_name' => $logicalTable,
                    'model_class' => '',
                    'rollback_ddl' => $rollbackDdl,
                    'payload' => [],
                ]], 'operation-status-cas');
            } catch (\Throwable $exception) {
                $failure = $exception;
            }

            self::assertInstanceOf(\RuntimeException::class, $failure);
            self::assertStringContainsString('migration status CAS persistence failed', $failure->getMessage());
            self::assertTrue($connector->physicalTableExists($identity));
            $fresh = $this->freshMigration($migrationId);
            self::assertSame(Migration::STATUS_INSTALLED, $fresh->getData(Migration::schema_fields_STATUS));
            self::assertSame('operation-status-cas', $fresh->getData(Migration::schema_fields_OPERATION_ID));
            self::assertSame([], $backup->getBackupsByMigrationId($migrationId));
        } finally {
            $this->dropStatusTamperTrigger($trigger, $function);
            if ($migrationId > 0) {
                $backup->cleanupBackupData($migrationId);
                (clone $migration)->reset()
                    ->where(Migration::schema_fields_ID, $migrationId)
                    ->delete()
                    ->fetch();
            }
            $connector->query("DROP SCHEMA {$quotedSchema} CASCADE")->fetch();
        }
    }

    public function testConcurrentWriterCannotCommitAfterBackupBeforeRollbackDropTable(): void
    {
        $factory = ObjectManager::getInstance(ConnectionFactory::class);
        $connector = $factory->getConnector();
        self::assertSame('pgsql', strtolower($connector->getConfigProvider()->getDbType()));
        self::assertInstanceOf(PhysicalTableMetadataInterface::class, $connector);
        self::assertInstanceOf(PhysicalTableIdentityProviderInterface::class, $connector);
        $metadata = $connector;
        $schema = 'weline_schema_rollback_atomic_' . bin2hex(random_bytes(5));
        $logicalTable = $schema . '.unit_probe';
        $identity = $connector->resolvePhysicalTableIdentity($logicalTable);
        $quotedSchema = $connector->quoteIdentifier($schema);
        $forwardDdl = 'CREATE TABLE ' . $metadata->quotePhysicalTable($identity)
            . ' (id INTEGER PRIMARY KEY, marker TEXT NOT NULL)';
        $rollbackDdl = 'DROP TABLE ' . $metadata->quotePhysicalTable($identity);
        $migration = ObjectManager::getInstance(Migration::class, [], false);
        $record = (clone $migration)->reset()->setData([
            Migration::schema_fields_MODULE => 'Weline_AtomicRollbackTest',
            Migration::schema_fields_VERSION => '1.0.0',
            Migration::schema_fields_FILE => 'schema_diff',
            Migration::schema_fields_DESCRIPTION => 'atomic rollback probe',
            Migration::schema_fields_STATUS => Migration::STATUS_INSTALLED,
            Migration::schema_fields_CHECKSUM => hash('sha256', $forwardDdl . "\0" . $rollbackDdl),
            Migration::schema_fields_FORWARD_DDL => $forwardDdl,
            Migration::schema_fields_ROLLBACK_DDL => $rollbackDdl,
            Migration::schema_fields_SCHEMA_TABLE_NAME => $logicalTable,
            Migration::schema_fields_MIGRATION_TYPE => 'schema_diff',
            Migration::schema_fields_OPERATION_KIND => SchemaDiffOp::KIND_CREATE_TABLE,
            Migration::schema_fields_OPERATION_PAYLOAD => '{}',
            Migration::schema_fields_OPERATION_ID => '',
        ]);
        $migrationId = 0;
        $delegate = ObjectManager::getInstance(BackupService::class, [], false);
        $process = null;
        $pipes = [];

        $connector->query("CREATE SCHEMA {$quotedSchema}")->fetch();
        $connector->query($forwardDdl)->fetch();
        $connector->query(
            'INSERT INTO ' . $metadata->quotePhysicalTable($identity) . " (id, marker) VALUES (1, 'before')",
        )->fetch();
        $saved = $record->save();
        self::assertIsInt($saved);
        $migrationId = (int)$record->getId();
        self::assertGreaterThan(0, $migrationId);
        [$process, $pipes] = $this->startConcurrentInsert($connector, $metadata, $identity);

        $hookedBackup = $this->getMockBuilder(BackupService::class)
            ->disableOriginalConstructor()
            ->onlyMethods([
                'backupTableStructure',
                'backupTableData',
                'smartBackupPhysicalTable',
                'getBackupsByMigrationId',
                'restorePhysicalTableStructure',
                'restorePhysicalTableData',
                'restorePhysicalTableDataChunked',
            ])
            ->getMock();
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
        $hookedBackup->method('backupTableStructure')->willReturnCallback(
            static fn(...$arguments): bool => true,
        );
        $hookedBackup->method('backupTableData')->willReturnCallback(
            function (...$arguments) use ($signal): array {
                $signal();
                return [];
            },
        );
        $hookedBackup->method('smartBackupPhysicalTable')->willReturnCallback(
            function (...$arguments) use ($delegate, $signal): array {
                $result = $delegate->smartBackupPhysicalTable(...$arguments);
                $signal();
                return $result;
            },
        );
        $hookedBackup->method('getBackupsByMigrationId')->willReturnCallback(
            static fn(...$arguments): array => $delegate->getBackupsByMigrationId(...$arguments),
        );
        $hookedBackup->method('restorePhysicalTableStructure')->willReturnCallback(
            static fn(...$arguments): bool => $delegate->restorePhysicalTableStructure(...$arguments),
        );
        $hookedBackup->method('restorePhysicalTableData')->willReturnCallback(
            static fn(...$arguments): bool => $delegate->restorePhysicalTableData(...$arguments),
        );
        $hookedBackup->method('restorePhysicalTableDataChunked')->willReturnCallback(
            static fn(...$arguments): bool => $delegate->restorePhysicalTableDataChunked(...$arguments),
        );

        try {
            $service = new SchemaRollbackService($factory, $migration, $hookedBackup);
            $completed = $service->executeRollbackPlan([[
                'migration_id' => $migrationId,
                'operation_kind' => SchemaDiffOp::KIND_CREATE_TABLE,
                'table_name' => $logicalTable,
                'model_class' => '',
                'rollback_ddl' => $rollbackDdl,
                'payload' => [],
            ]], 'operation-atomic-rollback');
            self::assertCount(1, $completed);
            if ($childResult === null) {
                stream_set_timeout($pipes[1], 5);
                $childResult = trim((string)fgets($pipes[1]));
            }
            $stderr = stream_get_contents($pipes[2]);
            $status = proc_close($process);
            $process = null;

            self::assertFalse(
                $completedBeforeDdl,
                'concurrent write committed after rollback backup but before DROP TABLE',
            );
            self::assertSame('error', $childResult, (string)$stderr);
            self::assertSame(0, $status, (string)$stderr);
            self::assertFalse($metadata->physicalTableExists($identity));
            $rolledBack = $this->freshMigration($migrationId);
            self::assertSame(Migration::STATUS_ROLLED_BACK, $rolledBack->getData(Migration::schema_fields_STATUS));
            self::assertSame(
                'operation-atomic-rollback',
                $rolledBack->getData(Migration::schema_fields_OPERATION_ID),
            );

            $service->compensate($completed, 'operation-atomic-rollback');
            self::assertTrue($metadata->physicalTableExists($identity));
            $rows = $connector->query(
                'SELECT id, marker FROM ' . $metadata->quotePhysicalTable($identity) . ' ORDER BY id',
            )->fetch();
            self::assertSame([['id' => 1, 'marker' => 'before']], $rows);
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
            if ($migrationId > 0) {
                $delegate->cleanupBackupData($migrationId);
                (clone $migration)->reset()
                    ->where(Migration::schema_fields_ID, $migrationId)
                    ->delete()
                    ->fetch();
            }
            $connector->query("DROP SCHEMA {$quotedSchema} CASCADE")->fetch();
        }
    }

    /** @return array{resource, array<int, resource>} */
    private function startConcurrentInsert(
        ConnectorInterface $connector,
        PhysicalTableMetadataInterface $metadata,
        PhysicalTableIdentity $identity,
    ): array {
        $config = $connector->getConfigProvider();
        $dsn = 'pgsql:host=' . $config->getHostName()
            . ';port=' . $config->getHostPort()
            . ';dbname=' . $config->getDatabase();
        $sql = 'INSERT INTO ' . $metadata->quotePhysicalTable($identity)
            . " (id, marker) VALUES (2, 'concurrent')";
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

    private function createStatusTamperTrigger(string $trigger, string $function, int $migrationId): void
    {
        $connector = ObjectManager::getInstance(Migration::class, [], false)->getConnection()->getConnector();
        $table = $connector->quoteTable($connector->formatTableName(Migration::schema_table));
        $quotedTrigger = $connector->quoteIdentifier($trigger);
        $quotedFunction = $connector->quoteIdentifier($function);
        $connector->getWrappedConnection()->execute(
            "CREATE FUNCTION {$quotedFunction}() RETURNS trigger LANGUAGE plpgsql AS "
            . "\$\$ BEGIN NEW.status := 'installed'; RETURN NEW; END \$\$",
        );
        $connector->getWrappedConnection()->execute(
            "CREATE TRIGGER {$quotedTrigger} BEFORE UPDATE OF "
            . $connector->quoteIdentifier(Migration::schema_fields_STATUS)
            . " ON {$table} FOR EACH ROW WHEN (OLD."
            . $connector->quoteIdentifier(Migration::schema_fields_ID)
            . " = {$migrationId} AND NEW."
            . $connector->quoteIdentifier(Migration::schema_fields_STATUS)
            . " = 'rolled_back') EXECUTE FUNCTION {$quotedFunction}()",
        );
    }

    private function dropStatusTamperTrigger(string $trigger, string $function): void
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

    private function freshMigration(int $migrationId): Migration
    {
        $record = ObjectManager::getInstance(Migration::class, [], false)
            ->reset()
            ->where(Migration::schema_fields_ID, $migrationId);
        $record->find()->fetch();
        return $record;
    }
}
