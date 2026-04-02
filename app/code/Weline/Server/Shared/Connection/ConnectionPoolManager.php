<?php

declare(strict_types=1);

namespace Weline\Server\Shared\Connection;

use Weline\Framework\Runtime\SchedulerSystem;
use Weline\Server\Shared\Contract\ConnectionPoolInterface;
use Weline\Server\Shared\Contract\PooledConnectionInterface;

class ConnectionPoolManager implements ConnectionPoolInterface
{
    /** @var array<string, self> */
    private static array $instances = [];

    /** @var array<int, array{conn:PooledConnection,busy:bool,last_used:float}> */
    private array $pool = [];

    public static function getInstance(string $host, int $port, array $options = []): self
    {
        $normalizedOptions = self::normalizeOptions($options);
        // 池 key 仅取连接身份字段（host:port:token），忽略 max_size/min_idle 等量级差异，
        // 确保同一 host:port:token 的不同调用方（预热、SessionClient、WlsSharedStorage）共享同一连接池。
        $tokenFileName = (string)($normalizedOptions['token_file_name'] ?? '');
        $key = $host . ':' . $port . ':' . $tokenFileName;
        if (!isset(self::$instances[$key])) {
            self::$instances[$key] = new self($host, $port, $normalizedOptions);
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

    private function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly array $options
    ) {
        $minIdle = \max(1, (int)($this->options['min_idle'] ?? 1));
        for ($i = 0; $i < $minIdle; $i++) {
            $conn = $this->createConnection();
            if ($conn->connect()) {
                $this->pool[] = ['conn' => $conn, 'busy' => false, 'last_used' => \microtime(true)];
            }
        }
    }

    public function acquire(float $timeoutSec = 0.05): ?PooledConnectionInterface
    {
        $startTime = \microtime(true);
        $deadline = $startTime + $timeoutSec;
        $maxSize = \max(1, (int)($this->options['max_size'] ?? 8));
        $retryCount = 0;

        while (\microtime(true) <= $deadline) {
            foreach ($this->pool as $idx => $item) {
                if ($item['busy']) {
                    continue;
                }
                $conn = $item['conn'];
                if (!$conn->isConnected() && !$conn->connect()) {
                    continue;
                }
                $this->pool[$idx]['busy'] = true;
                $this->pool[$idx]['last_used'] = \microtime(true);

                // 记录成功获取延迟
                $this->recordAcquireMetric($startTime, 'success', $retryCount);

                return $conn;
            }

            if (\count($this->pool) < $maxSize) {
                $conn = $this->createConnection();
                try {
                    if ($conn->connect()) {
                        $this->pool[] = ['conn' => $conn, 'busy' => true, 'last_used' => \microtime(true)];

                        // 记录成功获取延迟
                        $this->recordAcquireMetric($startTime, 'success', $retryCount);

                        return $conn;
                    }
                } catch (\Throwable $e) {
                    // 连接失败，清理资源
                    $conn->close();
                    $this->log('Connection failed during acquire: ' . $e->getMessage());
                }
            }
            $retryCount++;
            SchedulerSystem::usleep(100);
        }

        // 记录超时
        $this->recordAcquireMetric($startTime, 'timeout', $retryCount);
        $this->incrementMetric('wls_pool_acquire_timeout_total', []);

        return null;
    }

    public function release(PooledConnectionInterface $connection): void
    {
        foreach ($this->pool as $idx => $item) {
            if ($item['conn'] === $connection) {
                $this->pool[$idx]['busy'] = false;
                $this->pool[$idx]['last_used'] = \microtime(true);
                return;
            }
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
        $minIdle = \max(1, (int)($this->options['min_idle'] ?? 1));

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

            // 检查连接健康状态
            if (!$item['conn']->ping()) {
                $ok = false;
                $item['conn']->close();
                $conn = $this->createConnection();
                $conn->connect();
                $this->pool[$idx] = ['conn' => $conn, 'busy' => false, 'last_used' => \microtime(true)];
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
        if ($tokenFileName === '') {
            $tokenFileName = $this->port === 19971 ? 'memory_server.token' : 'session_server.token';
        }
        $tokenFilePath = $basePath . $tokenFileName;

        return new PooledConnection(
            $this->host,
            $this->port,
            (float)($this->options['connect_timeout'] ?? 1.0),
            (float)($this->options['timeout'] ?? 2.0),
            $tokenFilePath,
            (bool)($this->options['log_connect_fail'] ?? true)
        );
    }

    private function log(string $message): void
    {
        if (\PHP_SAPI !== 'cli' && \PHP_SAPI !== 'phpdbg') {
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
    public function detectLeaks(float $leakThresholdSec = 30.0, bool $autoReclaim = true): int
    {
        $now = \microtime(true);
        $leakCount = 0;

        foreach ($this->pool as $idx => $item) {
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

                // 自动回收泄漏的连接
                if ($autoReclaim) {
                    $this->log('Auto-reclaiming leaked connection');
                    $this->pool[$idx]['busy'] = false;
                    $this->pool[$idx]['last_used'] = $now;
                }
            }
        }

        return $leakCount;
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
