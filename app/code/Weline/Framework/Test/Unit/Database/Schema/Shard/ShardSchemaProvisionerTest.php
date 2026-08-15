<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Database\Schema\Shard;

use PDO;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Weline\Framework\Database\Connection\Adapter\Sqlite\Connector;
use Weline\Framework\Database\Connection\Api\ConnectorInterface;
use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Database\DbManager\ConfigProvider;
use Weline\Framework\Database\DbManager\ConfigProviderInterface;
use Weline\Framework\Database\Schema\ColumnDefinition;
use Weline\Framework\Database\Schema\DbSchemaReader;
use Weline\Framework\Database\Schema\SchemaDiffEngine;
use Weline\Framework\Database\Schema\SchemaDiffOp;
use Weline\Framework\Database\Schema\SchemaMigrationExecutor;
use Weline\Framework\Database\Schema\SchemaMigrationExecutorInterface;
use Weline\Framework\Database\Schema\SchemaReaderInterface;
use Weline\Framework\Database\Schema\Shard\ShardProvisionResult;
use Weline\Framework\Database\Schema\Shard\ShardSchemaFamilyProviderInterface;
use Weline\Framework\Database\Schema\Shard\ShardSchemaFamilyProviderRegistry;
use Weline\Framework\Database\Schema\Shard\ShardSchemaProvisioner;
use Weline\Framework\Database\Schema\TableSchema;
use Weline\Framework\Event\EventsManager;
use Weline\Framework\Setup\Model\Migration;
use Weline\Framework\Setup\Stage\SchemaDiffStage;

/**
 * TEST-P2A-01 / TEST-P2A-02（Framework 通用层，假 family）
 */
final class ShardSchemaProvisionerTest extends TestCase
{
    public function testProvisionReadyWhenExecutorSucceedsAndFingerprintsMatch(): void
    {
        $declared = $this->table('w_test_shard_0_product');
        $provider = $this->fakeFamily('test.website', ['0'], [
            '0' => [$declared],
        ]);
        $registry = new ShardSchemaFamilyProviderRegistry(
            manualFamilyProviders: ['test.website' => $provider],
            scanExtends: false,
        );

        $connector = $this->createConnector('sqlite');
        $factory = $this->createMock(ConnectionFactory::class);
        $factory->method('getConnector')->willReturn($connector);

        $reader = $this->createMock(SchemaReaderInterface::class);
        $reader->method('readTable')->willReturnOnConsecutiveCalls(
            null,
            $declared,
        );

        $executor = $this->createMock(SchemaMigrationExecutorInterface::class);
        $executor->expects(self::once())->method('execute');

        $provisioner = new ShardSchemaProvisioner(
            $factory,
            $registry,
            $reader,
            new SchemaDiffEngine(),
            $executor,
        );

        $result = $provisioner->provision('test.website', '0');
        self::assertTrue($result->isReady());
        self::assertSame(ShardProvisionResult::STATUS_READY, $result->status);
        self::assertSame(['w_test_shard_0_product'], $result->tableNames);
        self::assertNotSame('', $result->fingerprint);
        self::assertCount(1, $result->ops);
        self::assertSame(SchemaDiffOp::KIND_CREATE_TABLE, $result->ops[0]->kind);
    }

    public function testFailedShardGoesMaintenanceWithoutAffectingOtherShard(): void
    {
        $okTable = $this->table('w_test_shard_0_product');
        $badTable = $this->table('w_test_shard_1_product');
        $provider = $this->fakeFamily('test.website', ['0', '1'], [
            '0' => [$okTable],
            '1' => [$badTable],
        ]);
        $registry = new ShardSchemaFamilyProviderRegistry(
            manualFamilyProviders: ['test.website' => $provider],
            scanExtends: false,
        );

        $connector = $this->createConnector('sqlite');
        $factory = $this->createMock(ConnectionFactory::class);
        $factory->method('getConnector')->willReturn($connector);

        $reader = $this->createMock(SchemaReaderInterface::class);
        $reader->method('readTable')->willReturnOnConsecutiveCalls(
            null,
            $okTable,
            null,
        );

        $calls = 0;
        $executor = $this->createMock(SchemaMigrationExecutorInterface::class);
        $executor->method('execute')->willReturnCallback(
            static function () use (&$calls): void {
                $calls++;
                if ($calls >= 2) {
                    throw new \RuntimeException('simulated ddl failure');
                }
            }
        );

        $provisioner = new ShardSchemaProvisioner(
            $factory,
            $registry,
            $reader,
            new SchemaDiffEngine(),
            $executor,
        );

        $ready = $provisioner->provision('test.website', '0');
        $failed = $provisioner->provision('test.website', '1');

        self::assertTrue($ready->isReady());
        self::assertSame(ShardProvisionResult::STATUS_MAINTENANCE, $failed->status);
        self::assertFalse($failed->isWritable());
        self::assertStringContainsString('simulated ddl failure', (string)$failed->errorMessage);
        self::assertSame(['w_test_shard_1_product'], $failed->tableNames);
    }

    public function testUnknownFamilyFailsClosed(): void
    {
        $registry = new ShardSchemaFamilyProviderRegistry(
            manualFamilyProviders: [],
            scanExtends: false,
        );
        $factory = $this->createMock(ConnectionFactory::class);
        $provisioner = new ShardSchemaProvisioner(
            $factory,
            $registry,
            $this->createMock(SchemaReaderInterface::class),
            new SchemaDiffEngine(),
            $this->createMock(SchemaMigrationExecutorInterface::class),
        );

        $result = $provisioner->provision('missing.family', '0');
        self::assertSame(ShardProvisionResult::STATUS_FAILED, $result->status);
        self::assertFalse($result->isReady());
    }

    public function testGetTableSchemasExpandsAllRegisteredShards(): void
    {
        $provider = $this->fakeFamily('test.website', ['0', '1'], [
            '0' => [$this->table('w_test_shard_0_product')],
            '1' => [$this->table('w_test_shard_1_product')],
        ]);
        $all = $provider->getTableSchemas();
        self::assertCount(2, $all);
        self::assertSame('w_test_shard_0_product', $all[0]->tableName);
        self::assertSame('w_test_shard_1_product', $all[1]->tableName);
        self::assertSame(
            ['w_test_shard_0_product'],
            array_map(
                static fn(TableSchema $schema): string => $schema->tableName,
                $provider->getSchemaCheckpointTableSchemas(),
            ),
        );
    }

    public function testSchemaDiffStageMergesAllRegistryProvidersIntoDeclaredSchemas(): void
    {
        $table = $this->table('w_test_stage_shard_product');
        $provider = $this->fakeFamily('test.stage', ['0'], ['0' => [$table]]);
        $registry = new ShardSchemaFamilyProviderRegistry(
            manualFamilyProviders: ['test.stage' => $provider],
            scanExtends: false,
        );

        $reflection = new \ReflectionClass(SchemaDiffStage::class);
        $stage = $reflection->newInstanceWithoutConstructor();
        $registryProperty = $reflection->getProperty('schemaProviderRegistry');
        $registryProperty->setValue($stage, $registry);
        $declaredSchemas = [];
        $processedTables = [];
        $merge = $reflection->getMethod('mergeSchemaProviders');
        $merge->invokeArgs($stage, [&$declaredSchemas, &$processedTables]);

        self::assertSame($table, $declaredSchemas[$table->tableName] ?? null);
        self::assertArrayHasKey(strtolower($table->tableName), $processedTables);
        $moduleVersions = $reflection->getProperty('moduleVersions')->getValue($stage);
        self::assertSame('1', $moduleVersions['shard:test.stage'] ?? null);
        $fingerprints = $reflection->getProperty('moduleSchemaFingerprints')->getValue($stage);
        self::assertNotSame('', $fingerprints['shard:test.stage'][$table->tableName] ?? '');
    }

    public function testSchemaDiffStageCheckpointDoesNotGrowWithRegisteredShards(): void
    {
        $template = $this->table('w_test_checkpoint_0_product');
        $zero = $this->table('w_test_checkpoint_0_product');
        $one = $this->table('w_test_checkpoint_1_product');
        $provider = $this->fakeFamily(
            'test.checkpoint',
            ['0', '1'],
            ['0' => [$zero], '1' => [$one]],
            [$template],
        );
        $registry = new ShardSchemaFamilyProviderRegistry(
            manualFamilyProviders: ['test.checkpoint' => $provider],
            scanExtends: false,
        );

        $reflection = new \ReflectionClass(SchemaDiffStage::class);
        $stage = $reflection->newInstanceWithoutConstructor();
        $reflection->getProperty('schemaProviderRegistry')->setValue($stage, $registry);
        $declaredSchemas = [];
        $processedTables = [];
        $reflection->getMethod('mergeSchemaProviders')
            ->invokeArgs($stage, [&$declaredSchemas, &$processedTables]);

        self::assertSame([$zero->tableName, $one->tableName], array_keys($declaredSchemas));
        $fingerprints = $reflection->getProperty('moduleSchemaFingerprints')->getValue($stage);
        self::assertSame(
            [$template->tableName],
            array_keys($fingerprints['shard:test.checkpoint'] ?? []),
        );
    }

    public function testRealSqliteExecutorIsolatesStaleShardFailureWithoutDeletingExistingData(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite is not available.');
        }

        $dbPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'weline_p2a001_' . uniqid('', true) . '.sqlite';
        $connector = new Connector(new ConfigProvider([
            'type' => 'sqlite',
            'database' => '',
            'path' => $dbPath,
            'persistent' => false,
        ]));

        try {
            $goodTable = $this->table('w_p2a001_shard_good');
            $staleTable = $this->table('w_p2a001_shard_stale');
            $provider = $this->fakeFamily('test.sqlite', ['good', 'stale'], [
                'good' => [$goodTable],
                'stale' => [$staleTable],
            ]);
            $registry = new ShardSchemaFamilyProviderRegistry(
                manualFamilyProviders: ['test.sqlite' => $provider],
                scanExtends: false,
            );
            $factory = $this->createMock(ConnectionFactory::class);
            $factory->method('getConnector')->willReturn($connector);

            $realReader = new DbSchemaReader();
            $reader = new class ($realReader, $staleTable->tableName) implements SchemaReaderInterface {
                public function __construct(
                    private readonly DbSchemaReader $reader,
                    private readonly string $hiddenTable,
                ) {
                }

                public function readTable(ConnectorInterface $connector, string $tableName): ?TableSchema
                {
                    if ($tableName === $this->hiddenTable) {
                        return null;
                    }
                    return $this->reader->readTable($connector, $tableName);
                }

                public function readTablesBatch(ConnectorInterface $connector, array $tableNames): array
                {
                    $visible = array_values(array_filter(
                        $tableNames,
                        fn(string $tableName): bool => $tableName !== $this->hiddenTable,
                    ));
                    return $this->reader->readTablesBatch($connector, $visible);
                }
            };

            $migration = $this->createMock(Migration::class);
            $migration->method('recordSchemaDdl')->willReturn(1);
            $migration->method('updateStatus')->willReturn(true);
            $executor = new SchemaMigrationExecutor(
                $this->createMock(EventsManager::class),
                $migration,
                $this->createMock(\Weline\Framework\Database\Service\BackupService::class),
            );
            $provisioner = new ShardSchemaProvisioner(
                $factory,
                $registry,
                $reader,
                new SchemaDiffEngine(),
                $executor,
            );

            $ready = $provisioner->provision('test.sqlite', 'good');
            self::assertTrue($ready->isReady(), (string)$ready->errorMessage);
            self::assertTrue($connector->tableExist($goodTable->tableName));
            self::assertNotSame('', $ready->fingerprint);
            $physicalGoodTable = $realReader->readTable($connector, $goodTable->tableName);
            self::assertSame(
                ['id'],
                array_map(
                    static fn(ColumnDefinition $column): string => $column->name,
                    $physicalGoodTable?->columns ?? [],
                ),
                'Declarative schema creation must not add undeclared timestamp columns.',
            );

            $legacyTableName = 'w_p2a001_legacy_default';
            $legacyCreate = $connector->createTable();
            $legacyCreate->createTable($legacyTableName);
            $legacyCreate->addColumn('id', 'int', null, 'PRIMARY KEY AUTO_INCREMENT', '');
            $legacyCreate->addAdditional('');
            $legacyCreate->create();
            $legacyTable = $realReader->readTable($connector, $legacyTableName);
            $legacyColumns = array_map(
                static fn(ColumnDefinition $column): string => $column->name,
                $legacyTable?->columns ?? [],
            );
            self::assertContains('create_time', $legacyColumns);
            self::assertContains('update_time', $legacyColumns);

            $connector->query(
                'CREATE TABLE ' . $staleTable->tableName
                . ' (id INTEGER PRIMARY KEY AUTOINCREMENT, marker TEXT NOT NULL)'
            )->fetch();
            $connector->query(
                "INSERT INTO {$staleTable->tableName} (marker) VALUES ('preserved')"
            )->fetch();

            $failed = $provisioner->provision('test.sqlite', 'stale');
            self::assertSame(ShardProvisionResult::STATUS_MAINTENANCE, $failed->status);
            self::assertFalse($failed->isWritable());
            self::assertStringContainsString('拒绝用过期 CREATE 计划', (string)$failed->errorMessage);
            self::assertTrue($connector->tableExist($goodTable->tableName));
            self::assertTrue($connector->tableExist($staleTable->tableName));
            $row = $connector->query(
                "SELECT marker FROM {$staleTable->tableName} WHERE id = 1"
            )->fetch();
            self::assertSame('preserved', (string)($row[0]['marker'] ?? ''));
        } finally {
            $connector->close();
            if (is_file($dbPath)) {
                unlink($dbPath);
            }
        }
    }

    /**
     * @param list<string> $keys
     * @param array<string, list<TableSchema>> $byShard
     */
    private function fakeFamily(
        string $code,
        array $keys,
        array $byShard,
        ?array $checkpointSchemas = null,
    ): ShardSchemaFamilyProviderInterface
    {
        $checkpointSchemas ??= $byShard[$keys[0] ?? ''] ?? [];

        return new class ($code, $keys, $byShard, $checkpointSchemas) implements ShardSchemaFamilyProviderInterface {
            /**
             * @param list<string> $keys
             * @param array<string, list<TableSchema>> $byShard
             * @param list<TableSchema> $checkpointSchemas
             */
            public function __construct(
                private readonly string $code,
                private readonly array $keys,
                private readonly array $byShard,
                private readonly array $checkpointSchemas,
            ) {
            }

            public function getFamilyCode(): string
            {
                return $this->code;
            }

            public function getSchemaVersion(): string
            {
                return '1';
            }

            public function getSchemaCheckpointTableSchemas(): array
            {
                return $this->checkpointSchemas;
            }

            public function getRegisteredShardKeys(): array
            {
                return $this->keys;
            }

            public function getTableSchemasForShard(string $shardKey): array
            {
                return $this->byShard[$shardKey] ?? [];
            }

            public function getTableSchemas(): array
            {
                $all = [];
                foreach ($this->keys as $key) {
                    foreach ($this->getTableSchemasForShard($key) as $schema) {
                        $all[] = $schema;
                    }
                }
                return $all;
            }
        };
    }

    private function table(string $name): TableSchema
    {
        return new TableSchema(
            tableName: $name,
            comment: 'test',
            columns: [
                new ColumnDefinition('id', 'int', 11, false, true, true),
            ],
        );
    }

    private function createConnector(string $dbType): ConnectorInterface&MockObject
    {
        $config = $this->createMock(ConfigProviderInterface::class);
        $config->method('getDbType')->willReturn($dbType);

        $connector = $this->createMock(ConnectorInterface::class);
        $connector->method('getConfigProvider')->willReturn($config);

        return $connector;
    }
}
