<?php

declare(strict_types=1);

namespace Weline\Framework\Database\Test\Unit;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Database\Connection\Api\ConnectorInterface;
use Weline\Framework\Database\Schema\SchemaMigrationExecutor;
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
                'assertSchemaCheckpointCompatible',
                'getLatestSchemaCheckpointBefore',
                'recordSchemaCheckpoint',
            ])
            ->getMock();
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
            null,
        );
        $executor->execute($this->createMock(ConnectorInterface::class), [], [
            'module_versions' => [$module => $version],
            'module_schema_fingerprints' => [$module => $tables],
        ]);
    }
}
