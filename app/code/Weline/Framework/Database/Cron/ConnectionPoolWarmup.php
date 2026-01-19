<?php

declare(strict_types=1);

namespace Weline\Framework\Database\Cron;

use Weline\Cron\CronTaskInterface;
use Weline\Framework\App\Env;
use Weline\Framework\Database\Connection\Pool\ConnectionPool;
use Weline\Framework\Database\DbManager\ConfigProvider;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Output\Cli\Printing;

/**
 * 数据库连接池预热任务
 * 
 * 定时预热数据库连接池，确保连接池中有足够的可用连接
 * 如果池子不够就新建连接，直到达到配置的最大连接数
 * 
 * @package Weline\Framework\Database\Cron
 */
class ConnectionPoolWarmup implements CronTaskInterface
{
    private Printing $printing;

    public function __construct(Printing $printing)
    {
        $this->printing = $printing;
    }

    /**
     * @inheritDoc
     */
    public function name(): string
    {
        return __('数据库连接池预热任务');
    }

    /**
     * @inheritDoc
     */
    public function execute_name(): string
    {
        return 'connection_pool_warmup';
    }

    /**
     * @inheritDoc
     */
    public function tip(): string
    {
        $tip = '定时预热数据库连接池，确保连接池中有足够的可用连接。' . PHP_EOL;
        $tip .= '如果池子不够就新建连接，直到达到配置的最大连接数（默认10个）。' . PHP_EOL;
        $tip .= '执行频率：每5分钟执行一次。';
        return __($tip);
    }

    /**
     * @inheritDoc
     */
    public function cron_time(): string
    {
        // 每5分钟执行一次
        return '*/5 * * * *';
    }

    /**
     * @inheritDoc
     */
    public function execute(): string
    {
        $results = [];
        $totalCreated = 0;
        $totalErrors = 0;

        try {
            // 获取数据库配置
            $dbConfig = Env::getInstance()->getDbConfig();
            if (empty($dbConfig)) {
                return __('数据库配置为空，跳过预热');
            }

            // 预热主库连接池
            $masterConfig = new ConfigProvider($dbConfig['master'] ?? $dbConfig);
            $masterResult = $this->warmupConfig($masterConfig);
            $results['master'] = $masterResult;
            $totalCreated += $masterResult['created'];
            $totalErrors += $masterResult['errors'];

            // 预热从库连接池（如果有）
            if (isset($dbConfig['slaves']) && is_array($dbConfig['slaves'])) {
                foreach ($dbConfig['slaves'] as $index => $slaveConfig) {
                    $slaveConfigProvider = new ConfigProvider($slaveConfig);
                    $slaveResult = $this->warmupConfig($slaveConfigProvider);
                    $results['slave_' . $index] = $slaveResult;
                    $totalCreated += $slaveResult['created'];
                    $totalErrors += $slaveResult['errors'];
                }
            }

            // 构建结果消息
            $message = __("连接池预热完成。");
            $message .= " " . __('创建连接: %{1} 个', $totalCreated);
            if ($totalErrors > 0) {
                $message .= "，" . __('错误: %{1} 个', $totalErrors);
            }
            $message .= "\n" . __('详细统计:') . "\n";
            foreach ($results as $key => $result) {
                $message .= "  {$key}: " . __('可用=%{1}, 使用中=%{2}, 总数=%{3}/%{4}, 新建=%{5}, 错误=%{6}', [
                    $result['current_available'],
                    $result['current_in_use'],
                    $result['current_total'],
                    $result['target_size'],
                    $result['created'],
                    $result['errors']
                ]) . "\n";
            }

            return $message;
        } catch (\Throwable $e) {
            return __("连接池预热失败: %{1}", $e->getMessage());
        }
    }

    /**
     * 预热单个配置的连接池
     * 
     * @param ConfigProvider $configProvider
     * @return array
     */
    private function warmupConfig(ConfigProvider $configProvider): array
    {
        $dbType = $configProvider->getDbType();
        
        // 直接创建 PDO 连接，避免通过 Connector（防止循环依赖）
        $createConnection = function () use ($dbType, $configProvider) {
            if (!in_array($dbType, \PDO::getAvailableDrivers())) {
                throw new \Exception(__("数据库驱动 %{1} 不存在", $dbType));
            }
            
            // 根据数据库类型构建 DSN
            if ($dbType === 'pgsql') {
                $dsn = "pgsql:host={$configProvider->getHostName()};port={$configProvider->getHostPort()};dbname={$configProvider->getDatabase()}";
                if ($configProvider->getCharset()) {
                    $dsn .= ";options='--client_encoding={$configProvider->getCharset()}'";
                }
            } elseif ($dbType === 'sqlite') {
                $dsn = "{$dbType}:{$configProvider->getData('path')}";
            } else {
                // MySQL 和其他数据库
                $dsn = "{$dbType}:host={$configProvider->getHostName()}:{$configProvider->getHostPort()};dbname={$configProvider->getDatabase()};charset={$configProvider->getCharset()};collate={$configProvider->getCollate()}";
            }
            
            try {
                $connection = new \PDO(
                    $dsn,
                    $configProvider->getUsername(),
                    $configProvider->getPassword(),
                    $configProvider->getOptions()
                );
                
                // 执行预 SQL（如果有）
                if ($configProvider->getPreSql()) {
                    $connection->exec($configProvider->getPreSql());
                }
                
                // 设置字符集（PostgreSQL）
                if ($dbType === 'pgsql' && $configProvider->getCharset()) {
                    $connection->exec("SET NAMES '{$configProvider->getCharset()}'");
                }
                
                // 设置错误模式
                $connection->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
                
                return $connection;
            } catch (\PDOException $e) {
                throw new \Exception(__("创建数据库连接失败: %{1}", $e->getMessage()));
            }
        };

        // 预热连接池
        return ConnectionPool::warmup($configProvider, $createConnection);
    }

    /**
     * @inheritDoc
     */
    public function unlock_timeout(int $minute = 30): int
    {
        // 预热任务应该在5分钟内完成
        return 5;
    }
}
