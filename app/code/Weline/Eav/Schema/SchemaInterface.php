<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Eav\Schema;

/**
 * EAV表结构接口 (ISP - 接口隔离原则)
 * 
 * 定义EAV模块表结构的契约，每个Schema类只需实现必要的方法。
 */
interface SchemaInterface
{
    /**
     * 获取表名（不含前缀）
     */
    public function getTableName(): string;

    /**
     * 获取表注释
     */
    public function getTableComment(): string;

    /**
     * 获取列定义
     * 
     * @return array 列定义数组，格式：
     * [
     *     'column_name' => [
     *         'type' => TableInterface::column_type_*,
     *         'length' => int,
     *         'options' => 'primary key auto_increment|not null|default xxx',
     *         'comment' => '列注释'
     *     ]
     * ]
     */
    public function getColumns(): array;

    /**
     * 获取索引定义
     * 
     * @return array 索引定义数组，格式：
     * [
     *     'index_name' => [
     *         'type' => TableInterface::index_type_*,
     *         'columns' => 'column_name' | ['col1', 'col2'],
     *         'comment' => '索引注释'
     *     ]
     * ]
     */
    public function getIndexes(): array;

    /**
     * 获取外键定义
     * 
     * @return array 外键定义数组，格式：
     * [
     *     'fk_name' => [
     *         'column' => 'local_column',
     *         'reference_table' => 'table_name',
     *         'reference_column' => 'column_name',
     *         'on_delete' => true|false,
     *         'on_update' => true|false
     *     ]
     * ]
     */
    public function getForeignKeys(): array;

    /**
     * 获取初始数据（如预设类型）
     * 
     * @return array 初始数据数组，格式：
     * [
     *     ['column1' => 'value1', 'column2' => 'value2'],
     *     ...
     * ]
     */
    public function getInitialData(): array;

    /**
     * 获取依赖的Schema类名列表
     * 用于确定表创建顺序
     * 
     * @return array<class-string<SchemaInterface>>
     */
    public function getDependencies(): array;

    /**
     * 获取唯一键（用于初始数据的upsert）
     * 
     * @return string|array
     */
    public function getUniqueKey(): string|array;
}
