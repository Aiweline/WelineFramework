<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Setup\Data;

use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Database\DbManager\ConfigProvider;
use Weline\Framework\Database\DbManagerFactory;
use Weline\Framework\Database\Exception\DbException;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Output\Cli\Printing;
use Weline\Framework\Setup\Data\Context as SetupContext;
use Weline\Framework\Setup\Db\Setup as DbSetup;

class Setup
{
    protected DbSetup $setup_db;

    /**
     * @var Printing
     */
    private Printing $printing;

    private ?ConnectionFactory $master_connection = null;
    private ?ConnectionFactory $connection = null;

    /**
     * Setup 初始函数...
     *
     * @param DbSetup  $setup_db
     * @param Printing $printing
     */
    public function __construct(
        DbSetup  $setup_db,
        Printing $printing
    )
    {
        $this->setup_db = $setup_db;
        $this->printing = $printing;
    }

    /**
     * 设置模组上下文
     * @return void
     */
    public function setModuleContext(SetupContext $context)
    {
        # 解析模组数据库配置文件
        $db_file = $context->getModulePath() . DS . 'etc' . DS . 'db.php';
        if (is_file($db_file)) {
            $db_config = include $db_file;
            # 确认是否有主库配置
            if (!isset($db_config['master'])) {
                throw new DbException(__('请配置主数据库配置信息,或者主数据库配置信息设置错误') . (DEV ? '(' . $db_file . ')' : ''));
            }
            $this->connection = ObjectManager::getInstance(DbManagerFactory::class)->create(
                $context->getModuleName(),
                new ConfigProvider($db_config)
            );
            $this->setup_db->setConnection($this->connection);
        } else {
            if ($this->master_connection) {
                $this->connection = $this->master_connection;
                return $this->master_connection;
            }
            $this->master_connection = ObjectManager::getInstance(DbManagerFactory::class);
            $this->connection        = $this->master_connection;
            $this->setup_db->setConnection($this->connection);
        }
    }

    /**
     * @DESC          # 获取数据库链接
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2021/8/31 20:47
     * 参数区：
     * @return DbSetup
     * @deprecated
     */
    public function getDb(): DbSetup
    {
        return $this->setup_db;
    }

    /**
     * @DESC          # 打印
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2021/8/31 20:48
     * 参数区：
     * @return Printing
     */
    public function getPrinter(): Printing
    {
        return $this->printing;
    }
}
