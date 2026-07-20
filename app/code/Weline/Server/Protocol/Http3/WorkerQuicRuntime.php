<?php

declare(strict_types=1);

namespace Weline\Server\Protocol\Http3;

use Weline\Server\Service\Runtime\TlsTicketRingStore;

/**
 * Process-local HTTP/3 lifecycle used by a Direct SSL Worker.
 */
final class WorkerQuicRuntime
{
    private static ?self $active = null;

    private function __construct(
        private readonly Ngtcp2QuicTransportAdapter $adapter,
    ) {
    }

    /**
     * @param array<string,int> $limits
     * @param array{slot:int,slot_count:int,owner_epoch:int,generation:int,namespace_key:string,flags?:int} $linuxRoute
     */
    public static function bootReusePort(
        string $topology,
        bool $maintenanceWorker,
        string $host,
        int $port,
        string $certificate,
        string $privateKey,
        array $limits,
        string $retrySecret,
        string $instanceName,
        int $expectedTicketRingEpoch,
        string $expectedTicketRingDigest,
        array $linuxRoute,
    ): ?self {
        self::shutdownActive();
        $manifest = NativeTransportLibrary::manifest();
        if ($topology !== 'direct'
            || $maintenanceWorker
            || !($manifest['ready'] ?? false)
            || !($manifest['runtime_verified'] ?? false)
            || \PHP_OS_FAMILY !== 'Linux'
        ) {
            return null;
        }

        $adapter = new Ngtcp2QuicTransportAdapter();
        if (\strlen($retrySecret) !== 32) {
            throw new \RuntimeException('HTTP/3 Retry secret is unavailable or invalid.');
        }
        $ticketRing = self::loadTicketRing(
            $instanceName,
            $expectedTicketRingEpoch,
            $expectedTicketRingDigest,
        );
        try {
            $adapter->open(
                $host,
                $port,
                $certificate,
                $privateKey,
                true,
                $limits,
                $retrySecret,
                null,
                $linuxRoute,
                $ticketRing,
            );
        } finally {
            TlsTicketRingStore::wipeSnapshot($ticketRing);
        }
        $runtime = new self($adapter);
        self::$active = $runtime;
        return $runtime;
    }

    /**
     * @param array<string,int> $limits
     * @param array{worker_id:int,generation:int,channel_path:string,channel_key:string} $datagramWorker
     */
    public static function bootDatagramWorker(
        string $topology,
        bool $maintenanceWorker,
        string $host,
        int $port,
        string $certificate,
        string $privateKey,
        array $limits,
        string $retrySecret,
        string $instanceName,
        int $expectedTicketRingEpoch,
        string $expectedTicketRingDigest,
        array $datagramWorker,
    ): ?self {
        self::shutdownActive();
        $manifest = NativeTransportLibrary::manifest();
        if ($topology !== 'direct'
            || $maintenanceWorker
            || !($manifest['ready'] ?? false)
            || !($manifest['runtime_verified'] ?? false)
            || \PHP_OS_FAMILY !== 'Darwin'
        ) {
            return null;
        }
        if (\strlen($retrySecret) !== 32
            || \strlen((string)($datagramWorker['channel_key'] ?? '')) !== 32
        ) {
            throw new \RuntimeException('Darwin HTTP/3 datagram channel secrets are unavailable or invalid.');
        }

        $adapter = new Ngtcp2QuicTransportAdapter();
        $ticketRing = self::loadTicketRing(
            $instanceName,
            $expectedTicketRingEpoch,
            $expectedTicketRingDigest,
        );
        try {
            $adapter->open(
                $host,
                $port,
                $certificate,
                $privateKey,
                false,
                $limits,
                $retrySecret,
                $datagramWorker,
                null,
                $ticketRing,
            );
        } finally {
            TlsTicketRingStore::wipeSnapshot($ticketRing);
        }
        $runtime = new self($adapter);
        self::$active = $runtime;

        return $runtime;
    }

    public static function active(): ?self
    {
        return self::$active;
    }

    public static function shutdownActive(): void
    {
        if (self::$active !== null) {
            self::$active->close();
        }
        self::$active = null;
    }

    public function poll(int $timeoutMs = 0): int
    {
        return $this->adapter->poll($timeoutMs);
    }

    public function beginDrain(): void
    {
        $this->adapter->beginDrain();
    }

    /** @return array<string,int|string> */
    public function activateLinuxRoute(): array
    {
        return $this->adapter->activateLinuxRoute();
    }

    /** @return array<string,int|string> */
    public function linuxRouteStatus(): array
    {
        return $this->adapter->linuxRouteStatus();
    }

    public function isLinuxRouteActivated(): bool
    {
        return $this->adapter->isLinuxRouteActivated();
    }

    /** @return list<array{token:int,peer:string,raw_request:string,connection_id:int,stream_id:int}> */
    public function nextRequests(int $limit = 64): array
    {
        return $this->adapter->nextRequests($limit);
    }

    public function respond(int $token, string $response): void
    {
        $this->adapter->respond($token, $response);
    }

    public function closeRequest(int $token, int $errorCode = 0x0102): void
    {
        $this->adapter->closeRequest($token, $errorCode);
    }

    public function selectStream(): mixed
    {
        return $this->adapter->selectStream();
    }

    public function port(): int
    {
        return $this->adapter->boundPort();
    }

    public function nativeDigest(): string
    {
        return $this->adapter->nativeDigest();
    }

    /** @return array<string,bool|int|string> */
    public function tlsTicketRingStatus(): array
    {
        return $this->adapter->tlsTicketRingStatus();
    }

    /** @return array<string,int> */
    public function stats(): array
    {
        return $this->adapter->stats();
    }

    public function close(): void
    {
        $this->adapter->close();
    }

    /**
     * @return array{
     *     instance_name:string,epoch:int,created_at:int,rotation_seconds:int,
     *     digest:string,current:string,previous:string
     * }
     */
    private static function loadTicketRing(
        string $instanceName,
        int $expectedEpoch,
        string $expectedDigest,
    ): array {
        $expectedDigest = \strtolower(\trim($expectedDigest));
        if ($expectedEpoch <= 0
            || \preg_match('/^[a-f0-9]{64}$/D', $expectedDigest) !== 1
        ) {
            throw new \RuntimeException('HTTP/3 TLS ticket-ring expectation is invalid.');
        }
        $snapshot = (new TlsTicketRingStore())->loadSecretSnapshot($instanceName);
        $actualDigest = \strtolower(\trim((string)($snapshot['digest'] ?? '')));
        if ((int)($snapshot['epoch'] ?? 0) !== $expectedEpoch
            || \preg_match('/^[a-f0-9]{64}$/D', $actualDigest) !== 1
            || !\hash_equals($expectedDigest, $actualDigest)
            || (int)($snapshot['rotation_seconds'] ?? 0) < 300
            || (int)($snapshot['rotation_seconds'] ?? 0) > 604800
            || \strlen((string)($snapshot['current'] ?? '')) !== 32
            || \strlen((string)($snapshot['previous'] ?? '')) !== 32
        ) {
            TlsTicketRingStore::wipeSnapshot($snapshot);
            throw new \RuntimeException('HTTP/3 TLS ticket-ring snapshot does not match the Master metadata.');
        }
        $snapshot['instance_name'] = $instanceName;
        $snapshot['digest'] = $actualDigest;
        return $snapshot;
    }

    /** @return list<string> */
    public static function certificateHostPatterns(string $certificate): array
    {
        $pem = @\file_get_contents($certificate);
        if (!\is_string($pem) || $pem === '' || !\function_exists('openssl_x509_parse')) {
            return [];
        }
        $parsed = @\openssl_x509_parse($pem, false);
        if (!\is_array($parsed)) {
            return [];
        }

        $patterns = [];
        $subjectAltName = (string)($parsed['extensions']['subjectAltName'] ?? '');
        foreach (\array_filter(\array_map('trim', \explode(',', $subjectAltName))) as $entry) {
            if (\str_starts_with($entry, 'DNS:')) {
                $patterns[] = \substr($entry, 4);
                continue;
            }
            if (\str_starts_with($entry, 'IP Address:')) {
                $patterns[] = \substr($entry, 11);
                continue;
            }
            if (\str_starts_with($entry, 'IP:')) {
                $patterns[] = \substr($entry, 3);
            }
        }
        $commonName = (string)($parsed['subject']['CN'] ?? '');
        if ($commonName !== '') {
            $patterns[] = $commonName;
        }
        return \array_values(\array_unique(\array_filter(\array_map(
            static fn(string $pattern): string => \strtolower(\rtrim(\trim($pattern), '.')),
            $patterns,
        ))));
    }
}
