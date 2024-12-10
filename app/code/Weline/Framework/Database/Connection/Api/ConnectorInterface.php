<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Database\Connection\Api;

use Weline\Framework\Database\Connection\Api\Sql\QueryInterface;
use Weline\Framework\Database\Connection\Api\Sql\Table\AlterInterface;
use Weline\Framework\Database\Connection\Api\Sql\Table\CreateInterface;
use Weline\Framework\Database\DbManager\ConfigProviderInterface;

interface ConnectorInterface
{
    public function create(): static;
    public function close(): void;

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
     * @return QueryInterface
     */
    public function getIndexFields(string $table): QueryInterface;

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

    public function getVersion(): string;
}
