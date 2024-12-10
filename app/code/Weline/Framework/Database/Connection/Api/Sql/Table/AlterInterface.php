<?php

declare(strict_types=1);

namespace Weline\Framework\Database\Connection\Api\Sql\Table;

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */


interface AlterInterface
{
    /**
     * @DESC          # 修改表
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2021/9/5 17:23
     * 参数区：
     *
     * @param string $table_name
     * @param string $primary_key
     * @param string $comment 表注释
     *
     * @return AlterInterface
     */
    public function forTable(string $table_name, string $primary_key, string $comment = '', string $new_table_name = ''): AlterInterface;

    /**
     * @DESC          # 修改字段 【$old_field和$field_name相同则是修改字段属性，不同则是修改字段名】
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2021/8/26 21:31
     * 参数区：
     *
     * @param string      $old_field   旧字段
     * @param string      $field_name  新字段名
     * @param string      $after_field 指定添加到某个字段之后
     * @param string|null $type        字段类型
     * @param int|null    $length      长度
     * @param string|null $options     配置
     * @param string|null $comment     字段注释
     *
     * @return AlterInterface
     */
    public function alterColumn(string $old_field, string $field_name, string $after_field = '', string $type = null, ?int $length = null, string $options = null, string $comment = null): AlterInterface;

    /**
     * @DESC          # 删除字段
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2021/9/5 16:09
     * 参数区：
     *
     * @param string $field_name
     *
     * @return AlterInterface
     */
    public function deleteColumn(string $field_name): AlterInterface;


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
     * @return AlterInterface
     */
    public function addColumn(string $field_name, string $after_column, string $type, ?int $length, string $options, string $comment): AlterInterface;

    /**
     * @DESC          # 添加索引
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2021/9/5 17:26
     * 参数区：
     *
     * @param string       $type 【索引类型】
     * @param string       $name 【索引名】
     * @param array|string $column
     *
     * @return AlterInterface
     */
    public function addIndex(string $type, string $name, array|string $column, string $comment = '', string $index_method = 'BTREE'): AlterInterface;

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
     * @return AlterInterface
     */
    public function addAdditional(string $additional_sql = 'ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4;'): AlterInterface;

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
     * @return AlterInterface
     */
    public function addConstraints(string $constraints = ''): AlterInterface;

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
     * @return AlterInterface
     */
    public function addForeignKey(string $FK_Name, string $FK_Field, string $references_table, string $references_field, bool $on_delete = false, bool $on_update = false): AlterInterface;

    /**
     * @DESC          # 添加alter函数进行表更改
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2022/2/7 11:55
     * 参数区：
     * @return bool
     */
    public function alter(): bool;
}
