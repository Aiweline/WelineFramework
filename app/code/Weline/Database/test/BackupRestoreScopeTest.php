<?php

declare(strict_types=1);

namespace Weline\Database\test;

use Weline\Database\Model\MigrationBackup;
use Weline\Database\Service\BackupService;
use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\UnitTest\TestCore;

final class BackupRestoreScopeTest extends TestCore
{
    private const MIGRATION_ID = 991337;

    private BackupService $backupService;
    private ConnectionFactory $connectionFactory;
    private MigrationBackup $backupModel;
    private string $table;

    public function setUp(): void
    {
        parent::setUp();
        $this->backupService = ObjectManager::getInstance(BackupService::class);
        $this->connectionFactory = ObjectManager::getInstance(ConnectionFactory::class);
        $this->backupModel = ObjectManager::getInstance(MigrationBackup::class);
        $this->table = 'backup_scope_' . substr(hash('sha256', uniqid('', true)), 0, 10);
        $connector = $this->connectionFactory->getConnector();
        $physical = $connector->formatTableName($this->table);
        $connector->query(
            "CREATE TABLE {$physical} (tenant_id INTEGER NOT NULL, entity_id INTEGER NOT NULL, value VARCHAR(255), PRIMARY KEY (tenant_id, entity_id))"
        )->fetch();
    }

    public function tearDown(): void
    {
        try {
            $this->connectionFactory->getConnector()->dropTableIfExists($this->table);
            (clone $this->backupModel)->reset()
                ->where(MigrationBackup::schema_fields_MIGRATION_ID, self::MIGRATION_ID)
                ->delete()
                ->fetch();
        } finally {
            parent::tearDown();
        }
    }

    public function testRollbackScopeRestoresLatestCompositeKeyDataWithoutUsingUpgradeBackup(): void
    {
        $connector = $this->connectionFactory->getConnector();
        $connector->getQuery()->clearQuery()->table($this->table)->insert([
            'tenant_id' => 7,
            'entity_id' => 11,
            'value' => 'old-version-value',
        ])->fetch();
        $this->backupService->backupColumnData(
            $this->table,
            'value',
            self::MIGRATION_ID,
            $connector,
            null,
            'MODIFY',
            MigrationBackup::SCOPE_UPGRADE,
        );

        $this->updateValue('new-version-value');
        $this->backupService->backupColumnData(
            $this->table,
            'value',
            self::MIGRATION_ID,
            $connector,
            null,
            'ROLLBACK',
            MigrationBackup::SCOPE_ROLLBACK,
            'op-scope-one',
        );
        $this->updateValue(null);

        $result = $this->backupService->restoreColumnDataConflictSafe(
            $this->table,
            'value',
            self::MIGRATION_ID,
            $connector,
            null,
            null,
            MigrationBackup::SCOPE_ROLLBACK,
            'op-scope-one',
        );

        self::assertSame(['restored' => 1, 'unchanged' => 0, 'conflicts' => 0], $result);
        self::assertSame('new-version-value', $this->readValue());
        $restoredBackup = $this->findBackup(MigrationBackup::TYPE_COLUMN, 'op-scope-one');
        self::assertSame(MigrationBackup::SCOPE_ROLLBACK, $restoredBackup->getData(MigrationBackup::schema_fields_BACKUP_SCOPE));
        self::assertSame(MigrationBackup::RETENTION_EXPIRING, $restoredBackup->getData(MigrationBackup::schema_fields_RETENTION_STATE));
        self::assertNotEmpty($restoredBackup->getData(MigrationBackup::schema_fields_RETAIN_UNTIL));
    }

    public function testConflictIsRecordedAndDoesNotOverwriteCurrentValue(): void
    {
        $connector = $this->connectionFactory->getConnector();
        $connector->getQuery()->clearQuery()->table($this->table)->insert([
            'tenant_id' => 7,
            'entity_id' => 11,
            'value' => 'rollback-backup-value',
        ])->fetch();
        $this->backupService->backupColumnData(
            $this->table,
            'value',
            self::MIGRATION_ID,
            $connector,
            null,
            'ROLLBACK',
            MigrationBackup::SCOPE_ROLLBACK,
            'op-conflict',
        );
        $source = $this->findBackup(MigrationBackup::TYPE_COLUMN, 'op-conflict');
        $this->updateValue('concurrent-value');

        $result = $this->backupService->restoreColumnDataConflictSafe(
            $this->table,
            'value',
            self::MIGRATION_ID,
            $connector,
            null,
            null,
            MigrationBackup::SCOPE_ROLLBACK,
            'op-conflict',
        );

        self::assertSame(['restored' => 0, 'unchanged' => 0, 'conflicts' => 1], $result);
        self::assertSame('concurrent-value', $this->readValue());
        $conflict = $this->findBackup(MigrationBackup::TYPE_CONFLICT, 'op-conflict');
        self::assertSame((int)$source->getId(), (int)$conflict->getData(MigrationBackup::schema_fields_SOURCE_BACKUP_ID));
        self::assertSame(MigrationBackup::RETENTION_PROTECTED, $source->getData(MigrationBackup::schema_fields_RETENTION_STATE));
        self::assertSame(MigrationBackup::RETENTION_PROTECTED, $conflict->getData(MigrationBackup::schema_fields_RETENTION_STATE));
    }

    public function testNonEmptyColumnDefaultIsTreatedAsConflictAndNeverOverwritten(): void
    {
        $connector = $this->connectionFactory->getConnector();
        $connector->getQuery()->clearQuery()->table($this->table)->insert([
            'tenant_id' => 7,
            'entity_id' => 11,
            'value' => 'rollback-backup-value',
        ])->fetch();
        $this->backupService->backupColumnData(
            $this->table,
            'value',
            self::MIGRATION_ID,
            $connector,
            null,
            'ROLLBACK',
            MigrationBackup::SCOPE_ROLLBACK,
            'op-default-conflict',
        );
        $this->updateValue('0');

        $result = $this->backupService->restoreColumnDataConflictSafe(
            $this->table,
            'value',
            self::MIGRATION_ID,
            $connector,
            null,
            '0',
            MigrationBackup::SCOPE_ROLLBACK,
            'op-default-conflict',
        );

        self::assertSame(['restored' => 0, 'unchanged' => 0, 'conflicts' => 1], $result);
        self::assertSame('0', $this->readValue());
        self::assertInstanceOf(
            MigrationBackup::class,
            $this->findBackup(MigrationBackup::TYPE_CONFLICT, 'op-default-conflict')
        );
    }

    public function testTableStructureAndDataCompensationActuallyExecutesDdl(): void
    {
        $connector = $this->connectionFactory->getConnector();
        $connector->getQuery()->clearQuery()->table($this->table)->insert([
            'tenant_id' => 3,
            'entity_id' => 5,
            'value' => 'table-backup-value',
        ])->fetch();

        self::assertTrue($this->backupService->backupTableStructure(
            $this->table,
            self::MIGRATION_ID,
            MigrationBackup::SCOPE_ROLLBACK,
            'op-table',
        ));
        self::assertCount(1, $this->backupService->backupTableData(
            $this->table,
            self::MIGRATION_ID,
            MigrationBackup::SCOPE_ROLLBACK,
            'op-table',
        ));
        $connector->dropTableIfExists($this->table);
        self::assertFalse($connector->tableExist($this->table));

        self::assertTrue($this->backupService->restoreTableStructure(
            $this->table,
            self::MIGRATION_ID,
            false,
            MigrationBackup::SCOPE_ROLLBACK,
            'op-table',
        ));
        self::assertTrue($connector->tableExist($this->table));
        self::assertTrue($this->backupService->restoreTableData(
            $this->table,
            self::MIGRATION_ID,
            false,
            MigrationBackup::SCOPE_ROLLBACK,
            'op-table',
        ));
        $rows = $connector->getQuery()->clearQuery()->table($this->table)
            ->where('tenant_id', 3)
            ->where('entity_id', 5)
            ->limit(1)
            ->select()
            ->fetch();
        self::assertSame('table-backup-value', $rows[0]['value'] ?? null);
    }

    public function testTableRestoreInsertsMissingRowsAndRecordsPrimaryKeyConflicts(): void
    {
        $connector = $this->connectionFactory->getConnector();
        $connector->getQuery()->clearQuery()->table($this->table)->insert([
            'tenant_id' => 1,
            'entity_id' => 10,
            'value' => 'restore-me',
        ])->fetch();
        $connector->getQuery()->clearQuery()->table($this->table)->insert([
            'tenant_id' => 2,
            'entity_id' => 20,
            'value' => 'backup-value',
        ])->fetch();
        self::assertCount(2, $this->backupService->backupTableData(
            $this->table,
            self::MIGRATION_ID,
            MigrationBackup::SCOPE_ROLLBACK,
            'op-table-conflict',
        ));
        $source = $this->findBackup(MigrationBackup::TYPE_TABLE, 'op-table-conflict');

        $connector->getQuery()->clearQuery()->table($this->table)
            ->where('tenant_id', 1)
            ->where('entity_id', 10)
            ->delete()
            ->fetch();
        $connector->getQuery()->clearQuery()->table($this->table)
            ->where('tenant_id', 2)
            ->where('entity_id', 20)
            ->update(['value' => 'keep-current'])
            ->fetch();

        $result = $this->backupService->restoreTableDataConflictSafe(
            $this->table,
            self::MIGRATION_ID,
            MigrationBackup::SCOPE_ROLLBACK,
            'op-table-conflict',
            (int)$source->getId(),
        );

        self::assertSame(['restored' => 1, 'unchanged' => 0, 'conflicts' => 1], $result);
        $rows = $connector->getQuery()->clearQuery()->table($this->table)
            ->order('tenant_id', 'ASC')
            ->select()
            ->fetch();
        self::assertSame('restore-me', $rows[0]['value'] ?? null);
        self::assertSame('keep-current', $rows[1]['value'] ?? null);
        $conflict = $this->findBackup(MigrationBackup::TYPE_CONFLICT, 'op-table-conflict');
        self::assertSame((int)$source->getId(), (int)$conflict->getData(MigrationBackup::schema_fields_SOURCE_BACKUP_ID));
        self::assertSame(MigrationBackup::RETENTION_PROTECTED, $source->getData(MigrationBackup::schema_fields_RETENTION_STATE));
    }

    private function updateValue(?string $value): void
    {
        $this->connectionFactory->getConnector()->getQuery()->clearQuery()->table($this->table)
            ->where('tenant_id', 7)
            ->where('entity_id', 11)
            ->update(['value' => $value])
            ->fetch();
    }

    private function readValue(): mixed
    {
        $rows = $this->connectionFactory->getConnector()->getQuery()->clearQuery()->table($this->table)
            ->fields(['value'])
            ->where('tenant_id', 7)
            ->where('entity_id', 11)
            ->limit(1)
            ->select()
            ->fetch();
        return $rows[0]['value'] ?? null;
    }

    private function findBackup(string $type, string $operationId): MigrationBackup
    {
        $items = (clone $this->backupModel)->reset()
            ->where(MigrationBackup::schema_fields_MIGRATION_ID, self::MIGRATION_ID)
            ->where(MigrationBackup::schema_fields_TABLE_NAME, $this->table)
            ->where(MigrationBackup::schema_fields_BACKUP_TYPE, $type)
            ->where(MigrationBackup::schema_fields_OPERATION_ID, $operationId)
            ->order(MigrationBackup::schema_fields_ID, 'DESC')
            ->limit(1)
            ->select()
            ->fetch()
            ->getItems();
        $backup = $items[0] ?? null;
        self::assertInstanceOf(MigrationBackup::class, $backup);
        return $backup;
    }
}
