<?php

declare(strict_types=1);

namespace Weline\Server\Shared\Connection;

use Weline\Framework\Runtime\SchedulerSystem;
use Weline\Server\Log\WlsLogger;
use Weline\Server\Session\Server\SessionProtocol;
use Weline\Server\Shared\Contract\PooledConnectionInterface;

class PooledConnection implements PooledConnectionInterface
{
    private mixed $socket = null;
    private string $buffer = '';
    private bool $authenticated = false;
    private ?string $authToken = null;
    private int $authTokenMtime = 0;
    private int $authTokenVersion = 0; // Token 版本号
    private string $serviceType = ''; // 服务类型标识（Session/Memory/Cache）

    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly float $connectTimeout = 1.0,
        private readonly float $timeout = 2.0,
        private readonly string $tokenFilePath = '',
        private readonly bool $logConnectFailure = true,
        ?string $serviceType = null
    ) {
        // 根据端口自动推断服务类型
        $this->serviceType = $serviceType ?? $this->detectServiceType($port);
    }

    public function connect(): bool
    {
        if ($this->isConnected() && $this->authenticated) {
            return true;
        }

        $timestamp = date('Y-m-d H:i:s.') . sprintf('%03d', (int)(microtime(true) * 1000) % 1000);
        $this->log("[CONN-START] {$timestamp} Attempting connect to {$this->host}:{$this->port} ({$this->serviceType})");

        $errno = 0;
        $errstr = '';
        // Linux: 使用 context 明确超时，避免 default_socket_timeout 或系统行为导致长时间阻塞
        $timeoutSec = (float) $this->connectTimeout;
        if (\defined('PHP_OS_FAMILY') && PHP_OS_FAMILY === 'Linux' && $timeoutSec > 2.0) {
            $timeoutSec = 2.0;
        }
        $ctx = @\stream_context_create([
            'socket' => [
                'tcp_nodelay' => true,
            ],
        ]);
        $socket = @\stream_socket_client(
            "tcp://{$this->host}:{$this->port}",
            $errno,
            $errstr,
            $timeoutSec,
            STREAM_CLIENT_CONNECT,
            $ctx
        );
        if (!$socket) {
            $timestamp = date('Y-m-d H:i:s.') . sprintf('%03d', (int)(microtime(true) * 1000) % 1000);
            if ($this->logConnectFailure) {
                $this->log("[CONN-FAIL] {$timestamp} Connect failed: {$errstr} ({$errno}) ({$this->serviceType})");
            }
            return false;
        }

        \stream_set_timeout(
            $socket,
            (int)$this->timeout,
            (int)(($this->timeout - (int)$this->timeout) * 1000000)
        );
        \stream_set_blocking($socket, true);

        $this->socket = $socket;
        $this->buffer = '';
        $this->authenticated = false;

        if (!$this->authenticate()) {
            $timestamp = date('Y-m-d H:i:s.') . sprintf('%03d', (int)(microtime(true) * 1000) % 1000);
            $this->log("[CONN-AUTH-FAIL] {$timestamp} Authentication failed ({$this->serviceType})");
            $this->close();
            return false;
        }

        $timestamp = date('Y-m-d H:i:s.') . sprintf('%03d', (int)(microtime(true) * 1000) % 1000);
        $this->log("[CONN-OK] {$timestamp} Connected and authenticated ({$this->serviceType})");
        return true;
    }

    public function isConnected(): bool
    {
        return $this->socket !== null && \is_resource($this->socket) && !\feof($this->socket);
    }

    public function send(string $payload): bool
    {
        if (!$this->isConnected() && !$this->connect()) {
            return false;
        }
        $total = \strlen($payload);
        $offset = 0;
        while ($offset < $total) {
            $written = @\fwrite($this->socket, \substr($payload, $offset));
            if ($written === false || $written === 0) {
                $this->close();
                return false;
            }
            $offset += $written;
        }
        return true;
    }

    public function read(): ?array
    {
        if (!$this->isConnected()) {
            return null;
        }
        $deadline = \microtime(true) + $this->timeout;

        while (true) {
            $remaining = $deadline - \microtime(true);
            if ($remaining <= 0) {
                return null;
            }

            // 使用 stream_select 等待数据，避免 CPU 空转
            $read = [$this->socket];
            $write = null;
            $except = null;
            $timeoutSec = (int)$remaining;
            $timeoutUsec = (int)(($remaining - $timeoutSec) * 1000000);

            $ready = @\stream_select($read, $write, $except, $timeoutSec, $timeoutUsec);
            if ($ready === false) {
                $this->close();
                return null;
            }
            if ($ready === 0) {
                // 超时
                return null;
            }

            // 有数据可读
            $chunk = @\fread($this->socket, 65536);
            if ($chunk === false) {
                $this->close();
                return null;
            }
            if ($chunk === '') {
                if (\feof($this->socket)) {
                    $this->close();
                    return null;
                }
                // stream_select 说有数据但 fread 返回空，可能是信号中断，继续等待
                continue;
            }
            $this->buffer .= $chunk;

            // 防止缓冲区无限增长（限制 2MB）
            if (\strlen($this->buffer) > 2097152) {
                $this->close();
                return null;
            }

            $messages = SessionProtocol::extractMessages($this->buffer);
            if (!empty($messages)) {
                return $messages[0];
            }
        }
    }

    public function ping(): bool
    {
        if (!$this->send(SessionProtocol::buildPing())) {
            return false;
        }
        $response = $this->read();
        return \is_array($response)
            && SessionProtocol::isSuccess($response)
            && SessionProtocol::getData($response) === 'pong';
    }

    public function close(): void
    {
        if ($this->socket !== null) {
            $timestamp = date('Y-m-d H:i:s.') . sprintf('%03d', (int)(microtime(true) * 1000) % 1000);
            $this->log("[CONN-CLOSE] {$timestamp} Closing connection to {$this->host}:{$this->port}");
            @\fclose($this->socket);
            $this->socket = null;
        }
        $this->buffer = '';
        $this->authenticated = false;
    }

    private function authenticate(): bool
    {
        $authStartTime = \microtime(true);

        $token = $this->loadToken();
        if ($token === null) {
            $this->authenticated = true;
            $this->recordAuthMetric($authStartTime, 'success', 'no_auth');
            return true;
        }
        if ($this->tryAuthenticateWithToken($token)) {
            $this->authenticated = true;
            $this->recordAuthMetric($authStartTime, 'success', 'first_attempt');
            return true;
        }

        // WLS 常驻进程下，服务重启会轮换 token；认证失败时强制刷新 token 后重试。
        // 使用指数退避策略：10ms -> 20ms -> 50ms
        $retryDelays = [10000, 20000, 50000]; // 微秒
        $maxRetries = count($retryDelays);

        for ($retry = 0; $retry < $maxRetries; $retry++) {
            \usleep($retryDelays[$retry]);
            $freshToken = $this->loadToken(true);

            if ($freshToken !== null && $freshToken !== $token && $this->tryAuthenticateWithToken($freshToken)) {
                $this->authenticated = true;
                $this->recordAuthMetric($authStartTime, 'success', 'token_refresh_retry_' . ($retry + 1));
                $this->incrementMetric('wls_pool_token_reload_total', ['reason' => 'auth_retry_' . ($retry + 1)]);
                return true;
            }

            $token = $freshToken;
        }

        $this->authenticated = false;
        $this->recordAuthMetric($authStartTime, 'failure', 'token_mismatch');
        $this->incrementMetric('wls_pool_auth_failure_total', ['reason' => 'token_mismatch']);
        return false;
    }

    private function tryAuthenticateWithToken(string $token): bool
    {
        if (!$this->send(SessionProtocol::buildAuth($token))) {
            return false;
        }
        $response = $this->read();
        return \is_array($response) && SessionProtocol::isSuccess($response);
    }

    private function loadToken(bool $forceReload = false): ?string
    {
        if (!$forceReload && $this->authToken !== null && !$this->isTokenFileChanged()) {
            return $this->authToken;
        }
        if ($this->tokenFilePath === '' || !\is_file($this->tokenFilePath)) {
            $this->authToken = null;
            $this->authTokenMtime = 0;
            $this->authTokenVersion = 0;
            return null;
        }
        $mtime = (int)(@\filemtime($this->tokenFilePath) ?: 0);
        $content = @\file_get_contents($this->tokenFilePath);
        if ($content === false || $content === '') {
            $this->authToken = null;
            $this->authTokenMtime = $mtime;
            $this->authTokenVersion = 0;
            return null;
        }

        // 解析 token:version 格式（兼容旧格式：纯 token）
        $content = \trim($content);
        $parts = \explode(':', $content, 2);
        $token = $parts[0];
        $version = isset($parts[1]) ? (int)$parts[1] : 0;

        $this->authToken = $token;
        $this->authTokenMtime = $mtime;
        $this->authTokenVersion = $version;
        return $this->authToken;
    }

    private function isTokenFileChanged(): bool
    {
        if ($this->tokenFilePath === '' || !\is_file($this->tokenFilePath)) {
            return false;
        }
        $mtime = (int)(@\filemtime($this->tokenFilePath) ?: 0);
        return $mtime !== $this->authTokenMtime;
    }

    private function log(string $message): void
    {
        // 避免在 Web 请求响应中混入连接池日志（会污染 HTML 输出）。
        // 仅在 CLI/调试器场景输出到 WLS 日志流。
        if (\PHP_SAPI !== 'cli' && \PHP_SAPI !== 'phpdbg') {
            return;
        }
        WlsLogger::info_('[PooledConnection] ' . $message);
    }

    /**
     * 记录认证指标
     */
    private function recordAuthMetric(float $startTime, string $result, string $reason): void
    {
        $durationMs = (\microtime(true) - $startTime) * 1000;

        if (isset($GLOBALS['wls_metrics_collector'])) {
            $GLOBALS['wls_metrics_collector']->recordHistogram(
                'wls_pool_auth_duration_ms',
                $durationMs,
                ['host' => $this->host, 'port' => (string)$this->port, 'result' => $result]
            );
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
     * 根据端口号推断服务类型
     */
    private function detectServiceType(int $port): string
    {
        // 根据默认端口推断服务类型
        return match ($port) {
            26422, 26423 => 'Session',
            19971 => 'Memory',
            default => "Port:{$port}",
        };
    }
}
