<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Database;

use Weline\Framework\Database\DbManager\ConfigProvider;
use Weline\Framework\Database\Exception\DbException;
use Weline\Framework\Database\Exception\LinkException;
use Weline\Framework\Manager\ObjectManager;

/**
 * 文件信息
 * DESC:   | 数据库管理
 * 作者：   秋枫雁飞
 * 日期：   2020/7/2
 * 时间：   1:24
 * 网站：   https://bbs.aiweline.com
 * Email：  aiweline@qq.com
 */
class DbManager
{
    protected ?ConnectionFactory $defaultConnectionFactory = null;
//    protected \WeakMap $connections;
    protected array $connections = [];
    protected array $slaves_config = [];
    protected ConfigProvider $configProvider;

    public function __construct(ConfigProvider $configProvider)
    {
        $this->configProvider = $configProvider;
    }

    public function __init()
    {
        $this->create();
    }

    /**
     * @DESC         |休眠时执行函数： 保存配置信息，以及模型数据
     *
     * 参数区：
     *
     * @return string[]
     */
    public function __sleep()
    {
        return ['configProvider', 'connections'];
    }

    /**
     * @DESC         |设置数据库配置
     *
     * 参数区：
     *
     * @param ConfigProvider $configProvider
     *
     * @return $this
     */
    public function setConfig(ConfigProvider $configProvider): static
    {
        $this->configProvider = $configProvider;
        return $this;
    }

    /**
     * @DESC         |设置数据库配置
     *
     * 参数区：
     *
     * @param ConfigProvider $slave_config
     *
     * @return $this
     */
    public function addSlaveConfig(ConfigProvider $slave_config): static
    {
        $this->configProvider->addSlavesConfig($slave_config);
        return $this;
    }

    /**
     * @DESC         |数据库配置
     *
     * 参数区：
     *
     * @return ConfigProvider
     */
    public function getConfig(): ConfigProvider
    {
        return $this->configProvider;
    }

    /**
     * @DESC         |创建链接资源
     *
     * 兼并新链接
     *
     * 参数区：
     *
     * @param string              $connection_name 链接名称
     * @param ConfigProvider|null $configProvider  链接资源配置
     *
     * @return ConnectionFactory
     * @throws \ReflectionException
     * @throws LinkException|\Weline\Framework\App\Exception
     */
    public function create(string $connection_name = 'default', ConfigProvider $configProvider = null): ConnectionFactory
    {
        $connection = $this->getConnection($connection_name);
        // 如果不更新连接配置，且已经存在连接就直接读取
        if (empty($configProvider) && $connection) {
            return $connection;
        }
        // 存在连接配置则
        if ($configProvider && $connection) {
            // 如果更新连接配置，但是配置内容一致，且存在使用此配置存在的连接则直接返回
            if ($connection->getConfigProvider()->getData() == $configProvider->getData()) {
                return $connection;
            } else {
                $connection = new ConnectionFactory($configProvider);
            }
        } else {
            if ($configProvider && empty($connection)) {
                $connection = new ConnectionFactory($configProvider);
            } else {
                $connection = new ConnectionFactory($this->configProvider);
            }
        }
        $this->connections[$connection_name] = $connection;
//        $this->connections->offsetSet($connection, $connection_name);
        if ('default' === $connection_name) {
            $this->defaultConnectionFactory = $connection;
        }
        return $connection;
    }

    /**
     * @DESC         |获取连接
     *
     * 参数区：
     *
     * @param string $connection_name
     *
     * @return ConnectionFactory|null
     * @throws LinkException
     */
    public function getConnection(string $connection_name = 'default'): ?ConnectionFactory
    {
        if ('default' === $connection_name) {
            return $this->defaultConnectionFactory;
        }
        /**@var ConnectionFactory $connection */
        /*foreach ($this->connections->getIterator() as $connection => $connection_name_value) {
            if ($connection_name === $connection_name_value) {
                return $connection;
            }
        }*/
        return $this->connections[$connection_name] ?? null;
    }
}
