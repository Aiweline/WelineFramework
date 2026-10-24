<?php

declare(strict_types=1);

namespace Weline\Database\Service\Release;

use RuntimeException;
use Weline\Framework\Database\Connection\Api\ConnectorInterface;
use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Database\Schema\ColumnDefinition;
use Weline\Framework\Database\Schema\ForeignKeyDefinition;
use Weline\Framework\Database\Schema\IndexDefinition;
use Weline\Framework\Database\Schema\IndexDefinitionContract;
use Weline\Framework\Database\Schema\PrimaryKeyPromotionDefinition;
use Weline\Framework\Database\Schema\SchemaDiffOp;
use Weline\Framework\Database\Schema\TableSchema;

/**
 * 新 DDL 原语：把 SchemaDiffOp 翻译为方言 DDL 并执行。
 *
 * 只调用 ConnectorInterface 的公共 build*() 与 createTableFromSchema；
 * 不做备份、不做 journal、不做 checkpoint，这些由上层协调器负责。
 */
final class SchemaDdlExecutor
{
    public function __construct(private readonly ConnectionFactory $connectionFactory)
    {
    }

    public function build(SchemaDiffOp $op, bool $rollback = false, ?ConnectorInterface $connector = null): string
    {
        $connector ??= $this->connectionFactory->getConnector();
        $table = $connector->formatTableName($op->tableName);

        return $rollback
            ? $this->buildRollbackDdl($connector, $op, $table)
            : $this->buildForwardDdl($connector, $op, $table);
    }

    public function apply(SchemaDiffOp $op, bool $rollback = false, ?ConnectorInterface $connector = null): void
    {
        $connector ??= $this->connectionFactory->getConnector();
        if (!$rollback && $op->kind === SchemaDiffOp::KIND_CREATE_TABLE) {
            if (!$op->payload instanceof TableSchema) {
                throw new RuntimeException(
                    'create table payload 必须是 TableSchema; table=' . $op->tableName
                );
            }
            $connector->createTableFromSchema($op->tableName, $this->tableSchemaPayload($op->payload));
            return;
        }

        if ($op->kind === SchemaDiffOp::KIND_DROP_COLUMN) {
            $this->dropDependentIndexes($connector, $op);
        }

        $sql = $this->build($op, $rollback, $connector);
        if ($sql === '') {
            if ($this->isEmptyNoOp($op, $rollback)) {
                return;
            }
            throw new RuntimeException(
                'DDL 为空; kind=' . $op->kind . '; table=' . $op->tableName
            );
        }
        $stmt = $connector->query($sql);
        $stmt->fetch();
        if (method_exists($stmt, 'closeCursor')) {
            $stmt->closeCursor();
        }
    }

    /**
     * @return array{
     *     comment: string,
     *     columns: list<array<string, mixed>>,
     *     indexes: list<array<string, mixed>>,
     *     foreignKeys: list<array<string, mixed>>
     * }
     */
    public function tableSchemaPayload(TableSchema $schema): array
    {
        $columns = [];
        foreach ($schema->columns as $column) {
            if (!$column instanceof ColumnDefinition) {
                throw new RuntimeException('table schema 包含非法列定义; table=' . $schema->tableName);
            }
            $columns[] = $this->columnToArray($column, true);
        }

        $indexes = [];
        foreach ($schema->indexes as $index) {
            if (!$index instanceof IndexDefinition) {
                throw new RuntimeException('table schema 包含非法索引定义; table=' . $schema->tableName);
            }
            $indexes[] = $this->indexToArray($index);
        }

        $foreignKeys = [];
        foreach ($schema->foreignKeys as $foreignKey) {
            if (!$foreignKey instanceof ForeignKeyDefinition) {
                throw new RuntimeException('table schema 包含非法外键定义; table=' . $schema->tableName);
            }
            $foreignKeys[] = $this->foreignKeyToArray($foreignKey);
        }

        return [
            'comment' => $schema->comment,
            'columns' => $columns,
            'indexes' => $indexes,
            'foreignKeys' => $foreignKeys,
        ];
    }

    /**
     * SQLite 重建表会复制仍引用被删列的显式索引；先逐个 drop 再重建。
     * 其他引擎不做额外处理。
     */
    private function dropDependentIndexes(ConnectorInterface $connector, SchemaDiffOp $op): void
    {
        if (\strtolower((string)$connector->getConfigProvider()->getDbType()) !== 'sqlite') {
            return;
        }

        $columnName = \strtolower($this->requireColumn($op)->name);
        $table = $connector->formatTableName($op->tableName);
        foreach ($connector->getTableIndexes($op->tableName) as $index) {
            if (!\is_array($index)) {
                continue;
            }
            $indexName = \trim((string)($index['name'] ?? ''));
            if ($indexName === ''
                || \str_starts_with($indexName, IndexDefinitionContract::SQLITE_CONSTRAINT_INDEX_PREFIX)
            ) {
                continue;
            }
            $columns = \array_map(
                'strtolower',
                \array_map('strval', (array)($index['columns'] ?? [])),
            );
            if (!\in_array($columnName, $columns, true)) {
                continue;
            }

            $sql = $connector->buildDropIndexSql($table, $indexName);
            if ($sql === '') {
                continue;
            }
            try {
                $stmt = $connector->query($sql);
        $stmt->fetch();
        if (method_exists($stmt, 'closeCursor')) {
            $stmt->closeCursor();
        }
            } catch (\Throwable $exception) {
                // ALTER/DROP 过程中索引可能已被并发移除；只有 already-missing 可忽略。
                if (\stripos($exception->getMessage(), 'no such index') !== false) {
                    continue;
                }
                throw $exception;
            }
        }
    }

    private function buildForwardDdl(ConnectorInterface $connector, SchemaDiffOp $op, string $table): string
    {
        switch ($op->kind) {
            case SchemaDiffOp::KIND_CREATE_TABLE:
                if (!$op->payload instanceof TableSchema) {
                    throw new RuntimeException(
                        'create table payload 必须是 TableSchema; table=' . $op->tableName
                    );
                }
                return '';

            case SchemaDiffOp::KIND_ADD_COLUMN:
                return $connector->buildAlterAddColumnSql(
                    $table,
                    $this->columnToArray($this->requireColumn($op), true),
                );

            case SchemaDiffOp::KIND_DROP_COLUMN:
                return $connector->buildAlterDropColumnSql($table, $this->requireColumn($op)->name);

            case SchemaDiffOp::KIND_MODIFY_COLUMN:
                $column = $this->requireColumn($op);
                $existing = $op->rollbackPayload instanceof ColumnDefinition
                    ? $this->columnToArray($op->rollbackPayload)
                    : null;
                return $connector->buildAlterModifyColumnSql(
                    $table,
                    $this->columnToArray($column),
                    $existing,
                );

            case SchemaDiffOp::KIND_ADD_INDEX:
                return $connector->buildAddIndexSql($table, $this->indexToArray($this->requireIndex($op)));

            case SchemaDiffOp::KIND_DROP_INDEX:
                return $connector->buildDropIndexSql($table, $this->requireIndex($op)->name);

            case SchemaDiffOp::KIND_ADD_FOREIGN_KEY:
                return $connector->buildAddForeignKeySql(
                    $table,
                    $this->foreignKeyToArray($this->requireForeignKey($op)),
                );

            case SchemaDiffOp::KIND_DROP_FOREIGN_KEY:
                return $connector->buildDropForeignKeySql($table, $this->requireForeignKey($op)->name);

            case SchemaDiffOp::KIND_MODIFY_TABLE_COMMENT:
                if (!\is_string($op->payload)) {
                    throw new RuntimeException(
                        'table comment payload 必须是 string; table=' . $op->tableName
                    );
                }
                return $connector->buildAlterTableCommentSql($table, $this->escapeComment($op->payload));

            case SchemaDiffOp::KIND_PROMOTE_PRIMARY_KEY:
                if (!$op->payload instanceof PrimaryKeyPromotionDefinition) {
                    throw new RuntimeException(
                        'promote primary key payload 必须是 PrimaryKeyPromotionDefinition; table=' . $op->tableName
                    );
                }
                $sql = $connector->buildPromotePrimaryKeySql(
                    $table,
                    $op->payload->columns,
                    $op->payload->replaceUniqueIndexNames,
                );
                $trimmed = \ltrim($sql);
                if ($trimmed === '' || \str_starts_with($trimmed, '--')) {
                    throw new RuntimeException(
                        'adapter 无法提升 PRIMARY KEY; table=' . $op->tableName
                        . '; columns=' . \implode(',', $op->payload->columns)
                        . '; detail=' . \trim($sql)
                    );
                }
                return $sql;
        }

        throw new RuntimeException('不支持的 SchemaDiffOp: ' . $op->kind . '; table=' . $op->tableName);
    }

    private function buildRollbackDdl(ConnectorInterface $connector, SchemaDiffOp $op, string $table): string
    {
        switch ($op->kind) {
            case SchemaDiffOp::KIND_CREATE_TABLE:
                return 'DROP TABLE IF EXISTS ' . $table;

            case SchemaDiffOp::KIND_ADD_COLUMN:
                return $connector->buildAlterDropColumnSql($table, $this->requireColumn($op)->name);

            case SchemaDiffOp::KIND_DROP_COLUMN:
                return $connector->buildAlterAddColumnSql(
                    $table,
                    $this->columnToArray($this->requireColumn($op)),
                );

            case SchemaDiffOp::KIND_MODIFY_COLUMN:
                $column = $this->requireColumn($op);
                if (!$op->rollbackPayload instanceof ColumnDefinition) {
                    return '';
                }
                return $connector->buildAlterModifyColumnSql(
                    $table,
                    $this->columnToArray($op->rollbackPayload),
                    $this->columnToArray($column),
                );

            case SchemaDiffOp::KIND_ADD_INDEX:
                return $connector->buildDropIndexSql($table, $this->requireIndex($op)->name);

            case SchemaDiffOp::KIND_DROP_INDEX:
                return $connector->buildAddIndexSql($table, $this->indexToArray($this->requireIndex($op)));

            case SchemaDiffOp::KIND_ADD_FOREIGN_KEY:
                return $connector->buildDropForeignKeySql($table, $this->requireForeignKey($op)->name);

            case SchemaDiffOp::KIND_DROP_FOREIGN_KEY:
                return $connector->buildAddForeignKeySql(
                    $table,
                    $this->foreignKeyToArray($this->requireForeignKey($op)),
                );

            case SchemaDiffOp::KIND_MODIFY_TABLE_COMMENT:
                if (!\is_string($op->rollbackPayload)) {
                    throw new RuntimeException(
                        'table comment rollback payload 必须是 string; table=' . $op->tableName
                    );
                }
                return $connector->buildAlterTableCommentSql(
                    $table,
                    $this->escapeComment($op->rollbackPayload),
                );

            case SchemaDiffOp::KIND_PROMOTE_PRIMARY_KEY:
                // 结构备份才是权威；不生成方言相关的 PK 降级 DDL。
                return '';
        }

        throw new RuntimeException('不支持的 SchemaDiffOp: ' . $op->kind . '; table=' . $op->tableName);
    }

    private function isEmptyNoOp(SchemaDiffOp $op, bool $rollback): bool
    {
        if ($op->kind === SchemaDiffOp::KIND_MODIFY_TABLE_COMMENT) {
            // SQLite 等适配器不持久化表注释，build 返回空串即明确 no-op。
            return true;
        }
        return $rollback && $op->kind === SchemaDiffOp::KIND_PROMOTE_PRIMARY_KEY;
    }

    private function requireColumn(SchemaDiffOp $op): ColumnDefinition
    {
        if (!$op->payload instanceof ColumnDefinition) {
            throw new RuntimeException(
                'column payload 必须是 ColumnDefinition; kind=' . $op->kind . '; table=' . $op->tableName
            );
        }
        return $op->payload;
    }

    private function requireIndex(SchemaDiffOp $op): IndexDefinition
    {
        if (!$op->payload instanceof IndexDefinition) {
            throw new RuntimeException(
                'index payload 必须是 IndexDefinition; kind=' . $op->kind . '; table=' . $op->tableName
            );
        }
        return $op->payload;
    }

    private function requireForeignKey(SchemaDiffOp $op): ForeignKeyDefinition
    {
        if (!$op->payload instanceof ForeignKeyDefinition) {
            throw new RuntimeException(
                'foreign key payload 必须是 ForeignKeyDefinition; kind=' . $op->kind . '; table=' . $op->tableName
            );
        }
        return $op->payload;
    }

    /** @return array{name: string, type: string, length: int|string|null, nullable: bool, primaryKey: bool, autoIncrement: bool, default: mixed, comment: string, unique: bool} */
    private function columnToArray(ColumnDefinition $column, bool $declarative = false): array
    {
        $unique = $column->unique;
        if ($declarative && !$column->primaryKey) {
            $unique = false;
        }

        return [
            'name' => $column->name,
            'type' => $column->type,
            'length' => $column->length,
            'nullable' => $column->nullable,
            'primaryKey' => $column->primaryKey,
            'autoIncrement' => $column->autoIncrement,
            'default' => $column->default,
            'comment' => $column->comment,
            'unique' => $unique,
        ];
    }

    /** @return array{name: string, columns: list<string>, type: string, method: string, comment: string} */
    private function indexToArray(IndexDefinition $index): array
    {
        return [
            'name' => $index->name,
            'columns' => $index->columns,
            'type' => $index->type,
            'method' => $index->method,
            'comment' => $index->comment,
        ];
    }

    /** @return array{name: string, columns: list<string>, referencesTable: string, referencesColumns: list<string>, onDeleteCascade: bool, onUpdateCascade: bool} */
    private function foreignKeyToArray(ForeignKeyDefinition $foreignKey): array
    {
        return [
            'name' => $foreignKey->name,
            'columns' => $foreignKey->columns,
            'referencesTable' => $foreignKey->referencesTable,
            'referencesColumns' => $foreignKey->referencesColumns,
            'onDeleteCascade' => $foreignKey->onDeleteCascade,
            'onUpdateCascade' => $foreignKey->onUpdateCascade,
        ];
    }

    private function escapeComment(string $comment): string
    {
        return \str_replace("'", "''", $comment);
    }
}
