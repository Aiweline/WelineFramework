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
    private bool $released = false;

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
            // 默认 0：不在建池阶段预建 TCP。多 Worker 同时预建连接会对 Session/Memory 单进程打连接风暴，
            // 易导致共享服务事件循环阻塞、cmd 子进程风暴与访问卡死。首条业务 acquire 再建连；需要可显式传 pool_min_idle。
            $options['min_idle'] = 0;
        }
        if (!isset($options['max_size']) && !isset($options['pool_size'])) {
            // Align with SharedStatePoolDefaults; callers that need more must pass pool_size explicitly.
            $options['pool_size'] = \Weline\Server\Shared\Connection\SharedStatePoolDefaults::MEMORY_POOL_SIZE;
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
        $requestStart = \microtime(true);
        return $this->withConnection(function (PooledConnectionInterface $connection) use ($cmd, $params, $requestStart): ?array {
            $encodeStart = \microtime(true);
            $payload = SessionProtocol::encodeRequest($cmd, $params);
            $this->recordClientPhase('protocol_encode', $encodeStart, 'success');

            if (!$connection->send($payload)) {
                $this->recordClientPhase('request', $requestStart, 'failure');
                return null;
            }
            $response = $connection->read();
            $this->recordClientPhase(
                'request',
                $requestStart,
                \is_array($response) ? 'success' : 'timeout'
            );
            return $response;
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

    public function warmup(): bool
    {
        $result = $this->withConnection(static fn (PooledConnectionInterface $connection): array => ['ok' => $connection->isConnected()]);

        return \is_array($result);
    }

    public function disconnect(): void
    {
        if ($this->released) {
            return;
        }

        // Shared-service consumers are owned by Master IPC, not by per-worker TCP clients.
        $this->released = true;
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

    private function recordClientPhase(string $phase, float $startTime, string $result): void
    {
        $durationMs = (\microtime(true) - $startTime) * 1000;
        \Weline\Server\Service\Telemetry\MetricsCollector::getInstance()->recordHistogram(
            'wls_shared_client_phase_duration_ms',
            $durationMs,
            ['phase' => $phase, 'result' => $result]
        );
    }

    public function __destruct()
    {
        $this->disconnect();
    }
}
