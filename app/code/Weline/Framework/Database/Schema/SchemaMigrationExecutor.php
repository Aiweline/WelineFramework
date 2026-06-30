<?php

declare(strict_types=1);

namespace Weline\Framework\Database\Schema;

use Weline\Framework\Database\Connection\Api\ConnectorInterface;
use Weline\Framework\Database\Connection\Api\Sql\Table\CreateInterface;
use Weline\Framework\Setup\Model\Migration;
use Weline\Framework\Database\Service\BackupService;
use Weline\Framework\DataObject\DataObject;
use Weline\Framework\Event\EventsManager;

/**
 * 执行 SchemaDiffOp 列表：生成 DDL/rollback、记录 Migration、DROP 前备份、派发 table_ddl_before/after。
 */
final class SchemaMigrationExecutor
{
    public const EVENT_TABLE_DDL_BEFORE = 'Weline_Framework_Schema::table_ddl_before';
    public const EVENT_TABLE_DDL_AFTER = 'Weline_Framework_Schema::table_ddl_after';

    private const CONNECTION_NAME_DEFAULT = 'default';

    public function __construct(
        private readonly EventsManager $eventsManager,
        private readonly Migration $migrationModel,
        private readonly ?BackupService $backupService = null,
    ) {
    }

    /** 执行顺序优先级：先 ADD（列、索引、外键），再 DROP，确保 ADD_INDEX 引用的列在 DROP_COLUMN 前仍存在 */
    private const KIND_PRIORITY = [
        SchemaDiffOp::KIND_CREATE_TABLE => 0,
        SchemaDiffOp::KIND_ADD_COLUMN => 1,
        SchemaDiffOp::KIND_MODIFY_COLUMN => 2,
        SchemaDiffOp::KIND_ADD_INDEX => 3,
        SchemaDiffOp::KIND_ADD_FOREIGN_KEY => 4,
        SchemaDiffOp::KIND_DROP_COLUMN => 5,
        SchemaDiffOp::KIND_DROP_INDEX => 6,
        SchemaDiffOp::KIND_DROP_FOREIGN_KEY => 7,
        SchemaDiffOp::KIND_MODIFY_TABLE_COMMENT => 8,
    ];

    /**
     * 执行一组差异操作；单条 DDL 失败即抛异常。每条 DDL 记录 forward_ddl/rollback_ddl，DROP COLUMN 前备份列数据。
     * 按表名+操作类型排序，确保 ADD_COLUMN 先于 ADD_INDEX/ADD_FOREIGN_KEY 执行。
     *
     * @param list<SchemaDiffOp> $ops
     */
    public function execute(ConnectorInterface $connector, array $ops): void
    {
        $connectionName = self::CONNECTION_NAME_DEFAULT;

        usort($ops, function (SchemaDiffOp $a, SchemaDiffOp $b): int {
            $cmp = strcmp($a->tableName, $b->tableName);
            if ($cmp !== 0) {
                return $cmp;
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

            $moduleName = $this->moduleNameFromClass($op->modelClass);
            try {
                $migrationId = $this->migrationModel->recordSchemaDdl(
                    $moduleName,
                    $op->tableName,
                    $connectionName,
                    $forwardSql,
                    $rollbackSql,
                    $op->modelClass,
                );
            } catch (\Throwable $e) {
                throw $e;
            }
            if ($migrationId > 0 && $op->kind === SchemaDiffOp::KIND_DROP_COLUMN && $this->backupService !== null) {
                /** @var ColumnDefinition $col */
                $col = $op->payload;
                $this->backupService->backupColumnData($op->tableName, $col->name, $migrationId, $connector, $op->modelClass, 'DROP');
            }

            $this->dispatchBefore($op);

            if ($op->kind === SchemaDiffOp::KIND_CREATE_TABLE && $op->payload instanceof TableSchema) {
                $this->createTableViaAdapter($connector, $op->tableName, $op->payload);
            } else {
                foreach ($this->splitDdlStatements($forwardSql) as $sql) {
                    if (trim($sql) !== '') {
                        try {
                            $connector->query($sql)->fetch();
                        } catch (\Throwable $e) {
                            if ($this->shouldHealDocumentCatalogNamePidDuplicate($op, $e)) {
                                $this->dedupeDocumentCatalogNamePid(
                                    $connector,
                                    $op->tableName,
                                    $this->shouldCoalesceDocumentCatalogPidDuringDedupe($op),
                                );
                                $connector->query($sql)->fetch();
                                continue;
                            }
                            if ($this->shouldHealDocumentModuleFileDuplicate($op, $e)) {
                                $this->dedupeDocumentModuleFile($connector, $op->tableName);
                                $connector->query($sql)->fetch();
                                continue;
                            }
                            if ($this->healPgsqlConstraintBackedIndexDrop($connector, $op, $e)) {
                                $connector->query($sql)->fetch();
                                continue;
                            }
                            $colName = ($op->payload instanceof \Weline\Framework\Database\Schema\ColumnDefinition)
                                ? $op->payload->name : '';
                            $ctx = "table={$op->tableName} kind={$op->kind}" . ($colName !== '' ? " col={$colName}" : '');
                            throw new \RuntimeException("Schema DDL failed ({$ctx}): " . $e->getMessage(), 0, $e);
                        }
                    }
                }
            }

            $this->dispatchAfter($op);
            $this->migrationModel->updateStatus(Migration::STATUS_INSTALLED);
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

    /** 按 ";\n" 拆分 DDL，供需多条语句的方言（如 Pgsql 自增列 SET DEFAULT）使用 */
    private function splitDdlStatements(string $sql): array
    {
        $normalized = str_replace("\r\n", "\n", $sql);
        if (!str_contains($normalized, ";\n")) {
            return [$normalized];
        }
        return explode(";\n", $normalized);
    }

    /**
     * PostgreSQL：唯一约束会生成“索引”，DROP INDEX 会报 2BP01；需先 DROP CONSTRAINT。
     * 若 DDL 中 ALTER 未命中（约束在其它表、或名不一致），根据错误信息补一刀后重试原语句。
     */
    private function healPgsqlConstraintBackedIndexDrop(ConnectorInterface $connector, SchemaDiffOp $op, \Throwable $e): bool
    {
        if ($op->kind !== SchemaDiffOp::KIND_DROP_INDEX || !$op->payload instanceof IndexDefinition) {
            return false;
        }
        if ($connector->getConfigProvider()->getDbType() !== 'pgsql') {
            return false;
        }
        $msg = $e->getMessage();
        if (!str_contains($msg, 'cannot drop index') || !str_contains($msg, 'because constraint')) {
            return false;
        }
        $constraintTable = null;
        $constraintName = null;
        if (preg_match(
            '/cannot drop index\s+(\S+)\s+because constraint\s+(\S+)\s+on table\s+(\S+)\s+requires it/i',
            $msg,
            $m
        )) {
            $constraintName = trim($m[2], '"`');
            $constraintTable = trim($m[3], '"`');
        }
        if ($constraintTable === null || $constraintTable === '' || $constraintName === null || $constraintName === '') {
            return false;
        }
        $qt = $connector->quoteTable($constraintTable);
        $qn = $connector->quoteIdentifier($constraintName);
        $healSql = "ALTER TABLE {$qt} DROP CONSTRAINT IF EXISTS {$qn} CASCADE";
        try {
            $connector->query($healSql)->fetch();
        } catch (\Throwable) {
            return false;
        }
        return true;
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
                return $connector->buildAlterAddColumnSql($table, $this->colToArray($col));
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
                return $connector->buildAlterDropColumnSql($table, $col->name);
            case SchemaDiffOp::KIND_DROP_COLUMN:
                /** @var ColumnDefinition $col */
                $col = $op->payload;
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
     * 使用 connector->createTable() API 创建表，由各适配器处理方言（COMMENT、类型映射等）
     */
    private function createTableViaAdapter(ConnectorInterface $connector, string $tableName, TableSchema $payload): void
    {
        /** @var CreateInterface $create */
        $create = $connector->createTable();
        $create->createTable($tableName, $payload->comment);

        $pkColumns = [];
        foreach ($payload->columns as $col) {
            if ($col->primaryKey) {
                $pkColumns[] = $col->name;
            }
        }
        $hasCompositePk = count($pkColumns) > 1;
        $isSqlite = $connector->getConfigProvider()->getDbType() === 'sqlite';

        foreach ($payload->columns as $col) {
            $opts = [];
            if ($col->primaryKey && !$hasCompositePk) {
                $opts[] = 'PRIMARY KEY';
            }
            if ($col->autoIncrement && !($isSqlite && $hasCompositePk)) {
                $opts[] = 'AUTO_INCREMENT';
            }
            if (!$col->nullable && !$col->primaryKey) {
                $opts[] = 'NOT NULL';
            }
            if ($col->default !== null) {
                $d = $col->default;
                if (is_string($d) && strtoupper($d) === 'CURRENT_TIMESTAMP') {
                    $opts[] = 'DEFAULT CURRENT_TIMESTAMP';
                } elseif (is_string($d)) {
                    $opts[] = "DEFAULT '" . str_replace("'", "''", $d) . "'";
                } else {
                    $opts[] = "DEFAULT {$d}";
                }
            }
            if ($col->unique && !$col->primaryKey) {
                $opts[] = 'UNIQUE';
            }
            $options = implode(' ', $opts);
            $create->addColumn($col->name, $col->type, $col->length, $options, $col->comment);
        }

        if ($hasCompositePk) {
            $quoted = array_map(fn(string $n) => '"' . str_replace('"', '""', $n) . '"', $pkColumns);
            $create->addConstraints('PRIMARY KEY (' . implode(', ', $quoted) . ')');
        }

        foreach ($payload->indexes as $idx) {
            $create->addIndex($idx->type, $idx->name, $idx->columns, $idx->comment, $idx->method);
        }

        foreach ($payload->foreignKeys as $fk) {
            $create->addForeignKey(
                $fk->name,
                implode(',', $fk->columns),
                $fk->referencesTable,
                implode(',', $fk->referencesColumns),
                $fk->onDeleteCascade,
                $fk->onUpdateCascade,
            );
        }

        $create->addAdditional('');
        $create->create();
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

    /** @return array{name:string,columns:list<string>,type:string,method:string} */
    private function idxToArray(IndexDefinition $idx): array
    {
        return [
            'name' => $idx->name,
            'columns' => $idx->columns,
            'type' => $idx->type,
            'method' => $idx->method,
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

    private function shouldHealDocumentCatalogNamePidDuplicate(SchemaDiffOp $op, \Throwable $e): bool
    {
        if (!\str_contains($op->tableName, 'developer_workspace_document_catalog')) {
            return false;
        }
        if ($op->kind === SchemaDiffOp::KIND_ADD_INDEX && $op->payload instanceof IndexDefinition) {
            if ($op->payload->name !== 'idx_unique_name_pid') {
                return false;
            }
        } elseif (
            $op->kind === SchemaDiffOp::KIND_MODIFY_COLUMN
            && $op->payload instanceof ColumnDefinition
        ) {
            if ($op->payload->name !== 'pid') {
                return false;
            }
        } else {
            return false;
        }

        $message = \strtolower($e->getMessage());
        return \str_contains($message, 'unique violation')
            || \str_contains($message, 'duplicate')
            || \str_contains($message, 'could not create unique index');
    }

    private function shouldHealDocumentModuleFileDuplicate(SchemaDiffOp $op, \Throwable $e): bool
    {
        if (!\str_contains($op->tableName, 'developer_workspace_document')) {
            return false;
        }
        if (\str_contains($op->tableName, 'developer_workspace_document_catalog')) {
            return false;
        }
        if ($op->kind !== SchemaDiffOp::KIND_ADD_INDEX || !$op->payload instanceof IndexDefinition) {
            return false;
        }
        if ($op->payload->name !== 'idx_module_file_unique') {
            return false;
        }

        $message = \strtolower($e->getMessage());
        return \str_contains($message, 'unique violation')
            || \str_contains($message, 'duplicate')
            || \str_contains($message, 'could not create unique index');
    }

    private function shouldCoalesceDocumentCatalogPidDuringDedupe(SchemaDiffOp $op): bool
    {
        return $op->kind === SchemaDiffOp::KIND_MODIFY_COLUMN
            && $op->payload instanceof ColumnDefinition
            && $op->payload->name === 'pid';
    }

    private function dedupeDocumentCatalogNamePid(
        ConnectorInterface $connector,
        string $tableName,
        bool $coalescePid = false,
    ): int
    {
        $table = $connector->quoteTable($tableName);
        $partitionPid = $coalescePid ? 'COALESCE(pid, 0)' : 'pid';
        $orderBy = $coalescePid
            ? 'CASE WHEN pid IS NULL THEN 1 ELSE 0 END ASC, id ASC'
            : 'id ASC';
        $sql = "DELETE FROM {$table} AS t
                USING (
                    SELECT id, ROW_NUMBER() OVER (
                        PARTITION BY name, {$partitionPid}
                        ORDER BY {$orderBy}
                    ) AS rn
                    FROM {$table}
                ) AS d
                WHERE t.id = d.id AND d.rn > 1";
        $result = $connector->query($sql)->fetch();
        if (\is_array($result) && isset($result['affected_rows'])) {
            return (int) $result['affected_rows'];
        }
        if (\is_array($result) && isset($result[0]['affected_rows'])) {
            return (int) $result[0]['affected_rows'];
        }

        return -1;
    }

    private function dedupeDocumentModuleFile(ConnectorInterface $connector, string $tableName): int
    {
        $table = $connector->quoteTable($tableName);
        $id = $connector->quoteIdentifier('id');
        $moduleName = $connector->quoteIdentifier('module_name');
        $filePath = $connector->quoteIdentifier('file_path');
        $sql = "DELETE FROM {$table}
                WHERE {$id} IN (
                    SELECT {$id}
                    FROM (
                        SELECT {$id}, ROW_NUMBER() OVER (
                            PARTITION BY {$moduleName}, {$filePath}
                            ORDER BY {$id} ASC
                        ) AS rn
                        FROM {$table}
                        WHERE {$moduleName} IS NOT NULL AND {$filePath} IS NOT NULL
                    ) AS d
                    WHERE d.rn > 1
                )";
        $result = $connector->query($sql)->fetch();
        if (\is_array($result) && isset($result['affected_rows'])) {
            return (int) $result['affected_rows'];
        }
        if (\is_array($result) && isset($result[0]['affected_rows'])) {
            return (int) $result[0]['affected_rows'];
        }

        return -1;
    }

}
