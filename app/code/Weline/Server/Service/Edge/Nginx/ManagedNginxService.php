<?php

declare(strict_types=1);

namespace Weline\Server\Service\Edge\Nginx;

use Weline\Framework\App\Env;
use Weline\Framework\Runtime\SchedulerSystem;
use Weline\Server\Service\Edge\EdgeAdapterInterface;
use Weline\Server\Service\Edge\EdgeAdapterResolver;
use Weline\Server\Service\Runtime\RuntimeSelection;
use Weline\Server\Service\ServerInstanceManager;

/**
 * Facade for per-project managed nginx lifecycle used by CLI and server:start/stop.
 */
final class ManagedNginxService
{
    private const LIFECYCLE_LOCK_TIMEOUT_SECONDS = 90.0;

    public function __construct(
        private readonly ManagedNginxPaths $paths = new ManagedNginxPaths(),
        private readonly ManagedNginxInstaller $installer = new ManagedNginxInstaller(),
        private readonly ManagedNginxConfigWriter $configWriter = new ManagedNginxConfigWriter(),
        private readonly ManagedNginxProcessManager $processManager = new ManagedNginxProcessManager(),
        private readonly ManagedNginxPortAllocator $portAllocator = new ManagedNginxPortAllocator(),
        private readonly ManagedNginxTlsSessionResumptionVerifier $tlsSessionResumptionVerifier = new ManagedNginxTlsSessionResumptionVerifier(),
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
            $status = $this->processManager->status();
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
    ): array
    {
        return $this->withLifecycleLock(
            fn(): array => $this->prepareAndStartUnlocked(
                $upstreamPort,
                $upstreamHost,
                $serverNames,
                $ownerInstance,
                $edgeAdapterName,
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
        $currentStatus = $this->processManager->status();
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
        try {
            $written = $this->configWriter->write(
                $upstreamPort,
                $upstreamHost,
                $serverNames,
                true,
                (bool)($capabilities['gzip_module'] ?? false),
                true,
                (bool)($capabilities['http3_module'] ?? false),
                $upstreamPorts,
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

            $test = $this->processManager->testConfig($candidate);
            if (($test['code'] ?? 1) !== 0) {
                $this->configWriter->discardCandidate($candidate);
                return [
                    'ok' => false,
                    'message' => 'managed nginx candidate failed nginx -t: '
                        . \trim((string)($test['output'] ?? '')),
                ];
            }
            $transactionId = \bin2hex(\random_bytes(16));
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
                'config_rollback_expected' => \is_file($this->paths->confFile()),
                ...$this->protocolFacts($written, $capabilities),
                'updated_at' => \date('c'),
            ];
            $this->writeOwnerIntent($ownerIntent);
            $status = $this->processManager->status();
            if (!($status['ok'] ?? false)) {
                return [
                    'ok' => false,
                    'message' => 'managed nginx PID identity became unsafe before config publication',
                ];
            }
            $wasRunning = (bool)$status['running'];
            $publication = $this->configWriter->publishCandidate($candidate, $transactionId);
            $candidate = null;
            $published = true;
            $rollback = \is_string($publication['rollback'] ?? null)
                ? $publication['rollback']
                : null;
            $lifecycle = $wasRunning
                ? $this->processManager->reload()
                : $this->processManager->start();
            $startedByCall = !$wasRunning && (bool)($lifecycle['ok'] ?? false);
            if (!($lifecycle['ok'] ?? false)) {
                $recovery = $this->restorePublishedConfig($rollback, $wasRunning, $startedByCall);
                return [
                    'ok' => false,
                    'message' => 'managed nginx lifecycle rejected the candidate: '
                        . (string)($lifecycle['message'] ?? 'unknown') . $recovery,
                ];
            }
            if (!$this->probeConfigGeneration((int)$written['http'], (string)$written['config_generation'])) {
                $recovery = $this->restorePublishedConfig($rollback, $wasRunning, $startedByCall);
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
                $recovery = $this->restorePublishedConfig($rollback, $wasRunning, $startedByCall);
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
                $recovery = $this->restorePublishedConfig($rollback, $wasRunning, $startedByCall);
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
                $recovery = $this->restorePublishedConfig($rollback, $wasRunning, $startedByCall);
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
            $currentStatus = $this->processManager->status();
            if (!($currentStatus['ok'] ?? false) || !($currentStatus['running'] ?? false)) {
                $recovery = $this->restorePublishedConfig($rollback, $wasRunning, $startedByCall);
                return [
                    'ok' => false,
                    'message' => 'managed nginx exited or changed identity after live verification' . $recovery,
                ];
            }
            $http3 = $this->verifyHttp3Runtime(
                (bool)($written['http3_enabled'] ?? false),
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
                $recovery = $this->restorePublishedConfig($rollback, $wasRunning, $startedByCall);
                return [
                    'ok' => false,
                    'message' => 'managed nginx HTTP/3 runtime verification failed: '
                        . (string)($http3['message'] ?? 'unknown') . $recovery,
                ];
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
                $recovery = $this->restorePublishedConfig($rollback, $wasRunning, $startedByCall);
                return [
                    'ok' => false,
                    'message' => 'managed nginx TLS session resumption verification failed: '
                        . (string)($resumption['message'] ?? 'unknown') . $recovery,
                ];
            }
            $verifiedStatus = $this->processManager->status();
            if (!($verifiedStatus['ok'] ?? false)
                || !($verifiedStatus['running'] ?? false)
                || (int)($verifiedStatus['pid'] ?? 0) !== (int)$currentStatus['pid']
            ) {
                $recovery = $this->restorePublishedConfig($rollback, $wasRunning, $startedByCall);
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
            $this->commitOwnerIntent($ownerIntent);
            if (!$this->configWriter->commitPublished($rollback)) {
                return [
                    'ok' => false,
                    'message' => 'managed nginx is live, but publication bookkeeping could not be committed',
                ];
            }
            $published = false;
            $this->finalizeOwnerIntent($ownerIntent);
            return [
                'ok' => true,
                'message' => $wasRunning ? 'managed nginx candidate verified and reloaded' : 'managed nginx candidate verified and started',
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
                ],
            ];
        } catch (\Throwable $e) {
            if ($candidate !== null) {
                $this->configWriter->discardCandidate($candidate);
            }
            if ($published) {
                try {
                    $this->restorePublishedConfig($rollback, $wasRunning, $startedByCall);
                } catch (\Throwable) {
                    // Preserve the original lifecycle failure below.
                }
            }
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * @return array{ok:bool,message:string}
     */
    public function stop(): array
    {
        return $this->withLifecycleLock(function (): array {
            $status = $this->processManager->status();
            if (!($status['ok'] ?? false)) {
                return [
                    'ok' => false,
                    'message' => 'refusing owner cleanup because managed nginx PID identity is unsafe',
                ];
            }
            if (!$status['running']) {
                $this->clearOwner();
                if (!$this->paths->managedEnabled()) {
                    return ['ok' => true, 'message' => 'managed nginx disabled and not running'];
                }
                return ['ok' => true, 'message' => 'managed nginx is not running'];
            }
            $result = $this->processManager->stop();
            if ($result['ok'] ?? false) {
                $this->clearOwner();
            }
            return $result;
        });
    }

    /** @return array{ok:bool,message:string} */
    public function stopForInstance(string $instanceName): array
    {
        return $this->withLifecycleLock(function () use ($instanceName): array {
            $status = $this->processManager->status();
            if (!($status['ok'] ?? false)) {
                return [
                    'ok' => false,
                    'message' => 'refusing instance stop because managed nginx PID identity is unsafe',
                ];
            }
            $owner = $this->readOwner();
            if (!\is_array($owner)) {
                return ($status['running'] ?? false)
                    ? [
                        'ok' => false,
                        'message' => 'managed nginx is running without a verifiable owner; '
                            . 'use explicit server:nginx:stop after identity review',
                    ]
                    : ['ok' => true, 'message' => 'managed nginx is not running'];
            }
            if (!\hash_equals((string)$owner['instance_name'], \trim($instanceName))) {
                return ['ok' => true, 'message' => 'managed nginx is owned by another WLS instance; left running'];
            }
            $result = $this->processManager->stop();
            if ($result['ok'] ?? false) {
                $this->clearOwner();
            }
            return $result;
        });
    }

    /**
     * @return array{ok:bool,message:string,exit_code?:int|null}
     */
    public function reload(): array
    {
        return $this->withLifecycleLock(fn(): array => $this->reloadUnlocked());
    }

    /** @return array{ok:bool,message:string,exit_code?:int|null} */
    private function reloadUnlocked(): array
    {
        $identity = $this->installedBinaryIdentity();
        if (!($identity['ok'] ?? false)) {
            return $identity;
        }
        $capabilities = (array)($identity['capabilities'] ?? []);
        $status = $this->processManager->status();
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
        $reloadContinuityProbe = null;
        $reloadContinuityEvidence = [];
        try {
            $refreshed = $this->configWriter->write(
                (int)$owner['upstream_port'],
                (string)$owner['upstream_host'],
                (array)($owner['server_names'] ?? []),
                true,
                (bool)($capabilities['gzip_module'] ?? false),
                true,
                (bool)($capabilities['http3_module'] ?? false),
                $upstreamPorts,
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
            $test = $this->processManager->testConfig($candidate);
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
                'config_rollback_expected' => \is_file($this->paths->confFile()),
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
            $publication = $this->configWriter->publishCandidate($candidate, $transactionId);
            $candidate = null;
            $published = true;
            $rollback = \is_string($publication['rollback'] ?? null)
                ? $publication['rollback']
                : null;
            $reloaded = $this->processManager->reload();
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
                $recovery = $this->restorePublishedConfig($rollback, true, false);
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
                $recovery = $this->restorePublishedConfig($rollback, true, false);
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
                $recovery = $this->restorePublishedConfig($rollback, true, false);
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
            $currentStatus = $this->processManager->status();
            if (!($currentStatus['ok'] ?? false) || !($currentStatus['running'] ?? false)) {
                $recovery = $this->restorePublishedConfig($rollback, true, false);
                return [
                    'ok' => false,
                    'message' => 'managed nginx exited or changed identity after reload verification' . $recovery,
                    'exit_code' => 1,
                ];
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
                    $recovery = $this->restorePublishedConfig($rollback, true, false);
                    return [
                        'ok' => false,
                        'message' => 'managed nginx reload TLS Session continuity verification failed: '
                            . (string)($reloadContinuity['message'] ?? 'unknown') . $recovery,
                        'exit_code' => 1,
                    ];
                }
                $reloadContinuityEvidence = (array)($reloadContinuity['evidence'] ?? []);
            }
            $http3 = $this->verifyHttp3Runtime(
                (bool)($refreshed['http3_enabled'] ?? false),
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
                $recovery = $this->restorePublishedConfig($rollback, true, false);
                return [
                    'ok' => false,
                    'message' => 'managed nginx reload HTTP/3 runtime verification failed: '
                        . (string)($http3['message'] ?? 'unknown') . $recovery,
                    'exit_code' => 1,
                ];
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
                $recovery = $this->restorePublishedConfig($rollback, true, false);
                return [
                    'ok' => false,
                    'message' => 'managed nginx reload TLS session resumption verification failed: '
                        . (string)($resumption['message'] ?? 'unknown') . $recovery,
                    'exit_code' => 1,
                ];
            }
            $verifiedStatus = $this->processManager->status();
            if (!($verifiedStatus['ok'] ?? false)
                || !($verifiedStatus['running'] ?? false)
                || (int)($verifiedStatus['pid'] ?? 0) !== (int)$currentStatus['pid']
            ) {
                $recovery = $this->restorePublishedConfig($rollback, true, false);
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
            $this->commitOwnerIntent($refreshedOwner);
            if (!$this->configWriter->commitPublished($rollback)) {
                return [
                    'ok' => false,
                    'message' => 'reload is live, but publication bookkeeping could not be committed',
                    'exit_code' => 1,
                ];
            }
            $published = false;
            $this->finalizeOwnerIntent($refreshedOwner);
            return [
                'ok' => true,
                'message' => 'configuration candidate tested, activated, and verified',
                'exit_code' => $reloaded['exit_code'] ?? 0,
            ];
        } catch (\Throwable $exception) {
            if ($candidate !== null) {
                $this->configWriter->discardCandidate($candidate);
            }
            if ($published) {
                try {
                    $this->restorePublishedConfig($rollback, true, false);
                } catch (\Throwable) {
                    // Preserve the primary reload failure.
                }
            }
            return ['ok' => false, 'message' => $exception->getMessage(), 'exit_code' => 1];
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
            'http3_status' => $http3Configured ? 'pending' : 'not_configured',
            'http3_protocol' => '',
            'http3_master_pid' => 0,
            'http3_config_generation' => '',
            'http3_config_sha256' => '',
            'http3_ssl_certificate_sha256' => '',
            'http3_verified_at' => '',
            'http3_reason' => $http3Configured
                ? 'Nginx QUIC is configured from a verified --with-http_v3_module build; a real QUIC request is still required.'
                : (string)($capabilities['http3_reason'] ?? 'Nginx HTTP/3 capability is unavailable.'),
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
        if ($ports === [] || !\array_is_list($ports)) {
            return false;
        }
        foreach (\array_values(\array_unique(\array_map('intval', $ports))) as $port) {
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
            $errno = 0;
            $error = '';
            $socket = @\stream_socket_client(
                'tcp://' . $targetHost . ':' . $port,
                $errno,
                $error,
                0.5,
                STREAM_CLIENT_CONNECT,
            );
            if (!\is_resource($socket)) {
                continue;
            }
            @\stream_set_timeout($socket, 1);
            @\fwrite(
                $socket,
                "GET /_wls/health?detail=1 HTTP/1.1\r\nHost: 127.0.0.1\r\nConnection: close\r\n\r\n",
            );
            $response = '';
            while (!\feof($socket) && \strlen($response) < 1_048_576) {
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

    private function restorePublishedConfig(
        ?string $rollback,
        bool $wasRunning,
        bool $startedByCall,
    ): string {
        $notes = [];
        if ($startedByCall) {
            // Do not discard the only candidate/intent evidence while that
            // candidate may still be serving traffic.
            $this->stopManagedNginxFailClosed(
                'unable to stop the newly-started candidate before config rollback',
            );
            $notes[] = 'candidate process stopped';
        }

        $this->configWriter->rollbackPublished($rollback);
        if ($wasRunning) {
            $owner = $this->readOwner();
            $restored = $this->processManager->reload();
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

        return $notes === [] ? '' : '; ' . \implode('; ', $notes);
    }

    /** @param array<string,mixed> $owner */
    private function committedOwnerGenerationIsLive(array $owner): bool
    {
        $port = (int)($owner['listen_http'] ?? 0);
        $generation = \strtolower(\trim((string)($owner['config_generation'] ?? '')));
        $configSha256 = \strtolower(\trim((string)($owner['config_sha256'] ?? '')));
        $activeConfigSha256 = \is_file($this->paths->confFile())
            ? \hash_file('sha256', $this->paths->confFile())
            : false;
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
        $stop = $this->processManager->stop();
        $status = $this->processManager->status();
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
        ) {
            return false;
        }
        $consecutiveMatches = 0;
        for ($attempt = 0; $attempt < 60; $attempt++) {
            $matched = false;
            $errno = 0;
            $error = '';
            $socket = @\fsockopen('127.0.0.1', $port, $errno, $error, 0.25);
            if (\is_resource($socket)) {
                @\stream_set_timeout($socket, 1);
                @\fwrite(
                    $socket,
                    "GET /_wls/health HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\n\r\n",
                );
                $headers = '';
                while (!\feof($socket) && \strlen($headers) < 65_536) {
                    $line = @\fgets($socket, 8192);
                    if (!\is_string($line)) {
                        break;
                    }
                    $headers .= $line;
                    if ($line === "\r\n" || $line === "\n") {
                        break;
                    }
                }
                @\fclose($socket);
                if (\preg_match('/\AHTTP\/1\.[01]\s+200(?:\s|$)/', $headers) === 1
                    && \preg_match(
                        '/^X-Wls-Nginx-Config:\s*' . \preg_quote($generation, '/') . '\s*$/mi',
                        $headers,
                    ) === 1
                ) {
                    $matched = true;
                    $consecutiveMatches++;
                    if ($consecutiveMatches >= 8) {
                        return true;
                    }
                }
            }
            if (!$matched) {
                $consecutiveMatches = 0;
            }
            SchedulerSystem::usleep(100_000);
        }

        return false;
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
        ) {
            return false;
        }
        $peerName = 'localhost';
        foreach ($serverNames as $serverName) {
            $candidate = \trim((string)$serverName);
            if ($candidate !== '' && $candidate !== '_') {
                $peerName = $candidate;
                break;
            }
        }
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $context = \stream_context_create(['ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
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
                1.0,
                STREAM_CLIENT_CONNECT,
                $context,
            );
            if (\is_resource($socket)) {
                @\stream_set_timeout($socket, 2);
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
                    && \preg_match('/\AHTTP\/1\.[01]\s+200(?:\s|$)/', $headers) === 1
                    && \preg_match(
                        '/^X-Wls-Nginx-Config:\s*' . \preg_quote($generation, '/') . '\s*$/mi',
                        $headers,
                    ) === 1
                ) {
                    return true;
                }
            }
            SchedulerSystem::usleep(100_000);
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
        $probeHost = 'localhost';
        foreach ($serverNames as $candidate) {
            $candidate = \strtolower(\trim((string)$candidate));
            if ($candidate === ''
                || $candidate === '_'
                || \str_contains($candidate, '*')
                || \str_starts_with($candidate, '.')
                || \str_contains($candidate, ':')
                || \preg_match('/\A[a-z0-9](?:[a-z0-9.-]{0,251}[a-z0-9])?\z/D', $candidate) !== 1
            ) {
                continue;
            }
            $probeHost = $candidate;
            break;
        }

        $requestedVersion = $protocol === '2'
            ? (int)\constant('CURL_HTTP_VERSION_2_0')
            : (int)\constant('CURL_HTTP_VERSION_1_1');
        $sslVersion = (int)\constant('CURL_SSLVERSION_TLSv1_3');
        if (\defined('CURL_SSLVERSION_MAX_TLSv1_3')) {
            $sslVersion |= (int)\constant('CURL_SSLVERSION_MAX_TLSv1_3');
        }

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $headers = '';
            $handle = @\curl_init(
                'https://' . $probeHost . ':' . $port . '/_wls/health?detail=1',
            );
            if ($handle === false) {
                return false;
            }
            $options = [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HEADER => false,
                CURLOPT_HEADERFUNCTION => static function (mixed $curl, string $line) use (&$headers): int {
                    if (\preg_match('/\AHTTP\//i', $line) === 1) {
                        $headers = '';
                    }
                    $headers .= $line;
                    return \strlen($line);
                },
                CURLOPT_HTTP_VERSION => $requestedVersion,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => 0,
                CURLOPT_SSLVERSION => $sslVersion,
                CURLOPT_RESOLVE => [$probeHost . ':' . $port . ':127.0.0.1'],
                CURLOPT_CONNECTTIMEOUT_MS => 1_500,
                CURLOPT_TIMEOUT_MS => 5_000,
                CURLOPT_FRESH_CONNECT => true,
                CURLOPT_FORBID_REUSE => true,
                CURLOPT_PROXY => '',
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
            $body = @\curl_exec($handle);
            $errno = @\curl_errno($handle);
            $responseCode = (int)@\curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
            $httpVersion = (int)@\curl_getinfo($handle, CURLINFO_HTTP_VERSION);
            @\curl_close($handle);

            $health = \is_string($body) ? \json_decode($body, true) : null;
            if ($errno === CURLE_OK
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
            SchedulerSystem::usleep(100_000);
        }

        return false;
    }

    /**
     * @return array{ok:bool,message:string,evidence:array<string,mixed>}
     */
    private function verifyHttp3Runtime(
        bool $configured,
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
            return [
                'ok' => true,
                'message' => 'HTTP/3 is not configured',
                'evidence' => [
                    'http3_runtime_verified' => false,
                    'http3_verifier_available' => false,
                    'http3_status' => 'not_configured',
                    'http3_protocol' => '',
                    'http3_master_pid' => 0,
                    'http3_config_generation' => '',
                    'http3_config_sha256' => '',
                    'http3_ssl_certificate_sha256' => '',
                    'http3_verified_at' => '',
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
        ) {
            return ['ok' => false, 'message' => 'HTTP/3 verification identity is invalid', 'evidence' => []];
        }
        $backendIdentity = $this->resolveWlsBackendIdentity($ownerInstance, $upstreamPort);
        if ($backendIdentity === null) {
            return ['ok' => false, 'message' => 'HTTP/3 WLS backend identity is invalid', 'evidence' => []];
        }
        $probeHost = 'localhost';
        foreach ($serverNames as $candidate) {
            $candidate = \strtolower(\trim((string)$candidate));
            if ($candidate === ''
                || $candidate === '_'
                || \str_contains($candidate, '*')
                || \str_contains($candidate, ':')
                || \preg_match('/\A[a-z0-9](?:[a-z0-9.-]{0,251}[a-z0-9])?\z/D', $candidate) !== 1
            ) {
                continue;
            }
            $probeHost = $candidate;
            break;
        }

        $evidence = [
            'http3_runtime_verified' => false,
            'http3_verifier_available' => false,
            'http3_status' => 'verification_unavailable',
            'http3_protocol' => '',
            'http3_master_pid' => $masterPid,
            'http3_config_generation' => $generation,
            'http3_config_sha256' => $configSha256,
            'http3_ssl_certificate_sha256' => $certificateSha256,
            'http3_verified_at' => '',
            'http3_reason' => 'Nginx HTTP/3 is configured, but this PHP cURL runtime cannot issue an HTTP/3-only probe.',
        ];
        if (!\function_exists('curl_init')
            || !\defined('CURLOPT_HTTP_VERSION')
            || !\defined('CURL_HTTP_VERSION_3ONLY')
        ) {
            return ['ok' => true, 'message' => (string)$evidence['http3_reason'], 'evidence' => $evidence];
        }
        $curlVersion = \curl_version();
        if (\defined('CURL_VERSION_HTTP3')
            && (((int)($curlVersion['features'] ?? 0) & (int)\constant('CURL_VERSION_HTTP3')) === 0)
        ) {
            return ['ok' => true, 'message' => (string)$evidence['http3_reason'], 'evidence' => $evidence];
        }
        $evidence['http3_verifier_available'] = true;
        $lastError = 'HTTP/3-only request did not complete';
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $headers = '';
            $handle = \curl_init(
                'https://' . $probeHost . ':' . $port . '/_wls/health?detail=1'
            );
            if ($handle === false) {
                $lastError = 'unable to initialize cURL';
                break;
            }
            $options = [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HEADER => false,
                CURLOPT_HEADERFUNCTION => static function (mixed $curl, string $line) use (&$headers): int {
                    $headers .= $line;
                    return \strlen($line);
                },
                CURLOPT_HTTP_VERSION => (int)\constant('CURL_HTTP_VERSION_3ONLY'),
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => 0,
                CURLOPT_RESOLVE => [$probeHost . ':' . $port . ':127.0.0.1'],
                CURLOPT_CONNECTTIMEOUT_MS => 1500,
                CURLOPT_TIMEOUT_MS => 5000,
                CURLOPT_FRESH_CONNECT => true,
                CURLOPT_FORBID_REUSE => true,
                CURLOPT_PROXY => '',
            ];
            if (\defined('CURLOPT_PROTOCOLS') && \defined('CURLPROTO_HTTPS')) {
                $options[(int)\constant('CURLOPT_PROTOCOLS')] = (int)\constant('CURLPROTO_HTTPS');
            }
            if (\defined('CURLOPT_SSLVERSION') && \defined('CURL_SSLVERSION_TLSv1_3')) {
                $options[(int)\constant('CURLOPT_SSLVERSION')] = (int)\constant('CURL_SSLVERSION_TLSv1_3');
            }
            \curl_setopt_array($handle, $options);
            $body = \curl_exec($handle);
            $errno = \curl_errno($handle);
            $error = \curl_error($handle);
            $info = \curl_getinfo($handle);
            \curl_close($handle);
            $http3Version = \defined('CURL_HTTP_VERSION_3')
                ? (int)\constant('CURL_HTTP_VERSION_3')
                : 30;
            $health = \is_string($body) ? \json_decode($body, true) : null;
            if ($errno === 0
                && \is_string($body)
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
            SchedulerSystem::usleep(100_000);
        }

        $lastError = \trim((string)\preg_replace('/\s+/', ' ', $lastError));
        $evidence['http3_status'] = 'failed';
        $evidence['http3_reason'] = 'Live Nginx HTTP/3 QUIC probe failed: ' . \substr($lastError, 0, 240);
        return ['ok' => false, 'message' => (string)$evidence['http3_reason'], 'evidence' => $evidence];
    }

    /** @return array<string,mixed>|null */
    private function readOwner(): ?array
    {
        return $this->readOwnerFile($this->paths->ownerFile());
    }

    /** @return array{instance_name:string,upstream_host:string,upstream_port:int,server_names:list<string>,config_generation:string,updated_at:string}|null */
    private function readOwnerFile(string $file): ?array
    {
        if (!\is_file($file)) {
            return null;
        }
        $decoded = \json_decode((string)@\file_get_contents($file), true);
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
            || ((bool)($decoded['ssl_required'] ?? false)
                && \preg_match(
                    '/\A[a-f0-9]{64}\z/D',
                    \strtolower((string)($decoded['ssl_certificate_sha256'] ?? '')),
                ) !== 1)
        ) {
            return null;
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
        if (!\in_array(
            $http3Status,
            ['', 'pending', 'not_configured', 'verification_unavailable', 'failed', 'verified'],
            true,
        )
            || !\in_array($http3Protocol, ['', 'HTTP/3'], true)
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

        return [
            'transaction_id' => (string)($decoded['transaction_id'] ?? ''),
            'instance_name' => \trim((string)$decoded['instance_name']),
            'config_rollback_expected' => (bool)($decoded['config_rollback_expected'] ?? false),
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
    }

    /** @param array{instance_name:string,upstream_host:string,upstream_port:int,server_names?:list<string>,config_generation:string,updated_at:string} $owner */
    private function writeOwner(array $owner): void
    {
        $file = $this->paths->ownerFile();
        $json = \json_encode($owner, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $temp = $file . '.candidate.' . (string)\getmypid() . '.' . \bin2hex(\random_bytes(4));
        if (\file_put_contents($temp, $json, LOCK_EX) === false) {
            throw new \RuntimeException('Unable to write managed nginx owner candidate.');
        }
        $backup = null;
        if (\is_file($file)) {
            $transactionId = \strtolower(\trim((string)($owner['transaction_id'] ?? '')));
            if (\preg_match('/\A[a-f0-9]{32}\z/D', $transactionId) !== 1) {
                @\unlink($temp);
                throw new \RuntimeException('Managed nginx owner transaction id is invalid.');
            }
            $backup = $file . '.rollback.' . $transactionId;
            if (\is_file($backup)) {
                @\unlink($temp);
                throw new \RuntimeException('Managed nginx owner rollback already exists.');
            }
            if (!@\rename($file, $backup)) {
                @\unlink($temp);
                throw new \RuntimeException('Unable to preserve managed nginx owner state.');
            }
        }
        if (!@\rename($temp, $file)) {
            if ($backup !== null) {
                @\rename($backup, $file);
            }
            @\unlink($temp);
            throw new \RuntimeException('Unable to publish managed nginx owner state.');
        }
        if ($backup !== null) {
            @\unlink($backup);
        }
    }

    /** @param array{instance_name:string,upstream_host:string,upstream_port:int,server_names?:list<string>,config_generation:string,updated_at:string} $owner */
    private function writeOwnerIntent(array $owner): void
    {
        $file = $this->paths->ownerIntentFile();
        $json = \json_encode($owner, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $temp = $file . '.candidate.' . (string)\getmypid() . '.' . \bin2hex(\random_bytes(4));
        if (\file_put_contents($temp, $json, LOCK_EX) === false) {
            throw new \RuntimeException('Unable to write managed nginx owner intent.');
        }
        $previous = null;
        if (\is_file($file)) {
            $previous = $file . '.previous.' . (string)\getmypid() . '.' . \bin2hex(\random_bytes(4));
            if (!@\rename($file, $previous)) {
                @\unlink($temp);
                throw new \RuntimeException('Unable to preserve managed nginx owner intent.');
            }
        }
        if (!@\rename($temp, $file)) {
            if ($previous !== null) {
                @\rename($previous, $file);
            }
            @\unlink($temp);
            throw new \RuntimeException('Unable to publish managed nginx owner intent.');
        }
        if ($previous !== null) {
            @\unlink($previous);
        }
    }

    /** @param array{instance_name:string,upstream_host:string,upstream_port:int,server_names?:list<string>,config_generation:string,updated_at:string} $expected */
    private function commitOwnerIntent(array $expected): void
    {
        $intent = $this->readOwnerFile($this->paths->ownerIntentFile());
        if (!\is_array($intent)
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
        $activeConfigSha256 = \is_file($this->paths->confFile())
            ? \hash_file('sha256', $this->paths->confFile())
            : false;
        if (!\is_string($activeConfigSha256)
            || !\hash_equals((string)$intent['config_sha256'], \strtolower($activeConfigSha256))
        ) {
            throw new \RuntimeException('Managed nginx active config digest changed before owner commit.');
        }
        $this->writeOwner($intent);
    }

    /** @param array<string,mixed> $expected @param array<string,mixed> $intent */
    private function http3EvidenceMatches(array $expected, array $intent): bool
    {
        $fields = [
            'http3_runtime_verified',
            'http3_verifier_available',
            'http3_status',
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

    /** @param array<string,mixed> $expected @param array<string,mixed> $intent */
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

    /** @param array{transaction_id:string,instance_name:string,config_generation:string} $expected */
    private function finalizeOwnerIntent(array $expected): void
    {
        $intentFile = $this->paths->ownerIntentFile();
        $intent = $this->readOwnerFile($intentFile);
        if (!\is_array($intent)
            || !\hash_equals((string)$expected['transaction_id'], (string)$intent['transaction_id'])
            || !\hash_equals((string)$expected['instance_name'], (string)$intent['instance_name'])
            || !\hash_equals((string)$expected['config_generation'], (string)$intent['config_generation'])
            || !\hash_equals((string)$expected['config_sha256'], (string)$intent['config_sha256'])
            || !@\unlink($intentFile)
        ) {
            throw new \RuntimeException('Managed nginx owner intent could not be finalized.');
        }
    }

    private function recoverOwnerPublication(): void
    {
        $ownerFile = $this->paths->ownerFile();
        $intentFile = $this->paths->ownerIntentFile();
        $intent = $this->readOwnerFile($intentFile);
        if (!\is_array($intent) && \is_file($intentFile)) {
            throw new \RuntimeException('Managed nginx owner intent is unreadable or invalid.');
        }
        if (!\is_array($intent)) {
            return;
        }
        $transactionId = (string)($intent['transaction_id'] ?? '');
        if (!\is_file($ownerFile) && $transactionId !== '') {
            $ownerRollback = $ownerFile . '.rollback.' . $transactionId;
            if (\is_file($ownerRollback) && !@\rename($ownerRollback, $ownerFile)) {
                throw new \RuntimeException('Unable to recover managed nginx owner transaction.');
            }
        }
        $committedOwner = $this->readOwner();
        $activeConfigSha256 = \is_file($this->paths->confFile())
            ? \hash_file('sha256', $this->paths->confFile())
            : false;
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
        if ($committedOwnerIdentityMatches
            && (\preg_match('/\A[a-f0-9]{64}\z/D', (string)($intent['config_sha256'] ?? '')) !== 1
                || !\hash_equals((string)$intent['config_sha256'], (string)$committedOwner['config_sha256'])
                || !\is_string($activeConfigSha256)
                || !\hash_equals((string)$intent['config_sha256'], \strtolower($activeConfigSha256)))
        ) {
            throw new \RuntimeException(
                'Committed managed nginx owner no longer matches the active config digest; preserving rollback evidence.'
            );
        }
        if ($committedOwnerIdentityMatches) {
            $configRollback = $this->configWriter->rollbackPathForTransaction($transactionId);
            if (!$this->configWriter->commitPublished($configRollback)) {
                throw new \RuntimeException('Unable to finish committed owner/config bookkeeping.');
            }
            @\unlink($intentFile);
            return;
        }

        $status = $this->processManager->status();
        if (!($status['ok'] ?? false)) {
            throw new \RuntimeException('Cannot recover owner intent while nginx PID identity is unsafe.');
        }
        $configRollback = $transactionId !== ''
            ? $this->configWriter->rollbackPathForTransaction($transactionId)
            : null;
        $rollbackExpected = (bool)($intent['config_rollback_expected'] ?? false);
        $config = @\file_get_contents($this->paths->confFile());
        $generation = (string)$intent['config_generation'];
        $uncommittedConfigPublished = \is_string($config)
            && \preg_match(
                '/add_header X-Wls-Nginx-Config ' . \preg_quote($generation, '/') . ' always;/',
                $config,
            ) === 1;
        if (\is_string($configRollback) && \is_file($configRollback)) {
            $this->configWriter->rollbackPublished($configRollback);
            if ($status['running'] ?? false) {
                $reloaded = $this->processManager->reload();
                if (!($reloaded['ok'] ?? false)
                    || !\is_array($committedOwner)
                    || !$this->committedOwnerGenerationIsLive($committedOwner)
                ) {
                    $this->stopManagedNginxFailClosed(
                        'unable to prove the committed generation after interrupted rollback',
                    );
                }
            }
        } elseif ($rollbackExpected) {
            if ($uncommittedConfigPublished || !\is_file($this->paths->confFile())) {
                throw new \RuntimeException(
                    'Managed nginx transaction expected a rollback file, but its recovery evidence is missing.'
                );
            }
            if ($status['running'] ?? false) {
                $committedGenerationLive = \is_array($committedOwner)
                    && $this->committedOwnerGenerationIsLive($committedOwner);
                if (!$committedGenerationLive && \is_array($committedOwner)) {
                    $reloaded = $this->processManager->reload();
                    $committedGenerationLive = ($reloaded['ok'] ?? false)
                        && $this->committedOwnerGenerationIsLive($committedOwner);
                }
                if (!$committedGenerationLive) {
                    $this->stopManagedNginxFailClosed(
                        'rollback evidence is missing and the committed live generation cannot be proven',
                    );
                }
            }
            if (!@\unlink($intentFile) && \is_file($intentFile)) {
                throw new \RuntimeException('Unable to clear recovered managed nginx owner intent.');
            }
            return;
        } elseif ($uncommittedConfigPublished || !\is_file($this->paths->confFile())) {
            if ($status['running'] ?? false) {
                $this->stopManagedNginxFailClosed(
                    'unable to stop an interrupted first managed nginx publication',
                );
            }
            $this->configWriter->rollbackPublished(null);
        } elseif ($status['running'] ?? false) {
            $this->stopManagedNginxFailClosed(
                'uncommitted managed nginx intent has no trustworthy rollback identity',
            );
        }
        if (!@\unlink($intentFile) && \is_file($intentFile)) {
            throw new \RuntimeException('Unable to clear recovered managed nginx owner intent.');
        }
    }

    private function clearOwner(): void
    {
        $file = $this->paths->ownerFile();
        if (\is_file($file) && !@\unlink($file)) {
            throw new \RuntimeException('Unable to clear managed nginx owner state.');
        }
        $intent = $this->paths->ownerIntentFile();
        if (\is_file($intent) && !@\unlink($intent)) {
            throw new \RuntimeException('Unable to clear managed nginx owner intent.');
        }
    }

    /** @param callable():array<string,mixed> $operation @return array<string,mixed> */
    private function withLifecycleLock(callable $operation): array
    {
        try {
            $this->paths->ensureRuntimeDirectories();
            $handle = @\fopen($this->paths->lifecycleLockFile(), 'c+');
            if (!\is_resource($handle)) {
                return ['ok' => false, 'message' => 'unable to open managed nginx lifecycle lock'];
            }
            $deadline = \microtime(true) + self::LIFECYCLE_LOCK_TIMEOUT_SECONDS;
            $locked = false;
            do {
                $locked = @\flock($handle, LOCK_EX | LOCK_NB);
                if (!$locked) {
                    SchedulerSystem::usleep(50_000);
                }
            } while (!$locked && \microtime(true) < $deadline);
            if (!$locked) {
                @\fclose($handle);
                return ['ok' => false, 'message' => 'managed nginx lifecycle lock timed out'];
            }
            try {
                $this->recoverOwnerPublication();
                $this->configWriter->recoverInterruptedPublication();
                return $operation();
            } finally {
                @\flock($handle, LOCK_UN);
                @\fclose($handle);
            }
        } catch (\Throwable $exception) {
            return ['ok' => false, 'message' => $exception->getMessage()];
        }
    }

    /**
     * @return array<string,mixed>
     */
    public function doctorSnapshot(): array
    {
        $ports = $this->portAllocator->allocate();
        $status = $this->processManager->status();
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
        $activeConfigSha256 = \is_file($this->paths->confFile())
            ? \hash_file('sha256', $this->paths->confFile())
            : false;
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
        if (!\is_file($file)) {
            return null;
        }
        $decoded = \json_decode((string)\file_get_contents($file), true);
        return \is_array($decoded) ? $decoded : null;
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
