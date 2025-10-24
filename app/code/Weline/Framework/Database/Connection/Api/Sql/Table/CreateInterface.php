<?php

declare(strict_types=1);

namespace Weline\Framework\Database\Connection\Api\Sql\Table;

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

use Weline\Framework\Database\Connection\Api\Sql\TableInterface;

interface CreateInterface
{
    /**
     * @DESC          # 创建表
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2021/9/5 17:22
     * 参数区：
     *
     * @param string $table
     * @param string $comment
     *
     * @return CreateInterface
     */
    public function createTable(string $table, string $comment = ''): CreateInterface;

    /**
     * @DESC          # 创建表
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2021/8/31 20:36
     * 参数区：
     * @return bool
     */
    public function create(): mixed;

    /**
     * @DESC          # 添加字段
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2021/8/26 21:31
     * 参数区：
     *
     * @param string   $field_name 字段名
     * @param string   $type       字段类型
     * @param int|null $length     长度
     * @param string   $options    配置
     * @param string   $comment    字段注释
     *
     * @return CreateInterface
     */
    public function addColumn(string $field_name, string $type, int|string|null $length, string $options, string $comment): CreateInterface;

    /**
     * @DESC          # 添加索引
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2021/9/5 17:26
     * 参数区：
     *
     * @param string       $type         【索引类型】
     * @param string       $name         【索引名】
     * @param array|string $column
     * @param string       $comment      【索引注释】
     * @param string       $index_method 【索引类型】
     *
     * @return CreateInterface
     */
    public function addIndex(string $type, string $name, array|string $column, string $comment = '', string $index_method = ''): CreateInterface;

    /**
     * @DESC          # 建表附加
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2021/9/5 17:27
     * 参数区：
     *
     * @param string $additional_sql 【建表时附加的SQL】
     *
     * @return CreateInterface
     */
    public function addAdditional(string $additional_sql = 'ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4;'): CreateInterface;

    /**
     * @DESC          # 表约束
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2021/9/5 17:30
     * 参数区：
     *
     * @param string $constraints 【表约束语句】
     *
     * @return CreateInterface
     */
    public function addConstraints(string $constraints = ''): CreateInterface;

    /**
     * @DESC          # 读取表字段
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2021/9/5 17:32
     * 参数区：
     *
     * @param string $table_name
     *
     * @return mixed
     */
    public function getTableColumns(string $table_name = ''): mixed;

    /**
     * @DESC          # 添加外键
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2021/8/31 20:37
     * 参数区：
     *
     * @param string $FK_Name
     * @param string $FK_Field
     * @param string $references_table
     * @param string $references_field
     * @param false  $on_delete
     * @param false  $on_update
     *
     * @return $this
     */
    public function addForeignKey(string $FK_Name, string $FK_Field, string $references_table, string $references_field, bool $on_delete = false, bool $on_update = false): CreateInterface;
}
