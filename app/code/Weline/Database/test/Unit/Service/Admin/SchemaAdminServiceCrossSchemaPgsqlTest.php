<?php

declare(strict_types=1);

namespace Weline\Database\Test\Unit\Service\Admin;

use PHPUnit\Framework\TestCase;
use Weline\Database\Model\Release\BackupObject;
use Weline\Database\Model\Release\BackupSet;
use Weline\Database\Service\Admin\SchemaAdminService;
use Weline\Database\Service\Release\ReleaseTableInstaller;
use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Database\Connection\Api\PhysicalTableIdentity;
use Weline\Framework\Database\Connection\Api\PhysicalTableMetadataInterface;
use Weline\Framework\Database\Connection\Api\Sql\PhysicalTableQueryInterface;
use Weline\Framework\Database\Connection\Adapter\Pgsql\PgsqlIndexName;
use Weline\Framework\Database\Schema\SchemaParser;
use Weline\Framework\Manager\ObjectManager;

final class SchemaAdminServiceCrossSchemaPgsqlTest extends TestCase
{
    public function testBackupAndAllDestructiveDdlUseTheSameExplicitPgsqlSchema(): void
    {
        $schema = 'weline_schema_admin_' . bin2hex(random_bytes(5));
        $logicalTable = 'unit_probe';
        $qualifiedTable = $schema . '.' . $logicalTable;
        $identity = new PhysicalTableIdentity($schema, $logicalTable);
        $sentinel = new PhysicalTableIdentity($schema, 'w_' . $logicalTable);
        $factory = ObjectManager::getInstance(ConnectionFactory::class);
        $connector = $factory->getConnector();
        (new ReleaseTableInstaller(new SchemaParser(), $factory))->install();
        self::assertSame('pgsql', strtolower($connector->getConfigProvider()->getDbType()));
        self::assertInstanceOf(PhysicalTableMetadataInterface::class, $connector);
        self::assertInstanceOf(PhysicalTableQueryInterface::class, $connector->getQuery());
        $metadata = $connector;
        $quotedSchema = $connector->quoteIdentifier($schema);
        $connector->query("CREATE SCHEMA {$quotedSchema}")->fetch();

        try {
            $connector->query(
                'CREATE TABLE ' . $metadata->quotePhysicalTable($identity)
                . ' (id INTEGER PRIMARY KEY, marker TEXT NOT NULL)',
            )->fetch();
            $connector->query(
                'CREATE TABLE ' . $metadata->quotePhysicalTable($sentinel)
                . ' (id INTEGER PRIMARY KEY, marker TEXT NOT NULL, legacy_value TEXT NULL)',
            )->fetch();
            $connector->getQuery()->clearQuery()->tablePhysical($identity)
                ->insert(['id' => 1, 'marker' => 'exact'])->fetch();
            $connector->getQuery()->clearQuery()->tablePhysical($sentinel)
                ->insert(['id' => 1, 'marker' => 'sentinel', 'legacy_value' => 'keep'])->fetch();

            $service = ObjectManager::getInstance(SchemaAdminService::class);
            $service->addColumn(
                $schema,
                $logicalTable,
                'legacy_value',
                'TEXT',
            );
            self::assertContains('legacy_value', array_column($metadata->getPhysicalTableColumns($identity), 'name'));
            self::assertContains('legacy_value', array_column($metadata->getPhysicalTableColumns($sentinel), 'name'));
            $connector->query(
                'UPDATE ' . $metadata->quotePhysicalTable($identity)
                . " SET legacy_value = 'keep' WHERE id = 1",
            )->fetch();

            $service->modifyColumn($schema, $logicalTable, 'legacy_value', 'VARCHAR(255)');
            $exactColumns = $metadata->getPhysicalTableColumns($identity);
            $sentinelColumns = $metadata->getPhysicalTableColumns($sentinel);
            self::assertSame('varchar', $this->column($exactColumns, 'legacy_value')['type'] ?? null);
            self::assertSame('text', $this->column($sentinelColumns, 'legacy_value')['type'] ?? null);

            $connector->query(
                'CREATE INDEX ' . $connector->quoteIdentifier('sentinel_marker_idx')
                . ' ON ' . $metadata->quotePhysicalTable($sentinel)
                . ' (' . $connector->quoteIdentifier('marker') . ')',
            )->fetch();
            $service->addIndex($schema, $logicalTable, 'exact_marker_idx', ['marker']);
            $exactPhysicalIndex = PgsqlIndexName::canonicalPhysical($identity->canonical(), 'exact_marker_idx');
            self::assertTrue($this->physicalIndexExists($connector, $identity, $exactPhysicalIndex));
            self::assertTrue($this->physicalIndexExists($connector, $sentinel, 'sentinel_marker_idx'));
            $service->dropIndex($schema, $logicalTable, 'exact_marker_idx');
            self::assertFalse($this->physicalIndexExists($connector, $identity, $exactPhysicalIndex));
            self::assertTrue($this->physicalIndexExists($connector, $sentinel, 'sentinel_marker_idx'));

            $service->dropColumn($schema, $logicalTable, 'legacy_value');
            self::assertNotContains('legacy_value', array_column($metadata->getPhysicalTableColumns($identity), 'name'));
            self::assertContains('legacy_value', array_column($metadata->getPhysicalTableColumns($sentinel), 'name'));

            $backupSets = (new BackupSet())->setConnection($factory)->reset()
                ->where(BackupSet::schema_fields_SCOPE_KEY, 'admin:' . $schema)
                ->order(BackupSet::schema_fields_ID, 'ASC')
                ->select()
                ->fetchArray();
            self::assertCount(2, $backupSets, 'modify/drop column should create two backup sets');
            foreach ($backupSets as $backupSet) {
                self::assertMatchesRegularExpression(
                    '/\Aadmin-\d{14}-[a-f0-9]{8}\z/',
                    (string)$backupSet[BackupSet::schema_fields_RELEASE_ID],
                );
                self::assertSame(
                    'admin:' . $schema,
                    (string)$backupSet[BackupSet::schema_fields_SCOPE_KEY],
                );
                $objects = (new BackupObject())->setConnection($factory)->reset()
                    ->where(
                        BackupObject::schema_fields_BACKUP_SET_ID,
                        (int)$backupSet[BackupSet::schema_fields_ID],
                    )
                    ->where(BackupObject::schema_fields_OBJECT_TYPE, BackupObject::OBJECT_TYPE_COLUMN)
                    ->select()
                    ->fetchArray();
                self::assertCount(1, $objects);
                self::assertSame($identity->canonical(), (string)$objects[0][BackupObject::schema_fields_TABLE_NAME]);
                self::assertSame('legacy_value', (string)$objects[0][BackupObject::schema_fields_COLUMN_NAME]);
                self::assertStringNotContainsString(
                    'sentinel',
                    (string)$objects[0][BackupObject::schema_fields_DATA_JSON],
                );
            }

        } finally {
            $cleanupSets = (new BackupSet())->setConnection($factory)->reset()
                ->where(BackupSet::schema_fields_SCOPE_KEY, 'admin:' . $schema)
                ->select()
                ->fetchArray();
            foreach ($cleanupSets as $cleanupSet) {
                (new BackupObject())->setConnection($factory)->reset()
                    ->where(
                        BackupObject::schema_fields_BACKUP_SET_ID,
                        (int)$cleanupSet[BackupSet::schema_fields_ID],
                    )
                    ->delete()
                    ->fetch();
                (new BackupSet())->setConnection($factory)->reset()
                    ->where(BackupSet::schema_fields_ID, (int)$cleanupSet[BackupSet::schema_fields_ID])
                    ->delete()
                    ->fetch();
            }
            $connector->query("DROP SCHEMA {$quotedSchema} CASCADE")->fetch();
        }
    }

    /** @param list<array<string, mixed>> $columns @return array<string, mixed> */
    private function column(array $columns, string $name): array
    {
        foreach ($columns as $column) {
            if (($column['name'] ?? null) === $name) {
                return $column;
            }
        }
        return [];
    }

    private function physicalIndexExists(
        \Weline\Framework\Database\Connection\Api\ConnectorInterface $connector,
        PhysicalTableIdentity $identity,
        string $indexName,
    ): bool {
        $statement = $connector->getWrappedConnection()->prepare(
            'SELECT EXISTS ('
            . 'SELECT 1 FROM pg_catalog.pg_index ix '
            . 'JOIN pg_catalog.pg_class t ON t.oid = ix.indrelid '
            . 'JOIN pg_catalog.pg_namespace n ON n.oid = t.relnamespace '
            . 'JOIN pg_catalog.pg_class i ON i.oid = ix.indexrelid '
            . 'WHERE n.nspname = :schema AND t.relname = :table AND i.relname = :index'
            . ')',
        );
        $statement->execute([
            ':schema' => $identity->namespace(),
            ':table' => $identity->table(),
            ':index' => $indexName,
        ]);
        return (bool)$statement->fetchColumn();
    }
}
