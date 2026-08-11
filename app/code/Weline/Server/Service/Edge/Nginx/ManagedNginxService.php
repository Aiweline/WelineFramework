<?php

declare(strict_types=1);

namespace Weline\Server\Service\Edge\Nginx;

use Weline\Framework\App\Env;
use Weline\Framework\Runtime\SchedulerSystem;
use Weline\Server\Service\Edge\EdgeAdapterInterface;
use Weline\Server\Service\Edge\EdgeAdapterResolver;
use Weline\Server\Service\Edge\Gateway\GatewayProjectStateFilesystem;
use Weline\Server\Service\Edge\Gateway\ProjectCertificateGenerationStore;
use Weline\Server\Service\Edge\Nginx\Runtime\NginxLiveProbe;
use Weline\Server\Service\Edge\Nginx\Runtime\NginxConfigPublication;
use Weline\Server\Service\Runtime\RuntimeSelection;
use Weline\Server\Service\ServerInstanceManager;

/**
 * Facade for per-project managed nginx lifecycle used by CLI and server:start/stop.
 */
final class ManagedNginxService
{
    private const LIFECYCLE_LOCK_TIMEOUT_SECONDS = 90.0;
    private const MAX_HEALTH_RESPONSE_BYTES = 65536;
    private const MAX_HEALTH_HEADERS_BYTES = 65536;
    private const MAX_OWNER_RECOVERY_DIRECTORY_ENTRIES = 8192;
    private const MAX_OWNER_ATOMIC_TEMPORARIES_PER_TARGET = 8;
    private const MAX_OWNER_ATOMIC_TEMPORARIES_PER_DIRECTORY = 16;

    private ?float $activeLifecycleDeadlineMonotonic = null;

    public function __construct(
        private readonly ManagedNginxPaths $paths = new ManagedNginxPaths(),
        private readonly ManagedNginxInstaller $installer = new ManagedNginxInstaller(),
        private readonly ManagedNginxConfigWriter $configWriter = new ManagedNginxConfigWriter(),
        private readonly ManagedNginxProcessManager $processManager = new ManagedNginxProcessManager(),
        private readonly ManagedNginxPortAllocator $portAllocator = new ManagedNginxPortAllocator(),
        private readonly ManagedNginxTlsSessionResumptionVerifier $tlsSessionResumptionVerifier = new ManagedNginxTlsSessionResumptionVerifier(),
        private readonly NginxLiveProbe $liveProbe = new NginxLiveProbe(),
    ) {
    }

    public function isEdgeNginxManaged(): bool
    {
        $adapter = (new EdgeAdapterResolver())->resolve();
        return $adapter->name() === EdgeAdapterInterface::NAME_NGINX && $this->paths->managedEnabled();
    }

    public function paths(): ManagedNginxPaths
    {
        return $this->paths;
    }

    /**
     * @return array{ok:bool,message:string,manifest?:array<string,mixed>}
     */
    public function install(bool $force = false): array
    {
        return $this->withLifecycleLock(function () use ($force): array {
            $status = $this->processManager->status(
                $this->activeLifecycleDeadlineMonotonic,
            );
            if (!($status['ok'] ?? false)) {
                return ['ok' => false, 'message' => 'managed nginx PID identity is unsafe'];
            }
            if ($force && $status['running']) {
                return [
                    'ok' => false,
                    'message' => 'managed nginx is running; stop it before a forced binary replacement',
                ];
            }
            return $this->installer->ensureInstalled($force);
        });
    }

    /**
     * Write conf for upstream WLS port and start nginx.
     *
     * @param list<string> $serverNames
     * @return array{ok:bool,message:string,details?:array<string,mixed>}
     */
    public function prepareAndStart(
        int $upstreamPort,
        string $upstreamHost = '127.0.0.1',
        array $serverNames = [],
        string $ownerInstance = '',
        string $edgeAdapterName = EdgeAdapterInterface::NAME_NGINX,
        ?array $certificateGeneration = null,
    ): array
    {
        return $this->withLifecycleLock(
            fn(): array => $this->prepareAndStartUnlocked(
                $upstreamPort,
                $upstreamHost,
                $serverNames,
                $ownerInstance,
                $edgeAdapterName,
                $certificateGeneration,
            ),
        );
    }

    /** @param list<string> $serverNames @return array{ok:bool,message:string,details?:array<string,mixed>} */
    private function prepareAndStartUnlocked(
        int $upstreamPort,
        string $upstreamHost,
        array $serverNames,
        string $ownerInstance,
        string $edgeAdapterName,
        ?array $certificateGeneration,
    ): array {
        if ($edgeAdapterName !== EdgeAdapterInterface::NAME_NGINX) {
            return ['ok' => false, 'message' => 'Nginx is the only supported public edge adapter'];
        }
        if (!$this->paths->managedEnabled()) {
            return [
                'ok' => false,
                'message' => 'Nginx-only lifecycle requires wls.edge.nginx.managed=true',
            ];
        }
        $ownerInstance = \trim($ownerInstance);
        if ($ownerInstance === '') {
            return ['ok' => false, 'message' => 'managed nginx start requires an explicit edge owner instance'];
        }
        $previousOwner = $this->readOwner();
        $currentStatus = $this->processManager->status(
            $this->activeLifecycleDeadlineMonotonic,
        );
        if (!($currentStatus['ok'] ?? false)) {
            return [
                'ok' => false,
                'message' => 'managed nginx PID identity is unsafe: '
                    . (string)($currentStatus['message'] ?? 'unknown'),
            ];
        }
        if (\is_array($previousOwner)
            && !\hash_equals((string)$previousOwner['instance_name'], $ownerInstance)
        ) {
            return [
                'ok' => false,
                'message' => 'managed nginx is owned by instance '
                    . (string)$previousOwner['instance_name']
                    . '; stop that edge explicitly before assigning a different upstream',
            ];
        }
        if ($previousOwner === null && (bool)$currentStatus['running']) {
            return [
                'ok' => false,
                'message' => 'managed nginx is already running without a verifiable owner; refusing upstream takeover',
            ];
        }
        if (!$this->paths->isInstalled()) {
            return [
                'ok' => false,
                'message' => 'managed nginx is required and is not installed; normal WLS startup never '
                    . 'downloads or compiles it. Run php bin/w server:nginx:install explicitly.',
            ];
        }
        $identity = $this->installedBinaryIdentity();
        if (!($identity['ok'] ?? false)) {
            return $identity;
        }
        $capabilities = (array)$identity['capabilities'];
        $backendIdentity = $this->resolveWlsBackendIdentity($ownerInstance, $upstreamPort);
        if ($backendIdentity === null) {
            return [
                'ok' => false,
                'message' => 'managed nginx could not bind the requested owner to a canonical WLS backend identity',
            ];
        }
        $upstreamPorts = $backendIdentity['upstream_ports'];
        $candidate = null;
        $rollback = null;
        $wasRunning = false;
        $startedByCall = false;
        $published = false;
        $ownerIntent = null;
        try {
            $written = $this->configWriter->write(
                $upstreamPort,
                $upstreamHost,
                $serverNames,
                true,
                (bool)($capabilities['gzip_module'] ?? false),
                true,
                (bool)($capabilities['http3_module'] ?? false)
                    && $this->http3VerifierAvailable(),
                $upstreamPorts,
                $certificateGeneration,
            );
            if (!(bool)($written['ssl'] ?? false)) {
                $this->configWriter->discardCandidate((string)$written['conf']);
                return [
                    'ok' => false,
                    'message' => 'managed nginx requires certificate material and a TLS 1.3 public endpoint',
                ];
            }
            $candidate = (string)$written['conf'];
            if (!$this->probeWlsBackendPool(
                $upstreamHost,
                $upstreamPorts,
                $ownerInstance,
                $backendIdentity,
            )) {
                $this->configWriter->discardCandidate($candidate);
                return [
                    'ok' => false,
                    'message' => 'managed nginx upstream did not prove a healthy loopback WLS backend; '
                        . 'refusing to publish a potentially recursive edge route',
                ];
            }

            $test = $this->processManager->testConfig(
                $candidate,
                $this->activeLifecycleDeadlineMonotonic,
            );
            if (($test['code'] ?? 1) !== 0) {
                $this->configWriter->discardCandidate($candidate);
                return [
                    'ok' => false,
                    'message' => 'managed nginx candidate failed nginx -t: '
                        . \trim((string)($test['output'] ?? '')),
                ];
            }
            $transactionId = \bin2hex(\random_bytes(16));
            $previousOwnerContents = $this->captureOwnerBeforeImage($previousOwner);
            $configRollbackExpected = \is_file($this->paths->confFile());
            $previousConfigSha256 = $configRollbackExpected
                ? $this->stableManagedFileSha256(
                    $this->paths->confFile(),
                    'Managed Nginx previous active config',
                )
                : null;
            if ($configRollbackExpected && !\is_string($previousConfigSha256)) {
                throw new \RuntimeException(
                    'Managed nginx previous active config could not be snapshotted.',
                );
            }
            $ownerIntent = [
                'transaction_id' => $transactionId,
                'instance_name' => $ownerInstance,
                'upstream_host' => $upstreamHost,
                'upstream_port' => $upstreamPort,
                'upstream_ports' => $upstreamPorts,
                'server_names' => (array)($written['server_names'] ?? []),
                'listen_http' => (int)$written['http'],
                'listen_https' => (int)$written['https'],
                'ssl_required' => (bool)($written['ssl'] ?? false),
                'ssl_certificate_sha256' => (string)($written['ssl_certificate_sha256'] ?? ''),
                'config_generation' => (string)$written['config_generation'],
                'config_rollback_expected' => $configRollbackExpected,
                'previous_config_sha256' => (string)($previousConfigSha256 ?? ''),
                'owner_rollback_expected' => $previousOwnerContents !== null,
                'previous_owner_sha256' => $previousOwnerContents !== null
                    ? \hash('sha256', $previousOwnerContents)
                    : '',
                ...$this->certificateGenerationFacts($written),
                ...$this->protocolFacts($written, $capabilities),
                'updated_at' => \date('c'),
            ];
            $this->writeOwnerIntent($ownerIntent);
            $this->stageOwnerRollback($ownerIntent, $previousOwnerContents);
            $status = $this->processManager->status(
                $this->activeLifecycleDeadlineMonotonic,
            );
            if (!($status['ok'] ?? false)) {
                throw new \RuntimeException(
                    'Managed nginx PID identity became unsafe before config publication.',
                );
            }
            $wasRunning = (bool)$status['running'];
            $publication = $this->configWriter->publishCandidate($candidate, $transactionId);
            $candidate = null;
            $published = true;
            $rollback = \is_string($publication['rollback'] ?? null)
                ? $publication['rollback']
                : null;
            $lifecycle = $wasRunning
                ? $this->processManager->reload(
                    $this->activeLifecycleDeadlineMonotonic,
                )
                : $this->processManager->start(
                    $this->activeLifecycleDeadlineMonotonic,
                );
            $startedByCall = !$wasRunning && (bool)($lifecycle['ok'] ?? false);
            if (!($lifecycle['ok'] ?? false)) {
                $recovery = $this->restorePublishedConfig(
                    $rollback,
                    $wasRunning,
                    $startedByCall,
                    $ownerIntent,
                );
                return [
                    'ok' => false,
                    'message' => 'managed nginx lifecycle rejected the candidate: '
                        . (string)($lifecycle['message'] ?? 'unknown') . $recovery,
                ];
            }
            if (!$this->probeConfigGeneration((int)$written['http'], (string)$written['config_generation'])) {
                $recovery = $this->restorePublishedConfig(
                    $rollback,
                    $wasRunning,
                    $startedByCall,
                    $ownerIntent,
                );
                return [
                    'ok' => false,
                    'message' => 'managed nginx did not serve the published config generation' . $recovery,
                ];
            }
            if (!(bool)($written['ssl'] ?? false)
                || !$this->probeTls13(
                    (int)$written['https'],
                    (array)($written['server_names'] ?? []),
                    (string)$written['config_generation'],
                    (string)($written['ssl_certificate_sha256'] ?? ''),
                )
            ) {
                $recovery = $this->restorePublishedConfig(
                    $rollback,
                    $wasRunning,
                    $startedByCall,
                    $ownerIntent,
                );
                return [
                    'ok' => false,
                    'message' => 'managed nginx did not complete a live TLS 1.3 certificate-bound handshake'
                        . $recovery,
                ];
            }
            if (!(bool)($written['http2_enabled'] ?? false)
                || !$this->verifyHttpRuntime(
                    '2',
                    (int)$written['https'],
                    (array)($written['server_names'] ?? []),
                    (string)$written['config_generation'],
                    $ownerInstance,
                    $upstreamPort,
                )
            ) {
                $recovery = $this->restorePublishedConfig(
                    $rollback,
                    $wasRunning,
                    $startedByCall,
                    $ownerIntent,
                );
                return [
                    'ok' => false,
                    'message' => 'managed nginx did not carry an owner-bound HTTP/2 WLS health request'
                        . $recovery,
                ];
            }
            if (!$this->verifyHttpRuntime(
                '1.1',
                (int)$written['https'],
                (array)($written['server_names'] ?? []),
                (string)$written['config_generation'],
                $ownerInstance,
                $upstreamPort,
            )) {
                $recovery = $this->restorePublishedConfig(
                    $rollback,
                    $wasRunning,
                    $startedByCall,
                    $ownerIntent,
                );
                return [
                    'ok' => false,
                    'message' => 'managed nginx did not carry an owner-bound HTTP/1.1 WLS health request'
                        . $recovery,
                ];
            }
            $httpRuntimeEvidence = [
                'tls13_runtime_verified' => true,
                'http2_runtime_verified' => true,
                'http1_runtime_verified' => true,
                'public_protocols_runtime_verified' => ['http/2', 'http/1.1'],
            ];
            $currentStatus = $this->processManager->status(
                $this->activeLifecycleDeadlineMonotonic,
            );
            if (!($currentStatus['ok'] ?? false) || !($currentStatus['running'] ?? false)) {
                $recovery = $this->restorePublishedConfig(
                    $rollback,
                    $wasRunning,
                    $startedByCall,
                    $ownerIntent,
                );
                return [
                    'ok' => false,
                    'message' => 'managed nginx exited or changed identity after live verification' . $recovery,
                ];
            }
            $http3 = $this->verifyHttp3Runtime(
                (bool)($written['http3_enabled'] ?? false),
                (bool)($capabilities['http3_module'] ?? false),
                (int)$written['https'],
                (int)$currentStatus['pid'],
                (string)$written['config_generation'],
                (string)$written['config_sha256'],
                (string)($written['ssl_certificate_sha256'] ?? ''),
                (array)($written['server_names'] ?? []),
                $ownerInstance,
                $upstreamPort,
            );
            if (!($http3['ok'] ?? false)) {
                if (!$this->http3FailureCanDegrade($written, $http3)) {
                    $recovery = $this->restorePublishedConfig(
                        $rollback,
                        $wasRunning,
                        $startedByCall,
                        $ownerIntent,
                    );
                    return [
                        'ok' => false,
                        'message' => 'managed nginx HTTP/3 runtime verification failed: '
                            . (string)($http3['message'] ?? 'unknown') . $recovery,
                    ];
                }
                $degraded = $this->degradeFailedHttp3Publication(
                    $written,
                    $http3,
                    $capabilities,
                    $ownerIntent,
                    $transactionId,
                    $rollback,
                    $upstreamPort,
                    $upstreamHost,
                    $upstreamPorts,
                    $ownerInstance,
                    $certificateGeneration,
                    (int)$currentStatus['pid'],
                );
                $written = $degraded['config'];
                $ownerIntent = $degraded['owner_intent'];
                $currentStatus = $degraded['status'];
                $httpRuntimeEvidence = $degraded['http_runtime_evidence'];
                $http3 = $degraded['http3'];
            }
            $resumption = $this->tlsSessionResumptionVerifier->verify(
                (int)$written['https'],
                (array)($written['server_names'] ?? []),
                (int)$currentStatus['pid'],
                (string)$written['config_generation'],
                (string)$written['config_sha256'],
                (string)($written['ssl_certificate_sha256'] ?? ''),
            );
            if (!($resumption['ok'] ?? false)) {
                $recovery = $this->restorePublishedConfig(
                    $rollback,
                    $wasRunning,
                    $startedByCall,
                    $ownerIntent,
                );
                return [
                    'ok' => false,
                    'message' => 'managed nginx TLS session resumption verification failed: '
                        . (string)($resumption['message'] ?? 'unknown') . $recovery,
                ];
            }
            $verifiedStatus = $this->processManager->status(
                $this->activeLifecycleDeadlineMonotonic,
            );
            if (!($verifiedStatus['ok'] ?? false)
                || !($verifiedStatus['running'] ?? false)
                || (int)($verifiedStatus['pid'] ?? 0) !== (int)$currentStatus['pid']
            ) {
                $recovery = $this->restorePublishedConfig(
                    $rollback,
                    $wasRunning,
                    $startedByCall,
                    $ownerIntent,
                );
                return [
                    'ok' => false,
                    'message' => 'managed nginx master identity changed during TLS session verification' . $recovery,
                ];
            }
            $ownerIntent = [
                ...$ownerIntent,
                ...$httpRuntimeEvidence,
                ...(array)($http3['evidence'] ?? []),
                ...(array)($resumption['evidence'] ?? []),
                'updated_at' => \date('c'),
            ];
            $this->writeOwnerIntent($ownerIntent);
            $currentStatus = $verifiedStatus;
            $commit = $this->commitVerifiedPublication(
                $ownerIntent,
                $rollback,
                $wasRunning,
                $startedByCall,
            );
            $published = false;
            if (!($commit['ok'] ?? false)) {
                return [
                    'ok' => false,
                    'message' => (string)($commit['message']
                        ?? 'managed nginx publication could not be committed'),
                ];
            }
            $cleanupWarning = (string)($commit['cleanup_warning'] ?? '');
            return [
                'ok' => true,
                'message' => ($wasRunning
                    ? 'managed nginx candidate verified and reloaded'
                    : 'managed nginx candidate verified and started')
                    . ($cleanupWarning !== '' ? '; ' . $cleanupWarning : ''),
                'details' => [
                    'listen_http' => $written['http'],
                    'listen_https' => $written['https'],
                    'upstream' => $written['upstream'],
                    'upstreams' => (array)($written['upstreams'] ?? []),
                    'conf' => $this->paths->confFile(),
                    'pid' => $currentStatus['pid'] ?? null,
                    'ssl' => $written['ssl'] ?? false,
                    'config_generation' => $written['config_generation'],
                    'server_names' => (array)($written['server_names'] ?? []),
                    'ssl_certificate_sha256' => (string)($written['ssl_certificate_sha256'] ?? ''),
                    ...$this->protocolFacts($written, $capabilities),
                    ...$httpRuntimeEvidence,
                    ...(array)($http3['evidence'] ?? []),
                    ...(array)($resumption['evidence'] ?? []),
                    'publication_cleanup_warning' => $cleanupWarning,
                ],
            ];
        } catch (\Throwable $e) {
            if ($candidate !== null) {
                try {
                    $this->configWriter->discardCandidate($candidate);
                } catch (\Throwable) {
                    // Transaction recovery below remains authoritative.
                }
            }
            $recoveryFailure = '';
            if (\is_array($ownerIntent)
                && $this->pathExistsNoFollow($this->paths->ownerIntentFile())
            ) {
                try {
                    $this->recoverOwnerPublication();
                    $published = false;
                } catch (\Throwable $recovery) {
                    $recoveryFailure = '; transaction recovery failed: '
                        . $recovery->getMessage();
                }
            }
            return ['ok' => false, 'message' => $e->getMessage() . $recoveryFailure];
        }
    }

    /**
     * @return array{ok:bool,message:string}
     */
    public function stop(?float $deadlineMonotonic = null): array
    {
        return $this->withLifecycleLock(function () use ($deadlineMonotonic): array {
            $status = $this->processManager->status(
                $this->activeLifecycleDeadlineMonotonic,
            );
            if (!($status['ok'] ?? false)) {
                return [
                    'ok' => false,
                    'message' => 'refusing owner cleanup because managed nginx PID identity is unsafe: '
                        . (string)($status['message'] ?? 'identity unavailable'),
                ];
            }
            if (!$status['running']) {
                $this->clearOwner();
                if (!$this->paths->managedEnabled()) {
                    return ['ok' => true, 'message' => 'managed nginx disabled and not running'];
                }
                return ['ok' => true, 'message' => 'managed nginx is not running'];
            }
            $result = $this->processManager->stop(
                $this->activeLifecycleDeadlineMonotonic,
            );
            if ($result['ok'] ?? false) {
                $this->clearOwner();
            }
            return $result;
        }, $deadlineMonotonic);
    }

    /** @return array{ok:bool,message:string,stopped?:bool,owner_matched?:bool} */
    public function stopForInstance(
        string $instanceName,
        ?float $deadlineMonotonic = null,
    ): array {
        return $this->withLifecycleLock(function () use ($instanceName): array {
            $status = $this->processManager->status(
                $this->activeLifecycleDeadlineMonotonic,
            );
            if (!($status['ok'] ?? false)) {
                return [
                    'ok' => false,
                    'message' => 'refusing instance stop because managed nginx PID identity is unsafe',
                    'stopped' => false,
                    'owner_matched' => false,
                ];
            }
            $owner = $this->readOwner();
            if (!\is_array($owner)) {
                return ($status['running'] ?? false)
                    ? [
                        'ok' => false,
                        'message' => 'managed nginx is running without a verifiable owner; '
                            . 'use explicit server:nginx:stop after identity review',
                        'stopped' => false,
                        'owner_matched' => false,
                    ]
                    : [
                        'ok' => true,
                        'message' => 'managed nginx is not running',
                        'stopped' => true,
                        'owner_matched' => false,
                    ];
            }
            if (!\hash_equals((string)$owner['instance_name'], \trim($instanceName))) {
                return [
                    'ok' => true,
                    'message' => 'managed nginx is owned by another WLS instance; left running',
                    'stopped' => false,
                    'owner_matched' => false,
                ];
            }
            $result = $this->processManager->stop(
                $this->activeLifecycleDeadlineMonotonic,
            );
            if ($result['ok'] ?? false) {
                $this->clearOwner();
            }
            $result['stopped'] = ($result['ok'] ?? false) === true;
            $result['owner_matched'] = true;
            return $result;
        }, $deadlineMonotonic);
    }

    /**
     * Minimal exact-owner observation used to fence promotion rollback.
     *
     * @return array{
     *   ok:bool,
     *   running:bool,
     *   runtime_owner_active:bool,
     *   owner_instance:string,
     *   message:string
     * }
     */
    public function promotionRollbackOwnerSnapshot(
        ?float $deadlineMonotonic = null,
    ): array
    {
        $snapshot = $this->withLifecycleLock(function (): array {
            $status = $this->processManager->status(
                $this->activeLifecycleDeadlineMonotonic,
            );
            if (($status['ok'] ?? false) !== true) {
                return [
                    'ok' => false,
                    'running' => (bool)($status['running'] ?? false),
                    'runtime_owner_active' => false,
                    'owner_instance' => '',
                    'message' => 'managed nginx PID identity is unsafe',
                ];
            }
            $owner = $this->readOwner();
            $activeConfigSha256 = $this->stableManagedFileSha256(
                $this->paths->confFile(),
                'Managed Nginx active config',
            );
            $ownerConfigBound = \is_array($owner)
                && \is_string($activeConfigSha256)
                && \preg_match(
                    '/\A[a-f0-9]{64}\z/D',
                    (string)($owner['config_sha256'] ?? ''),
                ) === 1
                && \hash_equals(
                    (string)$owner['config_sha256'],
                    \strtolower($activeConfigSha256),
                );
            $running = (bool)($status['running'] ?? false);
            return [
                'ok' => true,
                'running' => $running,
                'runtime_owner_active' => $running && $ownerConfigBound,
                'owner_instance' => \is_array($owner)
                    ? (string)($owner['instance_name'] ?? '')
                    : '',
                'message' => 'managed nginx rollback ownership observed',
            ];
        }, $deadlineMonotonic);
        return [
            'ok' => ($snapshot['ok'] ?? false) === true,
            'running' => (bool)($snapshot['running'] ?? false),
            'runtime_owner_active' => (bool)(
                $snapshot['runtime_owner_active'] ?? false
            ),
            'owner_instance' => (string)($snapshot['owner_instance'] ?? ''),
            'message' => (string)(
                $snapshot['message'] ?? 'managed nginx rollback ownership is unavailable'
            ),
        ];
    }

    /**
     * @return array{ok:bool,message:string,exit_code?:int|null}
     */
    public function reload(?float $deadlineMonotonic = null): array
    {
        return $this->withLifecycleLock(
            fn(): array => $this->reloadUnlocked(),
            $deadlineMonotonic,
        );
    }

    /** @return array{ok:bool,message:string,exit_code?:int|null} */
    private function reloadUnlocked(): array
    {
        $identity = $this->installedBinaryIdentity();
        if (!($identity['ok'] ?? false)) {
            return $identity;
        }
        $capabilities = (array)($identity['capabilities'] ?? []);
        $status = $this->processManager->status(
            $this->activeLifecycleDeadlineMonotonic,
        );
        if (!($status['ok'] ?? false)) {
            return [
                'ok' => false,
                'message' => 'refusing reload because managed nginx PID identity is unsafe',
                'exit_code' => null,
            ];
        }
        if (!$status['running']) {
            return ['ok' => false, 'message' => 'managed nginx is not running', 'exit_code' => null];
        }
        $owner = $this->readOwner();
        if (!\is_array($owner)) {
            return [
                'ok' => false,
                'message' => 'managed nginx is running without a verifiable owner; refusing reload',
                'exit_code' => null,
            ];
        }
        $backendIdentity = $this->resolveWlsBackendIdentity(
            (string)$owner['instance_name'],
            (int)$owner['upstream_port'],
        );
        if ($backendIdentity === null) {
            return [
                'ok' => false,
                'message' => 'managed nginx owner no longer resolves to a canonical WLS backend identity',
                'exit_code' => 1,
            ];
        }
        $upstreamPorts = $backendIdentity['upstream_ports'];

        $candidate = null;
        $rollback = null;
        $published = false;
        $refreshedOwner = null;
        $reloadContinuityProbe = null;
        $reloadContinuityEvidence = [];
        try {
            $certificateGeneration = $this->resolveOwnerCertificateGeneration($owner);
            $refreshed = $this->configWriter->write(
                (int)$owner['upstream_port'],
                (string)$owner['upstream_host'],
                (array)($owner['server_names'] ?? []),
                true,
                (bool)($capabilities['gzip_module'] ?? false),
                true,
                (bool)($capabilities['http3_module'] ?? false)
                    && $this->http3VerifierAvailable(),
                $upstreamPorts,
                $certificateGeneration,
            );
            $candidate = (string)$refreshed['conf'];
            if (!$this->probeWlsBackendPool(
                (string)$owner['upstream_host'],
                $upstreamPorts,
                (string)$owner['instance_name'],
                $backendIdentity,
            )) {
                $this->configWriter->discardCandidate($candidate);
                return [
                    'ok' => false,
                    'message' => 'managed nginx reload refused because its loopback WLS backend is not healthy',
                    'exit_code' => 1,
                ];
            }

            $tlsConfigured = (bool)($refreshed['ssl'] ?? false);
            if (!$tlsConfigured) {
                $this->configWriter->discardCandidate($candidate);
                return [
                    'ok' => false,
                    'message' => 'managed nginx reload requires certificate material and TLS 1.3',
                    'exit_code' => 1,
                ];
            }
            $test = $this->processManager->testConfig(
                $candidate,
                $this->activeLifecycleDeadlineMonotonic,
            );
            if (($test['code'] ?? 1) !== 0) {
                $this->configWriter->discardCandidate($candidate);
                return [
                    'ok' => false,
                    'message' => 'managed nginx reload candidate failed nginx -t: '
                        . \trim((string)($test['output'] ?? '')),
                    'exit_code' => $test['code'] ?? 1,
                ];
            }
            $previousCertificateSha256 = \strtolower(
                \trim((string)($owner['ssl_certificate_sha256'] ?? ''))
            );
            $nextCertificateSha256 = \strtolower(
                \trim((string)($refreshed['ssl_certificate_sha256'] ?? ''))
            );
            if (\preg_match('/\A[a-f0-9]{64}\z/D', $previousCertificateSha256) === 1
                && \hash_equals($previousCertificateSha256, $nextCertificateSha256)
            ) {
                $reloadContinuityProbe = $this->tlsSessionResumptionVerifier
                    ->beginReloadContinuityProbe(
                        (int)$refreshed['https'],
                        (array)($owner['server_names'] ?? []),
                        (int)$status['pid'],
                        (string)$owner['config_generation'],
                        $previousCertificateSha256,
                    );
            }
            $transactionId = \bin2hex(\random_bytes(16));
            $previousOwnerContents = $this->captureOwnerBeforeImage($owner);
            if (!\is_string($previousOwnerContents)) {
                throw new \RuntimeException(
                    'Managed nginx reload owner before-image is unavailable.',
                );
            }
            $previousConfigSha256 = $this->stableManagedFileSha256(
                $this->paths->confFile(),
                'Managed Nginx previous active config',
            );
            if (!\is_string($previousConfigSha256)) {
                throw new \RuntimeException(
                    'Managed nginx reload config before-image is unavailable.',
                );
            }
            $refreshedOwner = [
                ...$owner,
                'transaction_id' => $transactionId,
                'upstream_ports' => $upstreamPorts,
                'server_names' => (array)($refreshed['server_names'] ?? []),
                'ssl_required' => $tlsConfigured,
                'listen_http' => (int)$refreshed['http'],
                'listen_https' => (int)$refreshed['https'],
                'ssl_certificate_sha256' => (string)($refreshed['ssl_certificate_sha256'] ?? ''),
                'config_generation' => (string)$refreshed['config_generation'],
                'config_rollback_expected' => true,
                'previous_config_sha256' => $previousConfigSha256,
                'owner_rollback_expected' => true,
                'previous_owner_sha256' => \hash('sha256', $previousOwnerContents),
                ...$this->certificateGenerationFacts($refreshed),
                ...$this->protocolFacts($refreshed, $capabilities),
                'updated_at' => \date('c'),
            ];
            foreach ([
                'tls_session_resumption_reload_continuity_verified',
                'tls_session_resumption_reload_continuity_status',
                'tls_session_resumption_reload_continuity_proof_model',
                'tls_session_resumption_reload_continuity_result',
                'tls_session_resumption_reload_issuer_worker_pid',
                'tls_session_resumption_reload_probe_worker_pid',
                'tls_session_resumption_reload_master_pid',
                'tls_session_resumption_reload_tls_handshake_us',
                'tls_session_resumption_reload_previous_config_generation',
                'tls_session_resumption_reload_config_generation',
                'tls_session_resumption_reload_verified_at',
            ] as $staleReloadContinuityKey) {
                unset($refreshedOwner[$staleReloadContinuityKey]);
            }
            $this->writeOwnerIntent($refreshedOwner);
            $this->stageOwnerRollback($refreshedOwner, $previousOwnerContents);
            $publication = $this->configWriter->publishCandidate($candidate, $transactionId);
            $candidate = null;
            $published = true;
            $rollback = \is_string($publication['rollback'] ?? null)
                ? $publication['rollback']
                : null;
            $reloaded = $this->processManager->reload(
                $this->activeLifecycleDeadlineMonotonic,
            );
            if (!($reloaded['ok'] ?? false)
                || !$this->probeConfigGeneration(
                    (int)$refreshed['http'],
                    (string)$refreshed['config_generation'],
                )
                || !$tlsConfigured
                || !$this->probeTls13(
                    (int)$refreshed['https'],
                    (array)($refreshed['server_names'] ?? []),
                    (string)$refreshed['config_generation'],
                    (string)($refreshed['ssl_certificate_sha256'] ?? ''),
                )
            ) {
                $recovery = $this->restorePublishedConfig(
                    $rollback,
                    true,
                    false,
                    $refreshedOwner,
                );
                return [
                    'ok' => false,
                    'message' => 'managed nginx reload did not activate the verified config/TLS generation: '
                        . (string)($reloaded['message'] ?? 'unknown') . $recovery,
                    'exit_code' => $reloaded['exit_code'] ?? 1,
                ];
            }
            if (!(bool)($refreshed['http2_enabled'] ?? false)
                || !$this->verifyHttpRuntime(
                    '2',
                    (int)$refreshed['https'],
                    (array)($refreshed['server_names'] ?? []),
                    (string)$refreshed['config_generation'],
                    (string)$owner['instance_name'],
                    (int)$owner['upstream_port'],
                )
            ) {
                $recovery = $this->restorePublishedConfig(
                    $rollback,
                    true,
                    false,
                    $refreshedOwner,
                );
                return [
                    'ok' => false,
                    'message' => 'managed nginx reload did not carry an owner-bound HTTP/2 WLS health request'
                        . $recovery,
                    'exit_code' => 1,
                ];
            }
            if (!$this->verifyHttpRuntime(
                '1.1',
                (int)$refreshed['https'],
                (array)($refreshed['server_names'] ?? []),
                (string)$refreshed['config_generation'],
                (string)$owner['instance_name'],
                (int)$owner['upstream_port'],
            )) {
                $recovery = $this->restorePublishedConfig(
                    $rollback,
                    true,
                    false,
                    $refreshedOwner,
                );
                return [
                    'ok' => false,
                    'message' => 'managed nginx reload did not carry an owner-bound HTTP/1.1 WLS health request'
                        . $recovery,
                    'exit_code' => 1,
                ];
            }
            $httpRuntimeEvidence = [
                'tls13_runtime_verified' => true,
                'http2_runtime_verified' => true,
                'http1_runtime_verified' => true,
                'public_protocols_runtime_verified' => ['http/2', 'http/1.1'],
            ];
            $currentStatus = $this->processManager->status(
                $this->activeLifecycleDeadlineMonotonic,
            );
            if (!($currentStatus['ok'] ?? false) || !($currentStatus['running'] ?? false)) {
                $recovery = $this->restorePublishedConfig(
                    $rollback,
                    true,
                    false,
                    $refreshedOwner,
                );
                return [
                    'ok' => false,
                    'message' => 'managed nginx exited or changed identity after reload verification' . $recovery,
                    'exit_code' => 1,
                ];
            }
            $http3 = $this->verifyHttp3Runtime(
                (bool)($refreshed['http3_enabled'] ?? false),
                (bool)($capabilities['http3_module'] ?? false),
                (int)$refreshed['https'],
                (int)$currentStatus['pid'],
                (string)$refreshed['config_generation'],
                (string)$refreshed['config_sha256'],
                (string)($refreshed['ssl_certificate_sha256'] ?? ''),
                (array)($refreshed['server_names'] ?? []),
                (string)$owner['instance_name'],
                (int)$owner['upstream_port'],
            );
            if (!($http3['ok'] ?? false)) {
                if (!$this->http3FailureCanDegrade($refreshed, $http3)) {
                    $recovery = $this->restorePublishedConfig(
                        $rollback,
                        true,
                        false,
                        $refreshedOwner,
                    );
                    return [
                        'ok' => false,
                        'message' => 'managed nginx reload HTTP/3 runtime verification failed: '
                            . (string)($http3['message'] ?? 'unknown') . $recovery,
                        'exit_code' => 1,
                    ];
                }
                $degraded = $this->degradeFailedHttp3Publication(
                    $refreshed,
                    $http3,
                    $capabilities,
                    $refreshedOwner,
                    $transactionId,
                    $rollback,
                    (int)$owner['upstream_port'],
                    (string)$owner['upstream_host'],
                    $upstreamPorts,
                    (string)$owner['instance_name'],
                    $certificateGeneration,
                    (int)$currentStatus['pid'],
                );
                $refreshed = $degraded['config'];
                $refreshedOwner = $degraded['owner_intent'];
                $currentStatus = $degraded['status'];
                $httpRuntimeEvidence = $degraded['http_runtime_evidence'];
                $http3 = $degraded['http3'];
            }
            if (\is_array($reloadContinuityProbe)) {
                $reloadContinuity = $this->tlsSessionResumptionVerifier
                    ->completeReloadContinuityProbe(
                        $reloadContinuityProbe,
                        (int)$refreshed['https'],
                        (int)$currentStatus['pid'],
                        (string)$refreshed['config_generation'],
                        (string)($refreshed['ssl_certificate_sha256'] ?? ''),
                    );
                if (!($reloadContinuity['ok'] ?? false)) {
                    $recovery = $this->restorePublishedConfig(
                        $rollback,
                        true,
                        false,
                        $refreshedOwner,
                    );
                    return [
                        'ok' => false,
                        'message' => 'managed nginx reload TLS Session continuity verification failed: '
                            . (string)($reloadContinuity['message'] ?? 'unknown') . $recovery,
                        'exit_code' => 1,
                    ];
                }
                $reloadContinuityEvidence = (array)($reloadContinuity['evidence'] ?? []);
            }
            $resumption = $this->tlsSessionResumptionVerifier->verify(
                (int)$refreshed['https'],
                (array)($refreshed['server_names'] ?? []),
                (int)$currentStatus['pid'],
                (string)$refreshed['config_generation'],
                (string)$refreshed['config_sha256'],
                (string)($refreshed['ssl_certificate_sha256'] ?? ''),
            );
            if (!($resumption['ok'] ?? false)) {
                $recovery = $this->restorePublishedConfig(
                    $rollback,
                    true,
                    false,
                    $refreshedOwner,
                );
                return [
                    'ok' => false,
                    'message' => 'managed nginx reload TLS session resumption verification failed: '
                        . (string)($resumption['message'] ?? 'unknown') . $recovery,
                    'exit_code' => 1,
                ];
            }
            $verifiedStatus = $this->processManager->status(
                $this->activeLifecycleDeadlineMonotonic,
            );
            if (!($verifiedStatus['ok'] ?? false)
                || !($verifiedStatus['running'] ?? false)
                || (int)($verifiedStatus['pid'] ?? 0) !== (int)$currentStatus['pid']
            ) {
                $recovery = $this->restorePublishedConfig(
                    $rollback,
                    true,
                    false,
                    $refreshedOwner,
                );
                return [
                    'ok' => false,
                    'message' => 'managed nginx master identity changed during reload TLS session verification'
                        . $recovery,
                    'exit_code' => 1,
                ];
            }
            $refreshedOwner = [
                ...$refreshedOwner,
                ...$httpRuntimeEvidence,
                ...(array)($http3['evidence'] ?? []),
                ...(array)($resumption['evidence'] ?? []),
                ...$reloadContinuityEvidence,
                'updated_at' => \date('c'),
            ];
            $this->writeOwnerIntent($refreshedOwner);
            $currentStatus = $verifiedStatus;
            $commit = $this->commitVerifiedPublication(
                $refreshedOwner,
                $rollback,
                true,
                false,
            );
            $published = false;
            if (!($commit['ok'] ?? false)) {
                return [
                    'ok' => false,
                    'message' => (string)($commit['message']
                        ?? 'reload publication could not be committed'),
                    'exit_code' => 1,
                ];
            }
            $cleanupWarning = (string)($commit['cleanup_warning'] ?? '');
            return [
                'ok' => true,
                'message' => 'configuration candidate tested, activated, and verified'
                    . ($cleanupWarning !== '' ? '; ' . $cleanupWarning : ''),
                'exit_code' => $reloaded['exit_code'] ?? 0,
            ];
        } catch (\Throwable $exception) {
            if ($candidate !== null) {
                try {
                    $this->configWriter->discardCandidate($candidate);
                } catch (\Throwable) {
                    // Transaction recovery below remains authoritative.
                }
            }
            $recoveryFailure = '';
            if (\is_array($refreshedOwner)
                && $this->pathExistsNoFollow($this->paths->ownerIntentFile())
            ) {
                try {
                    $this->recoverOwnerPublication();
                    $published = false;
                } catch (\Throwable $recovery) {
                    $recoveryFailure = '; transaction recovery failed: '
                        . $recovery->getMessage();
                }
            }
            return [
                'ok' => false,
                'message' => $exception->getMessage() . $recoveryFailure,
                'exit_code' => 1,
            ];
        } finally {
            if (\is_array($reloadContinuityProbe)) {
                $this->tlsSessionResumptionVerifier
                    ->releaseReloadContinuityProbe($reloadContinuityProbe);
            }
        }
    }
    /**
     * @param array<string,mixed> $written
     * @param array<string,mixed> $capabilities
     * @return array<string,mixed>
     */
    private function protocolFacts(array $written, array $capabilities): array
    {
        $tlsConfigured = (bool)($written['ssl'] ?? false);
        $http2Enabled = $tlsConfigured && (bool)($written['http2_enabled'] ?? false);
        $http3Configured = $tlsConfigured && (bool)($written['http3_enabled'] ?? false);
        $http3Capable = (bool)($capabilities['http3_module'] ?? false);
        $http3VerifierAvailable = $this->http3VerifierAvailable();
        $http3VerificationUnavailable = $http3Capable
            && !$http3Configured
            && !$http3VerifierAvailable;
        $sessionCacheShared = $tlsConfigured
            && (bool)($written['tls_session_cache_shared'] ?? false);
        $sessionTicketsConfigured = $tlsConfigured
            && (bool)($written['tls_session_tickets'] ?? false);
        $sharedTicketKeys = $sessionCacheShared
            && $sessionTicketsConfigured
            && (bool)($capabilities['shared_session_ticket_keys'] ?? false);
        $protocols = $http3Configured
            ? ['http/3', 'http/2', 'http/1.1']
            : ($http2Enabled ? ['http/2', 'http/1.1'] : ['http/1.1']);
        $upstreams = \is_array($written['upstreams'] ?? null)
            ? \array_values($written['upstreams'])
            : [(string)($written['upstream'] ?? '')];

        return [
            'config_sha256' => (string)($written['config_sha256'] ?? ''),
            'upstream_endpoint_sha256' => \hash(
                'sha256',
                \json_encode($upstreams, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            ),
            'public_protocols' => $protocols,
            'http2_enabled' => $http2Enabled,
            // Configuration is not runtime proof. Start/reload merges live
            // owner-bound H2/H1 evidence only after both requests succeed.
            'http2_runtime_verified' => false,
            'public_protocols_runtime_verified' => [],
            'http1_fallback' => $tlsConfigured,
            'http1_runtime_verified' => false,
            'http3_capable' => $http3Capable,
            'http3_configured' => $http3Configured,
            'http3_runtime_verified' => false,
            'http3_verifier_available' => false,
            'http3_status' => $http3Configured
                ? 'pending'
                : ($http3VerificationUnavailable
                    ? 'verification_unavailable'
                    : 'not_configured'),
            'http3_advertisement_status' => $http3Configured
                ? 'ADVERTISED'
                : 'NOT_ADVERTISED',
            'http3_protocol' => '',
            'http3_master_pid' => 0,
            'http3_config_generation' => '',
            'http3_config_sha256' => '',
            'http3_ssl_certificate_sha256' => '',
            'http3_verified_at' => '',
            'http3_reason' => $http3Configured
                ? 'Nginx QUIC is configured from a verified --with-http_v3_module build; a real QUIC request is still required.'
                : ($http3VerificationUnavailable
                    ? 'Nginx HTTP/3 capability is present, but QUIC and Alt-Svc are not published without an HTTP/3-only verifier.'
                    : (string)($capabilities['http3_reason'] ?? 'Nginx HTTP/3 capability is unavailable.')),
            'alt_svc_enabled' => $http3Configured,
            'tls13_only' => $tlsConfigured,
            'tls13_runtime_verified' => false,
            'tls_session_cache_shared' => $sessionCacheShared,
            'tls_session_tickets' => $sessionTicketsConfigured,
            'tls_session_ticket_keys_shared' => $sharedTicketKeys,
            'tls_session_resumption_runtime_verified' => false,
            'tls_session_resumption_reload_continuity_verified' => false,
            'tls_session_resumption_reload_continuity_status' => 'not_verified',
            'tls_session_resumption_reload_continuity_proof_model' => '',
            'tls_session_resumption_reload_continuity_result' => '',
            'tls_session_resumption_reload_issuer_worker_pid' => 0,
            'tls_session_resumption_reload_probe_worker_pid' => 0,
            'tls_session_resumption_reload_master_pid' => 0,
            'tls_session_resumption_reload_tls_handshake_us' => 0,
            'tls_session_resumption_reload_previous_config_generation' => '',
            'tls_session_resumption_reload_config_generation' => '',
            'tls_session_resumption_reload_verified_at' => '',
        ];
    }

    /** @param array<string,mixed> $written @return array<string,mixed> */
    private function certificateGenerationFacts(array $written): array
    {
        return [
            'certificate_generation_managed'
                => (bool)($written['certificate_generation_managed'] ?? false),
            'certificate_domain' => (string)($written['certificate_domain'] ?? ''),
            'certificate_generation' => (int)($written['certificate_generation'] ?? 0),
            'certificate_source_digest'
                => (string)($written['certificate_source_digest'] ?? ''),
            'certificate_cert_sha256'
                => (string)($written['certificate_cert_sha256'] ?? ''),
            'certificate_key_sha256'
                => (string)($written['certificate_key_sha256'] ?? ''),
            'certificate_chain_sha256'
                => (string)($written['certificate_chain_sha256'] ?? ''),
        ];
    }

    /**
     * @param array<string,mixed> $owner
     * @return array<string,mixed>|null
     */
    private function resolveOwnerCertificateGeneration(array $owner): ?array
    {
        if (!\array_key_exists('certificate_generation_managed', $owner)) {
            // Pre-WLS-2.0 owner files remain readable for one compatibility
            // reload from app/etc/ssl. Once the field exists, mutable raw-source
            // fallback is forbidden.
            return null;
        }
        if ($owner['certificate_generation_managed'] !== true) {
            throw new \RuntimeException(
                'Managed Nginx owner is not bound to an immutable certificate generation.',
            );
        }
        $domain = \strtolower(\trim((string)($owner['certificate_domain'] ?? '')));
        $active = $domain !== ''
            ? (new ProjectCertificateGenerationStore($this->paths->projectRoot()))
                ->active($domain)
            : null;
        if (!\is_array($active)
            || (int)($active['generation'] ?? 0)
                < (int)($owner['certificate_generation'] ?? 0)
        ) {
            throw new \RuntimeException(
                'Managed Nginx owner certificate generation is no longer active.'
            );
        }
        foreach ([
            'source_digest',
            'cert_path',
            'key_path',
            'chain_path',
            'leaf_fingerprint_sha256',
            'cert_sha256',
            'key_sha256',
            'chain_sha256',
        ] as $field) {
            if (!\is_string($active[$field] ?? null)
                || \trim((string)$active[$field]) === ''
            ) {
                throw new \RuntimeException(
                    'Managed Nginx active certificate generation is incomplete at ' . $field . '.',
                );
            }
        }
        return $active;
    }

    /**
     * @param list<int> $ports
     * @param array{topology:'direct'|'dispatcher',listener_mode:string,upstream_port:int,upstream_ports:list<int>,worker_port:int,worker_count:int} $backendIdentity
     */
    private function probeWlsBackendPool(
        string $host,
        array $ports,
        string $instanceName,
        array $backendIdentity,
    ): bool {
        if ($ports === []
            || !\array_is_list($ports)
            || !$this->lifecycleDeadlineAvailable()
        ) {
            return false;
        }
        foreach (\array_values(\array_unique(\array_map('intval', $ports))) as $port) {
            if (!$this->lifecycleDeadlineAvailable()) {
                return false;
            }
            if (!$this->probeWlsBackend($host, $port, $instanceName, $backendIdentity)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array{topology:'direct'|'dispatcher',listener_mode:string,upstream_port:int,upstream_ports:list<int>,worker_port:int,worker_count:int} $backendIdentity
     */
    private function probeWlsBackend(
        string $host,
        int $port,
        string $instanceName,
        array $backendIdentity,
    ): bool
    {
        $normalizedHost = \strtolower(\trim($host, " \t\n\r\0\x0B[]"));
        $instanceName = \trim($instanceName);
        $loopback = $normalizedHost === 'localhost'
            || $normalizedHost === '::1'
            || (\filter_var($normalizedHost, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false
                && \str_starts_with($normalizedHost, '127.'));
        if (!$loopback || $port < 1 || $port > 65535 || $instanceName === '') {
            return false;
        }
        $targetHost = \str_contains($normalizedHost, ':')
            ? '[' . $normalizedHost . ']'
            : $normalizedHost;
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $connectTimeout = $this->remainingLifecycleDeadline(0.5);
            if ($connectTimeout === null) {
                return false;
            }
            $errno = 0;
            $error = '';
            $socket = @\stream_socket_client(
                'tcp://' . $targetHost . ':' . $port,
                $errno,
                $error,
                $connectTimeout,
                STREAM_CLIENT_CONNECT,
            );
            if (!\is_resource($socket)) {
                continue;
            }
            if (!$this->setSocketTimeoutWithinLifecycleDeadline($socket, 1.0)) {
                @\fclose($socket);
                return false;
            }
            @\fwrite(
                $socket,
                "GET /_wls/health?detail=1 HTTP/1.1\r\nHost: 127.0.0.1\r\nConnection: close\r\n\r\n",
            );
            $response = '';
            while (!\feof($socket) && \strlen($response) < 1_048_576) {
                if (!$this->setSocketTimeoutWithinLifecycleDeadline($socket, 1.0)) {
                    break;
                }
                $chunk = @\fread($socket, 8192);
                if (!\is_string($chunk) || $chunk === '') {
                    break;
                }
                $response .= $chunk;
            }
            @\fclose($socket);
            $separator = \strpos($response, "\r\n\r\n");
            if ($separator === false) {
                $separator = \strpos($response, "\n\n");
                $separatorLength = 2;
            } else {
                $separatorLength = 4;
            }
            if ($separator === false
                || \preg_match('/\AHTTP\/1\.[01]\s+200(?:\s|$)/', $response) !== 1
                || !$this->lifecycleDeadlineAvailable()
            ) {
                continue;
            }
            $health = \json_decode(\substr($response, $separator + $separatorLength), true);
            if (\is_array($health) && $this->healthMatchesBackendIdentity(
                $health,
                $instanceName,
                $backendIdentity,
            )) {
                return true;
            }
        }

        return false;
    }
    /**
     * Bind the probe target to the frozen WLS endpoint before trusting any
     * response. POSIX Direct Workers share one upstream port, Windows Direct
     * exposes one loopback port per Worker, and Dispatcher owns the semantic
     * upstream port while forwarding to its private Worker range.
     *
     * @return array{topology:'direct'|'dispatcher',listener_mode:string,upstream_port:int,upstream_ports:list<int>,worker_port:int,worker_count:int}|null
     */
    private function resolveWlsBackendIdentity(string $instanceName, int $upstreamPort): ?array
    {
        try {
            $endpoint = (new ServerInstanceManager())->getRawInstanceData($instanceName);
            if (!\is_array($endpoint)) {
                return null;
            }
            $edgeAdapterName = \strtolower(\trim((string)($endpoint['edge_adapter'] ?? '')));
            if ($edgeAdapterName === '') {
                // The Master may atomically publish its control endpoint before
                // carrying forward every optional metadata field. Nginx is the
                // only supported public edge, so an absent field is unambiguous.
                $edgeAdapterName = EdgeAdapterInterface::NAME_NGINX;
            }
            if ($edgeAdapterName !== EdgeAdapterInterface::NAME_NGINX) {
                return null;
            }
            $endpointPort = (int)($endpoint['port'] ?? $endpoint['main_port'] ?? 0);
            $mainPort = (int)($endpoint['main_port'] ?? $endpointPort);
            if ($endpointPort !== $upstreamPort || $mainPort !== $upstreamPort) {
                return null;
            }
            $runtimeSelection = RuntimeSelection::fromArray(
                \is_array($endpoint['runtime_selection'] ?? null)
                    ? $endpoint['runtime_selection']
                    : [],
            );
            $workerCount = \max(1, (int)($endpoint['count'] ?? 1));
            if ($workerCount > 1024) {
                return null;
            }
            if ($runtimeSelection->isDirect()) {
                $firstWorkerPort = $runtimeSelection->listenerMode === 'worker_ports'
                    ? (int)($endpoint['worker_port'] ?? 0)
                    : $upstreamPort;
                if ($firstWorkerPort < 1
                    || $firstWorkerPort > 65535
                    || ($runtimeSelection->listenerMode === 'worker_ports'
                        && ($firstWorkerPort + $workerCount - 1) > 65535)
                ) {
                    return null;
                }
                $upstreamPorts = $runtimeSelection->listenerMode === 'worker_ports'
                    ? \range($firstWorkerPort, $firstWorkerPort + $workerCount - 1)
                    : [$upstreamPort];

                return [
                    'topology' => 'direct',
                    'listener_mode' => $runtimeSelection->listenerMode,
                    'upstream_port' => $upstreamPort,
                    'upstream_ports' => $upstreamPorts,
                    'worker_port' => $firstWorkerPort,
                    'worker_count' => $workerCount,
                ];
            }
            if (!$runtimeSelection->isDispatcher()) {
                return null;
            }
            $firstWorkerPort = (int)($endpoint['worker_port'] ?? 0);
            $workerCount = (int)($endpoint['count'] ?? 0);
            if ($firstWorkerPort < 1 || $firstWorkerPort > 65535 || $workerCount < 1) {
                return null;
            }

            return [
                'topology' => 'dispatcher',
                'listener_mode' => 'single',
                'upstream_port' => $upstreamPort,
                'upstream_ports' => [$upstreamPort],
                'worker_port' => $firstWorkerPort,
                'worker_count' => $workerCount,
            ];
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param array<string,mixed> $health
     * @param array{topology:'direct'|'dispatcher',listener_mode:string,upstream_port:int,upstream_ports:list<int>,worker_port:int,worker_count:int} $identity
     */
    private function healthMatchesBackendIdentity(
        array $health,
        string $instanceName,
        array $identity,
    ): bool {
        $workerId = (int)($health['worker_id'] ?? 0);
        $reportedPort = (int)($health['port'] ?? 0);
        if ((string)($health['status'] ?? '') !== 'healthy'
            || !\hash_equals($instanceName, (string)($health['instance'] ?? ''))
            || $workerId < 1
            || $workerId > $identity['worker_count']
        ) {
            return false;
        }
        if ($identity['topology'] === 'direct' && $identity['listener_mode'] !== 'worker_ports') {
            return $reportedPort === $identity['upstream_port'];
        }

        $expectedWorkerPort = $identity['worker_port'] + $workerId - 1;
        return $expectedWorkerPort <= 65535 && $reportedPort === $expectedWorkerPort;
    }


    /** @return array{ok:bool,message:string,capabilities?:array<string,mixed>} */
    private function installedBinaryIdentity(): array
    {
        $installation = $this->installer->installationStatus();
        $capabilities = $this->processManager->probeBinaryCapabilities();
        $valid = (bool)($installation['manifest_matches'] ?? false)
            && (bool)($capabilities['ok'] ?? false)
            && \hash_equals(ManagedNginxInstaller::VERSION, (string)($capabilities['version'] ?? ''))
            && (bool)($capabilities['http2_module'] ?? false)
            && (bool)($capabilities['http_ssl_module'] ?? false)
            && (bool)($capabilities['rewrite_module'] ?? false)
            && (bool)($capabilities['tls13_capable'] ?? false);
        if (!$valid) {
            return [
                'ok' => false,
                'message' => 'managed nginx identity gate failed: expected nginx/'
                    . ManagedNginxInstaller::VERSION
                    . ' with matching platform/arch/binary hash, HTTP/2, HTTP SSL, HTTP rewrite, and OpenSSL >= 1.1.1; actual version='
                    . (string)($capabilities['version'] ?? 'unknown')
                    . ', manifest=' . (($installation['manifest_matches'] ?? false) ? 'match' : 'mismatch')
                    . ', http2=' . (($capabilities['http2_module'] ?? false) ? 'yes' : 'no')
                    . ', http_ssl=' . (($capabilities['http_ssl_module'] ?? false) ? 'yes' : 'no')
                    . ', rewrite=' . (($capabilities['rewrite_module'] ?? false) ? 'yes' : 'no')
                    . ', openssl=' . (string)($capabilities['openssl_version'] ?? 'unknown')
                    . ', tls13=' . (($capabilities['tls13_capable'] ?? false) ? 'yes' : 'no'),
                'capabilities' => $capabilities,
            ];
        }

        return ['ok' => true, 'message' => 'managed nginx identity verified', 'capabilities' => $capabilities];
    }

    /**
     * @param array<string,mixed> $ownerIntent
     * @return array{ok:bool,message:string,cleanup_warning:string}
     */
    private function commitVerifiedPublication(
        array $ownerIntent,
        ?string $rollback,
        bool $wasRunning,
        bool $startedByCall,
    ): array {
        try {
            $this->commitOwnerIntent($ownerIntent);
        } catch (\Throwable $throwable) {
            if ($this->ownerAfterImageMatches($ownerIntent)) {
                // The owner rename committed and only post-rename durability
                // reporting failed. Continue the paired config commit.
            } else {
                try {
                    $recovery = $this->restorePublishedConfig(
                        $rollback,
                        $wasRunning,
                        $startedByCall,
                        $ownerIntent,
                    );
                    return [
                        'ok' => false,
                        'message' => 'managed nginx owner commit failed before publication commit: '
                            . $throwable->getMessage() . $recovery,
                        'cleanup_warning' => '',
                    ];
                } catch (\Throwable $recoveryFailure) {
                    return $this->failClosedPublication(
                        'managed nginx owner/config commit identity is ambiguous: '
                            . $throwable->getMessage() . '; recovery=' . $recoveryFailure->getMessage(),
                    );
                }
            }
        }

        if ($this->configWriter->commitPublished($rollback)) {
            try {
                $cleanupWarning = $this->finalizeCommittedOwnerState($ownerIntent);
            } catch (\Throwable $throwable) {
                if (!$this->managedFileDigestMatches(
                    $this->paths->confFile(),
                    (string)($ownerIntent['config_sha256'] ?? ''),
                    'Managed Nginx committed active config',
                ) || !$this->committedOwnerMatchesWithoutIntent($ownerIntent)) {
                    return $this->failClosedPublication(
                        'managed nginx committed publication identity changed during cleanup: '
                            . $throwable->getMessage(),
                    );
                }
                $cleanupWarning = 'committed publication cleanup pending: '
                    . $throwable->getMessage();
            }
            return [
                'ok' => true,
                'message' => 'owner and config publication committed',
                'cleanup_warning' => $cleanupWarning,
            ];
        }

        if ($this->publicationCommitAfterImageMatches($ownerIntent, $rollback)) {
            $warnings = ['config commit completed with deferred durability cleanup'];
            if ($rollback !== null) {
                try {
                    $this->configWriter->cleanupResolvedRollbackTemporaries($rollback);
                } catch (\Throwable $throwable) {
                    $warnings[] = 'rollback temporary cleanup pending: '
                        . $throwable->getMessage();
                }
            }
            try {
                $ownerWarning = $this->finalizeCommittedOwnerState($ownerIntent);
                if ($ownerWarning !== '') {
                    $warnings[] = $ownerWarning;
                }
            } catch (\Throwable $throwable) {
                $warnings[] = 'owner intent cleanup pending: ' . $throwable->getMessage();
            }
            return [
                'ok' => true,
                'message' => 'owner and config publication after-image is exact',
                'cleanup_warning' => \implode('; ', $warnings),
            ];
        }

        if ($this->rollbackBeforeImageIsExact($ownerIntent, $rollback)) {
            try {
                $recovery = $this->restorePublishedConfig(
                    $rollback,
                    $wasRunning,
                    $startedByCall,
                    $ownerIntent,
                );
                return [
                    'ok' => false,
                    'message' => 'managed nginx publication commit did not complete; '
                        . 'owner and config before-images were restored' . $recovery,
                    'cleanup_warning' => '',
                ];
            } catch (\Throwable $throwable) {
                return $this->failClosedPublication(
                    'managed nginx publication rollback failed: ' . $throwable->getMessage(),
                );
            }
        }

        return $this->failClosedPublication(
            'managed nginx publication commit is ambiguous; neither exact before-image '
                . 'nor exact after-image could be proven',
        );
    }

    /** @return array{ok:false,message:string,cleanup_warning:string} */
    private function failClosedPublication(string $message): array
    {
        try {
            $this->stopManagedNginxFailClosed($message);
            $message .= '; managed nginx stopped fail-closed';
        } catch (\Throwable $throwable) {
            $message .= '; fail-closed stop was not proven: ' . $throwable->getMessage();
        }
        return ['ok' => false, 'message' => $message, 'cleanup_warning' => ''];
    }

    /** @param array<string,mixed> $ownerIntent */
    private function publicationCommitAfterImageMatches(array $ownerIntent, ?string $rollback): bool
    {
        if (!$this->ownerAfterImageMatches($ownerIntent)
            || !$this->managedFileDigestMatches(
                $this->paths->confFile(),
                (string)($ownerIntent['config_sha256'] ?? ''),
                'Managed Nginx committed active config',
            )
        ) {
            return false;
        }
        if ($rollback === null) {
            return !(bool)($ownerIntent['config_rollback_expected'] ?? false);
        }
        if ($this->pathExistsNoFollow($rollback)) {
            return false;
        }
        return $this->managedFileDigestMatches(
            $this->paths->confFile() . '.last-good',
            (string)($ownerIntent['previous_config_sha256'] ?? ''),
            'Managed Nginx committed last-known-good config',
        );
    }

    /** @param array<string,mixed> $ownerIntent */
    private function rollbackBeforeImageIsExact(array $ownerIntent, ?string $rollback): bool
    {
        if (!(bool)($ownerIntent['config_rollback_expected'] ?? false)
            || $rollback === null
            || !$this->managedFileDigestMatches(
                $rollback,
                (string)($ownerIntent['previous_config_sha256'] ?? ''),
                'Managed Nginx rollback before-image',
            )
        ) {
            return false;
        }
        if ($this->ownerBeforeImageMatches($ownerIntent)) {
            return true;
        }
        if (!$this->ownerAfterImageMatches($ownerIntent)) {
            return false;
        }
        if (!(bool)($ownerIntent['owner_rollback_expected'] ?? false)) {
            return true;
        }
        $ownerRollback = $this->ownerRollbackPath(
            (string)($ownerIntent['transaction_id'] ?? ''),
        );
        try {
            $this->assertOwnerRollbackMatchesIntent($ownerIntent, $ownerRollback);
        } catch (\Throwable) {
            return false;
        }
        return true;
    }

    /** @param array<string,mixed> $ownerIntent */
    private function finalizeCommittedOwnerState(array $ownerIntent): string
    {
        $warnings = [];
        $ownerRollback = $this->ownerRollbackPath((string)$ownerIntent['transaction_id']);
        if ($this->pathExistsNoFollow($ownerRollback)) {
            $this->assertOwnerRollbackMatchesIntent($ownerIntent, $ownerRollback);
            try {
                GatewayProjectStateFilesystem::removeRegular(
                    $ownerRollback,
                    'Committed managed Nginx owner rollback',
                );
            } catch (\Throwable $throwable) {
                if ($this->pathExistsNoFollow($ownerRollback)) {
                    throw $throwable;
                }
                try {
                    $this->reconcileProjectStateRemovalAfterImage(
                        $ownerRollback,
                        'Committed managed Nginx owner rollback',
                        $throwable,
                    );
                } catch (\Throwable $syncFailure) {
                    if ($this->pathExistsNoFollow($ownerRollback)) {
                        throw $syncFailure;
                    }
                    $warnings[] = 'owner rollback unlink committed with deferred directory sync: '
                        . $syncFailure->getMessage();
                }
            }
        } elseif ((bool)($ownerIntent['owner_rollback_expected'] ?? false)
            && !$this->ownerAfterImageMatches($ownerIntent)
        ) {
            throw new \RuntimeException(
                'Committed managed nginx owner rollback disappeared before owner identity was proven.',
            );
        }
        $intentWarning = $this->finalizeOwnerIntent($ownerIntent);
        if ($intentWarning !== '') {
            $warnings[] = $intentWarning;
        }
        return \implode('; ', $warnings);
    }

    private function restorePublishedConfig(
        ?string $rollback,
        bool $wasRunning,
        bool $startedByCall,
        ?array $ownerIntent = null,
    ): string {
        $notes = [];
        if (!$wasRunning) {
            // start() may return failure after process creation (for example an
            // identity publication error). Re-prove that no new master remains
            // before changing the config under a potentially live process.
            $status = $this->processManager->status(
                $this->activeLifecycleDeadlineMonotonic,
            );
            if (!($status['ok'] ?? false)) {
                throw new \RuntimeException(
                    'Managed nginx newly-started process identity is unsafe; '
                        . 'publication evidence was retained before config rollback.',
                );
            }
            if ((bool)($status['running'] ?? false)) {
                $this->stopManagedNginxFailClosed(
                    'unable to stop the newly-started candidate before config rollback',
                );
                $notes[] = 'candidate process stopped';
            } elseif ($startedByCall) {
                $notes[] = 'candidate process already stopped';
            }
        }

        if (\is_array($ownerIntent)
            && (bool)($ownerIntent['config_rollback_expected'] ?? false)
            && !$this->rollbackBeforeImageIsExact($ownerIntent, $rollback)
        ) {
            throw new \RuntimeException(
                'Managed nginx config rollback before-image is missing or changed.',
            );
        }
        $this->configWriter->rollbackPublished($rollback);
        if (\is_array($ownerIntent)) {
            $this->restoreOwnerBeforeImage($ownerIntent);
        }
        if ($wasRunning) {
            $owner = $this->readOwner();
            $restored = $this->processManager->reload(
                $this->activeLifecycleDeadlineMonotonic,
            );
            if (!($restored['ok'] ?? false)
                || !\is_array($owner)
                || !$this->committedOwnerGenerationIsLive($owner)
            ) {
                $this->stopManagedNginxFailClosed(
                    'last-known-good config could not be proven live after rollback',
                );
                $notes[] = 'last-known-good config restored on disk; Nginx stopped fail-closed';
            } else {
                $notes[] = 'last-known-good config and live generation restored';
            }
        }

        if (\is_array($ownerIntent)) {
            $this->finalizeRolledBackOwnerIntent($ownerIntent);
        }

        return $notes === [] ? '' : '; ' . \implode('; ', $notes);
    }

    /** @param array<string,mixed> $owner */
    private function committedOwnerGenerationIsLive(array $owner): bool
    {
        $port = (int)($owner['listen_http'] ?? 0);
        $generation = \strtolower(\trim((string)($owner['config_generation'] ?? '')));
        $configSha256 = \strtolower(\trim((string)($owner['config_sha256'] ?? '')));
        $activeConfigSha256 = $this->stableManagedFileSha256(
            $this->paths->confFile(),
            'Managed Nginx active config',
        );
        if ($port < 1
            || $port > 65535
            || \preg_match('/\A[a-f0-9]{32}\z/D', $generation) !== 1
            || \preg_match('/\A[a-f0-9]{64}\z/D', $configSha256) !== 1
            || !\is_string($activeConfigSha256)
            || !\hash_equals($configSha256, \strtolower($activeConfigSha256))
        ) {
            return false;
        }

        return $this->probeConfigGeneration($port, $generation);
    }

    private function stopManagedNginxFailClosed(string $context): void
    {
        $stop = $this->processManager->stop(
            $this->activeLifecycleDeadlineMonotonic,
        );
        $status = $this->processManager->status(
            $this->activeLifecycleDeadlineMonotonic,
        );
        if (($status['ok'] ?? false) && !($status['running'] ?? false)) {
            return;
        }

        throw new \RuntimeException(
            $context
            . '; stop=' . (string)($stop['message'] ?? 'unknown')
            . '; status=' . (string)($status['message'] ?? 'unknown'),
        );
    }

    private function probeConfigGeneration(int $port, string $generation): bool
    {
        if ($port < 1 || $port > 65535
            || \preg_match('/\A[a-f0-9]{32}\z/D', $generation) !== 1
            || !$this->lifecycleDeadlineAvailable()
        ) {
            return false;
        }
        $probe = $this->liveProbe->probeHttp(
            address: '127.0.0.1',
            port: $port,
            host: 'localhost',
            path: '/_wls/health',
            expectedStatus: 200,
            expectedHeaders: ['X-Wls-Nginx-Config' => $generation],
            maxAttempts: 60,
            requiredConsecutive: 8,
            deadlineMonotonic: $this->activeLifecycleDeadlineMonotonic,
        );
        return (bool)($probe['ok'] ?? false);
    }

    /** @param list<string> $serverNames */
    private function resolveTlsProbeHost(array $serverNames): string
    {
        foreach ($serverNames as $serverName) {
            $candidate = \strtolower(\rtrim(\trim((string)$serverName), '.'));
            if (\str_starts_with($candidate, '*.')) {
                $suffix = \substr($candidate, 2);
                $candidate = 'wls-probe.' . $suffix;
            }
            if ($candidate === ''
                || $candidate === '_'
                || \str_contains($candidate, '*')
                || \str_contains($candidate, ':')
                || \strlen($candidate) > 253
                || \preg_match(
                    '/\A[a-z0-9](?:[a-z0-9.-]{0,251}[a-z0-9])?\z/D',
                    $candidate,
                ) !== 1
            ) {
                continue;
            }
            return $candidate;
        }

        return 'localhost';
    }

    /** @param list<string> $serverNames */
    private function probeTls13(
        int $port,
        array $serverNames,
        string $generation,
        string $expectedCertificateSha256,
    ): bool
    {
        if ($port < 1
            || $port > 65535
            || !\defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT')
            || \preg_match('/\A[a-f0-9]{32}\z/D', $generation) !== 1
            || \preg_match('/\A[a-f0-9]{64}\z/D', $expectedCertificateSha256) !== 1
            || !$this->lifecycleDeadlineAvailable()
        ) {
            return false;
        }
        $peerName = $this->resolveTlsProbeHost($serverNames);
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $connectTimeout = $this->remainingLifecycleDeadline(1.0);
            if ($connectTimeout === null) {
                return false;
            }
            $context = \stream_context_create(['ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => true,
                'allow_self_signed' => true,
                'SNI_enabled' => true,
                'peer_name' => $peerName,
                'alpn_protocols' => 'http/1.1',
                'capture_peer_cert' => true,
            ]]);
            $errno = 0;
            $error = '';
            $socket = @\stream_socket_client(
                'tcp://127.0.0.1:' . $port,
                $errno,
                $error,
                $connectTimeout,
                STREAM_CLIENT_CONNECT,
                $context,
            );
            if (\is_resource($socket)) {
                if (!$this->setSocketTimeoutWithinLifecycleDeadline(
                    $socket,
                    2.0,
                )) {
                    @\fclose($socket);
                    return false;
                }
                $enabled = @\stream_socket_enable_crypto(
                    $socket,
                    true,
                    (int)\constant('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT'),
                );
                $metadata = @\stream_get_meta_data($socket);
                $contextParameters = @\stream_context_get_params($socket);
                $peerCertificate = \is_array($contextParameters)
                    ? ($contextParameters['options']['ssl']['peer_certificate'] ?? null)
                    : null;
                $peerCertificateSha256 = \is_object($peerCertificate)
                    || \is_resource($peerCertificate)
                    ? @\openssl_x509_fingerprint($peerCertificate, 'sha256')
                    : false;
                $certificateVerified = \is_string($peerCertificateSha256)
                    && \hash_equals($expectedCertificateSha256, \strtolower($peerCertificateSha256));
                $headers = '';
                if ($enabled === true
                    && \is_array($metadata)
                    && (string)($metadata['crypto']['protocol'] ?? '') === 'TLSv1.3'
                    && (string)($metadata['crypto']['alpn_protocol'] ?? '') === 'http/1.1'
                ) {
                    @\fwrite(
                        $socket,
                        "GET /_wls/health HTTP/1.1\r\nHost: {$peerName}\r\nConnection: close\r\n\r\n",
                    );
                    while (!\feof($socket) && \strlen($headers) < 65_536) {
                        if (!$this->setSocketTimeoutWithinLifecycleDeadline(
                            $socket,
                            2.0,
                        )) {
                            break;
                        }
                        $line = @\fgets($socket, 8192);
                        if (!\is_string($line)) {
                            break;
                        }
                        $headers .= $line;
                        if ($line === "\r\n" || $line === "\n") {
                            break;
                        }
                    }
                }
                @\fclose($socket);
                if ($certificateVerified
                    && $this->lifecycleDeadlineAvailable()
                    && \preg_match('/\AHTTP\/1\.[01]\s+200(?:\s|$)/', $headers) === 1
                    && \preg_match(
                        '/^X-Wls-Nginx-Config:\s*' . \preg_quote($generation, '/') . '\s*$/mi',
                        $headers,
                    ) === 1
                ) {
                    return true;
                }
            }
            if (!$this->sleepWithinLifecycleDeadline(0.1)) {
                return false;
            }
        }

        return false;
    }

    /**
     * Prove that the public Nginx listener carries an actual request over the
     * requested TCP HTTP protocol to the frozen WLS backend identity.
     *
     * @param list<string> $serverNames
     */
    private function verifyHttpRuntime(
        string $protocol,
        int $port,
        array $serverNames,
        string $generation,
        string $ownerInstance,
        int $upstreamPort,
    ): bool {
        if (!\in_array($protocol, ['1.1', '2'], true)
            || $port < 1
            || $port > 65535
            || \trim($ownerInstance) === ''
            || $upstreamPort < 1
            || $upstreamPort > 65535
            || \preg_match('/\A[a-f0-9]{32}\z/D', $generation) !== 1
            || !\extension_loaded('curl')
            || !\function_exists('curl_init')
            || !\defined('CURLINFO_HTTP_VERSION')
            || !\defined('CURL_SSLVERSION_TLSv1_3')
            || !$this->lifecycleDeadlineAvailable()
        ) {
            return false;
        }
        if ($protocol === '2'
            && (!\defined('CURL_HTTP_VERSION_2_0')
                || !\defined('CURL_VERSION_HTTP2')
                || (((int)(\curl_version()['features'] ?? 0)
                    & (int)\constant('CURL_VERSION_HTTP2')) === 0))
        ) {
            return false;
        }
        if ($protocol === '1.1' && !\defined('CURL_HTTP_VERSION_1_1')) {
            return false;
        }

        $backendIdentity = $this->resolveWlsBackendIdentity($ownerInstance, $upstreamPort);
        if ($backendIdentity === null) {
            return false;
        }
        $probeHost = $this->resolveTlsProbeHost($serverNames);

        $requestedVersion = $protocol === '2'
            ? (int)\constant('CURL_HTTP_VERSION_2_0')
            : (int)\constant('CURL_HTTP_VERSION_1_1');
        $sslVersion = (int)\constant('CURL_SSLVERSION_TLSv1_3');
        if (\defined('CURL_SSLVERSION_MAX_TLSv1_3')) {
            $sslVersion |= (int)\constant('CURL_SSLVERSION_MAX_TLSv1_3');
        }

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $remainingMilliseconds = $this->remainingLifecycleMilliseconds(5_000);
            if ($remainingMilliseconds === null) {
                return false;
            }
            $headers = '';
            $responseBody = '';
            $responseOverflow = false;
            $handle = @\curl_init(
                'https://' . $probeHost . ':' . $port . '/_wls/health?detail=1',
            );
            if ($handle === false) {
                return false;
            }
            $options = [
                CURLOPT_RETURNTRANSFER => false,
                CURLOPT_HEADER => false,
                CURLOPT_HEADERFUNCTION => static function (mixed $curl, string $line) use (
                    &$headers,
                    &$responseOverflow,
                ): int {
                    if (\preg_match('/\AHTTP\//i', $line) === 1) {
                        $headers = '';
                    }
                    if (\strlen($headers) + \strlen($line) > self::MAX_HEALTH_HEADERS_BYTES) {
                        $responseOverflow = true;
                        return 0;
                    }
                    $headers .= $line;
                    return \strlen($line);
                },
                CURLOPT_WRITEFUNCTION => static function (mixed $curl, string $chunk) use (
                    &$responseBody,
                    &$responseOverflow,
                ): int {
                    if (\strlen($responseBody) + \strlen($chunk) > self::MAX_HEALTH_RESPONSE_BYTES) {
                        $responseOverflow = true;
                        return 0;
                    }
                    $responseBody .= $chunk;
                    return \strlen($chunk);
                },
                CURLOPT_HTTP_VERSION => $requestedVersion,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_SSLVERSION => $sslVersion,
                CURLOPT_RESOLVE => [$probeHost . ':' . $port . ':127.0.0.1'],
                CURLOPT_CONNECTTIMEOUT_MS => \min(1_500, $remainingMilliseconds),
                CURLOPT_TIMEOUT_MS => $remainingMilliseconds,
                CURLOPT_FRESH_CONNECT => true,
                CURLOPT_FORBID_REUSE => true,
                CURLOPT_PROXY => '',
                CURLOPT_NOPROXY => '*',
                CURLOPT_NOSIGNAL => true,
                CURLOPT_HTTPHEADER => [
                    'Accept: application/json',
                    'Cache-Control: no-cache',
                    'Pragma: no-cache',
                ],
            ];
            if (\defined('CURLOPT_PROTOCOLS') && \defined('CURLPROTO_HTTPS')) {
                $options[(int)\constant('CURLOPT_PROTOCOLS')] = (int)\constant('CURLPROTO_HTTPS');
            }
            if (!@\curl_setopt_array($handle, $options)) {
                @\curl_close($handle);
                return false;
            }
            $completed = @\curl_exec($handle);
            $errno = @\curl_errno($handle);
            $responseCode = (int)@\curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
            $httpVersion = (int)@\curl_getinfo($handle, CURLINFO_HTTP_VERSION);
            @\curl_close($handle);

            $health = \json_decode($responseBody, true);
            if ($completed === true
                && $this->lifecycleDeadlineAvailable()
                && !$responseOverflow
                && $errno === CURLE_OK
                && $responseCode === 200
                && $httpVersion === $requestedVersion
                && \is_array($health)
                && $this->healthMatchesBackendIdentity($health, $ownerInstance, $backendIdentity)
                && \preg_match(
                    '/^X-Wls-Nginx-Config:\s*' . \preg_quote($generation, '/') . '\s*$/mi',
                    $headers,
                ) === 1
            ) {
                return true;
            }
            if (!$this->sleepWithinLifecycleDeadline(0.1)) {
                return false;
            }
        }

        return false;
    }

    /**
     * @return array{ok:bool,message:string,evidence:array<string,mixed>}
     */
    private function verifyHttp3Runtime(
        bool $configured,
        bool $capable,
        int $port,
        int $masterPid,
        string $generation,
        string $configSha256,
        string $certificateSha256,
        array $serverNames,
        string $ownerInstance,
        int $upstreamPort,
    ): array {
        if (!$configured) {
            $verificationUnavailable = $capable && !$this->http3VerifierAvailable();
            return [
                'ok' => true,
                'message' => $verificationUnavailable
                    ? 'HTTP/3 was not advertised because a real QUIC verifier is unavailable'
                    : 'HTTP/3 is not configured',
                'evidence' => [
                    'http3_runtime_verified' => false,
                    'http3_verifier_available' => false,
                    'http3_status' => $verificationUnavailable
                        ? 'verification_unavailable'
                        : 'not_configured',
                    'http3_advertisement_status' => 'NOT_ADVERTISED',
                    'http3_protocol' => '',
                    'http3_master_pid' => 0,
                    'http3_config_generation' => '',
                    'http3_config_sha256' => '',
                    'http3_ssl_certificate_sha256' => '',
                    'http3_verified_at' => '',
                    'http3_reason' => $verificationUnavailable
                        ? 'HTTP/3 capability is present, but QUIC/Alt-Svc stayed disabled because this runtime cannot issue an HTTP/3-only probe.'
                        : '',
                ],
            ];
        }
        if ($port < 1
            || $port > 65535
            || $masterPid < 1
            || \trim($ownerInstance) === ''
            || $upstreamPort < 1
            || $upstreamPort > 65535
            || \preg_match('/\A[a-f0-9]{32}\z/D', $generation) !== 1
            || \preg_match('/\A[a-f0-9]{64}\z/D', $configSha256) !== 1
            || \preg_match('/\A[a-f0-9]{64}\z/D', $certificateSha256) !== 1
            || !$this->lifecycleDeadlineAvailable()
        ) {
            return ['ok' => false, 'message' => 'HTTP/3 verification identity is invalid', 'evidence' => []];
        }
        $backendIdentity = $this->resolveWlsBackendIdentity($ownerInstance, $upstreamPort);
        if ($backendIdentity === null) {
            return ['ok' => false, 'message' => 'HTTP/3 WLS backend identity is invalid', 'evidence' => []];
        }
        $probeHost = $this->resolveTlsProbeHost($serverNames);

        $evidence = [
            'http3_runtime_verified' => false,
            'http3_verifier_available' => false,
            'http3_status' => 'verification_unavailable',
            'http3_advertisement_status' => 'ADVERTISED',
            'http3_protocol' => '',
            'http3_master_pid' => $masterPid,
            'http3_config_generation' => $generation,
            'http3_config_sha256' => $configSha256,
            'http3_ssl_certificate_sha256' => $certificateSha256,
            'http3_verified_at' => '',
            'http3_reason' => 'Nginx HTTP/3 is configured, but this PHP cURL runtime cannot issue an HTTP/3-only probe.',
        ];
        if (!$this->http3VerifierAvailable()) {
            return [
                'ok' => false,
                'message' => 'HTTP/3 was configured without an available QUIC verifier',
                'evidence' => $evidence,
            ];
        }
        $evidence['http3_verifier_available'] = true;
        $lastError = 'HTTP/3-only request did not complete';
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $remainingMilliseconds = $this->remainingLifecycleMilliseconds(5_000);
            if ($remainingMilliseconds === null) {
                $lastError = 'managed nginx lifecycle deadline was exhausted';
                break;
            }
            $headers = '';
            $responseBody = '';
            $responseOverflow = false;
            $handle = \curl_init(
                'https://' . $probeHost . ':' . $port . '/_wls/health?detail=1'
            );
            if ($handle === false) {
                $lastError = 'unable to initialize cURL';
                break;
            }
            $options = [
                CURLOPT_RETURNTRANSFER => false,
                CURLOPT_HEADER => false,
                CURLOPT_HEADERFUNCTION => static function (mixed $curl, string $line) use (
                    &$headers,
                    &$responseOverflow,
                ): int {
                    if (\preg_match('/\AHTTP\//i', $line) === 1) {
                        $headers = '';
                    }
                    if (\strlen($headers) + \strlen($line) > self::MAX_HEALTH_HEADERS_BYTES) {
                        $responseOverflow = true;
                        return 0;
                    }
                    $headers .= $line;
                    return \strlen($line);
                },
                CURLOPT_WRITEFUNCTION => static function (mixed $curl, string $chunk) use (
                    &$responseBody,
                    &$responseOverflow,
                ): int {
                    if (\strlen($responseBody) + \strlen($chunk) > self::MAX_HEALTH_RESPONSE_BYTES) {
                        $responseOverflow = true;
                        return 0;
                    }
                    $responseBody .= $chunk;
                    return \strlen($chunk);
                },
                CURLOPT_HTTP_VERSION => (int)\constant('CURL_HTTP_VERSION_3ONLY'),
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_RESOLVE => [$probeHost . ':' . $port . ':127.0.0.1'],
                CURLOPT_CONNECTTIMEOUT_MS => \min(1_500, $remainingMilliseconds),
                CURLOPT_TIMEOUT_MS => $remainingMilliseconds,
                CURLOPT_FRESH_CONNECT => true,
                CURLOPT_FORBID_REUSE => true,
                CURLOPT_PROXY => '',
                CURLOPT_NOPROXY => '*',
                CURLOPT_NOSIGNAL => true,
            ];
            if (\defined('CURLOPT_PROTOCOLS') && \defined('CURLPROTO_HTTPS')) {
                $options[(int)\constant('CURLOPT_PROTOCOLS')] = (int)\constant('CURLPROTO_HTTPS');
            }
            if (\defined('CURLOPT_SSLVERSION') && \defined('CURL_SSLVERSION_TLSv1_3')) {
                $options[(int)\constant('CURLOPT_SSLVERSION')] = (int)\constant('CURL_SSLVERSION_TLSv1_3');
            }
            if (!@\curl_setopt_array($handle, $options)) {
                @\curl_close($handle);
                $lastError = 'unable to configure bounded HTTP/3 verifier';
                break;
            }
            $completed = \curl_exec($handle);
            $errno = \curl_errno($handle);
            $error = \curl_error($handle);
            $info = \curl_getinfo($handle);
            \curl_close($handle);
            $http3Version = \defined('CURL_HTTP_VERSION_3')
                ? (int)\constant('CURL_HTTP_VERSION_3')
                : 30;
            $health = \json_decode($responseBody, true);
            if ($completed === true
                && $this->lifecycleDeadlineAvailable()
                && !$responseOverflow
                && $errno === 0
                && (int)($info['http_code'] ?? 0) === 200
                && (int)($info['http_version'] ?? 0) === $http3Version
                && \is_array($health)
                && $this->healthMatchesBackendIdentity($health, $ownerInstance, $backendIdentity)
                && \preg_match(
                    '/^X-Wls-Nginx-Config:\s*' . \preg_quote($generation, '/') . '\s*$/mi',
                    $headers,
                ) === 1
            ) {
                $evidence = [
                    ...$evidence,
                    'http3_runtime_verified' => true,
                    'http3_status' => 'verified',
                    'http3_advertisement_status' => 'ADVERTISED',
                    'http3_protocol' => 'HTTP/3',
                    'http3_verified_at' => \date('c'),
                    'http3_reason' => 'Live owner-bound Nginx HTTP/3-only WLS health request verified.',
                    'public_protocols_runtime_verified' => ['http/3', 'http/2', 'http/1.1'],
                ];
                return ['ok' => true, 'message' => 'HTTP/3 runtime verified', 'evidence' => $evidence];
            }
            $lastError = $errno !== 0
                ? 'curl_errno=' . $errno . ' ' . $error
                : 'status=' . (int)($info['http_code'] ?? 0)
                    . ' http_version=' . (int)($info['http_version'] ?? 0);
            if (!$this->sleepWithinLifecycleDeadline(0.1)) {
                $lastError = 'managed nginx lifecycle deadline was exhausted';
                break;
            }
        }

        $lastError = \trim((string)\preg_replace('/\s+/', ' ', $lastError));
        $evidence['http3_status'] = 'failed';
        $evidence['http3_reason'] = 'Live Nginx HTTP/3 QUIC probe failed: ' . \substr($lastError, 0, 240);
        return ['ok' => false, 'message' => (string)$evidence['http3_reason'], 'evidence' => $evidence];
    }

    private function http3VerifierAvailable(): bool
    {
        if (!\function_exists('curl_init')
            || !\function_exists('curl_version')
            || !\defined('CURLOPT_HTTP_VERSION')
            || !\defined('CURL_HTTP_VERSION_3ONLY')
        ) {
            return false;
        }
        if (!\defined('CURL_VERSION_HTTP3')) {
            return true;
        }
        $curlVersion = \curl_version();
        return (((int)($curlVersion['features'] ?? 0)
                & (int)\constant('CURL_VERSION_HTTP3')) !== 0);
    }

    /**
     * @param array<string,mixed> $config
     * @param array<string,mixed> $http3
     */
    private function http3FailureCanDegrade(array $config, array $http3): bool
    {
        $evidence = \is_array($http3['evidence'] ?? null)
            ? $http3['evidence']
            : [];
        return (bool)($config['http3_enabled'] ?? false)
            && !($http3['ok'] ?? false)
            && (bool)($evidence['http3_verifier_available'] ?? false)
            && \hash_equals('failed', (string)($evidence['http3_status'] ?? ''))
            && \hash_equals(
                (string)($config['config_generation'] ?? ''),
                (string)($evidence['http3_config_generation'] ?? ''),
            )
            && \hash_equals(
                (string)($config['config_sha256'] ?? ''),
                (string)($evidence['http3_config_sha256'] ?? ''),
            );
    }

    /**
     * Replace a live but unverified QUIC candidate with an H2/H1-only candidate
     * inside the original config/owner transaction. The original before-images
     * stay authoritative until the downgraded data plane has passed every core
     * probe, so a QUIC-only failure cannot take a healthy TLS edge down.
     *
     * @param array<string,mixed> $currentConfig
     * @param array<string,mixed> $failedHttp3
     * @param array<string,mixed> $capabilities
     * @param array<string,mixed> $ownerIntent
     * @param list<int> $upstreamPorts
     * @param array<string,mixed>|null $certificateGeneration
     * @return array{
     *     config:array<string,mixed>,
     *     owner_intent:array<string,mixed>,
     *     status:array<string,mixed>,
     *     http_runtime_evidence:array<string,mixed>,
     *     http3:array<string,mixed>
     * }
     */
    private function degradeFailedHttp3Publication(
        array $currentConfig,
        array $failedHttp3,
        array $capabilities,
        array $ownerIntent,
        string $transactionId,
        ?string $rollback,
        int $upstreamPort,
        string $upstreamHost,
        array $upstreamPorts,
        string $ownerInstance,
        ?array $certificateGeneration,
        int $masterPid,
    ): array {
        if (!$this->http3FailureCanDegrade($currentConfig, $failedHttp3)
            || !\hash_equals(
                $transactionId,
                (string)($ownerIntent['transaction_id'] ?? ''),
            )
            || $masterPid < 1
        ) {
            throw new \RuntimeException(
                'Managed nginx HTTP/3 failure is not eligible for protocol-only degradation.',
            );
        }
        if ((bool)($ownerIntent['config_rollback_expected'] ?? false) !== ($rollback !== null)) {
            throw new \RuntimeException(
                'Managed nginx HTTP/3 degradation rollback identity is inconsistent.',
            );
        }

        $candidate = null;
        try {
            $degraded = $this->configWriter->write(
                $upstreamPort,
                $upstreamHost,
                (array)($currentConfig['server_names'] ?? []),
                true,
                (bool)($capabilities['gzip_module'] ?? false),
                true,
                false,
                $upstreamPorts,
                $certificateGeneration,
            );
            $candidate = (string)($degraded['conf'] ?? '');
            $this->assertHttp3DegradedConfigIdentity($currentConfig, $degraded, $candidate);
            $test = $this->processManager->testConfig(
                $candidate,
                $this->activeLifecycleDeadlineMonotonic,
            );
            if (($test['code'] ?? 1) !== 0) {
                throw new \RuntimeException(
                    'Managed nginx H2/H1 fallback candidate failed nginx -t: '
                        . \trim((string)($test['output'] ?? '')),
                );
            }

            $attemptEvidence = (array)($failedHttp3['evidence'] ?? []);
            $http3Evidence = [
                ...$attemptEvidence,
                'http3_runtime_verified' => false,
                'http3_verifier_available' => true,
                'http3_status' => 'failed',
                'http3_advertisement_status' => 'NOT_ADVERTISED',
                'http3_protocol' => '',
                'http3_verified_at' => '',
                'http3_reason' => \rtrim(
                    (string)($attemptEvidence['http3_reason']
                        ?? $failedHttp3['message']
                        ?? 'Live HTTP/3 verification failed.'),
                    ". \t\n\r\0\x0B",
                ) . '; HTTP/3 and Alt-Svc were removed in the same publication transaction.',
            ];
            $degradedOwner = [
                ...$ownerIntent,
                'server_names' => (array)($degraded['server_names'] ?? []),
                'listen_http' => (int)$degraded['http'],
                'listen_https' => (int)$degraded['https'],
                'ssl_required' => (bool)($degraded['ssl'] ?? false),
                'ssl_certificate_sha256'
                    => (string)($degraded['ssl_certificate_sha256'] ?? ''),
                'config_generation' => (string)$degraded['config_generation'],
                ...$this->certificateGenerationFacts($degraded),
                ...$this->protocolFacts($degraded, $capabilities),
                ...$http3Evidence,
                'updated_at' => \date('c'),
            ];
            $this->writeOwnerIntent($degradedOwner);

            $publication = (new NginxConfigPublication(
                $this->paths->confFile(),
                'managed nginx',
            ))->replacePublishedCandidate(
                $candidate,
                $transactionId,
                $rollback,
                (string)$currentConfig['config_sha256'],
            );
            $candidate = null;
            if (($publication['rollback'] ?? null) !== $rollback) {
                throw new \RuntimeException(
                    'Managed nginx H2/H1 fallback changed the original rollback identity.',
                );
            }
            $reloaded = $this->processManager->reload(
                $this->activeLifecycleDeadlineMonotonic,
            );
            if (!($reloaded['ok'] ?? false)
                || !$this->probeConfigGeneration(
                    (int)$degraded['http'],
                    (string)$degraded['config_generation'],
                )
                || !(bool)($degraded['ssl'] ?? false)
                || !$this->probeTls13(
                    (int)$degraded['https'],
                    (array)($degraded['server_names'] ?? []),
                    (string)$degraded['config_generation'],
                    (string)($degraded['ssl_certificate_sha256'] ?? ''),
                )
                || !(bool)($degraded['http2_enabled'] ?? false)
                || !$this->verifyHttpRuntime(
                    '2',
                    (int)$degraded['https'],
                    (array)($degraded['server_names'] ?? []),
                    (string)$degraded['config_generation'],
                    $ownerInstance,
                    $upstreamPort,
                )
                || !$this->verifyHttpRuntime(
                    '1.1',
                    (int)$degraded['https'],
                    (array)($degraded['server_names'] ?? []),
                    (string)$degraded['config_generation'],
                    $ownerInstance,
                    $upstreamPort,
                )
            ) {
                throw new \RuntimeException(
                    'Managed nginx H2/H1 fallback did not pass config, TLS, HTTP/2, and HTTP/1.1 probes: '
                        . (string)($reloaded['message'] ?? 'unknown'),
                );
            }
            $status = $this->processManager->status(
                $this->activeLifecycleDeadlineMonotonic,
            );
            if (!($status['ok'] ?? false)
                || !($status['running'] ?? false)
                || (int)($status['pid'] ?? 0) !== $masterPid
            ) {
                throw new \RuntimeException(
                    'Managed nginx master identity changed during HTTP/3 protocol degradation.',
                );
            }

            $httpRuntimeEvidence = [
                'tls13_runtime_verified' => true,
                'http2_runtime_verified' => true,
                'http1_runtime_verified' => true,
                'public_protocols_runtime_verified' => ['http/2', 'http/1.1'],
            ];
            $degradedOwner = [
                ...$degradedOwner,
                ...$httpRuntimeEvidence,
                ...$http3Evidence,
                'updated_at' => \date('c'),
            ];
            $this->writeOwnerIntent($degradedOwner);

            return [
                'config' => $degraded,
                'owner_intent' => $degradedOwner,
                'status' => $status,
                'http_runtime_evidence' => $httpRuntimeEvidence,
                'http3' => [
                    'ok' => true,
                    'message' => 'HTTP/3 verification failed; H2/H1 fallback verified',
                    'evidence' => $http3Evidence,
                ],
            ];
        } catch (\Throwable $throwable) {
            if ($candidate !== null) {
                try {
                    $this->configWriter->discardCandidate($candidate);
                } catch (\Throwable) {
                    // Preserve the publication failure. Transaction recovery
                    // retains the authoritative before-image evidence.
                }
            }
            throw $throwable;
        }
    }

    /**
     * @param array<string,mixed> $current
     * @param array<string,mixed> $degraded
     */
    private function assertHttp3DegradedConfigIdentity(
        array $current,
        array $degraded,
        string $candidate,
    ): void {
        $sameIdentity = (bool)($degraded['ssl'] ?? false)
            && (bool)($degraded['http2_enabled'] ?? false)
            && !(bool)($degraded['http3_enabled'] ?? false)
            && (int)($degraded['http'] ?? 0) === (int)($current['http'] ?? 0)
            && (int)($degraded['https'] ?? 0) === (int)($current['https'] ?? 0)
            && (array)($degraded['server_names'] ?? []) === (array)($current['server_names'] ?? [])
            && (array)($degraded['upstreams'] ?? []) === (array)($current['upstreams'] ?? [])
            && \hash_equals(
                (string)($current['ssl_certificate_sha256'] ?? ''),
                (string)($degraded['ssl_certificate_sha256'] ?? ''),
            )
            && $this->certificateGenerationFacts($current)
                === $this->certificateGenerationFacts($degraded)
            && \preg_match(
                '/\A[a-f0-9]{32}\z/D',
                (string)($degraded['config_generation'] ?? ''),
            ) === 1
            && \preg_match(
                '/\A[a-f0-9]{64}\z/D',
                (string)($degraded['config_sha256'] ?? ''),
            ) === 1;
        if (!$sameIdentity || $candidate === '') {
            throw new \RuntimeException(
                'Managed nginx H2/H1 fallback changed an immutable route or certificate identity.',
            );
        }
        $contents = GatewayProjectStateFilesystem::read(
            $candidate,
            16 * 1024 * 1024,
            'Managed Nginx H2/H1 fallback candidate',
        );
        if (\stripos($contents, 'Alt-Svc') !== false
            || \preg_match('/\bhttp3\s+on\s*;/i', $contents) === 1
            || \preg_match('/\blisten\s+[^;]*\bquic\b[^;]*;/i', $contents) === 1
        ) {
            throw new \RuntimeException(
                'Managed nginx H2/H1 fallback still advertises or listens for HTTP/3.',
            );
        }
        if (!\hash_equals(
            (string)$degraded['config_sha256'],
            \hash('sha256', $contents),
        )) {
            throw new \RuntimeException(
                'Managed nginx H2/H1 fallback candidate digest changed before publication.',
            );
        }
    }

    /** @return array<string,mixed>|null */
    private function readOwner(): ?array
    {
        return $this->readOwnerFile($this->paths->ownerFile());
    }

    /** @return array<string,mixed>|null */
    private function readOwnerFile(string $file): ?array
    {
        if (!\file_exists($file) && !\is_link($file)) {
            return null;
        }
        $decoded = \json_decode(GatewayProjectStateFilesystem::read(
            $file,
            4 * 1024 * 1024,
            'Managed Nginx owner state',
        ), true);
        if (!\is_array($decoded)
            || \trim((string)($decoded['instance_name'] ?? '')) === ''
            || \trim((string)($decoded['upstream_host'] ?? '')) === ''
            || (int)($decoded['upstream_port'] ?? 0) < 1
            || (int)($decoded['upstream_port'] ?? 0) > 65535
            || \preg_match('/\A[a-f0-9]{32}\z/D', (string)($decoded['config_generation'] ?? '')) !== 1
            || (\array_key_exists('transaction_id', $decoded)
                && \preg_match('/\A[a-f0-9]{32}\z/D', (string)$decoded['transaction_id']) !== 1)
            || (\array_key_exists('config_rollback_expected', $decoded)
                && !\is_bool($decoded['config_rollback_expected']))
            || (\array_key_exists('owner_rollback_expected', $decoded)
                && !\is_bool($decoded['owner_rollback_expected']))
            || ((bool)($decoded['ssl_required'] ?? false)
                && \preg_match(
                    '/\A[a-f0-9]{64}\z/D',
                    \strtolower((string)($decoded['ssl_certificate_sha256'] ?? '')),
                ) !== 1)
        ) {
            return null;
        }
        foreach (['previous_config_sha256', 'previous_owner_sha256'] as $previousHashField) {
            $previousHash = \strtolower((string)($decoded[$previousHashField] ?? ''));
            if ($previousHash !== ''
                && \preg_match('/\A[a-f0-9]{64}\z/D', $previousHash) !== 1
            ) {
                return null;
            }
        }
        if (((bool)($decoded['config_rollback_expected'] ?? false)
                && \array_key_exists('previous_config_sha256', $decoded)
                && \preg_match(
                    '/\A[a-f0-9]{64}\z/D',
                    \strtolower((string)($decoded['previous_config_sha256'] ?? '')),
                ) !== 1)
            || ((bool)($decoded['owner_rollback_expected'] ?? false)
                && \array_key_exists('previous_owner_sha256', $decoded)
                && \preg_match(
                    '/\A[a-f0-9]{64}\z/D',
                    \strtolower((string)($decoded['previous_owner_sha256'] ?? '')),
                ) !== 1)
        ) {
            return null;
        }
        $certificateContractPresent = \array_key_exists(
            'certificate_generation_managed',
            $decoded,
        );
        if ($certificateContractPresent
            && !\is_bool($decoded['certificate_generation_managed'])
        ) {
            return null;
        }
        if (($decoded['certificate_generation_managed'] ?? false) === true) {
            if (\trim((string)($decoded['certificate_domain'] ?? '')) === ''
                || (int)($decoded['certificate_generation'] ?? 0) < 1
            ) {
                return null;
            }
            foreach ([
                'certificate_source_digest',
                'certificate_cert_sha256',
                'certificate_key_sha256',
                'certificate_chain_sha256',
            ] as $certificateHashField) {
                if (\preg_match(
                    '/\A[a-f0-9]{64}\z/D',
                    \strtolower((string)($decoded[$certificateHashField] ?? '')),
                ) !== 1) {
                    return null;
                }
            }
        }
        $upstreamPorts = $decoded['upstream_ports'] ?? [(int)$decoded['upstream_port']];
        if (!\is_array($upstreamPorts) || !\array_is_list($upstreamPorts) || $upstreamPorts === []) {
            return null;
        }
        foreach ($upstreamPorts as $upstreamCandidate) {
            if (!\is_int($upstreamCandidate) || $upstreamCandidate < 1 || $upstreamCandidate > 65535) {
                return null;
            }
        }
        if (\count($upstreamPorts) !== \count(\array_unique($upstreamPorts, SORT_NUMERIC))) {
            return null;
        }
        foreach (['config_sha256', 'upstream_endpoint_sha256'] as $hashKey) {
            if (\array_key_exists($hashKey, $decoded)
                && \preg_match('/\A[a-f0-9]{64}\z/D', \strtolower((string)$decoded[$hashKey])) !== 1
            ) {
                return null;
            }
        }
        foreach (['listen_http', 'listen_https'] as $listenField) {
            if (\array_key_exists($listenField, $decoded)
                && ((int)$decoded[$listenField] < 1 || (int)$decoded[$listenField] > 65535)
            ) {
                return null;
            }
        }
        foreach ([
            'http2_enabled',
            'http2_runtime_verified',
            'http1_fallback',
            'http1_runtime_verified',
            'http3_capable',
            'http3_configured',
            'http3_runtime_verified',
            'http3_verifier_available',
            'alt_svc_enabled',
            'tls13_only',
            'tls13_runtime_verified',
            'tls_session_cache_shared',
            'tls_session_tickets',
            'tls_session_ticket_keys_shared',
            'tls_session_resumption_runtime_verified',
            'tls_session_resumption_same_worker_runtime_verified',
            'tls_session_resumption_cross_worker_runtime_verified',
            'tls_session_resumption_reload_continuity_verified',
        ] as $booleanKey) {
            if (\array_key_exists($booleanKey, $decoded) && !\is_bool($decoded[$booleanKey])) {
                return null;
            }
        }
        $publicProtocols = \is_array($decoded['public_protocols'] ?? null)
            ? \array_values(\array_filter(
                \array_map(static fn(mixed $protocol): string => (string)$protocol, $decoded['public_protocols']),
                static fn(string $protocol): bool => \in_array(
                    $protocol,
                    ['http/3', 'http/2', 'http/1.1'],
                    true,
                ),
            ))
            : [];
        $rawPublicProtocols = $decoded['public_protocols'] ?? null;
        if (\is_array($rawPublicProtocols)
            && (\count($publicProtocols) !== \count($rawPublicProtocols)
                || \count($publicProtocols) !== \count(\array_unique($publicProtocols)))
        ) {
            return null;
        }
        if (((bool)($decoded['http2_runtime_verified'] ?? false)
                && !(bool)($decoded['http2_enabled'] ?? false))
            || ((bool)($decoded['http1_runtime_verified'] ?? false)
                && !(bool)($decoded['http1_fallback'] ?? false))
            || ((bool)($decoded['http3_runtime_verified'] ?? false)
                && !(bool)($decoded['http3_configured'] ?? false))
            || ((bool)($decoded['http3_configured'] ?? false)
                && (!(bool)($decoded['http3_capable'] ?? false)
                    || !(bool)($decoded['alt_svc_enabled'] ?? false)
                    || !\in_array('http/3', $publicProtocols, true)))
            || ((bool)($decoded['tls13_runtime_verified'] ?? false)
                && !(bool)($decoded['tls13_only'] ?? false))
            || ((bool)($decoded['tls_session_ticket_keys_shared'] ?? false)
                && (!(bool)($decoded['tls_session_cache_shared'] ?? false)
                    || !(bool)($decoded['tls_session_tickets'] ?? false)))
            || ((bool)($decoded['tls_session_resumption_runtime_verified'] ?? false)
                && !(bool)($decoded['tls_session_ticket_keys_shared'] ?? false))
        ) {
            return null;
        }
        $http3Status = (string)($decoded['http3_status'] ?? '');
        $http3Protocol = (string)($decoded['http3_protocol'] ?? '');
        $http3AdvertisementStatus = (string)(
            $decoded['http3_advertisement_status'] ?? ''
        );
        if (!\in_array(
            $http3Status,
            ['', 'pending', 'not_configured', 'verification_unavailable', 'failed', 'verified'],
            true,
        )
            || !\in_array($http3Protocol, ['', 'HTTP/3'], true)
            || !\in_array(
                $http3AdvertisementStatus,
                ['', 'ADVERTISED', 'NOT_ADVERTISED'],
                true,
            )
            || ($http3AdvertisementStatus === 'ADVERTISED'
                && !(bool)($decoded['http3_configured'] ?? false))
            || ($http3AdvertisementStatus === 'NOT_ADVERTISED'
                && (bool)($decoded['http3_configured'] ?? false))
            || (\array_key_exists('http3_master_pid', $decoded)
                && (!\is_int($decoded['http3_master_pid']) || $decoded['http3_master_pid'] < 0))
            || (\array_key_exists('http3_reason', $decoded) && !\is_string($decoded['http3_reason']))
        ) {
            return null;
        }
        foreach (['http3_config_sha256', 'http3_ssl_certificate_sha256'] as $http3HashField) {
            $value = \strtolower((string)($decoded[$http3HashField] ?? ''));
            if ($value !== '' && \preg_match('/\A[a-f0-9]{64}\z/D', $value) !== 1) {
                return null;
            }
        }
        $http3EvidenceGeneration = \strtolower((string)($decoded['http3_config_generation'] ?? ''));
        if ($http3EvidenceGeneration !== ''
            && \preg_match('/\A[a-f0-9]{32}\z/D', $http3EvidenceGeneration) !== 1
        ) {
            return null;
        }
        $http3Verified = (bool)($decoded['http3_runtime_verified'] ?? false);
        if ($http3Verified) {
            if (!(bool)($decoded['http3_verifier_available'] ?? false)
                || $http3Status !== 'verified'
                || $http3Protocol !== 'HTTP/3'
                || (int)($decoded['http3_master_pid'] ?? 0) < 1
                || !\hash_equals(
                    (string)$decoded['config_generation'],
                    (string)($decoded['http3_config_generation'] ?? ''),
                )
                || !\hash_equals(
                    \strtolower((string)($decoded['config_sha256'] ?? '')),
                    \strtolower((string)($decoded['http3_config_sha256'] ?? '')),
                )
                || !\hash_equals(
                    \strtolower((string)($decoded['ssl_certificate_sha256'] ?? '')),
                    \strtolower((string)($decoded['http3_ssl_certificate_sha256'] ?? '')),
                )
                || !\is_string($decoded['http3_verified_at'] ?? null)
                || \strtotime((string)$decoded['http3_verified_at']) === false
            ) {
                return null;
            }
        }
        if (!$http3Verified
            && \array_key_exists('http3_verified_at', $decoded)
            && !\is_string($decoded['http3_verified_at'])
        ) {
            return null;
        }
        $resumptionIntegerFields = [
            'tls_session_resumption_sample_count',
            'tls_session_resumption_completed_count',
            'tls_session_resumption_failed_count',
            'tls_session_resumption_fresh_count',
            'tls_session_resumption_resumed_count',
            'tls_session_resumption_same_worker_resumed_count',
            'tls_session_resumption_cross_worker_resumed_count',
            'tls_session_resumption_resumed_tls_handshake_p95_us',
            'tls_session_resumption_baseline_worker_pid',
            'tls_session_resumption_observed_worker_count',
            'tls_session_resumption_effective_worker_count',
            'tls_session_resumption_master_pid',
            'tls_session_resumption_reload_issuer_worker_pid',
            'tls_session_resumption_reload_probe_worker_pid',
            'tls_session_resumption_reload_master_pid',
            'tls_session_resumption_reload_tls_handshake_us',
        ];
        foreach ($resumptionIntegerFields as $integerField) {
            if (\array_key_exists($integerField, $decoded)
                && (!\is_int($decoded[$integerField]) || $decoded[$integerField] < 0)
            ) {
                return null;
            }
        }
        $resumptionAllowedStrings = [
            'tls_session_resumption_status' => ['', 'verified'],
            'tls_session_resumption_proof_model' => [
                '',
                ManagedNginxTlsSessionResumptionVerifier::PROOF_MODEL,
            ],
            'tls_session_resumption_baseline_result' => ['', '.'],
            'tls_session_resumption_negative_control_result' => ['', '.'],
            'tls_session_resumption_tls_protocol' => ['', 'TLSv1.3'],
            'tls_session_resumption_http_protocol' => ['', 'http/1.1'],
            'tls_session_resumption_same_worker_status' => ['', 'verified', 'pending'],
            'tls_session_resumption_cross_worker_status' => [
                '',
                'verified',
                'pending',
                'not_applicable',
            ],
            'tls_session_resumption_reload_continuity_status' => [
                '',
                'not_verified',
                'verified',
            ],
            'tls_session_resumption_reload_continuity_proof_model' => [
                '',
                ManagedNginxTlsSessionResumptionVerifier::RELOAD_CONTINUITY_PROOF_MODEL,
            ],
            'tls_session_resumption_reload_continuity_result' => ['', 'r'],
        ];
        foreach ($resumptionAllowedStrings as $stringField => $allowedValues) {
            if (\array_key_exists($stringField, $decoded)
                && (!\is_string($decoded[$stringField])
                    || !\in_array($decoded[$stringField], $allowedValues, true))
            ) {
                return null;
            }
        }
        foreach ([
            'tls_session_resumption_config_sha256',
            'tls_session_resumption_ssl_certificate_sha256',
        ] as $hashField) {
            if (\array_key_exists($hashField, $decoded)
                && \preg_match(
                    '/\A[a-f0-9]{64}\z/D',
                    \strtolower((string)$decoded[$hashField]),
                ) !== 1
            ) {
                return null;
            }
        }
        if (\array_key_exists('tls_session_resumption_config_generation', $decoded)
            && \preg_match(
                '/\A[a-f0-9]{32}\z/D',
                \strtolower((string)$decoded['tls_session_resumption_config_generation']),
            ) !== 1
        ) {
            return null;
        }
        foreach ([
            'tls_session_resumption_reload_previous_config_generation',
            'tls_session_resumption_reload_config_generation',
        ] as $reloadGenerationField) {
            $reloadGeneration = \strtolower((string)($decoded[$reloadGenerationField] ?? ''));
            if ($reloadGeneration !== ''
                && \preg_match('/\A[a-f0-9]{32}\z/D', $reloadGeneration) !== 1
            ) {
                return null;
            }
        }

        $resumptionVerified = (bool)($decoded['tls_session_resumption_runtime_verified'] ?? false);
        if ($resumptionVerified) {
            $sampleCount = (int)($decoded['tls_session_resumption_sample_count'] ?? 0);
            $completedCount = (int)($decoded['tls_session_resumption_completed_count'] ?? 0);
            $failedCount = (int)($decoded['tls_session_resumption_failed_count'] ?? 0);
            $freshCount = (int)($decoded['tls_session_resumption_fresh_count'] ?? 0);
            $resumedCount = (int)($decoded['tls_session_resumption_resumed_count'] ?? 0);
            $sameResumedCount = (int)($decoded['tls_session_resumption_same_worker_resumed_count'] ?? 0);
            $crossResumedCount = (int)($decoded['tls_session_resumption_cross_worker_resumed_count'] ?? 0);
            $observedWorkerCount = (int)($decoded['tls_session_resumption_observed_worker_count'] ?? 0);
            $effectiveWorkerCount = (int)($decoded['tls_session_resumption_effective_worker_count'] ?? 0);
            $sameStatus = (string)($decoded['tls_session_resumption_same_worker_status'] ?? '');
            $crossStatus = (string)($decoded['tls_session_resumption_cross_worker_status'] ?? '');
            $resumedTlsHandshakeP95Us = (int)(
                $decoded['tls_session_resumption_resumed_tls_handshake_p95_us'] ?? 0
            );
            $sameWorkerVerified = (bool)(
                $decoded['tls_session_resumption_same_worker_runtime_verified'] ?? false
            );
            $crossWorkerVerified = (bool)(
                $decoded['tls_session_resumption_cross_worker_runtime_verified'] ?? false
            );
            if (!\array_key_exists('tls_session_resumption_resumed_tls_handshake_p95_us', $decoded)
                || !\hash_equals('verified', (string)($decoded['tls_session_resumption_status'] ?? ''))
                || !\hash_equals(
                    ManagedNginxTlsSessionResumptionVerifier::PROOF_MODEL,
                    (string)($decoded['tls_session_resumption_proof_model'] ?? ''),
                )
                || !\hash_equals('.', (string)($decoded['tls_session_resumption_baseline_result'] ?? ''))
                || !\hash_equals('.', (string)($decoded['tls_session_resumption_negative_control_result'] ?? ''))
                || !\hash_equals('TLSv1.3', (string)($decoded['tls_session_resumption_tls_protocol'] ?? ''))
                || !\hash_equals('http/1.1', (string)($decoded['tls_session_resumption_http_protocol'] ?? ''))
                || $sampleCount < ManagedNginxTlsSessionResumptionVerifier::MIN_VALID_PROBES
                || $sampleCount > ManagedNginxTlsSessionResumptionVerifier::MAX_PROOF_PAIRS
                || $completedCount < ManagedNginxTlsSessionResumptionVerifier::MIN_VALID_PROBES
                || $failedCount !== 0
                || $completedCount + $failedCount !== $sampleCount
                || $freshCount + $resumedCount !== $completedCount
                || $resumedCount < 1
                || $sameResumedCount + $crossResumedCount !== $resumedCount
                || $resumedTlsHandshakeP95Us < 1
                || $resumedTlsHandshakeP95Us
                    > ManagedNginxTlsSessionResumptionVerifier::MAX_RESUMED_TLS_HANDSHAKE_P95_US
                || (int)($decoded['tls_session_resumption_baseline_worker_pid'] ?? 0) < 1
                || $observedWorkerCount < 1
                || $effectiveWorkerCount < 1
                || $observedWorkerCount > $effectiveWorkerCount
                || (int)($decoded['tls_session_resumption_master_pid'] ?? 0) < 1
                || !\hash_equals(
                    (string)$decoded['config_generation'],
                    (string)($decoded['tls_session_resumption_config_generation'] ?? ''),
                )
                || !\hash_equals(
                    \strtolower((string)($decoded['config_sha256'] ?? '')),
                    \strtolower((string)($decoded['tls_session_resumption_config_sha256'] ?? '')),
                )
                || !\hash_equals(
                    \strtolower((string)($decoded['ssl_certificate_sha256'] ?? '')),
                    \strtolower((string)($decoded['tls_session_resumption_ssl_certificate_sha256'] ?? '')),
                )
                || $sameResumedCount < 1
                || $sameStatus !== 'verified'
                || !$sameWorkerVerified
                || ($effectiveWorkerCount === 1
                    ? ($observedWorkerCount !== 1
                        || $crossResumedCount !== 0
                        || $crossStatus !== 'not_applicable'
                        || $crossWorkerVerified)
                    : ($observedWorkerCount < 2
                        || $crossResumedCount < 1
                        || $crossStatus !== 'verified'
                        || !$crossWorkerVerified))
                || !\is_string($decoded['tls_session_resumption_verified_at'] ?? null)
                || \strtotime((string)$decoded['tls_session_resumption_verified_at']) === false
            ) {
                return null;
            }
        } elseif (\array_key_exists('tls_session_resumption_verified_at', $decoded)
            && !\is_string($decoded['tls_session_resumption_verified_at'])
        ) {
            return null;
        }
        $reloadContinuityVerified = (bool)(
            $decoded['tls_session_resumption_reload_continuity_verified'] ?? false
        );
        if ($reloadContinuityVerified) {
            $reloadPreviousGeneration = (string)(
                $decoded['tls_session_resumption_reload_previous_config_generation'] ?? ''
            );
            $reloadCurrentGeneration = (string)(
                $decoded['tls_session_resumption_reload_config_generation'] ?? ''
            );
            $reloadIssuerWorkerPid = (int)(
                $decoded['tls_session_resumption_reload_issuer_worker_pid'] ?? 0
            );
            $reloadProbeWorkerPid = (int)(
                $decoded['tls_session_resumption_reload_probe_worker_pid'] ?? 0
            );
            $reloadMasterPid = (int)(
                $decoded['tls_session_resumption_reload_master_pid'] ?? 0
            );
            $reloadHandshakeUs = (int)(
                $decoded['tls_session_resumption_reload_tls_handshake_us'] ?? 0
            );
            if (!$resumptionVerified
                || !\hash_equals(
                    'verified',
                    (string)($decoded['tls_session_resumption_reload_continuity_status'] ?? ''),
                )
                || !\hash_equals(
                    ManagedNginxTlsSessionResumptionVerifier::RELOAD_CONTINUITY_PROOF_MODEL,
                    (string)($decoded['tls_session_resumption_reload_continuity_proof_model'] ?? ''),
                )
                || !\hash_equals(
                    'r',
                    (string)($decoded['tls_session_resumption_reload_continuity_result'] ?? ''),
                )
                || $reloadIssuerWorkerPid < 1
                || $reloadProbeWorkerPid < 1
                || $reloadIssuerWorkerPid === $reloadProbeWorkerPid
                || $reloadMasterPid < 1
                || $reloadMasterPid !== (int)($decoded['tls_session_resumption_master_pid'] ?? 0)
                || $reloadHandshakeUs < 1
                || $reloadHandshakeUs
                    > ManagedNginxTlsSessionResumptionVerifier::MAX_RESUMED_TLS_HANDSHAKE_P95_US
                || \preg_match('/\A[a-f0-9]{32}\z/D', $reloadPreviousGeneration) !== 1
                || \preg_match('/\A[a-f0-9]{32}\z/D', $reloadCurrentGeneration) !== 1
                || \hash_equals($reloadPreviousGeneration, $reloadCurrentGeneration)
                || !\hash_equals(
                    (string)$decoded['config_generation'],
                    $reloadCurrentGeneration,
                )
                || !\is_string($decoded['tls_session_resumption_reload_verified_at'] ?? null)
                || \strtotime(
                    (string)$decoded['tls_session_resumption_reload_verified_at']
                ) === false
            ) {
                return null;
            }
        } elseif (\array_key_exists('tls_session_resumption_reload_verified_at', $decoded)
            && !\is_string($decoded['tls_session_resumption_reload_verified_at'])
        ) {
            return null;
        }

        $serverNames = \is_array($decoded['server_names'] ?? null)
            ? \array_values(\array_filter(
                \array_map(static fn(mixed $name): string => \trim((string)$name), $decoded['server_names']),
                static fn(string $name): bool => $name !== '',
            ))
            : [];

        $owner = [
            'transaction_id' => (string)($decoded['transaction_id'] ?? ''),
            'instance_name' => \trim((string)$decoded['instance_name']),
            'config_rollback_expected' => (bool)($decoded['config_rollback_expected'] ?? false),
            'previous_config_sha256' => \strtolower((string)(
                $decoded['previous_config_sha256'] ?? ''
            )),
            'owner_rollback_expected' => (bool)($decoded['owner_rollback_expected'] ?? false),
            'previous_owner_sha256' => \strtolower((string)(
                $decoded['previous_owner_sha256'] ?? ''
            )),
            'upstream_host' => \trim((string)$decoded['upstream_host']),
            'upstream_port' => (int)$decoded['upstream_port'],
            'upstream_ports' => \array_values($upstreamPorts),
            'server_names' => $serverNames,
            'ssl_required' => (bool)($decoded['ssl_required'] ?? false),
            'ssl_certificate_sha256' => \strtolower((string)($decoded['ssl_certificate_sha256'] ?? '')),
            'listen_http' => (int)($decoded['listen_http'] ?? 0),
            'listen_https' => (int)($decoded['listen_https'] ?? 0),
            'config_generation' => (string)$decoded['config_generation'],
            'config_sha256' => \strtolower((string)($decoded['config_sha256'] ?? '')),
            'upstream_endpoint_sha256' => \strtolower((string)($decoded['upstream_endpoint_sha256'] ?? '')),
            'public_protocols' => $publicProtocols,
            'public_protocols_runtime_verified' => \is_array($decoded['public_protocols_runtime_verified'] ?? null)
                ? \array_values($decoded['public_protocols_runtime_verified'])
                : [],
            'tls_session_resumption_resumed_tls_handshake_p95_us' => (int)($decoded['tls_session_resumption_resumed_tls_handshake_p95_us'] ?? 0),
            'http2_enabled' => (bool)($decoded['http2_enabled'] ?? false),
            'http2_runtime_verified' => (bool)($decoded['http2_runtime_verified'] ?? false),
            'http1_fallback' => (bool)($decoded['http1_fallback'] ?? false),
            'http1_runtime_verified' => (bool)($decoded['http1_runtime_verified'] ?? false),
            'http3_capable' => (bool)($decoded['http3_capable'] ?? false),
            'http3_configured' => (bool)($decoded['http3_configured'] ?? false),
            'http3_runtime_verified' => $http3Verified,
            'http3_verifier_available' => (bool)($decoded['http3_verifier_available'] ?? false),
            'http3_status' => $http3Status,
            'http3_advertisement_status' => $http3AdvertisementStatus,
            'http3_protocol' => $http3Protocol,
            'http3_master_pid' => (int)($decoded['http3_master_pid'] ?? 0),
            'http3_config_generation' => (string)($decoded['http3_config_generation'] ?? ''),
            'http3_config_sha256' => \strtolower((string)($decoded['http3_config_sha256'] ?? '')),
            'http3_ssl_certificate_sha256' => \strtolower((string)($decoded['http3_ssl_certificate_sha256'] ?? '')),
            'http3_verified_at' => (string)($decoded['http3_verified_at'] ?? ''),
            'http3_reason' => (string)($decoded['http3_reason'] ?? ''),
            'alt_svc_enabled' => (bool)($decoded['alt_svc_enabled'] ?? false),
            'tls13_only' => (bool)($decoded['tls13_only'] ?? false),
            'tls13_runtime_verified' => (bool)($decoded['tls13_runtime_verified'] ?? false),
            'tls_session_cache_shared' => (bool)($decoded['tls_session_cache_shared'] ?? false),
            'tls_session_tickets' => (bool)($decoded['tls_session_tickets'] ?? false),
            'tls_session_ticket_keys_shared' => (bool)($decoded['tls_session_ticket_keys_shared'] ?? false),
            'tls_session_resumption_runtime_verified' => $resumptionVerified,
            'tls_session_resumption_status' => (string)($decoded['tls_session_resumption_status'] ?? ''),
            'tls_session_resumption_proof_model' => (string)($decoded['tls_session_resumption_proof_model'] ?? ''),
            'tls_session_resumption_baseline_result' => (string)($decoded['tls_session_resumption_baseline_result'] ?? ''),
            'tls_session_resumption_negative_control_result' => (string)($decoded['tls_session_resumption_negative_control_result'] ?? ''),
            'tls_session_resumption_tls_protocol' => (string)($decoded['tls_session_resumption_tls_protocol'] ?? ''),
            'tls_session_resumption_http_protocol' => (string)($decoded['tls_session_resumption_http_protocol'] ?? ''),
            'tls_session_resumption_sample_count' => (int)($decoded['tls_session_resumption_sample_count'] ?? 0),
            'tls_session_resumption_completed_count' => (int)($decoded['tls_session_resumption_completed_count'] ?? 0),
            'tls_session_resumption_failed_count' => (int)($decoded['tls_session_resumption_failed_count'] ?? 0),
            'tls_session_resumption_fresh_count' => (int)($decoded['tls_session_resumption_fresh_count'] ?? 0),
            'tls_session_resumption_resumed_count' => (int)($decoded['tls_session_resumption_resumed_count'] ?? 0),
            'tls_session_resumption_same_worker_status' => (string)($decoded['tls_session_resumption_same_worker_status'] ?? ''),
            'tls_session_resumption_same_worker_runtime_verified' => (bool)($decoded['tls_session_resumption_same_worker_runtime_verified'] ?? false),
            'tls_session_resumption_same_worker_resumed_count' => (int)($decoded['tls_session_resumption_same_worker_resumed_count'] ?? 0),
            'tls_session_resumption_cross_worker_status' => (string)($decoded['tls_session_resumption_cross_worker_status'] ?? ''),
            'tls_session_resumption_cross_worker_runtime_verified' => (bool)($decoded['tls_session_resumption_cross_worker_runtime_verified'] ?? false),
            'tls_session_resumption_cross_worker_resumed_count' => (int)($decoded['tls_session_resumption_cross_worker_resumed_count'] ?? 0),
            'tls_session_resumption_baseline_worker_pid' => (int)($decoded['tls_session_resumption_baseline_worker_pid'] ?? 0),
            'tls_session_resumption_observed_worker_count' => (int)($decoded['tls_session_resumption_observed_worker_count'] ?? 0),
            'tls_session_resumption_effective_worker_count' => (int)($decoded['tls_session_resumption_effective_worker_count'] ?? 0),
            'tls_session_resumption_master_pid' => (int)($decoded['tls_session_resumption_master_pid'] ?? 0),
            'tls_session_resumption_config_generation' => (string)($decoded['tls_session_resumption_config_generation'] ?? ''),
            'tls_session_resumption_config_sha256' => \strtolower((string)($decoded['tls_session_resumption_config_sha256'] ?? '')),
            'tls_session_resumption_ssl_certificate_sha256' => \strtolower((string)($decoded['tls_session_resumption_ssl_certificate_sha256'] ?? '')),
            'tls_session_resumption_verified_at' => (string)($decoded['tls_session_resumption_verified_at'] ?? ''),
            'tls_session_resumption_reload_continuity_verified' => $reloadContinuityVerified,
            'tls_session_resumption_reload_continuity_status' => (string)($decoded['tls_session_resumption_reload_continuity_status'] ?? ''),
            'tls_session_resumption_reload_continuity_proof_model' => (string)($decoded['tls_session_resumption_reload_continuity_proof_model'] ?? ''),
            'tls_session_resumption_reload_continuity_result' => (string)($decoded['tls_session_resumption_reload_continuity_result'] ?? ''),
            'tls_session_resumption_reload_issuer_worker_pid' => (int)($decoded['tls_session_resumption_reload_issuer_worker_pid'] ?? 0),
            'tls_session_resumption_reload_probe_worker_pid' => (int)($decoded['tls_session_resumption_reload_probe_worker_pid'] ?? 0),
            'tls_session_resumption_reload_master_pid' => (int)($decoded['tls_session_resumption_reload_master_pid'] ?? 0),
            'tls_session_resumption_reload_tls_handshake_us' => (int)($decoded['tls_session_resumption_reload_tls_handshake_us'] ?? 0),
            'tls_session_resumption_reload_previous_config_generation' => (string)($decoded['tls_session_resumption_reload_previous_config_generation'] ?? ''),
            'tls_session_resumption_reload_config_generation' => (string)($decoded['tls_session_resumption_reload_config_generation'] ?? ''),
            'tls_session_resumption_reload_verified_at' => (string)($decoded['tls_session_resumption_reload_verified_at'] ?? ''),
            'updated_at' => (string)($decoded['updated_at'] ?? ''),
        ];
        if ($certificateContractPresent) {
            $owner = [
                ...$owner,
                'certificate_generation_managed'
                    => (bool)$decoded['certificate_generation_managed'],
                'certificate_domain' => \strtolower(\trim((string)(
                    $decoded['certificate_domain'] ?? ''
                ))),
                'certificate_generation' => (int)($decoded['certificate_generation'] ?? 0),
                'certificate_source_digest' => \strtolower((string)(
                    $decoded['certificate_source_digest'] ?? ''
                )),
                'certificate_cert_sha256' => \strtolower((string)(
                    $decoded['certificate_cert_sha256'] ?? ''
                )),
                'certificate_key_sha256' => \strtolower((string)(
                    $decoded['certificate_key_sha256'] ?? ''
                )),
                'certificate_chain_sha256' => \strtolower((string)(
                    $decoded['certificate_chain_sha256'] ?? ''
                )),
            ];
        }
        return $owner;
    }

    /** @param array<string,mixed> $owner */
    private function writeOwner(array $owner): void
    {
        $file = $this->paths->ownerFile();
        $json = $this->ownerJson($owner);
        if (\file_exists($file) || \is_link($file)) {
            GatewayProjectStateFilesystem::read(
                $file,
                4 * 1024 * 1024,
                'Existing managed Nginx owner state',
            );
            $transactionId = \strtolower(\trim((string)($owner['transaction_id'] ?? '')));
            if (\preg_match('/\A[a-f0-9]{32}\z/D', $transactionId) !== 1) {
                throw new \RuntimeException('Managed nginx owner transaction id is invalid.');
            }
        }
        try {
            GatewayProjectStateFilesystem::atomicWrite($file, $json, 0600);
        } catch (\Throwable $throwable) {
            $this->reconcileProjectStateWriteAfterImage(
                $file,
                \hash('sha256', $json),
                'Managed Nginx owner after-image',
                $throwable,
            );
        }
    }

    /** @param array<string,mixed> $owner */
    private function writeOwnerIntent(array $owner): void
    {
        $file = $this->paths->ownerIntentFile();
        $json = $this->ownerJson($owner);
        try {
            GatewayProjectStateFilesystem::atomicWrite($file, $json, 0600);
        } catch (\Throwable $throwable) {
            $this->reconcileProjectStateWriteAfterImage(
                $file,
                \hash('sha256', $json),
                'Managed Nginx owner intent after-image',
                $throwable,
            );
        }
    }

    /** @param array<string,mixed> $owner */
    private function ownerJson(array $owner): string
    {
        return \json_encode(
            $owner,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );
    }

    /** @param array<string,mixed>|null $previousOwner */
    private function captureOwnerBeforeImage(?array $previousOwner): ?string
    {
        $file = $this->paths->ownerFile();
        if ($previousOwner === null) {
            if ($this->pathExistsNoFollow($file)) {
                throw new \RuntimeException(
                    'Managed nginx owner before-image exists but is not verifiable.',
                );
            }
            return null;
        }
        $contents = GatewayProjectStateFilesystem::read(
            $file,
            4 * 1024 * 1024,
            'Managed Nginx owner before-image',
        );
        $current = $this->readOwner();
        if (!\is_array($current)
            || !\hash_equals(
                $this->ownerSemanticDigest($previousOwner),
                $this->ownerSemanticDigest($current),
            )
        ) {
            throw new \RuntimeException(
                'Managed nginx owner changed while its before-image was captured.',
            );
        }
        return $contents;
    }

    /** @param array<string,mixed> $intent */
    private function stageOwnerRollback(array $intent, ?string $previousOwnerContents): void
    {
        $expected = (bool)($intent['owner_rollback_expected'] ?? false);
        if ($expected !== ($previousOwnerContents !== null)) {
            throw new \RuntimeException('Managed nginx owner rollback expectation is inconsistent.');
        }
        if ($previousOwnerContents === null) {
            return;
        }
        $rollback = $this->ownerRollbackPath((string)$intent['transaction_id']);
        if ($this->pathExistsNoFollow($rollback)) {
            throw new \RuntimeException('Managed nginx owner rollback already exists.');
        }
        $expectedDigest = (string)($intent['previous_owner_sha256'] ?? '');
        if (!\hash_equals($expectedDigest, \hash('sha256', $previousOwnerContents))) {
            throw new \RuntimeException('Managed nginx owner rollback digest is inconsistent.');
        }
        try {
            GatewayProjectStateFilesystem::atomicWrite(
                $rollback,
                $previousOwnerContents,
                0600,
            );
        } catch (\Throwable $throwable) {
            $this->reconcileProjectStateWriteAfterImage(
                $rollback,
                $expectedDigest,
                'Managed Nginx owner rollback after-image',
                $throwable,
            );
        }
        $this->assertOwnerRollbackMatchesIntent($intent, $rollback);
    }

    private function ownerRollbackPath(string $transactionId): string
    {
        $transactionId = \strtolower(\trim($transactionId));
        if (\preg_match('/\A[a-f0-9]{32}\z/D', $transactionId) !== 1) {
            throw new \InvalidArgumentException('Managed nginx owner rollback transaction is invalid.');
        }
        return $this->paths->ownerFile() . '.rollback.' . $transactionId;
    }

    /** @param array<string,mixed> $intent */
    private function assertOwnerRollbackMatchesIntent(array $intent, string $rollback): void
    {
        if (!\hash_equals(
            $this->ownerRollbackPath((string)$intent['transaction_id']),
            $rollback,
        ) || !$this->managedFileDigestMatches(
            $rollback,
            (string)($intent['previous_owner_sha256'] ?? ''),
            'Managed Nginx owner rollback',
        ) || !\is_array($this->readOwnerFile($rollback))) {
            throw new \RuntimeException('Managed nginx owner rollback before-image is invalid.');
        }
    }

    /** @param array<string,mixed> $intent */
    private function restoreOwnerBeforeImage(array $intent): void
    {
        $ownerFile = $this->paths->ownerFile();
        $beforeMatches = $this->ownerBeforeImageMatches($intent);
        $afterMatches = $this->ownerAfterImageMatches($intent);
        if (!$beforeMatches && !$afterMatches) {
            throw new \RuntimeException(
                'Managed nginx owner is neither the exact before-image nor after-image.',
            );
        }
        $rollback = $this->ownerRollbackPath((string)$intent['transaction_id']);
        if ((bool)($intent['owner_rollback_expected'] ?? false)) {
            if (!$beforeMatches) {
                $this->assertOwnerRollbackMatchesIntent($intent, $rollback);
                $contents = GatewayProjectStateFilesystem::read(
                    $rollback,
                    4 * 1024 * 1024,
                    'Managed Nginx owner rollback',
                );
                try {
                    GatewayProjectStateFilesystem::atomicWrite($ownerFile, $contents, 0600);
                } catch (\Throwable $throwable) {
                    $this->reconcileProjectStateWriteAfterImage(
                        $ownerFile,
                        (string)$intent['previous_owner_sha256'],
                        'Restored managed Nginx owner before-image',
                        $throwable,
                    );
                }
            }
            if ($this->pathExistsNoFollow($rollback)) {
                $this->assertOwnerRollbackMatchesIntent($intent, $rollback);
                try {
                    GatewayProjectStateFilesystem::removeRegular(
                        $rollback,
                        'Restored managed Nginx owner rollback',
                    );
                } catch (\Throwable $throwable) {
                    $this->reconcileProjectStateRemovalAfterImage(
                        $rollback,
                        'Restored managed Nginx owner rollback',
                        $throwable,
                    );
                }
            }
            if (!$this->ownerBeforeImageMatches($intent)) {
                throw new \RuntimeException('Managed nginx owner before-image was not restored.');
            }
            return;
        }

        if ($this->pathExistsNoFollow($rollback)) {
            throw new \RuntimeException(
                'First managed nginx publication has unexpected owner rollback evidence.',
            );
        }
        if ($afterMatches && $this->pathExistsNoFollow($ownerFile)) {
            try {
                GatewayProjectStateFilesystem::removeRegular(
                    $ownerFile,
                    'Rolled-back first managed Nginx owner state',
                );
            } catch (\Throwable $throwable) {
                $this->reconcileProjectStateRemovalAfterImage(
                    $ownerFile,
                    'Rolled-back first managed Nginx owner state',
                    $throwable,
                );
            }
        }
        if ($this->pathExistsNoFollow($ownerFile)) {
            throw new \RuntimeException('First managed nginx owner before-image is not absent.');
        }
    }

    /** @param array<string,mixed> $intent */
    private function ownerBeforeImageMatches(array $intent): bool
    {
        $ownerFile = $this->paths->ownerFile();
        if (!(bool)($intent['owner_rollback_expected'] ?? false)) {
            return !$this->pathExistsNoFollow($ownerFile);
        }
        return $this->managedFileDigestMatches(
            $ownerFile,
            (string)($intent['previous_owner_sha256'] ?? ''),
            'Managed Nginx owner before-image',
        );
    }

    /** @param array<string,mixed> $expected */
    private function ownerAfterImageMatches(array $expected): bool
    {
        $current = $this->readOwner();
        $intent = $this->readOwnerFile($this->paths->ownerIntentFile());
        if (!\is_array($current)
            || !\is_array($intent)
            || !\hash_equals(
                (string)($expected['transaction_id'] ?? ''),
                (string)($intent['transaction_id'] ?? ''),
            )
            || !\hash_equals(
                (string)($expected['config_generation'] ?? ''),
                (string)($intent['config_generation'] ?? ''),
            )
        ) {
            return false;
        }
        return \hash_equals(
            $this->ownerSemanticDigest($intent),
            $this->ownerSemanticDigest($current),
        );
    }

    /** @param array<string,mixed> $owner */
    private function ownerSemanticDigest(array $owner): string
    {
        return \hash('sha256', \json_encode(
            $this->canonicalizeOwner($owner),
            JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ));
    }

    /** @param array<string,mixed> $value @return array<string,mixed> */
    private function canonicalizeOwner(array $value): array
    {
        foreach ($value as $key => $item) {
            if (\is_array($item)) {
                $value[$key] = $this->canonicalizeOwner($item);
            }
        }
        if (!\array_is_list($value)) {
            \ksort($value, SORT_STRING);
        }
        return $value;
    }

    private function managedFileDigestMatches(string $file, string $digest, string $label): bool
    {
        $digest = \strtolower(\trim($digest));
        if (\preg_match('/\A[a-f0-9]{64}\z/D', $digest) !== 1) {
            return false;
        }
        try {
            $actual = $this->stableManagedFileSha256($file, $label);
        } catch (\Throwable) {
            return false;
        }
        return \is_string($actual) && \hash_equals($digest, \strtolower($actual));
    }

    private function reconcileProjectStateWriteAfterImage(
        string $file,
        string $digest,
        string $label,
        \Throwable $original,
    ): void {
        if (!$this->managedFileDigestMatches($file, $digest, $label)) {
            throw $original;
        }
        try {
            GatewayProjectStateFilesystem::syncDirectory(\dirname($file));
        } catch (\Throwable $syncFailure) {
            throw new \RuntimeException(
                $label . ' is exact but its parent-directory durability remains unproven: '
                    . $syncFailure->getMessage(),
                0,
                $original,
            );
        }
        if (!$this->managedFileDigestMatches($file, $digest, $label)) {
            throw new \RuntimeException(
                $label . ' changed while its parent-directory durability was reconciled.',
                0,
                $original,
            );
        }
    }

    private function reconcileProjectStateRemovalAfterImage(
        string $file,
        string $label,
        \Throwable $original,
    ): void {
        if ($this->pathExistsNoFollow($file)) {
            throw $original;
        }
        try {
            GatewayProjectStateFilesystem::syncDirectory(\dirname($file));
        } catch (\Throwable $syncFailure) {
            throw new \RuntimeException(
                $label . ' is absent but its parent-directory durability remains unproven: '
                    . $syncFailure->getMessage(),
                0,
                $original,
            );
        }
        if ($this->pathExistsNoFollow($file)) {
            throw new \RuntimeException(
                $label . ' reappeared while its removal durability was reconciled.',
                0,
                $original,
            );
        }
    }

    private function pathExistsNoFollow(string $path): bool
    {
        \clearstatcache(true, $path);
        return @\lstat($path) !== false;
    }

    /** @param array<string,mixed> $intent */
    private function finalizeRolledBackOwnerIntent(array $intent): void
    {
        $intentFile = $this->paths->ownerIntentFile();
        $persisted = $this->readOwnerFile($intentFile);
        if (!\is_array($persisted)
            || !\hash_equals(
                (string)($intent['transaction_id'] ?? ''),
                (string)($persisted['transaction_id'] ?? ''),
            )
        ) {
            throw new \RuntimeException('Managed nginx rolled-back owner intent changed.');
        }
        try {
            GatewayProjectStateFilesystem::removeRegular(
                $intentFile,
                'Rolled-back managed Nginx owner intent',
            );
        } catch (\Throwable $throwable) {
            $this->reconcileProjectStateRemovalAfterImage(
                $intentFile,
                'Rolled-back managed Nginx owner intent',
                $throwable,
            );
        }
    }

    /** @param array<string,mixed> $expected */
    private function commitOwnerIntent(array $expected): void
    {
        $intent = $this->readOwnerFile($this->paths->ownerIntentFile());
        if (!\is_array($intent)
            || !\hash_equals(
                $this->ownerSemanticDigest($expected),
                $this->ownerSemanticDigest($intent),
            )
            || !\hash_equals((string)$expected['transaction_id'], (string)$intent['transaction_id'])
            || !\hash_equals((string)$expected['instance_name'], (string)$intent['instance_name'])
            || !\hash_equals((string)$expected['config_generation'], (string)$intent['config_generation'])
            || (int)($expected['listen_http'] ?? 0) !== (int)($intent['listen_http'] ?? 0)
            || (int)($expected['listen_https'] ?? 0) !== (int)($intent['listen_https'] ?? 0)
            || (array)($expected['server_names'] ?? []) !== (array)($intent['server_names'] ?? [])
            || (array)($expected['upstream_ports'] ?? []) !== (array)($intent['upstream_ports'] ?? [])
            || !\hash_equals((string)($expected['ssl_certificate_sha256'] ?? ''), (string)($intent['ssl_certificate_sha256'] ?? ''))
            || \preg_match('/\A[a-f0-9]{64}\z/D', (string)($expected['config_sha256'] ?? '')) !== 1
            || !\hash_equals((string)$expected['config_sha256'], (string)$intent['config_sha256'])
            || !\hash_equals(
                (string)$expected['upstream_endpoint_sha256'],
                (string)$intent['upstream_endpoint_sha256'],
            )
            || !$this->http3EvidenceMatches($expected, $intent)
            || !$this->tlsSessionEvidenceMatches($expected, $intent)
        ) {
            throw new \RuntimeException('Managed nginx owner intent changed before commit.');
        }
        $activeConfigSha256 = $this->stableManagedFileSha256(
            $this->paths->confFile(),
            'Managed Nginx active config',
        );
        if (!\is_string($activeConfigSha256)
            || !\hash_equals((string)$intent['config_sha256'], \strtolower($activeConfigSha256))
        ) {
            throw new \RuntimeException('Managed nginx active config digest changed before owner commit.');
        }
        $this->writeOwner($intent);
    }

    /**
     * @param array<string,mixed> $expected
     * @param array<string,mixed> $intent
     */
    private function http3EvidenceMatches(array $expected, array $intent): bool
    {
        $fields = [
            'http3_runtime_verified',
            'http3_verifier_available',
            'http3_status',
            'http3_advertisement_status',
            'http3_protocol',
            'http3_master_pid',
            'http3_config_generation',
            'http3_config_sha256',
            'http3_ssl_certificate_sha256',
            'http3_verified_at',
            'http3_reason',
            'public_protocols_runtime_verified',
        ];
        foreach ($fields as $field) {
            if (!\array_key_exists($field, $expected)
                || !\array_key_exists($field, $intent)
                || $expected[$field] !== $intent[$field]
            ) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string,mixed> $expected
     * @param array<string,mixed> $intent
     */
    private function tlsSessionEvidenceMatches(array $expected, array $intent): bool
    {
        $fields = [
            'tls_session_resumption_runtime_verified',
            'tls_session_resumption_status',
            'tls_session_resumption_proof_model',
            'tls_session_resumption_baseline_result',
            'tls_session_resumption_negative_control_result',
            'tls_session_resumption_tls_protocol',
            'tls_session_resumption_http_protocol',
            'tls_session_resumption_sample_count',
            'tls_session_resumption_completed_count',
            'tls_session_resumption_failed_count',
            'tls_session_resumption_resumed_tls_handshake_p95_us',
            'tls_session_resumption_fresh_count',
            'tls_session_resumption_resumed_count',
            'tls_session_resumption_same_worker_status',
            'tls_session_resumption_same_worker_runtime_verified',
            'tls_session_resumption_same_worker_resumed_count',
            'tls_session_resumption_cross_worker_status',
            'tls_session_resumption_cross_worker_runtime_verified',
            'tls_session_resumption_cross_worker_resumed_count',
            'tls_session_resumption_baseline_worker_pid',
            'tls_session_resumption_observed_worker_count',
            'tls_session_resumption_effective_worker_count',
            'tls_session_resumption_master_pid',
            'tls_session_resumption_config_generation',
            'tls_session_resumption_config_sha256',
            'tls_session_resumption_ssl_certificate_sha256',
            'tls_session_resumption_verified_at',
            'tls_session_resumption_reload_continuity_verified',
            'tls_session_resumption_reload_continuity_status',
            'tls_session_resumption_reload_continuity_proof_model',
            'tls_session_resumption_reload_continuity_result',
            'tls_session_resumption_reload_issuer_worker_pid',
            'tls_session_resumption_reload_probe_worker_pid',
            'tls_session_resumption_reload_master_pid',
            'tls_session_resumption_reload_tls_handshake_us',
            'tls_session_resumption_reload_previous_config_generation',
            'tls_session_resumption_reload_config_generation',
            'tls_session_resumption_reload_verified_at',
        ];
        foreach ($fields as $field) {
            if (!\array_key_exists($field, $expected)
                || !\array_key_exists($field, $intent)
                || $expected[$field] !== $intent[$field]
            ) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string,mixed> $expected */
    private function finalizeOwnerIntent(array $expected): string
    {
        $intentFile = $this->paths->ownerIntentFile();
        $intent = $this->readOwnerFile($intentFile);
        if (!\is_array($intent)
            || !\hash_equals((string)$expected['transaction_id'], (string)$intent['transaction_id'])
            || !\hash_equals((string)$expected['instance_name'], (string)$intent['instance_name'])
            || !\hash_equals((string)$expected['config_generation'], (string)$intent['config_generation'])
            || !\hash_equals((string)$expected['config_sha256'], (string)$intent['config_sha256'])
        ) {
            throw new \RuntimeException('Managed nginx owner intent could not be finalized.');
        }
        if (!$this->ownerAfterImageMatches($expected)
            || !$this->managedFileDigestMatches(
                $this->paths->confFile(),
                (string)($expected['config_sha256'] ?? ''),
                'Managed Nginx finalized active config',
            )
        ) {
            throw new \RuntimeException(
                'Managed nginx owner/config identity changed before intent finalization.',
            );
        }
        $this->cleanupOwnerStateAtomicWriteRecoveryBackups(
            $intentFile,
            'Managed Nginx owner intent',
        );
        try {
            GatewayProjectStateFilesystem::removeRegular(
                $intentFile,
                'Managed Nginx owner intent',
            );
        } catch (\Throwable $throwable) {
            if ($this->pathExistsNoFollow($intentFile)
                || !$this->managedFileDigestMatches(
                    $this->paths->confFile(),
                    (string)($expected['config_sha256'] ?? ''),
                    'Managed Nginx finalized active config after-image',
                )
                || !$this->committedOwnerMatchesWithoutIntent($expected)
            ) {
                throw $throwable;
            }
            try {
                $this->reconcileProjectStateRemovalAfterImage(
                    $intentFile,
                    'Committed managed Nginx owner intent',
                    $throwable,
                );
            } catch (\Throwable $syncFailure) {
                if ($this->pathExistsNoFollow($intentFile)) {
                    throw $syncFailure;
                }
                return 'owner intent unlink committed with deferred directory sync: '
                    . $syncFailure->getMessage();
            }
        }
        return '';
    }

    /** @param array<string,mixed> $expected */
    private function committedOwnerMatchesWithoutIntent(array $expected): bool
    {
        $current = $this->readOwner();
        if (!\is_array($current)) {
            return false;
        }
        return \hash_equals(
            $this->ownerSemanticDigest($expected),
            $this->ownerSemanticDigest($current),
        );
    }

    private function recoverOwnerPublication(): void
    {
        $intentFile = $this->paths->ownerIntentFile();
        if (!$this->pathExistsNoFollow($intentFile)) {
            return;
        }
        $rawIntent = \json_decode(GatewayProjectStateFilesystem::read(
            $intentFile,
            4 * 1024 * 1024,
            'Managed Nginx owner intent',
        ), true);
        $intent = $this->readOwnerFile($intentFile);
        if (!\is_array($rawIntent) || !\is_array($intent)) {
            throw new \RuntimeException('Managed nginx owner intent is unreadable or invalid.');
        }
        $strictTransaction = \array_key_exists('config_rollback_expected', $rawIntent)
            && \array_key_exists('previous_config_sha256', $rawIntent)
            && \array_key_exists('owner_rollback_expected', $rawIntent)
            && \array_key_exists('previous_owner_sha256', $rawIntent);
        if (!$strictTransaction) {
            $this->recoverLegacyOwnerPublication($intent);
            return;
        }

        $this->recoverStrictOwnerPublication($intent);
    }

    /** @param array<string,mixed> $intent */
    private function recoverStrictOwnerPublication(array $intent): void
    {
        $transactionId = (string)($intent['transaction_id'] ?? '');
        if (\preg_match('/\A[a-f0-9]{32}\z/D', $transactionId) !== 1) {
            throw new \RuntimeException('Managed nginx recovery transaction identity is invalid.');
        }
        $configRollbackExpected = (bool)($intent['config_rollback_expected'] ?? false);
        $ownerRollbackExpected = (bool)($intent['owner_rollback_expected'] ?? false);
        $previousConfigSha256 = (string)($intent['previous_config_sha256'] ?? '');
        $previousOwnerSha256 = (string)($intent['previous_owner_sha256'] ?? '');
        if (($configRollbackExpected
                && \preg_match('/\A[a-f0-9]{64}\z/D', $previousConfigSha256) !== 1)
            || (!$configRollbackExpected && $previousConfigSha256 !== '')
            || ($ownerRollbackExpected
                && \preg_match('/\A[a-f0-9]{64}\z/D', $previousOwnerSha256) !== 1)
            || (!$ownerRollbackExpected && $previousOwnerSha256 !== '')
        ) {
            throw new \RuntimeException(
                'Managed nginx recovery before-image metadata is inconsistent.',
            );
        }

        $configRollback = $this->configWriter->rollbackPathForTransaction($transactionId);
        $ownerRollback = $this->ownerRollbackPath($transactionId);
        $configRollbackExists = $this->pathExistsNoFollow($configRollback);
        $ownerRollbackExists = $this->pathExistsNoFollow($ownerRollback);
        if ($configRollbackExists) {
            if (!$configRollbackExpected
                || !$this->managedFileDigestMatches(
                    $configRollback,
                    $previousConfigSha256,
                    'Managed Nginx recovery config rollback',
                )
            ) {
                throw new \RuntimeException(
                    'Managed nginx recovery config rollback is unexpected or changed.',
                );
            }
        }
        if ($ownerRollbackExists) {
            if (!$ownerRollbackExpected) {
                throw new \RuntimeException(
                    'Managed nginx recovery found unexpected owner rollback evidence.',
                );
            }
            $this->assertOwnerRollbackMatchesIntent($intent, $ownerRollback);
        }

        $activeAfter = $this->managedFileDigestMatches(
            $this->paths->confFile(),
            (string)($intent['config_sha256'] ?? ''),
            'Managed Nginx recovery active after-image',
        );
        $activeBefore = $configRollbackExpected
            ? $this->managedFileDigestMatches(
                $this->paths->confFile(),
                $previousConfigSha256,
                'Managed Nginx recovery active before-image',
            )
            : !$this->pathExistsNoFollow($this->paths->confFile());
        $ownerAfter = $this->ownerAfterImageMatches($intent);
        $ownerBefore = $this->ownerBeforeImageMatches($intent);

        // If the rollback target remains, commitPublished() did not reach the
        // point of no return. Resolve both resources to their exact before-images.
        if ($configRollbackExists) {
            if (!$ownerBefore && !$ownerAfter) {
                $this->stopInterruptedPublicationFailClosed(
                    'owner identity is neither the exact before-image nor after-image',
                );
            }
            if ($ownerAfter && $ownerRollbackExpected && !$ownerRollbackExists) {
                $this->stopInterruptedPublicationFailClosed(
                    'owner after-image has no exact owner rollback before-image',
                );
            }
            $status = $this->safeRecoveryProcessStatus();
            $this->restorePublishedConfig(
                $configRollback,
                (bool)$status['running'],
                false,
                $intent,
            );
            return;
        }

        // rollback absent + exact active/LKG/owner after-image is the committed
        // point of no return. Only bookkeeping remains.
        if ($activeAfter && $ownerAfter) {
            if ($configRollbackExpected
                && !$this->managedFileDigestMatches(
                    $this->paths->confFile() . '.last-good',
                    $previousConfigSha256,
                    'Managed Nginx recovery last-known-good before-image',
                )
            ) {
                $this->stopInterruptedPublicationFailClosed(
                    'config rollback disappeared without an exact last-known-good after-image',
                );
            }
            $this->configWriter->cleanupResolvedRollbackTemporaries($configRollback);
            $this->finalizeCommittedOwnerState($intent);
            return;
        }

        // A crash before config publication (or after an already-completed
        // paired rollback) leaves both exact before-images and no config
        // rollback. Finish only the owner/intent cleanup.
        if ($activeBefore && $ownerBefore) {
            $status = $this->safeRecoveryProcessStatus();
            $this->proveRecoveryBeforeImageLive($status);
            $this->restoreOwnerBeforeImage($intent);
            $this->configWriter->cleanupResolvedRollbackTemporaries($configRollback);
            $this->finalizeRolledBackOwnerIntent($intent);
            return;
        }

        // A first publication has no config rollback. If its owner was never
        // committed, remove the uncommitted active config only after stopping
        // the exact managed process.
        if (!$configRollbackExpected
            && $ownerBefore
            && $this->pathExistsNoFollow($this->paths->confFile())
        ) {
            $status = $this->safeRecoveryProcessStatus();
            if ((bool)$status['running']) {
                $this->stopManagedNginxFailClosed(
                    'unable to stop an interrupted first managed nginx publication',
                );
            }
            $this->configWriter->rollbackPublished(null);
            $this->restoreOwnerBeforeImage($intent);
            $this->finalizeRolledBackOwnerIntent($intent);
            return;
        }

        // Config is already the before-image but owner commit crossed its
        // rename. The exact owner rollback is sufficient to restore the pair.
        if ($activeBefore && $ownerAfter && $ownerRollbackExists) {
            $status = $this->safeRecoveryProcessStatus();
            $this->restoreOwnerBeforeImage($intent);
            $this->proveRecoveryBeforeImageLive($status);
            $this->configWriter->cleanupResolvedRollbackTemporaries($configRollback);
            $this->finalizeRolledBackOwnerIntent($intent);
            return;
        }

        $this->stopInterruptedPublicationFailClosed(
            'neither the exact transaction before-image nor after-image can be proven',
        );
    }

    /** @return array<string,mixed> */
    private function safeRecoveryProcessStatus(): array
    {
        $status = $this->processManager->status(
            $this->activeLifecycleDeadlineMonotonic,
        );
        if (!($status['ok'] ?? false)) {
            throw new \RuntimeException(
                'Cannot recover owner intent while nginx PID identity is unsafe.',
            );
        }
        return $status;
    }

    /** @param array<string,mixed> $status */
    private function proveRecoveryBeforeImageLive(array $status): void
    {
        if (!(bool)($status['running'] ?? false)) {
            return;
        }
        $owner = $this->readOwner();
        if (!\is_array($owner)) {
            $this->stopManagedNginxFailClosed(
                'interrupted first publication left a process without an owner before-image',
            );
            return;
        }
        if ($this->committedOwnerGenerationIsLive($owner)) {
            return;
        }
        $reloaded = $this->processManager->reload(
            $this->activeLifecycleDeadlineMonotonic,
        );
        if (!($reloaded['ok'] ?? false) || !$this->committedOwnerGenerationIsLive($owner)) {
            $this->stopManagedNginxFailClosed(
                'unable to prove the exact owner/config before-image live during recovery',
            );
        }
    }

    private function stopInterruptedPublicationFailClosed(string $reason): never
    {
        try {
            $status = $this->safeRecoveryProcessStatus();
            if ((bool)($status['running'] ?? false)) {
                $this->stopManagedNginxFailClosed($reason);
            }
        } catch (\Throwable $throwable) {
            throw new \RuntimeException(
                'Managed nginx interrupted publication is ambiguous: ' . $reason
                    . '; fail-closed stop was not proven: ' . $throwable->getMessage(),
                0,
                $throwable,
            );
        }
        throw new \RuntimeException(
            'Managed nginx interrupted publication is ambiguous: ' . $reason
                . '; managed nginx stopped fail-closed and recovery evidence was retained.',
        );
    }

    /**
     * Compatibility recovery for owner intents written before the paired
     * before-image hashes were introduced.
     *
     * @param array<string,mixed> $intent
     */
    private function recoverLegacyOwnerPublication(array $intent): void
    {
        $ownerFile = $this->paths->ownerFile();
        $intentFile = $this->paths->ownerIntentFile();
        $transactionId = (string)($intent['transaction_id'] ?? '');
        $rollbackExpected = (bool)($intent['config_rollback_expected'] ?? false);
        if (!\is_file($ownerFile) && $transactionId !== '') {
            $ownerRollback = $ownerFile . '.rollback.' . $transactionId;
            if (\file_exists($ownerRollback) || \is_link($ownerRollback)) {
                $rollbackContents = GatewayProjectStateFilesystem::read(
                    $ownerRollback,
                    4 * 1024 * 1024,
                    'Legacy managed Nginx owner rollback',
                );
                GatewayProjectStateFilesystem::atomicWrite($ownerFile, $rollbackContents, 0600);
                GatewayProjectStateFilesystem::removeRegular(
                    $ownerRollback,
                    'Legacy managed Nginx owner rollback',
                );
            }
        }
        $committedOwner = $this->readOwner();
        $activeConfigSha256 = $this->stableManagedFileSha256(
            $this->paths->confFile(),
            'Managed Nginx active config',
        );
        $intentConfigSha256 = \strtolower(\trim((string)($intent['config_sha256'] ?? '')));
        $committedOwnerConfigSha256 = \is_array($committedOwner)
            ? \strtolower(\trim((string)($committedOwner['config_sha256'] ?? '')))
            : '';
        $committedOwnerIdentityMatches = \is_array($committedOwner)
            && $transactionId !== ''
            && \hash_equals($transactionId, (string)($committedOwner['transaction_id'] ?? ''))
            && \hash_equals((string)$intent['instance_name'], (string)$committedOwner['instance_name'])
            && \hash_equals(
                (string)$intent['config_generation'],
                (string)$committedOwner['config_generation'],
            )
            && $this->http3EvidenceMatches($intent, $committedOwner)
            && $this->tlsSessionEvidenceMatches($intent, $committedOwner);
        $committedOwnerConfigMatchesActive = \is_array($committedOwner)
            && \preg_match('/\A[a-f0-9]{64}\z/D', $committedOwnerConfigSha256) === 1
            && \is_string($activeConfigSha256)
            && \hash_equals($committedOwnerConfigSha256, \strtolower($activeConfigSha256));
        if ($committedOwnerIdentityMatches
            && (\preg_match('/\A[a-f0-9]{64}\z/D', $intentConfigSha256) !== 1
                || !\hash_equals($intentConfigSha256, $committedOwnerConfigSha256)
                || !\is_string($activeConfigSha256)
                || !\hash_equals($intentConfigSha256, \strtolower($activeConfigSha256)))
        ) {
            throw new \RuntimeException(
                'Committed managed nginx owner no longer matches the active config digest; preserving rollback evidence.',
            );
        }
        if ($committedOwnerIdentityMatches) {
            $configRollback = $this->configWriter->rollbackPathForTransaction($transactionId);
            $rollbackExists = \file_exists($configRollback) || \is_link($configRollback);
            if ($rollbackExpected && $rollbackExists) {
                if (!$this->configWriter->commitPublished($configRollback)) {
                    throw new \RuntimeException('Unable to finish committed owner/config bookkeeping.');
                }
            } elseif (!$rollbackExpected && $rollbackExists) {
                throw new \RuntimeException(
                    'Committed first managed nginx publication has unexpected rollback evidence.',
                );
            } else {
                $this->configWriter->cleanupResolvedRollbackTemporaries($configRollback);
            }
            GatewayProjectStateFilesystem::removeRegular(
                $intentFile,
                'Committed managed Nginx owner intent',
            );
            return;
        }

        $status = $this->safeRecoveryProcessStatus();
        $configRollback = $transactionId !== ''
            ? $this->configWriter->rollbackPathForTransaction($transactionId)
            : null;
        try {
            $config = GatewayProjectStateFilesystem::read(
                $this->paths->confFile(),
                16 * 1024 * 1024,
                'Managed Nginx active config',
            );
        } catch (\Throwable) {
            $config = null;
        }
        $generation = (string)$intent['config_generation'];
        $uncommittedConfigPublished = \is_string($config)
            && \preg_match(
                '/add_header X-Wls-Nginx-Config ' . \preg_quote($generation, '/') . ' always;/',
                $config,
            ) === 1;
        if (\is_string($configRollback) && \is_file($configRollback)) {
            $this->configWriter->rollbackPublished($configRollback);
            if ($status['running']) {
                $reloaded = $this->processManager->reload(
                    $this->activeLifecycleDeadlineMonotonic,
                );
                if (!$reloaded['ok']
                    || !\is_array($committedOwner)
                    || !$this->committedOwnerGenerationIsLive($committedOwner)
                ) {
                    $this->stopManagedNginxFailClosed(
                        'unable to prove the committed generation after interrupted rollback',
                    );
                }
            }
        } elseif ($rollbackExpected) {
            if ($uncommittedConfigPublished
                || !\is_file($this->paths->confFile())
                || !\is_array($committedOwner)
                || !$committedOwnerConfigMatchesActive
            ) {
                throw new \RuntimeException(
                    'Managed nginx transaction expected a rollback file, but the committed config cannot be proven.',
                );
            }
            if ($status['running']) {
                $committedGenerationLive = $this->committedOwnerGenerationIsLive($committedOwner);
                if (!$committedGenerationLive) {
                    $reloaded = $this->processManager->reload(
                        $this->activeLifecycleDeadlineMonotonic,
                    );
                    $committedGenerationLive = $reloaded['ok']
                        && $this->committedOwnerGenerationIsLive($committedOwner);
                }
                if (!$committedGenerationLive) {
                    $this->stopManagedNginxFailClosed(
                        'rollback evidence is missing and the committed live generation cannot be proven',
                    );
                }
            }
            if (!\is_string($configRollback)) {
                throw new \RuntimeException('Managed nginx rollback transaction identity is missing.');
            }
            $this->configWriter->cleanupResolvedRollbackTemporaries($configRollback);
            if (!GatewayProjectStateFilesystem::removeRegular(
                $intentFile,
                'Recovered managed Nginx owner intent',
            )) {
                throw new \RuntimeException('Unable to clear recovered managed nginx owner intent.');
            }
            return;
        } elseif ($uncommittedConfigPublished || !\is_file($this->paths->confFile())) {
            if ($status['running']) {
                $this->stopManagedNginxFailClosed(
                    'unable to stop an interrupted first managed nginx publication',
                );
            }
            $this->configWriter->rollbackPublished(null);
        } elseif ($status['running']) {
            $this->stopManagedNginxFailClosed(
                'uncommitted managed nginx intent has no trustworthy rollback identity',
            );
        }
        if (!GatewayProjectStateFilesystem::removeRegular(
            $intentFile,
            'Recovered managed Nginx owner intent',
        )) {
            throw new \RuntimeException('Unable to clear recovered managed nginx owner intent.');
        }
    }

    private function clearOwner(): void
    {
        $file = $this->paths->ownerFile();
        if ((\file_exists($file) || \is_link($file))
            && !GatewayProjectStateFilesystem::removeRegular(
                $file,
                'Managed Nginx owner state',
            )
        ) {
            throw new \RuntimeException('Unable to clear managed nginx owner state.');
        }
        $intent = $this->paths->ownerIntentFile();
        if ((\file_exists($intent) || \is_link($intent))
            && !GatewayProjectStateFilesystem::removeRegular(
                $intent,
                'Managed Nginx owner intent',
            )
        ) {
            throw new \RuntimeException('Unable to clear managed nginx owner intent.');
        }
    }

    /**
     * Return the remaining portion of the one active lifecycle deadline.
     * Probe helpers must never derive a fresh now+N budget while a lifecycle
     * transaction is in progress.
     */
    private function remainingLifecycleDeadline(
        float $maximumSeconds,
    ): ?float {
        if (!\is_finite($maximumSeconds) || $maximumSeconds <= 0.0) {
            throw new \InvalidArgumentException(
                'Managed Nginx lifecycle timeout is invalid.',
            );
        }
        $deadline = $this->activeLifecycleDeadlineMonotonic;
        if ($deadline === null) {
            return $maximumSeconds;
        }
        if (!\is_finite($deadline)) {
            return null;
        }
        $remaining = $deadline - (\hrtime(true) / 1_000_000_000);
        if ($remaining <= 0.0) {
            return null;
        }
        return \min($maximumSeconds, $remaining);
    }

    private function remainingLifecycleMilliseconds(
        int $maximumMilliseconds,
    ): ?int {
        if ($maximumMilliseconds < 1) {
            throw new \InvalidArgumentException(
                'Managed Nginx lifecycle millisecond timeout is invalid.',
            );
        }
        $remaining = $this->remainingLifecycleDeadline(
            $maximumMilliseconds / 1_000,
        );
        if ($remaining === null) {
            return null;
        }
        return (int)\max(1, \floor($remaining * 1_000));
    }

    private function lifecycleDeadlineAvailable(): bool
    {
        return $this->remainingLifecycleDeadline(1.0) !== null;
    }

    /** @param resource $socket */
    private function setSocketTimeoutWithinLifecycleDeadline(
        mixed $socket,
        float $maximumSeconds,
    ): bool {
        $timeout = $this->remainingLifecycleDeadline($maximumSeconds);
        if ($timeout === null) {
            return false;
        }
        $seconds = (int)\floor($timeout);
        $microseconds = (int)\ceil(($timeout - $seconds) * 1_000_000);
        if ($microseconds >= 1_000_000) {
            $seconds++;
            $microseconds = 0;
        } elseif ($seconds === 0 && $microseconds < 1) {
            $microseconds = 1;
        }
        return @\stream_set_timeout($socket, $seconds, $microseconds);
    }

    private function sleepWithinLifecycleDeadline(float $seconds): bool
    {
        $delay = $this->remainingLifecycleDeadline($seconds);
        if ($delay === null) {
            return false;
        }
        SchedulerSystem::usleep((int)\max(1, \ceil($delay * 1_000_000)));
        return $this->lifecycleDeadlineAvailable();
    }

    /**
     * @param callable():array<string,mixed> $operation
     * @return array<string,mixed>
     */
    private function withLifecycleLock(
        callable $operation,
        ?float $deadlineMonotonic = null,
    ): array
    {
        try {
            $this->paths->ensureRuntimeDirectories();
            $monotonicNow = \hrtime(true) / 1_000_000_000;
            if (!\is_finite($monotonicNow) || $monotonicNow <= 0.0) {
                return [
                    'ok' => false,
                    'message' => 'managed nginx lifecycle monotonic clock is unavailable',
                ];
            }
            $deadlineMonotonic ??= $monotonicNow
                + self::LIFECYCLE_LOCK_TIMEOUT_SECONDS;
            if (!\is_finite($deadlineMonotonic)) {
                return [
                    'ok' => false,
                    'message' => 'managed nginx lifecycle deadline is invalid',
                ];
            }
            $remaining = $deadlineMonotonic - $monotonicNow;
            if ($remaining <= 0.0) {
                return [
                    'ok' => false,
                    'message' => 'managed nginx lifecycle deadline was exhausted',
                ];
            }
            $waitTimeoutSeconds = \min(
                self::LIFECYCLE_LOCK_TIMEOUT_SECONDS,
                $remaining,
            );

            return GatewayProjectStateFilesystem::withExclusiveLock(
                $this->paths->lifecycleLockFile(),
                function () use ($operation, $deadlineMonotonic): array {
                    // Safe lock opening is part of the acquisition path. Keep
                    // the caller's absolute monotonic deadline authoritative
                    // before any lifecycle recovery or mutation may begin.
                    if ($deadlineMonotonic !== null
                        && (\hrtime(true) / 1_000_000_000) >= $deadlineMonotonic
                    ) {
                        return [
                            'ok' => false,
                            'message' => 'managed nginx lifecycle deadline was exhausted',
                        ];
                    }
                    $previousDeadline = $this->activeLifecycleDeadlineMonotonic;
                    $this->activeLifecycleDeadlineMonotonic = $deadlineMonotonic;
                    try {
                        $this->cleanupOwnerAtomicWriteRecoveryBackups();
                        $this->cleanupConfigAtomicWriteRecoveryBackups();
                        $this->recoverOwnerPublication();
                        $this->configWriter->recoverInterruptedPublication();
                        if ((\hrtime(true) / 1_000_000_000) >= $deadlineMonotonic) {
                            return [
                                'ok' => false,
                                'message' => 'managed nginx lifecycle deadline was exhausted',
                            ];
                        }
                        return $operation();
                    } finally {
                        $this->activeLifecycleDeadlineMonotonic = $previousDeadline;
                    }
                },
                null,
                $waitTimeoutSeconds,
                $deadlineMonotonic,
            );
        } catch (\Throwable $exception) {
            $message = $exception->getMessage();
            if ($message === 'Timed out acquiring the WLS state lock.') {
                $message = 'managed nginx lifecycle lock timed out';
            }
            return ['ok' => false, 'message' => $message];
        }
    }

    /**
     * owner.json and owner.intent.json have one writer namespace: this
     * service while managed-nginx.lifecycle.lock is held.
     */
    private function cleanupOwnerAtomicWriteRecoveryBackups(): void
    {
        $targets = [
            [
                'file' => $this->paths->ownerFile(),
                'label' => 'Managed Nginx owner state',
            ],
            [
                'file' => $this->paths->ownerIntentFile(),
                'label' => 'Managed Nginx owner intent',
            ],
        ];
        $temporaryClosure = $this->ownerAtomicWriteRecoveryTemporaries($targets);
        $retained = [];
        foreach ($targets as $target) {
            $hasBackups = GatewayProjectStateFilesystem::hasAtomicWriteRecoveryBackups(
                $target['file'],
                4 * 1024 * 1024,
                $target['label'],
            );
            $temporaries = $temporaryClosure['temporaries'][$target['file']] ?? [];
            if ($hasBackups || $temporaries !== []) {
                $retained[] = [
                    ...$target,
                    'has_backups' => $hasBackups,
                    'temporaries' => $temporaries,
                ];
            }
        }

        foreach ($retained as $index => $target) {
            $retained[$index]['digest'] = $this
                ->validateOwnerStateAtomicRecoveryTarget(
                    $target['file'],
                    $target['label'],
                );
        }
        foreach ($retained as $target) {
            foreach ($target['temporaries'] as $temporary) {
                $current = @\lstat($temporary['path']);
                if (!\is_array($current)
                    || !$this->sameOwnerArtifactState($temporary['identity'], $current)
                ) {
                    throw new \RuntimeException(
                        $target['label'] . ' atomic temporary changed before cleanup.',
                    );
                }
            }
        }
        $directoryCurrent = @\lstat($temporaryClosure['directory']);
        if (!\is_array($directoryCurrent)
            || !$this->sameOwnerArtifactState(
                $temporaryClosure['directory_identity'],
                $directoryCurrent,
            )
        ) {
            throw new \RuntimeException(
                'Managed Nginx owner atomic temporary directory changed before cleanup.',
            );
        }

        foreach ($retained as $target) {
            if ($target['has_backups']) {
                $this->cleanupOwnerStateAtomicWriteRecoveryBackups(
                    $target['file'],
                    $target['label'],
                    $target['digest'],
                );
            }
            foreach ($target['temporaries'] as $temporary) {
                $currentDigest = $this->validateOwnerStateAtomicRecoveryTarget(
                    $target['file'],
                    $target['label'],
                );
                if (!\hash_equals($target['digest'], $currentDigest)) {
                    throw new \RuntimeException(
                        $target['label'] . ' recovery target changed before temporary cleanup.',
                    );
                }
                if (!GatewayProjectStateFilesystem::removeRegular(
                    $temporary['path'],
                    $target['label'] . ' atomic temporary',
                    $temporary['identity'],
                )) {
                    throw new \RuntimeException(
                        'Unable to collect ' . $target['label'] . ' atomic temporary.',
                    );
                }
            }
        }
    }

    /**
     * @param list<array{file:string,label:string}> $targets
     * @return array{
     *   directory:string,
     *   directory_identity:array<string|int,mixed>,
     *   temporaries:array<string,list<array{path:string,identity:array<string|int,mixed>}>>
     * }
     */
    private function ownerAtomicWriteRecoveryTemporaries(array $targets): array
    {
        $directory = \dirname($this->paths->ownerFile());
        $directoryBefore = @\lstat($directory);
        if (!\is_array($directoryBefore)
            || \is_link($directory)
            || ((((int)$directoryBefore['mode']) & 0170000) !== 0040000)
        ) {
            throw new \RuntimeException(
                'Managed Nginx owner atomic temporary directory is unsafe.',
            );
        }
        foreach ($targets as $target) {
            if (!\hash_equals($directory, \dirname($target['file']))) {
                throw new \RuntimeException(
                    'Managed Nginx owner recovery target escaped its runtime directory.',
                );
            }
        }

        $handle = @\opendir($directory);
        if (!\is_resource($handle)) {
            throw new \RuntimeException(
                'Unable to enumerate managed Nginx owner atomic temporaries.',
            );
        }
        $temporaries = [];
        $rawEntries = 0;
        $total = 0;
        try {
            while (($leaf = \readdir($handle)) !== false) {
                if ($leaf === '.' || $leaf === '..') {
                    continue;
                }
                if (($rawEntries & 63) === 0
                    && !$this->lifecycleDeadlineAvailable()
                ) {
                    throw new \RuntimeException(
                        'Managed Nginx lifecycle deadline was exhausted during owner recovery enumeration.',
                    );
                }
                if (++$rawEntries > self::MAX_OWNER_RECOVERY_DIRECTORY_ENTRIES) {
                    throw new \RuntimeException(
                        'Managed Nginx owner atomic temporary directory quota is exhausted.',
                    );
                }
                foreach ($targets as $target) {
                    $prefix = \basename(\str_replace('\\', '/', $target['file']))
                        . '.tmp-';
                    $reserved = \str_starts_with($leaf, $prefix)
                        || (\PHP_OS_FAMILY === 'Windows'
                            && \strncasecmp($leaf, $prefix, \strlen($prefix)) === 0);
                    if (!$reserved) {
                        continue;
                    }
                    $suffix = \substr($leaf, \strlen($prefix));
                    if (!\str_starts_with($leaf, $prefix)
                        || \preg_match('/\A[a-f0-9]{24}\z/D', $suffix) !== 1
                    ) {
                        throw new \RuntimeException(
                            $target['label'] . ' atomic temporary reserved leaf is malformed.',
                        );
                    }
                    if (\count($temporaries[$target['file']] ?? [])
                            >= self::MAX_OWNER_ATOMIC_TEMPORARIES_PER_TARGET
                        || ++$total > self::MAX_OWNER_ATOMIC_TEMPORARIES_PER_DIRECTORY
                    ) {
                        throw new \RuntimeException(
                            $target['label'] . ' atomic temporary quota is exhausted.',
                        );
                    }
                    $path = $directory . DIRECTORY_SEPARATOR . $leaf;
                    if (!$this->lifecycleDeadlineAvailable()) {
                        throw new \RuntimeException(
                            'Managed Nginx lifecycle deadline was exhausted before owner recovery read.',
                        );
                    }
                    GatewayProjectStateFilesystem::read(
                        $path,
                        4 * 1024 * 1024,
                        $target['label'] . ' atomic temporary',
                        true,
                    );
                    $identity = @\lstat($path);
                    if (!\is_array($identity)) {
                        throw new \RuntimeException(
                            $target['label'] . ' atomic temporary disappeared during discovery.',
                        );
                    }
                    $temporaries[$target['file']][] = [
                        'path' => $path,
                        'identity' => $identity,
                    ];
                    continue 2;
                }
            }
        } finally {
            @\closedir($handle);
        }
        $directoryAfter = @\lstat($directory);
        if (!\is_array($directoryAfter)
            || !$this->sameOwnerArtifactState($directoryBefore, $directoryAfter)
        ) {
            throw new \RuntimeException(
                'Managed Nginx owner atomic temporary directory changed during discovery.',
            );
        }
        return [
            'directory' => $directory,
            'directory_identity' => $directoryAfter,
            'temporaries' => $temporaries,
        ];
    }

    /**
     * @param array<string|int,mixed> $before
     * @param array<string|int,mixed> $after
     */
    private function sameOwnerArtifactState(array $before, array $after): bool
    {
        foreach (['dev', 'ino', 'mode', 'nlink', 'size', 'mtime', 'ctime'] as $field) {
            if (!\array_key_exists($field, $before)
                || !\array_key_exists($field, $after)
                || (int)$before[$field] !== (int)$after[$field]
            ) {
                return false;
            }
        }
        return true;
    }

    private function cleanupOwnerStateAtomicWriteRecoveryBackups(
        string $file,
        string $label,
        ?string $expectedDigest = null,
    ): void {
        $expectedDigest ??= $this->validateOwnerStateAtomicRecoveryTarget(
            $file,
            $label,
        );
        GatewayProjectStateFilesystem::cleanupAtomicWriteRecoveryBackups(
            $file,
            4 * 1024 * 1024,
            $label,
            function (string $contents) use ($file, $label, $expectedDigest): void {
                $stableDigest = $this->validateOwnerStateAtomicRecoveryTarget(
                    $file,
                    $label,
                );
                if (!\hash_equals($expectedDigest, $stableDigest)
                    || !\hash_equals(
                        $expectedDigest,
                        \hash('sha256', $contents),
                    )
                ) {
                    throw new \RuntimeException(
                        $label . ' recovery target changed after complete validation.',
                    );
                }
            },
        );
    }

    private function validateOwnerStateAtomicRecoveryTarget(
        string $file,
        string $label,
    ): string {
        $contents = GatewayProjectStateFilesystem::read(
            $file,
            4 * 1024 * 1024,
            $label . ' recovery backup paired target',
        );
        $decoded = \json_decode(
            $contents,
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        if (!\is_array($decoded) || !\is_array($this->readOwnerFile($file))) {
            throw new \RuntimeException($label . ' recovery target is invalid.');
        }
        return \hash('sha256', $contents);
    }

    /**
     * Config publication also shares managed-nginx.lifecycle.lock. A retained
     * backup is collected only after the current paired config passes the
     * real isolated nginx -t path; missing or invalid targets keep evidence.
     */
    private function cleanupConfigAtomicWriteRecoveryBackups(): void
    {
        $this->configWriter->cleanupAtomicWriteRecoveryBackups(
            function (string $path, string $contents, string $kind): void {
                if ($contents === '') {
                    throw new \RuntimeException(
                        'Managed Nginx ' . $kind . ' recovery target is empty.',
                    );
                }
                $test = $this->processManager->testConfig(
                    $path,
                    $this->activeLifecycleDeadlineMonotonic,
                );
                if ((int)($test['code'] ?? 1) !== 0) {
                    throw new \RuntimeException(
                        'Managed Nginx ' . $kind
                            . ' recovery target failed nginx -t: '
                            . \substr(\trim((string)($test['output'] ?? '')), 0, 1024),
                    );
                }
            },
        );
    }

    /**
     * @return array<string,mixed>
     */
    public function retirementSnapshot(float $deadlineMonotonic): array
    {
        // Windows process identity may consume a ten-second child timeout plus
        // the bounded runner's documented twelve-second Job cleanup tail.
        $this->assertRetirementDeadline($deadlineMonotonic, 23.0);
        $status = $this->processManager->status($deadlineMonotonic);
        $owner = $this->readOwner();
        $activeConfigSha256 = $this->stableManagedFileSha256(
            $this->paths->confFile(),
            'Managed Nginx active config',
        );
        $ownerConfigBound = \is_array($owner)
            && \is_string($activeConfigSha256)
            && \preg_match(
                '/\A[a-f0-9]{64}\z/D',
                (string)($owner['config_sha256'] ?? ''),
            ) === 1
            && \hash_equals(
                (string)$owner['config_sha256'],
                \strtolower($activeConfigSha256),
            );
        $ownerListenHttp = (int)($owner['listen_http'] ?? 0);
        $ownerListenHttps = (int)($owner['listen_https'] ?? 0);
        $ownerPortsBound = $ownerListenHttp > 0 && $ownerListenHttp <= 65535
            && $ownerListenHttps > 0 && $ownerListenHttps <= 65535;
        $runtimeOwnerActive = ($status['ok'] ?? false) === true
            && ($status['running'] ?? false) === true
            && \is_array($owner)
            && $ownerPortsBound
            && $ownerConfigBound;
        $this->assertRetirementDeadline($deadlineMonotonic, 0.01);
        return [
            'running' => (bool)($status['running'] ?? false),
            'runtime_owner_active' => $runtimeOwnerActive,
            'pid' => (int)($status['pid'] ?? 0),
            'listen_https' => $ownerListenHttps,
            'owner_listen_https' => $ownerListenHttps,
            'owner_instance' => (string)($owner['instance_name'] ?? ''),
            'owner_config_sha256' => (string)($owner['config_sha256'] ?? ''),
            'owner_ssl_certificate_sha256' => (string)(
                $owner['ssl_certificate_sha256'] ?? ''
            ),
        ];
    }

    private function assertRetirementDeadline(
        float $deadlineMonotonic,
        float $minimumSeconds,
    ): void
    {
        if (!\is_finite($deadlineMonotonic)
            || !\is_finite($minimumSeconds)
            || $minimumSeconds <= 0.0
            || $deadlineMonotonic - (\hrtime(true) / 1_000_000_000)
                < $minimumSeconds
        ) {
            throw new \RuntimeException(
                'Managed Nginx retirement snapshot deadline was exhausted.',
            );
        }
    }

    /**
     * @return array<string,mixed>
     */
    public function doctorSnapshot(?float $deadlineMonotonic = null): array
    {
        $ports = $this->portAllocator->allocate();
        $status = $this->processManager->status($deadlineMonotonic);
        $hostBinary = $this->paths->detectHostNginxBinary();
        $installation = $this->installer->installationStatus();
        $binaryCapabilities = $installation['installed']
            ? $this->processManager->probeBinaryCapabilities()
            : [
                'ok' => false,
                'version' => '',
                'http2_module' => false,
                'http3_module' => false,
                'http3_configure_flag' => false,
                'http3_reason' => 'managed nginx binary missing',
                'quic_tls_recommended' => false,
                'shared_session_ticket_keys' => false,
                'http_ssl_module' => false,
                'rewrite_module' => false,
                'gzip_module' => false,
                'openssl_version' => '',
                'tls13_capable' => false,
                'message' => 'managed nginx binary missing',
            ];
        $owner = $this->readOwner();
        $ownerListenHttp = (int)($owner['listen_http'] ?? 0);
        $ownerListenHttps = (int)($owner['listen_https'] ?? 0);
        $ownerPortsBound = $ownerListenHttp > 0 && $ownerListenHttp <= 65535
            && $ownerListenHttps > 0 && $ownerListenHttps <= 65535;
        $activeConfigSha256 = $this->stableManagedFileSha256(
            $this->paths->confFile(),
            'Managed Nginx active config',
        );
        $ownerConfigBound = \is_array($owner)
            && \is_string($activeConfigSha256)
            && \preg_match('/\A[a-f0-9]{64}\z/D', (string)($owner['config_sha256'] ?? '')) === 1
            && \hash_equals((string)$owner['config_sha256'], \strtolower($activeConfigSha256));
        $runtimeOwnerActive = (bool)($status['ok'] ?? false)
            && (bool)($status['running'] ?? false)
            && \is_array($owner)
            && $ownerPortsBound
            && $ownerConfigBound;
        $http3EvidenceBound = \is_array($owner)
            && (bool)($owner['http3_runtime_verified'] ?? false)
            && \hash_equals(
                (string)($owner['config_generation'] ?? ''),
                (string)($owner['http3_config_generation'] ?? ''),
            )
            && \hash_equals(
                (string)($owner['config_sha256'] ?? ''),
                (string)($owner['http3_config_sha256'] ?? ''),
            )
            && \hash_equals(
                (string)($owner['ssl_certificate_sha256'] ?? ''),
                (string)($owner['http3_ssl_certificate_sha256'] ?? ''),
            );
        $http3MasterPidMatches = $runtimeOwnerActive
            && (int)($status['pid'] ?? 0) > 0
            && (int)($status['pid'] ?? 0) === (int)($owner['http3_master_pid'] ?? 0);
        $http3RuntimeVerified = $runtimeOwnerActive
            && $http3EvidenceBound
            && $http3MasterPidMatches;
        $sessionEvidenceBound = \is_array($owner)
            && (bool)($owner['tls_session_resumption_runtime_verified'] ?? false)
            && \hash_equals(
                ManagedNginxTlsSessionResumptionVerifier::PROOF_MODEL,
                (string)($owner['tls_session_resumption_proof_model'] ?? ''),
            )
            && \hash_equals(
                (string)($owner['config_generation'] ?? ''),
                (string)($owner['tls_session_resumption_config_generation'] ?? ''),
            )
            && \hash_equals(
                (string)($owner['config_sha256'] ?? ''),
                (string)($owner['tls_session_resumption_config_sha256'] ?? ''),
            )
            && \hash_equals(
                (string)($owner['ssl_certificate_sha256'] ?? ''),
                (string)($owner['tls_session_resumption_ssl_certificate_sha256'] ?? ''),
            );
        $sessionMasterPidMatches = $runtimeOwnerActive
            && (int)($status['pid'] ?? 0) > 0
            && (int)($status['pid'] ?? 0) === (int)($owner['tls_session_resumption_master_pid'] ?? 0);
        $sessionRuntimeVerified = $runtimeOwnerActive
            && $sessionEvidenceBound
            && $sessionMasterPidMatches;
        return [
            'managed' => $this->paths->managedEnabled(),
            'managed_mode' => $this->paths->managedMode(),
            'host_nginx_detected' => $hostBinary !== null,
            'host_nginx_binary' => $hostBinary,
            'auto_start' => $this->paths->autoStartEnabled(),
            'installed' => $this->paths->isInstalled(),
            'expected_version' => ManagedNginxInstaller::VERSION,
            'install_identity_matches' => (bool)$installation['manifest_matches'],
            'binary_version' => (string)($binaryCapabilities['version'] ?? ''),
            'http2_module' => (bool)($binaryCapabilities['http2_module'] ?? false),
            'http3_module' => (bool)($binaryCapabilities['http3_module'] ?? false),
            'http3_configure_flag' => (bool)($binaryCapabilities['http3_configure_flag'] ?? false),
            'http3_reason' => (string)($owner['http3_reason'] ?? $binaryCapabilities['http3_reason'] ?? ''),
            'http3_quic_tls_recommended' => (bool)($binaryCapabilities['quic_tls_recommended'] ?? false),
            'shared_session_ticket_keys_supported' => (bool)($binaryCapabilities['shared_session_ticket_keys'] ?? false),
            'http_ssl_module' => (bool)($binaryCapabilities['http_ssl_module'] ?? false),
            'rewrite_module' => (bool)($binaryCapabilities['rewrite_module'] ?? false),
            'gzip_module' => (bool)($binaryCapabilities['gzip_module'] ?? false),
            'openssl_version' => (string)($binaryCapabilities['openssl_version'] ?? ''),
            'tls13_capable' => (bool)($binaryCapabilities['tls13_capable'] ?? false),
            'binary_capabilities_ok' => (bool)($binaryCapabilities['ok'] ?? false),
            'binary' => $this->paths->binary(),
            'install_root' => $this->paths->installRoot(),
            'runtime_root' => $this->paths->runtimeRoot(),
            'conf' => $this->paths->confFile(),
            'listen_http' => \is_array($owner) ? $ownerListenHttp : $ports['http'],
            'listen_https' => \is_array($owner) ? $ownerListenHttps : $ports['https'],
            'configured_listen_http' => $ports['http'],
            'configured_listen_https' => $ports['https'],
            'owner_listen_http' => $ownerListenHttp,
            'owner_listen_https' => $ownerListenHttps,
            'owner_ports_bound' => $ownerPortsBound,
            'owner_config_bound' => $ownerConfigBound,
            'port_source' => \is_array($owner) ? 'owner' : $ports['source'],
            'project_offset' => $ports['offset'],
            'running' => $status['running'],
            'runtime_owner_active' => $runtimeOwnerActive,
            'pid' => $status['pid'],
            'owner_instance' => (string)($owner['instance_name'] ?? ''),
            'owner_upstream_host' => (string)($owner['upstream_host'] ?? ''),
            'owner_upstream_port' => (int)($owner['upstream_port'] ?? 0),
            'owner_upstream_ports' => (array)($owner['upstream_ports'] ?? []),
            'owner_config_generation' => (string)($owner['config_generation'] ?? ''),
            'owner_server_names' => (array)($owner['server_names'] ?? []),
            'owner_ssl_certificate_sha256' => (string)($owner['ssl_certificate_sha256'] ?? ''),
            'edge_cache' => $this->paths->edgeCacheEnabled(),
            'owner_config_sha256' => (string)($owner['config_sha256'] ?? ''),
            'owner_upstream_endpoint_sha256' => (string)($owner['upstream_endpoint_sha256'] ?? ''),
            'public_protocols' => (array)($owner['public_protocols'] ?? []),
            'public_protocols_runtime_verified' => (array)($owner['public_protocols_runtime_verified'] ?? []),
            'http2_enabled' => (bool)($owner['http2_enabled'] ?? false),
            'http2_runtime_verified' => $runtimeOwnerActive && (bool)($owner['http2_runtime_verified'] ?? false),
            'http1_fallback' => (bool)($owner['http1_fallback'] ?? false),
            'http1_runtime_verified' => $runtimeOwnerActive && (bool)($owner['http1_runtime_verified'] ?? false),
            'http3_capable' => (bool)($binaryCapabilities['http3_module'] ?? false),
            'http3_configured' => (bool)($owner['http3_configured'] ?? false),
            'http3_runtime_verified' => $http3RuntimeVerified,
            'http3_verifier_available' => (bool)($owner['http3_verifier_available'] ?? false),
            'http3_status' => (string)($owner['http3_status'] ?? ''),
            'http3_protocol' => (string)($owner['http3_protocol'] ?? ''),
            'http3_evidence_bound' => $http3EvidenceBound,
            'http3_master_pid_matches' => $http3MasterPidMatches,
            'http3_master_pid' => (int)($owner['http3_master_pid'] ?? 0),
            'http3_config_generation' => (string)($owner['http3_config_generation'] ?? ''),
            'http3_config_sha256' => (string)($owner['http3_config_sha256'] ?? ''),
            'http3_ssl_certificate_sha256' => (string)($owner['http3_ssl_certificate_sha256'] ?? ''),
            'http3_verified_at' => (string)($owner['http3_verified_at'] ?? ''),
            'alt_svc_enabled' => (bool)($owner['alt_svc_enabled'] ?? false),
            'tls13_only' => (bool)($owner['tls13_only'] ?? false),
            'tls13_runtime_verified' => $runtimeOwnerActive && (bool)($owner['tls13_runtime_verified'] ?? false),
            'tls_session_cache_shared' => (bool)($owner['tls_session_cache_shared'] ?? false),
            'tls_session_tickets' => (bool)($owner['tls_session_tickets'] ?? false),
            'tls_session_ticket_keys_shared' => (bool)($owner['tls_session_ticket_keys_shared'] ?? false),
            'tls_session_resumption_evidence_bound' => $sessionEvidenceBound,
            'tls_session_resumption_master_pid_matches' => $sessionMasterPidMatches,
            'tls_session_resumption_runtime_verified' => $sessionRuntimeVerified,
            'tls_session_resumption_status' => (string)($owner['tls_session_resumption_status'] ?? ''),
            'tls_session_resumption_proof_model' => (string)($owner['tls_session_resumption_proof_model'] ?? ''),
            'tls_session_resumption_baseline_result' => (string)($owner['tls_session_resumption_baseline_result'] ?? ''),
            'tls_session_resumption_resumed_tls_handshake_p95_us' => (int)($owner['tls_session_resumption_resumed_tls_handshake_p95_us'] ?? 0),
            'tls_session_resumption_negative_control_result' => (string)($owner['tls_session_resumption_negative_control_result'] ?? ''),
            'tls_session_resumption_tls_protocol' => (string)($owner['tls_session_resumption_tls_protocol'] ?? ''),
            'tls_session_resumption_http_protocol' => (string)($owner['tls_session_resumption_http_protocol'] ?? ''),
            'tls_session_resumption_sample_count' => (int)($owner['tls_session_resumption_sample_count'] ?? 0),
            'tls_session_resumption_completed_count' => (int)($owner['tls_session_resumption_completed_count'] ?? 0),
            'tls_session_resumption_failed_count' => (int)($owner['tls_session_resumption_failed_count'] ?? 0),
            'tls_session_resumption_fresh_count' => (int)($owner['tls_session_resumption_fresh_count'] ?? 0),
            'tls_session_resumption_resumed_count' => (int)($owner['tls_session_resumption_resumed_count'] ?? 0),
            'tls_session_resumption_same_worker_status' => (string)($owner['tls_session_resumption_same_worker_status'] ?? ''),
            'tls_session_resumption_same_worker_runtime_verified' => $sessionRuntimeVerified
                && (bool)($owner['tls_session_resumption_same_worker_runtime_verified'] ?? false)
                && (string)($owner['tls_session_resumption_same_worker_status'] ?? '') === 'verified',
            'tls_session_resumption_same_worker_resumed_count' => (int)($owner['tls_session_resumption_same_worker_resumed_count'] ?? 0),
            'tls_session_resumption_cross_worker_status' => (string)($owner['tls_session_resumption_cross_worker_status'] ?? ''),
            'tls_session_resumption_cross_worker_runtime_verified' => $sessionRuntimeVerified
                && (bool)($owner['tls_session_resumption_cross_worker_runtime_verified'] ?? false)
                && (string)($owner['tls_session_resumption_cross_worker_status'] ?? '') === 'verified',
            'tls_session_resumption_cross_worker_resumed_count' => (int)($owner['tls_session_resumption_cross_worker_resumed_count'] ?? 0),
            'tls_session_resumption_baseline_worker_pid' => (int)($owner['tls_session_resumption_baseline_worker_pid'] ?? 0),
            'tls_session_resumption_observed_worker_count' => (int)($owner['tls_session_resumption_observed_worker_count'] ?? 0),
            'tls_session_resumption_effective_worker_count' => (int)($owner['tls_session_resumption_effective_worker_count'] ?? 0),
            'tls_session_resumption_master_pid' => (int)($owner['tls_session_resumption_master_pid'] ?? 0),
            'tls_session_resumption_config_generation' => (string)($owner['tls_session_resumption_config_generation'] ?? ''),
            'tls_session_resumption_config_sha256' => (string)($owner['tls_session_resumption_config_sha256'] ?? ''),
            'tls_session_resumption_ssl_certificate_sha256' => (string)($owner['tls_session_resumption_ssl_certificate_sha256'] ?? ''),
            'tls_session_resumption_verified_at' => (string)($owner['tls_session_resumption_verified_at'] ?? ''),
            'tls_session_resumption_reload_continuity_verified' => $sessionRuntimeVerified
                && (bool)($owner['tls_session_resumption_reload_continuity_verified'] ?? false),
            'tls_session_resumption_reload_continuity_status' => (string)($owner['tls_session_resumption_reload_continuity_status'] ?? ''),
            'tls_session_resumption_reload_continuity_proof_model' => (string)($owner['tls_session_resumption_reload_continuity_proof_model'] ?? ''),
            'tls_session_resumption_reload_continuity_result' => (string)($owner['tls_session_resumption_reload_continuity_result'] ?? ''),
            'tls_session_resumption_reload_issuer_worker_pid' => (int)($owner['tls_session_resumption_reload_issuer_worker_pid'] ?? 0),
            'tls_session_resumption_reload_probe_worker_pid' => (int)($owner['tls_session_resumption_reload_probe_worker_pid'] ?? 0),
            'tls_session_resumption_reload_master_pid' => (int)($owner['tls_session_resumption_reload_master_pid'] ?? 0),
            'tls_session_resumption_reload_tls_handshake_us' => (int)($owner['tls_session_resumption_reload_tls_handshake_us'] ?? 0),
            'tls_session_resumption_reload_previous_config_generation' => (string)($owner['tls_session_resumption_reload_previous_config_generation'] ?? ''),
            'tls_session_resumption_reload_config_generation' => (string)($owner['tls_session_resumption_reload_config_generation'] ?? ''),
            'tls_session_resumption_reload_verified_at' => (string)($owner['tls_session_resumption_reload_verified_at'] ?? ''),
            'edge_cache_ttl_sec' => $this->paths->edgeCacheTtlSec(),
            'gzip' => $this->paths->gzipEnabled(),
            'upstream_keepalive' => $this->paths->upstreamKeepalive(),
            'worker_connections' => $this->paths->workerConnections(),
            'manifest' => $this->readManifest(),
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    private function readManifest(): ?array
    {
        $file = $this->paths->manifestFile();
        if (!\file_exists($file) && !\is_link($file)) {
            return null;
        }
        $decoded = \json_decode(GatewayProjectStateFilesystem::read(
            $file,
            16 * 1024 * 1024,
            'Managed Nginx install manifest',
        ), true);
        return \is_array($decoded) ? $decoded : null;
    }

    private function stableManagedFileSha256(string $file, string $label): string|false
    {
        if (!\file_exists($file) && !\is_link($file)) {
            return false;
        }
        try {
            return \hash('sha256', GatewayProjectStateFilesystem::read(
                $file,
                16 * 1024 * 1024,
                $label,
            ));
        } catch (\Throwable) {
            return false;
        }
    }

    public static function fromEnv(): self
    {
        $env = Env::getInstance()->getConfig();
        $nginxCfg = \is_array($env) && \is_array($env['wls']['edge']['nginx'] ?? null)
            ? $env['wls']['edge']['nginx']
            : [];
        $paths = new ManagedNginxPaths(null, $nginxCfg);
        return new self(
            $paths,
            new ManagedNginxInstaller($paths),
            new ManagedNginxConfigWriter($paths),
            new ManagedNginxProcessManager($paths),
            new ManagedNginxPortAllocator($paths),
        );
    }
}
