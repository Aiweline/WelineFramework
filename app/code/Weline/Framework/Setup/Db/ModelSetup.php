<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Setup\Db;

use Weline\Framework\Database\AbstractModel;
use Weline\Framework\Database\Connection\Adapter\Mysql\Table;
use Weline\Framework\Database\Connection\Adapter\Mysql\Table\Alter;
use Weline\Framework\Database\Connection\Api\Sql\Table\AlterInterface;
use Weline\Framework\Database\Connection\Api\Sql\Table\CreateInterface;
use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Database\Db\DdlFactory;
use Weline\Framework\Output\Cli\Printing;

/**
 * 这个类用来对Model表结构修改，自动读取Model模型的表名和主键
 */
class ModelSetup
{
    protected AbstractModel $model;

    private Table $ddl_table;
    private Printing $printing;

    /**
     * Setup constructor.
     *
     * @param \Weline\Framework\Output\Cli\Printing $printing
     *
     * @throws \ReflectionException
     * @throws \Weline\Framework\App\Exception
     */
    public function __construct(
        Printing   $printing,
    )
    {
        $this->printing  = $printing;
    }

    /**
     * @DESC          # 设置模型
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2021/9/6 22:25
     * 参数区：
     *
     * @param AbstractModel $model
     *
     * @return $this
     */
    public function putModel(AbstractModel $model): ModelSetup
    {
        $this->model = $model;
        return $this;
    }

    /**
     * @DESC         | 创建表
     *
     * 参数区：
     *
     * @param string $comment
     * @param string $table
     *
     * @return CreateInterface
     */
    public function createTable(string $comment = '', string $table = ''): CreateInterface
    {
        return $this->model->getConnection()->getConnector()
            ->createTable()
            ->createTable($table ?: $this->model->getOriginTableName(), $comment);
    }

    /**
     * @DESC         |修改表 两个都留空仅读取表修改类，用此类对表进行其他修改 【提示：如果对表名进行了修改，请紧接着修改Model模型名（或者模型提供对应表名，否则无法找到对应表）】
     *
     * 参数区：
     *
     * @param string $comment 留空不修改表注释
     * @param string $new_table_name 留空不修改表名
     *
     * @return AlterInterface
     */
    public function alterTable(string $comment = '', string $new_table_name = ''): AlterInterface
    {
        return $this->model->getConnection()->getConnector()->alterTable()->forTable($this->model->getTable(), $this->model->_primary_key, $comment, $new_table_name);
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
        $prefix = $this->model->getConnection()->getConfigProvider()->getPrefix();
        return $prefix ?? '';
    }

    /**
     * @DESC         |方法描述
     *
     * 参数区：
     *
     * @param string $table_name
     *
     * @return bool
     */
    public function tableExist(string $table_name = ''): bool
    {
        return $this->model->getConnection()->getConnector()->tableExist($table_name ?: $this->model->getTable());
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
        if (!empty($name) && !is_int(strpos($name, $this->getTablePrefix()))) {
            $name = $this->getTablePrefix() . $name;
        }
        if (empty($name)) {
            $name = $this->model->getTable();
        }
        return $name;
    }

    /**
     * @DESC         |删除表
     *
     * 参数区：
     *
     * @param string $table_name
     *
     * @return bool
     * @throws Null
     */
    public function dropTable(string $table_name = ''): bool
    {
        if (empty($table_name)) {
            $table_name = $this->model->getTable();
        }
        try {
            $this->query('DROP TABLE IF EXISTS ' . $table_name);
            return true;
        } catch (\Exception $exception) {
            throw $exception;
        }
    }

    /**
     * @DESC         |忽略约束删除表
     *
     * 参数区：
     *
     * @param string $table_name
     *
     * @return bool
     * @throws Null
     */
    public function forceDropTable(string $table_name = ''): bool
    {
        if (empty($table_name)) {
            $table_name = $this->model->getTable();
        }
        try {
            $this->query('SET FOREIGN_KEY_CHECKS = 0;DROP TABLE IF EXISTS ' . $table_name . ';SET FOREIGN_KEY_CHECKS = 1;');
            return true;
        } catch (\Exception $exception) {
            throw $exception;
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
     * @throws NUll
     */
    public function query(string $sql): mixed
    {
        return $this->model->getConnection()->query($sql)->fetch();
    }

    public function getVersion(): string
    {
        return $this->model->getConnection()->getVersion();
    }
    
    public function getConnection(): ConnectionFactory
    {
        return $this->model->getConnection();
    }

    /**
     * @DESC          # 读取打印器
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2021/9/8 21:56
     * 参数区：
     * @return Printing
     */
    public function getPrinting(): Printing
    {
        return $this->printing;
    }
}
