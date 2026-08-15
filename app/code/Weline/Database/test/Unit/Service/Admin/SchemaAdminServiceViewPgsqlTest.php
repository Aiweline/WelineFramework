<?php

declare(strict_types=1);

namespace Weline\Database\Test\Unit\Service\Admin;

use PHPUnit\Framework\TestCase;
use Weline\Database\Service\Admin\SchemaAdminService;
use Weline\Database\Service\BackupService;
use Weline\Framework\Database\Connection\Api\AtomicPhysicalViewChangeInterface;
use Weline\Framework\Database\Connection\Api\ConnectorInterface;
use Weline\Framework\Database\Connection\Api\PhysicalViewIdentity;
use Weline\Framework\Database\Connection\Api\PhysicalViewMetadataInterface;
use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Setup\Model\MigrationBackup;

final class SchemaAdminServiceViewPgsqlTest extends TestCase
{
    public function testReplaceAndDropViewPersistExactDefinitionAndCanRestoreIt(): void
    {
        $factory = ObjectManager::getInstance(ConnectionFactory::class);
        $connector = $factory->getConnector();
        self::assertSame('pgsql', strtolower($connector->getConfigProvider()->getDbType()));
        self::assertInstanceOf(PhysicalViewMetadataInterface::class, $connector);
        $views = $connector;
        $schema = 'weline_view_backup_' . bin2hex(random_bytes(5));
        $identity = new PhysicalViewIdentity($schema, 'unit_view');
        $quotedSchema = $connector->quoteIdentifier($schema);
        $baseTable = $connector->quoteTable($schema . '.unit_source');
        $backup = ObjectManager::getInstance(BackupService::class, [], false);
        $service = new SchemaAdminService($factory, $backup);
        $migrationIds = [];

        $connector->query("CREATE SCHEMA {$quotedSchema}")->fetch();
        $connector->query("CREATE TABLE {$baseTable} (id INTEGER PRIMARY KEY, marker TEXT NOT NULL)")->fetch();
        $connector->query("INSERT INTO {$baseTable} (id, marker) VALUES (1, 'before')")->fetch();
        $views->createOrReplacePhysicalView(
            $identity,
            "SELECT marker FROM {$baseTable}",
            false,
        );

        try {
            $service->createOrReplaceView(
                $schema,
                $identity->view(),
                "SELECT marker || '-after' AS marker FROM {$baseTable}",
            );
            self::assertSame('before-after', $this->viewMarker($connector, $views, $identity));
            $replaceBackup = $this->latestViewBackup($identity);
            $migrationIds[] = (int)$replaceBackup->getData(MigrationBackup::schema_fields_MIGRATION_ID);
            self::assertGreaterThan(0, (int)$replaceBackup->getId());
            self::assertTrue($backup->restorePhysicalViewDefinition(
                $identity,
                (int)$replaceBackup->getData(MigrationBackup::schema_fields_MIGRATION_ID),
                backupId: (int)$replaceBackup->getId(),
            ));
            self::assertSame('before', $this->viewMarker($connector, $views, $identity));

            $service->dropView($schema, $identity->view());
            self::assertFalse($views->physicalViewExists($identity));
            $dropBackup = $this->latestViewBackup($identity);
            $migrationIds[] = (int)$dropBackup->getData(MigrationBackup::schema_fields_MIGRATION_ID);
            self::assertTrue($backup->restorePhysicalViewDefinition(
                $identity,
                (int)$dropBackup->getData(MigrationBackup::schema_fields_MIGRATION_ID),
                backupId: (int)$dropBackup->getId(),
            ));
            self::assertSame('before', $this->viewMarker($connector, $views, $identity));
            self::assertNotSame($migrationIds[0], $migrationIds[1]);
        } finally {
            foreach (array_unique($migrationIds) as $migrationId) {
                if ($migrationId > 0) {
                    $backup->cleanupBackupData($migrationId);
                }
            }
            $connector->query("DROP SCHEMA {$quotedSchema} CASCADE")->fetch();
        }
    }

    public function testViewCallbackFailureRollsBackReplacementAndBackupRow(): void
    {
        $factory = ObjectManager::getInstance(ConnectionFactory::class);
        $connector = $factory->getConnector();
        self::assertInstanceOf(PhysicalViewMetadataInterface::class, $connector);
        self::assertInstanceOf(AtomicPhysicalViewChangeInterface::class, $connector);
        $views = $connector;
        $atomic = $connector;
        $schema = 'weline_view_rollback_' . bin2hex(random_bytes(5));
        $identity = new PhysicalViewIdentity($schema, 'unit_view');
        $quotedSchema = $connector->quoteIdentifier($schema);
        $baseTable = $connector->quoteTable($schema . '.unit_source');
        $migrationId = random_int(10_000_000, 900_000_000);
        $backup = ObjectManager::getInstance(BackupService::class, [], false);

        $connector->query("CREATE SCHEMA {$quotedSchema}")->fetch();
        $connector->query("CREATE TABLE {$baseTable} (id INTEGER PRIMARY KEY, marker TEXT NOT NULL)")->fetch();
        $connector->query("INSERT INTO {$baseTable} (id, marker) VALUES (1, 'stable')")->fetch();
        $views->createOrReplacePhysicalView($identity, "SELECT marker FROM {$baseTable}", false);

        try {
            $failure = null;
            try {
                $atomic->atomicPhysicalViewChange(
                    $identity,
                    function (ConnectorInterface $locked, bool $existed) use (
                        $backup,
                        $identity,
                        $migrationId,
                        $baseTable,
                    ): void {
                        self::assertTrue($existed);
                        $backup->backupPhysicalViewDefinition(
                            $identity,
                            $migrationId,
                            physicalConnector: $locked,
                        );
                        self::assertInstanceOf(PhysicalViewMetadataInterface::class, $locked);
                        $locked->createOrReplacePhysicalView(
                            $identity,
                            "SELECT marker || '-changed' AS marker FROM {$baseTable}",
                            true,
                        );
                        throw new \RuntimeException('forced view rollback');
                    },
                );
            } catch (\RuntimeException $exception) {
                $failure = $exception;
            }

            self::assertInstanceOf(\RuntimeException::class, $failure);
            self::assertSame('forced view rollback', $failure->getMessage());
            self::assertSame('stable', $this->viewMarker($connector, $views, $identity));
            self::assertSame(0, $this->backupCount($migrationId));
        } finally {
            $backup->cleanupBackupData($migrationId);
            $connector->query("DROP SCHEMA {$quotedSchema} CASCADE")->fetch();
        }
    }

    private function latestViewBackup(PhysicalViewIdentity $identity): MigrationBackup
    {
        $backup = ObjectManager::getInstance(MigrationBackup::class, [], false)->reset()
            ->where(MigrationBackup::schema_fields_TABLE_NAME, $identity->canonical())
            ->where(MigrationBackup::schema_fields_BACKUP_TYPE, MigrationBackup::TYPE_VIEW)
            ->order(MigrationBackup::schema_fields_ID, 'DESC')
            ->find()
            ->fetch();
        self::assertInstanceOf(MigrationBackup::class, $backup);
        return $backup;
    }

    private function viewMarker(
        ConnectorInterface $connector,
        PhysicalViewMetadataInterface $views,
        PhysicalViewIdentity $identity,
    ): string {
        $statement = $connector->getWrappedConnection()->prepare(
            'SELECT marker FROM ' . $views->quotePhysicalView($identity),
        );
        $statement->execute();
        return (string)$statement->fetchColumn();
    }

    private function backupCount(int $migrationId): int
    {
        return ObjectManager::getInstance(MigrationBackup::class, [], false)->reset()
            ->where(MigrationBackup::schema_fields_MIGRATION_ID, $migrationId)
            ->total();
    }
}
