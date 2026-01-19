<?php

declare(strict_types=1);

namespace Weline\Framework\Database\Connection\Pool;

use PDO;
use Weline\Framework\Database\DbManager\ConfigProviderInterface;

/**
 * PDO 连接池管理器
 * 
 * @package Weline\Framework\Database\Connection\Pool
 */
class ConnectionPool
{
    /**
     * @var array<string, array{pool: \SplQueue, in_use: array, max_size: int, current_size: int}> 连接池存储
     */
    private static array $pools = [];

    /**
     * 获取连接池键名
     */
    private static function getPoolKey(ConfigProviderInterface $configProvider): string
    {
        return md5(serialize([
            'type' => $configProvider->getDbType(),
            'host' => $configProvider->getHostName(),
            'port' => $configProvider->getHostPort(),
            'database' => $configProvider->getDatabase(),
            'username' => $configProvider->getUsername(),
        ]));
    }

    /**
     * 从连接池获取 PDO 连接
     * 
     * @param ConfigProviderInterface $configProvider
     * @param callable $createConnection 创建连接的闭包函数
     * @return PDO
     */
    public static function getConnection(ConfigProviderInterface $configProvider, callable $createConnection): PDO
    {
        $poolKey = self::getPoolKey($configProvider);
        $poolSize = $configProvider->getPoolSize();

        // 初始化连接池
        if (!isset(self::$pools[$poolKey])) {
            self::$pools[$poolKey] = [
                'pool' => new \SplQueue(),
                'in_use' => [],
                'max_size' => $poolSize,
                'current_size' => 0,
            ];
        }

        $pool = &self::$pools[$poolKey];

        // 如果池中有可用连接，直接返回
        if (!$pool['pool']->isEmpty()) {
            $connection = $pool['pool']->dequeue();
            $connectionValid = false;
            // 验证连接是否仍然有效
            try {
                $connection->query('SELECT 1');
                $connectionValid = true;
            } catch (\PDOException $e) {
                // 连接已失效，从池中移除，继续创建新连接
                $pool['current_size']--;
                // 继续执行创建新连接的逻辑
            }
            
            // 如果连接有效，使用它
            if ($connectionValid && $connection instanceof PDO) {
                $connectionId = spl_object_id($connection);
                $pool['in_use'][$connectionId] = $connection;
                return $connection;
            }
        }

        // 如果池未满，创建新连接
        if ($pool['current_size'] < $pool['max_size']) {
            $connection = $createConnection();
            $connectionId = spl_object_id($connection);
            $pool['in_use'][$connectionId] = $connection;
            $pool['current_size']++;
            return $connection;
        }

        // 池已满，等待或创建临时连接（这里简化处理，直接创建临时连接）
        // 在实际生产环境中，可以考虑使用信号量或等待机制
        $connection = $createConnection();
        return $connection;
    }

    /**
     * 归还连接到池中
     * 
     * @param PDO $connection
     * @param ConfigProviderInterface $configProvider
     */
    public static function releaseConnection(PDO $connection, ConfigProviderInterface $configProvider): void
    {
        $poolKey = self::getPoolKey($configProvider);
        
        if (!isset(self::$pools[$poolKey])) {
            return;
        }

        $pool = &self::$pools[$poolKey];
        $connectionId = spl_object_id($connection);

        // 如果连接在使用列表中，归还到池中
        if (isset($pool['in_use'][$connectionId])) {
            unset($pool['in_use'][$connectionId]);
            
            // 优化：跳过连接验证，直接归还到池中（连接验证在获取时进行）
            // 这样可以避免归还时的验证开销，并且如果连接失效，在下次获取时会检测到
            $pool['pool']->enqueue($connection);
            
            // 注释掉归还时的验证，改为在获取时验证
            // // 检查连接是否仍然有效
            // try {
            //     $connection->query('SELECT 1');
            //     $pool['pool']->enqueue($connection);
            //     $released = true;
            // } catch (\PDOException $e) {
            //     // 连接已失效，从池中移除
            //     $pool['current_size']--;
            //     $invalid = true;
            // }
        }
    }

    /**
     * 关闭连接池
     * 
     * @param ConfigProviderInterface|null $configProvider 如果为 null，关闭所有连接池
     */
    public static function closePool(?ConfigProviderInterface $configProvider = null): void
    {
        if ($configProvider === null) {
            // 关闭所有连接池
            foreach (self::$pools as $pool) {
                while (!$pool['pool']->isEmpty()) {
                    $connection = $pool['pool']->dequeue();
                    $connection = null; // 释放连接
                }
                foreach ($pool['in_use'] as $connection) {
                    $connection = null; // 释放连接
                }
            }
            self::$pools = [];
        } else {
            // 关闭指定配置的连接池
            $poolKey = self::getPoolKey($configProvider);
            if (isset(self::$pools[$poolKey])) {
                $pool = self::$pools[$poolKey];
                while (!$pool['pool']->isEmpty()) {
                    $connection = $pool['pool']->dequeue();
                    $connection = null;
                }
                foreach ($pool['in_use'] as $connection) {
                    $connection = null;
                }
                unset(self::$pools[$poolKey]);
            }
        }
    }

    /**
     * 获取连接池统计信息
     * 
     * @param ConfigProviderInterface|null $configProvider
     * @return array
     */
    public static function getPoolStats(?ConfigProviderInterface $configProvider = null): array
    {
        if ($configProvider === null) {
            $stats = [];
            foreach (self::$pools as $poolKey => $pool) {
                $stats[$poolKey] = [
                    'available' => $pool['pool']->count(),
                    'in_use' => count($pool['in_use']),
                    'max_size' => $pool['max_size'],
                    'current_size' => $pool['current_size'],
                ];
            }
            return $stats;
        } else {
            $poolKey = self::getPoolKey($configProvider);
            if (isset(self::$pools[$poolKey])) {
                $pool = self::$pools[$poolKey];
                return [
                    'available' => $pool['pool']->count(),
                    'in_use' => count($pool['in_use']),
                    'max_size' => $pool['max_size'],
                    'current_size' => $pool['current_size'],
                ];
            }
            return [];
        }
    }

    /**
     * 预热连接池
     * 如果池子不够就新建连接，直到达到配置的最大连接数
     * 
     * @param ConfigProviderInterface $configProvider
     * @param callable $createConnection 创建连接的闭包函数
     * @param int|null $targetSize 目标连接数，如果为 null 则使用配置的 pool_size
     * @return array 返回预热结果统计
     */
    public static function warmup(ConfigProviderInterface $configProvider, callable $createConnection, ?int $targetSize = null): array
    {
        $poolKey = self::getPoolKey($configProvider);
        $poolSize = $targetSize ?? $configProvider->getPoolSize();

        // 初始化连接池
        if (!isset(self::$pools[$poolKey])) {
            self::$pools[$poolKey] = [
                'pool' => new \SplQueue(),
                'in_use' => [],
                'max_size' => $poolSize,
                'current_size' => 0,
            ];
        }

        $pool = &self::$pools[$poolKey];
        $created = 0;
        $errors = 0;

        // 计算需要创建的连接数
        $needed = $poolSize - ($pool['pool']->count() + count($pool['in_use']));

        // 如果池子不够，创建新连接
        for ($i = 0; $i < $needed && $pool['current_size'] < $poolSize; $i++) {
            try {
                $connection = $createConnection();
                // 测试连接是否有效
                $connection->query('SELECT 1');
                $pool['pool']->enqueue($connection);
                $pool['current_size']++;
                $created++;
            } catch (\PDOException $e) {
                $errors++;
                // 连接池预热失败，静默处理（错误已计入统计）
            } catch (\Throwable $e) {
                $errors++;
                // 连接池预热异常，静默处理（错误已计入统计）
            }
        }

        return [
            'pool_key' => $poolKey,
            'target_size' => $poolSize,
            'created' => $created,
            'errors' => $errors,
            'current_available' => $pool['pool']->count(),
            'current_in_use' => count($pool['in_use']),
            'current_total' => $pool['current_size'],
        ];
    }

    /**
     * 获取所有连接池的键名（用于预热所有连接池）
     * 
     * @return array<string> 返回所有连接池的配置信息摘要
     */
    public static function getAllPoolKeys(): array
    {
        return array_keys(self::$pools);
    }
}
