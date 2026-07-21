<?php

declare(strict_types=1);

namespace Weline\Server\Shared\Connection;

use Weline\Framework\Runtime\SchedulerSystem;
use Weline\Server\Log\WlsLogger;
use Weline\Server\Session\Server\SessionProtocol;
use Weline\Server\Shared\Contract\PooledConnectionInterface;

/**
 * 单连接上复用 Session 帧协议（非 HTTP/2 多路复用）。
 * 由 ConnectionPoolManager 保证同一时刻仅一个租约持有者；不得把同一实例跨 Fiber 传递或并行读写。
 *
 * Socket 统一非阻塞：WLS Fiber + enableIoWait 时挂起等待 fd；CLI/FPM/无 I/O await 时
 * 回退到有界 stream_select。超时/EOF/协议错误一律 close，禁止迟到响应回池。
 */
class PooledConnection implements PooledConnectionInterface
{
    private mixed $socket = null;
    private string $buffer = '';
    private bool $authenticated = false;
    private ?string $authToken = null;
    private int $authTokenMtime = 0;
    private int $authTokenVersion = 0;
    private string $serviceType = '';

    private float $nextConnectAttemptAt = 0.0;
    private int $consecutiveFailures = 0;
    private float $connectTimeout;
    private float $timeout;

    public function __construct(
        private readonly string $host,
        private readonly int $port,
        float $connectTimeout = 1.0,
        float $timeout = 2.0,
        private readonly string $tokenFilePath = '',
        private readonly bool $logConnectFailure = true,
        ?string $serviceType = null,
        private readonly bool $logLifecycleDetails = true,
    ) {
        $this->connectTimeout = \max(0.001, $connectTimeout);
        $this->timeout = \max(0.001, $timeout);
        $this->serviceType = $serviceType ?? $this->detectServiceType($port);
    }

    /**
     * Allow pool option merges to refresh timeouts on already-created connections.
     */
    public function applyTimeouts(float $connectTimeout, float $timeout): void
    {
        if ($connectTimeout > 0.0) {
            $this->connectTimeout = $connectTimeout;
        }
        if ($timeout > 0.0) {
            $this->timeout = $timeout;
        }
        if ($this->socket !== null && \is_resource($this->socket)) {
            @\stream_set_timeout(
                $this->socket,
                (int) $this->timeout,
                (int) (($this->timeout - (int) $this->timeout) * 1_000_000)
            );
        }
    }

    public function connect(): bool
    {
        if ($this->isConnected() && $this->authenticated) {
            return true;
        }

        if (\microtime(true) < $this->nextConnectAttemptAt) {
            return false;
        }

        if ($this->logLifecycleDetails) {
            $timestamp = date('Y-m-d H:i:s.') . sprintf('%03d', (int)(microtime(true) * 1000) % 1000);
            $this->log("[CONN-START] {$timestamp} Attempting connect to {$this->host}:{$this->port} ({$this->serviceType})");
        }

        $connectStart = \microtime(true);
        $deadline = $connectStart + $this->connectTimeout;
        if (\defined('PHP_OS_FAMILY') && PHP_OS_FAMILY === 'Linux' && $this->connectTimeout > 2.0) {
            $deadline = $connectStart + 2.0;
        }

        $errno = 0;
        $errstr = '';
        $ctx = @\stream_context_create([
            'socket' => [
                'tcp_nodelay' => true,
            ],
        ]);
        $socket = @\stream_socket_client(
            "tcp://{$this->host}:{$this->port}",
            $errno,
            $errstr,
            0.0,
            STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT,
            $ctx
        );
        if (!$socket) {
            $timestamp = date('Y-m-d H:i:s.') . sprintf('%03d', (int)(microtime(true) * 1000) % 1000);
            if ($this->logConnectFailure) {
                $this->log("[CONN-FAIL] {$timestamp} Connect failed: {$errstr} ({$errno}) ({$this->serviceType})");
            }
            $this->registerFailure();
            $this->recordPhaseMetric('connect', $connectStart, 'failure');
            return false;
        }

        @\stream_set_blocking($socket, false);
        @\stream_set_timeout(
            $socket,
            (int) $this->timeout,
            (int) (($this->timeout - (int) $this->timeout) * 1_000_000)
        );

        $this->socket = $socket;
        $this->buffer = '';
        $this->authenticated = false;

        if (!$this->awaitWritable($deadline)) {
            if ($this->logLifecycleDetails) {
                $this->log("[CONN-FAIL] Connect writable timeout ({$this->serviceType})");
            }
            $this->close();
            $this->registerFailure();
            $this->recordPhaseMetric('connect', $connectStart, 'timeout');
            return false;
        }

        if (!$this->assertSocketConnected()) {
            if ($this->logConnectFailure) {
                $this->log("[CONN-FAIL] Connect SO_ERROR failed ({$this->serviceType})");
            }
            $this->close();
            $this->registerFailure();
            $this->recordPhaseMetric('connect', $connectStart, 'failure');
            return false;
        }

        $this->recordPhaseMetric('connect', $connectStart, 'success');

        if (!$this->authenticate($deadline)) {
            if ($this->logLifecycleDetails) {
                $timestamp = date('Y-m-d H:i:s.') . sprintf('%03d', (int)(microtime(true) * 1000) % 1000);
                $this->log("[CONN-AUTH-FAIL] {$timestamp} Authentication failed ({$this->serviceType})");
            }
            $this->close();
            $this->registerFailure();
            return false;
        }

        if ($this->logLifecycleDetails) {
            $timestamp = date('Y-m-d H:i:s.') . sprintf('%03d', (int)(microtime(true) * 1000) % 1000);
            $this->log("[CONN-OK] {$timestamp} Connected and authenticated ({$this->serviceType})");
        }
        $this->resetFailureState();
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

        $deadline = \microtime(true) + $this->timeout;
        $phaseStart = \microtime(true);
        $total = \strlen($payload);
        $offset = 0;
        while ($offset < $total) {
            if (\microtime(true) >= $deadline) {
                $this->close();
                $this->recordPhaseMetric('write', $phaseStart, 'timeout');
                return false;
            }

            $written = @\fwrite($this->socket, \substr($payload, $offset));
            if ($written === false) {
                $this->close();
                $this->recordPhaseMetric('write', $phaseStart, 'failure');
                return false;
            }
            if ($written === 0) {
                if (!$this->awaitWritable($deadline)) {
                    $this->close();
                    $this->recordPhaseMetric('write', $phaseStart, 'timeout');
                    return false;
                }
                continue;
            }
            $offset += $written;
        }

        $this->recordPhaseMetric('write', $phaseStart, 'success');
        return true;
    }

    public function read(): ?array
    {
        if (!$this->isConnected()) {
            return null;
        }

        $deadline = \microtime(true) + $this->timeout;
        $phaseStart = \microtime(true);

        while (true) {
            $messages = SessionProtocol::extractMessages($this->buffer);
            if (!empty($messages)) {
                $this->recordPhaseMetric('read', $phaseStart, 'success');
                return $messages[0];
            }

            if (\microtime(true) >= $deadline) {
                $this->close();
                $this->recordPhaseMetric('read', $phaseStart, 'timeout');
                return null;
            }

            if (!$this->awaitReadable($deadline)) {
                $this->close();
                $this->recordPhaseMetric('read', $phaseStart, 'timeout');
                return null;
            }

            $chunk = @\fread($this->socket, 65536);
            if ($chunk === false) {
                $this->close();
                $this->recordPhaseMetric('read', $phaseStart, 'failure');
                return null;
            }
            if ($chunk === '') {
                if (\feof($this->socket)) {
                    $this->close();
                    $this->recordPhaseMetric('read', $phaseStart, 'failure');
                    return null;
                }
                // Spurious readable: wait again within deadline.
                continue;
            }

            $this->buffer .= $chunk;
            if (\strlen($this->buffer) > SessionProtocol::MAX_BUFFER_BYTES) {
                $this->close();
                $this->recordPhaseMetric('read', $phaseStart, 'failure');
                return null;
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
            if ($this->logLifecycleDetails) {
                $timestamp = date('Y-m-d H:i:s.') . sprintf('%03d', (int)(microtime(true) * 1000) % 1000);
                $this->log("[CONN-CLOSE] {$timestamp} Closing connection to {$this->host}:{$this->port}");
            }
            @\fclose($this->socket);
            $this->socket = null;
        }
        $this->buffer = '';
        $this->authenticated = false;
    }

    private function authenticate(float $outerDeadline): bool
    {
        $authStartTime = \microtime(true);
        $deadline = \min($outerDeadline, \microtime(true) + $this->timeout);

        $token = $this->loadToken();
        if ($token === null) {
            $this->authenticated = true;
            $this->recordAuthMetric($authStartTime, 'success', 'no_auth');
            return true;
        }
        if ($this->tryAuthenticateWithToken($token, $deadline)) {
            $this->authenticated = true;
            $this->recordAuthMetric($authStartTime, 'success', 'first_attempt');
            return true;
        }

        $retryDelays = [10000, 20000, 50000];
        $maxRetries = count($retryDelays);

        for ($retry = 0; $retry < $maxRetries; $retry++) {
            if (\microtime(true) >= $deadline) {
                break;
            }
            SchedulerSystem::usleep($retryDelays[$retry]);
            $freshToken = $this->loadToken(true);

            if ($freshToken !== null && $freshToken !== $token && $this->tryAuthenticateWithToken($freshToken, $deadline)) {
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

    private function tryAuthenticateWithToken(string $token, float $deadline): bool
    {
        $remaining = $deadline - \microtime(true);
        if ($remaining <= 0) {
            return false;
        }
        // Temporarily bound send/read to remaining auth budget via absolute waits inside.
        if (!$this->sendWithDeadline(SessionProtocol::buildAuth($token), $deadline)) {
            return false;
        }
        $response = $this->readWithDeadline($deadline);
        return \is_array($response) && SessionProtocol::isSuccess($response);
    }

    private function sendWithDeadline(string $payload, float $deadline): bool
    {
        if (!$this->isConnected()) {
            return false;
        }
        $total = \strlen($payload);
        $offset = 0;
        while ($offset < $total) {
            if (\microtime(true) >= $deadline) {
                $this->close();
                return false;
            }
            $written = @\fwrite($this->socket, \substr($payload, $offset));
            if ($written === false) {
                $this->close();
                return false;
            }
            if ($written === 0) {
                if (!$this->awaitWritable($deadline)) {
                    $this->close();
                    return false;
                }
                continue;
            }
            $offset += $written;
        }
        return true;
    }

    private function readWithDeadline(float $deadline): ?array
    {
        if (!$this->isConnected()) {
            return null;
        }
        while (true) {
            $messages = SessionProtocol::extractMessages($this->buffer);
            if (!empty($messages)) {
                return $messages[0];
            }
            if (\microtime(true) >= $deadline) {
                $this->close();
                return null;
            }
            if (!$this->awaitReadable($deadline)) {
                $this->close();
                return null;
            }
            $chunk = @\fread($this->socket, 65536);
            if ($chunk === false || ($chunk === '' && \feof($this->socket))) {
                $this->close();
                return null;
            }
            if ($chunk === '') {
                continue;
            }
            $this->buffer .= $chunk;
            if (\strlen($this->buffer) > SessionProtocol::MAX_BUFFER_BYTES) {
                $this->close();
                return null;
            }
        }
    }

    private function awaitWritable(float $deadline): bool
    {
        if (!\is_resource($this->socket)) {
            return false;
        }
        $remaining = $deadline - \microtime(true);
        if ($remaining <= 0) {
            return false;
        }
        return SchedulerSystem::awaitWritable($this->socket, $remaining);
    }

    private function awaitReadable(float $deadline): bool
    {
        if (!\is_resource($this->socket)) {
            return false;
        }
        $remaining = $deadline - \microtime(true);
        if ($remaining <= 0) {
            return false;
        }
        return SchedulerSystem::awaitReadable($this->socket, $remaining);
    }

    private function assertSocketConnected(): bool
    {
        if (!\is_resource($this->socket)) {
            return false;
        }

        if (\function_exists('socket_import_stream') && \defined('SO_ERROR')) {
            $native = @\socket_import_stream($this->socket);
            if ($native !== false) {
                $error = @\socket_get_option($native, \SOL_SOCKET, \SO_ERROR);
                if ($error !== false && (int) $error !== 0) {
                    return false;
                }
                return true;
            }
        }

        // Fallback: peer name becomes available after TCP handshake completes.
        $peer = @\stream_socket_get_name($this->socket, true);
        return \is_string($peer) && $peer !== '';
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
        if (\PHP_SAPI !== 'cli' && \PHP_SAPI !== 'phpdbg') {
            return;
        }
        WlsLogger::info_('[PooledConnection] ' . $message);
    }

    private function recordAuthMetric(float $startTime, string $result, string $reason): void
    {
        $durationMs = (\microtime(true) - $startTime) * 1000;
        \Weline\Server\Service\Telemetry\MetricsCollector::getInstance()->recordHistogram(
            'wls_pool_auth_duration_ms',
            $durationMs,
            ['host' => $this->host, 'port' => (string)$this->port, 'result' => $result]
        );
        unset($reason);
    }

    private function recordPhaseMetric(string $phase, float $startTime, string $result): void
    {
        $durationMs = (\microtime(true) - $startTime) * 1000;
        \Weline\Server\Service\Telemetry\MetricsCollector::getInstance()->recordHistogram(
            'wls_pool_io_phase_duration_ms',
            $durationMs,
            [
                'host' => $this->host,
                'port' => (string) $this->port,
                'phase' => $phase,
                'result' => $result,
            ]
        );
        if ($result !== 'success') {
            $this->incrementMetric('wls_pool_io_phase_error_total', [
                'phase' => $phase,
                'result' => $result,
            ]);
        }
    }

    private function incrementMetric(string $name, array $labels): void
    {
        \Weline\Server\Service\Telemetry\MetricsCollector::getInstance()->incrementCounter(
            $name,
            1,
            \array_merge(['host' => $this->host, 'port' => (string)$this->port], $labels)
        );
    }

    private function registerFailure(): void
    {
        $this->consecutiveFailures++;
        $step = \min(5, $this->consecutiveFailures - 1);
        $delaySec = \min(5.0, 0.25 * (2 ** $step));
        $this->nextConnectAttemptAt = \microtime(true) + $delaySec;
    }

    private function resetFailureState(): void
    {
        $this->consecutiveFailures = 0;
        $this->nextConnectAttemptAt = 0.0;
    }

    private function detectServiceType(int $port): string
    {
        return match ($port) {
            26422, 26423 => 'Session',
            26424, 19971 => 'Memory',
            default => "Port:{$port}",
        };
    }
}
