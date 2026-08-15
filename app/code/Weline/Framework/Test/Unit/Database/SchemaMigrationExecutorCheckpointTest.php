<?php

declare(strict_types=1);

namespace Weline\Framework\Database\Test\Unit;

\defined('BP') || \define('BP', \dirname(__DIR__, 7) . \DIRECTORY_SEPARATOR);
\defined('DS') || \define('DS', \DIRECTORY_SEPARATOR);
\defined('APP_PATH') || \define('APP_PATH', BP . 'app' . DS);
\defined('APP_ETC_PATH') || \define('APP_ETC_PATH', APP_PATH . 'etc' . DS);
\defined('APP_CODE_PATH') || \define('APP_CODE_PATH', APP_PATH . 'code' . DS);
\defined('VENDOR_PATH') || \define('VENDOR_PATH', BP . 'vendor' . DS);
\defined('PUB') || \define('PUB', BP . 'pub' . DS);
\defined('DEV') || \define('DEV', false);
\defined('DEBUG') || \define('DEBUG', false);
\defined('SANDBOX') || \define('SANDBOX', false);
require_once APP_CODE_PATH . 'Weline/Framework/Common/functions.php';

use PDO;
use PHPUnit\Framework\TestCase;
use Weline\Framework\Database\Connection\Adapter\Sqlite\Connector;
use Weline\Framework\Database\Connection\Api\ConnectorInterface;
use Weline\Framework\Database\DbManager\ConfigProvider;
use Weline\Framework\Database\DbManager\ConfigProviderInterface;
use Weline\Framework\Database\Schema\ColumnDefinition;
use Weline\Framework\Database\Schema\SchemaCheckpointDataException;
use Weline\Framework\Database\Schema\SchemaDiffOp;
use Weline\Framework\Database\Schema\SchemaMigrationExecutor;
use Weline\Framework\Database\Service\BackupService;
use Weline\Framework\Event\EventsManager;
use Weline\Framework\Setup\Model\Migration;

final class SchemaMigrationExecutorCheckpointTest extends TestCase
{
    public function testZeroDiffStillPersistsSemanticCheckpoint(): void
    {
        $module = 'Weline_CheckpointUnit';
        $version = '1.2.3';
        $tables = ['unit_table' => hash('sha256', 'declared-schema')];
        $migration = $this->getMockBuilder(Migration::class)
            ->disableOriginalConstructor()
            ->onlyMethods([
                'getSchemaCheckpoint',
                'assertSchemaCheckpointCompatible',
                'getLatestSchemaCheckpointBefore',
                'recordSchemaCheckpoint',
            ])
            ->getMock();
        $migration->expects(self::once())
            ->method('getSchemaCheckpoint')
            ->with($module, $version)
            ->willReturn(null);
        $migration->expects(self::once())
            ->method('assertSchemaCheckpointCompatible')
            ->with($module, $version, $tables)
            ->willReturn(null);
        $migration->expects(self::once())
            ->method('getLatestSchemaCheckpointBefore')
            ->with($module, $version)
            ->willReturn(null);
        $migration->expects(self::once())
            ->method('recordSchemaCheckpoint')
            ->with($module, $version, $tables, '')
            ->willReturn(101);

        $executor = new SchemaMigrationExecutor(
            $this->createMock(EventsManager::class),
            $migration,
            $this->createMock(BackupService::class),
        );
        $executor->execute($this->createMock(ConnectorInterface::class), [], [
            'module_versions' => [$module => $version],
            'module_schema_fingerprints' => [$module => $tables],
        ]);
    }

    public function testInvalidCheckpointFailsClosedWithoutExplicitRebind(): void
    {
        $migration = $this->checkpointMigrationMock();
        $migration->expects(self::once())
            ->method('getSchemaCheckpoint')
            ->willThrowException(new SchemaCheckpointDataException('invalid checkpoint'));
        $migration->expects(self::never())->method('supersedeSchemaCheckpoint');

        $executor = new SchemaMigrationExecutor(
            $this->createMock(EventsManager::class),
            $migration,
            $this->createMock(BackupService::class),
        );

        $this->expectException(SchemaCheckpointDataException::class);
        $executor->execute($this->createMock(ConnectorInterface::class), [], [
            'module_versions' => ['Weline_Invalid' => '1.0.0'],
            'module_schema_fingerprints' => ['Weline_Invalid' => []],
        ]);
    }

    public function testExplicitRebindSupersedesInvalidCheckpointBeforeValidation(): void
    {
        $module = 'Weline_Invalid';
        $version = '1.0.0';
        $migration = $this->checkpointMigrationMock();
        $migration->expects(self::once())
            ->method('getSchemaCheckpoint')
            ->with($module, $version)
            ->willThrowException(new SchemaCheckpointDataException('invalid checkpoint'));
        $migration->expects(self::once())
            ->method('supersedeSchemaCheckpoint')
            ->with($module, $version, 'force-schema-rebind:invalid-checkpoint')
            ->willReturn(1);
        $migration->expects(self::once())
            ->method('assertSchemaCheckpointCompatible')
            ->willReturn(null);
        $migration->expects(self::once())
            ->method('getLatestSchemaCheckpointBefore')
            ->willReturn(null);
        $migration->expects(self::once())
            ->method('recordSchemaCheckpoint')
            ->willReturn(101);

        $executor = new SchemaMigrationExecutor(
            $this->createMock(EventsManager::class),
            $migration,
            $this->createMock(BackupService::class),
        );
        $executor->execute($this->createMock(ConnectorInterface::class), [], [
            'module_versions' => [$module => $version],
            'module_schema_fingerprints' => [$module => []],
            'force_schema_rebind' => true,
        ]);
    }

    public function testExplicitRebindDoesNotMaskOperationalRuntimeFailure(): void
    {
        $migration = $this->checkpointMigrationMock();
        $migration->expects(self::once())
            ->method('getSchemaCheckpoint')
            ->willThrowException(new \RuntimeException('database unavailable'));
        $migration->expects(self::never())->method('supersedeSchemaCheckpoint');

        $executor = new SchemaMigrationExecutor(
            $this->createMock(EventsManager::class),
            $migration,
            $this->createMock(BackupService::class),
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('database unavailable');
        $executor->execute($this->createMock(ConnectorInterface::class), [], [
            'module_versions' => ['Weline_Invalid' => '1.0.0'],
            'module_schema_fingerprints' => ['Weline_Invalid' => []],
            'force_schema_rebind' => true,
        ]);
    }

    public function testDdlExecutionFailsClosedWhenAuditRowHasNoId(): void
    {
        $migration = $this->getMockBuilder(Migration::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['recordSchemaDdl'])
            ->getMock();
        $migration->expects(self::once())->method('recordSchemaDdl')->willReturn(0);
        [$connector, $dbPath] = $this->createDdlConnector();

        $executor = new SchemaMigrationExecutor(
            $this->createMock(EventsManager::class),
            $migration,
            $this->createMock(BackupService::class),
        );

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('migration_id=0');
            $executor->execute($connector, [$this->createAddColumnOp()]);
        } finally {
            $connector->close();
            if (is_file($dbPath)) {
                unlink($dbPath);
            }
        }
    }

    public function testDdlExecutionFailsClosedWhenInstalledStatusCannotBePersisted(): void
    {
        $migration = $this->getMockBuilder(Migration::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['recordSchemaDdl', 'updateStatus'])
            ->getMock();
        $migration->expects(self::once())->method('recordSchemaDdl')->willReturn(101);
        $migration->expects(self::once())->method('updateStatus')->willReturn(false);
        [$connector, $dbPath] = $this->createDdlConnector();

        $executor = new SchemaMigrationExecutor(
            $this->createMock(EventsManager::class),
            $migration,
            $this->createMock(BackupService::class),
        );

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('状态写回失败');
            $executor->execute($connector, [$this->createAddColumnOp()]);
        } finally {
            $connector->close();
            if (is_file($dbPath)) {
                unlink($dbPath);
            }
        }
    }

    /**
     * @dataProvider destructiveColumnOperationProvider
     */
    public function testDestructiveColumnOperationStopsBeforeDdlWhenBackupFails(
        string $kind,
        string $backupReason,
    ): void {
        $migration = $this->getMockBuilder(Migration::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['recordSchemaDdl'])
            ->getMock();
        $migration->expects(self::once())->method('recordSchemaDdl')->willReturn(101);

        $connector = $this->schemaConnectorMock();
        $connector->expects(self::never())->method('query');
        if ($kind === SchemaDiffOp::KIND_DROP_COLUMN) {
            $connector->method('buildAlterAddColumnSql')->willReturn('ALTER TABLE unit_probe ADD COLUMN legacy text');
            $connector->method('buildAlterDropColumnSql')->willReturn('ALTER TABLE unit_probe DROP COLUMN legacy');
        } else {
            $connector->method('buildAlterModifyColumnSql')->willReturn('ALTER TABLE unit_probe ALTER COLUMN legacy TYPE text');
        }

        $backup = $this->createMock(BackupService::class);
        $backup->expects(self::once())
            ->method('backupColumnData')
            ->with(
                'unit_probe',
                'legacy',
                101,
                self::identicalTo($connector),
                'Weline\\Unit\\Model\\Probe',
                $backupReason,
            )
            ->willThrowException(new \RuntimeException('backup unavailable'));

        $payload = new ColumnDefinition('legacy', 'text', null, true);
        $op = new SchemaDiffOp(
            $kind,
            'unit_probe',
            $payload,
            'Weline\\Unit\\Model\\Probe',
            $kind === SchemaDiffOp::KIND_MODIFY_COLUMN
                ? new ColumnDefinition('legacy', 'varchar', 255, true)
                : null,
        );
        $executor = new SchemaMigrationExecutor(
            $this->createMock(EventsManager::class),
            $migration,
            $backup,
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('backup unavailable');
        $executor->execute($connector, [$op]);
    }

    /** @return iterable<string, array{string, string}> */
    public static function destructiveColumnOperationProvider(): iterable
    {
        yield 'drop column' => [SchemaDiffOp::KIND_DROP_COLUMN, 'DROP'];
        yield 'modify column' => [SchemaDiffOp::KIND_MODIFY_COLUMN, 'MODIFY'];
    }

    /** @return array{Connector, string} */
    private function createDdlConnector(): array
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite is not available.');
        }
        $dbPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'weline_schema_executor_' . uniqid('', true) . '.sqlite';
        $connector = new Connector(new ConfigProvider([
            'type' => 'sqlite',
            'database' => '',
            'path' => $dbPath,
            'persistent' => false,
        ]));
        $connector->query('CREATE TABLE unit_probe (id INTEGER PRIMARY KEY AUTOINCREMENT)')->fetch();
        return [$connector, $dbPath];
    }

    private function createAddColumnOp(): SchemaDiffOp
    {
        return new SchemaDiffOp(
            SchemaDiffOp::KIND_ADD_COLUMN,
            'unit_probe',
            new ColumnDefinition('marker', 'text', null, false),
            'Weline\\Unit\\Model\\Probe',
        );
    }

    /** @return ConnectorInterface&\PHPUnit\Framework\MockObject\MockObject */
    private function schemaConnectorMock(): ConnectorInterface
    {
        $config = $this->createMock(ConfigProviderInterface::class);
        $config->method('getDbType')->willReturn('pgsql');

        $connector = $this->createMock(ConnectorInterface::class);
        $connector->method('getConfigProvider')->willReturn($config);
        $connector->method('formatTableName')->willReturnArgument(0);
        return $connector;
    }

    private function checkpointMigrationMock(): Migration
    {
        return $this->getMockBuilder(Migration::class)
            ->disableOriginalConstructor()
            ->onlyMethods([
                'getSchemaCheckpoint',
                'supersedeSchemaCheckpoint',
                'assertSchemaCheckpointCompatible',
                'getLatestSchemaCheckpointBefore',
                'recordSchemaCheckpoint',
            ])
            ->getMock();
    }
}
