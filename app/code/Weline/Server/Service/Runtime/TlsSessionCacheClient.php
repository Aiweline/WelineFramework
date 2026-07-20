<?php

declare(strict_types=1);

namespace Weline\Server\Service\Runtime;

use Weline\Server\Session\Server\SessionProtocol;

/**
 * One preconnected, non-persistent Memory-sidecar channel per TLS Worker.
 *
 * Callback methods never connect, retry, resolve DNS, or log. A timeout closes
 * the channel to prevent a late NDJSON response from corrupting the next call.
 */
final class TlsSessionCacheClient
{
    private const MAX_PENDING_RESPONSES = 1024;
    private const CONNECT_IDLE = 'idle';
    private const CONNECT_TCP = 'tcp_connect';
    private const CONNECT_AUTH_WRITE = 'auth_write';
    private const CONNECT_AUTH_READ = 'auth_read';
    private const CONNECT_STATS_WRITE = 'stats_write';
    private const CONNECT_STATS_READ = 'stats_read';

    /** @var resource|null */
    private $socket = null;
    private string $readBuffer = '';
    private float $nextReconnectAt = 0.0;
    private int $pendingResponses = 0;
    private int $lostResponses = 0;
    private float $pendingResponseDeadline = 0.0;
    private bool $configurationValidated = false;
    private ?string $cachedToken = null;
    private string $connectPhase = self::CONNECT_IDLE;
    private string $connectWriteBuffer = '';
    private int $connectWriteOffset = 0;
    private float $connectAttemptDeadline = 0.0;
    private readonly TlsSessionCacheTokenState $tokenState;

    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly string $tokenFilePath,
        private readonly float $callbackTimeoutSeconds,
        private readonly float $readyTimeoutSeconds,
        private readonly float $reconnectCooldownSeconds,
        private readonly string $expectedConfigFingerprint = '',
        ?TlsSessionCacheTokenState $tokenState = null,
    ) {
        $this->tokenState = $tokenState ?? new TlsSessionCacheTokenState();
    }

    public function ready(): bool
    {
        return $this->maintain($this->readyTimeoutSeconds, true);
    }

    public function maintain(float $maximumSeconds = 0.01, bool $allowTokenReload = false): bool
    {
        $maximumSeconds = \max(0.0001, $maximumSeconds);
        $deadline = \microtime(true) + $maximumSeconds;
        if ($this->configurationValidated) {
            if ($this->isConnected()) {
                return true;
            }
            $this->tripCircuit();
            return false;
        }
        if ($this->isConnected() && $this->connectPhase === self::CONNECT_IDLE) {
            $this->tripCircuit();
            return false;
        }
        if ($this->connectPhase !== self::CONNECT_IDLE
            && $this->connectAttemptDeadline > 0.0
            && \microtime(true) >= $this->connectAttemptDeadline
        ) {
            $this->tripCircuit();
            return false;
        }
        if (!$this->isConnected() && $this->connectPhase !== self::CONNECT_IDLE) {
            $this->tripCircuit();
            return false;
        }
        if (!$this->isConnected() && \microtime(true) < $this->nextReconnectAt) {
            return false;
        }
        if (!$this->isConnected() && $this->cachedToken === null) {
            $this->cachedToken = $this->sharedTokenFromMemory();
            if ($this->cachedToken === null) {
                if (!$allowTokenReload) {
                    return false;
                }
                // PHP has no portable non-blocking filesystem read. Token
                // reload is restricted to startup or the Worker's explicit
                // low-frequency recovery lane, never the 500us hot path.
                $this->cachedToken = $this->loadToken();
            }
            if ($this->cachedToken === null) {
                $this->tripCircuit();
                return false;
            }
            if (\microtime(true) >= $deadline) {
                return false;
            }
        }
        if (!$this->isConnected() && !$this->beginConnection($deadline)) {
            return false;
        }

        return $this->advanceConnection($deadline);
    }

    /** @return array{der:string,created_at:int,expires_at:int}|null */
    public function get(string $contextHex, string $sessionIdHex): ?array
    {
        $response = $this->request(SessionProtocol::CMD_TLS_SESSION_GET, [
            'ctx' => $contextHex,
            'sid' => $sessionIdHex,
        ], $this->callbackTimeoutSeconds);
        if (!\is_array($response) || !SessionProtocol::isSuccess($response)) {
            return null;
        }
        $data = SessionProtocol::getData($response);
        if (!\is_array($data)) {
            return null;
        }

        return [
            'der' => (string)($data['der'] ?? ''),
            'created_at' => (int)($data['created_at'] ?? 0),
            'expires_at' => (int)($data['expires_at'] ?? 0),
        ];
    }

    public function put(
        string $contextHex,
        string $sessionIdHex,
        string $derBase64,
        int $createdAt,
        int $expiresAt
    ): bool {
        $response = $this->request(SessionProtocol::CMD_TLS_SESSION_PUT, [
            'ctx' => $contextHex,
            'sid' => $sessionIdHex,
            'der' => $derBase64,
            'created_at' => $createdAt,
            'expires_at' => $expiresAt,
        ], $this->callbackTimeoutSeconds);

        return \is_array($response) && SessionProtocol::isSuccess($response);
    }

    public function remove(string $contextHex, string $sessionIdHex): void
    {
        $this->request(SessionProtocol::CMD_TLS_SESSION_REMOVE, [
            'ctx' => $contextHex,
            'sid' => $sessionIdHex,
        ], $this->callbackTimeoutSeconds);
    }

    public function sendPut(
        string $contextHex,
        string $sessionIdHex,
        string $derBase64,
        int $createdAt,
        int $expiresAt,
        float $timeoutSeconds
    ): bool {
        return $this->sendOnly(SessionProtocol::CMD_TLS_SESSION_PUT, [
            'ctx' => $contextHex,
            'sid' => $sessionIdHex,
            'der' => $derBase64,
            'created_at' => $createdAt,
            'expires_at' => $expiresAt,
        ], $timeoutSeconds);
    }

    public function sendRemove(string $contextHex, string $sessionIdHex, float $timeoutSeconds): bool
    {
        return $this->sendOnly(SessionProtocol::CMD_TLS_SESSION_REMOVE, [
            'ctx' => $contextHex,
            'sid' => $sessionIdHex,
        ], $timeoutSeconds);
    }

    /** @return array{results:list<bool>,lost:int} */
    public function drainPendingResponses(
        int $maximumResponses = 256,
        float $maximumSeconds = 0.001
    ): array
    {
        $results = [];
        $lost = $this->takeLostResponseCount();
        $maximumResponses = \max(1, \min(self::MAX_PENDING_RESPONSES, $maximumResponses));
        $deadline = \microtime(true) + \max(0.0001, $maximumSeconds);
        if ($this->pendingResponses > 0 && !$this->isConnected()) {
            $this->disconnect();
            $lost += $this->takeLostResponseCount();
        }
        while (\count($results) < $maximumResponses
            && $this->pendingResponses > 0
            && $this->isConnected()
            && \microtime(true) < $deadline
        ) {
            $messages = SessionProtocol::extractTlsMessages(
                $this->readBuffer,
                \min($maximumResponses - \count($results), $this->pendingResponses),
                $deadline,
            );
            if ($messages === null) {
                $this->tripCircuit();
                $lost += $this->takeLostResponseCount();
                break;
            }
            foreach ($messages as $message) {
                $results[] = SessionProtocol::isSuccess($message);
                $this->pendingResponses = \max(0, $this->pendingResponses - 1);
                $this->pendingResponseDeadline = $this->pendingResponses > 0
                    ? \microtime(true) + $this->pendingResponseTimeoutSeconds()
                    : 0.0;
            }
            if (\count($results) >= $maximumResponses
                || $this->pendingResponses <= 0
                || \microtime(true) >= $deadline
            ) {
                break;
            }

            $read = [$this->socket];
            $write = null;
            $except = null;
            $ready = @\stream_select($read, $write, $except, 0, 0);
            if ($ready !== 1) {
                break;
            }
            $chunk = @\fread($this->socket, 65536);
            if (!\is_string($chunk) || $chunk === '') {
                if (@\feof($this->socket)) {
                    $this->tripCircuit();
                    $lost += $this->takeLostResponseCount();
                }
                break;
            }
            $this->readBuffer .= $chunk;
        }
        // Consume buffered or immediately readable ACKs before declaring a
        // stall. Maintenance may resume after the ACK already arrived.
        if ($this->pendingResponses > 0
            && $this->pendingResponseDeadline > 0.0
            && \microtime(true) >= $this->pendingResponseDeadline
        ) {
            $this->tripCircuit();
            $lost += $this->takeLostResponseCount();
        }

        return ['results' => $results, 'lost' => $lost];
    }

    public function pendingResponseCount(): int
    {
        return $this->pendingResponses;
    }

    public function connected(): bool
    {
        return $this->configurationValidated && $this->isConnected();
    }

    public function needsTokenReload(): bool
    {
        return !$this->configurationValidated && $this->cachedToken === null;
    }

    public function disconnect(): void
    {
        if ($this->pendingResponses > 0) {
            $this->lostResponses = \min(
                \PHP_INT_MAX,
                $this->lostResponses + $this->pendingResponses,
            );
        }
        if (\is_resource($this->socket)) {
            @\fclose($this->socket);
        }
        $this->socket = null;
        $this->readBuffer = '';
        $this->pendingResponses = 0;
        $this->pendingResponseDeadline = 0.0;
        $this->configurationValidated = false;
        $this->connectPhase = self::CONNECT_IDLE;
        $this->connectWriteBuffer = '';
        $this->connectWriteOffset = 0;
        $this->connectAttemptDeadline = 0.0;
    }

    private function takeLostResponseCount(): int
    {
        $lost = $this->lostResponses;
        $this->lostResponses = 0;

        return $lost;
    }

    private function beginConnection(float $deadline): bool
    {
        if ($deadline <= \microtime(true)) {
            return false;
        }
        $this->disconnect();
        $this->connectAttemptDeadline = \microtime(true) + \max(0.001, $this->readyTimeoutSeconds);
        if ($this->port <= 0 || $this->host === '' || $this->tokenFilePath === '') {
            $this->tripCircuit();
            return false;
        }
        if ($this->cachedToken === null) {
            $this->tripCircuit();
            return false;
        }
        $errno = 0;
        $errstr = '';
        $context = @\stream_context_create(['socket' => ['tcp_nodelay' => true]]);
        $socket = @\stream_socket_client(
            'tcp://' . $this->host . ':' . $this->port,
            $errno,
            $errstr,
            0.0,
            \STREAM_CLIENT_CONNECT | \STREAM_CLIENT_ASYNC_CONNECT,
            $context,
        );
        if (!\is_resource($socket)) {
            $this->tripCircuit();
            return false;
        }
        @\stream_set_blocking($socket, false);
        $this->socket = $socket;
        $this->readBuffer = '';
        try {
            $this->prepareConnectWrite(SessionProtocol::CMD_AUTH, [
                'token' => $this->cachedToken,
                'channel' => 'tls_session_cache',
            ]);
        } catch (\Throwable) {
            $this->tripCircuit();
            return false;
        }
        $this->connectPhase = self::CONNECT_TCP;

        return true;
    }

    private function advanceConnection(float $deadline): bool
    {
        $now = \microtime(true);
        if ($this->connectAttemptDeadline > 0.0 && $now >= $this->connectAttemptDeadline) {
            $this->tripCircuit();
            return false;
        }
        if ($this->connectAttemptDeadline > 0.0) {
            $deadline = \min($deadline, $this->connectAttemptDeadline);
        }
        if ($deadline <= $now) {
            return false;
        }
        for ($steps = 0; $steps < 8 && \microtime(true) < $deadline; $steps++) {
            if ($this->connectPhase === self::CONNECT_TCP) {
                $connected = $this->progressConnectTcp($deadline);
                if ($connected === null) {
                    $this->tripCircuit();
                    return false;
                }
                if ($connected === false) {
                    return false;
                }
                $this->connectPhase = self::CONNECT_AUTH_WRITE;
                continue;
            }

            if ($this->connectPhase === self::CONNECT_AUTH_WRITE
                || $this->connectPhase === self::CONNECT_STATS_WRITE
            ) {
                $written = $this->progressConnectWrite($deadline);
                if ($written === null) {
                    $this->tripCircuit();
                    return false;
                }
                if ($written === false) {
                    return false;
                }
                $this->connectPhase = $this->connectPhase === self::CONNECT_AUTH_WRITE
                    ? self::CONNECT_AUTH_READ
                    : self::CONNECT_STATS_READ;
                continue;
            }

            if ($this->connectPhase === self::CONNECT_AUTH_READ) {
                $response = $this->readConnectResponse($deadline);
                if ($response === false) {
                    return false;
                }
                if (!\is_array($response)) {
                    $this->tripCircuit();
                    return false;
                }
                if (!SessionProtocol::isSuccess($response)) {
                    $rejectedToken = $this->cachedToken;
                    $this->cachedToken = null;
                    $this->invalidateSharedToken($rejectedToken);
                    $this->tripCircuit();
                    return false;
                }
                try {
                    $this->prepareConnectWrite(SessionProtocol::CMD_TLS_SESSION_STATS, []);
                } catch (\Throwable) {
                    $this->tripCircuit();
                    return false;
                }
                $this->connectPhase = self::CONNECT_STATS_WRITE;
                continue;
            }

            if ($this->connectPhase === self::CONNECT_STATS_READ) {
                $response = $this->readConnectResponse($deadline);
                if ($response === false) {
                    return false;
                }
                $stats = \is_array($response) ? SessionProtocol::getData($response) : null;
                $fingerprint = \is_array($stats) && \is_string($stats['config_fingerprint'] ?? null)
                    ? $stats['config_fingerprint']
                    : '';
                if (!\is_array($response)
                    || !SessionProtocol::isSuccess($response)
                    || $this->expectedConfigFingerprint === ''
                    || !\hash_equals($this->expectedConfigFingerprint, $fingerprint)
                ) {
                    $this->tripCircuit();
                    return false;
                }
                $this->configurationValidated = true;
                $this->connectPhase = self::CONNECT_IDLE;
                $this->connectWriteBuffer = '';
                $this->connectWriteOffset = 0;
                $this->connectAttemptDeadline = 0.0;
                $this->nextReconnectAt = 0.0;
                return true;
            }

            $this->tripCircuit();
            return false;
        }

        return false;
    }

    /** @param array<string, mixed> $params */
    private function prepareConnectWrite(string $command, array $params): void
    {
        $this->connectWriteBuffer = \json_encode(
            ['cmd' => $command] + $params,
            \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR,
        ) . "\n";
        $this->connectWriteOffset = 0;
    }

    private function progressConnectTcp(float $deadline): ?bool
    {
        $remaining = $deadline - \microtime(true);
        if ($remaining <= 0.0) {
            return false;
        }
        if (!\is_resource($this->socket)) {
            return null;
        }
        $read = null;
        $write = [$this->socket];
        $except = [$this->socket];
        [$seconds, $microseconds] = self::selectTimeout($remaining);
        $ready = @\stream_select($read, $write, $except, $seconds, $microseconds);
        if ($ready === 0) {
            return false;
        }
        if ($ready === false) {
            return null;
        }
        if (!\function_exists('socket_import_stream')
            || !\function_exists('socket_get_option')
            || !\defined('SOL_SOCKET')
            || !\defined('SO_ERROR')
        ) {
            return null;
        }
        $nativeSocket = @\socket_import_stream($this->socket);
        $socketError = $nativeSocket !== false
            ? @\socket_get_option($nativeSocket, \SOL_SOCKET, \SO_ERROR)
            : false;
        if (!\is_int($socketError) || $socketError !== 0) {
            return null;
        }
        $peer = @\stream_socket_get_name($this->socket, true);

        if (\is_string($peer) && $peer !== '') {
            return true;
        }

        return false;
    }

    private function progressConnectWrite(float $deadline): ?bool
    {
        $length = \strlen($this->connectWriteBuffer);
        while ($this->connectWriteOffset < $length) {
            $remaining = $deadline - \microtime(true);
            if ($remaining <= 0.0) {
                return false;
            }
            if (!$this->isConnected()) {
                return null;
            }
            $read = null;
            $write = [$this->socket];
            $except = null;
            [$seconds, $microseconds] = self::selectTimeout($remaining);
            $ready = @\stream_select($read, $write, $except, $seconds, $microseconds);
            if ($ready === 0) {
                return false;
            }
            if ($ready !== 1) {
                return null;
            }
            $written = @\fwrite(
                $this->socket,
                \substr($this->connectWriteBuffer, $this->connectWriteOffset),
            );
            if (!\is_int($written) || $written <= 0) {
                return @\feof($this->socket) ? null : false;
            }
            $this->connectWriteOffset += $written;
        }

        return true;
    }

    /** @return array<string, mixed>|false|null False means the current time slice expired. */
    private function readConnectResponse(float $deadline): array|false|null
    {
        while (true) {
            $messages = SessionProtocol::extractTlsMessages($this->readBuffer, 1, $deadline);
            if ($messages === null) {
                return null;
            }
            if ($messages !== []) {
                return \is_array($messages[0] ?? null) ? $messages[0] : null;
            }
            $remaining = $deadline - \microtime(true);
            if ($remaining <= 0.0) {
                return false;
            }
            if (!$this->isConnected()) {
                return null;
            }
            $read = [$this->socket];
            $write = null;
            $except = null;
            [$seconds, $microseconds] = self::selectTimeout($remaining);
            $ready = @\stream_select($read, $write, $except, $seconds, $microseconds);
            if ($ready === 0) {
                return false;
            }
            if ($ready !== 1) {
                return null;
            }
            $chunk = @\fread($this->socket, 65536);
            if (!\is_string($chunk) || $chunk === '') {
                return @\feof($this->socket) ? null : false;
            }
            $this->readBuffer .= $chunk;
        }
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>|null
     */
    private function request(
        string $command,
        array $params,
        float $timeoutSeconds,
        bool $tripOnFailure = true
    ): ?array {
        if (!$this->configurationValidated
            || !$this->isConnected()
            || $this->pendingResponses !== 0
        ) {
            return null;
        }
        try {
            $payload = \json_encode(
                ['cmd' => $command] + $params,
                \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR,
            ) . "\n";
            $deadline = \microtime(true) + \max(0.0005, $timeoutSeconds);
            if (!$this->writeAll($payload, $deadline)) {
                if ($tripOnFailure) {
                    $this->tripCircuit();
                } else {
                    $this->disconnect();
                }
                return null;
            }
            $response = $this->readOne($deadline);
            if (!\is_array($response)) {
                if ($tripOnFailure) {
                    $this->tripCircuit();
                } else {
                    $this->disconnect();
                }
                return null;
            }

            return $response;
        } catch (\Throwable) {
            if ($tripOnFailure) {
                $this->tripCircuit();
            } else {
                $this->disconnect();
            }
            return null;
        }
    }

    /** @param array<string, mixed> $params */
    private function sendOnly(string $command, array $params, float $timeoutSeconds): bool
    {
        if (!$this->configurationValidated
            || !$this->isConnected()
            || $this->pendingResponses >= self::MAX_PENDING_RESPONSES
        ) {
            return false;
        }
        try {
            $payload = \json_encode(
                ['cmd' => $command] + $params,
                \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR,
            ) . "\n";
            $deadline = \microtime(true) + \max(0.0001, $timeoutSeconds);
            if (!$this->writeAll($payload, $deadline)) {
                $this->tripCircuit();
                return false;
            }
            $this->pendingResponses++;
            if ($this->pendingResponses === 1) {
                $this->pendingResponseDeadline = \microtime(true) + $this->pendingResponseTimeoutSeconds();
            }

            return true;
        } catch (\Throwable) {
            $this->tripCircuit();
            return false;
        }
    }

    private function writeAll(string $payload, float $deadline): bool
    {
        $offset = 0;
        $length = \strlen($payload);
        while ($offset < $length) {
            $remaining = $deadline - \microtime(true);
            if ($remaining <= 0.0 || !$this->isConnected()) {
                return false;
            }
            $read = null;
            $write = [$this->socket];
            $except = null;
            [$seconds, $microseconds] = self::selectTimeout($remaining);
            $ready = @\stream_select($read, $write, $except, $seconds, $microseconds);
            if ($ready !== 1) {
                return false;
            }
            $written = @\fwrite($this->socket, \substr($payload, $offset));
            if (!\is_int($written) || $written <= 0) {
                return false;
            }
            $offset += $written;
        }

        return true;
    }

    /** @return array<string, mixed>|null */
    private function readOne(float $deadline): ?array
    {
        while (true) {
            $messages = SessionProtocol::extractTlsMessages($this->readBuffer, 1, $deadline);
            if ($messages === null) {
                return null;
            }
            if ($messages !== []) {
                return \is_array($messages[0] ?? null) ? $messages[0] : null;
            }
            if (\strlen($this->readBuffer) > SessionProtocol::MAX_BUFFER_BYTES) {
                return null;
            }
            $remaining = $deadline - \microtime(true);
            if ($remaining <= 0.0 || !$this->isConnected()) {
                return null;
            }
            $read = [$this->socket];
            $write = null;
            $except = null;
            [$seconds, $microseconds] = self::selectTimeout($remaining);
            $ready = @\stream_select($read, $write, $except, $seconds, $microseconds);
            if ($ready !== 1) {
                return null;
            }
            $chunk = @\fread($this->socket, 65536);
            if (!\is_string($chunk) || $chunk === '') {
                return null;
            }
            $this->readBuffer .= $chunk;
        }
    }

    private function isConnected(): bool
    {
        return \is_resource($this->socket) && !@\feof($this->socket);
    }

    private function tripCircuit(): void
    {
        $this->disconnect();
        $this->nextReconnectAt = \microtime(true) + $this->reconnectCooldownSeconds;
    }

    private function loadToken(): ?string
    {
        $sharedToken = $this->sharedTokenFromMemory();
        if ($sharedToken !== null) {
            return $sharedToken;
        }
        if (!\is_file($this->tokenFilePath)) {
            return null;
        }
        $content = @\file_get_contents($this->tokenFilePath);
        if (!\is_string($content) || $content === '') {
            return null;
        }
        $token = \explode(':', \trim($content), 2)[0] ?? '';

        if ($token === '') {
            return null;
        }

        return $this->tokenState->remember($token);
    }

    private function sharedTokenFromMemory(): ?string
    {
        $token = $this->tokenState->current();

        return \is_string($token) && $token !== '' ? $token : null;
    }

    private function invalidateSharedToken(?string $rejectedToken): void
    {
        if (!\is_string($rejectedToken) || $rejectedToken === '') {
            return;
        }
        $this->tokenState->invalidate($rejectedToken);
    }

    private function pendingResponseTimeoutSeconds(): float
    {
        return \max(0.05, \min(5.0, $this->readyTimeoutSeconds));
    }

    /** @return array{0:int,1:int} */
    private static function selectTimeout(float $seconds): array
    {
        $seconds = \max(0.0, $seconds);
        $whole = (int)$seconds;
        $microseconds = (int)(($seconds - $whole) * 1000000);

        return [$whole, \max(0, \min(999999, $microseconds))];
    }

    public function __destruct()
    {
        $this->disconnect();
    }
}
