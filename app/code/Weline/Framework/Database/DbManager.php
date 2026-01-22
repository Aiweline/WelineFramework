<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Database;

use Weline\Framework\App\Debug;
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
        // 延迟初始化：不在这里创建连接，而是在真正需要时再创建
        // 这样可以避免每次实例化 DbManager 时都创建数据库连接
        // $this->create();
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
     * @DESC         |反序列化时执行函数： 确保配置提供者不为空
     *
     * 参数区：
     *
     * @return void
     * @throws \Exception
     */
    public function __wakeup(): void
    {
        // 如果反序列化后 configProvider 为 null，尝试重新创建
        if (!isset($this->configProvider) || $this->configProvider === null) {
            try {
                $this->configProvider = new ConfigProvider();
            } catch (\Throwable $e) {
                throw new \Exception(__('数据库配置提供者无法初始化：%{1}', [$e->getMessage()]));
            }
        }
        // 重置连接，因为连接对象可能无法正确序列化
        $this->defaultConnectionFactory = null;
        $this->connections = [];
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
     * @param string $connection_name 链接名称
     * @param ConfigProvider|null $configProvider 链接资源配置
     *
     * @return ConnectionFactory
     * @throws LinkException
     */
    public function create(string $connection_name = 'default', null|ConfigProvider $configProvider = null): ConnectionFactory
    {
        $connection = $this->getConnection($connection_name);
        
        // 确定要使用的配置：如果不提供配置类，使用默认的配置链接
        $targetConfigProvider = $configProvider ?? $this->configProvider;
        
        // 确保配置提供者不为 null
        if ($targetConfigProvider === null) {
            try {
                $targetConfigProvider = new ConfigProvider();
                $this->configProvider = $targetConfigProvider;
            } catch (\Throwable $e) {
                throw new LinkException(__('数据库配置提供者无法初始化：%{1}', [$e->getMessage()]));
            }
        }
        
        // 如果已有连接，检查是否需要更新
        if ($connection) {
            // 如果不更新连接配置，直接返回已有连接
            if (empty($configProvider)) {
                return $connection;
            }
            // 如果提供了新配置，检查配置是否一致
            if ($connection->getConfigProvider()->getData() == $targetConfigProvider->getData()) {
                return $connection;
            }
        }
        
        // 创建新连接（使用目标配置）
        $connection = ConnectionFactory::getInstance($targetConfigProvider);
        
        // 保存连接
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

    /**
     * @DESC         |获取连接器（为了兼容 ConnectionFactory 接口）
     *
     * 参数区：
     *
     * @return \Weline\Framework\Database\Connection\Api\ConnectorInterface
     * @throws LinkException
     */
    public function getConnector(): \Weline\Framework\Database\Connection\Api\ConnectorInterface
    {
        // 确保默认连接已创建
        if (!$this->defaultConnectionFactory) {
            $this->create();
        }
        return $this->defaultConnectionFactory->getConnector();
    }

    /**
     * @DESC         |获取配置提供者（为了兼容 ConnectionFactory 接口）
     *
     * 参数区：
     *
     * @return ConfigProvider
     */
    public function getConfigProvider(): ConfigProvider
    {
        return $this->configProvider;
    }
}
