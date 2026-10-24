<?php

declare(strict_types=1);

namespace Weline\Database\Test\Unit\Service\Admin;

use PHPUnit\Framework\TestCase;
use Weline\Database\Model\Release\BackupObject;
use Weline\Database\Model\Release\BackupSet;
use Weline\Database\Service\Admin\SchemaAdminService;
use Weline\Database\Service\Release\BackupCatalog;
use Weline\Database\Service\Release\ColumnBackupService;
use Weline\Framework\Database\Connection\Api\AtomicPhysicalTableChangeInterface;
use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Database\DbManager\ConfigProvider;
use Weline\Framework\Database\Connection\Api\ConnectorInterface;
use Weline\Framework\Database\Connection\Api\PhysicalTableMetadataInterface;
use Weline\Framework\Database\Connection\Api\PhysicalViewMetadataInterface;
use Weline\Framework\Database\Schema\DbSchemaReader;

final class SchemaAdminServiceBackupFailureTest extends TestCase
{
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
        $service = $this->newService($factory);

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
        $service = $this->newService($factory);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('atomic physical view change capability unavailable');

        $service->createOrReplaceView('analytics', 'unit_view', 'SELECT 1 AS id');
    }

    private function newService(ConnectionFactory $factory): SchemaAdminService
    {
        $catalog = new BackupCatalog($factory, new BackupSet(), new BackupObject());
        $columnBackup = new ColumnBackupService($factory, $catalog, new DbSchemaReader());

        return new SchemaAdminService($factory, $columnBackup, $catalog);
    }
}
