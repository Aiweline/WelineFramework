<?php

declare(strict_types=1);

namespace Weline\Database\Service;

use Weline\Database\Model\Migration;
use Weline\Framework\Database\Connection\Api\AtomicPhysicalTableChangeInterface;
use Weline\Framework\Database\Connection\Api\ConnectorInterface;
use Weline\Framework\Database\Connection\Api\PhysicalTableIdentity;
use Weline\Framework\Database\Connection\Api\PhysicalTableIdentityProviderInterface;
use Weline\Framework\Database\Connection\Api\PhysicalTableMetadataInterface;
use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Database\Schema\SchemaDiffOp;
use Weline\Framework\Setup\Model\MigrationBackup;

/**
 * Durable reverse executor for SchemaDiff records.
 *
 * Destructive rollback DDL is admitted only when the adapter can keep its
 * exact-table backup and DDL inside one bounded physical transaction.
 */
final class SchemaRollbackService
{
    public function __construct(
        private readonly ConnectionFactory $connectionFactory,
        private readonly Migration $migrationModel,
        private readonly BackupService $backupService,
    ) {
    }

    /**
     * @return array{
     *     operations: list<array<string, mixed>>,
     *     checkpoints: array{current: ?array<string, mixed>, target: ?array<string, mixed>},
     *     blockers: list<string>,
     *     warnings: list<string>
     * }
     */
    public function createPlan(string $moduleName, string $targetVersion, string $currentVersion): array
    {
        $operations = [];
        $blockers = [];
        $warnings = [];
        $records = $this->migrationModel->reset()
            ->where(Migration::schema_fields_MODULE, $moduleName)
            ->where(Migration::schema_fields_FILE, 'schema_diff')
            ->where(Migration::schema_fields_STATUS, Migration::STATUS_INSTALLED)
            ->select()
            ->fetch()
            ->getItems();

        foreach ($records as $record) {
            $version = trim((string)$record->getData(Migration::schema_fields_VERSION));
            if (!$this->isSemanticVersion($version)) {
                $blockers[] = __(
                    '模块 %{1} 存在无法验证版本归属的历史 Schema 记录 #%{2} (legacy_unverified)',
                    [$moduleName, (string)$record->getId()]
                );
                continue;
            }
            if (!version_compare($version, $targetVersion, '>')
                || !version_compare($version, $currentVersion, '<=')) {
                continue;
            }

            $forwardDdl = (string)$record->getData(Migration::schema_fields_FORWARD_DDL);
            $rollbackDdl = (string)$record->getData(Migration::schema_fields_ROLLBACK_DDL);
            $expectedChecksum = trim((string)$record->getData(Migration::schema_fields_CHECKSUM));
            $actualChecksum = hash('sha256', $forwardDdl . "\0" . $rollbackDdl);
            if ($expectedChecksum === '' || !hash_equals($expectedChecksum, $actualChecksum)) {
                $blockers[] = __('模块 %{1} Schema 记录 #%{2} 校验和不一致', [$moduleName, (string)$record->getId()]);
                continue;
            }
            if (trim($rollbackDdl) === '') {
                $blockers[] = __('模块 %{1} Schema 记录 #%{2} 缺少反向 DDL', [$moduleName, (string)$record->getId()]);
                continue;
            }

            $payload = json_decode((string)$record->getData(Migration::schema_fields_OPERATION_PAYLOAD), true);
            $operations[] = [
                'migration_id' => (int)$record->getId(),
                'module_name' => $moduleName,
                'version' => $version,
                'batch_id' => (string)$record->getData(Migration::schema_fields_BATCH_ID),
                'sequence_no' => (int)$record->getData(Migration::schema_fields_SEQUENCE),
                'operation_kind' => (string)$record->getData(Migration::schema_fields_OPERATION_KIND),
                'model_class' => (string)$record->getData(Migration::schema_fields_MODEL_CLASS),
                'table_name' => (string)$record->getData(Migration::schema_fields_SCHEMA_TABLE_NAME),
                'forward_ddl' => $forwardDdl,
                'rollback_ddl' => $rollbackDdl,
                'schema_before_checksum' => (string)$record->getData(Migration::schema_fields_SCHEMA_BEFORE_CHECKSUM),
                'schema_after_checksum' => (string)$record->getData(Migration::schema_fields_SCHEMA_AFTER_CHECKSUM),
                'checksum' => $expectedChecksum,
                'payload' => is_array($payload) ? $payload : [],
            ];
        }

        usort($operations, static function (array $left, array $right): int {
            $version = version_compare((string)$right['version'], (string)$left['version']);
            if ($version !== 0) {
                return $version;
            }
            return (int)$right['migration_id'] <=> (int)$left['migration_id'];
        });

        $currentCheckpoint = null;
        $targetCheckpoint = null;
        try {
            $currentCheckpoint = $this->migrationModel->getSchemaCheckpoint($moduleName, $currentVersion);
            $targetCheckpoint = $this->migrationModel->getSchemaCheckpoint($moduleName, $targetVersion);
        } catch (\Throwable $e) {
            $blockers[] = __('模块 %{1} 的 Schema checkpoint 无法验证：%{2}', [$moduleName, $e->getMessage()]);
        }
        if ($currentCheckpoint === null) {
            $blockers[] = __('模块 %{1} 当前版本 %{2} 缺少 Schema checkpoint', [$moduleName, $currentVersion]);
        }
        if ($targetCheckpoint === null) {
            $blockers[] = __('模块 %{1} 目标版本 %{2} 缺少 Schema checkpoint', [$moduleName, $targetVersion]);
        }
        if ($currentCheckpoint !== null && $targetCheckpoint !== null) {
            $blockers = array_merge(
                $blockers,
                $this->verifyCheckpointChain($moduleName, $operations, $currentCheckpoint, $targetCheckpoint),
            );
            if ($operations === [] && $blockers === []) {
                $warnings[] = __('模块 %{1} 两个版本的 Schema checkpoint 一致，无需执行 DDL', $moduleName);
            }
        }

        return [
            'operations' => $operations,
            'checkpoints' => [
                'current' => $this->checkpointSummary($currentCheckpoint),
                'target' => $this->checkpointSummary($targetCheckpoint),
            ],
            'blockers' => array_values(array_unique($blockers)),
            'warnings' => array_values(array_unique($warnings)),
        ];
    }

    /** @param list<array<string, mixed>> $operations */
    public function executeRollbackPlan(array $operations, string $operationId): array
    {
        $completed = [];
        $connector = $this->connectionFactory->getConnector();
        foreach ($operations as $operation) {
            $migrationId = (int)($operation['migration_id'] ?? 0);
            $record = clone $this->migrationModel;
            $record->load($migrationId);
            if (!$record->getId()
                || $record->getData(Migration::schema_fields_STATUS) !== Migration::STATUS_INSTALLED) {
                throw new \RuntimeException(__('待回滚 Schema 记录状态已变化: #%{1}', (string)$migrationId));
            }
            $this->assertRecordChecksum($record);
            $this->migrationModel->bindOperationIdFailClosed($migrationId, $operationId);
            if (!$connector instanceof PhysicalTableMetadataInterface
                || !$connector instanceof PhysicalTableIdentityProviderInterface) {
                throw new \RuntimeException('exact physical table capability unavailable');
            }
            if (!$connector instanceof AtomicPhysicalTableChangeInterface) {
                throw new \RuntimeException('atomic physical table change capability unavailable');
            }

            $kind = (string)($operation['operation_kind'] ?? '');
            $table = (string)($operation['table_name'] ?? '');
            $column = trim((string)($operation['payload']['name'] ?? ''));
            $modelClass = trim((string)($operation['model_class'] ?? '')) ?: null;
            $identity = $connector->resolvePhysicalTableIdentity($table);
            $connector->atomicPhysicalTableChange(
                $identity,
                function (ConnectorInterface $lockedConnector) use (
                    $connector,
                    $identity,
                    $migrationId,
                    $operationId,
                    $kind,
                    $column,
                    $modelClass,
                    $operation,
                ): void {
                    if ($lockedConnector !== $connector
                        || !$lockedConnector instanceof PhysicalTableMetadataInterface) {
                        throw new \RuntimeException('atomic physical table connector changed during rollback');
                    }
                    $this->loadPlannedSchemaRecordForUpdate(
                        $migrationId,
                        Migration::STATUS_INSTALLED,
                        $operationId,
                        $operation,
                    );
                    if ($kind === SchemaDiffOp::KIND_CREATE_TABLE) {
                        $this->backupService->smartBackupPhysicalTable(
                            $identity,
                            $migrationId,
                            MigrationBackup::SCOPE_ROLLBACK,
                            $operationId,
                            $lockedConnector,
                        );
                    } elseif (in_array(
                        $kind,
                        [SchemaDiffOp::KIND_ADD_COLUMN, SchemaDiffOp::KIND_MODIFY_COLUMN],
                        true,
                    )) {
                        if ($column === '') {
                            throw new \RuntimeException(
                                __('Schema 记录 #%{1} 缺少列名', (string)$migrationId),
                            );
                        }
                        $this->backupService->backupPhysicalColumnData(
                            $identity,
                            $column,
                            $migrationId,
                            $modelClass,
                            'ROLLBACK',
                            MigrationBackup::SCOPE_ROLLBACK,
                            $operationId,
                            $lockedConnector,
                        );
                    }

                    $this->executeDdlUsing($lockedConnector, (string)$operation['rollback_ddl']);
                    if ($kind === SchemaDiffOp::KIND_DROP_COLUMN && $column !== '') {
                        $this->backupService->restorePhysicalColumnDataConflictSafe(
                            $identity,
                            $column,
                            $migrationId,
                            $operation['payload']['default'] ?? null,
                            MigrationBackup::SCOPE_UPGRADE,
                        );
                    }
                    $this->migrationModel->compareAndSwapStatusFailClosed(
                        $migrationId,
                        Migration::STATUS_INSTALLED,
                        Migration::STATUS_ROLLED_BACK,
                        $operationId,
                        $this->connectionFactory,
                    );
                },
            );
            $completed[] = $operation;
        }

        return $completed;
    }

    /** @param list<array<string, mixed>> $operations */
    public function compensate(array $operations, string $operationId): void
    {
        $connector = $this->connectionFactory->getConnector();
        if (!$connector instanceof PhysicalTableMetadataInterface
            || !$connector instanceof PhysicalTableIdentityProviderInterface) {
            throw new \RuntimeException('exact physical table capability unavailable');
        }
        if (!$connector instanceof AtomicPhysicalTableChangeInterface) {
            throw new \RuntimeException('atomic physical table change capability unavailable');
        }

        foreach (array_reverse($operations) as $operation) {
            $migrationId = (int)($operation['migration_id'] ?? 0);
            if ($migrationId <= 0) {
                throw new \RuntimeException(__('Schema 补偿记录不存在: #%{1}', (string)$migrationId));
            }
            $kind = (string)($operation['operation_kind'] ?? '');
            $table = (string)($operation['table_name'] ?? '');
            $column = trim((string)($operation['payload']['name'] ?? ''));

            $identity = $connector->resolvePhysicalTableIdentity($table);
            $connector->atomicPhysicalTableChange(
                $identity,
                function (ConnectorInterface $lockedConnector) use (
                    $connector,
                    $identity,
                    $migrationId,
                    $operationId,
                    $kind,
                    $column,
                    $operation,
                ): void {
                    if ($lockedConnector !== $connector
                        || !$lockedConnector instanceof PhysicalTableMetadataInterface) {
                        throw new \RuntimeException('atomic physical table connector changed during compensation');
                    }
                    $record = $this->loadSchemaRecordForUpdate($migrationId, $operationId, $operation);
                    $status = (string)$record->getData(Migration::schema_fields_STATUS);
                    if ($status === Migration::STATUS_INSTALLED) {
                        return;
                    }
                    if ($status !== Migration::STATUS_ROLLED_BACK) {
                        throw new \RuntimeException(__(
                            'Schema 补偿记录状态或 operation_id 已变化: #%{1}',
                            (string)$migrationId,
                        ));
                    }

                    if ($kind === SchemaDiffOp::KIND_CREATE_TABLE) {
                        $this->restorePhysicalRollbackTable($identity, $migrationId, $operationId);
                    } else {
                        $this->executeDdlUsing($lockedConnector, (string)$operation['forward_ddl']);
                        if (in_array(
                            $kind,
                            [SchemaDiffOp::KIND_ADD_COLUMN, SchemaDiffOp::KIND_MODIFY_COLUMN],
                            true,
                        ) && $column !== '') {
                            $this->backupService->restorePhysicalColumnDataConflictSafe(
                                $identity,
                                $column,
                                $migrationId,
                                $operation['payload']['default'] ?? null,
                                MigrationBackup::SCOPE_ROLLBACK,
                                $operationId,
                            );
                        }
                    }
                    $this->migrationModel->compareAndSwapStatusFailClosed(
                        $migrationId,
                        Migration::STATUS_ROLLED_BACK,
                        Migration::STATUS_INSTALLED,
                        $operationId,
                        $this->connectionFactory,
                    );
                },
            );
        }
    }

    private function restorePhysicalRollbackTable(
        PhysicalTableIdentity $identity,
        int $migrationId,
        string $operationId,
    ): void {
        $matching = array_values(array_filter(
            $this->backupService->getBackupsByMigrationId($migrationId),
            static fn(MigrationBackup $backup): bool =>
                (string)$backup->getData(MigrationBackup::schema_fields_TABLE_NAME) === $identity->canonical()
                && (string)$backup->getData(MigrationBackup::schema_fields_BACKUP_SCOPE)
                    === MigrationBackup::SCOPE_ROLLBACK
                && (string)$backup->getData(MigrationBackup::schema_fields_OPERATION_ID) === $operationId,
        ));
        $structures = [];
        $tables = [];
        $chunks = [];
        foreach ($matching as $backup) {
            $type = (string)$backup->getData(MigrationBackup::schema_fields_BACKUP_TYPE);
            if ($type === MigrationBackup::TYPE_STRUCTURE) {
                $structures[] = $backup;
            } elseif ($type === MigrationBackup::TYPE_TABLE) {
                $tables[] = $backup;
            } elseif ($type === MigrationBackup::TYPE_CHUNK) {
                $chunks[] = $backup;
            }
        }
        if (count($structures) !== 1 || count($tables) > 1 || ($tables !== [] && $chunks !== [])) {
            throw new \RuntimeException('physical rollback backup group is incomplete or ambiguous');
        }
        if (!$this->backupService->restorePhysicalTableStructure(
            $identity,
            $migrationId,
            MigrationBackup::SCOPE_ROLLBACK,
            $operationId,
        )) {
            throw new \RuntimeException(__('Table %{1} structure compensation failed', $identity->canonical()));
        }
        if ($tables !== [] && !$this->backupService->restorePhysicalTableData(
            $identity,
            $migrationId,
            MigrationBackup::SCOPE_ROLLBACK,
            $operationId,
            (int)$tables[0]->getId(),
        )) {
            throw new \RuntimeException(__('Table %{1} data compensation failed', $identity->canonical()));
        }
        if ($chunks !== [] && !$this->backupService->restorePhysicalTableDataChunked(
            $identity,
            $migrationId,
            MigrationBackup::SCOPE_ROLLBACK,
            $operationId,
        )) {
            throw new \RuntimeException(__('Table %{1} chunk compensation failed', $identity->canonical()));
        }
    }

    /**
     * Prove that complete version/batch/table transitions transform the
     * current semantic checkpoint into the requested target checkpoint.
     *
     * @param list<array<string, mixed>> $operations
     * @param array{tables: array<string, string>} $currentCheckpoint
     * @param array{tables: array<string, string>} $targetCheckpoint
     * @return list<string>
     */
    private function verifyCheckpointChain(
        string $moduleName,
        array $operations,
        array $currentCheckpoint,
        array $targetCheckpoint,
    ): array {
        $blockers = [];
        $groups = [];
        foreach ($operations as $operation) {
            $migrationId = (int)($operation['migration_id'] ?? 0);
            $version = trim((string)($operation['version'] ?? ''));
            $batchId = trim((string)($operation['batch_id'] ?? ''));
            $tableName = trim((string)($operation['table_name'] ?? ''));
            $before = strtolower(trim((string)($operation['schema_before_checksum'] ?? '')));
            $after = strtolower(trim((string)($operation['schema_after_checksum'] ?? '')));
            if ($migrationId <= 0 || $batchId === '' || $tableName === '') {
                $blockers[] = __('模块 %{1} Schema 记录 #%{2} 缺少版本批次或表信息', [$moduleName, (string)$migrationId]);
                continue;
            }
            if (!$this->isFingerprint($before) || !$this->isFingerprint($after)) {
                $blockers[] = __('模块 %{1} Schema 记录 #%{2} 缺少有效的前后指纹', [$moduleName, (string)$migrationId]);
                continue;
            }

            $groupKey = $version . "\0" . $batchId . "\0" . $tableName;
            if (!isset($groups[$groupKey])) {
                $groups[$groupKey] = [
                    'migration_id' => $migrationId,
                    'version' => $version,
                    'batch_id' => $batchId,
                    'table_name' => $tableName,
                    'before' => $before,
                    'after' => $after,
                ];
                continue;
            }
            if (!hash_equals((string)$groups[$groupKey]['before'], $before)
                || !hash_equals((string)$groups[$groupKey]['after'], $after)) {
                $blockers[] = __('模块 %{1} 批次 %{2} 的表 %{3} 指纹不一致', [$moduleName, $batchId, $tableName]);
            }
        }
        if ($blockers !== []) {
            return array_values(array_unique($blockers));
        }

        $absentFingerprint = hash('sha256', 'absent');
        $simulated = $this->normalizeCheckpointTables((array)$currentCheckpoint['tables'], $absentFingerprint);
        foreach ($groups as $group) {
            $tableName = (string)$group['table_name'];
            $actual = (string)($simulated[$tableName] ?? $absentFingerprint);
            $expected = (string)$group['after'];
            if (!hash_equals($expected, $actual)) {
                $blockers[] = __(
                    '模块 %{1} Schema 反向链在 %{2}/%{3}/%{4} 断裂',
                    [$moduleName, (string)$group['version'], (string)$group['batch_id'], $tableName]
                );
                break;
            }
            $simulated[$tableName] = (string)$group['before'];
            $simulated = $this->normalizeCheckpointTables($simulated, $absentFingerprint);
        }

        $targetTables = $this->normalizeCheckpointTables(
            (array)$targetCheckpoint['tables'],
            $absentFingerprint,
        );
        if ($blockers === [] && $simulated !== $targetTables) {
            $changedTables = [];
            foreach (array_unique(array_merge(array_keys($simulated), array_keys($targetTables))) as $tableName) {
                if (($simulated[$tableName] ?? null) !== ($targetTables[$tableName] ?? null)) {
                    $changedTables[] = $tableName;
                }
            }
            $blockers[] = __(
                '模块 %{1} 的 Schema 反向链无法重建目标 checkpoint（差异表：%{2}）',
                [$moduleName, implode(', ', array_slice($changedTables, 0, 8))]
            );
        }

        return array_values(array_unique($blockers));
    }

    /** @param array<string, string> $tables @return array<string, string> */
    private function normalizeCheckpointTables(array $tables, string $absentFingerprint): array
    {
        $normalized = [];
        foreach ($tables as $tableName => $fingerprint) {
            $tableName = trim((string)$tableName);
            $fingerprint = strtolower(trim((string)$fingerprint));
            if ($tableName !== '' && $this->isFingerprint($fingerprint) && !hash_equals($absentFingerprint, $fingerprint)) {
                $normalized[$tableName] = $fingerprint;
            }
        }
        ksort($normalized);
        return $normalized;
    }

    /** @return array<string, mixed>|null */
    private function checkpointSummary(?array $checkpoint): ?array
    {
        if ($checkpoint === null) {
            return null;
        }
        return [
            'migration_id' => (int)($checkpoint['migration_id'] ?? 0),
            'version' => (string)($checkpoint['version'] ?? ''),
            'checksum' => (string)($checkpoint['checksum'] ?? ''),
            'table_count' => count((array)($checkpoint['tables'] ?? [])),
        ];
    }

    private function isFingerprint(string $fingerprint): bool
    {
        return preg_match('/^[a-f0-9]{64}$/', $fingerprint) === 1;
    }

    private function assertRecordChecksum(Migration $record): void
    {
        $expected = trim((string)$record->getData(Migration::schema_fields_CHECKSUM));
        $actual = hash(
            'sha256',
            (string)$record->getData(Migration::schema_fields_FORWARD_DDL)
                . "\0"
                . (string)$record->getData(Migration::schema_fields_ROLLBACK_DDL)
        );
        if ($expected === '' || !hash_equals($expected, $actual)) {
            throw new \RuntimeException(__('Schema 记录 #%{1} 校验和不一致', (string)$record->getId()));
        }
    }

    /** @param array<string, mixed> $operation */
    private function loadPlannedSchemaRecordForUpdate(
        int $migrationId,
        string $expectedStatus,
        string $operationId,
        array $operation,
    ): Migration {
        $record = $this->loadSchemaRecordForUpdate($migrationId, $operationId, $operation);
        if ($record->getData(Migration::schema_fields_STATUS) !== $expectedStatus) {
            throw new \RuntimeException(__('Schema 计划记录状态或 operation_id 已变化: #%{1}', (string)$migrationId));
        }
        return $record;
    }

    /** @param array<string, mixed> $operation */
    private function loadSchemaRecordForUpdate(
        int $migrationId,
        string $operationId,
        array $operation,
    ): Migration {
        $record = (clone $this->migrationModel)->reset()->setConnection($this->connectionFactory)
            ->where(Migration::schema_fields_ID, $migrationId);
        $record->additional('FOR UPDATE');
        $record->find()->fetch();
        if ((int)$record->getId() !== $migrationId
            || (string)$record->getData(Migration::schema_fields_OPERATION_ID) !== $operationId) {
            throw new \RuntimeException(__('Schema 计划记录状态或 operation_id 已变化: #%{1}', (string)$migrationId));
        }
        $plannedChecksum = trim((string)($operation['checksum'] ?? ''));
        if ($plannedChecksum !== ''
            && !hash_equals(
                $plannedChecksum,
                trim((string)$record->getData(Migration::schema_fields_CHECKSUM)),
            )) {
            throw new \RuntimeException(__('Schema 计划记录校验和已变化: #%{1}', (string)$migrationId));
        }
        $this->assertRecordChecksum($record);
        return $record;
    }

    private function executeDdlUsing(ConnectorInterface $connector, string $ddl): void
    {
        if (str_contains($ddl, '/* WELINE_SQLITE_REBUILD */')) {
            throw new \RuntimeException('atomic SQLite schema rollback is unsupported');
        }
        foreach ($this->splitDdlStatements($ddl) as $statement) {
            if (trim($statement) === '') {
                continue;
            }
            $result = $connector->query($statement)->fetch();
            if ($result === false) {
                throw new \RuntimeException('atomic schema rollback DDL failed');
            }
        }
    }

    /** @return list<string> */
    private function splitDdlStatements(string $sql): array
    {
        $normalized = str_replace("\r\n", "\n", $sql);
        if (str_contains($normalized, "\n-- WELINE_DDL_STATEMENT\n")) {
            return explode("\n-- WELINE_DDL_STATEMENT\n", $normalized);
        }
        return str_contains($normalized, ";\n") ? explode(";\n", $normalized) : [$normalized];
    }

    private function isSemanticVersion(string $version): bool
    {
        return preg_match('/^\d+\.\d+\.\d+(?:[-+][A-Za-z0-9.-]+)?$/', $version) === 1;
    }
}
