<?php

declare(strict_types=1);

namespace Weline\Server\Shared\Client;

use Weline\Server\Session\Server\SessionProtocol;
use Weline\Server\Shared\Connection\ConnectionPoolManager;
use Weline\Server\Shared\Contract\ConnectionPoolInterface;
use Weline\Server\Shared\Contract\PooledConnectionInterface;

class SharedStateClient
{
    private ConnectionPoolInterface $pool;
    private float $acquireTimeout;

    public function __construct(
        string $host = '127.0.0.1',
        int $port = 0,
        array $options = []
    ) {
        // 如果端口为 0，使用项目偏移量计算默认端口
        if ($port <= 0) {
            $port = 19970 + \Weline\Server\Service\MasterProcess::getProjectPortOffset();
        }
        if (!isset($options['min_idle']) && !isset($options['pool_min_idle'])) {
            // 与 SessionClient 一致：默认保持至少 1 条空闲长连接，避免每请求 TCP 握手。
            $options['min_idle'] = 1;
        }
        if (!isset($options['idle_timeout'])) {
            // Worker 常驻：默认 24h 内不因「空闲略久」主动拆 TCP（仍可在 acquire 失败时 invalidate）
            $options['idle_timeout'] = 86400.0;
        }
        if (!isset($options['pool_health_ping_idle'])) {
            // 默认不对池中每条空闲连接周期性 ping，避免与业务请求交错时误判断连、反复 Client disconnected
            $options['pool_health_ping_idle'] = false;
        }
        $this->pool = ConnectionPoolManager::getInstance($host, $port, $options);
        $this->acquireTimeout = (float)($options['acquire_timeout'] ?? $options['pool_acquire_timeout'] ?? 0.2);
    }

    public function request(string $cmd, array $params = []): ?array
    {
        return $this->withConnection(function (PooledConnectionInterface $connection) use ($cmd, $params): ?array {
            $payload = SessionProtocol::encodeRequest($cmd, $params);
            if (!$connection->send($payload)) {
                return null;
            }
            return $connection->read();
        });
    }

    public function isHealthy(): bool
    {
        return $this->pool->healthCheck();
    }

    public function ping(): bool
    {
        $resp = $this->request(SessionProtocol::CMD_PING);
        return \is_array($resp) && SessionProtocol::isSuccess($resp) && SessionProtocol::getData($resp) === 'pong';
    }

    public function disconnect(): void
    {
        // Keep the process-level shared-service pool alive for WLS worker reuse.
        // Real socket shutdown can still happen through explicit shutdownPool()
        // or naturally when the worker process exits.
    }

    public function shutdownPool(): void
    {
        $this->pool->shutdown();
    }

    private function withConnection(callable $callback): ?array
    {
        $conn = $this->pool->acquire($this->acquireTimeout);
        if ($conn === null) {
            return null;
        }

        // Fiber 并发：acquire 与 release/invalidate 必须在同一条 Fiber（或同为非 Fiber 上下文）内成对完成；
        // 具体校验在 ConnectionPoolManager::release；此处用 finally 杜绝异常路径下 busy 泄漏。
        $dispose = 'invalidate';
        $result = null;
        try {
            $result = $callback($conn);
            $dispose = \is_array($result) ? 'release' : 'invalidate';
        } catch (\Throwable) {
            $result = null;
        } finally {
            try {
                if ($dispose === 'release') {
                    $this->pool->release($conn);
                } else {
                    $this->pool->invalidate($conn);
                }
            } catch (\Throwable) {
                try {
                    $this->pool->invalidate($conn);
                } catch (\Throwable) {
                    // 已尽力回收；避免 finally 再抛导致掩盖业务异常
                }
            }
        }

        return $result;
    }
}
