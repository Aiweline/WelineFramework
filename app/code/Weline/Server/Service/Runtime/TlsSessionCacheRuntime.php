<?php

declare(strict_types=1);

namespace Weline\Server\Service\Runtime;

/**
 * PHP 8.6 OpenSSL external session-cache callbacks for WLS defer-SSL streams.
 *
 * No PHP 8.6-only type appears in a declaration, so PHP 8.4 can parse and
 * autoload this file while the feature remains disabled.
 */
final class TlsSessionCacheRuntime
{
    private const SESSION_CLASS = 'Openssl\\Session';
    private const EXCEPTION_CLASS = 'Openssl\\OpensslException';
    private const CONTEXT_SCHEMA = 'wls-tls-session-context-v1';
    private const MAX_CONTEXT_OPTIONS = 1024;
    private const MAX_PENDING_WRITES = 4096;
    private const MAX_PENDING_WRITE_BYTES = 8_388_608;

    private TlsSessionCacheClient $client;
    private TlsSessionCacheClient $writerClient;

    /** @var array<string, array<string, mixed>> */
    private array $contextOptions = [];

    /** @var array<string, string> */
    private array $certificateDigests = [];

    /** @var array<string, array{session:object,expires_at:int}> */
    private array $localSessions = [];

    /**
     * @var array<string, array{op:string,ctx:string,sid:string,der?:string,created_at?:int,expires_at?:int,bytes:int}>
     */
    private array $pendingWrites = [];
    private int $pendingWriteBytes = 0;

    /**
     * Operations written to the sidecar but not yet confirmed by an ordered ACK.
     *
     * @var array<int, array{op:string,ctx:string,sid:string,der?:string,created_at?:int,expires_at?:int,bytes:int,queue_key:string}>
     */
    private array $inflightWrites = [];
    private int $inflightWriteBytes = 0;
    private int $droppedWriteCount = 0;
    private bool $writerMaintenanceFirst = false;

    private int $newCount = 0;
    private int $getCount = 0;
    private int $localHitCount = 0;
    private int $sharedHitCount = 0;
    private int $failureCount = 0;
    private int $actualResumedCount = 0;
    private int $actualFullHandshakeCount = 0;
    private int $reuseObservationMissingCount = 0;
    private readonly string $configurationSha256;
    private readonly string $runtimeIdentitySha256;

    public function __construct(
        private readonly TlsSessionCacheConfig $config,
        string $memoryHost,
        int $memoryPort,
        string $memoryTokenFilePath,
        string $instanceName,
        string $publicOrigin,
        private readonly string $tlsPolicyIdentity,
    ) {
        if (!$config->enabled()) {
            throw new \InvalidArgumentException('TLS external session cache runtime cannot be constructed while mode is off.');
        }
        self::assertApiAvailable();
        if (!self::isLoopbackHost($memoryHost)) {
            throw new \InvalidArgumentException(
                'wls.ssl.session_cache external mode requires a loopback Memory sidecar endpoint.'
            );
        }
        if ($memoryPort <= 0 || $memoryPort > 65535) {
            throw new \InvalidArgumentException('TLS external session cache received an invalid Memory sidecar port.');
        }
        $this->configurationSha256 = $config->sha256();
        $this->runtimeIdentitySha256 = (new TlsSessionResumptionEvidenceStore())->runtimeIdentitySha256();

        $projectIdentity = \defined('BP')
            ? \Weline\Server\Service\MasterProcess::getProjectIdentityHash()
            : '';
        $this->scopeIdentity = \hash('sha256', \implode("\0", [
            self::CONTEXT_SCHEMA,
            $projectIdentity,
            \trim($instanceName),
            \strtolower(\trim($publicOrigin)),
        ]));
        $storeConfigFingerprint = \Weline\Server\Session\Server\TlsSessionCacheStore::expectedConfigurationFingerprint(
            $config->maxEntries,
            $config->maxTotalBytes,
            $config->maxSessionBytes,
            $config->timeoutSeconds,
            (string)\ini_get('memory_limit'),
        );
        $tokenState = new TlsSessionCacheTokenState();
        $this->client = new TlsSessionCacheClient(
            $memoryHost,
            $memoryPort,
            $memoryTokenFilePath,
            $config->callbackTimeoutSeconds,
            $config->readyTimeoutSeconds,
            $config->reconnectCooldownSeconds,
            $storeConfigFingerprint,
            $tokenState,
        );
        $this->writerClient = new TlsSessionCacheClient(
            $memoryHost,
            $memoryPort,
            $memoryTokenFilePath,
            $config->callbackTimeoutSeconds,
            $config->readyTimeoutSeconds,
            $config->reconnectCooldownSeconds,
            $storeConfigFingerprint,
            $tokenState,
        );
    }

    private readonly string $scopeIdentity;

    public static function apiAvailable(): bool
    {
        if (\PHP_VERSION_ID < 80600
            || !\extension_loaded('openssl')
            || !\extension_loaded('sockets')
            || !\function_exists('socket_import_stream')
            || !\function_exists('socket_get_option')
            || !\defined('SOL_SOCKET')
            || !\defined('SO_ERROR')
        ) {
            return false;
        }
        if (!\class_exists(self::SESSION_CLASS, false)
            || !\class_exists(self::EXCEPTION_CLASS, false)
            || !\property_exists(self::SESSION_CLASS, 'id')
        ) {
            return false;
        }
        foreach (['export', 'import', 'isResumable', 'getTimeout', 'getCreatedAt', 'getProtocol'] as $method) {
            if (!\method_exists(self::SESSION_CLASS, $method)) {
                return false;
            }
        }

        return \defined('OPENSSL_ENCODING_DER');
    }

    public static function assertApiAvailable(): void
    {
        if (!self::apiAvailable()) {
            throw new \RuntimeException(
                'wls.ssl.session_cache=external requires PHP 8.6 OpenSSL Stream callbacks, Openssl\\Session, '
                . 'and the PHP sockets extension with SO_ERROR support; install a compatible prebuilt PHP 8.6 runtime '
                . 'or disable the feature. WLS does not compile a protocol stack at startup.'
            );
        }
    }

    public function ready(): bool
    {
        return $this->client->ready() && $this->writerClient->ready();
    }

    public function maintain(float $maximumSeconds = 0.01, bool $allowTokenReload = false): bool
    {
        $maximumSeconds = \max(0.0001, $maximumSeconds);
        $deadline = \microtime(true) + $maximumSeconds;
        $readerReady = $this->client->connected();
        $writerReady = $this->writerClient->connected();

        if (!$readerReady && !$writerReady) {
            $writerFirst = $this->writerMaintenanceFirst;
            $this->writerMaintenanceFirst = !$this->writerMaintenanceFirst;
            $firstBudget = \min($maximumSeconds, \max(0.0001, $maximumSeconds / 2));
            if ($writerFirst) {
                $writerReady = $this->writerClient->maintain($firstBudget, $allowTokenReload);
            } else {
                $readerReady = $this->client->maintain($firstBudget, $allowTokenReload);
            }
            $remaining = $deadline - \microtime(true);
            if ($remaining >= 0.0001) {
                if ($writerFirst) {
                    $readerReady = $this->client->maintain($remaining, $allowTokenReload);
                } else {
                    $writerReady = $this->writerClient->maintain($remaining, $allowTokenReload);
                }
            }
        } elseif (!$readerReady) {
            $remaining = $deadline - \microtime(true);
            if ($remaining >= 0.0001) {
                $writerHasWork = $this->pendingWrites !== []
                    || $this->inflightWrites !== []
                    || $this->writerClient->pendingResponseCount() > 0;
                $readerBudget = $writerHasWork
                    ? \min($remaining, \max(0.0001, $maximumSeconds / 2))
                    : $remaining;
                $readerReady = $this->client->maintain($readerBudget, $allowTokenReload);
            }
        } elseif (!$writerReady) {
            $remaining = $deadline - \microtime(true);
            if ($remaining >= 0.0001) {
                $writerReady = $this->writerClient->maintain($remaining, $allowTokenReload);
            }
        }

        $remaining = $deadline - \microtime(true);
        if ($writerReady && $remaining >= 0.0001) {
            $this->flushPendingWrites($remaining);
        }

        return $readerReady && $writerReady;
    }

    public function disconnect(): void
    {
        $this->client->disconnect();
        $this->writerClient->disconnect();
    }

    /**
     * @param array{local_cert?:string,local_pk?:string} $certificatePair
     * @return array<string, mixed>
     */
    public function streamContextOptions(string $effectiveSni, array $certificatePair): array
    {
        $host = \strtolower(\rtrim(\trim($effectiveSni), '.'));
        if ($host === '') {
            $host = '(default)';
        }
        $certificatePath = (string)($certificatePair['local_cert'] ?? '');
        $certificateDigest = $this->certificateIdentityDigest($certificatePath);
        $cacheKey = \hash('sha256', \implode("\0", [
            $host,
            $certificateDigest,
            $this->tlsPolicyIdentity,
            $this->config->contextEpoch,
        ]));
        if (isset($this->contextOptions[$cacheKey])) {
            $options = $this->contextOptions[$cacheKey];
            unset($this->contextOptions[$cacheKey]);
            $this->contextOptions[$cacheKey] = $options;
            return $options;
        }

        // OpenSSL SSL_MAX_SID_CTX_LENGTH is 32 bytes. Use raw SHA-256, not 64-byte hex.
        $contextRaw = \hash('sha256', \implode("\0", [
            self::CONTEXT_SCHEMA,
            $this->scopeIdentity,
            $host,
            $certificateDigest,
            $this->tlsPolicyIdentity,
            $this->config->contextEpoch,
        ]), true);
        $contextHex = \bin2hex($contextRaw);

        while (\count($this->contextOptions) >= self::MAX_CONTEXT_OPTIONS) {
            $oldestKey = \array_key_first($this->contextOptions);
            if (!\is_string($oldestKey)) {
                break;
            }
            unset($this->contextOptions[$oldestKey]);
        }

        return $this->contextOptions[$cacheKey] = [
            'session_id_context' => $contextRaw,
            // PHP's external-cache branch currently ignores the OpenSSL
            // session_timeout context option. timeoutSeconds is enforced as
            // an absolute WLS store/local-cache TTL ceiling instead.
            'num_tickets' => $this->config->numTickets,
            'session_new_cb' => function ($stream, $session) use ($contextHex): void {
                try {
                    $this->storeSession($contextHex, $session);
                } catch (\Throwable) {
                    $this->failureCount++;
                }
            },
            'session_get_cb' => function ($stream, string $sessionId) use ($contextHex) {
                try {
                    return $this->loadSession($contextHex, $sessionId);
                } catch (\Throwable) {
                    $this->failureCount++;
                    return null;
                }
            },
            'session_remove_cb' => function ($stream, string $sessionId) use ($contextHex): void {
                try {
                    $this->removeSession($contextHex, $sessionId);
                } catch (\Throwable) {
                    $this->failureCount++;
                }
            },
        ];
    }

    public function clearContextCache(): void
    {
        $this->contextOptions = [];
        $this->certificateDigests = [];
    }

    /** @return array<string, int|string> */
    public function counters(): array
    {
        return [
            'configuration_sha256' => $this->configurationSha256,
            'runtime_identity_sha256' => $this->runtimeIdentitySha256,
            'new' => $this->newCount,
            'get' => $this->getCount,
            'local_hit' => $this->localHitCount,
            'shared_hit' => $this->sharedHitCount,
            'failure' => $this->failureCount,
            'local_entries' => \count($this->localSessions),
            'pending_writes' => \count($this->pendingWrites),
            'pending_write_bytes' => $this->pendingWriteBytes,
            'inflight_writes' => \count($this->inflightWrites),
            'inflight_write_bytes' => $this->inflightWriteBytes,
            'writer_pending_responses' => $this->writerClient->pendingResponseCount(),
            'dropped_writes' => $this->droppedWriteCount,
            'actual_resumed' => $this->actualResumedCount,
            'actual_full_handshake' => $this->actualFullHandshakeCount,
            'reuse_observation_missing' => $this->reuseObservationMissingCount,
        ];
    }

    /** @param resource $stream */
    public function recordHandshakeResult($stream): ?bool
    {
        $metadata = \is_resource($stream) ? @\stream_get_meta_data($stream) : null;
        $value = \is_array($metadata) && \is_array($metadata['crypto'] ?? null)
            ? ($metadata['crypto']['session_reused'] ?? null)
            : null;
        $reused = \is_bool($value) ? $value : (\is_int($value) ? $value !== 0 : null);
        if ($reused === true) {
            $this->actualResumedCount++;
        } elseif ($reused === false) {
            $this->actualFullHandshakeCount++;
        } else {
            $this->reuseObservationMissingCount++;
        }
        \Weline\Server\Service\WlsWorkerGlobals::recordTlsSessionHandshake($reused);

        return $reused;
    }

    public function needsMaintenance(): bool
    {
        return $this->pendingWrites !== []
            || $this->inflightWrites !== []
            || $this->writerClient->pendingResponseCount() > 0
            || !$this->client->connected()
            || !$this->writerClient->connected();
    }

    public function needsTokenReload(): bool
    {
        return $this->client->needsTokenReload() || $this->writerClient->needsTokenReload();
    }

    public function hasPendingWrites(): bool
    {
        return $this->pendingWrites !== []
            || $this->inflightWrites !== []
            || $this->writerClient->pendingResponseCount() > 0;
    }

    /**
     * Flush queued new/remove operations after OpenSSL has returned from its callback.
     */
    public function flushPendingWrites(float $maximumSeconds = 0.001): bool
    {
        $deadline = \microtime(true) + \max(0.0001, $maximumSeconds);
        $this->drainWriterResponses($deadline);

        while ($this->pendingWrites !== [] && \microtime(true) < $deadline) {
            $key = \array_key_first($this->pendingWrites);
            if (!\is_string($key)) {
                break;
            }
            $operation = $this->pendingWrites[$key];
            $remaining = $deadline - \microtime(true);
            if ($remaining < 0.0001) {
                break;
            }
            $sent = ($operation['op'] ?? '') === 'put'
                ? $this->writerClient->sendPut(
                    $operation['ctx'],
                    $operation['sid'],
                    (string)($operation['der'] ?? ''),
                    (int)($operation['created_at'] ?? 0),
                    (int)($operation['expires_at'] ?? 0),
                    $remaining,
                )
                : $this->writerClient->sendRemove(
                    $operation['ctx'],
                    $operation['sid'],
                    $remaining,
                );
            if (!$sent) {
                $before = \count($this->inflightWrites);
                $this->drainWriterResponses($deadline);
                if (!$this->writerClient->connected()
                    || \count($this->inflightWrites) === $before
                ) {
                    break;
                }
                continue;
            }
            $this->pendingWriteBytes = \max(0, $this->pendingWriteBytes - $operation['bytes']);
            unset($this->pendingWrites[$key]);
            $operation['queue_key'] = $key;
            $this->inflightWrites[] = $operation;
            $this->inflightWriteBytes += $operation['bytes'];

            if ($this->writerClient->pendingResponseCount() >= 256) {
                $this->drainWriterResponses($deadline);
            }
        }
        if ($this->inflightWrites !== [] && \microtime(true) < $deadline) {
            $this->drainWriterResponses($deadline);
        }

        return $this->pendingWrites === [] && $this->inflightWrites === [];
    }

    private function drainWriterResponses(float $deadline): void
    {
        $remaining = $deadline - \microtime(true);
        if ($remaining < 0.0001) {
            return;
        }
        $drain = $this->writerClient->drainPendingResponses(256, $remaining);
        foreach ($drain['results'] as $success) {
            $index = \array_key_first($this->inflightWrites);
            if (!\is_int($index)) {
                $this->failureCount++;
                break;
            }
            $operation = $this->inflightWrites[$index];
            unset($this->inflightWrites[$index]);
            $this->inflightWriteBytes = \max(
                0,
                $this->inflightWriteBytes - (int)($operation['bytes'] ?? 0),
            );
            if (!$success) {
                $this->failureCount++;
                $this->droppedWriteCount++;
            }
        }
        if ($this->inflightWrites === []) {
            $this->inflightWrites = [];
            $this->inflightWriteBytes = 0;
        }
        if ($drain['lost'] > 0) {
            $this->failureCount += $drain['lost'];
            $this->requeueInflightWrites();
        }
    }

    private function requeueInflightWrites(): void
    {
        if ($this->inflightWrites === []) {
            return;
        }
        $requeued = [];
        foreach ($this->inflightWrites as $operation) {
            $key = (string)($operation['queue_key'] ?? '');
            if ($key === '') {
                continue;
            }
            unset($requeued[$key]);
            $requeued[$key] = $operation;
        }
        foreach ($this->pendingWrites as $key => $operation) {
            unset($requeued[$key]);
            $requeued[$key] = $operation;
        }
        $this->pendingWrites = $requeued;
        $this->pendingWriteBytes = 0;
        foreach ($this->pendingWrites as $operation) {
            $this->pendingWriteBytes += (int)($operation['bytes'] ?? 0);
        }
        $this->inflightWrites = [];
        $this->inflightWriteBytes = 0;
    }

    private function storeSession(string $contextHex, mixed $session): void
    {
        $this->newCount++;
        if (!$this->validSessionObject($session) || !$session->isResumable()) {
            return;
        }
        $sessionId = $session->id;
        if (!\is_string($sessionId) || !$this->validSessionId($sessionId)) {
            return;
        }
        $createdAt = (int)$session->getCreatedAt();
        $sessionTimeout = (int)$session->getTimeout();
        $now = \time();
        if ($createdAt <= 0 || $createdAt > $now + 60 || $sessionTimeout <= 0) {
            return;
        }
        $expiresAt = \min(
            $createdAt + \min($sessionTimeout, $this->config->timeoutSeconds),
            $now + $this->config->timeoutSeconds,
        );
        if ($expiresAt <= $now) {
            return;
        }
        $der = $session->export(\OPENSSL_ENCODING_DER);
        if (!\is_string($der) || $der === '' || \strlen($der) > $this->config->maxSessionBytes) {
            return;
        }

        $sessionIdHex = \bin2hex($sessionId);
        $derBase64 = \base64_encode($der);
        $this->putLocal($contextHex, $sessionIdHex, $session, $expiresAt);

        // A Session returned to the client must be visible to another Worker
        // before this callback completes. Merely writing PUT bytes to a
        // separate TCP channel creates a race where an immediate resume can
        // reach the reader channel before the sidecar has committed the PUT.
        // The writer is preconnected at READY and the synchronous request is
        // bounded by callback_timeout_ms. During a sidecar outage we retain
        // availability: the failed write falls back to the existing bounded
        // asynchronous recovery queue and the client performs a full handshake.
        if ($this->pendingWrites === []
            && $this->inflightWrites === []
            && $this->writerClient->pendingResponseCount() === 0
            && $this->writerClient->put(
                $contextHex,
                $sessionIdHex,
                $derBase64,
                $createdAt,
                $expiresAt,
            )
        ) {
            return;
        }

        $this->queueWrite($contextHex . ':' . $sessionIdHex, [
            'op' => 'put',
            'ctx' => $contextHex,
            'sid' => $sessionIdHex,
            'der' => $derBase64,
            'created_at' => $createdAt,
            'expires_at' => $expiresAt,
        ]);
    }

    private function loadSession(string $contextHex, string $sessionId): ?object
    {
        $this->getCount++;
        if (!$this->validSessionId($sessionId)) {
            return null;
        }
        $sessionIdHex = \bin2hex($sessionId);
        $localKey = $contextHex . ':' . $sessionIdHex;
        $local = $this->localSessions[$localKey] ?? null;
        if (\is_array($local)) {
            if (($local['expires_at'] ?? 0) > \time()
                && $this->validSessionObject($local['session'] ?? null)
                && $local['session']->isResumable()
                && \hash_equals($sessionId, (string)$local['session']->id)
            ) {
                unset($this->localSessions[$localKey]);
                $this->localSessions[$localKey] = $local;
                $this->localHitCount++;
                return $local['session'];
            }
            unset($this->localSessions[$localKey]);
        }

        $payload = $this->client->get($contextHex, $sessionIdHex);
        if (!\is_array($payload)) {
            return null;
        }
        $now = \time();
        $createdAt = (int)($payload['created_at'] ?? 0);
        $expiresAt = (int)($payload['expires_at'] ?? 0);
        if ($createdAt <= 0 || $createdAt > $now + 60 || $expiresAt <= $now) {
            return null;
        }
        $der = \base64_decode((string)($payload['der'] ?? ''), true);
        if (!\is_string($der) || $der === '' || \strlen($der) > $this->config->maxSessionBytes) {
            return null;
        }

        $sessionClass = self::SESSION_CLASS;
        $session = $sessionClass::import($der, \OPENSSL_ENCODING_DER);
        if (!$this->validSessionObject($session)
            || !$session->isResumable()
            || !\is_string($session->id)
            || !\hash_equals($sessionId, $session->id)
        ) {
            return null;
        }
        $objectCreatedAt = (int)$session->getCreatedAt();
        $objectTimeout = (int)$session->getTimeout();
        $objectExpiresAt = $objectCreatedAt + \min($objectTimeout, $this->config->timeoutSeconds);
        if ($objectCreatedAt !== $createdAt || $objectTimeout <= 0 || $objectExpiresAt <= $now) {
            return null;
        }
        $effectiveExpiresAt = \min($expiresAt, $objectExpiresAt);
        $this->putLocal($contextHex, $sessionIdHex, $session, $effectiveExpiresAt);
        $this->sharedHitCount++;

        return $session;
    }

    private function removeSession(string $contextHex, string $sessionId): void
    {
        if (!$this->validSessionId($sessionId)) {
            return;
        }
        $sessionIdHex = \bin2hex($sessionId);
        unset($this->localSessions[$contextHex . ':' . $sessionIdHex]);
        $this->queueWrite($contextHex . ':' . $sessionIdHex, [
            'op' => 'remove',
            'ctx' => $contextHex,
            'sid' => $sessionIdHex,
        ]);
    }

    /**
     * @param array{op:string,ctx:string,sid:string,der?:string,created_at?:int,expires_at?:int} $operation
     */
    private function queueWrite(string $key, array $operation): void
    {
        $bytes = \strlen((string)($operation['der'] ?? '')) + \strlen($key) + 256;
        if ($bytes > self::MAX_PENDING_WRITE_BYTES) {
            $this->droppedWriteCount++;
            $this->failureCount++;
            return;
        }
        if (isset($this->pendingWrites[$key])) {
            $this->pendingWriteBytes = \max(
                0,
                $this->pendingWriteBytes - (int)($this->pendingWrites[$key]['bytes'] ?? 0),
            );
            unset($this->pendingWrites[$key]);
        }
        while ($this->pendingWrites !== [] && (
            \count($this->pendingWrites) + \count($this->inflightWrites) >= self::MAX_PENDING_WRITES
            || $this->pendingWriteBytes + $this->inflightWriteBytes + $bytes > self::MAX_PENDING_WRITE_BYTES
        )) {
            $oldestKey = \array_key_first($this->pendingWrites);
            if (!\is_string($oldestKey)) {
                break;
            }
            $this->pendingWriteBytes = \max(
                0,
                $this->pendingWriteBytes - (int)($this->pendingWrites[$oldestKey]['bytes'] ?? 0),
            );
            unset($this->pendingWrites[$oldestKey]);
            $this->droppedWriteCount++;
            $this->failureCount++;
        }
        if (\count($this->pendingWrites) + \count($this->inflightWrites) >= self::MAX_PENDING_WRITES
            || $this->pendingWriteBytes + $this->inflightWriteBytes + $bytes > self::MAX_PENDING_WRITE_BYTES
        ) {
            $this->droppedWriteCount++;
            $this->failureCount++;
            return;
        }
        $operation['bytes'] = $bytes;
        $this->pendingWrites[$key] = $operation;
        $this->pendingWriteBytes += $bytes;
    }

    private function putLocal(
        string $contextHex,
        string $sessionIdHex,
        object $session,
        int $expiresAt
    ): void {
        if ($this->config->localCacheSize <= 0) {
            return;
        }
        $key = $contextHex . ':' . $sessionIdHex;
        unset($this->localSessions[$key]);
        while (\count($this->localSessions) >= $this->config->localCacheSize) {
            $oldestKey = \array_key_first($this->localSessions);
            if (!\is_string($oldestKey)) {
                break;
            }
            unset($this->localSessions[$oldestKey]);
        }
        $this->localSessions[$key] = ['session' => $session, 'expires_at' => $expiresAt];
    }

    private function certificateIdentityDigest(string $certificatePath): string
    {
        if ($certificatePath === '') {
            throw new \RuntimeException('TLS session context certificate is unavailable.');
        }
        $cacheKey = \str_replace('\\', '/', $certificatePath);
        if (isset($this->certificateDigests[$cacheKey])) {
            return $this->certificateDigests[$cacheKey];
        }
        if (!\is_file($certificatePath) || !\is_readable($certificatePath)) {
            throw new \RuntimeException('TLS session context certificate is unavailable.');
        }
        $pem = @\file_get_contents($certificatePath);
        if (!\is_string($pem) || $pem === '' || \strlen($pem) > 4194304) {
            throw new \RuntimeException('TLS session context certificate could not be read safely.');
        }
        // Hash the complete leaf certificate identity, not only its public key.
        // A renewal using the same key must still invalidate pre-renewal tickets.
        $material = $pem;
        if (\preg_match(
            '/-----BEGIN CERTIFICATE-----\s*([A-Za-z0-9+\/=\r\n]+)\s*-----END CERTIFICATE-----/',
            $pem,
            $matches,
        ) === 1) {
            $encoded = \preg_replace('/\s+/', '', (string)($matches[1] ?? ''));
            $leafDer = \is_string($encoded) ? \base64_decode($encoded, true) : false;
            if (\is_string($leafDer) && $leafDer !== '') {
                $material = $leafDer;
            }
        }

        // Certificate reload explicitly calls clearContextCache(), so the
        // ClientHello hot path needs no per-handshake stat syscalls.
        return $this->certificateDigests[$cacheKey] = \hash('sha256', $material);
    }

    private function validSessionObject(mixed $session): bool
    {
        $class = self::SESSION_CLASS;

        return \is_object($session) && $session instanceof $class;
    }

    private function validSessionId(string $sessionId): bool
    {
        $length = \strlen($sessionId);

        return $length >= 1 && $length <= 32;
    }

    private static function isLoopbackHost(string $host): bool
    {
        $host = \strtolower(\trim($host, " []\t\n\r\0\x0B"));

        return \in_array($host, ['127.0.0.1', '::1', 'localhost'], true);
    }
}
