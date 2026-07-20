<?php

declare(strict_types=1);

namespace Weline\Server\Protocol\Http3;

/**
 * One process-local ngtcp2/nghttp3 QUIC UDP listener.
 *
 * Packet and frame work stays in C. FFI crosses only at complete request and
 * complete response boundaries.
 */
final class Ngtcp2QuicTransportAdapter implements QuicTransportAdapterInterface
{
    private const OK = 0;
    private const AGAIN = 1;
    private const TLS13_VERSION = 0x0304;
    private const TLS_CAP_SHARED_TICKET_RING = 1 << 4;
    private const TLS_CAP_SESSION_REUSE_STATS = 1 << 5;
    private const TLS_STATS_RING_ACTIVE = 1 << 0;
    private const TLS_STATS_EARLY_DATA_DISABLED = 1 << 1;

    private mixed $ffi = null;
    private mixed $tlsContext = null;
    private mixed $server = null;
    private mixed $selectStream = null;
    private mixed $peerBuffer = null;
    private mixed $requestBuffer = null;
    private mixed $responseBuffer = null;

    /** @var list<mixed> */
    private array $nativeBuffers = [];

    private int $requestCapacity = 0;
    private int $responseCapacity = 0;
    private int $maxResponseBytes = 0;
    private int $boundPort = 0;
    private string $nativeDigest = '';
    private bool $linuxRoute = false;
    private bool $linuxRouteActivated = false;
    private string $linuxRouteNamespaceDigest = '';

    public function __construct(private readonly bool $selfTestCandidate = false)
    {
    }

    public function readiness(): array
    {
        if ($this->selfTestCandidate) {
            $loaded = NativeTransportLibrary::loadSelfTestCandidate();
        } elseif (NativeTransportLibrary::hasPinnedManifest()) {
            $loaded = NativeTransportLibrary::load();
        } else {
            $selection = NativeTransportLibrary::selectInstalledVerified();
            $loaded = ($selection['ready'] ?? false)
                ? NativeTransportLibrary::load()
                : [
                    'available' => false,
                    'reason' => (string)($selection['reason'] ?? 'native transport unavailable'),
                    'manifest' => (array)($selection['manifest'] ?? []),
                ];
        }
        $manifest = \is_array($loaded['manifest'] ?? null) ? $loaded['manifest'] : [];
        $available = (bool)($loaded['available'] ?? false);
        $runtimeVerified = $available && NativeTransportLibrary::hasVerifiedRuntimeEvidence($manifest);
        $runtimeReason = (string)($manifest['runtime_reason'] ?? $loaded['reason'] ?? '');

        $serverStats = [
            'available' => $available,
            'adapter' => self::class,
            'reason' => $available
                ? ($runtimeVerified
                    ? $runtimeReason
                    : 'Native transport is loadable, but its QUIC/TLS runtime evidence is missing, stale or failed.'
                        . ($runtimeReason !== '' ? ' Last result: ' . $runtimeReason : ''))
                : (string)($loaded['reason'] ?? 'native transport unavailable'),
            'capabilities' => [
                'udp_accept_loop' => $available,
                'quic_tls13_handshake' => $available,
                'http3_stream_multiplexing' => $available,
                'worker_policy_dispatch' => $runtimeVerified,
                'h3_alt_svc_advertising' => $runtimeVerified,
                'runtime_self_test' => $runtimeVerified,
                'stateless_retry' => $available,
                'connection_error_isolation' => $available,
                'darwin_datagram_router' => $available && \PHP_OS_FAMILY === 'Darwin',
                'authenticated_packet_channel' => $available && \PHP_OS_FAMILY === 'Darwin',
                'native_kqueue_packet_loop' => $available && \PHP_OS_FAMILY === 'Darwin',
                'linux_reuseport_ebpf_route' => $runtimeVerified && \PHP_OS_FAMILY === 'Linux',
                'linux_cid_sockhash' => $runtimeVerified && \PHP_OS_FAMILY === 'Linux',
                'staged_route_activation' => $runtimeVerified && \PHP_OS_FAMILY === 'Linux',
            ],
            'missing' => $available
                ? ($runtimeVerified ? [] : ['runtime_quic_tls_evidence'])
                : ['native_wls_transport'],
        ];
        return $serverStats;
    }

    /**
     * @param array<string,int> $limits
     * @param array{worker_id:int,generation:int,channel_path:string,channel_key:string}|null $datagramWorker
     * @param array{slot:int,slot_count:int,owner_epoch:int,generation:int,namespace_key:string,flags?:int}|null $linuxRoute
     * @param array{instance_name:string,epoch:int,created_at:int,rotation_seconds:int,digest:string,current:string,previous:string}|null $ticketRing
     */
    public function open(
        string $host,
        int $port,
        string $certificate,
        string $privateKey,
        bool $reusePort,
        array $limits = [],
        string $retrySecret = '',
        ?array $datagramWorker = null,
        ?array $linuxRoute = null,
        ?array $ticketRing = null,
    ): void {
        if ($this->server !== null) {
            throw new \LogicException('HTTP/3 adapter is already open.');
        }
        if ($port < 0 || $port > 65535 || !\is_file($certificate) || !\is_file($privateKey)) {
            throw new \InvalidArgumentException('Invalid HTTP/3 listener or TLS files.');
        }
        if ($datagramWorker !== null && $linuxRoute !== null) {
            throw new \InvalidArgumentException('Darwin packet routing and Linux eBPF routing are mutually exclusive.');
        }
        if ($linuxRoute !== null && (\PHP_OS_FAMILY !== 'Linux' || !$reusePort)) {
            throw new \InvalidArgumentException('Linux HTTP/3 route configuration requires Linux Direct reuseport mode.');
        }
        if (\PHP_OS_FAMILY === 'Linux' && $reusePort
            && $datagramWorker === null && $linuxRoute === null
        ) {
            throw new \InvalidArgumentException('Linux Direct HTTP/3 requires an explicit staged eBPF route.');

        }
        $loaded = $this->selfTestCandidate
            ? NativeTransportLibrary::loadSelfTestCandidate()
            : NativeTransportLibrary::load();
        if (!($loaded['available'] ?? false)) {
            throw new \RuntimeException((string)($loaded['reason'] ?? 'native transport unavailable'));
        }
        $this->ffi = $loaded['ffi'];
        $manifest = (array)$loaded['manifest'];
        $this->nativeDigest = (string)($manifest['library_sha256'] ?? '');

        $certificateBuffer = $this->nativeString($certificate);
        $privateKeyBuffer = $this->nativeString($privateKey);
        $tlsConfig = $this->ffi->new('wls_tls_context_config');
        $tlsConfig->struct_size = \FFI::sizeof($tlsConfig);
        $tlsConfig->certificate_file = \FFI::addr($certificateBuffer[0]);
        $tlsConfig->private_key_file = \FFI::addr($privateKeyBuffer[0]);
        $alpn = $this->ffi->new('uint8_t[3]');
        $alpn[0] = 2;
        $alpn[1] = \ord('h');
        $alpn[2] = \ord('3');
        $this->nativeBuffers[] = $alpn;
        $tlsConfig->alpn_wire = \FFI::addr($alpn[0]);
        $tlsConfig->alpn_wire_length = 3;
        $tlsConfig->min_tls_version = self::TLS13_VERSION;
        $tlsConfig->max_tls_version = self::TLS13_VERSION;

        $tlsOut = $this->ffi->new('wls_tls_context *[1]');
        $result = (int)$this->ffi->wls_tls_context_new(\FFI::addr($tlsConfig), $tlsOut);
        $this->assertResult($result, 'create QUIC TLS context');
        $this->tlsContext = $tlsOut[0];
        if ($ticketRing !== null) {
            $this->installTlsTicketRing($ticketRing, $certificate);
        }

        $headerBytes = \max(1024, (int)($limits['max_header_bytes'] ?? 65536));
        $bodyBytes = \max(0, (int)($limits['max_body_bytes'] ?? 16 * 1024 * 1024));
        $serverConfig = $this->ffi->new('wls_h3_server_config');
        $serverConfig->struct_size = \FFI::sizeof($serverConfig);
        $serverConfig->disable_active_migration = 1;
        $serverConfig->max_idle_timeout_ms = \max(1000, (int)($limits['max_idle_timeout_ms'] ?? 30000));
        $serverConfig->initial_max_stream_data = $headerBytes + $bodyBytes;
        $serverConfig->initial_max_data = \max(
            $serverConfig->initial_max_stream_data,
            (int)($limits['initial_max_data'] ?? (($headerBytes + $bodyBytes) * 16))
        );
        $serverConfig->initial_max_streams_bidi = \max(1, (int)($limits['max_streams_bidi'] ?? 64));
        $serverConfig->max_connections = \max(1, (int)($limits['max_connections'] ?? 512));
        $serverConfig->max_active_streams = \max(1, (int)($limits['max_active_streams'] ?? 4096));
        $serverConfig->retry_token_lifetime_ms = \max(1000, (int)($limits['retry_token_lifetime_ms'] ?? 10000));
        $serverConfig->max_request_header_bytes = $headerBytes;
        $serverConfig->max_request_body_bytes = $bodyBytes;

        if (\strlen($retrySecret) !== 32) {
            throw new \InvalidArgumentException('HTTP/3 Retry secret must be exactly 32 bytes.');
        }
        $retry = $this->ffi->new('uint8_t[32]');
        \FFI::memcpy($retry, $retrySecret, 32);
        $this->nativeBuffers[] = $retry;
        $serverConfig->retry_secret = \FFI::addr($retry[0]);
        $serverConfig->retry_secret_length = 32;

        $serverOut = $this->ffi->new('wls_h3_server *[1]');
        $result = (int)$this->ffi->wls_h3_server_new(
            $this->tlsContext,
            \FFI::addr($serverConfig),
            $serverOut
        );
        $this->assertResult($result, 'create HTTP/3 server');
        $this->server = $serverOut[0];

        if ($datagramWorker !== null) {
            if (\PHP_OS_FAMILY !== 'Darwin'
                || (int)($datagramWorker['worker_id'] ?? 0) <= 0
                || (int)($datagramWorker['generation'] ?? 0) <= 0
                || \trim((string)($datagramWorker['channel_path'] ?? '')) === ''
                || \strlen((string)($datagramWorker['channel_key'] ?? '')) !== 32
                || $port <= 0
            ) {
                throw new \InvalidArgumentException('Invalid Darwin HTTP/3 datagram Worker configuration.');
            }
            $channelPath = $this->nativeString((string)$datagramWorker['channel_path']);
            $channelKey = $this->ffi->new('uint8_t[32]');
            \FFI::memcpy($channelKey, (string)$datagramWorker['channel_key'], 32);
            $this->nativeBuffers[] = $channelKey;
            $workerConfig = $this->ffi->new('wls_h3_datagram_worker_config');
            $workerConfig->struct_size = \FFI::sizeof($workerConfig);
            $workerConfig->worker_id = (int)$datagramWorker['worker_id'];
            $workerConfig->generation = (int)$datagramWorker['generation'];
            $workerConfig->public_port = $port;
            $workerConfig->channel_path = \FFI::addr($channelPath[0]);
            $workerConfig->channel_key = \FFI::addr($channelKey[0]);
            $workerConfig->channel_key_length = 32;
            $result = (int)$this->ffi->wls_h3_server_bind_datagram_worker(
                $this->server,
                \FFI::addr($workerConfig),
            );
            $this->assertResult($result, 'bind Darwin HTTP/3 datagram Worker channel');
        } elseif ($linuxRoute !== null) {
            $slot = (int)($linuxRoute['slot'] ?? -1);
            $slotCount = (int)($linuxRoute['slot_count'] ?? 0);
            $ownerEpoch = (int)($linuxRoute['owner_epoch'] ?? 0);
            $generation = (int)($linuxRoute['generation'] ?? 0);
            $namespaceKey = \trim((string)($linuxRoute['namespace_key'] ?? ''));
            if ($slot < 0 || $slotCount < 1 || $slotCount > 64
                || $slot >= $slotCount || $ownerEpoch <= 0
                || $generation <= 0 || $namespaceKey === '' || $port <= 0
            ) {
                throw new \InvalidArgumentException('Invalid Linux HTTP/3 eBPF route configuration.');
            }
            $hostBuffer = $this->nativeString($host);
            $namespaceBuffer = $this->nativeString($namespaceKey);
            $routeConfig = $this->ffi->new('wls_h3_linux_route_config');
            $routeConfig->struct_size = \FFI::sizeof($routeConfig);
            $routeConfig->slot = $slot;
            $routeConfig->slot_count = $slotCount;
            $routeConfig->flags = (int)($linuxRoute['flags'] ?? 1);
            $routeConfig->owner_epoch = $ownerEpoch;
            $routeConfig->generation = $generation;
            $routeConfig->namespace_key = \FFI::addr($namespaceBuffer[0]);
            $result = (int)$this->ffi->wls_h3_server_bind_linux_route(
                $this->server,
                $hostBuffer,
                $port,
                \FFI::addr($routeConfig),
            );
            $this->assertResult($result, 'stage Linux HTTP/3 reuseport eBPF route');
            $this->linuxRoute = true;
            $this->linuxRouteNamespaceDigest = \hash('sha256', $namespaceKey);
            $routeStatus = $this->linuxRouteStatus();
            if (($routeStatus['state'] ?? 0) !== 1
                || ($routeStatus['slot'] ?? -1) !== $slot
                || ($routeStatus['slot_count'] ?? 0) !== $slotCount
                || ($routeStatus['owner_epoch'] ?? 0) !== $ownerEpoch
                || ($routeStatus['generation'] ?? 0) !== $generation
                || ($routeStatus['listener_cookie'] ?? 0) <= 0
                || ($routeStatus['connection_cookie'] ?? 0) <= 0
                || ($routeStatus['program_id'] ?? 0) <= 0
                || ($routeStatus['listen_map_id'] ?? 0) <= 0
                || ($routeStatus['worker_map_id'] ?? 0) <= 0
                || ($routeStatus['count_map_id'] ?? 0) <= 0
                || ($routeStatus['owner_map_id'] ?? 0) <= 0
            ) {
                throw new \RuntimeException('Linux HTTP/3 eBPF route staged identity mismatch.');
            }
        } else {
            $hostBuffer = $this->nativeString($host);
            $result = (int)$this->ffi->wls_h3_server_bind(
                $this->server,
                $hostBuffer,
                $port,
                $reusePort ? 1 : 0
            );
            $this->assertResult($result, 'bind HTTP/3 UDP listener');
        }
        $this->boundPort = (int)$this->ffi->wls_h3_server_bound_port($this->server);
        if ($this->boundPort <= 0) {
            throw new \RuntimeException('Native HTTP/3 listener returned an invalid port.');
        }

        $selectFd = (int)$this->ffi->wls_h3_server_wait_fd($this->server);
        if ($selectFd < 0) {
            throw new \RuntimeException('Native HTTP/3 listener did not expose a selectable descriptor.');
        }
        $this->selectStream = @\fopen('php://fd/' . $selectFd, 'r');
        if (!\is_resource($this->selectStream)) {
            throw new \RuntimeException('PHP could not wrap the HTTP/3 selectable descriptor.');
        }
        @\stream_set_blocking($this->selectStream, false);
        $this->requestCapacity = $headerBytes + $bodyBytes + 4096;
        $this->maxResponseBytes = \max(
            65536,
            \min(
                128 * 1024 * 1024,
                (int)($limits['max_response_bytes'] ?? \max(16 * 1024 * 1024, $this->requestCapacity))
            )
        );
        $this->peerBuffer = $this->ffi->new('char[256]');
        $this->requestBuffer = $this->ffi->new('uint8_t[' . $this->requestCapacity . ']');
    }

    public function poll(int $timeoutMs = 0): int
    {
        if ($this->server === null) {
            return 0;
        }
        $result = (int)$this->ffi->wls_h3_server_poll(
            $this->server,
            \max(0, $timeoutMs)
        );
        if ($result === self::AGAIN) {
            return 0;
        }
        $this->assertResult($result, 'poll HTTP/3 UDP listener', true);
        return \max(0, $result);
    }

    public function beginDrain(): void
    {
        if ($this->server === null) {
            return;
        }
        $this->assertResult(
            (int)$this->ffi->wls_h3_server_begin_drain($this->server),
            'begin graceful HTTP/3 drain',
        );
        if ($this->linuxRoute) {
            $this->linuxRouteActivated = false;
        }
    }

    /** @return array<string,int|string> */
    public function activateLinuxRoute(): array
    {
        if (!$this->linuxRoute || $this->server === null) {
            throw new \LogicException('Linux HTTP/3 route is not staged.');
        }
        $this->assertResult(
            (int)$this->ffi->wls_h3_server_activate_linux_route($this->server),
            'activate Linux HTTP/3 reuseport eBPF route',
        );
        $status = $this->linuxRouteStatus();
        if (($status['state'] ?? 0) !== 2) {
            throw new \RuntimeException('Linux HTTP/3 route did not enter activated state.');
        }
        $this->linuxRouteActivated = true;
        return $status;
    }

    /** @return array<string,int|string> */
    public function linuxRouteStatus(): array
    {
        if (!$this->linuxRoute || $this->server === null) {
            return [];
        }
        $status = $this->ffi->new('wls_h3_linux_route_status');
        $status->struct_size = \FFI::sizeof($status);
        $this->assertResult(
            (int)$this->ffi->wls_h3_server_get_linux_route_status(
                $this->server,
                \FFI::addr($status),
            ),
            'read Linux HTTP/3 route status',
        );
        $state = (int)$status->state;
        $serverStats = [
            'state' => $state,
            'state_name' => match ($state) {
                1 => 'staged',
                2 => 'activated',
                3 => 'draining',
                4 => 'failed',
                default => 'disabled',
            },
            'slot' => (int)$status->slot,
            'slot_count' => (int)$status->slot_count,
            'owner_epoch' => (int)$status->owner_epoch,
            'generation' => (int)$status->generation,
            'listener_cookie' => (int)$status->listener_cookie,
            'connection_cookie' => (int)$status->connection_cookie,
            'active_cids' => (int)$status->active_cids,
            'program_id' => (int)$status->program_id,
            'listen_map_id' => (int)$status->listen_map_id,
            'worker_map_id' => (int)$status->worker_map_id,
            'count_map_id' => (int)$status->count_map_id,
            'owner_map_id' => (int)$status->owner_map_id,
            'pin_namespace' => \FFI::string($status->pin_namespace),
            'namespace_digest' => $this->linuxRouteNamespaceDigest,
        ];
        return $serverStats;
    }

    public function isLinuxRouteActivated(): bool
    {
        return $this->linuxRouteActivated;
    }

    /**
     * @return list<array{token:int,peer:string,raw_request:string,connection_id:int,stream_id:int}>
     */
    public function nextRequests(int $limit = 64): array
    {
        if ($this->server === null) {
            return [];
        }
        if ($this->peerBuffer === null || $this->requestBuffer === null) {
            throw new \LogicException('HTTP/3 request buffers are not initialized.');
        }
        $requests = [];
        for ($index = 0; $index < \max(1, \min(256, $limit)); $index++) {
            $request = $this->ffi->new('wls_h3_request');
            $request->struct_size = \FFI::sizeof($request);
            $request->peer = \FFI::addr($this->peerBuffer[0]);
            $request->peer_capacity = 256;
            $request->raw_request = \FFI::addr($this->requestBuffer[0]);
            $request->raw_request_capacity = $this->requestCapacity;
            $result = (int)$this->ffi->wls_h3_server_next_request(
                $this->server,
                \FFI::addr($request)
            );
            if ($result === self::AGAIN) {
                break;
            }
            $this->assertResult($result, 'read complete HTTP/3 request');
            $requests[] = [
                'token' => (int)$request->token,
                'peer' => \FFI::string($this->peerBuffer, (int)$request->peer_length),
                'raw_request' => \FFI::string($this->requestBuffer, (int)$request->raw_request_length),
                'connection_id' => (int)$request->connection_id,
                'stream_id' => (int)$request->stream_id,
            ];
        }
        return $requests;
    }

    public function respond(int $token, string $rawResponse): void
    {
        if ($this->server === null || $token <= 0 || $rawResponse === '') {
            throw new \InvalidArgumentException('Invalid HTTP/3 response.');
        }
        $length = \strlen($rawResponse);
        if ($length > $this->maxResponseBytes) {
            throw new \LengthException('HTTP/3 response exceeds the configured process-local buffer limit.');
        }
        $this->ensureResponseCapacity($length);
        \FFI::memcpy($this->responseBuffer, $rawResponse, $length);
        $response = $this->ffi->new('wls_h3_response');
        $response->struct_size = \FFI::sizeof($response);
        $response->token = $token;
        $response->raw_response = \FFI::addr($this->responseBuffer[0]);
        $response->raw_response_length = $length;
        $this->assertResult(
            (int)$this->ffi->wls_h3_server_respond($this->server, \FFI::addr($response)),
            'submit HTTP/3 response'
        );
    }

    public function closeRequest(int $token, int $errorCode = 0x0102): void
    {
        if ($this->server === null || $token <= 0) {
            return;
        }
        $result = (int)$this->ffi->wls_h3_server_close_request(
            $this->server,
            $token,
            $errorCode
        );
        if ($result !== self::OK && $result !== -10) {
            $this->assertResult($result, 'close HTTP/3 request');
        }
    }

    /** @return array<string,int> */
    public function stats(): array
    {
        if ($this->server === null) {
            return [];
        }
        $stats = $this->ffi->new('wls_h3_server_stats');
        $stats->struct_size = \FFI::sizeof($stats);
        $this->assertResult(
            (int)$this->ffi->wls_h3_server_get_stats($this->server, \FFI::addr($stats)),
            'read HTTP/3 stats'
        );
        $serverStats = [
            'received_datagrams' => (int)$stats->received_datagrams,
            'accepted_initials' => (int)$stats->accepted_initials,
            'active_connections' => (int)$stats->active_connections,
            'active_streams' => (int)$stats->active_streams,
            'queued_requests' => (int)$stats->queued_requests,
            'retry_sent' => (int)$stats->retry_sent,
            'retry_validated' => (int)$stats->retry_validated,
            'rejected_initials' => (int)$stats->rejected_initials,
            'connection_errors' => (int)$stats->connection_errors,
            'connection_read_errors' => (int)$stats->connection_read_errors,
            'connection_flush_errors' => (int)$stats->connection_flush_errors,
            'connection_callback_errors' => (int)$stats->connection_callback_errors,
            'connection_expiry_errors' => (int)$stats->connection_expiry_errors,
            'draining_reads' => (int)$stats->draining_reads,
            'closing_reads' => (int)$stats->closing_reads,
            'flush_skipped_draining' => (int)$stats->flush_skipped_draining,
            'flush_skipped_closing' => (int)$stats->flush_skipped_closing,
            'write_stream_not_found' => (int)$stats->write_stream_not_found,
            'connection_rotation_requests' => (int)$stats->connection_rotation_requests,
            'connection_rotation_goaways' => (int)$stats->connection_rotation_goaways,
            'connection_rotation_completions' => (int)$stats->connection_rotation_completions,
            'max_connection_request_count' => (int)$stats->max_connection_request_count,
            'last_connection_error_stage' => (int)$stats->last_connection_error_stage,
            'last_connection_error_code' => (int)$stats->last_connection_error_code,
            'capacity_rejections' => (int)$stats->capacity_rejections,
            'peer_mismatch_drops' => (int)$stats->peer_mismatch_drops,
            'routed_datagrams' => (int)$stats->routed_datagrams,
            'channel_drops' => (int)$stats->channel_drops,
            'channel_auth_failures' => (int)$stats->channel_auth_failures,
        ];
        $tls = $this->tlsTicketRingStatus();
        $serverStats['tls_ticket_ring_active'] = (int)($tls['active'] ?? false);
        $serverStats['tls_early_data_disabled'] = (int)($tls['early_data_disabled'] ?? false);
        $serverStats['tls_ticket_ring_epoch'] = (int)($tls['epoch'] ?? 0);
        $serverStats['tls_ticket_lifetime_seconds'] = (int)($tls['lifetime_seconds'] ?? 0);
        foreach ([
            'handshakes_completed',
            'full_handshakes',
            'resumed_handshakes',
            'tickets_encrypted',
            'tickets_decrypted_current',
            'tickets_decrypted_previous',
            'tickets_rejected',
            'ticket_errors',
        ] as $metric) {
            $serverStats['tls_' . $metric] = (int)($tls[$metric] ?? 0);
        }
        return $serverStats;
    }

    public function selectStream(): mixed
    {
        return $this->selectStream;
    }

    public function boundPort(): int
    {
        return $this->boundPort;
    }

    public function nativeDigest(): string
    {
        return $this->nativeDigest;
    }

    /** @return array<string,bool|int|string> */
    public function tlsTicketRingStatus(): array
    {
        if ($this->tlsContext === null || $this->ffi === null) {
            return [];
        }
        $stats = $this->ffi->new('wls_tls_context_stats');
        $stats->struct_size = \FFI::sizeof($stats);
        $this->assertResult(
            (int)$this->ffi->wls_tls_context_get_stats($this->tlsContext, \FFI::addr($stats)),
            'read QUIC TLS ticket-ring stats',
        );
        $flags = (int)$stats->flags;
        return [
            'active' => ($flags & self::TLS_STATS_RING_ACTIVE) !== 0,
            'early_data_disabled' => ($flags & self::TLS_STATS_EARLY_DATA_DISABLED) !== 0,
            'epoch' => (int)$stats->ticket_epoch,
            'digest' => \strtolower(\FFI::string($stats->ticket_digest)),
            'lifetime_seconds' => (int)$stats->ticket_lifetime_seconds,
            'handshakes_completed' => (int)$stats->handshakes_completed,
            'full_handshakes' => (int)$stats->full_handshakes,
            'resumed_handshakes' => (int)$stats->resumed_handshakes,
            'tickets_encrypted' => (int)$stats->tickets_encrypted,
            'tickets_decrypted_current' => (int)$stats->tickets_decrypted_current,
            'tickets_decrypted_previous' => (int)$stats->tickets_decrypted_previous,
            'tickets_rejected' => (int)$stats->tickets_rejected,
            'ticket_errors' => (int)$stats->ticket_errors,
        ];
    }

    public function isOpen(): bool
    {
        return $this->server !== null;
    }

    public function close(): void
    {
        if (\is_resource($this->selectStream)) {
            @\fclose($this->selectStream);
        }
        $this->selectStream = null;
        if ($this->server !== null && $this->ffi !== null) {
            $this->ffi->wls_h3_server_destroy($this->server);
        }
        $this->server = null;
        $this->linuxRoute = false;
        $this->linuxRouteActivated = false;
        $this->linuxRouteNamespaceDigest = '';
        if ($this->tlsContext !== null && $this->ffi !== null) {
            $this->ffi->wls_tls_context_release($this->tlsContext);
        }
        $this->tlsContext = null;
        $this->nativeBuffers = [];
        $this->peerBuffer = null;
        $this->requestBuffer = null;
        $this->responseBuffer = null;
        $this->requestCapacity = 0;
        $this->responseCapacity = 0;
        $this->maxResponseBytes = 0;
        $this->boundPort = 0;
    }

    public function __destruct()
    {
        $this->close();
    }

    private function nativeString(string $value): mixed
    {
        $buffer = $this->ffi->new('char[' . (\strlen($value) + 1) . ']');
        \FFI::memcpy($buffer, $value, \strlen($value));
        $buffer[\strlen($value)] = "\0";
        $this->nativeBuffers[] = $buffer;
        return $buffer;
    }

    /**
     * @param array{instance_name:string,epoch:int,created_at:int,rotation_seconds:int,digest:string,current:string,previous:string} $ticketRing
     */
    private function installTlsTicketRing(array $ticketRing, string $certificate): void
    {
        $instanceName = \trim((string)($ticketRing['instance_name'] ?? ''));
        $epoch = (int)($ticketRing['epoch'] ?? 0);
        $digest = \strtolower(\trim((string)($ticketRing['digest'] ?? '')));
        $lifetime = (int)($ticketRing['rotation_seconds'] ?? 0);
        $current = (string)($ticketRing['current'] ?? '');
        $previous = (string)($ticketRing['previous'] ?? '');
        $certificateDigest = \hash_file('sha256', $certificate);
        if ($instanceName === ''
            || $epoch <= 0
            || \preg_match('/^[a-f0-9]{64}$/D', $digest) !== 1
            || $lifetime < 300
            || $lifetime > 604800
            || \strlen($current) !== 32
            || \strlen($previous) !== 32
            || \hash_equals($current, $previous)
            || !\is_string($certificateDigest)
            || \preg_match('/^[a-f0-9]{64}$/D', $certificateDigest) !== 1
        ) {
            throw new \RuntimeException('Invalid HTTP/3 TLS ticket-ring snapshot.');
        }
        $capabilities = (int)$this->ffi->wls_tls_context_capabilities($this->tlsContext);
        if (($capabilities & self::TLS_CAP_SESSION_REUSE_STATS) === 0) {
            throw new \RuntimeException('Native HTTP/3 TLS context does not expose session-reuse stats.');
        }

        $sessionContextBytes = \hash(
            'sha256',
            "wls-h3-ticket-context-v1\0"
                . $instanceName . "\0"
                . $certificateDigest . "\0h3\0tls1.3\0"
                . $lifetime,
            true,
        );
        $currentBuffer = $this->ffi->new('uint8_t[32]');
        $previousBuffer = $this->ffi->new('uint8_t[32]');
        $sessionContext = $this->ffi->new('uint8_t[32]');
        $digestBuffer = $this->ffi->new('char[65]');
        \FFI::memcpy($currentBuffer, $current, 32);
        \FFI::memcpy($previousBuffer, $previous, 32);
        \FFI::memcpy($sessionContext, $sessionContextBytes, 32);
        \FFI::memcpy($digestBuffer, $digest, 64);

        $ring = $this->ffi->new('wls_tls_ticket_ring');
        $ring->struct_size = \FFI::sizeof($ring);
        $ring->current_key = \FFI::addr($currentBuffer[0]);
        $ring->current_key_length = 32;
        $ring->previous_key = \FFI::addr($previousBuffer[0]);
        $ring->previous_key_length = 32;
        $ring->epoch = $epoch;
        $ring->digest = \FFI::addr($digestBuffer[0]);
        $ring->session_context = \FFI::addr($sessionContext[0]);
        $ring->session_context_length = 32;
        $ring->ticket_lifetime_seconds = $lifetime;
        try {
            $this->assertResult(
                (int)$this->ffi->wls_tls_context_set_ticket_ring(
                    $this->tlsContext,
                    \FFI::addr($ring),
                ),
                'install QUIC TLS ticket ring',
            );
            $activeCapabilities = (int)$this->ffi->wls_tls_context_capabilities($this->tlsContext);
            $status = $this->tlsTicketRingStatus();
            if (($activeCapabilities & self::TLS_CAP_SHARED_TICKET_RING) === 0
                || !($status['active'] ?? false)
                || !($status['early_data_disabled'] ?? false)
                || (int)($status['epoch'] ?? 0) !== $epoch
                || !\hash_equals($digest, (string)($status['digest'] ?? ''))
                || (int)($status['lifetime_seconds'] ?? 0) !== $lifetime
            ) {
                throw new \RuntimeException('Native HTTP/3 TLS ticket-ring acknowledgement mismatch.');
            }
        } finally {
            \FFI::memset($currentBuffer, 0, 32);
            \FFI::memset($previousBuffer, 0, 32);
            \FFI::memset($sessionContext, 0, 32);
            \FFI::memset($digestBuffer, 0, 65);
            if (\function_exists('sodium_memzero')) {
                \sodium_memzero($sessionContextBytes);
            }
        }
    }

    private function ensureResponseCapacity(int $requiredBytes): void
    {
        if ($requiredBytes <= $this->responseCapacity && $this->responseBuffer !== null) {
            return;
        }

        $capacity = Http3ResponseBatch::bufferCapacity(
            $requiredBytes,
            $this->responseCapacity,
            $this->maxResponseBytes,
        );
        $this->responseBuffer = $this->ffi->new('uint8_t[' . $capacity . ']');
        $this->responseCapacity = $capacity;
    }

    private function assertResult(int $result, string $operation, bool $allowPositive = false): void
    {
        if ($result === self::OK || ($allowPositive && $result > 0)) {
            return;
        }
        $reason = '';
        if ($this->ffi !== null) {
            try {
                $nativeReason = $this->ffi->wls_transport_last_error();
                $reason = \is_string($nativeReason) ? $nativeReason : \FFI::string($nativeReason);
            } catch (\Throwable) {
            }
        }
        throw new \RuntimeException($operation . ' failed'
            . ($reason !== '' ? ': ' . $reason : ' (code=' . $result . ')'));
    }
}
