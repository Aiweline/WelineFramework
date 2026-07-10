<?php

declare(strict_types=1);

namespace Weline\Framework\Database\Connection\Pool;

use PDO;
use Weline\Framework\Runtime\SchedulerSystem;
use Weline\Framework\Database\DbManager\ConfigProviderInterface;

/**
 * PDO 连接池管理器
 * 
 * @package Weline\Framework\Database\Connection\Pool
 */
class ConnectionPool
{
    /** 空闲超过此秒数才在取出时做 SELECT 1 验证，减少频繁验证开销 */
    private const IDLE_VALIDATE_SECONDS = 30;

    /** 池满时重试次数，每次间隔 POOL_FULL_WAIT_US 微秒 */
    private const POOL_FULL_RETRIES = 3;
    private const POOL_FULL_WAIT_US = 50_000;

    /**
     * 池中队列元素：PDO 或 array{connection: PDO, last_used: float}（新格式带 last_used）
     * @var array<string, array{pool: \SplQueue, in_use: array, max_size: int, current_size: int}> 连接池存储
     */
    private static array $pools = [];

    /** @var array<int, true> PDO object ids known to be unusable. */
    private static array $unhealthyConnections = [];

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
            'path' => method_exists($configProvider, 'getData') ? (string)$configProvider->getData('path') : '',
            'username' => $configProvider->getUsername(),
        ]));
    }

    public static function isDisconnectException(\Throwable $exception): bool
    {
        $message = \strtolower((string) $exception->getMessage());

        return \str_contains($message, 'no connection to the server')
            || \str_contains($message, 'server closed the connection')
            || \str_contains($message, 'terminating connection')
            || \str_contains($message, 'ssl connection has been closed')
            || \str_contains($message, 'connection not open')
            || \str_contains($message, 'lost connection')
            || \str_contains($message, 'could not connect to server')
            || \str_contains($message, 'connection refused')
            || \str_contains($message, 'broken pipe')
            || \str_contains($message, 'connection reset by peer');
    }

    public static function markConnectionUnhealthy(PDO $connection): void
    {
        self::$unhealthyConnections[\spl_object_id($connection)] = true;
    }

    public static function isConnectionMarkedUnhealthy(PDO $connection): bool
    {
        return isset(self::$unhealthyConnections[\spl_object_id($connection)]);
    }

    public static function discardConnection(PDO $connection, ConfigProviderInterface $configProvider): void
    {
        $poolKey = self::getPoolKey($configProvider);
        $connectionId = \spl_object_id($connection);
        unset(self::$unhealthyConnections[$connectionId]);

        if (!isset(self::$pools[$poolKey])) {
            return;
        }

        $pool = &self::$pools[$poolKey];
        $removed = false;
        if (isset($pool['in_use'][$connectionId])) {
            unset($pool['in_use'][$connectionId]);
            $removed = true;
        }

        if (!$pool['pool']->isEmpty()) {
            $queue = new \SplQueue();
            while (!$pool['pool']->isEmpty()) {
                $item = $pool['pool']->dequeue();
                $itemConnection = \is_array($item) ? ($item['connection'] ?? null) : $item;
                if ($itemConnection instanceof PDO && \spl_object_id($itemConnection) === $connectionId) {
                    $removed = true;
                    continue;
                }
                $queue->enqueue($item);
            }
            $pool['pool'] = $queue;
        }

        if ($removed) {
            $pool['current_size'] = \max(0, (int) $pool['current_size'] - 1);
        }
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

        // 如果池中有可用连接，取出并视情况验证
        if (!$pool['pool']->isEmpty()) {
            $item = $pool['pool']->dequeue();
            $connection = is_array($item) ? $item['connection'] : $item;
            $lastUsed = is_array($item) ? (float)($item['last_used'] ?? 0) : 0.0;
            $connectionValid = false;
            // 仅当空闲超过阈值时做 SELECT 1 验证，减少高频验证
            if (!$connection instanceof PDO) {
                $pool['current_size'] = \max(0, (int) $pool['current_size'] - 1);
            } elseif (self::isConnectionMarkedUnhealthy($connection)) {
                $pool['current_size'] = \max(0, (int) $pool['current_size'] - 1);
                unset(self::$unhealthyConnections[\spl_object_id($connection)]);
            } elseif ((microtime(true) - $lastUsed) <= self::IDLE_VALIDATE_SECONDS) {
                $connectionValid = true;
            } else {
                try {
                    $connection->query('SELECT 1');
                    $connectionValid = true;
                } catch (\Throwable $e) {
                    $pool['current_size'] = \max(0, (int) $pool['current_size'] - 1);
                    unset(self::$unhealthyConnections[\spl_object_id($connection)]);
                }
            }
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

        // 池已满：短暂等待重试，避免无限创建临时连接
        for ($i = 0; $i < self::POOL_FULL_RETRIES; $i++) {
            SchedulerSystem::usleep(self::POOL_FULL_WAIT_US);
            if (!$pool['pool']->isEmpty()) {
                $item = $pool['pool']->dequeue();
                $connection = is_array($item) ? $item['connection'] : $item;
                $lastUsed = is_array($item) ? (float)($item['last_used'] ?? 0) : 0.0;
                if (!$connection instanceof PDO) {
                    $pool['current_size'] = \max(0, (int) $pool['current_size'] - 1);
                    $connectionValid = false;
                } elseif (self::isConnectionMarkedUnhealthy($connection)) {
                    $pool['current_size'] = \max(0, (int) $pool['current_size'] - 1);
                    unset(self::$unhealthyConnections[\spl_object_id($connection)]);
                    $connectionValid = false;
                } elseif ((microtime(true) - $lastUsed) <= self::IDLE_VALIDATE_SECONDS) {
                    $connectionValid = true;
                } else {
                    try {
                        $connection->query('SELECT 1');
                        $connectionValid = true;
                    } catch (\Throwable $e) {
                        $pool['current_size'] = \max(0, (int) $pool['current_size'] - 1);
                        unset(self::$unhealthyConnections[\spl_object_id($connection)]);
                        $connectionValid = false;
                    }
                }
                if ($connectionValid && $connection instanceof PDO) {
                    $connectionId = spl_object_id($connection);
                    $pool['in_use'][$connectionId] = $connection;
                    return $connection;
                }
            }
        }
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

        // 如果连接在使用列表中，归还到池中（带 last_used 供取出时按需验证）
        if (isset($pool['in_use'][$connectionId])) {
            unset($pool['in_use'][$connectionId]);
            if (isset(self::$unhealthyConnections[$connectionId])) {
                unset(self::$unhealthyConnections[$connectionId]);
                $pool['current_size'] = \max(0, (int) $pool['current_size'] - 1);
                return;
            }
            $pool['pool']->enqueue(['connection' => $connection, 'last_used' => microtime(true)]);
            
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
                    $item = $pool['pool']->dequeue();
                    $connection = is_array($item) ? $item['connection'] : $item;
                    $connection = null;
                }
                foreach ($pool['in_use'] as $connection) {
                    $connection = null; // 释放连接
                }
            }
            self::$pools = [];
            self::$unhealthyConnections = [];
        } else {
            // 关闭指定配置的连接池
            $poolKey = self::getPoolKey($configProvider);
            if (isset(self::$pools[$poolKey])) {
                $pool = self::$pools[$poolKey];
                while (!$pool['pool']->isEmpty()) {
                    $item = $pool['pool']->dequeue();
                    $connection = is_array($item) ? $item['connection'] : $item;
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
                $connection->query('SELECT 1');
                $pool['pool']->enqueue(['connection' => $connection, 'last_used' => microtime(true)]);
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
     * 请求结束时清理连接状态
     * 
     * FPM 下进程结束时 PHP 自动关闭连接，未提交的事务由数据库自动回滚。
     * WLS 下连接跨请求复用，必须手动清理：
     * 1. 回滚未提交的事务（防止事务泄漏到下一个请求）
     * 2. 归还所有"正在使用"的连接到池中（防止连接泄漏）
     */
    public static function requestEndCleanup(): void
    {
        foreach (self::$pools as $poolKey => &$pool) {
            foreach ($pool['in_use'] as $connectionId => $connection) {
                $reusable = true;
                try {
                    // 回滚未提交的事务
                    if ($connection->inTransaction()) {
                        $connection->rollBack();
                    }
                } catch (\Throwable $e) {
                    $reusable = false;
                    self::markConnectionUnhealthy($connection);
                    // 连接可能已断开，忽略回滚错误
                }
                unset($pool['in_use'][$connectionId]);
                if ($reusable && !isset(self::$unhealthyConnections[$connectionId])) {
                    $pool['pool']->enqueue(['connection' => $connection, 'last_used' => microtime(true)]);
                    continue;
                }
                unset(self::$unhealthyConnections[$connectionId]);
                $pool['current_size'] = \max(0, (int) $pool['current_size'] - 1);
            }
        }
        unset($pool);
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
