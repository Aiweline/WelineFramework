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

use Weline\Framework\App\Exception;
use Weline\Framework\Database\Connection\Api\ConnectorInterface;
use Weline\Framework\Database\Connection\Api\Sql\QueryInterface;
use Weline\Framework\Database\Connection\Api\Sql\Table\AlterInterface;
use Weline\Framework\Database\DbManager\ConfigProvider;
use Weline\Framework\Database\Exception\LinkException;
use Weline\Framework\Database\Service\DriverRegistry;
use Weline\Framework\Manager\ObjectManager;

class ConnectionFactory
{
    /**
     * @var array<string, array{defaultConnector: ?ConnectorInterface, configProvider: ConfigProvider, connectors: array}> 静态存储连接工厂实例
     */
    private static array $instances = [];

    /**
     * 获取连接工厂实例的键名
     */
    private static function getInstanceKey(ConfigProvider $configProvider): string
    {
        return md5(serialize($configProvider->getData()));
    }

    /**
     * 获取或创建连接工厂实例
     *
     * @param ConfigProvider $configProvider
     * @return static
     * @throws LinkException
     */
    public static function getInstance(ConfigProvider $configProvider): static
    {
        $key = self::getInstanceKey($configProvider);
        if (!isset(self::$instances[$key])) {
            self::$instances[$key] = [
                'defaultConnector' => null,
                'configProvider' => $configProvider,
                'connectors' => [],
            ];
            // 延迟创建连接：不再在 getInstance() 时立即创建连接器
            // 连接器会在真正需要时（调用 getConnector()）才创建
            // self::create($configProvider);
        }
        return new static($key);
    }

    private string $instanceKey;

    /**
     * Connection 初始函数（私有，只能通过 getInstance 创建）
     *
     * @param string $instanceKey
     */
    private function __construct(string $instanceKey)
    {
        $this->instanceKey = $instanceKey;
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
        return self::$instances[$this->instanceKey]['configProvider'];
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
    public static function create(ConfigProvider $configProvider): void
    {
        $key = self::getInstanceKey($configProvider);
        if (!isset(self::$instances[$key])) {
            self::$instances[$key] = [
                'defaultConnector' => null,
                'configProvider' => $configProvider,
                'connectors' => [],
            ];
        }
        $instance = &self::$instances[$key];
        if (!$instance['defaultConnector']) {
            $factory = new static($key);
            $instance['defaultConnector'] = $factory->getConnectorAdapter()->create($configProvider);
        }
    }

    /**
     * 获取适配器
     *
     * @param ConfigProvider|null $configProvider
     * @return ConnectorInterface
     * @throws \ReflectionException
     * @throws Exception
     */
    public function getConnectorAdapter(null|ConfigProvider $configProvider = null): ConnectorInterface
    {
        $configProvider = $configProvider ?: $this->getConfigProvider();
        $driver_type = $configProvider->getDbType();
        
        // 优先从驱动注册表加载
        try {
            $driverRegistry = ObjectManager::getInstance(DriverRegistry::class);
            $driverClass = $driverRegistry->getDriverClass($driver_type);
            
            if ($driverClass && class_exists($driverClass, false)) {
                try {
                    return ObjectManager::make($driverClass, ['configProvider' => $configProvider]);
                } catch (\Throwable $e) {
                    // 如果实例化失败，回退到原有方式（静默处理）
                }
            }
        } catch (\Throwable $e) {
            // 如果驱动注册表加载失败，静默回退到原有方式
            // 不记录错误日志，避免在正常回退时产生噪音
        }
        
        // 回退到原有硬编码方式（向后兼容）
        $driverClass = "Weline\\Framework\\Database\\Connection\\Adapter\\" . ucfirst($driver_type) . '\\Connector';
        if (!class_exists($driverClass)) {
            throw new Exception(__("数据库驱动 %{1} 不存在", $driverClass));
        }
        return ObjectManager::make($driverClass, ['configProvider' => $configProvider]);
    }

    public function close(): void
    {
        if (isset(self::$instances[$this->instanceKey])) {
            self::$instances[$this->instanceKey]['defaultConnector'] = null;
        }
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
        return self::$instances[$this->instanceKey]['defaultConnector'];
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
        $instance = &self::$instances[$this->instanceKey];
        
        // 确保 connectors 数组已初始化
        if (!isset($instance['connectors'])) {
            $instance['connectors'] = [];
        }
        
        if (is_null($instance['defaultConnector'])) {
            $adapter = $this->getConnectorAdapter();
            
            // 确保适配器不为空
            if (is_null($adapter)) {
                throw new LinkException(__('无法创建数据库连接适配器'));
            }
            
            // 确保连接器已初始化：调用 create() 方法初始化连接
            $adapter->create();
            
            // 将适配器存储到 connectors 数组中
            $instance['connectors']['master'] = $adapter;
            $instance['defaultConnector'] = $instance['connectors']['master'];
        }
        
        // 确保返回的连接器不为空
        if (is_null($instance['defaultConnector'])) {
            throw new LinkException(__('数据库连接器未初始化'));
        }
        
        return $instance['defaultConnector'];
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
     */
    public function query(string $sql): QueryInterface
    {
        $instance = &self::$instances[$this->instanceKey];
        $configProvider = $instance['configProvider'];
        
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
            if ($slaves_configs = $configProvider->getSalvesConfig()) {
                # 如果有从库直接读取从库，一个请求只能读取一个从库
                # FIXME 均衡算法（先随机选一个）
                $slave_config = $slaves_configs[array_rand($slaves_configs)];
                $config_key = md5($slave_config['host'] . $slave_config['port'] . $slave_config['database']);
                if (!isset($instance['connectors'][$config_key])) {
                    $instance['connectors'][$config_key] = $this->getConnectorAdapter($slave_config);
                }
                $instance['defaultConnector'] = $instance['connectors'][$config_key];
            } else {
                $instance['defaultConnector'] = $this->getConnector();
            }
        }
        if (is_null($instance['defaultConnector'])) {
            $this->getConnector();
        }

        return $instance['defaultConnector']->query($sql);
    }

    public function getQuery(): QueryInterface
    {
        return self::$instances[$this->instanceKey]['defaultConnector']->getQuery();
    }

}
