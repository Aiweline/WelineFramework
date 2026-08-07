<?php

declare(strict_types=1);

namespace Weline\Server\Shared\Connection;

use Weline\Framework\Runtime\SchedulerSystem;
use Weline\Server\Log\WlsLogger;
use Weline\Server\Session\Server\SessionProtocol;
use Weline\Server\Session\Server\SharedStateTokenStore;
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
    private static function monotonicSeconds(): float
    {
        return \hrtime(true) / 1_000_000_000;
    }

    private static function wallLogTimestamp(): string
    {
        return (new \DateTimeImmutable('now'))->format('Y-m-d H:i:s.v');
    }

    private mixed $socket = null;
    private string $buffer = '';
    private bool $authenticated = false;
    private ?string $authToken = null;
    private int $highestObservedAuthTokenVersion = 0;
    private string $highestObservedAuthTokenDigest = '';
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
        private readonly ?string $tokenAuthorityInstance = null,
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

        if (self::monotonicSeconds() < $this->nextConnectAttemptAt) {
            return false;
        }

        if ($this->logLifecycleDetails) {
            $timestamp = self::wallLogTimestamp();
            $this->log("[CONN-START] {$timestamp} Attempting connect to {$this->host}:{$this->port} ({$this->serviceType})");
        }

        $connectStart = self::monotonicSeconds();
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
            $timestamp = self::wallLogTimestamp();
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
                $timestamp = self::wallLogTimestamp();
                $this->log("[CONN-AUTH-FAIL] {$timestamp} Authentication failed ({$this->serviceType})");
            }
            $this->close();
            $this->registerFailure();
            return false;
        }

        if ($this->logLifecycleDetails) {
            $timestamp = self::wallLogTimestamp();
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

        $deadline = self::monotonicSeconds() + $this->timeout;
        $phaseStart = self::monotonicSeconds();
        $total = \strlen($payload);
        $offset = 0;
        while ($offset < $total) {
            if (self::monotonicSeconds() >= $deadline) {
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

        $deadline = self::monotonicSeconds() + $this->timeout;
        $phaseStart = self::monotonicSeconds();

        while (true) {
            $messages = SessionProtocol::extractMessages($this->buffer);
            if (!empty($messages)) {
                $this->recordPhaseMetric('read', $phaseStart, 'success');
                return $messages[0];
            }

            if (self::monotonicSeconds() >= $deadline) {
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
                $timestamp = self::wallLogTimestamp();
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
        $authStartTime = self::monotonicSeconds();
        $deadline = \min($outerDeadline, self::monotonicSeconds() + $this->timeout);

        $token = $this->loadToken();
        if ($token === null) {
            if ($this->tokenFilePath !== '') {
                $this->authenticated = false;
                $this->recordAuthMetric($authStartTime, 'failure', 'token_unavailable');
                $this->incrementMetric(
                    'wls_pool_auth_failure_total',
                    ['reason' => 'token_unavailable'],
                );
                return false;
            }
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
            $remaining = $deadline - self::monotonicSeconds();
            if ($remaining <= 0.0) {
                break;
            }
            SchedulerSystem::usleep((int)\min(
                $retryDelays[$retry],
                \max(0.0, $remaining * 1_000_000),
            ));
            $freshToken = $this->loadToken(true);

            if ($freshToken !== null
                && ($token === null || !\hash_equals($freshToken, $token))
            ) {
                // SessionServer deliberately closes a connection after an
                // invalid AUTH frame. A freshly loaded generation therefore
                // must never be retried on that rejected transport.
                if ($this->reopenTransportForAuthentication($deadline)
                    && $this->tryAuthenticateWithToken($freshToken, $deadline)
                ) {
                    $this->authenticated = true;
                    $this->recordAuthMetric($authStartTime, 'success', 'token_refresh_retry_' . ($retry + 1));
                    $this->incrementMetric('wls_pool_token_reload_total', ['reason' => 'auth_retry_' . ($retry + 1)]);
                    return true;
                }
            }

            $token = $freshToken;
        }

        $this->authenticated = false;
        $this->recordAuthMetric($authStartTime, 'failure', 'token_mismatch');
        $this->incrementMetric('wls_pool_auth_failure_total', ['reason' => 'token_mismatch']);
        return false;
    }

    private function reopenTransportForAuthentication(float $deadline): bool
    {
        $this->close();
        if (self::monotonicSeconds() >= $deadline) {
            return false;
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
            $ctx,
        );
        if (!\is_resource($socket)) {
            return false;
        }

        @\stream_set_blocking($socket, false);
        @\stream_set_timeout(
            $socket,
            (int)$this->timeout,
            (int)(($this->timeout - (int)$this->timeout) * 1_000_000),
        );
        $this->socket = $socket;
        $this->buffer = '';
        $this->authenticated = false;

        if (!$this->awaitWritable($deadline) || !$this->assertSocketConnected()) {
            $this->close();
            return false;
        }

        return true;
    }

    private function tryAuthenticateWithToken(string $token, float $deadline): bool
    {
        $remaining = $deadline - self::monotonicSeconds();
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
            if (self::monotonicSeconds() >= $deadline) {
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
            if (self::monotonicSeconds() >= $deadline) {
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
        $remaining = $deadline - self::monotonicSeconds();
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
        $remaining = $deadline - self::monotonicSeconds();
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
        // Authentication happens only while opening/reopening a transport, so
        // always re-read the bounded capability envelope. Filesystem mtimes are
        // second-resolution on supported hosts and cannot safely detect an
        // atomic same-second rotation or a removed/unsafe capability path.
        unset($forceReload);
        if ($this->tokenFilePath === '' || !\is_file($this->tokenFilePath)) {
            $this->authToken = null;
            return null;
        }
        try {
            $token = SharedStateTokenStore::readCapabilityStatePath(
                $this->tokenFilePath,
                $this->tokenAuthority(),
            );
        } catch (\Throwable) {
            $token = null;
        }
        if ($token === null) {
            $this->authToken = null;
            return null;
        }

        $version = $token['version'];
        $digest = $token['digest'];
        if ($version < $this->highestObservedAuthTokenVersion
            || ($version === $this->highestObservedAuthTokenVersion
                && $this->highestObservedAuthTokenDigest !== ''
                && !\hash_equals($this->highestObservedAuthTokenDigest, $digest))
        ) {
            $this->authToken = null;
            return null;
        }
        if ($version > $this->highestObservedAuthTokenVersion
            || $this->highestObservedAuthTokenDigest === ''
        ) {
            $this->highestObservedAuthTokenVersion = $version;
            $this->highestObservedAuthTokenDigest = $digest;
        }

        if (!$token['active']) {
            $this->authToken = null;
            return null;
        }

        $this->authToken = $token['secret'];
        return $this->authToken;
    }

    /** @return array{role:string,host:string,port:int,instance:string} */
    private function tokenAuthority(): array
    {
        $normalizedService = \strtolower(\trim($this->serviceType));
        $role = \str_contains($normalizedService, 'memory')
            ? 'memory_server'
            : 'session_server';
        $instance = \trim((string)$this->tokenAuthorityInstance);
        if ($instance === '') {
            $instance = SharedStateTokenStore::defaultInstance(
                $role,
                $this->host,
                $this->port,
            );
        }

        return [
            'role' => $role,
            'host' => $this->host,
            'port' => $this->port,
            'instance' => $instance,
        ];
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
        $durationMs = (self::monotonicSeconds() - $startTime) * 1000;
        \Weline\Server\Service\Telemetry\MetricsCollector::getInstance()->recordHistogram(
            'wls_pool_auth_duration_ms',
            $durationMs,
            ['host' => $this->host, 'port' => (string)$this->port, 'result' => $result]
        );
        unset($reason);
    }

    private function recordPhaseMetric(string $phase, float $startTime, string $result): void
    {
        $durationMs = (self::monotonicSeconds() - $startTime) * 1000;
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
        $this->nextConnectAttemptAt = self::monotonicSeconds() + $delaySec;
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
