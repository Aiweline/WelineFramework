<?php

declare(strict_types=1);

namespace Weline\Server\Shared\Connection;

use Weline\Framework\Runtime\SchedulerSystem;
use Weline\Server\Scheduler\FiberScheduler;
use Weline\Server\Shared\Contract\ConnectionPoolInterface;
use Weline\Server\Shared\Contract\PooledConnectionInterface;

class ConnectionPoolManager implements ConnectionPoolInterface
{
    /** @var array<string, self> */
    private static array $instances = [];

    /**
     * lease_fiber_id: acquire 时记录 spl_object_id(Fiber::getCurrent())，无 Fiber 时为 0。
     * 用于防止跨 Fiber 误 release 将「半包协议状态」的连接标记为空闲（见 release）。
     *
     * @var array<int, array{conn:PooledConnection,busy:bool,last_used:float,lease_fiber_id:?int}>
     */
    private array $pool = [];
    private float $nextConnectAttemptAt = 0.0;
    private int $consecutiveConnectFailures = 0;
    private string $lastConnectFailureReason = '';

    public static function getInstance(string $host, int $port, array $options = []): self
    {
        $normalizedOptions = self::normalizeOptions($options);
        // 池 key 仅取连接身份字段（host:port:token）；min_idle/max_size 等由 mergeOptions 取并集升级，
        // 确保同一 host:port:token 的探测端与 Worker 共享同一连接池且可升到长连池策略。
        $tokenFileName = (string)($normalizedOptions['token_file_name'] ?? '');
        $key = $host . ':' . $port . ':' . $tokenFileName;
        if (!isset(self::$instances[$key])) {
            self::$instances[$key] = new self($host, $port, $normalizedOptions);
        } else {
            self::$instances[$key]->mergeOptions($normalizedOptions);
        }
        return self::$instances[$key];
    }

    /**
     * 丢弃指定 host:port:token 的连接池（关闭套接字并移除单例），用于共享侧车冷启动探测前清理陈旧状态。
     */
    public static function discardPool(string $host, int $port, string $tokenFileName = ''): void
    {
        $key = $host . ':' . $port . ':' . $tokenFileName;
        if (!isset(self::$instances[$key])) {
            return;
        }
        self::$instances[$key]->shutdown();
        unset(self::$instances[$key]);
    }

    /**
     * 在已启用 SchedulerSystem + 已注入 FiberScheduler 的前提下，将回调放入 Fiber 并泵送 tick，
     * 使池内 ensureMinIdleConnections 的 SchedulerSystem::yield() 能生效；结束后不保留任何额外调度状态。
     */
    public static function runWithFiberScheduler(FiberScheduler $scheduler, callable $body): void
    {
        $fiber = new \Fiber($body);
        $fiber->start();
        $guard = 0;
        while (!$fiber->isTerminated()) {
            $scheduler->tick();
            if ($fiber->isSuspended() && $scheduler->getNextTimerDelay() === null) {
                if (++$guard > 5000) {
                    \Weline\Server\Log\WlsLogger::warning_('[ConnectionPool] Fiber 预热挂起但无到期定时器，中止泵送');
                    break;
                }
                SchedulerSystem::usleep(500);
            } else {
                $guard = 0;
            }
        }
    }

    /**
     * 合并后续 getInstance 传入的池参数（取 max(min_idle)、max(max_size) 等），
     * 避免「先探测 min_idle=0 占坑」导致 Worker 侧 min_idle=1 永远不生效。
     *
     * @param array<string, mixed> $incoming
     */
    private function mergeOptions(array $incoming): void
    {
        $a = $this->options;
        $merged = $a;
        $merged['min_idle'] = \max((int)($a['min_idle'] ?? 0), (int)($incoming['min_idle'] ?? 0));
        $merged['max_size'] = \max((int)($a['max_size'] ?? 8), (int)($incoming['max_size'] ?? 8));
        $merged['connect_timeout'] = \max((float)($a['connect_timeout'] ?? 1.0), (float)($incoming['connect_timeout'] ?? 1.0));
        $merged['timeout'] = \max((float)($a['timeout'] ?? 2.0), (float)($incoming['timeout'] ?? 2.0));
        $merged['idle_timeout'] = \max((float)($a['idle_timeout'] ?? 300.0), (float)($incoming['idle_timeout'] ?? 300.0));
        $merged['pool_health_ping_idle'] = (bool)(($a['pool_health_ping_idle'] ?? false) || ($incoming['pool_health_ping_idle'] ?? false));
        $merged['log_connect_fail'] = (bool)(($a['log_connect_fail'] ?? true) || ($incoming['log_connect_fail'] ?? false));
        $merged['log_pool_lifecycle'] = (bool)(($a['log_pool_lifecycle'] ?? true) && ($incoming['log_pool_lifecycle'] ?? true));
        if (isset($incoming['service_type']) && \trim((string)$incoming['service_type']) !== '') {
            $merged['service_type'] = (string)$incoming['service_type'];
        }
        $this->options = $merged;
        $this->ensureMinIdleConnections();
    }

    /**
     * 补足最小空闲长连接（Worker/门面侧 pool_min_idle 依赖此项）。
     */
    private function ensureMinIdleConnections(): void
    {
        $minIdle = \max(0, (int)($this->options['min_idle'] ?? 0));
        if ($minIdle <= 0) {
            return;
        }
        $maxSize = \max(1, (int)($this->options['max_size'] ?? 8));
        while (true) {
            $idleConnected = 0;
            foreach ($this->pool as $item) {
                if (!$item['busy'] && $item['conn']->isConnected()) {
                    $idleConnected++;
                }
            }
            if ($idleConnected >= $minIdle || \count($this->pool) >= $maxSize) {
                break;
            }
            $conn = $this->createConnection();
            if (!$conn->connect()) {
                break;
            }
            $this->registerConnectSuccess();
            $this->pool[] = [
                'conn' => $conn,
                'busy' => false,
                'last_used' => \microtime(true),
                'lease_fiber_id' => null,
            ];
            // WLS 下在 Fiber 内预热时让出，避免独占 Worker/Dispatcher 主循环前的同步段
            SchedulerSystem::yield();
        }
    }

    /** @param array<string, mixed> $options */
    private function __construct(
        private readonly string $host,
        private readonly int $port,
        private array $options
    ) {
        // 默认 0：就绪探测等场景不得在建池时抢先建连；显式 min_idle / pool_min_idle 的 Worker/SessionClient 仍会预热。
        $this->ensureMinIdleConnections();
    }

    public function acquire(float $timeoutSec = 0.05): ?PooledConnectionInterface
    {
        $startTime = \microtime(true);
        $deadline = $startTime + $timeoutSec;
        $maxSize = \max(1, (int)($this->options['max_size'] ?? 8));
        $retryCount = 0;

        $leaseFiberId = self::currentFiberLeaseId();

        while (\microtime(true) <= $deadline) {
            if ($this->isInConnectCooldown()) {
                $retryCount++;
                $this->sleepUntilRetryWindow($deadline);
                continue;
            }
            foreach ($this->pool as $idx => $item) {
                if ($item['busy']) {
                    continue;
                }
                $conn = $item['conn'];
                if (!$conn->isConnected() && !$conn->connect()) {
                    $this->registerConnectFailure('reuse_connect');
                    continue;
                }
                $this->registerConnectSuccess();
                $this->pool[$idx]['busy'] = true;
                $this->pool[$idx]['last_used'] = \microtime(true);
                $this->pool[$idx]['lease_fiber_id'] = $leaseFiberId;

                // 记录成功获取延迟
                $this->recordAcquireMetric($startTime, 'success', $retryCount);

                return $conn;
            }

            if (\count($this->pool) < $maxSize) {
                $conn = $this->createConnection();
                try {
                    if ($conn->connect()) {
                        $this->registerConnectSuccess();
                        $this->pool[] = [
                            'conn' => $conn,
                            'busy' => true,
                            'last_used' => \microtime(true),
                            'lease_fiber_id' => $leaseFiberId,
                        ];

                        // 记录成功获取延迟
                        $this->recordAcquireMetric($startTime, 'success', $retryCount);

                        return $conn;
                    }
                    $this->registerConnectFailure('create_connect');
                } catch (\Throwable $e) {
                    // 连接失败，清理资源
                    $conn->close();
                    $this->log('Connection failed during acquire: ' . $e->getMessage());
                    $this->registerConnectFailure('create_exception');
                }
            }
            $retryCount++;
            SchedulerSystem::usleep(1000);
        }

        // 记录超时
        $this->recordAcquireMetric($startTime, 'timeout', $retryCount);
        $this->incrementMetric('wls_pool_acquire_timeout_total', []);

        return null;
    }

    public function release(PooledConnectionInterface $connection): void
    {
        $currentLease = self::currentFiberLeaseId();

        foreach ($this->pool as $idx => $item) {
            if ($item['conn'] !== $connection) {
                continue;
            }

            $expected = $item['lease_fiber_id'] ?? 0;
            if ($expected !== $currentLease) {
                $this->incrementMetric('wls_pool_fiber_lease_violation_total', ['op' => 'release']);
                $this->log(\sprintf(
                    'release ignored: fiber lease mismatch (expected=%s current=%s), invalidating',
                    (string)$expected,
                    (string)$currentLease
                ));
                $this->invalidate($connection);

                return;
            }

            $this->pool[$idx]['busy'] = false;
            $this->pool[$idx]['last_used'] = \microtime(true);
            $this->pool[$idx]['lease_fiber_id'] = null;

            return;
        }
    }

    public function invalidate(PooledConnectionInterface $connection): void
    {
        foreach ($this->pool as $idx => $item) {
            if ($item['conn'] === $connection) {
                $item['conn']->close();
                unset($this->pool[$idx]);
                $this->pool = \array_values($this->pool);
                return;
            }
        }
    }

    public function healthCheck(): bool
    {
        $ok = true;
        $now = \microtime(true);
        $idleTimeout = (float)($this->options['idle_timeout'] ?? 300.0); // 默认 5 分钟空闲超时
        $minIdle = \max(0, (int)($this->options['min_idle'] ?? 0));

        if ($this->pool === []) {
            $conn = $this->createConnection();
            if (!$conn->connect()) {
                return false;
            }
            $this->pool[] = [
                'conn' => $conn,
                'busy' => false,
                'last_used' => $now,
                'lease_fiber_id' => null,
            ];

            return true;
        }

        foreach ($this->pool as $idx => $item) {
            if ($item['busy']) {
                continue;
            }

            // 检查空闲超时：超过阈值的连接关闭（但保留最小空闲数）
            $idleDuration = $now - $item['last_used'];
            if ($idleDuration > $idleTimeout && \count($this->pool) > $minIdle) {
                $item['conn']->close();
                unset($this->pool[$idx]);
                $this->pool = \array_values($this->pool);
                $this->log(\sprintf('Closed idle connection (idle: %.2fs)', $idleDuration));
                continue;
            }

            // 可选：对空闲连接逐条 ping（易与业务请求交错误判，默认关闭，见 pool_health_ping_idle）
            if (($this->options['pool_health_ping_idle'] ?? false) && !$item['conn']->ping()) {
                $ok = false;
                $item['conn']->close();
                $conn = $this->createConnection();
                if ($conn->connect()) {
                    $this->registerConnectSuccess();
                } else {
                    $this->registerConnectFailure('health_check');
                }
                $this->pool[$idx] = [
                    'conn' => $conn,
                    'busy' => false,
                    'last_used' => \microtime(true),
                    'lease_fiber_id' => null,
                ];
            }
        }
        return $ok;
    }

    public function shutdown(): void
    {
        foreach ($this->pool as $item) {
            $item['conn']->close();
        }
        $this->pool = [];
    }

    private function createConnection(): PooledConnection
    {
        $basePath = \defined('BP') ? BP . 'var/session/' : '/tmp/wls_session/';
        $tokenFileName = (string)($this->options['token_file_name'] ?? '');
        $serviceType = (string)($this->options['service_type'] ?? '');
        if ($tokenFileName === '') {
            $normalizedServiceType = \strtolower(\trim($serviceType));
            $tokenFileName = \str_starts_with($normalizedServiceType, 'memory')
                ? 'memory_server.token'
                : 'session_server.token';
        } elseif ($serviceType === '') {
            $normalizedToken = \strtolower($tokenFileName);
            if (\str_contains($normalizedToken, 'memory')) {
                $serviceType = 'Memory';
            } elseif (\str_contains($normalizedToken, 'session')) {
                $serviceType = 'Session';
            }
        }
        $tokenFilePath = $basePath . $tokenFileName;

        return new PooledConnection(
            $this->host,
            $this->port,
            (float)($this->options['connect_timeout'] ?? 1.0),
            (float)($this->options['timeout'] ?? 2.0),
            $tokenFilePath,
            (bool)($this->options['log_connect_fail'] ?? true),
            $serviceType !== '' ? $serviceType : null,
            (bool)($this->options['log_pool_lifecycle'] ?? true),
        );
    }

    private function log(string $message): void
    {
        if (\PHP_SAPI !== 'cli' && \PHP_SAPI !== 'phpdbg') {
            return;
        }
        if (!($this->options['log_pool_lifecycle'] ?? true)) {
            return;
        }
        \Weline\Server\Log\WlsLogger::info_('[ConnectionPool] ' . $message);
    }

    private static function normalizeOptions(array $options): array
    {
        if (!isset($options['min_idle']) && isset($options['pool_min_idle'])) {
            $options['min_idle'] = (int)$options['pool_min_idle'];
        }
        if (!isset($options['max_size']) && isset($options['pool_size'])) {
            $options['max_size'] = (int)$options['pool_size'];
        }
        return $options;
    }

    /**
     * 记录连接获取指标
     */
    private function recordAcquireMetric(float $startTime, string $result, int $retryCount): void
    {
        $durationMs = (\microtime(true) - $startTime) * 1000;

        if (isset($GLOBALS['wls_metrics_collector'])) {
            $GLOBALS['wls_metrics_collector']->recordHistogram(
                'wls_pool_acquire_duration_ms',
                $durationMs,
                ['host' => $this->host, 'port' => (string)$this->port, 'result' => $result]
            );

            if ($retryCount > 0) {
                $GLOBALS['wls_metrics_collector']->incrementCounter(
                    'wls_pool_acquire_retry_total',
                    $retryCount,
                    ['host' => $this->host, 'port' => (string)$this->port]
                );
            }
        }
    }

    /**
     * 递增指标计数器
     */
    private function incrementMetric(string $name, array $labels): void
    {
        if (isset($GLOBALS['wls_metrics_collector'])) {
            $GLOBALS['wls_metrics_collector']->incrementCounter(
                $name,
                1,
                \array_merge(['host' => $this->host, 'port' => (string)$this->port], $labels)
            );
        }
    }

    /**
     * 检测连接泄漏并自动回收
     */
    private function isInConnectCooldown(): bool
    {
        return \microtime(true) < $this->nextConnectAttemptAt;
    }

    private function sleepUntilRetryWindow(float $deadline): void
    {
        $remainingSec = \min(
            \max(0.0, $this->nextConnectAttemptAt - \microtime(true)),
            \max(0.0, $deadline - \microtime(true))
        );
        if ($remainingSec <= 0) {
            return;
        }

        SchedulerSystem::usleep((int)\max(1000, \min(250000, $remainingSec * 1000000)));
    }

    private function registerConnectFailure(string $reason): void
    {
        $this->consecutiveConnectFailures++;
        $step = \min(5, $this->consecutiveConnectFailures - 1);
        $delaySec = \min(5.0, 0.25 * (2 ** $step));
        $this->nextConnectAttemptAt = \microtime(true) + $delaySec;

        if (
            $this->consecutiveConnectFailures === 1
            || $this->lastConnectFailureReason !== $reason
            || $this->consecutiveConnectFailures % 5 === 0
        ) {
            $this->log(\sprintf(
                'Entering connect cooldown: %.2fs after %d failure(s) to %s:%d (%s)',
                $delaySec,
                $this->consecutiveConnectFailures,
                $this->host,
                $this->port,
                $reason
            ));
        }

        $this->lastConnectFailureReason = $reason;
        $this->incrementMetric('wls_pool_connect_backoff_total', ['reason' => $reason]);
    }

    private function registerConnectSuccess(): void
    {
        if ($this->consecutiveConnectFailures > 0) {
            $this->log(\sprintf(
                'Connect cooldown cleared after %d failure(s) to %s:%d',
                $this->consecutiveConnectFailures,
                $this->host,
                $this->port
            ));
        }

        $this->consecutiveConnectFailures = 0;
        $this->nextConnectAttemptAt = 0.0;
        $this->lastConnectFailureReason = '';
    }

    public function detectLeaks(float $leakThresholdSec = 30.0, bool $autoReclaim = true): int
    {
        $now = \microtime(true);
        $leakCount = 0;
        $toInvalidate = [];

        foreach ($this->pool as $item) {
            if (!$item['busy']) {
                continue;
            }

            $busyDuration = $now - $item['last_used'];
            if ($busyDuration > $leakThresholdSec) {
                $leakCount++;

                if (isset($GLOBALS['wls_metrics_collector'])) {
                    $GLOBALS['wls_metrics_collector']->incrementCounter(
                        'wls_pool_connection_leak_total',
                        1,
                        ['host' => $this->host, 'port' => (string)$this->port]
                    );
                }

                $this->log(\sprintf(
                    'Connection leak detected: busy for %.2fs (threshold: %.2fs)',
                    $busyDuration,
                    $leakThresholdSec
                ));

                // 半开/卡死连接不可再标为空闲入池，避免下一 Fiber 复用污染协议状态
                if ($autoReclaim) {
                    $this->log('Auto-reclaiming leaked connection (invalidate/close)');
                    $toInvalidate[] = $item['conn'];
                }
            }
        }

        foreach ($toInvalidate as $conn) {
            $this->invalidate($conn);
        }

        return $leakCount;
    }

    /**
     * 0 表示非 Fiber 上下文（如同步 CLI）；与 acquire 时记录的值一致才可 release。
     */
    private static function currentFiberLeaseId(): int
    {
        $fiber = \Fiber::getCurrent();

        return $fiber !== null ? \spl_object_id($fiber) : 0;
    }

    /**
     * 获取连接池状态指标
     */
    public function getPoolMetrics(): array
    {
        $idle = 0;
        $busy = 0;

        foreach ($this->pool as $item) {
            if ($item['busy']) {
                $busy++;
            } else {
                $idle++;
            }
        }

        $total = \count($this->pool);

        // 更新 Gauge 指标
        if (isset($GLOBALS['wls_metrics_collector'])) {
            $labels = ['host' => $this->host, 'port' => (string)$this->port];
            $GLOBALS['wls_metrics_collector']->setGauge('wls_pool_connections_total', (float)$idle, \array_merge($labels, ['state' => 'idle']));
            $GLOBALS['wls_metrics_collector']->setGauge('wls_pool_connections_total', (float)$busy, \array_merge($labels, ['state' => 'busy']));
            $GLOBALS['wls_metrics_collector']->setGauge('wls_pool_connections_total', (float)$total, \array_merge($labels, ['state' => 'total']));
        }

        return [
            'idle' => $idle,
            'busy' => $busy,
            'total' => $total,
        ];
    }
}
