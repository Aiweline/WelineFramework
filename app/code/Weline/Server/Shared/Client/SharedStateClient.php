<?php

declare(strict_types=1);

namespace Weline\Server\Shared\Client;

use Weline\Server\Session\Server\SessionProtocol;
use Weline\Server\Shared\Connection\ConnectionPoolManager;
use Weline\Server\Shared\Contract\ConnectionPoolInterface;
use Weline\Server\Shared\Contract\PooledConnectionInterface;

class SharedStateClient
{
    private static function monotonicSeconds(): float
    {
        return \hrtime(true) / 1_000_000_000;
    }

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
        $requestStartNs = \hrtime(true);
        $requestStart = $requestStartNs / 1_000_000_000;
        $trace = \Weline\Framework\Runtime\RequestLifecycleTrace::isEnabled() ? [
            'rpc_start_monotonic_us' => \intdiv($requestStartNs, 1000),
            'rpc_end_monotonic_us' => null,
            'read_start_monotonic_us' => null,
            'read_end_monotonic_us' => null,
            'command' => \substr($cmd, 0, 32),
            'namespace' => \is_string($params['ns'] ?? null) ? \substr($params['ns'], 0, 128) : '',
            'key_hash' => \is_scalar($params['key'] ?? null) ? \hash('sha256', (string)$params['key']) : null,
            'encoded_bytes' => null,
            'acquire_ms' => null,
            'encode_ms' => null,
            'send_ms' => null,
            'read_ms' => null,
            'dispose_ms' => null,
            'send_called' => false,
            'send_succeeded' => false,
            'read_called' => false,
            'response_received' => false,
        ] : null;
        try {
            return $this->withConnection(function (PooledConnectionInterface $connection) use ($cmd, $params, $requestStart, &$trace): ?array {
                $encodeStart = self::monotonicSeconds();
                try {
                    $payload = SessionProtocol::encodeRequest($cmd, $params);
                } finally {
                    if ($trace !== null) {
                        $trace['encode_ms'] = \round((self::monotonicSeconds() - $encodeStart) * 1000, 3);
                    }
                }
                $this->recordClientPhase('protocol_encode', $encodeStart, 'success');
                if ($trace !== null) {
                    $trace['encoded_bytes'] = \strlen($payload);
                    $trace['send_called'] = true;
                }
                $sendStart = $trace !== null ? self::monotonicSeconds() : null;
                try {
                    $sent = $connection->send($payload);
                } finally {
                    if ($trace !== null) {
                        $trace['send_ms'] = \round((self::monotonicSeconds() - $sendStart) * 1000, 3);
                    }
                }
                if ($trace !== null) {
                    $trace['send_succeeded'] = $sent;
                }
                if (!$sent) {
                    $this->recordClientPhase('request', $requestStart, 'failure');
                    return null;
                }
                if ($trace !== null) {
                    $trace['read_called'] = true;
                }
                $readStartNs = $trace !== null ? \hrtime(true) : null;
                try {
                    $response = $connection->read();
                } finally {
                    if ($trace !== null) {
                        $readEndNs = \hrtime(true);
                        $trace['read_ms'] = \round(($readEndNs - $readStartNs) / 1_000_000, 3);
                        $trace['read_start_monotonic_us'] = \intdiv($readStartNs, 1000);
                        $trace['read_end_monotonic_us'] = \intdiv($readEndNs, 1000);
                    }
                }
                if ($trace !== null) {
                    $trace['response_received'] = \is_array($response);
                }
                $this->recordClientPhase(
                    'request',
                    $requestStart,
                    \is_array($response) ? 'success' : 'timeout'
                );
                return $response;
            }, $trace);
        } finally {
            if ($trace !== null) {
                $requestEndNs = \hrtime(true);
                $trace['rpc_end_monotonic_us'] = \intdiv($requestEndNs, 1000);
                // RPC is a child of existing cache/session spans; do not count it again in WLS totals.
                \Weline\Framework\Runtime\RequestLifecycleTrace::recordSpan(
                    'wls.rpc.request',
                    ($requestEndNs - $requestStartNs) / 1_000_000,
                    'rpc',
                    null,
                    $trace
                );
            }
        }
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

    private function withConnection(callable $callback, ?array &$trace = null): ?array
    {
        $acquireStart = $trace !== null ? self::monotonicSeconds() : null;
        try {
            $conn = $this->pool->acquire($this->acquireTimeout);
        } finally {
            if ($trace !== null) {
                $trace['acquire_ms'] = \round((self::monotonicSeconds() - $acquireStart) * 1000, 3);
            }
        }
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
            $disposeStart = $trace !== null ? self::monotonicSeconds() : null;
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
            } finally {
                if ($trace !== null) {
                    $trace['dispose_ms'] = \round((self::monotonicSeconds() - $disposeStart) * 1000, 3);
                }
            }
        }

        return $result;
    }

    private function recordClientPhase(string $phase, float $startTime, string $result): void
    {
        $durationMs = (self::monotonicSeconds() - $startTime) * 1000;
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
