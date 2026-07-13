<?php

declare(strict_types=1);

namespace Weline\Framework\Database\Connection\Pool;

use PDO;
use Weline\Framework\Database\Exception\ConnectionPoolExhaustedException;
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

    /** 池满时的默认总等待预算；等待期间让出当前 Fiber，禁止创建池外连接。 */
    private const DEFAULT_ACQUIRE_TIMEOUT_SECONDS = 0.15;
    private const POOL_FULL_WAIT_SLICE_US = 1_000;

    /**
     * 池中队列元素：PDO 或 array{connection: PDO, last_used: float}（新格式带 last_used）
     * @var array<string, array{pool: \SplQueue, in_use: array, max_size: int, current_size: int}> 连接池存储
     */
    private static array $pools = [];

    /** @var array<int, true> PDO object ids known to be unusable. */
    private static array $unhealthyConnections = [];

    /**
     * Active ownership tokens, held weakly so an abandoned lease can release
     * itself from its destructor instead of being retained by the pool.
     *
     * @var array<string, array<int, \WeakReference<ConnectionLease>>>
     */
    private static array $activeLeases = [];

    /**
     * One physical PDO per pool and request/Fiber owner. Connector clones only
     * add logical lease tokens; a PDO returns to the idle queue after the last
     * token is released.
     *
     * @var array<string, array<string, array{
     *     connection: PDO,
     *     connection_id: int,
     *     ref_count: int,
     *     lease_tokens: array<int, true>,
     *     acquired_at: float,
     *     owner_type: string
     * }>>
     */
    private static array $ownerConnections = [];

    /**
     * Raw compatibility checkouts are owner-scoped as well. This prevents one
     * request cleanup from returning another suspended Fiber's connection.
     *
     * @var array<string, array<int, string>> pool key => PDO id => owner key
     */
    private static array $rawOwners = [];

    /** @var \WeakMap<\Fiber, string>|null */
    private static ?\WeakMap $fiberOwners = null;

    private static int $ownerSequence = 0;
    private static int $leaseSequence = 0;

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

    /**
     * Acquire one RAII ownership token. New framework code should prefer this
     * API; getConnection()/releaseConnection() remain as the raw compatibility
     * bridge for existing integrations.
     */
    public static function acquire(
        ConfigProviderInterface $configProvider,
        callable $createConnection,
        float $acquireTimeoutSeconds = self::DEFAULT_ACQUIRE_TIMEOUT_SECONDS
    ): ConnectionLease
    {
        $poolKey = self::getPoolKey($configProvider);
        $ownerKey = self::resolveCurrentOwnerKey();

        $existing = self::$ownerConnections[$poolKey][$ownerKey] ?? null;
        if (\is_array($existing)) {
            $connection = $existing['connection'] ?? null;
            $connectionId = $connection instanceof PDO ? \spl_object_id($connection) : 0;
            $isCheckedOut = $connection instanceof PDO
                && isset(self::$pools[$poolKey]['in_use'][$connectionId]);
            if (
                $isCheckedOut
                && !self::isConnectionMarkedUnhealthy($connection)
            ) {
                return self::registerLogicalLease($connection, $poolKey, $ownerKey);
            }

            // Never resurrect a stale/broken owner checkout. Invalidate all of
            // its logical tokens before obtaining a fresh bounded slot.
            self::settleOwnerConnection($poolKey, $ownerKey, true);
        }

        $connection = self::getConnection(
            $configProvider,
            $createConnection,
            $acquireTimeoutSeconds
        );
        $connectionId = \spl_object_id($connection);
        unset(self::$rawOwners[$poolKey][$connectionId]);
        if (empty(self::$rawOwners[$poolKey])) {
            unset(self::$rawOwners[$poolKey]);
        }

        self::$ownerConnections[$poolKey][$ownerKey] = [
            'connection' => $connection,
            'connection_id' => $connectionId,
            'ref_count' => 0,
            'lease_tokens' => [],
            'acquired_at' => \microtime(true),
            'owner_type' => self::ownerType($ownerKey),
        ];

        return self::registerLogicalLease($connection, $poolKey, $ownerKey);
    }

    public static function discardConnection(PDO $connection, ConfigProviderInterface $configProvider): void
    {
        $poolKey = self::getPoolKey($configProvider);
        $connectionId = \spl_object_id($connection);
        $ownerKey = self::findLogicalOwnerByConnection($poolKey, $connectionId);
        if ($ownerKey !== null) {
            self::assertCurrentRawOwner($ownerKey);
            self::settleOwnerConnection($poolKey, $ownerKey, true);
            return;
        }

        self::assertAndForgetRawOwner($poolKey, $connectionId);
        self::discardConnectionByPoolKey($connection, $poolKey);
    }

    /** @internal Called by ConnectionLease after its local state transition. */
    public static function discardLease(ConnectionLease $lease): void
    {
        if (!self::isRegisteredLease($lease)) {
            return;
        }

        // A broken PDO invalidates every logical checkout owned by this
        // request/Fiber. Sibling Connector clones must reacquire as well.
        self::settleOwnerConnection($lease->getPoolKey(), $lease->getOwnerKey(), true);
    }

    private static function discardConnectionByPoolKey(PDO $connection, string $poolKey): void
    {
        $connectionId = \spl_object_id($connection);
        unset(self::$unhealthyConnections[$connectionId]);
        unset(self::$rawOwners[$poolKey][$connectionId]);
        if (empty(self::$rawOwners[$poolKey])) {
            unset(self::$rawOwners[$poolKey]);
        }

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
    public static function getConnection(
        ConfigProviderInterface $configProvider,
        callable $createConnection,
        float $acquireTimeoutSeconds = self::DEFAULT_ACQUIRE_TIMEOUT_SECONDS
    ): PDO
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
                self::trackRawCheckout($poolKey, $connectionId);
                return $connection;
            }
        }

        // 如果池未满，创建新连接
        if ($pool['current_size'] < $pool['max_size']) {
            $connection = $createConnection();
            if (!$connection instanceof PDO) {
                throw new \UnexpectedValueException('Connection factory must return PDO.');
            }
            $connectionId = spl_object_id($connection);
            $pool['in_use'][$connectionId] = $connection;
            $pool['current_size']++;
            self::trackRawCheckout($poolKey, $connectionId);
            return $connection;
        }

        // 池已满：在单一 deadline 内等待归还。达到上限后必须显式失败，
        // 不能创建不受池统计和释放协议管理的临时连接。
        $deadline = \microtime(true) + \max(0.0, $acquireTimeoutSeconds);
        do {
            $remainingUs = (int) \floor(($deadline - \microtime(true)) * 1_000_000);
            if ($remainingUs <= 0) {
                break;
            }
            SchedulerSystem::usleep(\min(self::POOL_FULL_WAIT_SLICE_US, $remainingUs));
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
                    self::trackRawCheckout($poolKey, $connectionId);
                    return $connection;
                }
            }

            // A stale/invalid idle entry may have reduced current_size after
            // the pool was observed full. Refill the bounded slot immediately
            // while retaining the original acquisition deadline.
            if ($pool['current_size'] < $pool['max_size'] && \microtime(true) < $deadline) {
                $connection = $createConnection();
                if (!$connection instanceof PDO) {
                    throw new \UnexpectedValueException('Connection factory must return PDO.');
                }
                if (\microtime(true) >= $deadline) {
                    $connection = null;
                    break;
                }
                $connectionId = \spl_object_id($connection);
                $pool['in_use'][$connectionId] = $connection;
                $pool['current_size']++;
                self::trackRawCheckout($poolKey, $connectionId);
                return $connection;
            }
        } while (\microtime(true) < $deadline);

        $diagnostics = self::poolDiagnostics($poolKey, $pool);
        $elapsedMilliseconds = \round(\max(0.0, $acquireTimeoutSeconds) * 1000, 2);
        throw new ConnectionPoolExhaustedException(
            'Database connection pool exhausted after '
            . $elapsedMilliseconds
            . 'ms (max_size=' . (int)$pool['max_size']
            . ', current_size=' . (int)$pool['current_size']
            . ', in_use=' . \count($pool['in_use'])
            . ', owners=' . (int)$diagnostics['owner_count']
            . ', leases=' . (int)$diagnostics['lease_count'] . ').',
            [
                'reason' => 'pool_deadline_exhausted',
                'pool_id' => \substr($poolKey, 0, 12),
                'timeout_ms' => $elapsedMilliseconds,
                'max_size' => (int)$pool['max_size'],
                'current_size' => (int)$pool['current_size'],
                'available' => $pool['pool']->count(),
                'in_use' => \count($pool['in_use']),
                'owner_count' => $diagnostics['owner_count'],
                'lease_count' => $diagnostics['lease_count'],
                'raw_owner_count' => $diagnostics['raw_owner_count'],
                'owners' => $diagnostics['owners'],
            ]
        );
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
        $connectionId = \spl_object_id($connection);
        $ownerKey = self::findLogicalOwnerByConnection($poolKey, $connectionId);
        if ($ownerKey !== null) {
            self::assertCurrentRawOwner($ownerKey);
            self::settleOwnerConnection($poolKey, $ownerKey, false);
            return;
        }

        self::assertAndForgetRawOwner($poolKey, $connectionId);
        self::releaseConnectionByPoolKey($connection, $poolKey);
    }

    /** @internal Called by ConnectionLease after its local state transition. */
    public static function releaseLease(ConnectionLease $lease): void
    {
        if (!self::isRegisteredLease($lease)) {
            return;
        }

        $poolKey = $lease->getPoolKey();
        $ownerKey = $lease->getOwnerKey();
        $leaseToken = $lease->getLeaseToken();
        unset(self::$activeLeases[$poolKey][$leaseToken]);
        if (empty(self::$activeLeases[$poolKey])) {
            unset(self::$activeLeases[$poolKey]);
        }

        if (!isset(self::$ownerConnections[$poolKey][$ownerKey])) {
            return;
        }

        $owner = &self::$ownerConnections[$poolKey][$ownerKey];
        unset($owner['lease_tokens'][$leaseToken]);
        $owner['ref_count'] = \count($owner['lease_tokens']);
        if ($owner['ref_count'] > 0) {
            unset($owner);
            return;
        }

        $connection = $owner['connection'];
        unset($owner);
        unset(self::$ownerConnections[$poolKey][$ownerKey]);
        if (empty(self::$ownerConnections[$poolKey])) {
            unset(self::$ownerConnections[$poolKey]);
        }
        self::releaseConnectionByPoolKey($connection, $poolKey);
    }

    private static function releaseConnectionByPoolKey(PDO $connection, string $poolKey): void
    {
        if (!isset(self::$pools[$poolKey])) {
            return;
        }

        $pool = &self::$pools[$poolKey];
        $connectionId = spl_object_id($connection);
        unset(self::$rawOwners[$poolKey][$connectionId]);
        if (empty(self::$rawOwners[$poolKey])) {
            unset(self::$rawOwners[$poolKey]);
        }

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

    private static function registerLogicalLease(
        PDO $connection,
        string $poolKey,
        string $ownerKey
    ): ConnectionLease
    {
        if (!isset(self::$ownerConnections[$poolKey][$ownerKey])) {
            throw new \LogicException('Database pool owner checkout is missing.');
        }

        $leaseToken = ++self::$leaseSequence;
        $lease = new ConnectionLease($connection, $poolKey, $ownerKey, $leaseToken);
        self::$ownerConnections[$poolKey][$ownerKey]['lease_tokens'][$leaseToken] = true;
        self::$ownerConnections[$poolKey][$ownerKey]['ref_count'] = \count(
            self::$ownerConnections[$poolKey][$ownerKey]['lease_tokens']
        );
        self::$activeLeases[$poolKey][$leaseToken] = \WeakReference::create($lease);
        return $lease;
    }

    private static function isRegisteredLease(ConnectionLease $lease): bool
    {
        $poolKey = $lease->getPoolKey();
        $leaseToken = $lease->getLeaseToken();
        $reference = self::$activeLeases[$poolKey][$leaseToken] ?? null;
        $registered = $reference instanceof \WeakReference ? $reference->get() : null;
        if ($registered !== $lease) {
            return false;
        }

        $owner = self::$ownerConnections[$poolKey][$lease->getOwnerKey()] ?? null;
        return \is_array($owner)
            && isset($owner['lease_tokens'][$leaseToken])
            && ($owner['connection'] ?? null) === $lease->getConnection();
    }

    private static function settleOwnerConnection(
        string $poolKey,
        string $ownerKey,
        bool $discarded
    ): void
    {
        $owner = self::$ownerConnections[$poolKey][$ownerKey] ?? null;
        if (!\is_array($owner)) {
            return;
        }
        unset(self::$ownerConnections[$poolKey][$ownerKey]);
        if (empty(self::$ownerConnections[$poolKey])) {
            unset(self::$ownerConnections[$poolKey]);
        }

        foreach (\array_keys($owner['lease_tokens']) as $leaseToken) {
            $reference = self::$activeLeases[$poolKey][$leaseToken] ?? null;
            unset(self::$activeLeases[$poolKey][$leaseToken]);
            $lease = $reference instanceof \WeakReference ? $reference->get() : null;
            if ($lease instanceof ConnectionLease) {
                $lease->finalizeFromPool($discarded);
            }
        }
        if (empty(self::$activeLeases[$poolKey])) {
            unset(self::$activeLeases[$poolKey]);
        }

        $connection = $owner['connection'];
        if ($discarded) {
            self::discardConnectionByPoolKey($connection, $poolKey);
            return;
        }
        self::releaseConnectionByPoolKey($connection, $poolKey);
    }

    private static function invalidatePoolLeases(string $poolKey): void
    {
        foreach (self::$activeLeases[$poolKey] ?? [] as $reference) {
            $lease = $reference instanceof \WeakReference ? $reference->get() : null;
            if ($lease instanceof ConnectionLease) {
                $lease->finalizeFromPool(true);
            }
        }
        unset(self::$activeLeases[$poolKey]);
        unset(self::$ownerConnections[$poolKey], self::$rawOwners[$poolKey]);
    }

    private static function findLogicalOwnerByConnection(string $poolKey, int $connectionId): ?string
    {
        foreach (self::$ownerConnections[$poolKey] ?? [] as $ownerKey => $owner) {
            if ((int)($owner['connection_id'] ?? 0) === $connectionId) {
                return $ownerKey;
            }
        }
        return null;
    }

    private static function trackRawCheckout(string $poolKey, int $connectionId): void
    {
        self::$rawOwners[$poolKey][$connectionId] = self::resolveCurrentOwnerKey();
    }

    private static function assertAndForgetRawOwner(string $poolKey, int $connectionId): void
    {
        $ownerKey = self::$rawOwners[$poolKey][$connectionId] ?? null;
        if ($ownerKey === null) {
            return;
        }

        self::assertCurrentRawOwner($ownerKey);
        unset(self::$rawOwners[$poolKey][$connectionId]);
        if (empty(self::$rawOwners[$poolKey])) {
            unset(self::$rawOwners[$poolKey]);
        }
    }

    private static function assertCurrentRawOwner(string $expectedOwnerKey): void
    {
        if (self::resolveCurrentOwnerKey() !== $expectedOwnerKey) {
            throw new \LogicException(
                'A raw database connection cannot be returned by another request or Fiber owner.'
            );
        }
    }

    private static function resolveCurrentOwnerKey(): string
    {
        $fiber = \Fiber::getCurrent();
        if ($fiber instanceof \Fiber) {
            self::$fiberOwners ??= new \WeakMap();
            if (!isset(self::$fiberOwners[$fiber])) {
                self::$fiberOwners[$fiber] = 'fiber:' . (\getmypid() ?: 0) . ':' . (++self::$ownerSequence);
            }
            return self::$fiberOwners[$fiber];
        }

        // Non-Fiber runtimes execute one request at a time. A process owner is
        // stable across bootstrap Context enter/leave transitions and is reset
        // by normal PHP request teardown or requestEndCleanup().
        return 'process:' . (\getmypid() ?: 0);
    }

    private static function ownerType(string $ownerKey): string
    {
        $separator = \strpos($ownerKey, ':');
        return $separator === false ? 'unknown' : \substr($ownerKey, 0, $separator);
    }

    /**
     * 关闭连接池
     * 
     * @param ConfigProviderInterface|null $configProvider 如果为 null，关闭所有连接池
     */
    public static function closePool(?ConfigProviderInterface $configProvider = null): void
    {
        if ($configProvider === null) {
            foreach (\array_keys(self::$activeLeases) as $poolKey) {
                self::invalidatePoolLeases($poolKey);
            }
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
            self::$activeLeases = [];
            self::$ownerConnections = [];
            self::$rawOwners = [];
        } else {
            // 关闭指定配置的连接池
            $poolKey = self::getPoolKey($configProvider);
            self::invalidatePoolLeases($poolKey);
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
    public static function getPoolStats(
        ?ConfigProviderInterface $configProvider = null,
        bool $includeOwners = false
    ): array
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
                if ($includeOwners) {
                    $stats[$poolKey] += self::poolDiagnostics($poolKey, $pool);
                }
            }
            return $stats;
        } else {
            $poolKey = self::getPoolKey($configProvider);
            if (isset(self::$pools[$poolKey])) {
                $pool = self::$pools[$poolKey];
                $stats = [
                    'available' => $pool['pool']->count(),
                    'in_use' => count($pool['in_use']),
                    'max_size' => $pool['max_size'],
                    'current_size' => $pool['current_size'],
                ];
                if ($includeOwners) {
                    $stats += self::poolDiagnostics($poolKey, $pool);
                }
                return $stats;
            }
            return [];
        }
    }

    /**
     * @param array{pool: \SplQueue, in_use: array, max_size: int, current_size: int} $pool
     * @return array{
     *     owner_count: int,
     *     lease_count: int,
     *     raw_owner_count: int,
     *     owners: list<array{owner_id: string, owner_type: string, connection_id: int, leases: int, held_ms: float}>
     * }
     */
    private static function poolDiagnostics(string $poolKey, array $pool): array
    {
        unset($pool);
        $owners = [];
        $leaseCount = 0;
        $now = \microtime(true);
        foreach (self::$ownerConnections[$poolKey] ?? [] as $ownerKey => $owner) {
            $leases = \count($owner['lease_tokens'] ?? []);
            $leaseCount += $leases;
            $owners[] = [
                'owner_id' => \substr(\hash('sha256', $ownerKey), 0, 12),
                'owner_type' => (string)($owner['owner_type'] ?? self::ownerType($ownerKey)),
                'connection_id' => (int)($owner['connection_id'] ?? 0),
                'leases' => $leases,
                'held_ms' => \round(\max(0.0, $now - (float)($owner['acquired_at'] ?? $now)) * 1000, 2),
            ];
        }

        return [
            'owner_count' => \count($owners),
            'lease_count' => $leaseCount,
            'raw_owner_count' => \count(self::$rawOwners[$poolKey] ?? []),
            'owners' => $owners,
        ];
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
                if (!$connection instanceof PDO) {
                    throw new \UnexpectedValueException('Connection factory must return PDO.');
                }
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
     * 2. 只归还当前请求/Fiber owner 的连接，不影响挂起的同辈 Fiber
     */
    public static function requestEndCleanup(): void
    {
        $ownerKey = self::resolveCurrentOwnerKey();

        foreach (\array_keys(self::$ownerConnections) as $poolKey) {
            $owner = self::$ownerConnections[$poolKey][$ownerKey] ?? null;
            if (!\is_array($owner) || !(($owner['connection'] ?? null) instanceof PDO)) {
                continue;
            }

            $reusable = self::prepareConnectionForReturn($owner['connection']);
            self::settleOwnerConnection($poolKey, $ownerKey, !$reusable);
        }

        // Compatibility checkouts also carry an owner token. Clean only the
        // current one so a suspended peer request cannot lose its PDO.
        foreach (\array_keys(self::$rawOwners) as $poolKey) {
            foreach (self::$rawOwners[$poolKey] ?? [] as $connectionId => $rawOwnerKey) {
                if ($rawOwnerKey !== $ownerKey) {
                    continue;
                }

                $connection = self::$pools[$poolKey]['in_use'][$connectionId] ?? null;
                unset(self::$rawOwners[$poolKey][$connectionId]);
                if (!$connection instanceof PDO) {
                    continue;
                }

                if (self::prepareConnectionForReturn($connection)) {
                    self::releaseConnectionByPoolKey($connection, $poolKey);
                } else {
                    self::discardConnectionByPoolKey($connection, $poolKey);
                }
            }
            if (empty(self::$rawOwners[$poolKey])) {
                unset(self::$rawOwners[$poolKey]);
            }
        }
    }

    private static function prepareConnectionForReturn(PDO $connection): bool
    {
        if (self::isConnectionMarkedUnhealthy($connection)) {
            return false;
        }

        try {
            if ($connection->inTransaction() && !$connection->rollBack()) {
                self::markConnectionUnhealthy($connection);
                return false;
            }
        } catch (\Throwable) {
            self::markConnectionUnhealthy($connection);
            return false;
        }

        return !self::isConnectionMarkedUnhealthy($connection);
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
