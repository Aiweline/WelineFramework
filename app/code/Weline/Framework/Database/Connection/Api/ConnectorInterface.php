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
     * @return list<array{name: string, columns: list<string>, unique: bool}>
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
     * 生成 ADD COLUMN DDL。$col: name, type, length?, nullable, primaryKey, autoIncrement, default?, comment, unique
     */
    public function buildAlterAddColumnSql(string $table, array $col): string;

    /**
     * 生成 MODIFY COLUMN DDL（方言由各适配器实现）。
     * @param string $table 表名
     * @param array $col 新列定义（name, type, length?, nullable, ...）
     * @param array|null $existingCol 现有列定义；类型变更时用于生成兼容当前类型的 NULL 填充值，避免 UPDATE 类型不匹配
     */
    public function buildAlterModifyColumnSql(string $table, array $col, ?array $existingCol = null): string;

    /**
     * 生成 DROP COLUMN DDL。
     */
    public function buildAlterDropColumnSql(string $table, string $colName): string;

    /**
     * 生成表注释 DDL。PostgreSQL 用 COMMENT ON TABLE，MySQL 用 ALTER TABLE COMMENT。
     */
    public function buildAlterTableCommentSql(string $table, string $comment): string;

    /**
     * 生成 ADD INDEX / CREATE INDEX DDL。$idx: name, columns, type, method
     */
    public function buildAddIndexSql(string $table, array $idx): string;

    /**
     * 生成 DROP INDEX DDL。
     */
    public function buildDropIndexSql(string $table, string $indexName): string;

    /**
     * 生成 ADD FOREIGN KEY DDL。$fk: name, columns, referencesTable, referencesColumns, onDeleteCascade, onUpdateCascade
     */
    public function buildAddForeignKeySql(string $table, array $fk): string;

    /**
     * 生成 DROP FOREIGN KEY / DROP CONSTRAINT DDL。
     */
    public function buildDropForeignKeySql(string $table, string $fkName): string;

    /**
     * 建表时的默认 additional 片段（如 MySQL 的 ENGINE=InnoDB...），无则返回空字符串。
     * 方言由各适配器实现。
     */
    public function getDefaultTableAdditional(): string;
}
