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
    public const READINESS_PROTOCOL_VERSION = 3;
    public const CAPABILITY_DYNAMIC_FIRST_RENDER_PROOF = 'dynamic_first_render_proof_v1';
    public const CAPABILITY_COMPILED_CONTAINER_DIGEST = 'compiled_container_digest_v1';

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
        if (!$normalized['hit']
            || $normalized['fpc_status'] !== 'HIT'
            || !\str_starts_with($normalized['source'], 'process')
            || \preg_match('#^https?://#i', $normalized['full_uri']) !== 1
            || $normalized['http_status'] < 200
            || $normalized['http_status'] >= 400
        ) {
            throw new \InvalidArgumentException('Business Worker hot state requires a valid homepage process FPC proof.');
        }

        self::$homepageFpcProof = $normalized;
        self::$warmupState = 'hot';
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
     *         event_loop:string,ssl_engine:string
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
            ],
        ];
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
