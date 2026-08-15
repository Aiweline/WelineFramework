<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Database\Service;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Database\Connection\Api\ConnectorInterface;
use Weline\Framework\Database\Connection\Api\AtomicPhysicalTableChangeInterface;
use Weline\Framework\Database\Connection\Api\PhysicalTableIdentity;
use Weline\Framework\Database\Connection\Api\PhysicalTableMetadataInterface;
use Weline\Framework\Database\Connection\Api\PhysicalViewIdentity;
use Weline\Framework\Database\Connection\Api\Sql\PhysicalTableQueryInterface;
use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Database\Service\BackupService;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Output\Cli\Printing;
use Weline\Framework\Setup\Model\MigrationBackup;

final class PhysicalBackupServicePgsqlTest extends TestCase
{
    public function testExactPhysicalBackupAndRestoreNeverTouchesPrefixedSentinel(): void
    {
        $factory = ObjectManager::getInstance(ConnectionFactory::class);
        $connector = $factory->getConnector();
        self::assertSame('pgsql', strtolower($connector->getConfigProvider()->getDbType()));
        self::assertInstanceOf(PhysicalTableMetadataInterface::class, $connector);
        self::assertInstanceOf(PhysicalTableQueryInterface::class, $connector->getQuery());
        $metadata = $connector;
        $schema = 'weline_backup_physical_' . bin2hex(random_bytes(5));
        $identity = new PhysicalTableIdentity($schema, 'unit_probe');
        $sentinel = new PhysicalTableIdentity($schema, 'w_unit_probe');
        $quotedSchema = $connector->quoteIdentifier($schema);
        $migrationId = random_int(1000000, 900000000);
        $chunkMigrationId = $migrationId + 1;
        $service = ObjectManager::getInstance(BackupService::class, [], false);
        $connector->query("CREATE SCHEMA {$quotedSchema}")->fetch();

        try {
            foreach ([$identity, $sentinel] as $table) {
                $connector->query(
                    'CREATE TABLE ' . $metadata->quotePhysicalTable($table)
                    . ' (id INTEGER PRIMARY KEY, marker TEXT NOT NULL, tracked TEXT NULL)',
                )->fetch();
            }
            $this->insertPhysical($connector, $identity, ['id' => 1, 'marker' => 'exact', 'tracked' => 'before']);
            $this->insertPhysical($connector, $sentinel, ['id' => 1, 'marker' => 'sentinel', 'tracked' => 'keep']);

            $summary = $service->smartBackupPhysicalTable($identity, $migrationId);
            self::assertTrue($summary['structure_backed_up']);
            self::assertTrue($summary['data_backed_up']);
            self::assertSame(1, $summary['total_rows']);
            self::assertSame($identity->canonical(), $summary['table']);

            $records = $this->backupRecords($migrationId);
            self::assertNotEmpty($records);
            foreach ($records as $record) {
                self::assertSame(
                    $identity->canonical(),
                    (string)$record->getData(MigrationBackup::schema_fields_TABLE_NAME),
                );
            }
            $tableBackup = $this->firstBackupOfType($records, MigrationBackup::TYPE_TABLE);
            $tableRows = json_decode(
                (string)$tableBackup->getData(MigrationBackup::schema_fields_BACKUP_DATA),
                true,
            );
            self::assertSame('exact', $tableRows[0]['marker'] ?? null);

            $this->updatePhysical($connector, $identity, ['marker' => 'changed']);
            self::assertTrue($service->restorePhysicalTableData($identity, $migrationId));
            self::assertSame('exact', $this->physicalRow($connector, $identity)['marker'] ?? null);
            self::assertSame('sentinel', $this->physicalRow($connector, $sentinel)['marker'] ?? null);

            $this->updatePhysical($connector, $identity, ['marker' => 'restore-by-id-changed']);
            self::assertTrue($service->restoreByBackupId((int)$tableBackup->getId()));
            self::assertSame('exact', $this->physicalRow($connector, $identity)['marker'] ?? null);
            self::assertSame('sentinel', $this->physicalRow($connector, $sentinel)['marker'] ?? null);

            $service->backupPhysicalColumnData($identity, 'tracked', $migrationId);
            $this->updatePhysical($connector, $identity, ['tracked' => null]);
            self::assertSame(
                ['restored' => 1, 'unchanged' => 0, 'conflicts' => 0],
                $service->restorePhysicalColumnDataConflictSafe($identity, 'tracked', $migrationId),
            );
            self::assertSame('before', $this->physicalRow($connector, $identity)['tracked'] ?? null);
            self::assertSame('keep', $this->physicalRow($connector, $sentinel)['tracked'] ?? null);

            $this->updatePhysical($connector, $identity, ['tracked' => 'changed-again']);
            self::assertTrue($service->restorePhysicalColumnData($identity, 'tracked', $migrationId));
            self::assertSame('before', $this->physicalRow($connector, $identity)['tracked'] ?? null);

            $query = $connector->getQuery();
            self::assertInstanceOf(PhysicalTableQueryInterface::class, $query);
            $query->clearQuery()->tablePhysical($identity)->delete()->fetch();
            self::assertSame(
                ['restored' => 1, 'unchanged' => 0, 'conflicts' => 0],
                $service->restorePhysicalTableDataConflictSafe($identity, $migrationId),
            );
            self::assertSame('exact', $this->physicalRow($connector, $identity)['marker'] ?? null);
            self::assertSame(1, $service->getPhysicalTableRowCount($identity));

            $chunk = $service->backupPhysicalTableDataChunked($identity, $chunkMigrationId, 1);
            self::assertSame(1, $chunk['chunks']);
            foreach ($this->backupRecords($chunkMigrationId) as $record) {
                self::assertSame(
                    $identity->canonical(),
                    (string)$record->getData(MigrationBackup::schema_fields_TABLE_NAME),
                );
            }
            $this->updatePhysical($connector, $identity, ['marker' => 'chunk-changed']);
            self::assertTrue($service->restorePhysicalTableDataChunked($identity, $chunkMigrationId));
            self::assertSame('exact', $this->physicalRow($connector, $identity)['marker'] ?? null);

            $metadata->dropPhysicalTableIfExists($identity);
            self::assertTrue($service->restorePhysicalTableStructure($identity, $migrationId));
            self::assertTrue($metadata->physicalTableExists($identity));
            self::assertTrue($metadata->physicalTableExists($sentinel));
        } finally {
            $service->cleanupBackupData($migrationId);
            $service->cleanupBackupData($chunkMigrationId);
            $connector->query("DROP SCHEMA {$quotedSchema} CASCADE")->fetch();
        }
    }

    public function testPhysicalBackupFailsClosedWhenConnectorLacksOptionalCapabilities(): void
    {
        $connector = $this->createMock(ConnectorInterface::class);
        $connector->expects(self::never())->method('getQuery');
        $factory = $this->createMock(ConnectionFactory::class);
        $factory->expects(self::once())->method('getConnector')->willReturn($connector);
        $service = new BackupService(
            $factory,
            $this->createMock(MigrationBackup::class),
            $this->createMock(Printing::class),
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('exact physical table capability unavailable');

        $service->smartBackupPhysicalTable(new PhysicalTableIdentity('public', 'unit_probe'), 991);
    }

    public function testCompositePrimaryKeyChunkBackupUsesDeterministicKeysetOrder(): void
    {
        $factory = ObjectManager::getInstance(ConnectionFactory::class);
        $connector = $factory->getConnector();
        self::assertInstanceOf(PhysicalTableMetadataInterface::class, $connector);
        $metadata = $connector;
        $schema = 'weline_backup_keyset_' . bin2hex(random_bytes(5));
        $identity = new PhysicalTableIdentity($schema, 'unit_probe');
        $quotedSchema = $connector->quoteIdentifier($schema);
        $migrationId = random_int(10_000_000, 900_000_000);
        $service = ObjectManager::getInstance(BackupService::class, [], false);
        $connector->query("CREATE SCHEMA {$quotedSchema}")->fetch();
        $connector->query(
            'CREATE TABLE ' . $metadata->quotePhysicalTable($identity)
            . ' (tenant_id INTEGER NOT NULL, id INTEGER NOT NULL, marker TEXT NOT NULL,'
            . ' PRIMARY KEY (tenant_id, id))',
        )->fetch();

        try {
            foreach ([[2, 3], [1, 9], [1, 2], [2, 1], [1, 1]] as [$tenantId, $id]) {
                $this->insertPhysical($connector, $identity, [
                    'tenant_id' => $tenantId,
                    'id' => $id,
                    'marker' => $tenantId . '-' . $id,
                ]);
            }

            $summary = $service->backupPhysicalTableDataChunked($identity, $migrationId, 2);
            self::assertSame(5, $summary['total_rows']);
            self::assertSame(3, $summary['chunks']);
            $actual = [];
            foreach ($this->backupRecords($migrationId) as $record) {
                if ($record->getData(MigrationBackup::schema_fields_BACKUP_TYPE) !== MigrationBackup::TYPE_CHUNK) {
                    continue;
                }
                $rows = json_decode(
                    (string)$record->getData(MigrationBackup::schema_fields_BACKUP_DATA),
                    true,
                    flags: JSON_THROW_ON_ERROR,
                );
                foreach ($rows as $row) {
                    $actual[] = [(int)$row['tenant_id'], (int)$row['id']];
                }
            }
            self::assertSame([[1, 1], [1, 2], [1, 9], [2, 1], [2, 3]], $actual);
        } finally {
            $service->cleanupBackupData($migrationId);
            $connector->query("DROP SCHEMA {$quotedSchema} CASCADE")->fetch();
        }
    }

    public function testChunkBackupFailsClosedWhenExactTableHasNoPrimaryKey(): void
    {
        $factory = ObjectManager::getInstance(ConnectionFactory::class);
        $connector = $factory->getConnector();
        self::assertInstanceOf(PhysicalTableMetadataInterface::class, $connector);
        $metadata = $connector;
        $schema = 'weline_backup_no_pk_' . bin2hex(random_bytes(5));
        $identity = new PhysicalTableIdentity($schema, 'unit_probe');
        $quotedSchema = $connector->quoteIdentifier($schema);
        $migrationId = random_int(10_000_000, 900_000_000);
        $service = ObjectManager::getInstance(BackupService::class, [], false);
        $connector->query("CREATE SCHEMA {$quotedSchema}")->fetch();
        $connector->query(
            'CREATE TABLE ' . $metadata->quotePhysicalTable($identity) . ' (marker TEXT NOT NULL)',
        )->fetch();

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('requires a complete primary key');
            $service->backupPhysicalTableDataChunked($identity, $migrationId, 2);
        } finally {
            $service->cleanupBackupData($migrationId);
            $connector->query("DROP SCHEMA {$quotedSchema} CASCADE")->fetch();
        }
    }

    public function testLargeSmartBackupRemainsNestedInAtomicPhysicalTransaction(): void
    {
        $factory = ObjectManager::getInstance(ConnectionFactory::class);
        $connector = $factory->getConnector();
        self::assertInstanceOf(PhysicalTableMetadataInterface::class, $connector);
        self::assertInstanceOf(AtomicPhysicalTableChangeInterface::class, $connector);
        $metadata = $connector;
        $atomic = $connector;
        $schema = 'weline_backup_large_atomic_' . bin2hex(random_bytes(5));
        $identity = new PhysicalTableIdentity($schema, 'unit_probe');
        $quotedSchema = $connector->quoteIdentifier($schema);
        $migrationId = random_int(10_000_000, 900_000_000);
        $service = ObjectManager::getInstance(BackupService::class, [], false);
        $connector->query("CREATE SCHEMA {$quotedSchema}")->fetch();
        $connector->query(
            'CREATE TABLE ' . $metadata->quotePhysicalTable($identity)
            . ' (id INTEGER PRIMARY KEY, marker TEXT NOT NULL)',
        )->fetch();
        $seed = $connector->getWrappedConnection()->prepare(
            'INSERT INTO ' . $metadata->quotePhysicalTable($identity)
            . " (id, marker) SELECT value, 'marker-' || value::text FROM generate_series(1, 10001) value",
        );
        $seed->execute();

        try {
            $summary = [];
            try {
                $atomic->atomicPhysicalTableChange(
                    $identity,
                    function (ConnectorInterface $lockedConnector) use (
                        $service,
                        $identity,
                        $migrationId,
                        &$summary,
                    ): void {
                        $summary = $service->smartBackupPhysicalTable(
                            $identity,
                            $migrationId,
                            physicalConnector: $lockedConnector,
                        );
                        throw new \RuntimeException('rollback large physical backup');
                    },
                );
                self::fail('atomic callback must throw');
            } catch (\RuntimeException $exception) {
                self::assertSame('rollback large physical backup', $exception->getMessage());
            }

            self::assertSame('chunked', $summary['strategy'] ?? null);
            self::assertSame(10001, $summary['total_rows'] ?? null);
            self::assertSame([], $this->backupRecords($migrationId));
        } finally {
            $service->cleanupBackupData($migrationId);
            $connector->query("DROP SCHEMA {$quotedSchema} CASCADE")->fetch();
        }
    }

    public function testExactColumnBackupRejectsTableWithoutCatalogPrimaryKey(): void
    {
        $factory = ObjectManager::getInstance(ConnectionFactory::class);
        $connector = $factory->getConnector();
        self::assertInstanceOf(PhysicalTableMetadataInterface::class, $connector);
        $schema = 'weline_backup_column_no_pk_' . bin2hex(random_bytes(5));
        $identity = new PhysicalTableIdentity($schema, 'unit_probe');
        $quotedSchema = $connector->quoteIdentifier($schema);
        $migrationId = random_int(10_000_000, 900_000_000);
        $service = ObjectManager::getInstance(BackupService::class, [], false);
        $connector->query("CREATE SCHEMA {$quotedSchema}")->fetch();
        $connector->query(
            'CREATE TABLE ' . $connector->quotePhysicalTable($identity)
            . ' (id INTEGER NOT NULL, tracked TEXT NULL)',
        )->fetch();
        $connector->query(
            'INSERT INTO ' . $connector->quotePhysicalTable($identity) . " VALUES (1, 'value')",
        )->fetch();

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('complete primary key');
            $service->backupPhysicalColumnData($identity, 'tracked', $migrationId);
        } finally {
            $service->cleanupBackupData($migrationId);
            $connector->query("DROP SCHEMA {$quotedSchema} CASCADE")->fetch();
        }
    }

    public function testExactBackupRejectsBackupRepositoryAsTargetBeforeReadingIt(): void
    {
        $factory = ObjectManager::getInstance(ConnectionFactory::class);
        $connector = $factory->getConnector();
        $identity = $connector->resolvePhysicalTableIdentity(MigrationBackup::schema_table);
        $service = ObjectManager::getInstance(BackupService::class, [], false);
        $before = ObjectManager::getInstance(MigrationBackup::class, [], false)->reset()->total();

        try {
            $service->smartBackupPhysicalTable($identity, random_int(10_000_000, 900_000_000));
            self::fail('backup repository self-target must be rejected');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('backup repository', $exception->getMessage());
        }

        self::assertSame(
            $before,
            ObjectManager::getInstance(MigrationBackup::class, [], false)->reset()->total(),
        );
    }

    public function testRestoreMarkerTamperRollsBackExactPayloadRestore(): void
    {
        $factory = ObjectManager::getInstance(ConnectionFactory::class);
        $connector = $factory->getConnector();
        self::assertInstanceOf(PhysicalTableMetadataInterface::class, $connector);
        $schema = 'weline_restore_marker_' . bin2hex(random_bytes(5));
        $identity = new PhysicalTableIdentity($schema, 'unit_probe');
        $backupIdentity = $connector->resolvePhysicalTableIdentity(MigrationBackup::schema_table);
        $quotedSchema = $connector->quoteIdentifier($schema);
        $function = $quotedSchema . '.' . $connector->quoteIdentifier('tamper_restore_marker');
        $trigger = 'weline_restore_tamper_' . bin2hex(random_bytes(4));
        $migrationId = random_int(10_000_000, 900_000_000);
        $service = ObjectManager::getInstance(BackupService::class, [], false);
        $connector->query("CREATE SCHEMA {$quotedSchema}")->fetch();
        $connector->query(
            'CREATE TABLE ' . $connector->quotePhysicalTable($identity)
            . ' (id INTEGER PRIMARY KEY, marker TEXT NOT NULL)',
        )->fetch();
        $this->insertPhysical($connector, $identity, ['id' => 1, 'marker' => 'before']);
        $service->backupPhysicalTableData($identity, $migrationId);
        $backup = $this->firstBackupOfType($this->backupRecords($migrationId), MigrationBackup::TYPE_TABLE);
        $this->updatePhysical($connector, $identity, ['marker' => 'changed']);
        $statement = $connector->getWrappedConnection()->prepare(
            "CREATE FUNCTION {$function}() RETURNS trigger LANGUAGE plpgsql AS "
            . "\$\$BEGIN NEW.retention_state := 'protected'; NEW.restored_at := NULL; RETURN NEW; END\$\$",
        );
        $statement->execute();
        $connector->query(
            'CREATE TRIGGER ' . $connector->quoteIdentifier($trigger)
            . ' BEFORE UPDATE ON ' . $connector->quotePhysicalTable($backupIdentity)
            . " FOR EACH ROW WHEN (OLD.backup_id = " . (int)$backup->getId() . ")"
            . " EXECUTE FUNCTION {$function}()",
        )->fetch();

        try {
            self::assertFalse($service->restoreByBackupId((int)$backup->getId()));
            self::assertSame('changed', $this->physicalRow($connector, $identity)['marker'] ?? null);
        } finally {
            $connector->query(
                'DROP TRIGGER IF EXISTS ' . $connector->quoteIdentifier($trigger)
                . ' ON ' . $connector->quotePhysicalTable($backupIdentity),
            )->fetch();
            $service->cleanupBackupData($migrationId);
            $connector->query("DROP SCHEMA {$quotedSchema} CASCADE")->fetch();
        }
    }

    public function testPhysicalSnapshotRestoresGeneratedAlwaysIdentityOptionsAndSequenceState(): void
    {
        $factory = ObjectManager::getInstance(ConnectionFactory::class);
        $connector = $factory->getConnector();
        self::assertInstanceOf(PhysicalTableMetadataInterface::class, $connector);
        $schema = 'weline_identity_snapshot_' . bin2hex(random_bytes(5));
        $identity = new PhysicalTableIdentity($schema, 'unit_probe');
        $quotedSchema = $connector->quoteIdentifier($schema);
        $quotedTable = $connector->quotePhysicalTable($identity);
        $migrationId = random_int(10_000_000, 900_000_000);
        $service = ObjectManager::getInstance(BackupService::class, [], false);
        $connector->query("CREATE SCHEMA {$quotedSchema}")->fetch();
        $connector->query(
            "CREATE TABLE {$quotedTable} ("
            . 'id BIGINT GENERATED ALWAYS AS IDENTITY '
            . '(START WITH 10 INCREMENT BY 5 MINVALUE 10 MAXVALUE 1000 CACHE 3 CYCLE) PRIMARY KEY,'
            . ' base_value INTEGER NOT NULL,'
            . ' generated_value INTEGER GENERATED ALWAYS AS (base_value * 2) STORED)'
        )->fetch();
        $connector->query("INSERT INTO {$quotedTable} (base_value) VALUES (2), (3)")->fetch();
        $sequence = (string)$connector->getWrappedConnection()->getPdo()->query(
            'SELECT pg_get_serial_sequence('
            . $connector->getWrappedConnection()->getPdo()->quote($identity->canonical())
            . ", 'id')",
        )->fetchColumn();
        self::assertNotSame('', $sequence);
        $connector->query(
            'SELECT pg_catalog.setval('
            . $connector->getWrappedConnection()->getPdo()->quote($sequence)
            . '::regclass, 55, true)'
        )->fetch();

        try {
            $summary = $service->smartBackupPhysicalTable($identity, $migrationId);
            self::assertTrue($summary['structure_backed_up']);
            self::assertTrue($summary['data_backed_up']);
            $connector->dropPhysicalTableIfExists($identity);
            self::assertTrue($service->restorePhysicalTableStructure($identity, $migrationId));
            self::assertTrue($service->restorePhysicalTableData($identity, $migrationId));

            $rows = $connector->query(
                "SELECT id, base_value, generated_value FROM {$quotedTable} ORDER BY id",
            )->fetch();
            self::assertSame([
                ['id' => 10, 'base_value' => 2, 'generated_value' => 4],
                ['id' => 15, 'base_value' => 3, 'generated_value' => 6],
            ], $rows);
            $options = $connector->query(
                'SELECT seqstart, seqincrement, seqmin, seqmax, seqcache, seqcycle '
                . 'FROM pg_catalog.pg_sequence WHERE seqrelid = '
                . $connector->getWrappedConnection()->getPdo()->quote($sequence)
                . '::regclass',
            )->fetch();
            self::assertSame([[
                'seqstart' => 10,
                'seqincrement' => 5,
                'seqmin' => 10,
                'seqmax' => 1000,
                'seqcache' => 3,
                'seqcycle' => true,
            ]], $options);
            $next = $connector->query(
                "INSERT INTO {$quotedTable} (base_value) VALUES (4) RETURNING id, generated_value",
            )->fetch();
            self::assertSame([['id' => 60, 'generated_value' => 8]], $next);
        } finally {
            $service->cleanupBackupData($migrationId);
            $connector->query("DROP SCHEMA {$quotedSchema} CASCADE")->fetch();
        }
    }

    public function testPhysicalViewSnapshotRestoresCteSecurityOwnerAclAndComments(): void
    {
        $factory = ObjectManager::getInstance(ConnectionFactory::class);
        $connector = $factory->getConnector();
        $schema = 'weline_view_snapshot_' . bin2hex(random_bytes(5));
        $quotedSchema = $connector->quoteIdentifier($schema);
        $table = new PhysicalTableIdentity($schema, 'source_probe');
        $view = new PhysicalViewIdentity($schema, 'unit_view');
        $quotedTable = $connector->quotePhysicalTable($table);
        $quotedView = $connector->quotePhysicalView($view);
        $migrationId = random_int(10_000_000, 900_000_000);
        $service = ObjectManager::getInstance(BackupService::class, [], false);
        $connector->query("CREATE SCHEMA {$quotedSchema}")->fetch();
        $connector->query("CREATE TABLE {$quotedTable} (id INTEGER PRIMARY KEY, marker TEXT NOT NULL)")->fetch();
        $connector->query("INSERT INTO {$quotedTable} VALUES (1, 'before')")->fetch();
        $connector->query(
            "CREATE VIEW {$quotedView} WITH (security_barrier=true, security_invoker=true) AS "
            . "WITH filtered AS (SELECT id, marker FROM {$quotedTable} WHERE id > 0) "
            . 'SELECT id, marker FROM filtered',
        )->fetch();
        $connector->query("COMMENT ON VIEW {$quotedView} IS 'view-comment'")->fetch();
        $connector->query("COMMENT ON COLUMN {$quotedView}.marker IS 'column-comment'")->fetch();
        $connector->query("GRANT SELECT ON {$quotedView} TO PUBLIC")->fetch();

        try {
            $viewBackup = $service->backupPhysicalViewDefinition($view, $migrationId);
            $connector->query("DROP VIEW {$quotedView}")->fetch();
            $connector->query(
                "CREATE VIEW {$quotedView} AS SELECT id, marker FROM {$quotedTable} WHERE false",
            )->fetch();
            $connector->query("COMMENT ON VIEW {$quotedView} IS 'mutated'")->fetch();
            $connector->query("REVOKE ALL PRIVILEGES ON {$quotedView} FROM PUBLIC")->fetch();
            self::assertTrue($service->restorePhysicalViewDefinition($view, $migrationId));
            $this->assertBackupMarkedRestored((int)$viewBackup->getId());

            $catalog = $connector->query(
                "SELECT COALESCE(pg_catalog.array_to_json(c.reloptions)::text, '[]') AS reloptions, "
                . 'pg_catalog.pg_get_userbyid(c.relowner) AS owner, '
                . 'pg_catalog.obj_description(c.oid, ' . "'pg_class'" . ') AS view_comment '
                . 'FROM pg_catalog.pg_class c JOIN pg_catalog.pg_namespace n ON n.oid = c.relnamespace '
                . 'WHERE n.nspname = ' . $connector->getWrappedConnection()->getPdo()->quote($schema)
                . ' AND c.relname = ' . $connector->getWrappedConnection()->getPdo()->quote($view->view()),
            )->fetch();
            $options = json_decode((string)($catalog[0]['reloptions'] ?? '[]'), true, flags: JSON_THROW_ON_ERROR);
            $options = array_values(array_map('strval', is_array($options) ? $options : []));
            sort($options);
            self::assertContains('security_barrier=true', $options);
            self::assertContains('security_invoker=true', $options);
            self::assertSame('view-comment', $catalog[0]['view_comment'] ?? null);
            self::assertSame(
                (string)$connector->query('SELECT current_user AS owner')->fetch()[0]['owner'],
                $catalog[0]['owner'] ?? null,
            );
            $columnComment = $connector->query(
                'SELECT pg_catalog.col_description(c.oid, a.attnum) AS comment '
                . 'FROM pg_catalog.pg_class c JOIN pg_catalog.pg_namespace n ON n.oid = c.relnamespace '
                . 'JOIN pg_catalog.pg_attribute a ON a.attrelid = c.oid '
                . 'WHERE n.nspname = ' . $connector->getWrappedConnection()->getPdo()->quote($schema)
                . ' AND c.relname = ' . $connector->getWrappedConnection()->getPdo()->quote($view->view())
                . " AND a.attname = 'marker'",
            )->fetch();
            self::assertSame('column-comment', $columnComment[0]['comment'] ?? null);
            $publicSelect = $connector->query(
                'SELECT EXISTS (SELECT 1 FROM pg_catalog.pg_class c '
                . 'JOIN pg_catalog.pg_namespace n ON n.oid = c.relnamespace '
                . 'CROSS JOIN LATERAL pg_catalog.aclexplode(COALESCE(c.relacl, '
                . "pg_catalog.acldefault('r', c.relowner))) acl "
                . 'WHERE n.nspname = ' . $connector->getWrappedConnection()->getPdo()->quote($schema)
                . ' AND c.relname = ' . $connector->getWrappedConnection()->getPdo()->quote($view->view())
                . " AND acl.grantee = 0 AND acl.privilege_type = 'SELECT') AS granted",
            )->fetch();
            self::assertTrue((bool)($publicSelect[0]['granted'] ?? false));
            self::assertSame([['id' => 1, 'marker' => 'before']], $connector->query(
                "SELECT id, marker FROM {$quotedView}",
            )->fetch());
        } finally {
            $service->cleanupBackupData($migrationId);
            $connector->query("DROP SCHEMA {$quotedSchema} CASCADE")->fetch();
        }
    }

    public function testRestoreByBackupIdRoutesEveryExactTableTypeAndPersistsWholeGroupMarkers(): void
    {
        $factory = ObjectManager::getInstance(ConnectionFactory::class);
        $connector = $factory->getConnector();
        $schema = 'weline_restore_by_group_' . bin2hex(random_bytes(5));
        $identity = new PhysicalTableIdentity($schema, 'unit_probe');
        $quotedSchema = $connector->quoteIdentifier($schema);
        $quotedTable = $connector->quotePhysicalTable($identity);
        $migrationId = random_int(10_000_000, 900_000_000);
        $service = ObjectManager::getInstance(BackupService::class, [], false);
        $connector->query("CREATE SCHEMA {$quotedSchema}")->fetch();
        $connector->query(
            "CREATE TABLE {$quotedTable} (id INTEGER PRIMARY KEY, marker TEXT NOT NULL, tracked TEXT NULL)",
        )->fetch();
        $connector->query("INSERT INTO {$quotedTable} VALUES (1, 'before', 'tracked-before')")->fetch();

        try {
            $service->smartBackupPhysicalTable($identity, $migrationId);
            $service->backupPhysicalColumnData($identity, 'tracked', $migrationId);
            $service->backupPhysicalTableDataChunked($identity, $migrationId, 1);
            $records = $this->backupRecords($migrationId);
            $structure = $this->firstBackupOfType($records, MigrationBackup::TYPE_STRUCTURE);
            $table = $this->firstBackupOfType($records, MigrationBackup::TYPE_TABLE);
            $column = $this->firstBackupOfType($records, MigrationBackup::TYPE_COLUMN);
            $chunks = array_values(array_filter(
                $records,
                static fn(MigrationBackup $backup): bool =>
                    $backup->getData(MigrationBackup::schema_fields_BACKUP_TYPE) === MigrationBackup::TYPE_CHUNK,
            ));
            self::assertNotEmpty($chunks);

            $connector->query("UPDATE {$quotedTable} SET marker = 'table-mutated'")->fetch();
            self::assertTrue($service->restoreByBackupId((int)$table->getId()));
            self::assertSame('before', $this->physicalRow($connector, $identity)['marker'] ?? null);
            $this->assertBackupMarkedRestored((int)$table->getId());

            $connector->query("UPDATE {$quotedTable} SET tracked = NULL")->fetch();
            self::assertTrue($service->restoreByBackupId((int)$column->getId()));
            self::assertSame('tracked-before', $this->physicalRow($connector, $identity)['tracked'] ?? null);
            $this->assertBackupMarkedRestored((int)$column->getId());

            $connector->query("UPDATE {$quotedTable} SET marker = 'chunk-mutated'")->fetch();
            self::assertTrue($service->restoreByBackupId((int)$chunks[0]->getId()));
            self::assertSame('before', $this->physicalRow($connector, $identity)['marker'] ?? null);
            foreach ($chunks as $chunk) {
                $this->assertBackupMarkedRestored((int)$chunk->getId());
            }

            self::assertTrue($service->restoreByBackupId((int)$structure->getId()));
            $this->assertBackupMarkedRestored((int)$structure->getId());
        } finally {
            $service->cleanupBackupData($migrationId);
            $connector->query("DROP SCHEMA {$quotedSchema} CASCADE")->fetch();
        }
    }

    private function insertPhysical(ConnectorInterface $connector, PhysicalTableIdentity $identity, array $row): void
    {
        $query = $connector->getQuery();
        self::assertInstanceOf(PhysicalTableQueryInterface::class, $query);
        $query->clearQuery()->tablePhysical($identity)->insert($row)->fetch();
    }

    private function updatePhysical(ConnectorInterface $connector, PhysicalTableIdentity $identity, array $row): void
    {
        $query = $connector->getQuery();
        self::assertInstanceOf(PhysicalTableQueryInterface::class, $query);
        $query->clearQuery()->tablePhysical($identity)->where('id', 1)->update($row)->fetch();
    }

    private function physicalRow(ConnectorInterface $connector, PhysicalTableIdentity $identity): array
    {
        $query = $connector->getQuery();
        self::assertInstanceOf(PhysicalTableQueryInterface::class, $query);
        $rows = $query->clearQuery()->tablePhysical($identity)->where('id', 1)->limit(1)->select()->fetch();
        return is_array($rows[0] ?? null) ? $rows[0] : [];
    }

    /** @return list<MigrationBackup> */
    private function backupRecords(int $migrationId): array
    {
        return ObjectManager::getInstance(MigrationBackup::class, [], false)
            ->reset()
            ->where(MigrationBackup::schema_fields_MIGRATION_ID, $migrationId)
            ->order(MigrationBackup::schema_fields_ID, 'ASC')
            ->select()
            ->fetch()
            ->getItems();
    }

    /** @param list<MigrationBackup> $records */
    private function firstBackupOfType(array $records, string $type): MigrationBackup
    {
        foreach ($records as $record) {
            if ($record->getData(MigrationBackup::schema_fields_BACKUP_TYPE) === $type) {
                return $record;
            }
        }
        self::fail("missing {$type} backup");
    }

    private function assertBackupMarkedRestored(int $backupId): void
    {
        $backup = ObjectManager::getInstance(MigrationBackup::class, [], false)
            ->reset()
            ->where(MigrationBackup::schema_fields_ID, $backupId);
        $backup->find()->fetch();
        self::assertSame($backupId, (int)$backup->getId());
        self::assertSame(
            MigrationBackup::RETENTION_EXPIRING,
            $backup->getData(MigrationBackup::schema_fields_RETENTION_STATE),
        );
        self::assertNotSame('', trim((string)$backup->getData(MigrationBackup::schema_fields_RESTORED_AT)));
    }
}
