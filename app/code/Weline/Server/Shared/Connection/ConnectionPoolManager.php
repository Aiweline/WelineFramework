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

    private function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly array $options
    ) {
        $minIdle = \max(1, (int)($this->options['min_idle'] ?? 1));
        for ($i = 0; $i < $minIdle; $i++) {
            $conn = $this->createConnection();
            $conn->connect();
            $this->pool[] = ['conn' => $conn, 'busy' => false, 'last_used' => \microtime(true)];
        }
    }

    public function acquire(float $timeoutSec = 0.05): ?PooledConnectionInterface
    {
        $deadline = \microtime(true) + $timeoutSec;
        $maxSize = \max(1, (int)($this->options['max_size'] ?? 8));

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
                return $conn;
            }

            if (\count($this->pool) < $maxSize) {
                $conn = $this->createConnection();
                if ($conn->connect()) {
                    $this->pool[] = ['conn' => $conn, 'busy' => true, 'last_used' => \microtime(true)];
                    return $conn;
                }
            }
            SchedulerSystem::usleep(1000);
        }

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
        foreach ($this->pool as $idx => $item) {
            if ($item['busy']) {
                continue;
            }
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
}
