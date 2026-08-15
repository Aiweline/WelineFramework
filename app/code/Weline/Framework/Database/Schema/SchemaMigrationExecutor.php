<?php

declare(strict_types=1);

namespace Weline\Framework\Database\Schema;

use Weline\Framework\Database\Connection\Adapter\Pgsql\PgsqlIndexName;
use Weline\Framework\Database\Connection\Adapter\Pgsql\Connector as PgsqlConnector;
use Weline\Framework\Database\Connection\Adapter\Sqlite\Connector as SqliteConnector;
use Weline\Framework\Database\Connection\Api\ConnectorInterface;
use Weline\Framework\Database\Connection\Api\AtomicPhysicalTableChangeInterface;
use Weline\Framework\Database\Connection\Api\PhysicalTableIdentity;
use Weline\Framework\Database\Connection\Api\PhysicalTableIdentityProviderInterface;
use Weline\Framework\Database\Connection\Api\PhysicalTableMetadataInterface;
use Weline\Framework\Database\Connection\Api\PhysicalTableSnapshotInterface;
use Weline\Framework\Setup\Model\Migration;
use Weline\Framework\Database\Service\BackupService;
use Weline\Framework\DataObject\DataObject;
use Weline\Framework\Event\EventsManager;
use Weline\Framework\Setup\Model\MigrationBackup;

/**
 * 执行 SchemaDiffOp 列表：生成 DDL/rollback、记录 Migration、DROP 前备份、派发 table_ddl_before/after。
 */
final class SchemaMigrationExecutor implements SchemaMigrationExecutorInterface
{
    public const EVENT_TABLE_DDL_BEFORE = 'Weline_Framework_Schema::table_ddl_before';
    public const EVENT_TABLE_DDL_AFTER = 'Weline_Framework_Schema::table_ddl_after';

    private const CONNECTION_NAME_DEFAULT = 'default';

    public function __construct(
        private readonly EventsManager $eventsManager,
        private readonly Migration $migrationModel,
        private readonly BackupService $backupService,
    ) {
    }

    /** 先创建列再创建依赖；删除时先外键/索引再列。回滚按 sequence 倒序执行时恰好相反。 */
    private const KIND_PRIORITY = [
        SchemaDiffOp::KIND_CREATE_TABLE => 0,
        SchemaDiffOp::KIND_ADD_COLUMN => 1,
        SchemaDiffOp::KIND_MODIFY_COLUMN => 2,
        SchemaDiffOp::KIND_ADD_INDEX => 3,
        SchemaDiffOp::KIND_ADD_FOREIGN_KEY => 4,
        SchemaDiffOp::KIND_DROP_FOREIGN_KEY => 5,
        SchemaDiffOp::KIND_DROP_INDEX => 6,
        SchemaDiffOp::KIND_DROP_COLUMN => 7,
        SchemaDiffOp::KIND_MODIFY_TABLE_COMMENT => 8,
    ];

    /**
     * 执行一组差异操作；单条 DDL 失败即抛异常。每条 DDL 记录 forward_ddl/rollback_ddl，DROP COLUMN 前备份列数据。
     * 按表名+操作类型排序，确保 ADD_COLUMN 先于 ADD_INDEX/ADD_FOREIGN_KEY 执行。
     *
     * @param list<SchemaDiffOp> $ops
     */
    public function execute(ConnectorInterface $connector, array $ops, array $context = []): void
    {
        $connectionName = self::CONNECTION_NAME_DEFAULT;
        $moduleVersions = is_array($context['module_versions'] ?? null) ? $context['module_versions'] : [];
        $tableFingerprints = is_array($context['table_fingerprints'] ?? null) ? $context['table_fingerprints'] : [];
        $moduleSchemaFingerprints = is_array($context['module_schema_fingerprints'] ?? null)
            ? $context['module_schema_fingerprints']
            : [];
        $moduleSchemaFingerprintCandidates = is_array($context['module_schema_fingerprint_candidates'] ?? null)
            ? $context['module_schema_fingerprint_candidates']
            : [];
        $checkpointRuntimeQualifiers = is_array($context['checkpoint_runtime_qualifiers'] ?? null)
            ? array_values(array_filter(
                array_map('strval', $context['checkpoint_runtime_qualifiers']),
                static fn(string $qualifier): bool => $qualifier !== '',
            ))
            : [];
        $operationId = trim((string)($context['operation_id'] ?? ''));
        $forceSchemaRebind = !empty($context['force_schema_rebind']);
        $physicalTableFingerprints = is_array($context['physical_table_fingerprints'] ?? null)
            ? array_map('strval', $context['physical_table_fingerprints'])
            : [];
        $batchIds = [];
        $sequences = [];
        $checkpointState = $this->prepareSchemaCheckpoints(
            $moduleVersions,
            $moduleSchemaFingerprints,
            $moduleSchemaFingerprintCandidates,
            $forceSchemaRebind,
        );

        $sqlitePrimaryKeyTransferTables = $this->sqlitePrimaryKeyTransferTables($connector, $ops);
        usort($ops, function (SchemaDiffOp $a, SchemaDiffOp $b) use ($sqlitePrimaryKeyTransferTables): int {
            $cmp = strcmp($a->tableName, $b->tableName);
            if ($cmp !== 0) {
                return $cmp;
            }
            if (isset($sqlitePrimaryKeyTransferTables[$a->tableName])) {
                $pa = $this->sqlitePrimaryKeyTransferPriority($a);
                $pb = $this->sqlitePrimaryKeyTransferPriority($b);
                if ($pa !== $pb) {
                    return $pa <=> $pb;
                }
            }
            $pa = self::KIND_PRIORITY[$a->kind] ?? 99;
            $pb = self::KIND_PRIORITY[$b->kind] ?? 99;
            return $pa <=> $pb;
        });

        foreach ($ops as $op) {
            $rollbackSql = $this->buildRollbackDdl($connector, $op);
            $forwardSql = $this->buildDdl($connector, $op);

            if ($op->kind === SchemaDiffOp::KIND_CREATE_TABLE && $op->payload instanceof TableSchema) {
                $forwardSql = 'CREATE TABLE IF NOT EXISTS ' . $connector->quoteTable($op->tableName) . ' (via Create API)';
            }

            if ($forwardSql === '') {
                $this->dispatchBefore($op);
                $this->dispatchAfter($op);
                continue;
            }

            $destructive = in_array(
                $op->kind,
                [
                    SchemaDiffOp::KIND_DROP_COLUMN,
                    SchemaDiffOp::KIND_MODIFY_COLUMN,
                    SchemaDiffOp::KIND_DROP_INDEX,
                    SchemaDiffOp::KIND_DROP_FOREIGN_KEY,
                ],
                true,
            );
            $physicalIdentity = $connector instanceof PhysicalTableIdentityProviderInterface
                ? $connector->resolvePhysicalTableIdentity($op->tableName)
                : null;
            $expectedPhysicalFingerprint = trim((string)($physicalTableFingerprints[$op->tableName] ?? ''));
            if ($destructive) {
                if (!$connector instanceof PhysicalTableSnapshotInterface
                    || !$physicalIdentity instanceof PhysicalTableIdentity) {
                    throw new \RuntimeException('physical table catalog fingerprint capability unavailable');
                }
                if (preg_match('/\A[a-f0-9]{64}\z/D', $expectedPhysicalFingerprint) !== 1) {
                    throw new \RuntimeException('destructive SchemaDiff lacks before catalog fingerprint');
                }
            }

            $moduleName = $this->moduleNameFromClass($op->modelClass);
            if (!isset($batchIds[$moduleName])) {
                $batchIds[$moduleName] = sprintf(
                    'schema-%s-%s-%s',
                    preg_replace('/[^A-Za-z0-9_.-]/', '-', $moduleName),
                    preg_replace('/[^A-Za-z0-9_.-]/', '-', (string)($moduleVersions[$moduleName] ?? 'legacy')),
                    bin2hex(random_bytes(6))
                );
                $sequences[$moduleName] = 0;
            }
            $sequences[$moduleName]++;
            $fingerprints = is_array($tableFingerprints[$op->tableName] ?? null)
                ? $tableFingerprints[$op->tableName]
                : [];
            $currentCheckpoint = $checkpointState[$moduleName]['current'] ?? null;
            $previousCheckpoint = $checkpointState[$moduleName]['previous'] ?? null;
            $declaredFingerprint = SchemaCheckpointIdentity::fingerprint(
                (array)($checkpointState[$moduleName]['tables'] ?? []),
                $op->tableName,
                $checkpointRuntimeQualifiers,
            ) ?? (string)($fingerprints['after'] ?? '');
            if ($currentCheckpoint === null && is_array($previousCheckpoint)) {
                $fingerprints['before'] = SchemaCheckpointIdentity::fingerprint(
                    (array)($previousCheckpoint['tables'] ?? []),
                    $op->tableName,
                    $checkpointRuntimeQualifiers,
                ) ?? hash('sha256', 'absent');
            }
            if ($declaredFingerprint !== '') {
                $fingerprints['after'] = $declaredFingerprint;
            }
            try {
                $migrationId = $this->migrationModel->recordSchemaDdl(
                    $moduleName,
                    $op->tableName,
                    $connectionName,
                    $forwardSql,
                    $rollbackSql,
                    $op->modelClass,
                    (string)($moduleVersions[$moduleName] ?? ''),
                    $batchIds[$moduleName],
                    $sequences[$moduleName],
                    $op->kind,
                    (string)($fingerprints['before'] ?? ''),
                    (string)($fingerprints['after'] ?? ''),
                    $operationId,
                    $this->normalizeOperationPayload($op->payload),
                );
            } catch (\Throwable $e) {
                throw $e;
            }
            if ($migrationId <= 0) {
                throw new \RuntimeException(__(
                    'Schema DDL 审计写入失败（migration_id=0），已中止执行：module=%{1} table=%{2} kind=%{3}',
                    [$moduleName, $op->tableName, $op->kind]
                ));
            }
            try {
                if ($destructive) {
                    if (!$connector instanceof PhysicalTableMetadataInterface
                        || !$connector instanceof PhysicalTableIdentityProviderInterface) {
                        throw new \RuntimeException('exact physical table capability unavailable');
                    }
                    if (!$connector instanceof AtomicPhysicalTableChangeInterface) {
                        throw new \RuntimeException('atomic physical table change capability unavailable');
                    }
                    $physicalIdentity ??= $connector->resolvePhysicalTableIdentity($op->tableName);
                    $connector->atomicPhysicalTableChange(
                        $physicalIdentity,
                        function (ConnectorInterface $lockedConnector) use (
                            $connector,
                            $op,
                            $forwardSql,
                            $moduleName,
                            $migrationId,
                            $physicalIdentity,
                            $expectedPhysicalFingerprint,
                            $operationId,
                            &$physicalTableFingerprints,
                        ): void {
                            if ($lockedConnector !== $connector) {
                                throw new \RuntimeException(
                                    'atomic physical table connector changed during SchemaDiff',
                                );
                            }
                            if (!$lockedConnector instanceof PhysicalTableSnapshotInterface
                                || !hash_equals(
                                    $expectedPhysicalFingerprint,
                                    $lockedConnector->physicalTableCatalogFingerprint($physicalIdentity),
                                )) {
                                throw new \RuntimeException(
                                    'physical table catalog fingerprint changed before destructive SchemaDiff lock',
                                );
                            }
                            $this->executeRecordedOperation(
                                $lockedConnector,
                                $op,
                                $forwardSql,
                                $moduleName,
                                $migrationId,
                                $physicalIdentity,
                                $operationId,
                            );
                            $physicalTableFingerprints[$op->tableName]
                                = $lockedConnector->physicalTableCatalogFingerprint($physicalIdentity);
                        },
                    );
                } else {
                    $this->executeRecordedOperation(
                        $connector,
                        $op,
                        $forwardSql,
                        $moduleName,
                        $migrationId,
                        $physicalIdentity,
                        $operationId,
                    );
                    if ($physicalIdentity instanceof PhysicalTableIdentity
                        && $connector instanceof PhysicalTableSnapshotInterface) {
                        $physicalTableFingerprints[$op->tableName]
                            = $connector->physicalTableCatalogFingerprint($physicalIdentity);
                    }
                }
            } catch (\Throwable $e) {
                $this->markMigrationFailed($migrationId, $operationId);
                throw $e;
            }
        }

        foreach ($checkpointState as $moduleName => $state) {
            $this->migrationModel->recordSchemaCheckpoint(
                $moduleName,
                (string)$state['version'],
                (array)$state['tables'],
                $operationId,
            );
        }
    }

    private function executeRecordedOperation(
        ConnectorInterface $connector,
        SchemaDiffOp $op,
        string $forwardSql,
        string $moduleName,
        int $migrationId,
        ?PhysicalTableIdentity $physicalIdentity,
        string $operationId,
    ): void {
        if (in_array(
            $op->kind,
            [SchemaDiffOp::KIND_DROP_COLUMN, SchemaDiffOp::KIND_MODIFY_COLUMN],
            true,
        )) {
            if (!$op->payload instanceof ColumnDefinition || $physicalIdentity === null) {
                throw new \RuntimeException('destructive SchemaDiff lacks exact column identity');
            }
            $reason = $op->kind === SchemaDiffOp::KIND_DROP_COLUMN ? 'DROP' : 'MODIFY';
            $this->backupService->backupPhysicalColumnData(
                $physicalIdentity,
                $op->payload->name,
                $migrationId,
                $op->modelClass,
                $reason,
                physicalConnector: $connector,
            );
        } elseif (in_array(
            $op->kind,
            [SchemaDiffOp::KIND_DROP_INDEX, SchemaDiffOp::KIND_DROP_FOREIGN_KEY],
            true,
        )) {
            if ($physicalIdentity === null || !$this->backupService->backupPhysicalTableStructure(
                $physicalIdentity,
                $migrationId,
                physicalConnector: $connector,
            )) {
                throw new \RuntimeException('destructive SchemaDiff structure backup failed');
            }
        }

        // Event observers execute inside the adapter savepoint for destructive
        // changes; direct commit/rollback therefore aborts the whole operation.
        $this->dispatchBefore($op);
        if ($op->kind === SchemaDiffOp::KIND_CREATE_TABLE && $op->payload instanceof TableSchema) {
            $this->createTableViaAdapter($connector, $op->tableName, $op->payload, true);
        } else {
            $sqliteRebuild = str_contains($forwardSql, '/* WELINE_SQLITE_REBUILD */');
            $ddl = str_replace('/* WELINE_SQLITE_REBUILD */', '', $forwardSql);
            try {
                if ($sqliteRebuild) {
                    $connector->query('PRAGMA foreign_keys=OFF')->fetch();
                    $connector->beginTransaction();
                }
                foreach ($this->splitDdlStatements($ddl) as $sql) {
                    if (trim($sql) === '') {
                        continue;
                    }
                    try {
                        $connector->query($sql)->fetch();
                    } catch (\Throwable $e) {
                        $column = $op->payload instanceof ColumnDefinition ? $op->payload->name : '';
                        $context = "table={$op->tableName} kind={$op->kind}"
                            . ($column !== '' ? " col={$column}" : '');
                        throw new \RuntimeException(
                            "Schema DDL failed ({$context}): " . $e->getMessage(),
                            0,
                            $e,
                        );
                    }
                }
                if ($sqliteRebuild) {
                    $connector->commit();
                }
            } catch (\Throwable $e) {
                if ($sqliteRebuild) {
                    $connector->rollBack();
                }
                throw $e;
            } finally {
                if ($sqliteRebuild) {
                    $connector->query('PRAGMA foreign_keys=ON')->fetch();
                }
            }
        }

        $this->assertIndexPostcondition($connector, $op);
        $this->dispatchAfter($op);
        if (in_array($op->kind, [SchemaDiffOp::KIND_ADD_COLUMN, SchemaDiffOp::KIND_MODIFY_COLUMN], true)
            && $op->payload instanceof ColumnDefinition) {
            $this->restorePreviouslyRolledBackColumnData(
                $moduleName,
                $op->tableName,
                $op->payload,
                $migrationId,
                $connector,
                $op->modelClass,
                $physicalIdentity,
            );
        }
        $this->migrationModel->compareAndSwapStatusFailClosed(
            $migrationId,
            Migration::STATUS_RUNNING,
            Migration::STATUS_INSTALLED,
            $operationId,
        );
    }

    /**
     * SQLite cannot add a PRIMARY KEY column. A legacy primary key must be
     * demoted (or removed) before the replacement key is added by table rebuild.
     *
     * @param list<SchemaDiffOp> $ops
     * @return array<string, true>
     */
    private function sqlitePrimaryKeyTransferTables(ConnectorInterface $connector, array $ops): array
    {
        if (strtolower((string)$connector->getConfigProvider()->getDbType()) !== 'sqlite') {
            return [];
        }
        $adds = [];
        $removals = [];
        foreach ($ops as $op) {
            if ($op->kind === SchemaDiffOp::KIND_ADD_COLUMN
                && $op->payload instanceof ColumnDefinition
                && $op->payload->primaryKey) {
                $adds[$op->tableName] = true;
            }
            if ($this->isPrimaryKeyRemoval($op)) {
                $removals[$op->tableName] = true;
            }
        }

        return array_intersect_key($adds, $removals);
    }

    private function sqlitePrimaryKeyTransferPriority(SchemaDiffOp $op): int
    {
        if ($op->kind === SchemaDiffOp::KIND_DROP_FOREIGN_KEY) {
            return 0;
        }
        if ($op->kind === SchemaDiffOp::KIND_DROP_INDEX) {
            return 1;
        }
        if ($this->isPrimaryKeyRemoval($op)) {
            return 2;
        }
        if ($op->kind === SchemaDiffOp::KIND_ADD_COLUMN
            && $op->payload instanceof ColumnDefinition
            && $op->payload->primaryKey) {
            return 3;
        }

        return 10 + (self::KIND_PRIORITY[$op->kind] ?? 99);
    }

    private function isPrimaryKeyRemoval(SchemaDiffOp $op): bool
    {
        if ($op->kind === SchemaDiffOp::KIND_DROP_COLUMN
            && $op->payload instanceof ColumnDefinition) {
            return $op->payload->primaryKey;
        }
        return $op->kind === SchemaDiffOp::KIND_MODIFY_COLUMN
            && $op->payload instanceof ColumnDefinition
            && $op->rollbackPayload instanceof ColumnDefinition
            && $op->rollbackPayload->primaryKey
            && !$op->payload->primaryKey;
    }

    /**
     * Validate immutable checkpoints before executing DDL. For a first upgrade
     * to a version, the previous checkpoint supplies the logical "before"
     * fingerprint; same-version drift keeps the physical fingerprint so the
     * rollback planner can detect and block an unverifiable chain.
     *
     * @param array<string, string> $moduleVersions
     * @param array<string, array<string, string>> $moduleSchemaFingerprints
     * @param array<string, list<array<string, string>>> $moduleSchemaFingerprintCandidates
     * @return array<string, array{version: string, tables: array<string, string>, current: ?array, previous: ?array}>
     */
    private function prepareSchemaCheckpoints(
        array $moduleVersions,
        array $moduleSchemaFingerprints,
        array $moduleSchemaFingerprintCandidates = [],
        bool $forceSchemaRebind = false,
    ): array {
        $state = [];
        foreach ($moduleVersions as $moduleName => $moduleVersion) {
            $moduleName = trim((string)$moduleName);
            $moduleVersion = trim((string)$moduleVersion);
            if ($moduleName === '') {
                continue;
            }
            $tables = is_array($moduleSchemaFingerprints[$moduleName] ?? null)
                ? $moduleSchemaFingerprints[$moduleName]
                : [];
            $candidates = is_array($moduleSchemaFingerprintCandidates[$moduleName] ?? null)
                ? $moduleSchemaFingerprintCandidates[$moduleName]
                : [];
            try {
                $existing = $this->migrationModel->getSchemaCheckpoint($moduleName, $moduleVersion);
            } catch (SchemaCheckpointDataException $exception) {
                if (!$forceSchemaRebind) {
                    throw $exception;
                }
                $this->supersedeSchemaCheckpointForRebind(
                    $moduleName,
                    $moduleVersion,
                    'invalid-checkpoint',
                );
                $existing = null;
            }
            if ($candidates !== [] && $existing !== null) {
                $matched = null;
                foreach ($candidates as $candidate) {
                    if (!is_array($candidate)) {
                        continue;
                    }
                    $checksum = $this->migrationModel->schemaCheckpointChecksum(
                        $candidate,
                        (int)$existing['format'],
                    );
                    if (hash_equals((string)$existing['checksum'], $checksum)) {
                        $matched = $candidate;
                        break;
                    }
                }
                if ($matched !== null) {
                    $tables = (array)$existing['tables'];
                }
            }
            if ($forceSchemaRebind && $existing !== null) {
                    $expectedChecksum = $this->migrationModel->schemaCheckpointChecksum(
                        $tables,
                        (int)$existing['format'],
                    );
                    if (!hash_equals((string)$existing['checksum'], $expectedChecksum)) {
                        $this->supersedeSchemaCheckpointForRebind(
                            $moduleName,
                            $moduleVersion,
                            'checksum-mismatch',
                        );
                        $existing = null;
                    }
            }
            $current = $this->migrationModel->assertSchemaCheckpointCompatible(
                $moduleName,
                $moduleVersion,
                $tables,
            );
            $state[$moduleName] = [
                'version' => $moduleVersion,
                'tables' => $tables,
                'current' => $current,
                'previous' => $current === null
                    ? $this->migrationModel->getLatestSchemaCheckpointBefore($moduleName, $moduleVersion)
                    : null,
            ];
        }

        return $state;
    }

    private function supersedeSchemaCheckpointForRebind(
        string $moduleName,
        string $moduleVersion,
        string $reason,
    ): void {
        $superseded = $this->migrationModel->supersedeSchemaCheckpoint(
            $moduleName,
            $moduleVersion,
            'force-schema-rebind:' . $reason,
        );
        if (function_exists('w_log_warning')) {
            w_log_warning(sprintf(
                'Schema checkpoint rebind: module=%s version=%s reason=%s superseded_rows=%d',
                $moduleName,
                $moduleVersion,
                $reason,
                $superseded,
            ));
        }
    }

    private function markMigrationFailed(int $migrationId, string $operationId): void
    {
        if ($migrationId <= 0) {
            return;
        }
        $this->migrationModel->compareAndSwapStatusFailClosed(
            $migrationId,
            Migration::STATUS_RUNNING,
            Migration::STATUS_FAILED,
            $operationId,
        );
    }

    private function normalizeOperationPayload(mixed $payload): array
    {
        if (is_object($payload)) {
            $payload = get_object_vars($payload);
        }
        if (!is_array($payload)) {
            return ['value' => $payload];
        }
        foreach ($payload as $key => $value) {
            if (is_object($value)) {
                $payload[$key] = get_object_vars($value);
            } elseif (is_array($value)) {
                $payload[$key] = $this->normalizeOperationPayload($value);
            }
        }
        return $payload;
    }

    private function restorePreviouslyRolledBackColumnData(
        string $moduleName,
        string $tableName,
        ColumnDefinition $column,
        int $currentMigrationId,
        ConnectorInterface $connector,
        ?string $modelClass,
        ?PhysicalTableIdentity $physicalIdentity = null,
    ): void {
        $items = (clone $this->migrationModel)->reset()
            ->where(Migration::schema_fields_MODULE, $moduleName)
            ->where(Migration::schema_fields_FILE, 'schema_diff')
            ->where(Migration::schema_fields_SCHEMA_TABLE_NAME, $tableName)
            ->where(Migration::schema_fields_STATUS, Migration::STATUS_ROLLED_BACK)
            ->order(Migration::schema_fields_ROLLBACK_AT, 'DESC')
            ->select()
            ->fetch()
            ->getItems();

        foreach ($items as $item) {
            $migrationId = (int)$item->getId();
            if ($migrationId <= 0 || $migrationId === $currentMigrationId) {
                continue;
            }
            if (!in_array(
                (string)$item->getData(Migration::schema_fields_OPERATION_KIND),
                [SchemaDiffOp::KIND_ADD_COLUMN, SchemaDiffOp::KIND_MODIFY_COLUMN],
                true
            )) {
                continue;
            }
            $payload = json_decode((string)$item->getData(Migration::schema_fields_OPERATION_PAYLOAD), true);
            if (!is_array($payload) || (string)($payload['name'] ?? '') !== $column->name) {
                continue;
            }
            $result = $physicalIdentity !== null
                ? $this->backupService->restorePhysicalColumnDataConflictSafe(
                    $physicalIdentity,
                    $column->name,
                    $migrationId,
                    $column->default,
                    MigrationBackup::SCOPE_ROLLBACK,
                )
                : $this->backupService->restoreColumnDataConflictSafe(
                    $tableName,
                    $column->name,
                    $migrationId,
                    $connector,
                    $modelClass,
                    $column->default,
                    MigrationBackup::SCOPE_ROLLBACK,
                );
            if (($result['conflicts'] ?? 0) > 0) {
                $this->eventsManager->dispatch('Weline_Framework_Schema::column_restore_conflict', new DataObject([
                    'module_name' => $moduleName,
                    'table_name' => $tableName,
                    'column_name' => $column->name,
                    'migration_id' => $migrationId,
                    'conflicts' => $result['conflicts'],
                ]));
            }
            break;
        }
    }

    private function dispatchBefore(SchemaDiffOp $op): void
    {
        $data = new DataObject([
            'module_name' => $this->moduleNameFromClass($op->modelClass),
            'table_name' => $op->tableName,
            'model_class' => $op->modelClass,
        ]);
        $this->eventsManager->dispatch(self::EVENT_TABLE_DDL_BEFORE, $data);
    }

    private function dispatchAfter(SchemaDiffOp $op): void
    {
        $data = new DataObject([
            'module_name' => $this->moduleNameFromClass($op->modelClass),
            'table_name' => $op->tableName,
            'model_class' => $op->modelClass,
        ]);
        $this->eventsManager->dispatch(self::EVENT_TABLE_DDL_AFTER, $data);
    }

    private function assertIndexPostcondition(ConnectorInterface $connector, SchemaDiffOp $op): void
    {
        if (!$op->payload instanceof IndexDefinition
            || !in_array($op->kind, [SchemaDiffOp::KIND_ADD_INDEX, SchemaDiffOp::KIND_DROP_INDEX], true)) {
            return;
        }

        $databaseType = strtolower($connector->getConfigProvider()->getDbType());
        $formattedTable = $connector->formatTableName($op->tableName);
        if ($op->kind === SchemaDiffOp::KIND_ADD_INDEX) {
            $actual = $this->findPhysicalIndex($connector, $op->tableName, $op->payload);
            if ($actual !== null
                && IndexDefinitionContract::equals($op->payload, $actual, $databaseType)) {
                return;
            }
            throw new \RuntimeException(__(
                '表 %{1} 的索引 %{2} 新增后未通过物理定义回读',
                [$formattedTable, $op->payload->name],
            ));
        }

        $rows = $connector->getTableIndexes($op->tableName);
        if ($databaseType === 'pgsql') {
            // DROP ops are produced from concrete physical PG rows.  Check
            // exactly that target, not every backward-compatible candidate.
            $physicalTarget = PgsqlIndexName::rawPhysical($op->payload->name);
            foreach ($rows as $row) {
                if ((string)($row['name'] ?? '') !== $physicalTarget) {
                    continue;
                }
                throw new \RuntimeException(__(
                    'PostgreSQL 表 %{1} 的索引 %{2} 删除后仍然存在',
                    [$formattedTable, $op->payload->name],
                ));
            }
            return;
        }

        $expectedIdentity = IndexDefinitionContract::physicalIdentity(
            $connector,
            $op->tableName,
            $op->payload->name,
        );
        foreach ($rows as $row) {
            $actualName = trim((string)($row['name'] ?? ''));
            if ($actualName === '') {
                continue;
            }
            if (strtolower($actualName) === strtolower($op->payload->name)
                || IndexDefinitionContract::physicalIdentity($connector, $op->tableName, $actualName) === $expectedIdentity) {
                throw new \RuntimeException(__(
                    '表 %{1} 的索引 %{2} 删除后仍然存在',
                    [$formattedTable, $op->payload->name],
                ));
            }
        }
    }

    /** 按 ";\n" 拆分 DDL，供需多条语句的方言（如 Pgsql 自增列 SET DEFAULT）使用 */
    private function splitDdlStatements(string $sql): array
    {
        $normalized = str_replace("\r\n", "\n", $sql);
        if (str_contains($normalized, "\n-- WELINE_DDL_STATEMENT\n")) {
            return explode("\n-- WELINE_DDL_STATEMENT\n", $normalized);
        }
        if (!str_contains($normalized, ";\n")) {
            return [$normalized];
        }
        return explode(";\n", $normalized);
    }

    private function moduleNameFromClass(?string $modelClass): string
    {
        if ($modelClass === null || $modelClass === '') {
            return '';
        }
        $parts = explode('\\', $modelClass);
        $first = $parts[0] ?? '';
        $second = $parts[1] ?? '';
        return $first . ($second !== '' ? '_' . $second : '');
    }

    private function buildDdl(ConnectorInterface $connector, SchemaDiffOp $op): string
    {
        // 使用 formatTableName 将逻辑表名转换为物理表名（添加前缀和 schema）
        $table = $connector->formatTableName($op->tableName);
        switch ($op->kind) {
            case SchemaDiffOp::KIND_CREATE_TABLE:
                return ''; // 使用 createTableViaAdapter，由适配器处理方言
            case SchemaDiffOp::KIND_ADD_COLUMN:
                /** @var ColumnDefinition $col */
                $col = $op->payload;
                return $connector->buildAlterAddColumnSql($table, $this->declarativeColToArray($col));
            case SchemaDiffOp::KIND_DROP_COLUMN:
                /** @var ColumnDefinition $col */
                $col = $op->payload;
                return $connector->buildAlterDropColumnSql($table, $col->name);
            case SchemaDiffOp::KIND_MODIFY_COLUMN:
                /** @var ColumnDefinition $col */
                $col = $op->payload;
                $existingCol = $op->rollbackPayload instanceof ColumnDefinition ? $this->colToArray($op->rollbackPayload) : null;
                return $connector->buildAlterModifyColumnSql($table, $this->colToArray($col), $existingCol);
            case SchemaDiffOp::KIND_ADD_INDEX:
                /** @var IndexDefinition $idx */
                $idx = $op->payload;
                return $connector->buildAddIndexSql($table, $this->idxToArray($idx));
            case SchemaDiffOp::KIND_DROP_INDEX:
                /** @var IndexDefinition $idx */
                $idx = $op->payload;
                return $connector->buildDropIndexSql($table, $idx->name);
            case SchemaDiffOp::KIND_ADD_FOREIGN_KEY:
                /** @var ForeignKeyDefinition $fk */
                $fk = $op->payload;
                return $connector->buildAddForeignKeySql($table, $this->fkToArray($fk));
            case SchemaDiffOp::KIND_DROP_FOREIGN_KEY:
                /** @var ForeignKeyDefinition $fk */
                $fk = $op->payload;
                return $connector->buildDropForeignKeySql($table, $fk->name);
            case SchemaDiffOp::KIND_MODIFY_TABLE_COMMENT:
                $comment = is_string($op->payload) ? str_replace("'", "''", $op->payload) : '';
                return $connector->buildAlterTableCommentSql($table, $comment);
            default:
                return '';
        }
    }

    private function buildRollbackDdl(ConnectorInterface $connector, SchemaDiffOp $op): string
    {
        // 使用 formatTableName 将逻辑表名转换为物理表名（添加前缀和 schema）
        $table = $connector->formatTableName($op->tableName);
        switch ($op->kind) {
            case SchemaDiffOp::KIND_CREATE_TABLE:
                return "DROP TABLE IF EXISTS {$table}";
            case SchemaDiffOp::KIND_ADD_COLUMN:
                /** @var ColumnDefinition $col */
                $col = $op->payload;
                if ($connector instanceof SqliteConnector) {
                    return $connector->buildAlterDropProjectedColumnSql($table, $col->name);
                }
                return $connector->buildAlterDropColumnSql($table, $col->name);
            case SchemaDiffOp::KIND_DROP_COLUMN:
                /** @var ColumnDefinition $col */
                $col = $op->payload;
                if ($connector instanceof SqliteConnector) {
                    return $connector->buildAlterAddProjectedColumnSql($table, $this->colToArray($col));
                }
                return $connector->buildAlterAddColumnSql($table, $this->colToArray($col));
            case SchemaDiffOp::KIND_MODIFY_COLUMN:
                $oldCol = $op->rollbackPayload instanceof ColumnDefinition ? $op->rollbackPayload : null;
                if ($oldCol === null) {
                    return '';
                }
                return $connector->buildAlterModifyColumnSql($table, $this->colToArray($oldCol), $this->colToArray($op->payload));
            case SchemaDiffOp::KIND_ADD_INDEX:
                /** @var IndexDefinition $idx */
                $idx = $op->payload;
                return $connector->buildDropIndexSql($table, $idx->name);
            case SchemaDiffOp::KIND_DROP_INDEX:
                /** @var IndexDefinition $idx */
                $idx = $op->payload;
                if ($connector instanceof PgsqlConnector) {
                    return $connector->buildRestorePhysicalIndexSql(
                        $table,
                        $this->idxToArray($idx),
                    );
                }
                return $connector->buildAddIndexSql($table, $this->idxToArray($idx));
            case SchemaDiffOp::KIND_ADD_FOREIGN_KEY:
                /** @var ForeignKeyDefinition $fk */
                $fk = $op->payload;
                return $connector->buildDropForeignKeySql($table, $fk->name);
            case SchemaDiffOp::KIND_DROP_FOREIGN_KEY:
                /** @var ForeignKeyDefinition $fk */
                $fk = $op->payload;
                return $connector->buildAddForeignKeySql($table, $this->fkToArray($fk));
            case SchemaDiffOp::KIND_MODIFY_TABLE_COMMENT:
                $oldComment = is_string($op->rollbackPayload) ? str_replace("'", "''", $op->rollbackPayload) : '';
                return $connector->buildAlterTableCommentSql($table, $oldComment);
            default:
                return '';
        }
    }

    /**
     * 从 TableSchema 创建表，供 bootstrap 阶段调用。方言由 connector->createTable() 适配器处理。
     */
    public function createBootstrapTable(ConnectorInterface $connector, TableSchema $schema): void
    {
        $this->createTableViaAdapter($connector, $schema->tableName, $schema);
    }

    /**
     * Index/契约编排留在 Executor；PRIMARY KEY / AUTO_INCREMENT 等方言规则委托各适配器 createTableFromSchema。
     */
    private function createTableViaAdapter(
        ConnectorInterface $connector,
        string $tableName,
        TableSchema $payload,
        bool $rejectExisting = false,
    ): void {
        if ($rejectExisting && $connector->tableExist($tableName)) {
            throw new \RuntimeException(__(
                '表 %{1} 在 Schema prepare 后已出现，拒绝用过期 CREATE 计划接管既有表',
                [$tableName],
            ));
        }

        $indexIdentity = static fn(string $indexName): string => IndexDefinitionContract::physicalIdentity(
            $connector,
            $tableName,
            $indexName,
        );
        IndexDefinitionContract::assertAdapterLimits($connector, $payload->indexes);
        IndexDefinitionContract::assertDeclaredNames($payload->indexes, $indexIdentity);
        $targetIndexes = $payload->indexes;
        $explicitSingleUniqueColumns = IndexDefinitionContract::explicitSingleUniqueColumnMap($payload->indexes);
        $reservedImplicitIndexNames = IndexDefinitionContract::reservedIdentities(
            $indexIdentity,
            $payload->indexes,
        );
        foreach ($payload->columns as $col) {
            if (!$col->unique || $col->primaryKey || isset($explicitSingleUniqueColumns[$col->name])) {
                continue;
            }
            $implicitName = IndexDefinitionContract::resolveImplicitName(
                $tableName,
                $col->name,
                $reservedImplicitIndexNames,
                $indexIdentity,
            );
            $reservedImplicitIndexNames[strtolower($implicitName)] = true;
            $reservedImplicitIndexNames[$indexIdentity($implicitName)] = true;
            $targetIndexes[] = new IndexDefinition(
                name: $implicitName,
                columns: [$col->name],
                type: 'UNIQUE',
                comment: $col->comment,
            );
        }

        $connector->createTableFromSchema($tableName, [
            'comment' => $payload->comment,
            'columns' => array_map(fn (ColumnDefinition $col): array => $this->declarativeColToArray($col), $payload->columns),
            'indexes' => array_map(fn (IndexDefinition $idx): array => $this->idxToArray($idx), $targetIndexes),
            'foreignKeys' => array_map(fn (ForeignKeyDefinition $fk): array => $this->fkToArray($fk), $payload->foreignKeys),
        ]);
        $this->ensureCreateIndexes($connector, $tableName, $targetIndexes);
    }

    /** @param list<IndexDefinition> $targetIndexes */
    private function ensureCreateIndexes(
        ConnectorInterface $connector,
        string $tableName,
        array $targetIndexes,
    ): void {
        $databaseType = strtolower($connector->getConfigProvider()->getDbType());
        foreach ($targetIndexes as $expected) {
            $actual = $this->findPhysicalIndex($connector, $tableName, $expected);
            if ($actual !== null) {
                if (!IndexDefinitionContract::equals($expected, $actual, $databaseType)) {
                    throw new \RuntimeException(__(
                        '表 %{1} 的索引 %{2} 创建后物理定义不一致',
                        [$tableName, $expected->name],
                    ));
                }
                continue;
            }

            $physicalTable = $connector->formatTableName($tableName);
            $connector->query($connector->buildAddIndexSql($physicalTable, $this->idxToArray($expected)))->fetch();
            $actual = $this->findPhysicalIndex($connector, $tableName, $expected);
            if ($actual === null || !IndexDefinitionContract::equals($expected, $actual, $databaseType)) {
                throw new \RuntimeException(__(
                    '表 %{1} 的索引 %{2} 补建后未通过物理回读',
                    [$tableName, $expected->name],
                ));
            }
        }
    }

    private function findPhysicalIndex(
        ConnectorInterface $connector,
        string $tableName,
        IndexDefinition $expected,
    ): ?IndexDefinition {
        $expectedIdentity = IndexDefinitionContract::physicalIdentity($connector, $tableName, $expected->name);
        foreach ($connector->getTableIndexes($tableName) as $row) {
            $actualName = trim((string)($row['name'] ?? ''));
            if ($actualName === '') {
                continue;
            }
            $rawIdentity = strtolower($actualName);
            $mappedIdentity = IndexDefinitionContract::physicalIdentity($connector, $tableName, $actualName);
            if ($expectedIdentity !== $rawIdentity && $expectedIdentity !== $mappedIdentity) {
                continue;
            }
            return new IndexDefinition(
                name: $actualName,
                columns: array_values(array_map('strval', (array)($row['columns'] ?? []))),
                type: (string)($row['type'] ?? (!empty($row['unique']) ? 'UNIQUE' : 'DEFAULT')),
                method: (string)($row['method'] ?? 'BTREE'),
            );
        }
        return null;
    }

    /** @return array{name:string,type:string,length?:int|string|null,nullable:bool,primaryKey:bool,autoIncrement:bool,default?:mixed,comment:string,unique:bool} */
    private function colToArray(ColumnDefinition $col): array
    {
        return [
            'name' => $col->name,
            'type' => $col->type,
            'length' => $col->length,
            'nullable' => $col->nullable,
            'primaryKey' => $col->primaryKey,
            'autoIncrement' => $col->autoIncrement,
            'default' => $col->default,
            'comment' => $col->comment,
            'unique' => $col->unique,
        ];
    }

    /**
     * Declarative UNIQUE ownership lives in explicit or stable implicit indexes.
     * Keeping it on the column as well would create a second constraint-owned
     * index on SQLite and make a cold install require another SchemaDiff pass.
     *
     * @return array{name:string,type:string,length?:int|string|null,nullable:bool,primaryKey:bool,autoIncrement:bool,default?:mixed,comment:string,unique:bool}
     */
    private function declarativeColToArray(ColumnDefinition $col): array
    {
        $definition = $this->colToArray($col);
        if (!$col->primaryKey) {
            $definition['unique'] = false;
        }

        return $definition;
    }

    /** @return array{name:string,columns:list<string>,type:string,method:string,comment:string} */
    private function idxToArray(IndexDefinition $idx): array
    {
        return [
            'name' => $idx->name,
            'columns' => $idx->columns,
            'type' => $idx->type,
            'method' => $idx->method,
            'comment' => $idx->comment,
        ];
    }

    /** @return array{name:string,columns:list<string>,referencesTable:string,referencesColumns:list<string>,onDeleteCascade:bool,onUpdateCascade:bool} */
    private function fkToArray(ForeignKeyDefinition $fk): array
    {
        return [
            'name' => $fk->name,
            'columns' => $fk->columns,
            'referencesTable' => $fk->referencesTable,
            'referencesColumns' => $fk->referencesColumns,
            'onDeleteCascade' => $fk->onDeleteCascade,
            'onUpdateCascade' => $fk->onUpdateCascade,
        ];
    }

}
