<?php

declare(strict_types=1);
/**
 * 文件信息
 * 作者：邹万才
 * 网名：秋风雁飞(Aiweline)
 * 网站：www.aiweline.com/bbs.aiweline.com
 * 工具：PhpStorm
 * 日期：2021/6/15
 * 时间：16:43
 * 描述：此文件源码由Aiweline（秋枫雁飞）开发，请勿随意修改源码！
 */

namespace Weline\Framework\Database;

use Weline\Framework\Database\Connection\Api\ConnectorInterface;
use Weline\Framework\Database\Connection\Api\Sql\QueryInterface;
use Weline\Framework\Database\Connection\Api\Sql\Table\AlterInterface;
use Weline\Framework\Database\DbManager\ConfigProvider;
use Weline\Framework\Database\Exception\LinkException;
use Weline\Framework\Manager\ObjectManager;

class ConnectionFactory
{
    protected ?ConnectorInterface $defaultConnector = null;
    protected ConfigProvider $configProvider;
    protected ?AlterInterface $alter;
    /**
     * @var ConnectorInterface[] $connectors
     */
    protected array $connectors = [];

    /**
     * Connection 初始函数...
     *
     * @param ConfigProvider $configProvider
     *
     * @throws LinkException
     */
    public function __construct(ConfigProvider $configProvider)
    {
        $this->configProvider = $configProvider;
        $this->create();
    }

    /**
     * @DESC         |连接配置
     *
     * 参数区：
     *
     * @return ConfigProvider
     */
    public function getConfigProvider(): ConfigProvider
    {
        return $this->configProvider;
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
        return ['configProvider', 'query'];
    }

    /**
     * @DESC         |唤醒时执行函数
     *
     * 参数区：
     *
     * @throws LinkException
     * @throws \Weline\Framework\App\Exception
     */
    public function __wakeup()
    {
        $this->create();
    }

    /**
     * @DESC          # 获得数据库PDO链接
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2021/8/18 21:10
     * 参数区：
     * @throws LinkException
     */
    public function create(): static
    {
        if (!$this->defaultConnector) {
            $this->defaultConnector = $this->getConnectorAdapter()->create($this->configProvider);
        }
        return $this;
    }

    /**
     * 获取适配器
     *
     * @param string $driver_type
     *
     * @return string
     */
    public function getConnectorAdapter(null|ConfigProvider $configProvider = null): ConnectorInterface
    {
        $configProvider = $configProvider ?: $this->configProvider;
        $driver_type = $configProvider->getDbType();
        $driverClass = "Weline\\Framework\\Database\\Connection\\Adapter\\" . ucfirst($driver_type) . '\\Connector';
        return ObjectManager::make($driverClass, ['configProvider' => $configProvider]);
    }

    public function close(): void
    {
        $this->defaultConnector = null;
    }

    /**
     * @DESC          # 获取连接
     * @return ConnectorInterface
     * @deprecated 函数已准备移除 使用 getConnector 代替
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2021/8/18 21:06
     * 参数区：
     */
    public function getConnection(): ConnectorInterface
    {
        return $this->defaultConnector;
    }

    /**
     * @DESC          # 获取查询类
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2021/8/18 21:07
     * 参数区：
     * @return ConnectorInterface
     */
    public function getConnector(): ConnectorInterface
    {
        if (is_null($this->defaultConnector)) {
            $adapter = $this->getConnectorAdapter();
            $this->connectors['master'] = $adapter;
            $this->defaultConnector = $this->connectors['master'];
        }
        return $this->defaultConnector;
    }

    /**
     * @DESC          # 查询
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2021/9/5 22:40
     * 参数区：
     *
     * @param string $sql
     *
     * @return QueryInterface
     * @throws \ReflectionException
     * @throws \Weline\Framework\App\Exception
     */
    public function query(string $sql): QueryInterface
    {
        # 非写操作，用均衡算法从从库中选择一个
        $write_flags = [
            'insert',
            'update',
            'delete',
            'replace',
            'alter',
            'create',
            'drop',
            'truncate',
            'desc',
            'describe',
            'explain',
            'grant',
            'revoke',
        ];
        $sql_type = strtolower(substr(trim($sql), 0, strpos($sql, ' ')));
        if (!in_array($sql_type, $write_flags)) {
            # 检测从库配置，如果有从库，则从库中查询
            if ($slaves_configs = $this->configProvider->getSalvesConfig()) {
                # 如果有从库直接读取从库，一个请求只能读取一个从库
                # FIXME 均衡算法（先随机选一个）
                $slave_config = $slaves_configs[array_rand($slaves_configs)];
                $config_key = md5($slave_config['host'] . $slave_config['port'] . $slave_config['database']);
                if (!isset($this->connectors[$config_key])) {
                    $this->connectors[$config_key] = $this->getConnectorAdapter($slave_config);
                }
                $this->defaultConnector = $this->connectors[$config_key];
            } else {
                $this->defaultConnector = $this->getConnector();
            }
        }
        if (is_null($this->defaultConnector)) {
            $this->getConnector();
        }
        // 如果是drop开头的语句，则不进行缓存
        //        if (strpos(strtolower($sql), 'drop') === 0) {
        //            p($sql);
        //        }
        //
        //        // 如果是drop开头的语句，则不进行缓存
        //        if (strpos(strtolower($sql), 'm_aiweline_hello_world')) {
        //            p($sql);
        //        }

        return $this->defaultConnector->query($sql);
    }

}
