<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Setup\Db;

use PDOException;
use Weline\Framework\App\Exception;
use Weline\Framework\Database\Connection\Adapter\Mysql\Table;
use Weline\Framework\Database\Connection\Adapter\Mysql\Table\Alter;
use Weline\Framework\Database\Connection\Adapter\Mysql\Table\Create;
use Weline\Framework\Database\Connection\Api\ConnectorInterface;
use Weline\Framework\Database\Connection\Api\Sql\Table\AlterInterface;
use Weline\Framework\Database\Connection\Api\Sql\Table\CreateInterface;
use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Database\DbManager\ConfigProvider;
use Weline\Framework\Database\Exception\LinkException;

class Setup
{
    private ?ConnectorInterface $connector = null;

    /**
     * Setup constructor.
     *
     * @param ConfigProvider $configProvider
     *
     * @throws Exception
     * @throws \ReflectionException
     */
    public function __construct(
        $connector = null
    ) {
        $this->connector = $connector;
    }

    public function setConnection(ConnectionFactory $connecttion)
    {
        $this->connector = $connecttion->getConnector();
        return $this;
    }
    public function setConnector(ConnectorInterface $connector)
    {
        $this->connector = $connector;
        return $this;
    }

    public function getConnector()
    {
        return $this->connector;
    }

    /**
     * @DESC         | 创建表
     *
     * 参数区：
     *
     * @param string $table_name
     * @param string $comment
     *
     * @return Create
     */
    public function createTable(string $table_name, string $comment = ''): CreateInterface
    {
        $table_name = $this->getTable($table_name);
        return $this->getConnector()->reset()->createTable()->createTable($table_name, $comment);
    }

    /**
     * @DESC         |修改表
     *
     * 参数区：
     *
     * @param string $table_name
     * @param string $primary_key
     * @param string $comment
     * @param string $new_table_name
     *
     * @return Alter
     */
    public function alterTable(string $table_name, string $primary_key, string $comment = '', string $new_table_name = ''): AlterInterface
    {
        $table_name = $this->getTable($table_name);
        return $this->getConnector()->reset()->alterTable()->forTable($table_name, $primary_key, $comment, $new_table_name);
    }

    /**
     * @DESC          # 获取前缀
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2021/8/31 20:27
     * 参数区：
     * @return string
     */
    public function getTablePrefix(): string
    {
        $prefix = $this->getConnector()->getConfigProvider()->getPrefix();
        return $prefix ?? '';
    }

    /**
     * @DESC         |方法描述
     *
     * 参数区：
     *
     * @param string $table
     *
     * @return bool
     * @throws Exception
     * @throws \ReflectionException
     * @throws LinkException
     */
    public function tableExist(string $table): bool
    {
        $table = $this->getTable($table);
        try {
            $this->query("DESC {$table}");
            return true;
        } catch (PDOException $exception) {
            return false;
        }
    }

    /**
     * @DESC         |获取表名
     *
     * 参数区：
     *
     * @param string $name
     *
     * @return string
     */
    public function getTable(string $name = ''): string
    {
        if (!str_contains($name, '.')) {
            if (!str_starts_with($name, $this->getTablePrefix())) {
                $name = $this->getTablePrefix() . $name;
            }
        }
        return $name;
    }

    /**
     * @DESC         |删除表
     *
     * 参数区：
     *
     * @param string $tableName
     *
     * @return bool
     */
    public function dropTable(string $tableName): bool
    {
        if (!is_int(strpos($tableName, $this->getTablePrefix()))) {
            $tableName = $this->getTable($tableName);
        }
        try {
            $this->query('DROP TABLE ' . $tableName);
            return true;
        } catch (\Exception $exception) {
            return false;
        }
    }

    /**
     * @DESC          # 方法描述
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2021/8/31 20:56
     * 参数区：
     *
     * @param string $sql
     *
     * @return mixed
     */
    public function query(string $sql): mixed
    {
        return $this->getConnector()->query($sql)->fetch();
    }
}
