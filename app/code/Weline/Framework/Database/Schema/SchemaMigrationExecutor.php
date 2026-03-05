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
            $migrationId = $this->migrationModel->recordSchemaDdl(
                $moduleName,
                $op->tableName,
                $connectionName,
                $forwardSql,
                $rollbackSql,
                $op->modelClass,
            );
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
        if (!str_contains($sql, ";\n")) {
            return [$sql];
        }
        return explode(";\n", $sql);
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
        $table = $connector->quoteTable($op->tableName);
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
        $table = $connector->quoteTable($op->tableName);
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

        foreach ($payload->columns as $col) {
            $opts = [];
            if ($col->primaryKey) {
                $opts[] = 'PRIMARY KEY';
            }
            if ($col->autoIncrement) {
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
}
