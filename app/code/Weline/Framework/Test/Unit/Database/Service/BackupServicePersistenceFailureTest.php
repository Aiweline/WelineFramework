<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Database\Service;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Weline\Framework\Database\Connection\Api\ConnectorInterface;
use Weline\Framework\Database\Connection\Api\AtomicPhysicalTableChangeInterface;
use Weline\Framework\Database\Connection\Api\PhysicalTableIdentity;
use Weline\Framework\Database\Connection\Api\PhysicalTableIdentityProviderInterface;
use Weline\Framework\Database\Connection\Api\PhysicalTableMetadataInterface;
use Weline\Framework\Database\Connection\Api\PhysicalTableSnapshotInterface;
use Weline\Framework\Database\Connection\Api\Sql\QueryInterface;
use Weline\Framework\Database\Connection\Api\Sql\PhysicalTableQueryInterface;
use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Database\DbManager\ConfigProviderInterface;
use Weline\Framework\Database\Schema\ColumnDefinition;
use Weline\Framework\Database\Schema\SchemaDiffOp;
use Weline\Framework\Database\Schema\SchemaMigrationExecutor;
use Weline\Framework\Database\Service\BackupService;
use Weline\Framework\Event\EventsManager;
use Weline\Framework\Output\Cli\Printing;
use Weline\Framework\Setup\Model\Migration;
use Weline\Framework\Setup\Model\MigrationBackup;

interface BackupServicePersistenceFailureConnectorInterface extends ConnectorInterface
{
    public function clearQuery(string $type = ''): QueryInterface;

    public function formatTableName(string $logicalName): string;
}

final class BackupServicePersistenceFailureTest extends TestCase
{
    /**
     * @dataProvider invalidPersistenceProvider
     */
    public function testTableBackupFailsClosedWhenPersistenceDoesNotReturnAnInsertedId(
        mixed $saveResult,
        int|string $modelId,
    ): void
    {
        $query = $this->dataQuery([['id' => 1, 'legacy' => 'value']]);
        $factory = $this->createMock(ConnectionFactory::class);
        $factory->expects(self::once())->method('getQuery')->willReturn($query);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('backup persistence failed');

        $this->backupService($factory, $saveResult, $modelId)->backupTableData('unit_probe', 701);
    }

    /**
     * @dataProvider invalidPersistenceProvider
     */
    public function testColumnBackupFailsClosedWhenPersistenceDoesNotReturnAnInsertedId(
        mixed $saveResult,
        int|string $modelId,
    ): void
    {
        $connector = $this->columnBackupConnector();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('backup persistence failed');

        $this->backupService($this->createMock(ConnectionFactory::class), $saveResult, $modelId)->backupColumnData(
            'unit_probe',
            'legacy',
            702,
            $connector,
        );
    }

    public function testTableBackupAcceptsMatchingPositiveIntegerInsertedId(): void
    {
        $query = $this->dataQuery([['id' => 1, 'legacy' => 'value']]);
        $factory = $this->createMock(ConnectionFactory::class);
        $factory->expects(self::once())->method('getQuery')->willReturn($query);

        self::assertSame(
            [['id' => 1, 'legacy' => 'value']],
            $this->backupService($factory, 704, '704')->backupTableData('unit_probe', 704),
        );
    }

    public function testColumnBackupAcceptsMatchingPositiveIntegerInsertedId(): void
    {
        $connector = $this->columnBackupConnector();

        self::assertSame(
            [['id' => 1, 'legacy' => 'value']],
            $this->backupService($this->createMock(ConnectionFactory::class), 705, '705')->backupColumnData(
                'unit_probe',
                'legacy',
                705,
                $connector,
            ),
        );
    }

    /**
     * @dataProvider invalidPersistenceProvider
     */
    public function testSchemaExecutorDoesNotIssueDdlOrBeforeEventWhenColumnBackupPersistenceFails(
        mixed $saveResult,
        int|string $modelId,
    ): void
    {
        $migration = $this->getMockBuilder(Migration::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['recordSchemaDdl', 'compareAndSwapStatusFailClosed'])
            ->getMock();
        $migration->expects(self::once())->method('recordSchemaDdl')->willReturn(703);
        $migration->expects(self::once())
            ->method('compareAndSwapStatusFailClosed')
            ->with(703, Migration::STATUS_RUNNING, Migration::STATUS_FAILED, '');

        $catalogFingerprint = hash('sha256', 'unit_probe-before-catalog');
        $connector = $this->atomicColumnBackupConnector($catalogFingerprint);
        $connector->expects(self::never())->method('query');
        $connector->method('buildAlterAddColumnSql')
            ->willReturn('ALTER TABLE unit_probe ADD COLUMN legacy text');
        $connector->method('buildAlterDropColumnSql')
            ->willReturn('ALTER TABLE unit_probe DROP COLUMN legacy');

        $events = $this->createMock(EventsManager::class);
        $events->expects(self::never())->method('dispatch');
        $factory = $this->createMock(ConnectionFactory::class);
        $factory->expects(self::once())->method('getConnector')->willReturn($connector);
        $executor = new SchemaMigrationExecutor(
            $events,
            $migration,
            $this->backupService($factory, $saveResult, $modelId),
        );
        $op = new SchemaDiffOp(
            SchemaDiffOp::KIND_DROP_COLUMN,
            'unit_probe',
            new ColumnDefinition('legacy', 'text', null, true),
            'Weline\\Unit\\Model\\Probe',
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('backup persistence failed');
        $executor->execute($connector, [$op], [
            'physical_table_fingerprints' => ['unit_probe' => $catalogFingerprint],
        ]);
    }

    /**
     * @dataProvider invalidPersistenceProvider
     */
    public function testTableStructureBackupReturnsFalseWhenPersistenceDoesNotReturnAnInsertedId(
        mixed $saveResult,
        int|string $modelId,
    ): void
    {
        $connector = $this->createMock(ConnectorInterface::class);
        $connector->expects(self::once())
            ->method('getCreateTableSql')
            ->with('unit_probe')
            ->willReturn('CREATE TABLE unit_probe (id integer)');
        $factory = $this->createMock(ConnectionFactory::class);
        $factory->expects(self::once())->method('getConnector')->willReturn($connector);

        self::assertFalse(
            $this->backupService($factory, $saveResult, $modelId)->backupTableStructure('unit_probe', 706),
        );
    }

    /**
     * @dataProvider invalidPersistenceProvider
     */
    public function testChunkedBackupFailsClosedWhenPersistenceDoesNotReturnAnInsertedId(
        mixed $saveResult,
        int|string $modelId,
    ): void
    {
        $firstQuery = $this->dataQuery([['id' => 1, 'legacy' => 'value']]);
        $factory = $this->createMock(ConnectionFactory::class);
        $factory->expects(self::once())
            ->method('getQuery')
            ->willReturn($firstQuery);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('chunk backup persistence failed');

        $this->backupService($factory, $saveResult, $modelId)->backupTableDataChunked('unit_probe', 707, 1);
    }

    public function testSmartBackupTableThrowsWhenStructureBackupReturnsFalse(): void
    {
        $service = $this->getMockBuilder(BackupService::class)
            ->setConstructorArgs([
                $this->createMock(ConnectionFactory::class),
                $this->backupModel(708, 708),
                $this->createMock(Printing::class),
            ])
            ->onlyMethods(['backupTableStructure'])
            ->getMock();
        $service->expects(self::once())
            ->method('backupTableStructure')
            ->with('unit_probe', 708)
            ->willReturn(false);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('structure backup failed');

        $service->smartBackupTable('unit_probe', 708);
    }

    public function testSmartBackupTableRethrowsDataBackupFailure(): void
    {
        $query = $this->createMock(QueryInterface::class);
        $query->method('clearQuery')->willReturnSelf();
        $query->method('table')->willReturnSelf();
        $query->method('total')->willReturn(1);
        $factory = $this->createMock(ConnectionFactory::class);
        $factory->expects(self::once())->method('getQuery')->willReturn($query);
        $service = $this->getMockBuilder(BackupService::class)
            ->setConstructorArgs([$factory, $this->backupModel(709, 709), $this->createMock(Printing::class)])
            ->onlyMethods(['backupTableStructure', 'backupTableData'])
            ->getMock();
        $service->expects(self::once())->method('backupTableStructure')->willReturn(true);
        $service->expects(self::once())
            ->method('backupTableData')
            ->with('unit_probe', 709)
            ->willThrowException(new \RuntimeException('data backup failed'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('data backup failed');

        $service->smartBackupTable('unit_probe', 709);
    }

    public function testSmartBackupTableRethrowsChunkBackupFailure(): void
    {
        $query = $this->createMock(QueryInterface::class);
        $query->method('clearQuery')->willReturnSelf();
        $query->method('table')->willReturnSelf();
        $query->method('total')->willReturn(BackupService::LARGE_TABLE_THRESHOLD + 1);
        $factory = $this->createMock(ConnectionFactory::class);
        $factory->expects(self::once())->method('getQuery')->willReturn($query);
        $service = $this->getMockBuilder(BackupService::class)
            ->setConstructorArgs([$factory, $this->backupModel(710, 710), $this->createMock(Printing::class)])
            ->onlyMethods(['backupTableStructure', 'backupTableDataChunked'])
            ->getMock();
        $service->expects(self::once())->method('backupTableStructure')->willReturn(true);
        $service->expects(self::once())
            ->method('backupTableDataChunked')
            ->with('unit_probe', 710)
            ->willThrowException(new \RuntimeException('chunk backup failed'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('chunk backup failed');

        $service->smartBackupTable('unit_probe', 710);
    }

    /** @return iterable<string, array{mixed, int|string}> */
    public static function invalidPersistenceProvider(): iterable
    {
        yield 'false' => [false, 0];
        yield 'zero' => [0, 0];
        yield 'true is not an inserted primary key' => [true, 0];
        yield 'saved id differs from model id' => [710, 711];
    }

    private function backupService(ConnectionFactory $factory, mixed $saveResult = false, int|string $modelId = 0): BackupService
    {
        return new BackupService(
            $factory,
            $this->backupModel($saveResult, $modelId),
            $this->createMock(Printing::class),
        );
    }

    private function backupModel(mixed $saveResult, int|string $modelId): MigrationBackup
    {
        $query = $this->createMock(QueryInterface::class);
        $query->method('clearQuery')->willReturnSelf();
        $query->method('table')->willReturnSelf();
        $query->method('identity')->willReturnSelf();
        $connector = $this->createMock(BackupServicePersistenceFailureConnectorInterface::class);
        $connector->method('clearQuery')->willReturn($query);
        $connection = $this->createMock(ConnectionFactory::class);
        $connection->method('getConnector')->willReturn($connector);

        return new class($saveResult, $modelId, $connection) extends MigrationBackup {
            public function __construct(
                private readonly mixed $saveResult,
                private readonly int|string $modelId,
                private readonly ConnectionFactory $connection,
            ) {
            }

            public function getConnection()
            {
                return $this->connection;
            }

            public function reset(): static
            {
                return $this;
            }

            public function setData($key, $value = null, bool $is_unique = false): static
            {
                return $this;
            }

            public function save(
                string|array|bool|\Weline\Framework\Database\AbstractModel $data = [],
                string|array $sequence = '',
            ): bool|int {
                return $this->saveResult;
            }

            public function getId(mixed $default = 0)
            {
                return $this->modelId;
            }
        };
    }

    /** @return QueryInterface&MockObject */
    private function dataQuery(array $rows): QueryInterface
    {
        $query = $this->createMock(QueryInterface::class);
        $query->method('clearQuery')->willReturnSelf();
        $query->method('table')->willReturnSelf();
        $query->method('fields')->willReturnSelf();
        $query->method('where')->willReturnSelf();
        $query->method('select')->willReturnSelf();
        $query->method('limit')->willReturnSelf();
        $query->method('fetch')->willReturn($rows);
        return $query;
    }

    /** @return ConnectorInterface&MockObject */
    private function columnBackupConnector(): ConnectorInterface
    {
        $config = $this->createMock(ConfigProviderInterface::class);
        $config->method('getDbType')->willReturn('pgsql');

        $connector = $this->createMock(ConnectorInterface::class);
        $connector->method('getConfigProvider')->willReturn($config);
        $connector->method('getTableColumns')->willReturn([
            ['name' => 'id', 'primary_key' => true],
            ['name' => 'legacy', 'primary_key' => false],
        ]);
        $connector->method('getQuery')->willReturn($this->dataQuery([['id' => 1, 'legacy' => 'value']]));
        return $connector;
    }

    /** @return ConnectorInterface&MockObject */
    private function atomicColumnBackupConnector(string $catalogFingerprint): ConnectorInterface
    {
        $config = $this->createMock(ConfigProviderInterface::class);
        $config->method('getDbType')->willReturn('pgsql');
        $query = $this->createMockForIntersectionOfInterfaces([
            QueryInterface::class,
            PhysicalTableQueryInterface::class,
        ]);
        $query->method('clearQuery')->willReturnSelf();
        $query->method('tablePhysical')->willReturnSelf();
        $query->method('fields')->willReturnSelf();
        $query->method('where')->willReturnSelf();
        $query->method('select')->willReturnSelf();
        $query->method('fetch')->willReturn([['id' => 1, 'legacy' => 'value']]);

        $connector = $this->createMockForIntersectionOfInterfaces([
            BackupServicePersistenceFailureConnectorInterface::class,
            PhysicalTableMetadataInterface::class,
            PhysicalTableIdentityProviderInterface::class,
            PhysicalTableSnapshotInterface::class,
            AtomicPhysicalTableChangeInterface::class,
        ]);
        $connector->method('formatTableName')->willReturnCallback(
            static fn(string $logicalName): string => str_contains($logicalName, MigrationBackup::schema_table)
                ? MigrationBackup::schema_table
                : 'unit_probe',
        );
        $connector->method('physicalTableCatalogFingerprint')->willReturn($catalogFingerprint);
        $connector->method('getConfigProvider')->willReturn($config);
        $connector->method('resolvePhysicalTableIdentity')
            ->willReturnCallback(
                static fn(string $logicalName): PhysicalTableIdentity => $logicalName === MigrationBackup::schema_table
                    ? new PhysicalTableIdentity('public', MigrationBackup::schema_table)
                    : new PhysicalTableIdentity('public', 'unit_probe'),
            );
        $connector->method('getPhysicalTableColumns')->willReturn([
            ['name' => 'id', 'primary_key' => true],
            ['name' => 'legacy', 'primary_key' => false],
        ]);
        $connector->method('getQuery')->willReturn($query);
        $connector->method('atomicPhysicalTableChange')->willReturnCallback(
            static fn(PhysicalTableIdentity $identity, callable $callback): mixed => $callback($connector),
        );
        return $connector;
    }
}
