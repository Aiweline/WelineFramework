<?php

declare(strict_types=1);
/**
 * 文件信息
 * 作者：邹万才
 * 网名：秋风雁飞(Aiweline)
 * 网站：www.aiweline.com/bbs.aiweline.com
 * 工具：PhpStorm
 * 日期：2021/6/15
 * 时间：16:01
 * 描述：此文件源码由Aiweline（秋枫雁飞）开发，请勿随意修改源码！
 */

namespace Weline\Framework\Database\DbManager;

use Weline\Framework\App\Env;
use Weline\Framework\App\Exception;
use Weline\Framework\DataObject\DataObject;
use Weline\Framework\Output\Cli\Printing;

/**
 * 文件信息
 * DESC:   | 数据库配置者信息
 * 作者：   秋枫雁飞
 * 日期：   2021/6/15
 * 时间：   16:01
 * 网站：   https://bbs.aiweline.com
 * Email：  aiweline@qq.com
 * @DESC    :    此文件源码由Aiweline（秋枫雁飞）开发，请勿随意修改源码！
 * @SuppressWarnings(PHPMD.NumberOfChildren)
 * @since   1.2
 *
 * Class Configurator
 * @package Weline\Framework\Database\DbManager
 */
class ConfigProvider extends DataObject implements ConfigProviderInterface
{
    public string $connection_name = 'default';
    public array $slaves = [];

    /**
     * @throws Exception
     */
    public function __construct($db_conf = [])
    {
        if (empty($db_conf)) {
            $db_conf = $this->getConfig();
        }
        # 检测是否设置了slave从库
        if (isset($db_conf['slaves']) && $slaves = $db_conf['slaves']) {
            $this->addSlavesConfig($slaves);
        }
        # 主数据库
        $master = $db_conf['master'] ?? $db_conf;
        parent::__construct($master);
    }

    /**
     * @DESC         |获取配置信息
     *
     * 参数区：
     *
     * @return array|mixed
     * @throws Exception
     */
    protected function getConfig(): mixed
    {
        $db_conf = Env::getInstance()->getDbConfig();# Env配置对象内存生命周期常驻，无需担心配置多次操作env.php配置文件
        if (empty($db_conf)) {
            $db_conf = Env::getInstance()->reload()->getDbConfig();
            if (empty($db_conf) || !isset($db_conf['master'])) {
                if ('cli' === PHP_SAPI) {
                    (new Printing())->error('请安装系统后操作:bin/w system:install', '系统');

                    throw new Exception('数据库尚未配置，请安装系统后操作:bin/w system:install');
                }

                throw new Exception('数据库尚未配置，请安装系统后操作:bin/w system:install');
            }
        }
        $connection_name = 'default';
        $this->setConnectionName($connection_name);
        return $db_conf;
    }

    /**
     * @DESC          # 添加从库
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2022/5/22 13:17
     * 参数区：
     */
    public function addSlavesConfig($slaves)
    {
        foreach ($slaves as $slave) {
            $this->slaves[] = new ConfigProvider($slave);
        }
    }

    public function getSalvesConfig(): array
    {
        return $this->slaves;
    }

    public function getConnectionName(): string
    {
        return $this->connection_name;
    }

    public function setConnectionName($connection_name): ConfigProviderInterface
    {
        $this->connection_name = $connection_name;
        return $this;
    }

    public function setDbType(string $type): ConfigProviderInterface
    {
        return $this->setData('type', $type);
    }

    public function getDbType(): string
    {
        return $this->getData('type') ?? '';
    }

    public function setHostName(string $hostname): ConfigProviderInterface
    {
        return $this->setData('hostname', $hostname);
    }

    public function getHostName(): string
    {
        return $this->getData('hostname') ?? '';
    }

    public function setDatabase(string $database_name): ConfigProviderInterface
    {
        return $this->setData('database', $database_name);
    }

    public function getDatabase(): string
    {
        return $this->getData('database') ?? '';
    }

    public function setUsername(string $username): ConfigProviderInterface
    {
        return $this->setData('username', $username);
    }

    public function getUsername(): string
    {
        return $this->getData('username') ?? '';
    }

    public function getPreSql(): string
    {
        return $this->getData('pre_sql') ?? '';
    }

    public function setPreSql(string $sql): static
    {
        return $this->setData('pre_sql', $sql);
    }

    public function setPassword(string $password): ConfigProviderInterface
    {
        return $this->setData('password', $password);
    }

    public function getPassword(): string
    {
        return $this->getData('password') ?? "";
    }

    public function setHostPort(string $host_port): ConfigProviderInterface
    {
        return $this->setData('hostport', $host_port);
    }

    public function getHostPort(): int
    {
        return (int)$this->getData('hostport');
    }

    public function setPrefix(string $prefix): ConfigProviderInterface
    {
        return $this->setData('prefix', $prefix);
    }

    public function getPrefix(): string
    {
        return $this->getData('prefix') ?? '';
    }

    public function setCharset(string $charset = 'utf8mb4'): ConfigProviderInterface
    {
        return $this->setData('charset', $charset);
    }

    public function getCharset(): string
    {
        return $this->getData('charset') ?? 'utf8mb4';
    }

    public function setOptions(array $pdo_options = []): ConfigProviderInterface
    {
        return $this->setData('options', $pdo_options);
    }

    public function getOptions(): array
    {
        $defaultOptions = [
            // 持久连接：默认启用，除非主动销毁否则保持连接
            \PDO::ATTR_PERSISTENT => $this->isPersistent(),
            
            // 错误模式：使用异常模式，提高错误处理性能
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            
            // 默认获取模式：使用关联数组，避免数字索引和对象转换开销
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            
            // 自动提交：默认启用，提高事务性能
            \PDO::ATTR_AUTOCOMMIT => true,
        ];
        
        // MySQL 特定优化选项
        if ($this->getDbType() === 'mysql') {
            $defaultOptions[\PDO::MYSQL_ATTR_INIT_COMMAND] = "SET NAMES {$this->getCharset()} COLLATE {$this->getCollate()}";
            
            // 禁用预处理语句模拟：使用原生预处理，提高性能和安全性
            // 注意：某些复杂查询可能需要模拟，可通过配置覆盖
            $defaultOptions[\PDO::ATTR_EMULATE_PREPARES] = $this->getData('emulate_prepares') ?? false;
            
            // 使用缓冲查询：默认启用，提高查询性能
            $defaultOptions[\PDO::MYSQL_ATTR_USE_BUFFERED_QUERY] = $this->getData('use_buffered_query') ?? true;
            
            // MySQL 压缩：如果网络较慢可以启用（默认禁用，因为会增加CPU开销）
            if ($this->getData('compress') ?? false) {
                $defaultOptions[\PDO::MYSQL_ATTR_COMPRESS] = true;
            }
        }
        
        // PostgreSQL 特定优化选项
        if ($this->getDbType() === 'pgsql') {
            // PostgreSQL 预处理语句默认使用原生模式，无需额外配置
            // 可以设置连接超时（秒）
            if ($timeout = $this->getData('timeout')) {
                $defaultOptions[\PDO::ATTR_TIMEOUT] = (int)$timeout;
            }
        }
        
        // SQLite 特定优化选项
        if ($this->getDbType() === 'sqlite') {
            // SQLite 预处理语句默认使用原生模式
            // 可以设置 busy_timeout（毫秒）
            if ($busyTimeout = $this->getData('busy_timeout')) {
                // SQLite 的 busy_timeout 需要通过 PRAGMA 设置，在连接后执行
            }
        }
        
        // 连接超时（通用，单位：秒）
        if ($timeout = $this->getData('timeout')) {
            $defaultOptions[\PDO::ATTR_TIMEOUT] = (int)$timeout;
        }
        
        // 合并用户自定义选项（用户选项优先级更高）
        $userOptions = $this->getData('options') ?? [];
        return array_merge($defaultOptions, $userOptions);
    }

    /**
     * @DESC          # 是否启用持久连接
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2025/1/20
     * 参数区：
     * @return bool
     */
    public function isPersistent(): bool
    {
        return (bool)($this->getData('persistent') ?? true);  // 默认启用持久连接
    }

    /**
     * @DESC          # 设置是否启用持久连接
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2025/1/20
     * 参数区：
     * @param bool $persistent
     * @return ConfigProviderInterface
     */
    public function setPersistent(bool $persistent): ConfigProviderInterface
    {
        return $this->setData('persistent', $persistent);
    }

    /**
     * @DESC          # 默认排序
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2022/4/24 20:12
     * 参数区：
     * @return string
     */
    public function getCollate(): string
    {
        return $this->getData('collate') ?? 'utf8mb4_general_ci';
    }

    /**
     * @DESC          # 默认排序
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2022/4/24 20:12
     * 参数区：
     *
     * @param string $collate
     *
     * @return ConfigProviderInterface
     */
    public function setCollate(string $collate = 'utf8mb4_general_ci'): ConfigProviderInterface
    {
        return $this->setData('collate', $collate);
    }

    /**
     * @DESC          # 获取连接池大小
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2024/12/19
     * 参数区：
     * @return int
     */
    public function getPoolSize(): int
    {
        return (int)($this->getData('pool_size') ?? 10);
    }

    /**
     * @DESC          # 设置连接池大小
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2024/12/19
     * 参数区：
     *
     * @param int $poolSize
     *
     * @return ConfigProviderInterface
     */
    public function setPoolSize(int $poolSize): ConfigProviderInterface
    {
        return $this->setData('pool_size', $poolSize);
    }
}
