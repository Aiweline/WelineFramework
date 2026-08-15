<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Database\Connection\Api;

use Weline\Framework\Database\Connection\ConnectionInterface as DbConnectionInterface;
use Weline\Framework\Database\Connection\Api\Sql\QueryInterface;
use Weline\Framework\Database\Connection\Api\Sql\Table\AlterInterface;
use Weline\Framework\Database\Connection\Api\Sql\Table\CreateInterface;
use Weline\Framework\Database\DbManager\ConfigProviderInterface;

interface ConnectorInterface
{
    public function create(): static;

    public function close(): void;

    /**
     * 获取封装后的数据库连接（推荐使用，避免直接依赖 PDO）
     * @since 1.0.0
     */
    public function getWrappedConnection(): DbConnectionInterface;

    /**
     * @DESC          # 查询
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2021/9/5 17:33
     * 参数区：
     *
     * @param string $sql
     *
     * @return QueryInterface
     */
    public function query(string $sql): QueryInterface;

    /**
     * 获取查询构建器，用于 table/fields/where/select 等链式调用。方言由适配器实现。
     */
    public function getQuery(): QueryInterface;

    public function getConfigProvider(): ConfigProviderInterface;

    /**
     * @DESC          # 创建表
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2021/9/5 21:03
     * 参数区：
     * @return CreateInterface
     */
    public function createTable(): Sql\Table\CreateInterface;

    /**
     * @DESC          # 修改表
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2021/9/5 21:04
     * 参数区：
     * @return AlterInterface
     */
    public function alterTable(): Sql\Table\AlterInterface;

    /**
     * @param string $table 索引数据库
     * @return bool
     */
    public function reindex(string $table): bool;

    /**
     * @DESC          # 查看所有索引字段
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2022/5/17 22:52
     * 参数区：
     * @param string $table
     * @return array
     */
    public function getIndexFields(string $table): array;

    /**
     * @DESC          # 读取创建表SQL
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2021/9/5 22:08
     * 参数区：
     *
     * @param string $table_name
     *
     * @return mixed
     */
    public function getCreateTableSql(string $table_name): string;

    public function tableExist(string $table_name): bool;

    /**
     * 批量检查哪些表名存在，返回实际存在的表名列表。
     * 用于 SchemaDiff 阶段将 N 次 tableExist() 合并为 1 次查询。
     * 方言由适配器实现。
     *
     * @param list<string> $tableNames
     * @return list<string> 实际存在的表名（与输入顺序无关）
     */
    public function getExistingTables(array $tableNames): array;

    /**
     * 若表存在则删除。方言由各适配器实现。
     */
    public function dropTableIfExists(string $table): void;

    public function getVersion(): string;

    public function hasField(string $table, string $field): bool;

    public function hasIndex(string $table, string $idx_name): bool;

    /**
     * 读取表注释。方言由适配器实现，禁止在 DbSchemaReader 中写 SQL。
     */
    public function getTableComment(string $table): string;

    /**
     * 读取表列信息。方言由适配器实现。
     *
     * @return list<array{name: string, type: string, length: ?int, nullable: bool, primary_key: bool, auto_increment: bool, default: mixed, comment: string, unique: bool}>
     */
    public function getTableColumns(string $table): array;

    /**
     * 读取表索引（不含主键）。方言由适配器实现。
     *
     * @return list<array{name: string, columns: list<string>, unique: bool, method?: string}>
     */
    public function getTableIndexes(string $table): array;

    /**
     * 读取表外键。方言由适配器实现。
     *
     * @return list<array{name: string, columns: list<string>, ref_table: string, ref_columns: list<string>, on_delete_cascade: bool, on_update_cascade: bool}>
     */
    public function getTableForeignKeys(string $table): array;

    /**
     * 引用表名（含 schema.table），用于 DDL。由各适配器按方言实现。
     */
    public function quoteTable(string $table): string;

    /**
     * 引用标识符（索引/约束等），用于 DDL。由各适配器按方言实现。
     */
    public function quoteIdentifier(string $identifier): string;

    /**
     * Build DDL for the adapter's current exact physical target. $table may be
     * qualified and/or quoted; implementations must not add a prefix or replace
     * its namespace. $col: name, type, length?, nullable, primaryKey,
     * autoIncrement, default?, comment, unique.
     */
    public function buildAlterAddColumnSql(string $table, array $col): string;

    /**
     * Generate MODIFY COLUMN DDL for the exact physical target. The adapter
     * must not prefix $table or replace an explicit namespace.
     * @param string $table 当前 adapter 的 exact physical 表名（可 qualified/quoted）
     * @param array $col 新列定义（name, type, length?, nullable, ...）
     * @param array|null $existingCol 现有列定义；类型变更时用于生成兼容当前类型的 NULL 填充值，避免 UPDATE 类型不匹配
     */
    public function buildAlterModifyColumnSql(string $table, array $col, ?array $existingCol = null): string;

    /**
     * Generate DROP COLUMN DDL for the exact physical target; never prefix or
     * replace its namespace.
     */
    public function buildAlterDropColumnSql(string $table, string $colName): string;

    /**
     * Generate table-comment DDL for the exact physical target; never prefix
     * or replace its namespace. PostgreSQL uses COMMENT ON TABLE and MySQL ALTER TABLE COMMENT.
     */
    public function buildAlterTableCommentSql(string $table, string $comment): string;

    /**
     * Generate ADD/CREATE INDEX DDL for the exact physical target; never
     * prefix or replace its namespace. $idx: name, columns, type, method.
     */
    public function buildAddIndexSql(string $table, array $idx): string;

    /**
     * Generate DROP INDEX DDL owned by the exact physical target; never prefix
     * or replace its namespace.
     */
    public function buildDropIndexSql(string $table, string $indexName): string;

    /**
     * Generate ADD FOREIGN KEY DDL for the exact physical target. $table is
     * never prefixed or moved; referencesTable deliberately remains a logical
     * name and is resolved by the adapter. Other keys: name, columns,
     * referencesColumns, onDeleteCascade, onUpdateCascade.
     */
    public function buildAddForeignKeySql(string $table, array $fk): string;

    /**
     * Generate DROP FOREIGN KEY/CONSTRAINT DDL for the exact physical target;
     * never prefix or replace its namespace.
     */
    public function buildDropForeignKeySql(string $table, string $fkName): string;

    /**
     * 建表时的默认 additional 片段（如 MySQL 的 ENGINE=InnoDB...），无则返回空字符串。
     * 方言由各适配器实现。
     */
    public function getDefaultTableAdditional(): string;

    /**
     * 按声明式 schema 建表。PRIMARY KEY / AUTO_INCREMENT 等方言规则由各适配器实现。
     *
     * @param array{
     *   comment?: string,
     *   columns?: list<array{name:string,type?:string,length?:int|string|null,nullable?:bool,primaryKey?:bool,autoIncrement?:bool,default?:mixed,comment?:string,unique?:bool}>,
     *   indexes?: list<array{name:string,columns:list<string>,type?:string,method?:string,comment?:string}>,
     *   foreignKeys?: list<array{name:string,columns:list<string>,referencesTable:string,referencesColumns:list<string>,onDeleteCascade?:bool,onUpdateCascade?:bool}>
     * } $schema
     */
    public function createTableFromSchema(string $tableName, array $schema): void;
}
