<?php

declare(strict_types=1);

namespace Weline\Database\Test\Unit\Service\Admin;

use PHPUnit\Framework\TestCase;
use Weline\Database\Service\Admin\SchemaAdminService;
use Weline\Database\Service\BackupService;
use Weline\Framework\Database\Connection\Api\AtomicPhysicalTableChangeInterface;
use Weline\Framework\Database\Connection\Api\ConnectorInterface;
use Weline\Framework\Database\Connection\Api\PhysicalTableIdentity;
use Weline\Framework\Database\Connection\Api\PhysicalTableMetadataInterface;
use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Setup\Model\MigrationBackup;

final class SchemaAdminServiceAtomicPgsqlTest extends TestCase
{
    public function testConcurrentWriterCannotCommitBetweenExactBackupAndDropColumn(): void
    {
        $factory = ObjectManager::getInstance(ConnectionFactory::class);
        $connector = $factory->getConnector();
        self::assertSame('pgsql', strtolower($connector->getConfigProvider()->getDbType()));
        self::assertInstanceOf(PhysicalTableMetadataInterface::class, $connector);
        $schema = 'weline_schema_atomic_' . bin2hex(random_bytes(5));
        $identity = new PhysicalTableIdentity($schema, 'unit_probe');
        $quotedSchema = $connector->quoteIdentifier($schema);
        $metadata = $connector;
        $connector->query("CREATE SCHEMA {$quotedSchema}")->fetch();
        $connector->query(
            'CREATE TABLE ' . $metadata->quotePhysicalTable($identity)
            . ' (id INTEGER PRIMARY KEY, marker TEXT NOT NULL, legacy_value TEXT NULL)',
        )->fetch();
        $connector->query(
            'INSERT INTO ' . $metadata->quotePhysicalTable($identity)
            . " (id, marker, legacy_value) VALUES (1, 'exact', 'before')",
        )->fetch();

        [$process, $pipes] = $this->startConcurrentColumnUpdate($connector, $identity);
        $delegate = ObjectManager::getInstance(BackupService::class, [], false);
        $hookedBackup = $this->getMockBuilder(BackupService::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['beginPhysicalBackupOperation', 'smartBackupPhysicalTable'])
            ->getMock();
        $completedBeforeDdl = false;
        $childResult = null;
        $migrationId = 0;
        $hookedBackup->method('beginPhysicalBackupOperation')->willReturnCallback(
            function (...$arguments) use ($delegate, &$migrationId): int {
                $migrationId = $delegate->beginPhysicalBackupOperation(...$arguments);
                return $migrationId;
            },
        );
        $hookedBackup->method('smartBackupPhysicalTable')->willReturnCallback(
            function (...$arguments) use (
                $delegate,
                $pipes,
                &$completedBeforeDdl,
                &$childResult,
                &$migrationId,
            ): array {
                $result = $delegate->smartBackupPhysicalTable(...$arguments);
                self::assertSame($migrationId, (int)($arguments[1] ?? 0));
                fwrite($pipes[0], "go\n");
                fflush($pipes[0]);
                $read = [$pipes[1]];
                $write = [];
                $except = [];
                if (stream_select($read, $write, $except, 0, 300_000) > 0) {
                    $completedBeforeDdl = true;
                    $childResult = trim((string)fgets($pipes[1]));
                }
                return $result;
            },
        );

        try {
            $service = new SchemaAdminService($factory, $hookedBackup);
            $service->dropColumn($schema, $identity->table(), 'legacy_value');
            if ($childResult === null) {
                stream_set_timeout($pipes[1], 5);
                $childResult = trim((string)fgets($pipes[1]));
            }
            $stderr = stream_get_contents($pipes[2]);
            $status = proc_close($process);
            $process = null;

            self::assertFalse(
                $completedBeforeDdl,
                'concurrent write committed after backup but before destructive DDL',
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
            if ($migrationId > 0) {
                $delegate->cleanupBackupData($migrationId);
            }
            $connector->query("DROP SCHEMA {$quotedSchema} CASCADE")->fetch();
        }
    }

    public function testAtomicCallbackFailureRollsBackDdlAndNestedBackupInsert(): void
    {
        $factory = ObjectManager::getInstance(ConnectionFactory::class);
        $connector = $factory->getConnector();
        self::assertInstanceOf(PhysicalTableMetadataInterface::class, $connector);
        self::assertInstanceOf(AtomicPhysicalTableChangeInterface::class, $connector);
        $metadata = $connector;
        $atomic = $connector;
        $schema = 'weline_schema_atomic_rollback_' . bin2hex(random_bytes(5));
        $identity = new PhysicalTableIdentity($schema, 'unit_probe');
        $quotedSchema = $connector->quoteIdentifier($schema);
        $migrationId = random_int(10_000_000, 900_000_000);
        $backup = ObjectManager::getInstance(BackupService::class, [], false);
        $connector->query("CREATE SCHEMA {$quotedSchema}")->fetch();
        $connector->query(
            'CREATE TABLE ' . $metadata->quotePhysicalTable($identity)
            . ' (id INTEGER PRIMARY KEY, marker TEXT NOT NULL)',
        )->fetch();

        try {
            $failure = null;
            try {
                $atomic->atomicPhysicalTableChange(
                    $identity,
                    function (ConnectorInterface $lockedConnector) use ($backup, $identity, $migrationId): void {
                        $backup->smartBackupPhysicalTable(
                            $identity,
                            $migrationId,
                            physicalConnector: $lockedConnector,
                        );
                        self::assertInstanceOf(PhysicalTableMetadataInterface::class, $lockedConnector);
                        $lockedConnector->query(
                            'ALTER TABLE ' . $lockedConnector->quotePhysicalTable($identity)
                            . ' ADD COLUMN rollback_probe TEXT',
                        )->fetch();
                        throw new \RuntimeException('forced callback rollback');
                    },
                );
            } catch (\RuntimeException $exception) {
                $failure = $exception;
            }

            self::assertInstanceOf(\RuntimeException::class, $failure);
            self::assertSame('forced callback rollback', $failure->getMessage());
            self::assertNotContains(
                'rollback_probe',
                array_column($metadata->getPhysicalTableColumns($identity), 'name'),
            );
            self::assertSame(0, $this->backupCount($migrationId));
        } finally {
            $backup->cleanupBackupData($migrationId);
            $connector->query("DROP SCHEMA {$quotedSchema} CASCADE")->fetch();
        }
    }

    public function testAtomicGuardRejectsCallbackCommitAndRestoresLocalLockTimeout(): void
    {
        $factory = ObjectManager::getInstance(ConnectionFactory::class);
        $connector = $factory->getConnector();
        self::assertInstanceOf(PhysicalTableMetadataInterface::class, $connector);
        self::assertInstanceOf(AtomicPhysicalTableChangeInterface::class, $connector);
        $metadata = $connector;
        $atomic = $connector;
        $schema = 'weline_schema_atomic_guard_' . bin2hex(random_bytes(5));
        $identity = new PhysicalTableIdentity($schema, 'unit_probe');
        $quotedSchema = $connector->quoteIdentifier($schema);
        $migrationId = random_int(10_000_000, 900_000_000);
        $backup = ObjectManager::getInstance(BackupService::class, [], false);
        $connector->query("CREATE SCHEMA {$quotedSchema}")->fetch();
        $connector->query(
            'CREATE TABLE ' . $metadata->quotePhysicalTable($identity)
            . ' (id INTEGER PRIMARY KEY, marker TEXT NOT NULL)',
        )->fetch();
        $originalLockTimeout = $this->lockTimeout($connector);

        try {
            $failure = null;
            try {
                $atomic->atomicPhysicalTableChange(
                    $identity,
                    function (ConnectorInterface $lockedConnector) use ($backup, $identity, $migrationId): void {
                        $backup->smartBackupPhysicalTable(
                            $identity,
                            $migrationId,
                            physicalConnector: $lockedConnector,
                        );
                        self::assertInstanceOf(PhysicalTableMetadataInterface::class, $lockedConnector);
                        $lockedConnector->query(
                            'ALTER TABLE ' . $lockedConnector->quotePhysicalTable($identity)
                            . ' ADD COLUMN commit_probe TEXT',
                        )->fetch();
                        $lockedConnector->getQuery()->commit();
                    },
                );
            } catch (\Throwable $exception) {
                $failure = $exception;
            }

            self::assertInstanceOf(\Throwable::class, $failure);
            self::assertStringContainsString('不能直接commit', $failure->getMessage());
            self::assertNotContains(
                'commit_probe',
                array_column($metadata->getPhysicalTableColumns($identity), 'name'),
            );
            self::assertSame(0, $this->backupCount($migrationId));

            $atomic->atomicPhysicalTableChange($identity, static fn(ConnectorInterface $unused): null => null);
            self::assertSame($originalLockTimeout, $this->lockTimeout($connector));
        } finally {
            $backup->cleanupBackupData($migrationId);
            $connector->query("DROP SCHEMA {$quotedSchema} CASCADE")->fetch();
        }
    }

    public function testAtomicFailureInsideExistingOwnerTransactionUnwindsOnlyItsNestedLayers(): void
    {
        $factory = ObjectManager::getInstance(ConnectionFactory::class);
        $connector = $factory->getConnector();
        self::assertInstanceOf(PhysicalTableMetadataInterface::class, $connector);
        self::assertInstanceOf(AtomicPhysicalTableChangeInterface::class, $connector);
        $metadata = $connector;
        $atomic = $connector;
        $schema = 'weline_schema_atomic_nested_' . bin2hex(random_bytes(5));
        $identity = new PhysicalTableIdentity($schema, 'unit_probe');
        $quotedSchema = $connector->quoteIdentifier($schema);
        $migrationId = random_int(10_000_000, 900_000_000);
        $backup = ObjectManager::getInstance(BackupService::class, [], false);
        $connector->query("CREATE SCHEMA {$quotedSchema}")->fetch();
        $connector->query(
            'CREATE TABLE ' . $metadata->quotePhysicalTable($identity)
            . ' (id INTEGER PRIMARY KEY, marker TEXT NOT NULL)',
        )->fetch();
        $owner = clone $connector;
        $owner->clearQuery();
        $owner->beginTransaction();

        try {
            try {
                $atomic->atomicPhysicalTableChange(
                    $identity,
                    function (ConnectorInterface $lockedConnector) use ($backup, $identity, $migrationId): void {
                        $backup->smartBackupPhysicalTable(
                            $identity,
                            $migrationId,
                            physicalConnector: $lockedConnector,
                        );
                        self::assertInstanceOf(PhysicalTableMetadataInterface::class, $lockedConnector);
                        $lockedConnector->query(
                            'ALTER TABLE ' . $lockedConnector->quotePhysicalTable($identity)
                            . ' ADD COLUMN nested_probe TEXT',
                        )->fetch();
                        throw new \RuntimeException('nested atomic rollback');
                    },
                );
                self::fail('nested atomic callback must throw');
            } catch (\RuntimeException $exception) {
                self::assertSame('nested atomic rollback', $exception->getMessage());
            }
            $owner->rollBack();

            self::assertNotContains(
                'nested_probe',
                array_column($metadata->getPhysicalTableColumns($identity), 'name'),
            );
            self::assertSame(0, $this->backupCount($migrationId));
        } finally {
            try {
                $owner->rollBack();
            } catch (\Throwable) {
            }
            $backup->cleanupBackupData($migrationId);
            $connector->query("DROP SCHEMA {$quotedSchema} CASCADE")->fetch();
        }
    }

    public function testTamperedBackupInsertRollsBackBeforeDestructiveDdl(): void
    {
        $factory = ObjectManager::getInstance(ConnectionFactory::class);
        $connector = $factory->getConnector();
        self::assertInstanceOf(PhysicalTableMetadataInterface::class, $connector);
        $metadata = $connector;
        $schema = 'weline_backup_tamper_' . bin2hex(random_bytes(5));
        $identity = new PhysicalTableIdentity($schema, 'unit_probe');
        $backupIdentity = $connector->resolvePhysicalTableIdentity(MigrationBackup::schema_table);
        $quotedSchema = $connector->quoteIdentifier($schema);
        $function = $quotedSchema . '.' . $connector->quoteIdentifier('tamper_backup_insert');
        $trigger = 'weline_tamper_' . bin2hex(random_bytes(4));
        $connector->query("CREATE SCHEMA {$quotedSchema}")->fetch();
        $connector->query(
            'CREATE TABLE ' . $metadata->quotePhysicalTable($identity)
            . ' (id INTEGER PRIMARY KEY, legacy_value TEXT NULL)',
        )->fetch();
        $statement = $connector->getWrappedConnection()->prepare(
            "CREATE FUNCTION {$function}() RETURNS trigger LANGUAGE plpgsql AS "
            . "\$\$BEGIN NEW.backup_data := 'tampered'; RETURN NEW; END\$\$",
        );
        $statement->execute();
        $connector->query(
            'CREATE TRIGGER ' . $connector->quoteIdentifier($trigger)
            . ' BEFORE INSERT ON ' . $metadata->quotePhysicalTable($backupIdentity)
            . " FOR EACH ROW EXECUTE FUNCTION {$function}()",
        )->fetch();
        $before = ObjectManager::getInstance(MigrationBackup::class, [], false)->reset()->total();

        try {
            $service = new SchemaAdminService(
                $factory,
                ObjectManager::getInstance(BackupService::class, [], false),
            );
            try {
                $service->dropColumn($schema, $identity->table(), 'legacy_value');
                self::fail('tampered backup insert must fail closed');
            } catch (\RuntimeException $exception) {
                self::assertStringContainsString('backup persistence', $exception->getMessage());
            }

            self::assertContains(
                'legacy_value',
                array_column($metadata->getPhysicalTableColumns($identity), 'name'),
            );
            self::assertSame(
                $before,
                ObjectManager::getInstance(MigrationBackup::class, [], false)->reset()->total(),
            );
        } finally {
            $connector->query(
                'DROP TRIGGER IF EXISTS ' . $connector->quoteIdentifier($trigger)
                . ' ON ' . $metadata->quotePhysicalTable($backupIdentity),
            )->fetch();
            $connector->query("DROP SCHEMA {$quotedSchema} CASCADE")->fetch();
        }
    }

    /** @return array{resource, array<int, resource>} */
    private function startConcurrentColumnUpdate(
        ConnectorInterface $connector,
        PhysicalTableIdentity $identity,
    ): array {
        $config = $connector->getConfigProvider();
        $dsn = 'pgsql:host=' . $config->getHostName()
            . ';port=' . $config->getHostPort()
            . ';dbname=' . $config->getDatabase();
        $sql = 'UPDATE ' . $connector->quotePhysicalTable($identity)
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

    private function backupCount(int $migrationId): int
    {
        return ObjectManager::getInstance(MigrationBackup::class, [], false)
            ->reset()
            ->where(MigrationBackup::schema_fields_MIGRATION_ID, $migrationId)
            ->total();
    }

    private function lockTimeout(ConnectorInterface $connector): string
    {
        $statement = $connector->getWrappedConnection()->prepare('SHOW lock_timeout');
        $statement->execute();
        return (string)$statement->fetchColumn();
    }
}
