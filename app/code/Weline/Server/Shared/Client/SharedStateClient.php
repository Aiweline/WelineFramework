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

        try {
            $result = $callback($conn);
            if (!\is_array($result)) {
                $this->pool->invalidate($conn);
            } else {
                $this->pool->release($conn);
            }
            return $result;
        } catch (\Throwable) {
            $this->pool->invalidate($conn);
            return null;
        }
    }
}
