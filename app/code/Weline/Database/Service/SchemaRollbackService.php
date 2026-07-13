<?php

declare(strict_types=1);

namespace Weline\Database\Service;

use Weline\Database\Model\Migration;
use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Database\Schema\SchemaDiffOp;
use Weline\Framework\Setup\Model\MigrationBackup;

/**
 * Durable reverse executor for SchemaDiff records.
 *
 * DDL is deliberately not wrapped in a transaction: MySQL can commit it
 * implicitly. Every completed record is persisted and can be replayed by the
 * compensation path.
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
            $record->setData(Migration::schema_fields_OPERATION_ID, $operationId)->save();

            $kind = (string)($operation['operation_kind'] ?? '');
            $table = (string)($operation['table_name'] ?? '');
            $column = trim((string)($operation['payload']['name'] ?? ''));
            $modelClass = trim((string)($operation['model_class'] ?? '')) ?: null;

            if ($kind === SchemaDiffOp::KIND_CREATE_TABLE) {
                if (!$this->backupService->backupTableStructure(
                    $table,
                    $migrationId,
                    MigrationBackup::SCOPE_ROLLBACK,
                    $operationId,
                )) {
                    throw new \RuntimeException(__('无法备份表 %{1} 的结构', $table));
                }
                $this->backupService->backupTableData(
                    $table,
                    $migrationId,
                    MigrationBackup::SCOPE_ROLLBACK,
                    $operationId,
                );
            } elseif (in_array($kind, [SchemaDiffOp::KIND_ADD_COLUMN, SchemaDiffOp::KIND_MODIFY_COLUMN], true)) {
                if ($column === '') {
                    throw new \RuntimeException(__('Schema 记录 #%{1} 缺少列名', (string)$migrationId));
                }
                $this->backupService->backupColumnData(
                    $table,
                    $column,
                    $migrationId,
                    $connector,
                    $modelClass,
                    'ROLLBACK',
                    MigrationBackup::SCOPE_ROLLBACK,
                    $operationId,
                );
            }

            $this->executeDdl((string)$operation['rollback_ddl']);
            if ($kind === SchemaDiffOp::KIND_DROP_COLUMN && $column !== '') {
                $this->backupService->restoreColumnDataConflictSafe(
                    $table,
                    $column,
                    $migrationId,
                    $connector,
                    $modelClass,
                    $operation['payload']['default'] ?? null,
                    MigrationBackup::SCOPE_UPGRADE,
                );
            }
            $record->updateStatus(Migration::STATUS_ROLLED_BACK);
            $completed[] = $operation;
        }

        return $completed;
    }

    /** @param list<array<string, mixed>> $operations */
    public function compensate(array $operations, string $operationId): void
    {
        $connector = $this->connectionFactory->getConnector();
        foreach (array_reverse($operations) as $operation) {
            $migrationId = (int)($operation['migration_id'] ?? 0);
            $record = clone $this->migrationModel;
            $record->load($migrationId);
            if (!$record->getId()) {
                throw new \RuntimeException(__('Schema 补偿记录不存在: #%{1}', (string)$migrationId));
            }
            $kind = (string)($operation['operation_kind'] ?? '');
            $table = (string)($operation['table_name'] ?? '');
            $column = trim((string)($operation['payload']['name'] ?? ''));
            $modelClass = trim((string)($operation['model_class'] ?? '')) ?: null;

            if ($kind === SchemaDiffOp::KIND_CREATE_TABLE) {
                if (!$this->backupService->restoreTableStructure(
                    $table,
                    $migrationId,
                    false,
                    MigrationBackup::SCOPE_ROLLBACK,
                    $operationId,
                ) || !$this->backupService->restoreTableData(
                    $table,
                    $migrationId,
                    false,
                    MigrationBackup::SCOPE_ROLLBACK,
                    $operationId,
                )) {
                    throw new \RuntimeException(__('表 %{1} 结构或数据补偿失败', $table));
                }
            } else {
                $this->executeDdl((string)$operation['forward_ddl']);
                if (in_array($kind, [SchemaDiffOp::KIND_ADD_COLUMN, SchemaDiffOp::KIND_MODIFY_COLUMN], true) && $column !== '') {
                    $this->backupService->restoreColumnDataConflictSafe(
                        $table,
                        $column,
                        $migrationId,
                        $connector,
                        $modelClass,
                        $operation['payload']['default'] ?? null,
                        MigrationBackup::SCOPE_ROLLBACK,
                        $operationId,
                    );
                }
            }
            $record->setData(Migration::schema_fields_OPERATION_ID, $operationId);
            $record->updateStatus(Migration::STATUS_INSTALLED);
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

    private function executeDdl(string $ddl): void
    {
        $connector = $this->connectionFactory->getConnector();
        $sqliteRebuild = str_contains($ddl, '/* WELINE_SQLITE_REBUILD */');
        $ddl = str_replace('/* WELINE_SQLITE_REBUILD */', '', $ddl);
        try {
            if ($sqliteRebuild) {
                $connector->query('PRAGMA foreign_keys=OFF')->fetch();
                $connector->beginTransaction();
            }
            foreach ($this->splitDdlStatements($ddl) as $statement) {
                if (trim($statement) !== '') {
                    $connector->query($statement)->fetch();
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
