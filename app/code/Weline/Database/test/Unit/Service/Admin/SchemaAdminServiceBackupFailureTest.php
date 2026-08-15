<?php

declare(strict_types=1);

namespace Weline\Database\Test\Unit\Service\Admin;

use PHPUnit\Framework\TestCase;
use Weline\Database\Service\Admin\SchemaAdminService;
use Weline\Database\Service\BackupService;
use Weline\Framework\Database\Connection\Api\AtomicPhysicalTableChangeInterface;
use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Database\DbManager\ConfigProvider;
use Weline\Framework\Database\Connection\Api\ConnectorInterface;
use Weline\Framework\Database\Connection\Api\PhysicalTableIdentity;
use Weline\Framework\Database\Connection\Api\PhysicalTableMetadataInterface;
use Weline\Framework\Database\Connection\Api\PhysicalViewMetadataInterface;
use Weline\Framework\Setup\Model\MigrationBackup;

final class SchemaAdminServiceBackupFailureTest extends TestCase
{
    /**
     * @dataProvider destructiveOperationProvider
     */
    public function testDestructiveSchemaAdminOperationDoesNotConnectOrExecuteDdlWhenBackupFails(
        string $operation,
        string $expectedAction,
    ): void
    {
        $connector = $this->createMockForIntersectionOfInterfaces([
            ConnectorInterface::class,
            PhysicalTableMetadataInterface::class,
            AtomicPhysicalTableChangeInterface::class,
        ]);
        $connector->expects(self::once())
            ->method('atomicPhysicalTableChange')
            ->willReturnCallback(
                static fn(PhysicalTableIdentity $identity, callable $callback): mixed => $callback($connector),
            );
        $connector->expects(self::never())->method('query');
        $factory = $this->createMock(ConnectionFactory::class);
        $config = $this->createMock(ConfigProvider::class);
        $config->method('getDbType')->willReturn('pgsql');
        $factory->method('getConfigProvider')->willReturn($config);
        $factory->expects(self::once())->method('getConnector')->willReturn($connector);
        $factory->expects(self::never())->method('query');
        $backup = $this->createMock(BackupService::class);
        $backup->expects(self::once())
            ->method('beginPhysicalBackupOperation')
            ->with(
                self::equalTo(new PhysicalTableIdentity('analytics', 'unit_probe')),
                $expectedAction,
                self::identicalTo($connector),
            )
            ->willReturn(991);
        $backup->expects(self::once())
            ->method('smartBackupPhysicalTable')
            ->with(
                self::equalTo(new PhysicalTableIdentity('analytics', 'unit_probe')),
                991,
                MigrationBackup::SCOPE_UPGRADE,
                '',
                self::identicalTo($connector),
            )
            ->willThrowException(new \RuntimeException('backup persistence failed'));
        $service = new SchemaAdminService($factory, $backup);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('backup persistence failed');

        match ($operation) {
            'add' => $service->addColumn('analytics', 'unit_probe', 'legacy', 'text'),
            'modify' => $service->modifyColumn('analytics', 'unit_probe', 'legacy', 'text'),
            'drop' => $service->dropColumn('analytics', 'unit_probe', 'legacy'),
            'drop_index' => $service->dropIndex('analytics', 'unit_probe', 'legacy_idx'),
        };
    }

    /** @return iterable<string, array{string, string}> */
    public static function destructiveOperationProvider(): iterable
    {
        yield 'add column' => ['add', 'add_column'];
        yield 'modify column' => ['modify', 'modify_column'];
        yield 'drop column' => ['drop', 'drop_column'];
        yield 'drop index' => ['drop_index', 'drop_index'];
    }

    public function testDestructiveOperationFailsClosedBeforeBackupWhenAtomicCapabilityIsMissing(): void
    {
        $connector = $this->createMockForIntersectionOfInterfaces([
            ConnectorInterface::class,
            PhysicalTableMetadataInterface::class,
        ]);
        $factory = $this->createMock(ConnectionFactory::class);
        $config = $this->createMock(ConfigProvider::class);
        $config->method('getDbType')->willReturn('pgsql');
        $factory->method('getConfigProvider')->willReturn($config);
        $factory->expects(self::once())->method('getConnector')->willReturn($connector);
        $factory->expects(self::never())->method('query');
        $backup = $this->createMock(BackupService::class);
        $backup->expects(self::never())->method('beginPhysicalBackupOperation');
        $backup->expects(self::never())->method('smartBackupPhysicalTable');
        $service = new SchemaAdminService($factory, $backup);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('atomic physical table change capability unavailable');

        $service->dropColumn('analytics', 'unit_probe', 'legacy');
    }

    public function testViewReplacementFailsClosedBeforeBackupWhenAtomicCapabilityIsMissing(): void
    {
        $connector = $this->createMockForIntersectionOfInterfaces([
            ConnectorInterface::class,
            PhysicalViewMetadataInterface::class,
        ]);
        $factory = $this->createMock(ConnectionFactory::class);
        $config = $this->createMock(ConfigProvider::class);
        $config->method('getDbType')->willReturn('pgsql');
        $factory->method('getConfigProvider')->willReturn($config);
        $factory->expects(self::once())->method('getConnector')->willReturn($connector);
        $factory->expects(self::never())->method('query');
        $backup = $this->createMock(BackupService::class);
        $backup->expects(self::never())->method('beginPhysicalViewBackupOperation');
        $backup->expects(self::never())->method('backupPhysicalViewDefinition');
        $service = new SchemaAdminService($factory, $backup);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('atomic physical view change capability unavailable');

        $service->createOrReplaceView('analytics', 'unit_view', 'SELECT 1 AS id');
    }
}
