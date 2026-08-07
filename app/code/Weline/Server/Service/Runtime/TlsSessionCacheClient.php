<?php

declare(strict_types=1);

namespace Weline\Server\Service\Runtime;

use Weline\Server\Service\Edge\Gateway\GatewayProjectStateFilesystem;

use Weline\Server\Session\Server\SessionProtocol;

/**
 * One preconnected, non-persistent Memory-sidecar channel per TLS Worker.
 *
 * Callback methods never connect, retry, resolve DNS, or log. A timeout closes
 * the channel to prevent a late NDJSON response from corrupting the next call.
 */
final class TlsSessionCacheClient
{
    private static function monotonicSeconds(): float
    {
        return \hrtime(true) / 1_000_000_000;
    }

    private const MAX_PENDING_RESPONSES = 1024;
    private const LATENCY_BUCKET_UPPER_BOUNDS_US = [50, 100, 250, 500, 1000, 2000, 5000, 10000, 20000];
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
    /** @var array<string, array{total:int,deadline_exceeded:int,fail_fast:int,latency_bucket_counts:list<int>}> */
    private array $callbackTelemetry;
    /**
     * @var array{
     *   client_read_buffer_zero:bool,
     *   client_write_buffer_zero:bool,
     *   client_write_buffer_control_supported:bool,
     *   client_tcp_nodelay:bool,
     *   sidecar_read_buffer_zero:bool,
     *   sidecar_write_buffer_zero:bool,
     *   sidecar_write_buffer_control_supported:bool,
     *   sidecar_tcp_nodelay:bool
     * }
     */
    private array $transportStatus = [
        'client_read_buffer_zero' => false,
        'client_write_buffer_zero' => false,
        'client_write_buffer_control_supported' => false,
        'client_tcp_nodelay' => false,
        'sidecar_read_buffer_zero' => false,
        'sidecar_write_buffer_zero' => false,
        'sidecar_write_buffer_control_supported' => false,
        'sidecar_tcp_nodelay' => false,
    ];
    private string $lastRequestOutcome = 'fail_fast';
    private string $lastIoOutcome = 'fail_fast';

    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly string $tokenFilePath,
        private readonly float $callbackTimeoutSeconds,
        private readonly float $readyTimeoutSeconds,
        private readonly float $reconnectCooldownSeconds,
        private readonly string $expectedConfigFingerprint = '',
        private readonly string $expectedIntegrationSha256 = '',
        ?TlsSessionCacheTokenState $tokenState = null,
    ) {
        $this->tokenState = $tokenState ?? new TlsSessionCacheTokenState();
        $emptyBuckets = \array_fill(0, \count(self::LATENCY_BUCKET_UPPER_BOUNDS_US) + 1, 0);
        $this->callbackTelemetry = [
            'get' => [
                'total' => 0,
                'deadline_exceeded' => 0,
                'fail_fast' => 0,
                'latency_bucket_counts' => $emptyBuckets,
            ],
            'put' => [
                'total' => 0,
                'deadline_exceeded' => 0,
                'fail_fast' => 0,
                'latency_bucket_counts' => $emptyBuckets,
            ],
        ];
    }

    public function ready(): bool
    {
        return $this->maintain($this->readyTimeoutSeconds, true);
    }

    public function maintain(float $maximumSeconds = 0.01, bool $allowTokenReload = false): bool
    {
        $maximumSeconds = \max(0.0001, $maximumSeconds);
        $deadline = self::monotonicSeconds() + $maximumSeconds;
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
            && self::monotonicSeconds() >= $this->connectAttemptDeadline
        ) {
            $this->tripCircuit();
            return false;
        }
        if (!$this->isConnected() && $this->connectPhase !== self::CONNECT_IDLE) {
            $this->tripCircuit();
            return false;
        }
        if (!$this->isConnected() && self::monotonicSeconds() < $this->nextReconnectAt) {
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
            if (self::monotonicSeconds() >= $deadline) {
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
        $startedAt = \hrtime(true);
        $response = $this->request(SessionProtocol::CMD_TLS_SESSION_GET, [
            'ctx' => $contextHex,
            'sid' => $sessionIdHex,
        ], $this->callbackTimeoutSeconds);
        $this->recordCallbackTelemetry('get', $startedAt);
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
        $startedAt = \hrtime(true);
        $response = $this->request(SessionProtocol::CMD_TLS_SESSION_PUT, [
            'ctx' => $contextHex,
            'sid' => $sessionIdHex,
            'der' => $derBase64,
            'created_at' => $createdAt,
            'expires_at' => $expiresAt,
        ], $this->callbackTimeoutSeconds);
        $this->recordCallbackTelemetry('put', $startedAt);

        return \is_array($response) && SessionProtocol::isSuccess($response);
    }

    /**
     * @return array{
     *   bucket_upper_bounds_us:list<int>,
     *   get:array{total:int,deadline_exceeded:int,fail_fast:int,latency_bucket_counts:list<int>},
     *   put:array{total:int,deadline_exceeded:int,fail_fast:int,latency_bucket_counts:list<int>},
     *   transport:array<string,bool>
     * }
     */
    public function callbackTelemetry(): array
    {
        return [
            'bucket_upper_bounds_us' => self::LATENCY_BUCKET_UPPER_BOUNDS_US,
            'get' => $this->callbackTelemetry['get'],
            'put' => $this->callbackTelemetry['put'],
            'transport' => $this->transportStatus,
        ];
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
        $deadline = self::monotonicSeconds() + \max(0.0001, $maximumSeconds);
        if ($this->pendingResponses > 0 && !$this->isConnected()) {
            $this->disconnect();
            $lost += $this->takeLostResponseCount();
        }
        while (\count($results) < $maximumResponses
            && $this->pendingResponses > 0
            && $this->isConnected()
            && self::monotonicSeconds() < $deadline
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
                    ? self::monotonicSeconds() + $this->pendingResponseTimeoutSeconds()
                    : 0.0;
            }
            if (\count($results) >= $maximumResponses
                || $this->pendingResponses <= 0
                || self::monotonicSeconds() >= $deadline
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
            && self::monotonicSeconds() >= $this->pendingResponseDeadline
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
        $this->transportStatus = [
            'client_read_buffer_zero' => false,
            'client_write_buffer_zero' => false,
            'client_write_buffer_control_supported' => false,
            'client_tcp_nodelay' => false,
            'sidecar_read_buffer_zero' => false,
            'sidecar_write_buffer_zero' => false,
            'sidecar_write_buffer_control_supported' => false,
            'sidecar_tcp_nodelay' => false,
        ];
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
        if ($deadline <= self::monotonicSeconds()) {
            return false;
        }
        $this->disconnect();
        $this->connectAttemptDeadline = self::monotonicSeconds() + \max(0.001, $this->readyTimeoutSeconds);
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
        $this->transportStatus = $this->tuneClientTransport($socket);
        if (!$this->transportReady($this->transportStatus, 'client')) {
            @\fclose($socket);
            $this->tripCircuit();
            return false;
        }
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
        $now = self::monotonicSeconds();
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
        for ($steps = 0; $steps < 8 && self::monotonicSeconds() < $deadline; $steps++) {
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
                $integrationSha256 = \is_array($stats) && \is_string($stats['integration_sha256'] ?? null)
                    ? \strtolower($stats['integration_sha256'])
                    : '';
                $transport = \is_array($stats['transport'] ?? null) ? $stats['transport'] : [];
                $transportKeys = \array_keys($transport);
                \sort($transportKeys, SORT_STRING);
                $expectedTransportKeys = [
                    'read_buffer_zero',
                    'tcp_nodelay',
                    'write_buffer_control_supported',
                    'write_buffer_zero',
                ];
                if (!\is_array($response)
                    || !SessionProtocol::isSuccess($response)
                    || $this->expectedConfigFingerprint === ''
                    || !\hash_equals($this->expectedConfigFingerprint, $fingerprint)
                    || ($stats['transport_schema_version'] ?? null) !== 2
                    || !\preg_match('/\A[a-f0-9]{64}\z/D', $this->expectedIntegrationSha256)
                    || !\hash_equals($this->expectedIntegrationSha256, $integrationSha256)
                    || $transportKeys !== $expectedTransportKeys
                ) {
                    $this->tripCircuit();
                    return false;
                }
                foreach ($expectedTransportKeys as $transportKey) {
                    if (!\is_bool($transport[$transportKey])) {
                        $this->tripCircuit();
                        return false;
                    }
                }
                $this->transportStatus['sidecar_read_buffer_zero'] =
                    (bool)($transport['read_buffer_zero'] ?? false);
                $this->transportStatus['sidecar_write_buffer_zero'] =
                    (bool)($transport['write_buffer_zero'] ?? false);
                $this->transportStatus['sidecar_write_buffer_control_supported'] =
                    (bool)($transport['write_buffer_control_supported'] ?? false);
                $this->transportStatus['sidecar_tcp_nodelay'] =
                    (bool)($transport['tcp_nodelay'] ?? false);
                if (!$this->transportReady($this->transportStatus, 'client')
                    || !$this->transportReady($this->transportStatus, 'sidecar')
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

    /** @param resource $socket @return array<string,bool> */
    private function tuneClientTransport($socket): array
    {
        $readBufferZero = @\stream_set_read_buffer($socket, 0) === 0;
        $writeBufferResult = @\stream_set_write_buffer($socket, 0);
        $writeBufferControlSupported = $writeBufferResult === 0;
        $writeBufferZero = $writeBufferControlSupported;
        $tcpNoDelay = false;
        $tcpLevel = \defined('SOL_TCP')
            ? (int)\constant('SOL_TCP')
            : (\defined('IPPROTO_TCP') ? (int)\constant('IPPROTO_TCP') : null);
        if ($tcpLevel !== null
            && \defined('TCP_NODELAY')
            && \function_exists('socket_import_stream')
            && \function_exists('socket_set_option')
            && \function_exists('socket_get_option')
        ) {
            $nativeSocket = @\socket_import_stream($socket);
            if ($nativeSocket !== false) {
                $option = (int)\constant('TCP_NODELAY');
                $set = @\socket_set_option($nativeSocket, $tcpLevel, $option, 1);
                $value = $set ? @\socket_get_option($nativeSocket, $tcpLevel, $option) : false;
                $tcpNoDelay = $set && (int)$value !== 0;
            }
        }

        return [
            'client_read_buffer_zero' => $readBufferZero,
            'client_write_buffer_zero' => $writeBufferZero,
            'client_write_buffer_control_supported' => $writeBufferControlSupported,
            'client_tcp_nodelay' => $tcpNoDelay,
            'sidecar_read_buffer_zero' => false,
            'sidecar_write_buffer_zero' => false,
            'sidecar_write_buffer_control_supported' => false,
            'sidecar_tcp_nodelay' => false,
        ];
    }

    /** @param array<string,bool> $status */
    private function transportReady(array $status, string $endpoint): bool
    {
        $writeControlSupported = ($status[$endpoint . '_write_buffer_control_supported'] ?? false) === true;

        return ($status[$endpoint . '_read_buffer_zero'] ?? false) === true
            && (!$writeControlSupported || ($status[$endpoint . '_write_buffer_zero'] ?? false) === true)
            && ($status[$endpoint . '_tcp_nodelay'] ?? false) === true;
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
        $remaining = $deadline - self::monotonicSeconds();
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
            $remaining = $deadline - self::monotonicSeconds();
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
            $remaining = $deadline - self::monotonicSeconds();
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
        $this->lastRequestOutcome = 'fail_fast';
        $this->lastIoOutcome = 'fail_fast';
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
            $deadline = self::monotonicSeconds() + \max(0.0005, $timeoutSeconds);
            if (!$this->writeAll($payload, $deadline)) {
                $this->lastRequestOutcome = $this->lastIoOutcome;
                if ($tripOnFailure) {
                    $this->tripCircuit();
                } else {
                    $this->disconnect();
                }
                return null;
            }
            $response = $this->readOne($deadline);
            if (!\is_array($response)) {
                $this->lastRequestOutcome = $this->lastIoOutcome;
                if ($tripOnFailure) {
                    $this->tripCircuit();
                } else {
                    $this->disconnect();
                }
                return null;
            }

            $this->lastRequestOutcome = 'success';
            return $response;
        } catch (\Throwable) {
            $this->lastRequestOutcome = 'fail_fast';
            if ($tripOnFailure) {
                $this->tripCircuit();
            } else {
                $this->disconnect();
            }
            return null;
        }
    }

    private function recordCallbackTelemetry(string $operation, int $startedAt): void
    {
        if (!isset($this->callbackTelemetry[$operation])) {
            return;
        }
        $elapsedUs = \intdiv(\max(0, \hrtime(true) - $startedAt), 1000);
        $bucket = \count(self::LATENCY_BUCKET_UPPER_BOUNDS_US);
        foreach (self::LATENCY_BUCKET_UPPER_BOUNDS_US as $index => $upperBound) {
            if ($elapsedUs <= $upperBound) {
                $bucket = $index;
                break;
            }
        }
        $this->callbackTelemetry[$operation]['total']++;
        $this->callbackTelemetry[$operation]['latency_bucket_counts'][$bucket]++;
        if ($this->lastRequestOutcome === 'deadline_exceeded') {
            $this->callbackTelemetry[$operation]['deadline_exceeded']++;
        } elseif ($this->lastRequestOutcome === 'fail_fast') {
            $this->callbackTelemetry[$operation]['fail_fast']++;
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
            $deadline = self::monotonicSeconds() + \max(0.0001, $timeoutSeconds);
            if (!$this->writeAll($payload, $deadline)) {
                $this->tripCircuit();
                return false;
            }
            $this->pendingResponses++;
            if ($this->pendingResponses === 1) {
                $this->pendingResponseDeadline = self::monotonicSeconds() + $this->pendingResponseTimeoutSeconds();
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
            $remaining = $deadline - self::monotonicSeconds();
            if ($remaining <= 0.0) {
                $this->lastIoOutcome = 'deadline_exceeded';
                return false;
            }
            if (!$this->isConnected()) {
                $this->lastIoOutcome = 'fail_fast';
                return false;
            }
            $read = null;
            $write = [$this->socket];
            $except = null;
            [$seconds, $microseconds] = self::selectTimeout($remaining);
            $ready = @\stream_select($read, $write, $except, $seconds, $microseconds);
            if ($ready === 0) {
                $this->lastIoOutcome = 'deadline_exceeded';
                return false;
            }
            if ($ready !== 1) {
                $this->lastIoOutcome = 'fail_fast';
                return false;
            }
            $written = @\fwrite($this->socket, \substr($payload, $offset));
            if (!\is_int($written) || $written <= 0) {
                $this->lastIoOutcome = 'fail_fast';
                return false;
            }
            $offset += $written;
        }

        $this->lastIoOutcome = 'success';
        return true;
    }

    /** @return array<string, mixed>|null */
    private function readOne(float $deadline): ?array
    {
        while (true) {
            $messages = SessionProtocol::extractTlsMessages($this->readBuffer, 1, $deadline);
            if ($messages === null) {
                $this->lastIoOutcome = self::monotonicSeconds() >= $deadline
                    ? 'deadline_exceeded'
                    : 'fail_fast';
                return null;
            }
            if ($messages !== []) {
                $message = $messages[0] ?? null;
                $this->lastIoOutcome = \is_array($message) ? 'success' : 'fail_fast';
                return \is_array($message) ? $message : null;
            }
            if (\strlen($this->readBuffer) > SessionProtocol::MAX_BUFFER_BYTES) {
                $this->lastIoOutcome = 'fail_fast';
                return null;
            }
            $remaining = $deadline - self::monotonicSeconds();
            if ($remaining <= 0.0) {
                $this->lastIoOutcome = 'deadline_exceeded';
                return null;
            }
            if (!$this->isConnected()) {
                $this->lastIoOutcome = 'fail_fast';
                return null;
            }
            $read = [$this->socket];
            $write = null;
            $except = null;
            [$seconds, $microseconds] = self::selectTimeout($remaining);
            $ready = @\stream_select($read, $write, $except, $seconds, $microseconds);
            if ($ready === 0) {
                $this->lastIoOutcome = 'deadline_exceeded';
                return null;
            }
            if ($ready !== 1) {
                $this->lastIoOutcome = 'fail_fast';
                return null;
            }
            $chunk = @\fread($this->socket, 65536);
            if (!\is_string($chunk) || $chunk === '') {
                $this->lastIoOutcome = 'fail_fast';
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
        $this->nextReconnectAt = self::monotonicSeconds() + $this->reconnectCooldownSeconds;
    }

    private function loadToken(): ?string
    {
        $sharedToken = $this->sharedTokenFromMemory();
        if ($sharedToken !== null) {
            return $sharedToken;
        }
        if (!\file_exists($this->tokenFilePath) && !\is_link($this->tokenFilePath)) {
            return null;
        }
        try {
            $content = GatewayProjectStateFilesystem::read(
                $this->tokenFilePath,
                4096,
                'TLS session-cache token',
            );
        } catch (\Throwable) {
            return null;
        }
        if ($content === '') {
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
