<?php

declare(strict_types=1);

namespace Weline\Server\Service\Runtime;

use Weline\Framework\Container\ContainerRuntime;

/**
 * Process-local evidence used to build a Worker READY announcement.
 *
 * Every field starts fail-closed. Transport adapters may only mark a
 * capability after the corresponding bind/listen, policy load, or warmup step
 * has completed successfully.
 */
final class WorkerReadinessState
{
    public const READINESS_PROTOCOL_VERSION = 7;
    public const CAPABILITY_DYNAMIC_FIRST_RENDER_PROOF = 'dynamic_first_render_proof_v1';
    public const CAPABILITY_COMPILED_CONTAINER_DIGEST = 'compiled_container_digest_v1';
    public const CAPABILITY_HTTP3_QUIC_READY = 'http3_quic_ready_v3';
    public const CAPABILITY_HTTP3_LINUX_EBPF_ROUTE = 'http3_linux_ebpf_route_v1';
    public const CAPABILITY_HTTP3_TLS_TICKET_RING = 'http3_tls_ticket_ring_v1';

    private static string $topology = '';
    private static string $policyDigest = '';
    private static string $warmupState = 'cold';
    /**
     * @var array{hit:bool,fpc_status:string,source:string,full_uri:string,reason:string,http_status:int}
     */
    private static array $homepageFpcProof = [
        'hit' => false,
        'fpc_status' => '',
        'source' => '',
        'full_uri' => '',
        'reason' => '',
        'http_status' => 0,
    ];
    /**
     * @var array{
     *     ready:bool,host:string,path:string,status_code:int,body_length:int,
     *     elapsed_ms:float,target_ms:float,attempts:int,fpc_status:string,
     *     cache:string,reason:string
     * }
     */
    private static array $dynamicFirstRenderProof = [
        'ready' => false,
        'host' => '',
        'path' => '',
        'status_code' => 0,
        'body_length' => 0,
        'elapsed_ms' => 0.0,
        'target_ms' => 0.0,
        'attempts' => 0,
        'fpc_status' => '',
        'cache' => '',
        'reason' => 'not-recorded',
    ];
    private static bool $listenerBound = false;
    private static bool $reusePortBound = false;
    private static string $listenerMode = '';
    private static bool $sharedListener = false;
    private static int $inheritedFd = 0;
    private static string $eventLoop = '';
    private static string $sslEngine = '';
    private static string $http3Mode = '';
    private static bool $http3ListenerBound = false;
    private static bool $http3DatagramChannelReady = false;
    private static bool $http3RouteGenerationReady = false;
    private static int $http3Port = 0;
    private static string $http3NativeDigest = '';
    private static bool $http3RuntimeVerified = false;
    private static bool $http3TlsTicketRingActive = false;
    private static bool $http3EarlyDataDisabled = false;
    private static int $http3TlsTicketRingEpoch = 0;
    private static int $http3TlsTicketLifetimeSeconds = 0;
    private static string $http3TlsTicketRingDigest = '';
    /** @var array<string,int|string> */
    private static array $http3Route = [];
    private static string $http3ActivationId = '';

    public static function reset(string $topology): void
    {
        $topology = \strtolower(\trim($topology));
        self::$topology = \in_array($topology, ['direct', 'dispatcher'], true) ? $topology : '';
        self::$policyDigest = '';
        self::$warmupState = 'cold';
        self::$homepageFpcProof = self::emptyHomepageFpcProof();
        self::$dynamicFirstRenderProof = self::emptyDynamicFirstRenderProof();
        self::$listenerBound = false;
        self::$reusePortBound = false;
        self::$listenerMode = '';
        self::$sharedListener = false;
        self::$inheritedFd = 0;
        self::$eventLoop = '';
        self::$sslEngine = '';
        self::$http3Mode = '';
        self::$http3ListenerBound = false;
        self::$http3DatagramChannelReady = false;
        self::$http3RouteGenerationReady = false;
        self::$http3Port = 0;
        self::$http3NativeDigest = '';
        self::$http3RuntimeVerified = false;
        self::$http3TlsTicketRingActive = false;
        self::$http3EarlyDataDisabled = false;
        self::$http3TlsTicketRingEpoch = 0;
        self::$http3TlsTicketLifetimeSeconds = 0;
        self::$http3TlsTicketRingDigest = '';
        self::$http3Route = [];
        self::$http3ActivationId = '';
    }

    public static function markListenerBound(
        bool $reusePortBound,
        string $eventLoop,
        string $sslEngine,
        string $listenerMode = '',
        int $inheritedFd = 0,
    ): void {
        $listenerMode = \strtolower(\trim($listenerMode));
        if ($listenerMode === '') {
            $listenerMode = $reusePortBound ? 'reuseport' : 'single';
        }
        self::$listenerBound = true;
        self::$reusePortBound = $reusePortBound;
        self::$listenerMode = $listenerMode;
        self::$sharedListener = $listenerMode === 'shared_fd' && $inheritedFd > 0;
        self::$inheritedFd = self::$sharedListener ? $inheritedFd : 0;
        self::$eventLoop = \strtolower(\trim($eventLoop));
        self::$sslEngine = \strtolower(\trim($sslEngine));
    }

    public static function markListenerClosed(): void
    {
        self::$listenerBound = false;
        self::$reusePortBound = false;
        self::$sharedListener = false;
        self::$inheritedFd = 0;
    }

    /** @param array<string,int|string> $routeStatus */
    public static function markHttp3LinuxRouteStaged(
        int $port,
        string $nativeDigest,
        bool $runtimeVerified,
        array $routeStatus,
    ): void {
        self::markHttp3Ready('reuseport-ebpf', $port, $nativeDigest, $runtimeVerified, true, false, false);
        $route = self::normalizeLinuxRouteStatus($routeStatus, 'staged');
        if (self::$http3Mode === '' || $route === []) {
            self::markHttp3Closed();
            return;
        }
        self::$http3Route = $route;
        self::$http3ActivationId = '';
    }

    /** @param array<string,int|string> $routeStatus */
    public static function markHttp3LinuxRouteActivated(array $routeStatus, string $activationId): void
    {
        $activationId = \strtolower(\trim($activationId));
        $route = self::normalizeLinuxRouteStatus($routeStatus, 'active');
        if (self::$http3Mode !== 'reuseport-ebpf'
            || self::$http3Route === []
            || $route === []
            || \preg_match('/^[a-f0-9]{64}$/D', $activationId) !== 1
            || !self::sameLinuxRouteIdentity(self::$http3Route, $route)
        ) {
            throw new \RuntimeException('Linux HTTP/3 route activation identity mismatch.');
        }
        self::$http3Route = $route;
        self::$http3ActivationId = $activationId;
        self::$http3RouteGenerationReady = true;
    }

    public static function markHttp3DatagramWorkerReady(
        int $port,
        string $nativeDigest,
        bool $runtimeVerified,
    ): void {
        self::markHttp3Ready('datagram-router', $port, $nativeDigest, $runtimeVerified, false, true, true);
    }

    /** @param array<string,bool|int|string> $status */
    public static function markHttp3TlsTicketRingReady(array $status): void
    {
        $digest = \strtolower(\trim((string)($status['digest'] ?? '')));
        $epoch = (int)($status['epoch'] ?? 0);
        $lifetime = (int)($status['lifetime_seconds'] ?? 0);
        if (!($status['active'] ?? false)
            || !($status['early_data_disabled'] ?? false)
            || $epoch <= 0
            || $lifetime < 300
            || $lifetime > 604800
            || \preg_match('/^[a-f0-9]{64}$/D', $digest) !== 1
        ) {
            throw new \RuntimeException('HTTP/3 TLS ticket-ring readiness is invalid.');
        }
        self::$http3TlsTicketRingActive = true;
        self::$http3EarlyDataDisabled = true;
        self::$http3TlsTicketRingEpoch = $epoch;
        self::$http3TlsTicketLifetimeSeconds = $lifetime;
        self::$http3TlsTicketRingDigest = $digest;
    }

    public static function markHttp3Closed(): void
    {
        self::$http3Mode = '';
        self::$http3ListenerBound = false;
        self::$http3DatagramChannelReady = false;
        self::$http3RouteGenerationReady = false;
        self::$http3Port = 0;
        self::$http3NativeDigest = '';
        self::$http3RuntimeVerified = false;
        self::$http3TlsTicketRingActive = false;
        self::$http3EarlyDataDisabled = false;
        self::$http3TlsTicketRingEpoch = 0;
        self::$http3TlsTicketLifetimeSeconds = 0;
        self::$http3TlsTicketRingDigest = '';
        self::$http3Route = [];
        self::$http3ActivationId = '';
    }

    public static function markPolicyLoaded(string $digest): void
    {
        $digest = \strtolower(\trim($digest));
        self::$policyDigest = \preg_match('/^[a-f0-9]{64}$/D', $digest) === 1 ? $digest : '';
    }

    public static function markMaintenanceReady(): void
    {
        self::$homepageFpcProof = self::emptyHomepageFpcProof();
        self::$dynamicFirstRenderProof = self::emptyDynamicFirstRenderProof();
        self::$warmupState = 'ready';
    }

    /**
     * @param array{hit?:mixed,fpc_status?:mixed,source?:mixed,full_uri?:mixed,reason?:mixed,http_status?:mixed} $proof
     */
    public static function markBusinessHomepageHot(array $proof): void
    {
        $normalized = [
            'hit' => (bool)($proof['hit'] ?? false),
            'fpc_status' => \strtoupper(\trim((string)($proof['fpc_status'] ?? ''))),
            'source' => \strtolower(\trim((string)($proof['source'] ?? ''))),
            'full_uri' => \trim((string)($proof['full_uri'] ?? '')),
            'reason' => \trim((string)($proof['reason'] ?? '')),
            'http_status' => (int)($proof['http_status'] ?? 0),
        ];
        $validProcessFpcProof = $normalized['hit']
            && $normalized['fpc_status'] === 'HIT'
            && \str_starts_with($normalized['source'], 'process')
            && \preg_match('#^https?://#i', $normalized['full_uri']) === 1
            && $normalized['http_status'] >= 200
            && $normalized['http_status'] < 400;

        self::$homepageFpcProof = $normalized;
        self::$warmupState = $validProcessFpcProof ? 'hot' : 'warm';
    }

    /**
     * Preserve the exact dynamic-render receipt for Master-side admission.
     * Normalization happens here; trust and threshold validation remain the
     * Orchestrator's responsibility so a rejected proof retains diagnostics.
     *
     * @param array{
     *     ready?:mixed,host?:mixed,path?:mixed,status_code?:mixed,body_length?:mixed,
     *     elapsed_ms?:mixed,target_ms?:mixed,attempts?:mixed,fpc_status?:mixed,
     *     cache?:mixed,reason?:mixed
     * } $proof
     */
    public static function markDynamicFirstRenderProof(array $proof): void
    {
        $elapsedMs = (float)($proof['elapsed_ms'] ?? 0.0);
        $targetMs = (float)($proof['target_ms'] ?? 0.0);
        self::$dynamicFirstRenderProof = [
            'ready' => (bool)($proof['ready'] ?? false),
            'host' => \trim((string)($proof['host'] ?? '')),
            'path' => \trim((string)($proof['path'] ?? '')),
            'status_code' => (int)($proof['status_code'] ?? 0),
            'body_length' => (int)($proof['body_length'] ?? 0),
            'elapsed_ms' => \is_finite($elapsedMs) ? $elapsedMs : -1.0,
            'target_ms' => \is_finite($targetMs) ? $targetMs : 0.0,
            'attempts' => (int)($proof['attempts'] ?? 0),
            'fpc_status' => \strtoupper(\trim((string)($proof['fpc_status'] ?? ''))),
            'cache' => \trim((string)($proof['cache'] ?? '')),
            'reason' => \trim((string)($proof['reason'] ?? '')),
        ];
    }

    /**
     * @return array{
     *     readiness_protocol_version:int,
     *     readiness_capabilities:list<string>,
     *     topology:string,
     *     policy_digest:string,
     *     container_registry_digest:string,
     *     warmup_state:string,
     *     homepage_fpc:array{hit:bool,fpc_status:string,source:string,full_uri:string,reason:string,http_status:int},
     *     dynamic_first_render:array{
     *         ready:bool,host:string,path:string,status_code:int,body_length:int,
     *         elapsed_ms:float,target_ms:float,attempts:int,fpc_status:string,
     *         cache:string,reason:string
     *     },
     *     listen_capabilities:array{
     *         bound:bool,reuseport:bool,mode:string,shared_listener:bool,inherited_fd:int,
     *         event_loop:string,ssl_engine:string,
     *         http3:array{
     *             mode:string,listener_bound:bool,datagram_channel_ready:bool,
     *             route_generation_ready:bool,port:int,native_digest:string,runtime_verified:bool
     *         }
     *     }
     * }
     */
    public static function snapshot(): array
    {
        return [
            'readiness_protocol_version' => self::READINESS_PROTOCOL_VERSION,
            'readiness_capabilities' => [
                self::CAPABILITY_DYNAMIC_FIRST_RENDER_PROOF,
                self::CAPABILITY_COMPILED_CONTAINER_DIGEST,
                self::CAPABILITY_HTTP3_QUIC_READY,
                self::CAPABILITY_HTTP3_LINUX_EBPF_ROUTE,
                self::CAPABILITY_HTTP3_TLS_TICKET_RING,
            ],
            'topology' => self::$topology,
            'policy_digest' => self::$policyDigest,
            'container_registry_digest' => ContainerRuntime::registryDigest(),
            'warmup_state' => self::$warmupState,
            'homepage_fpc' => self::$homepageFpcProof,
            'dynamic_first_render' => self::$dynamicFirstRenderProof,
            'listen_capabilities' => [
                'bound' => self::$listenerBound,
                'reuseport' => self::$reusePortBound,
                'mode' => self::$listenerMode,
                'shared_listener' => self::$sharedListener,
                'inherited_fd' => self::$inheritedFd,
                'event_loop' => self::$eventLoop,
                'ssl_engine' => self::$sslEngine,
                'http3' => [
                    'mode' => self::$http3Mode,
                    'listener_bound' => self::$http3ListenerBound,
                    'datagram_channel_ready' => self::$http3DatagramChannelReady,
                    'route_generation_ready' => self::$http3RouteGenerationReady,
                    'port' => self::$http3Port,
                    'native_digest' => self::$http3NativeDigest,
                    'runtime_verified' => self::$http3RuntimeVerified,
                    'tls_ticket_ring' => [
                        'active' => self::$http3TlsTicketRingActive,
                        'early_data_disabled' => self::$http3EarlyDataDisabled,
                        'epoch' => self::$http3TlsTicketRingEpoch,
                        'lifetime_seconds' => self::$http3TlsTicketLifetimeSeconds,
                        'digest' => self::$http3TlsTicketRingDigest,
                    ],
                    'activation_id' => self::$http3ActivationId,
                    'route' => self::$http3Route,
                ],
            ],
        ];
    }

    private static function markHttp3Ready(
        string $mode,
        int $port,
        string $nativeDigest,
        bool $runtimeVerified,
        bool $listenerBound,
        bool $datagramChannelReady,
        bool $routeGenerationReady,
    ): void {
        $nativeDigest = \strtolower(\trim($nativeDigest));
        $valid = \in_array($mode, ['reuseport-ebpf', 'datagram-router'], true)
            && $port > 0
            && $port <= 65535
            && $runtimeVerified
            && \preg_match('/^[a-f0-9]{64}$/D', $nativeDigest) === 1;
        self::$http3Mode = $valid ? $mode : '';
        self::$http3ListenerBound = $valid && $listenerBound;
        self::$http3DatagramChannelReady = $valid && $datagramChannelReady;
        self::$http3RouteGenerationReady = $valid && $routeGenerationReady;
        self::$http3Port = $valid ? $port : 0;
        self::$http3NativeDigest = $valid ? $nativeDigest : '';
        self::$http3RuntimeVerified = $valid;
    }

    /** @param array<string,mixed> $status @return array<string,int|string> */
    private static function normalizeLinuxRouteStatus(array $status, string $expectedState): array
    {
        $state = (int)($status['state'] ?? 0);
        $stateName = \strtolower(\trim((string)($status['state_name'] ?? '')));
        $normalizedState = $state === 1 && $stateName === 'staged'
            ? 'staged'
            : (($state === 2 && \in_array($stateName, ['active', 'activated'], true)) ? 'active' : '');
        $route = [
            'state' => $normalizedState,
            'slot' => (int)($status['slot'] ?? -1),
            'slot_count' => (int)($status['slot_count'] ?? 0),
            'owner_epoch' => (int)($status['owner_epoch'] ?? 0),
            'generation' => (int)($status['generation'] ?? 0),
            'listener_cookie' => (int)($status['listener_cookie'] ?? 0),
            'connection_cookie' => (int)($status['connection_cookie'] ?? 0),
            'program_id' => (int)($status['program_id'] ?? 0),
            'listen_map_id' => (int)($status['listen_map_id'] ?? 0),
            'worker_map_id' => (int)($status['worker_map_id'] ?? 0),
            'count_map_id' => (int)($status['count_map_id'] ?? 0),
            'owner_map_id' => (int)($status['owner_map_id'] ?? 0),
            'namespace_digest' => \strtolower(\trim((string)($status['namespace_digest'] ?? ''))),
        ];
        if ($route['state'] !== $expectedState
            || $route['slot'] < 0
            || $route['slot_count'] < 1
            || $route['slot_count'] > 64
            || $route['slot'] >= $route['slot_count']
            || $route['owner_epoch'] <= 0
            || $route['generation'] <= 0
            || $route['listener_cookie'] <= 0
            || $route['connection_cookie'] <= 0
            || $route['program_id'] <= 0
            || $route['listen_map_id'] <= 0
            || $route['worker_map_id'] <= 0
            || $route['count_map_id'] <= 0
            || $route['owner_map_id'] <= 0
            || \preg_match('/^[a-f0-9]{64}$/D', (string)$route['namespace_digest']) !== 1
        ) {
            return [];
        }
        return $route;
    }

    /** @param array<string,int|string> $left @param array<string,int|string> $right */
    private static function sameLinuxRouteIdentity(array $left, array $right): bool
    {
        foreach ([
            'slot', 'slot_count', 'owner_epoch', 'generation',
            'listener_cookie', 'connection_cookie', 'program_id',
            'listen_map_id', 'worker_map_id', 'count_map_id', 'owner_map_id',
            'namespace_digest',
        ] as $key) {
            if (($left[$key] ?? null) !== ($right[$key] ?? null)) {
                return false;
            }
        }
        return true;
    }

    /**
     * @return array{hit:bool,fpc_status:string,source:string,full_uri:string,reason:string,http_status:int}
     */
    private static function emptyHomepageFpcProof(): array
    {
        return [
            'hit' => false,
            'fpc_status' => '',
            'source' => '',
            'full_uri' => '',
            'reason' => '',
            'http_status' => 0,
        ];
    }

    /**
     * @return array{
     *     ready:bool,host:string,path:string,status_code:int,body_length:int,
     *     elapsed_ms:float,target_ms:float,attempts:int,fpc_status:string,
     *     cache:string,reason:string
     * }
     */
    private static function emptyDynamicFirstRenderProof(): array
    {
        return [
            'ready' => false,
            'host' => '',
            'path' => '',
            'status_code' => 0,
            'body_length' => 0,
            'elapsed_ms' => 0.0,
            'target_ms' => 0.0,
            'attempts' => 0,
            'fpc_status' => '',
            'cache' => '',
            'reason' => 'not-recorded',
        ];
    }
}
