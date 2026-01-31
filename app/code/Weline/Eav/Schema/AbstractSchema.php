<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Eav\Schema;

use Weline\Framework\Database\Api\Db\TableInterface;

/**
 * EAV表结构抽象基类 (DRY - 不重复原则)
 * 
 * 提供通用的列定义辅助方法和默认实现。
 */
abstract class AbstractSchema implements SchemaInterface
{
    /**
     * 创建自增主键列定义
     */
    protected function primaryKey(string $comment = 'ID'): array
    {
        return [
            'type' => TableInterface::column_type_INTEGER,
            'length' => 0,
            'options' => 'primary key auto_increment',
            'comment' => $comment,
        ];
    }

    /**
     * 创建整数列定义
     */
    protected function integer(string $comment, string $options = 'not null'): array
    {
        return [
            'type' => TableInterface::column_type_INTEGER,
            'length' => 0,
            'options' => $options,
            'comment' => $comment,
        ];
    }

    /**
     * 创建小整数列定义
     */
    protected function smallint(string $comment, int $length = 1, string $options = 'default 0'): array
    {
        return [
            'type' => TableInterface::column_type_SMALLINT,
            'length' => $length,
            'options' => $options,
            'comment' => $comment,
        ];
    }

    /**
     * 创建布尔列定义
     */
    protected function boolean(string $comment, bool $default = false): array
    {
        return [
            'type' => TableInterface::column_type_SMALLINT,
            'length' => 1,
            'options' => 'default ' . ($default ? '1' : '0'),
            'comment' => $comment,
        ];
    }

    /**
     * 创建VARCHAR列定义
     */
    protected function varchar(string $comment, int $length = 255, string $options = 'not null'): array
    {
        return [
            'type' => TableInterface::column_type_VARCHAR,
            'length' => $length,
            'options' => $options,
            'comment' => $comment,
        ];
    }

    /**
     * 创建TEXT列定义
     */
    protected function text(string $comment, string $options = ''): array
    {
        return [
            'type' => TableInterface::column_type_TEXT,
            'length' => 0,
            'options' => $options,
            'comment' => $comment,
        ];
    }

    /**
     * 创建MEDIUMTEXT列定义
     */
    protected function mediumText(string $comment, string $options = ''): array
    {
        return [
            'type' => TableInterface::column_type_MEDIU_TEXT,
            'length' => 0,
            'options' => $options,
            'comment' => $comment,
        ];
    }

    /**
     * 创建LONGTEXT列定义
     */
    protected function longText(string $comment, string $options = ''): array
    {
        return [
            'type' => TableInterface::column_type_LONG_TEXT,
            'length' => 0,
            'options' => $options,
            'comment' => $comment,
        ];
    }

    /**
     * 创建唯一索引定义
     */
    protected function uniqueIndex(string|array $columns, string $comment = ''): array
    {
        return [
            'type' => TableInterface::index_type_UNIQUE,
            'columns' => $columns,
            'comment' => $comment,
        ];
    }

    /**
     * 创建普通索引定义
     */
    protected function index(string|array $columns, string $comment = ''): array
    {
        return [
            'type' => TableInterface::index_type_KEY,
            'columns' => $columns,
            'comment' => $comment,
        ];
    }

    /**
     * 创建外键定义
     */
    protected function foreignKey(
        string $column,
        string $referenceTable,
        string $referenceColumn,
        bool $onDelete = false,
        bool $onUpdate = false
    ): array {
        return [
            'column' => $column,
            'reference_table' => $referenceTable,
            'reference_column' => $referenceColumn,
            'on_delete' => $onDelete,
            'on_update' => $onUpdate,
        ];
    }

    /**
     * 默认无外键
     */
    public function getForeignKeys(): array
    {
        return [];
    }

    /**
     * 默认无初始数据
     */
    public function getInitialData(): array
    {
        return [];
    }

    /**
     * 默认无依赖
     */
    public function getDependencies(): array
    {
        return [];
    }

    /**
     * 默认唯一键为主键
     */
    public function getUniqueKey(): string|array
    {
        return '';
    }
}
