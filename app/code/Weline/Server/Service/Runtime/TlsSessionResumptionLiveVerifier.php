<?php

declare(strict_types=1);

namespace Weline\Server\Service\Runtime;

use Weline\Framework\App\Env;
use Weline\Framework\Runtime\SchedulerSystem;
use Weline\Framework\System\Process\Processer;
use Weline\Server\IPC\ControlMessage;
use Weline\Server\Service\Control\IpcControlGateway;
use Weline\Server\Service\SharedStateProtocolProbe;
use Weline\Server\Service\SharedStateServiceRegistry;

/**
 * Destructive, explicit live gate for PHP 8.6 external TLS Session Resumption.
 *
 * This verifier never serializes an Openssl\Session, Session ID, sidecar token,
 * command line, or raw SNI. It is intentionally reached only through
 * TlsSessionResumptionEvidenceStore::verifyAndPublish(). The sidecar fault
 * phase fails closed unless the target is one isolated ai-test-* instance.
 */
final class TlsSessionResumptionLiveVerifier
{
    private const HEALTH_PATH = '/_wls/health?detail=1';
    private const MAX_HTTP_RESPONSE_BYTES = 1_048_576;
    private const RELOAD_TIMEOUT_SECONDS = 210.0;
    private const SIDECAR_RECOVERY_TIMEOUT_SECONDS = 45.0;
    private const PROCESS_IDENTITY_MAX_AGE_SECONDS = 900;
    private const INSTANCE_CONFIG_READ_ATTEMPTS = 5;
    private const INSTANCE_CONFIG_RETRY_MICROSECONDS = 50_000;
    private const MAX_INSTANCE_CONFIG_BYTES = 1_048_576;

    private readonly IpcControlGateway $gateway;
    private readonly SharedStateServiceRegistry $sharedRegistry;
    private string $activeInstanceName = '';
    private string $expectedCertificateSha256 = '';
    private string $expectedCacheConfigSha256 = '';
    private string $expectedRuntimeIdentitySha256 = '';
    private bool $usePublicTlsDataPath = false;

    public function __construct()
    {
        $this->gateway = new IpcControlGateway();
        $this->sharedRegistry = new SharedStateServiceRegistry();
    }

    /**
     * @param array<string, int|float|string|bool|array> $options Store-normalized options only.
     * @return array{captured_at:string,scope:array<string,mixed>,proof:array<string,mixed>}
     */
    public function verify(string $instanceName, array $options): array
    {
        $instanceName = $this->normalizeInstanceName($instanceName);
        $this->activeInstanceName = $instanceName;
        if (!TlsSessionCacheRuntime::apiAvailable()) {
            throw new \RuntimeException('PHP 8.6 external TLS Session cache API is unavailable.');
        }
        $cacheConfig = TlsSessionCacheConfig::fromEnvironment();
        if (!$cacheConfig->enabled()) {
            throw new \RuntimeException('External TLS Session cache is not enabled.');
        }
        $this->expectedCacheConfigSha256 = $this->requiredSha256Option(
            $options,
            'expected_config_sha256',
        );
        $this->expectedRuntimeIdentitySha256 = $this->requiredSha256Option(
            $options,
            'expected_runtime_identity_sha256',
        );
        if (!\hash_equals($this->expectedCacheConfigSha256, $cacheConfig->sha256())) {
            throw new \RuntimeException('TLS verifier process configuration does not match its frozen binding.');
        }

        $sameRounds = $this->optionInt($options, 'same_worker_rounds', 12, 10, 512);
        $crossRounds = $this->optionInt($options, 'cross_worker_rounds', 24, 24, 512);
        $reloadRounds = $this->optionInt($options, 'reload_rounds', 24, 24, 512);
        $faultRounds = $this->optionInt($options, 'fault_rounds', 200, 40, 1_024);
        $postRecoveryRounds = $this->optionInt($options, 'post_recovery_rounds', 24, 24, 512);
        $connectTimeoutMs = $this->optionInt($options, 'connect_timeout_ms', 5_000, 250, 30_000);
        $latencyLimitMs = $this->optionFloat(
            $options,
            'resumption_tls_p95_limit_ms',
            100.0,
            1.0,
            5_000.0,
        );
        $connectTimeoutSeconds = $connectTimeoutMs / 1000;

        $instanceConfig = $this->readInstanceConfig($instanceName);
        $statusBefore = $this->readyStatus($instanceName, $instanceConfig);
        $this->assertControlPlaneIdle($statusBefore);
        $workersBefore = $this->readyWorkers($statusBefore, $instanceConfig);
        if (\count($workersBefore) < 2) {
            throw new \RuntimeException('Cross-Worker TLS verification requires at least two READY Workers.');
        }
        $scope = $this->buildScope($instanceName, $instanceConfig, $statusBefore, $workersBefore);
        $sni = $this->publicHost($instanceConfig);
        $originPort = $this->mainPort($instanceConfig);

        $same = $this->runPairPhase(
            $instanceName,
            $workersBefore,
            $sni,
            $originPort,
            $sameRounds,
            'same',
            $connectTimeoutSeconds,
        );
        $initial = $this->runPairPhase(
            $instanceName,
            $workersBefore,
            $sni,
            $originPort,
            $crossRounds,
            'cross',
            $connectTimeoutSeconds,
        );

        $continuityCapture = $this->connect(
            $this->usePublicTlsDataPath ? $originPort : (int)$workersBefore[0]['port'],
            $sni,
            $originPort,
            null,
            $connectTimeoutSeconds,
        );
        if (!$continuityCapture['healthy'] || (int)$continuityCapture['worker_id'] <= 0) {
            throw new \RuntimeException('Reload continuity capture did not traverse a healthy TLS Worker.');
        }
        $continuitySession = $continuityCapture['session'] ?? null;
        if (!$this->isResumableSession($continuitySession)) {
            throw new \RuntimeException('Reload continuity capture did not produce a resumable Session.');
        }

        $reloadResult = $this->reloadWorkers(
            $instanceName,
            $instanceConfig,
            $statusBefore,
            $workersBefore,
        );
        $statusAfterReload = $reloadResult['status'];
        $workersAfterReload = $reloadResult['workers'];
        $continuityBaseline = $this->snapshotWorkers(
            $instanceName,
            $workersAfterReload,
            $sni,
            $originPort,
            $connectTimeoutSeconds,
        );
        $continuityTarget = $workersAfterReload[\count($workersAfterReload) > 1 ? 1 : 0];
        $continuityResume = $this->connect(
            $this->usePublicTlsDataPath ? $originPort : (int)$continuityTarget['port'],
            $sni,
            $originPort,
            $continuitySession,
            $connectTimeoutSeconds,
        );
        if (!$continuityResume['healthy']) {
            throw new \RuntimeException('Reload continuity request was not healthy.');
        }
        unset($continuitySession, $continuityCapture);
        $continuityAfter = $this->snapshotWorkers(
            $instanceName,
            $workersAfterReload,
            $sni,
            $originPort,
            $connectTimeoutSeconds,
        );
        $continuityServerDelta = $this->counterDelta(
            $continuityBaseline,
            $continuityAfter,
            'actual_resumed',
        );

        $postReload = $this->runPairPhase(
            $instanceName,
            $workersAfterReload,
            $sni,
            $originPort,
            $reloadRounds,
            'cross',
            $connectTimeoutSeconds,
        );

        $faultBaseline = $this->snapshotWorkers(
            $instanceName,
            $workersAfterReload,
            $sni,
            $originPort,
            $connectTimeoutSeconds,
        );
        $sidecarBefore = $this->authorizeIsolatedMemorySidecarTermination(
            $instanceName,
            $instanceConfig,
            $statusAfterReload,
            $workersAfterReload,
        );
        $sidecarFault = null;
        $sidecarAfter = null;
        $faultFinal = null;
        $faultError = null;
        $recoveryError = null;
        $faultAttempted = false;
        try {
            $faultAttempted = true;
            $this->shutdownAuthorizedMemorySidecar($sidecarBefore, $instanceName);
            $this->waitForMemorySidecarExit($sidecarBefore);
            $sidecarFault = $this->runPairPhase(
                $instanceName,
                $workersAfterReload,
                $sni,
                $originPort,
                $faultRounds,
                'cross',
                $connectTimeoutSeconds,
                $faultBaseline,
            );
        } catch (\Throwable $throwable) {
            $faultError = $throwable;
        } finally {
            if ($faultAttempted) {
                try {
                    $sidecarAfter = $this->waitForMemorySidecarRecovery(
                        $instanceName,
                        $sidecarBefore,
                    );
                } catch (\Throwable $throwable) {
                    $recoveryError = $throwable;
                }
                try {
                    $faultFinal = $this->waitForCacheDrain(
                        $instanceName,
                        $workersAfterReload,
                        $sni,
                        $originPort,
                        $connectTimeoutSeconds,
                    );
                } catch (\Throwable $throwable) {
                    $recoveryError = $recoveryError === null
                        ? $throwable
                        : new \RuntimeException(
                            $recoveryError->getMessage() . '; cache drain also failed: '
                            . $throwable->getMessage(),
                            0,
                            $recoveryError,
                        );
                }
            }
        }
        if ($faultError !== null) {
            if ($recoveryError !== null) {
                throw new \RuntimeException(
                    'TLS sidecar fault phase failed and recovery cleanup also failed: '
                    . $recoveryError->getMessage(),
                    0,
                    $faultError,
                );
            }
            throw $faultError;
        }
        if ($recoveryError !== null) {
            throw $recoveryError;
        }
        if (!\is_array($sidecarFault) || !\is_array($sidecarAfter) || !\is_array($faultFinal)) {
            throw new \RuntimeException('TLS sidecar fault phase did not produce complete recovery evidence.');
        }
        $sidecarFault = $this->withSnapshotDeltas($sidecarFault, $faultBaseline, $faultFinal);

        $statusAfterFault = $this->readyStatus($instanceName, $instanceConfig);
        $this->assertCriticalProcessesUnchanged(
            $statusAfterReload,
            $statusAfterFault,
            $workersAfterReload,
            $instanceConfig,
        );

        $postRecovery = $this->runPairPhase(
            $instanceName,
            $workersAfterReload,
            $sni,
            $originPort,
            $postRecoveryRounds,
            'cross',
            $connectTimeoutSeconds,
        );
        $finalSnapshot = $postRecovery['after_snapshot'];

        $finalInstanceConfig = $this->readInstanceConfig($instanceName);
        if (!\hash_equals(
            $this->instanceConfigFingerprint($instanceConfig),
            $this->instanceConfigFingerprint($finalInstanceConfig),
        )) {
            throw new \RuntimeException('Active WLS instance configuration drifted during TLS verification.');
        }
        $finalStatus = $this->readyStatus($instanceName, $finalInstanceConfig);
        $this->assertControlPlaneIdle($finalStatus);
        $finalWorkers = $this->readyWorkers($finalStatus, $finalInstanceConfig);
        $this->assertCriticalProcessesUnchanged(
            $statusAfterReload,
            $finalStatus,
            $workersAfterReload,
            $finalInstanceConfig,
        );
        $finalScope = $this->buildScope(
            $instanceName,
            $finalInstanceConfig,
            $finalStatus,
            $finalWorkers,
        );
        if ($finalScope !== $scope) {
            throw new \RuntimeException('Active WLS TLS scope drifted during live verification.');
        }

        $cleanLatencies = \array_merge(
            $same['resumed_tls_ms'],
            $initial['resumed_tls_ms'],
            $postReload['resumed_tls_ms'],
            $postRecovery['resumed_tls_ms'],
        );
        if (\count($cleanLatencies) < 24) {
            throw new \RuntimeException('Dedicated resumed-handshake latency gate has fewer than 24 samples.');
        }

        $cacheFailureDelta = $same['cache_failure_delta']
            + $initial['cache_failure_delta']
            + $postReload['cache_failure_delta']
            + $sidecarFault['cache_failure_delta']
            + $postRecovery['cache_failure_delta'];
        $droppedWriteDelta = $same['dropped_write_delta']
            + $initial['dropped_write_delta']
            + $postReload['dropped_write_delta']
            + $sidecarFault['dropped_write_delta']
            + $postRecovery['dropped_write_delta'];
        $missingDelta = $same['reuse_observation_missing_delta']
            + $initial['reuse_observation_missing_delta']
            + $postReload['reuse_observation_missing_delta']
            + $sidecarFault['reuse_observation_missing_delta']
            + $postRecovery['reuse_observation_missing_delta']
            + $this->counterDelta($continuityBaseline, $continuityAfter, 'reuse_observation_missing');

        $proof = [
            'same_worker_rounds' => $same['rounds'],
            'same_worker_http_success' => $same['http_success'],
            'same_worker_client_resumed_pairs' => $same['client_resumed_pairs'],
            'same_worker_server_resumed_delta' => $same['server_resumed_delta'],
            'initial_rounds' => $initial['rounds'],
            'initial_http_success' => $initial['http_success'],
            'initial_client_resumed_pairs' => $initial['client_resumed_pairs'],
            'initial_server_resumed_delta' => $initial['server_resumed_delta'],
            'initial_cross_worker_pairs' => $initial['cross_worker_pairs'],
            'post_reload_rounds' => $postReload['rounds'],
            'post_reload_http_success' => $postReload['http_success'],
            'post_reload_client_resumed_pairs' => $postReload['client_resumed_pairs'],
            'post_reload_server_resumed_delta' => $postReload['server_resumed_delta'],
            'post_reload_cross_worker_pairs' => $postReload['cross_worker_pairs'],
            'reload_worker_fingerprint_changed' => $reloadResult['worker_fingerprint_changed'],
            'reload_preserved_session_resumed' => $continuityResume['session_reused'] === true,
            'reload_preserved_session_server_resumed_delta' => $continuityServerDelta,
            'sidecar_fault_method' => 'authenticated_server_shutdown',
            'sidecar_fault_rounds' => $sidecarFault['rounds'],
            'sidecar_fault_http_success' => $sidecarFault['http_success'],
            'sidecar_fault_client_resumed_pairs' => $sidecarFault['client_resumed_pairs'],
            'sidecar_fault_server_resumed_delta' => $sidecarFault['server_resumed_delta'],
            'sidecar_fault_cross_worker_pairs' => $sidecarFault['cross_worker_pairs'],
            'sidecar_generation_changed' => !\hash_equals(
                (string)$sidecarBefore['_managed_lease_launch_id'],
                (string)$sidecarAfter['_managed_lease_launch_id'],
            ),
            'post_recovery_rounds' => $postRecovery['rounds'],
            'post_recovery_http_success' => $postRecovery['http_success'],
            'post_recovery_client_resumed_pairs' => $postRecovery['client_resumed_pairs'],
            'post_recovery_server_resumed_delta' => $postRecovery['server_resumed_delta'],
            'post_recovery_cross_worker_pairs' => $postRecovery['cross_worker_pairs'],
            'reuse_observation_missing' => $missingDelta,
            'cache_failure_delta' => $cacheFailureDelta,
            'dropped_write_delta' => $droppedWriteDelta,
            'pending_writes_final' => $this->sumSnapshotCounter($finalSnapshot, 'pending_writes')
                + $this->sumSnapshotCounter($finalSnapshot, 'writer_pending_responses'),
            'inflight_writes_final' => $this->sumSnapshotCounter($finalSnapshot, 'inflight_writes'),
            'resumption_tls_p95_ms' => $this->percentile($cleanLatencies, 0.95),
            'resumption_tls_p95_limit_ms' => $latencyLimitMs,
        ];

        return [
            'captured_at' => \date('c'),
            'scope' => $scope,
            'proof' => $proof,
        ];
    }

    private function normalizeInstanceName(string $instanceName): string
    {
        $instanceName = \trim($instanceName);
        if ($instanceName === ''
            || \strlen($instanceName) > 128
            || \preg_match('/^[A-Za-z0-9._-]+$/D', $instanceName) !== 1
        ) {
            throw new \InvalidArgumentException('TLS verifier instance name is invalid.');
        }

        return $instanceName;
    }

    /** @param array<string,mixed> $options */
    private function optionInt(array $options, string $key, int $default, int $minimum, int $maximum): int
    {
        $value = \array_key_exists($key, $options) ? (int)$options[$key] : $default;
        if ($value < $minimum || $value > $maximum) {
            throw new \InvalidArgumentException($key . ' is outside the live-verifier safety range.');
        }

        return $value;
    }

    /** @param array<string,mixed> $options */
    private function optionFloat(array $options, string $key, float $default, float $minimum, float $maximum): float
    {
        $value = \array_key_exists($key, $options) ? (float)$options[$key] : $default;
        if (!\is_finite($value) || $value < $minimum || $value > $maximum) {
            throw new \InvalidArgumentException($key . ' is outside the live-verifier safety range.');
        }

        return $value;
    }

    /** @param array<string,mixed> $options */
    private function requiredSha256Option(array $options, string $key): string
    {
        $value = \strtolower(\trim((string)($options[$key] ?? '')));
        if (!$this->isSha256($value)) {
            throw new \InvalidArgumentException($key . ' must be a SHA-256 digest supplied by the evidence store.');
        }

        return $value;
    }

    /** @return array<string,mixed> */
    private function readInstanceConfig(string $instanceName): array
    {
        $path = Env::VAR_DIR . 'server' . DIRECTORY_SEPARATOR . 'instances'
            . DIRECTORY_SEPARATOR . $instanceName . '.json';
        for ($attempt = 1; $attempt <= self::INSTANCE_CONFIG_READ_ATTEMPTS; $attempt++) {
            \clearstatcache(true, $path);
            $raw = $this->readStableInstanceConfigDocument($path);
            if ($raw !== null) {
                try {
                    $config = \json_decode($raw, true, 128, JSON_THROW_ON_ERROR);
                } catch (\JsonException) {
                    $config = null;
                }
                if (\is_array($config)) {
                    if ((string)($config['instance_name'] ?? '') !== $instanceName) {
                        throw new \RuntimeException(
                            'Active WLS instance configuration identity does not match.'
                        );
                    }

                    return $config;
                }
            }
            if ($attempt < self::INSTANCE_CONFIG_READ_ATTEMPTS) {
                SchedulerSystem::usleep(self::INSTANCE_CONFIG_RETRY_MICROSECONDS);
            }
        }

        throw new \RuntimeException(
            'Unable to read a complete active WLS instance configuration after bounded retries.'
        );
    }

    private function readStableInstanceConfigDocument(string $path): ?string
    {
        $pathBefore = @\lstat($path);
        if (!\is_array($pathBefore)) {
            return null;
        }
        $this->assertRegularInstanceConfigStat($pathBefore);
        $handle = @\fopen($path, 'rb');
        if (!\is_resource($handle)) {
            return null;
        }
        try {
            $handleBefore = @\fstat($handle);
            if (!\is_array($handleBefore)) {
                return null;
            }
            $this->assertRegularInstanceConfigStat($handleBefore);
            $size = (int)($handleBefore['size'] ?? -1);
            if ($size > self::MAX_INSTANCE_CONFIG_BYTES) {
                throw new \RuntimeException('Active WLS instance configuration size is invalid.');
            }
            if ($size <= 0) {
                return null;
            }
            $raw = @\stream_get_contents($handle, self::MAX_INSTANCE_CONFIG_BYTES + 1);
            $handleAfter = @\fstat($handle);
        } finally {
            @\fclose($handle);
        }
        if (!\is_string($raw)) {
            return null;
        }
        if (\strlen($raw) > self::MAX_INSTANCE_CONFIG_BYTES) {
            throw new \RuntimeException('Active WLS instance configuration size is invalid.');
        }
        if (\strlen($raw) !== $size || !\is_array($handleAfter)) {
            return null;
        }
        $this->assertRegularInstanceConfigStat($handleAfter);
        \clearstatcache(true, $path);
        $pathAfter = @\lstat($path);
        if (!\is_array($pathAfter)) {
            return null;
        }
        $this->assertRegularInstanceConfigStat($pathAfter);
        if (!$this->sameInstanceConfigSnapshot($pathBefore, $handleBefore)
            || !$this->sameInstanceConfigSnapshot($handleBefore, $handleAfter)
            || !$this->sameInstanceConfigSnapshot($handleAfter, $pathAfter)
        ) {
            return null;
        }

        return $raw;
    }

    /** @param array<string|int, mixed> $stat */
    private function assertRegularInstanceConfigStat(array $stat): void
    {
        $type = ((int)($stat['mode'] ?? 0)) & 0170000;
        if ($type !== 0100000) {
            throw new \RuntimeException('Active WLS instance configuration is unsafe.');
        }
        if ((int)($stat['size'] ?? -1) > self::MAX_INSTANCE_CONFIG_BYTES) {
            throw new \RuntimeException('Active WLS instance configuration size is invalid.');
        }
    }

    /**
     * @param array<string|int, mixed> $left
     * @param array<string|int, mixed> $right
     */
    private function sameInstanceConfigSnapshot(array $left, array $right): bool
    {
        foreach (['dev', 'ino', 'mode', 'size', 'mtime', 'ctime'] as $field) {
            if (!\array_key_exists($field, $left)
                || !\array_key_exists($field, $right)
                || (int)$left[$field] !== (int)$right[$field]
            ) {
                return false;
            }
        }

        return true;
    }

    /** @return array<string,mixed> */
    private function readyStatus(string $instanceName, array $instanceConfig): array
    {
        $response = $this->gateway->getStatus($instanceName, 5.0);
        $data = \is_array($response['data'] ?? null) ? $response['data'] : [];
        if (($response['success'] ?? false) !== true
            || ($data['running'] ?? false) !== true
            || ($data['shutting_down'] ?? false) === true
            || (string)($data['policy_state'] ?? '') !== 'active'
            || (\is_array($data['recovery_quarantine'] ?? null) && $data['recovery_quarantine'] !== [])
        ) {
            throw new \RuntimeException('WLS instance is not in a clean running state for TLS verification.');
        }
        $masterPid = (int)($instanceConfig['master_pid'] ?? 0);
        if ($masterPid <= 0 || !Processer::isRunningByPid($masterPid)) {
            throw new \RuntimeException('WLS Master identity is not live.');
        }

        return $data;
    }

    /** @return list<array<string,mixed>> */
    private function readyWorkers(array $status, array $instanceConfig): array
    {
        $instances = $status['services']['worker']['instances'] ?? [];
        if (!\is_array($instances)) {
            throw new \RuntimeException('WLS Worker status is unavailable.');
        }
        $workers = [];
        $policyDigest = (string)($status['policy_digest'] ?? '');
        foreach ($instances as $instance) {
            if (!\is_array($instance)) {
                continue;
            }
            $metadata = \is_array($instance['metadata'] ?? null) ? $instance['metadata'] : [];
            $homepage = \is_array($metadata['homepage_fpc'] ?? null) ? $metadata['homepage_fpc'] : [];
            if ((string)($instance['state'] ?? '') !== 'ready'
                || (int)($instance['pid'] ?? 0) <= 0
                || (int)($instance['port'] ?? 0) <= 0
                || (string)($metadata['policy_digest'] ?? '') !== $policyDigest
                || ($homepage['hit'] ?? false) !== true
                || (string)($homepage['source'] ?? '') !== 'process'
            ) {
                throw new \RuntimeException('Every TLS Worker must be READY with Process FPC HIT and the active policy.');
            }
            $workers[] = $instance;
        }
        \usort($workers, static fn(array $left, array $right): int =>
            ((int)($left['instance_id'] ?? 0)) <=> ((int)($right['instance_id'] ?? 0))
        );
        $desired = (int)($status['desired_state']['worker'] ?? $instanceConfig['count'] ?? 0);
        if ($desired <= 0 || \count($workers) !== $desired) {
            throw new \RuntimeException('READY Worker count does not match desired state.');
        }

        return $workers;
    }

    /** @return array<string,mixed> */
    private function buildScope(
        string $instanceName,
        array $instanceConfig,
        array $status,
        array $workers,
    ): array {
        $runtimeSelection = \is_array($instanceConfig['runtime_selection'] ?? null)
            ? $instanceConfig['runtime_selection']
            : [];
        $topology = \strtolower(\trim((string)($runtimeSelection['effective_topology'] ?? '')));
        if (!\in_array($topology, ['direct', 'dispatcher'], true)) {
            throw new \RuntimeException('Active WLS topology is not explicit.');
        }
        $this->usePublicTlsDataPath = $topology === 'dispatcher';
        $policyDigest = \strtolower((string)($status['policy_digest'] ?? ''));
        if (!$this->isSha256($policyDigest)) {
            throw new \RuntimeException('Active WLS policy digest is invalid.');
        }
        $sniSha256 = \hash('sha256', $this->publicHost($instanceConfig));
        $certificateSha256 = $this->certificateFingerprint($this->certificatePath($instanceConfig));
        $this->expectedCertificateSha256 = $certificateSha256;
        $scope = [
            'instance_name' => $instanceName,
            'mechanism' => 'php86_external_stateful_cache',
            'transport' => 'tcp',
            'topology' => $topology,
            'worker_count' => \count($workers),
            'policy_digest' => $policyDigest,
            'sni_sha256' => $sniSha256,
            'certificate_sha256' => $certificateSha256,
        ];
        $scope['instance_scope_sha256'] = \hash('sha256', $this->canonicalJson($scope));

        return $scope;
    }

    private function publicHost(array $instanceConfig): string
    {
        $host = \strtolower(\rtrim(\trim((string)(
            $instanceConfig['public_host'] ?? $instanceConfig['ssl_domain'] ?? ''
        )), '.'));
        if ($host === '' || \strlen($host) > 253 || \str_contains($host, ':')) {
            throw new \RuntimeException('Active WLS public TLS host is invalid.');
        }

        return $host;
    }

    private function mainPort(array $instanceConfig): int
    {
        $port = (int)($instanceConfig['main_port'] ?? $instanceConfig['port'] ?? 0);
        if ($port <= 0 || $port > 65535) {
            throw new \RuntimeException('Active WLS public TLS port is invalid.');
        }

        return $port;
    }

    private function certificatePath(array $instanceConfig): string
    {
        $path = \trim((string)($instanceConfig['ssl_cert'] ?? ''));
        if ($path === '') {
            throw new \RuntimeException('Active WLS TLS certificate path is empty.');
        }
        $absolute = \preg_match('/^(?:[A-Za-z]:[\\\\\/]|[\\\\\/]{2}|\/)/D', $path) === 1;
        if (!$absolute) {
            $path = \rtrim((string)\constant('BP'), '/\\') . DIRECTORY_SEPARATOR
                . \str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
        }
        if (!\is_file($path) || \is_link($path)) {
            throw new \RuntimeException('Active WLS TLS certificate is missing or unsafe.');
        }

        return $path;
    }

    private function certificateFingerprint(string $path): string
    {
        $certificate = @\openssl_x509_read('file://' . $path);
        if ($certificate === false) {
            $pem = @\file_get_contents($path);
            $certificate = \is_string($pem) ? @\openssl_x509_read($pem) : false;
        }
        if ($certificate === false) {
            throw new \RuntimeException('Unable to parse the active TLS certificate.');
        }
        $fingerprint = @\openssl_x509_fingerprint($certificate, 'sha256');
        if (!\is_string($fingerprint) || !$this->isSha256(\strtolower($fingerprint))) {
            throw new \RuntimeException('Unable to fingerprint the active TLS certificate.');
        }

        return \strtolower($fingerprint);
    }

    /**
     * @return array<string,mixed>
     */
    private function runPairPhase(
        string $instanceName,
        array $workers,
        string $sni,
        int $originPort,
        int $rounds,
        string $relation,
        float $connectTimeoutSeconds,
        ?array $beforeSnapshot = null,
    ): array {
        $beforeSnapshot ??= $this->snapshotWorkers(
            $instanceName,
            $workers,
            $sni,
            $originPort,
            $connectTimeoutSeconds,
        );
        $uniquePorts = \array_values(\array_unique(\array_map(
            static fn(array $worker): int => (int)($worker['port'] ?? 0),
            $workers,
        )));
        $deterministic = !$this->usePublicTlsDataPath
            && \count($uniquePorts) === \count($workers);
        $accepted = 0;
        $attempts = 0;
        $httpSuccess = 0;
        $resumedPairs = 0;
        $crossWorkerPairs = 0;
        $resumedTlsMs = [];
        $maximumAttempts = $deterministic ? $rounds : \max(512, $rounds * 16);
        while ($accepted < $rounds && $attempts < $maximumAttempts) {
            $index = $attempts % \count($workers);
            $source = $workers[$index];
            $target = $relation === 'same'
                ? $source
                : $workers[($index + 1) % \count($workers)];
            $sourcePort = $deterministic ? (int)$source['port'] : $originPort;
            $targetPort = $deterministic ? (int)$target['port'] : $originPort;
            $attempts++;
            $fresh = $this->connect(
                $sourcePort,
                $sni,
                $originPort,
                null,
                $connectTimeoutSeconds,
            );
            if (!$fresh['healthy']) {
                throw new \RuntimeException('TLS verifier fresh HTTP health request failed.');
            }
            $session = $fresh['session'] ?? null;
            if (!$this->isResumableSession($session)) {
                throw new \RuntimeException('TLS verifier fresh handshake did not produce a resumable Session.');
            }
            if (!$deterministic && $relation === 'same') {
                // The Dispatcher may use strict rotation, SNI affinity, or
                // load-aware selection. Cycle every possible connection
                // offset, then accept only an observed Worker-ID relation.
                $paddingConnections = ($attempts - 1) % \count($workers);
                for ($padding = 0; $padding < $paddingConnections; $padding++) {
                    $advance = $this->connect(
                        $originPort,
                        $sni,
                        $originPort,
                        null,
                        $connectTimeoutSeconds,
                    );
                    if (!$advance['healthy']) {
                        throw new \RuntimeException(
                            'TLS verifier Dispatcher offset-sampling health request failed.'
                        );
                    }
                }
            }
            $resumed = $this->connect(
                $targetPort,
                $sni,
                $originPort,
                $session,
                $connectTimeoutSeconds,
            );
            unset($session);
            if (!$resumed['healthy']) {
                throw new \RuntimeException('TLS verifier resumed HTTP health request failed.');
            }
            $validWorkerIds = (int)$fresh['worker_id'] > 0 && (int)$resumed['worker_id'] > 0;
            if ($deterministic
                && ((int)$fresh['worker_id'] !== (int)($source['instance_id'] ?? 0)
                    || (int)$resumed['worker_id'] !== (int)($target['instance_id'] ?? 0))
            ) {
                throw new \RuntimeException('Private TLS Worker endpoint identity changed during verification.');
            }
            $sameWorker = $validWorkerIds
                && (int)$fresh['worker_id'] === (int)$resumed['worker_id'];
            $qualifies = $validWorkerIds && ($relation === 'same' ? $sameWorker : !$sameWorker);
            if (!$qualifies) {
                continue;
            }
            $accepted++;
            $httpSuccess += ($fresh['healthy'] ? 1 : 0) + ($resumed['healthy'] ? 1 : 0);
            if ($resumed['session_reused'] === true) {
                $resumedPairs++;
                $resumedTlsMs[] = (float)$resumed['tls_connect_ms'];
            }
            if (!$sameWorker) {
                $crossWorkerPairs++;
            }
        }
        if ($accepted !== $rounds) {
            throw new \RuntimeException('TLS data-path sampler could not collect every requested Worker relation.');
        }
        $afterSnapshot = $this->snapshotWorkers(
            $instanceName,
            $workers,
            $sni,
            $originPort,
            $connectTimeoutSeconds,
        );
        $result = [
            'rounds' => $accepted,
            'http_success' => $httpSuccess,
            'client_resumed_pairs' => $resumedPairs,
            'cross_worker_pairs' => $crossWorkerPairs,
            'resumed_tls_ms' => $resumedTlsMs,
            'before_snapshot' => $beforeSnapshot,
            'after_snapshot' => $afterSnapshot,
        ];

        return $this->withSnapshotDeltas($result, $beforeSnapshot, $afterSnapshot);
    }

    /** @return array<string,mixed> */
    private function withSnapshotDeltas(array $phase, array $before, array $after): array
    {
        $phase['after_snapshot'] = $after;
        $phase['server_resumed_delta'] = $this->counterDelta($before, $after, 'actual_resumed');
        $phase['reuse_observation_missing_delta'] = $this->counterDelta(
            $before,
            $after,
            'reuse_observation_missing',
        );
        $phase['cache_failure_delta'] = $this->counterDelta($before, $after, 'failure');
        $phase['dropped_write_delta'] = $this->counterDelta($before, $after, 'dropped_writes');

        return $phase;
    }

    /** @return array<int,array<string,mixed>> keyed by Worker ID */
    private function snapshotWorkers(
        string $instanceName,
        array $workers,
        string $sni,
        int $originPort,
        float $connectTimeoutSeconds,
    ): array {
        $snapshot = [];
        $uniquePorts = \array_values(\array_unique(\array_map(
            static fn(array $worker): int => (int)($worker['port'] ?? 0),
            $workers,
        )));
        if (\count($uniquePorts) === \count($workers)) {
            foreach ($workers as $worker) {
                $observation = $this->connect(
                    (int)$worker['port'],
                    $sni,
                    $originPort,
                    null,
                    $connectTimeoutSeconds,
                );
                $expectedId = (int)($worker['instance_id'] ?? 0);
                if (!$observation['healthy'] || (int)$observation['worker_id'] !== $expectedId) {
                    throw new \RuntimeException('Private TLS Worker health identity does not match status.');
                }
                $snapshot[$expectedId] = $observation['server_cache'];
            }
        } else {
            $attempts = 0;
            while (\count($snapshot) < \count($workers) && $attempts++ < 256) {
                $observation = $this->connect(
                    $originPort,
                    $sni,
                    $originPort,
                    null,
                    $connectTimeoutSeconds,
                );
                $workerId = (int)$observation['worker_id'];
                if ($observation['healthy'] && $workerId > 0) {
                    $snapshot[$workerId] = $observation['server_cache'];
                }
            }
        }
        if (\count($snapshot) !== \count($workers)) {
            throw new \RuntimeException('Unable to snapshot every TLS Worker cache counter.');
        }
        \ksort($snapshot, SORT_NUMERIC);
        $expectedWorkerIds = \array_map(
            static fn(array $worker): int => (int)($worker['instance_id'] ?? 0),
            $workers,
        );
        \sort($expectedWorkerIds, SORT_NUMERIC);
        if (\array_keys($snapshot) !== $expectedWorkerIds) {
            throw new \RuntimeException('TLS cache snapshot Worker identities do not match WLS status.');
        }

        return $snapshot;
    }

    /** @return array<string,mixed> */
    private function connect(
        int $port,
        string $sni,
        int $originPort,
        mixed $resumeSession,
        float $timeoutSeconds,
    ): array {
        if ($port <= 0 || $port > 65535) {
            throw new \RuntimeException('TLS verifier received an invalid endpoint port.');
        }
        $captured = null;
        $ssl = [
            'verify_peer' => false,
            'verify_peer_name' => false,
            'allow_self_signed' => true,
            'peer_name' => $sni,
            'SNI_enabled' => true,
            'capture_peer_cert' => true,
            'crypto_method' => \STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT,
            'alpn_protocols' => 'http/1.1',
            'session_new_cb' => static function ($stream, $session) use (&$captured): void {
                $captured = $session;
            },
        ];
        if ($resumeSession !== null) {
            $ssl['session_data'] = $resumeSession;
        }
        $context = \stream_context_create(['ssl' => $ssl]);
        $started = \hrtime(true);
        $errno = 0;
        $error = '';
        $connection = @\stream_socket_client(
            'tls://127.0.0.1:' . $port,
            $errno,
            $error,
            $timeoutSeconds,
            STREAM_CLIENT_CONNECT,
            $context,
        );
        $tlsConnectMs = (\hrtime(true) - $started) / 1_000_000;
        if (!\is_resource($connection)) {
            throw new \RuntimeException('TLS verifier connection failed.');
        }
        @\stream_set_timeout($connection, (int)\ceil($timeoutSeconds));
        $request = 'GET ' . self::HEALTH_PATH . " HTTP/1.1\r\n"
            . 'Host: ' . $sni . ':' . $originPort . "\r\n"
            . "Connection: close\r\n\r\n";
        if (@\fwrite($connection, $request) !== \strlen($request)) {
            @\fclose($connection);
            throw new \RuntimeException('TLS verifier could not write the health request.');
        }
        $response = @\stream_get_contents($connection, self::MAX_HTTP_RESPONSE_BYTES + 1);
        $metadata = @\stream_get_meta_data($connection);
        $contextParameters = @\stream_context_get_params($connection);
        @\fclose($connection);
        if (!\is_string($response) || \strlen($response) > self::MAX_HTTP_RESPONSE_BYTES) {
            throw new \RuntimeException('TLS verifier health response is invalid or oversized.');
        }
        $bodyOffset = \strpos($response, "\r\n\r\n");
        $health = $bodyOffset === false
            ? null
            : \json_decode(\substr($response, $bodyOffset + 4), true);
        $crypto = \is_array($metadata) && \is_array($metadata['crypto'] ?? null)
            ? $metadata['crypto']
            : [];
        $reuseValue = $crypto['session_reused'] ?? null;
        $sessionReused = \is_bool($reuseValue)
            ? $reuseValue
            : (\is_int($reuseValue) ? $reuseValue !== 0 : null);
        $serverReuse = \is_array($health['tls_session_reuse'] ?? null)
            ? $health['tls_session_reuse']
            : [];
        $serverCache = \is_array($health['tls_session_cache'] ?? null)
            ? $health['tls_session_cache']
            : [];
        if (!\is_array($health)
            || (string)($health['instance'] ?? '') !== $this->activeInstanceName
        ) {
            throw new \RuntimeException('TLS health response belongs to a different WLS instance.');
        }
        $peerCertificate = \is_array($contextParameters)
            ? ($contextParameters['options']['ssl']['peer_certificate'] ?? null)
            : null;
        $peerFingerprint = $peerCertificate !== null
            ? @\openssl_x509_fingerprint($peerCertificate, 'sha256')
            : false;
        if (!\is_string($peerFingerprint)
            || $this->expectedCertificateSha256 === ''
            || !\hash_equals($this->expectedCertificateSha256, \strtolower($peerFingerprint))
        ) {
            throw new \RuntimeException('TLS peer certificate does not match the bound instance certificate.');
        }
        $workerConfigSha256 = \strtolower((string)($serverCache['configuration_sha256'] ?? ''));
        $workerRuntimeIdentitySha256 = \strtolower((string)(
            $serverCache['runtime_identity_sha256'] ?? ''
        ));
        if ($this->expectedCacheConfigSha256 === ''
            || !\hash_equals($this->expectedCacheConfigSha256, $workerConfigSha256)
        ) {
            throw new \RuntimeException('Active TLS Worker configuration does not match the frozen verifier config.');
        }
        if ($this->expectedRuntimeIdentitySha256 === ''
            || !\hash_equals($this->expectedRuntimeIdentitySha256, $workerRuntimeIdentitySha256)
        ) {
            throw new \RuntimeException('Active TLS Worker runtime identity does not match the verifier runtime.');
        }
        $requiredCacheCounters = [
            'failure',
            'pending_writes',
            'inflight_writes',
            'writer_pending_responses',
            'dropped_writes',
            'actual_resumed',
            'actual_full_handshake',
            'reuse_observation_missing',
        ];
        foreach ($requiredCacheCounters as $counter) {
            if (!\array_key_exists($counter, $serverCache) || !\is_numeric($serverCache[$counter])) {
                throw new \RuntimeException('TLS Worker did not expose required cache counters.');
            }
            $serverCache[$counter] = (int)$serverCache[$counter];
            if ($serverCache[$counter] < 0) {
                throw new \RuntimeException('TLS Worker exposed a negative cache counter.');
            }
        }
        $healthy = \is_array($health)
            && (string)($health['status'] ?? '') === 'healthy'
            && \str_starts_with($response, 'HTTP/1.1 200 ')
            && (string)($crypto['protocol'] ?? '') === 'TLSv1.3';

        return [
            'healthy' => $healthy,
            'worker_id' => \is_array($health) ? (int)($health['worker_id'] ?? 0) : 0,
            'session_reused' => $sessionReused,
            'session' => $captured,
            'tls_connect_ms' => \round($tlsConnectMs, 3),
            'server_reuse' => $serverReuse,
            'server_cache' => $serverCache,
        ];
    }

    private function isResumableSession(mixed $session): bool
    {
        $class = 'Openssl\\Session';

        return \is_object($session)
            && $session instanceof $class
            && \method_exists($session, 'isResumable')
            && $session->isResumable();
    }

    /** @return array{status:array<string,mixed>,workers:list<array<string,mixed>>,worker_fingerprint_changed:bool} */
    private function reloadWorkers(
        string $instanceName,
        array $instanceConfig,
        array $statusBefore,
        array $workersBefore,
    ): array {
        $this->assertControlPlaneIdle($statusBefore);
        $oldFingerprints = $this->workerIdentityMap($workersBefore);
        $oldDispatcher = $this->dispatcherIdentity($statusBefore);
        $oldEpoch = (int)($statusBefore['epoch'] ?? 0);
        $oldFullRestartCount = (int)($statusBefore['metrics']['full_restart_count'] ?? 0);
        $oldMasterPid = (int)($instanceConfig['master_pid'] ?? 0);

        $requestId = 'tls-evidence-reload-' . \getmypid() . '-' . \bin2hex(\random_bytes(8));
        $ack = $this->gateway->command(
            $instanceName,
            ControlMessage::ACTION_RELOAD_WAIT,
            ControlMessage::RELOAD_TYPE_CODE,
            ['msg_id' => $requestId],
            5.0,
        );
        $ackData = \is_array($ack['data'] ?? null) ? $ack['data'] : [];
        $operationId = \trim((string)($ackData['operation_id'] ?? ''));
        if (($ack['success'] ?? false) !== true
            || ($ack['accepted'] ?? false) !== true
            || (string)($ack['request_id'] ?? '') !== $requestId
            || $operationId === ''
        ) {
            throw new \RuntimeException('TLS reload_wait command was not explicitly accepted.');
        }
        $deadline = \microtime(true) + self::RELOAD_TIMEOUT_SECONDS;
        $sawGenerationChange = false;
        $sawOwnedOperation = false;
        do {
            SchedulerSystem::usleep(100_000);
            $latestConfig = $this->readInstanceConfig($instanceName);
            if (!\hash_equals(
                $this->instanceConfigFingerprint($instanceConfig),
                $this->instanceConfigFingerprint($latestConfig),
            )) {
                throw new \RuntimeException('WLS instance configuration drifted during reload verification.');
            }
            try {
                $status = $this->readyStatus($instanceName, $latestConfig);
            } catch (\Throwable) {
                continue;
            }
            $operation = $status['control_operation'] ?? null;
            if (!\is_array($operation)
                || !\array_key_exists('active', $operation)
                || !\array_key_exists('queued', $operation)
                || !\is_array($operation['queued'])
            ) {
                throw new \RuntimeException('WLS control-operation status became malformed during reload.');
            }
            $active = \is_array($operation['active'] ?? null) ? $operation['active'] : [];
            $queued = \is_array($operation['queued'] ?? null) ? $operation['queued'] : [];
            $ownedOperationVisible = false;
            if ($active !== []) {
                if ((string)($active['id'] ?? '') !== $operationId
                    || (string)($active['action'] ?? '') !== ControlMessage::ACTION_RELOAD_WAIT
                ) {
                    throw new \RuntimeException('A foreign active WLS operation appeared during TLS reload verification.');
                }
                $ownedOperationVisible = true;
            }
            foreach ($queued as $queuedOperation) {
                if (!\is_array($queuedOperation)
                    || (string)($queuedOperation['id'] ?? '') !== $operationId
                    || (string)($queuedOperation['action'] ?? '') !== ControlMessage::ACTION_RELOAD_WAIT
                ) {
                    throw new \RuntimeException('A foreign queued WLS operation appeared during TLS reload verification.');
                }
                $ownedOperationVisible = true;
            }
            $sawOwnedOperation = $sawOwnedOperation || $ownedOperationVisible;
            try {
                $workers = $this->readyWorkers($status, $latestConfig);
            } catch (\Throwable) {
                continue;
            }
            $newFingerprints = $this->workerIdentityMap($workers);
            $sawGenerationChange = $sawGenerationChange
                || $this->anyWorkerGenerationChanged($oldFingerprints, $newFingerprints);
            $lastOperation = \is_array($operation['last'] ?? null) ? $operation['last'] : [];
            $ownedTerminalSuccess = (string)($lastOperation['id'] ?? '') === $operationId
                && (string)($lastOperation['action'] ?? '') === ControlMessage::ACTION_RELOAD_WAIT
                && (string)($lastOperation['state'] ?? '') === 'completed'
                && ($lastOperation['success'] ?? false) === true;
            $sawOwnedOperation = $sawOwnedOperation || $ownedTerminalSuccess;
            $identitiesChanged = $this->allWorkerIdentitiesChanged($oldFingerprints, $newFingerprints);
            $controlIdle = ($status['rolling_restart_in_progress'] ?? false) === false
                && ($operation['active'] ?? null) === null
                && $operation['queued'] === [];
            $complete = $this->allWorkerIdentitiesChanged($oldFingerprints, $newFingerprints)
                && $sawOwnedOperation
                && $controlIdle
                && $ownedTerminalSuccess;
            if ($complete) {
                if ((int)($latestConfig['master_pid'] ?? 0) !== $oldMasterPid
                    || (int)($status['epoch'] ?? 0) !== $oldEpoch
                    || (int)($status['metrics']['full_restart_count'] ?? 0) !== $oldFullRestartCount
                    || $this->dispatcherIdentity($status) !== $oldDispatcher
                    || (string)($status['policy_digest'] ?? '') !== (string)($statusBefore['policy_digest'] ?? '')
                ) {
                    throw new \RuntimeException('TLS reload gate escalated beyond a Worker rolling reload.');
                }
                $publicProbe = $this->connect(
                    $this->mainPort($latestConfig),
                    $this->publicHost($latestConfig),
                    $this->mainPort($latestConfig),
                    null,
                    5.0,
                );
                if (!$publicProbe['healthy']) {
                    throw new \RuntimeException('Public WLS origin was unhealthy after reload_wait.');
                }

                return [
                    'status' => $status,
                    'workers' => $workers,
                    'worker_fingerprint_changed' => true,
                ];
            }
            if ($identitiesChanged && $sawOwnedOperation && $controlIdle && !$ownedTerminalSuccess) {
                $terminalState = (string)($lastOperation['state'] ?? 'missing');
                throw new \RuntimeException(
                    'TLS reload_wait lacked a matching successful terminal outcome: ' . $terminalState
                );
            }
        } while (\microtime(true) < $deadline);

        throw new \RuntimeException($sawGenerationChange
            ? 'TLS reload gate timed out before all Worker slots completed.'
            : ($sawOwnedOperation
                ? 'TLS reload_wait did not change any Worker generation.'
                : 'TLS reload_wait operation was never observed in authoritative status.'));
    }

    private function assertControlPlaneIdle(array $status): void
    {
        $operation = $status['control_operation'] ?? null;
        if (!\is_array($operation)
            || !\array_key_exists('active', $operation)
            || !\array_key_exists('queued', $operation)
            || !\is_array($operation['queued'])
            || $operation['active'] !== null
            || $operation['queued'] !== []
            || ($status['rolling_restart_in_progress'] ?? false) !== false
        ) {
            throw new \RuntimeException('WLS control plane is not idle for isolated TLS verification.');
        }
    }

    /** @return array<int,array<string,int|string>> */
    private function workerIdentityMap(array $workers): array
    {
        $map = [];
        foreach ($workers as $worker) {
            $metadata = \is_array($worker['metadata'] ?? null) ? $worker['metadata'] : [];
            $id = (int)($worker['instance_id'] ?? 0);
            $map[$id] = [
                'pid' => (int)($worker['tracking_pid'] ?? $worker['pid'] ?? 0),
                'launch_id' => (string)($worker['launch_id'] ?? ''),
                'lease_id' => (string)($metadata['lease_id'] ?? ''),
                'generation' => (int)($metadata['generation'] ?? 0),
            ];
        }
        \ksort($map, SORT_NUMERIC);

        return $map;
    }

    private function anyWorkerGenerationChanged(array $before, array $after): bool
    {
        foreach ($before as $id => $old) {
            if (isset($after[$id]) && (int)$after[$id]['generation'] > (int)$old['generation']) {
                return true;
            }
        }

        return false;
    }

    private function allWorkerIdentitiesChanged(array $before, array $after): bool
    {
        if (\array_keys($before) !== \array_keys($after)) {
            return false;
        }
        foreach ($before as $id => $old) {
            $new = $after[$id];
            if ((int)$new['pid'] <= 0
                || (int)$new['pid'] === (int)$old['pid']
                || (string)$new['launch_id'] === ''
                || (string)$new['launch_id'] === (string)$old['launch_id']
                || (string)$new['lease_id'] === ''
                || (string)$new['lease_id'] === (string)$old['lease_id']
                || (int)$new['generation'] <= (int)$old['generation']
            ) {
                return false;
            }
        }

        return true;
    }

    /** @return array<string,int|string> */
    private function dispatcherIdentity(array $status): array
    {
        $instances = $status['services']['dispatcher']['instances'] ?? [];
        if (!\is_array($instances) || $instances === []) {
            return [];
        }
        $instance = \reset($instances);
        if (!\is_array($instance) || (string)($instance['state'] ?? '') !== 'ready') {
            throw new \RuntimeException('Dispatcher is not READY during TLS verification.');
        }
        $metadata = \is_array($instance['metadata'] ?? null) ? $instance['metadata'] : [];

        return [
            'pid' => (int)($instance['tracking_pid'] ?? $instance['pid'] ?? 0),
            'launch_id' => (string)($instance['launch_id'] ?? ''),
            'lease_id' => (string)($metadata['lease_id'] ?? ''),
            'generation' => (int)($metadata['generation'] ?? 0),
        ];
    }

    /** @return array<string,mixed> */
    private function authorizeIsolatedMemorySidecarTermination(
        string $instanceName,
        array $instanceConfig,
        array $status,
        array $workers,
    ): array {
        $port = $this->mainPort($instanceConfig);
        if (!\str_starts_with($instanceName, 'ai-test-') || $port < 9502 || $port === 9501) {
            throw new \RuntimeException('Sidecar fault injection is restricted to isolated ai-test-* instances.');
        }
        $latestInstanceConfig = $this->readInstanceConfig($instanceName);
        if (!\hash_equals(
            $this->instanceConfigFingerprint($instanceConfig),
            $this->instanceConfigFingerprint($latestInstanceConfig),
        )) {
            throw new \RuntimeException('WLS instance configuration drifted before sidecar fault injection.');
        }
        $latestStatus = $this->readyStatus($instanceName, $latestInstanceConfig);
        $this->assertControlPlaneIdle($latestStatus);
        $this->assertCriticalProcessesUnchanged(
            $status,
            $latestStatus,
            $workers,
            $latestInstanceConfig,
        );
        $record = $this->bindManagedMemoryLease($this->validatedMemoryRecord($instanceName));
        $fingerprint = $this->memoryRecordFingerprint($record, $instanceName);
        if (!SharedStateProtocolProbe::pingWithTokenBasename(
            (string)$record['host'],
            (int)$record['port'],
            (string)$record['token_file_name'],
        )) {
            throw new \RuntimeException('Authenticated Memory sidecar PING failed before fault injection.');
        }
        $pid = (int)$record['pid'];
        if ($this->kernelListenerPid((int)$record['port']) !== $pid) {
            throw new \RuntimeException('Memory sidecar registry PID does not own the registered port.');
        }
        $this->assertFreshMemoryCommandLine($record, $instanceName);

        // Resolve every authority again immediately before the single signal.
        $latest = $this->bindManagedMemoryLease($this->validatedMemoryRecord($instanceName));
        if (!\hash_equals($fingerprint, $this->memoryRecordFingerprint($latest, $instanceName))
            || !SharedStateProtocolProbe::pingWithTokenBasename(
                (string)$latest['host'],
                (int)$latest['port'],
                (string)$latest['token_file_name'],
            )
            || $this->kernelListenerPid((int)$latest['port']) !== (int)$latest['pid']
        ) {
            throw new \RuntimeException('Memory sidecar identity drifted before fault injection.');
        }
        $this->assertFreshMemoryCommandLine($latest, $instanceName);

        return $latest;
    }

    private function shutdownAuthorizedMemorySidecar(array $authorized, string $instanceName): void
    {
        $latest = $this->bindManagedMemoryLease($this->validatedMemoryRecord($instanceName));
        if (!\hash_equals(
            $this->memoryRecordFingerprint($authorized, $instanceName),
            $this->memoryRecordFingerprint($latest, $instanceName),
        )
            || $this->kernelListenerPid((int)$latest['port']) !== (int)$latest['pid']
            || !SharedStateProtocolProbe::pingWithTokenBasename(
                (string)$latest['host'],
                (int)$latest['port'],
                (string)$latest['token_file_name'],
            )
        ) {
            throw new \RuntimeException('Memory sidecar authorization changed immediately before termination.');
        }
        $this->assertFreshMemoryCommandLine($latest, $instanceName);
        if (!SharedStateProtocolProbe::shutdownWithTokenBasename(
            (string)$latest['host'],
            (int)$latest['port'],
            (string)$latest['token_file_name'],
            null,
            ['server' => true],
        )) {
            throw new \RuntimeException('Authenticated Memory sidecar shutdown was refused.');
        }
    }

    /** @return array<string,mixed> */
    private function bindManagedMemoryLease(array $record): array
    {
        $processName = \trim((string)($record['process_name'] ?? ''));
        $pid = (int)$record['pid'];
        $expectedNameToken = '--name=' . $processName;
        $discovered = Processer::getProcessRecordByPid($pid);
        $leasePname = \trim((string)($discovered['pname'] ?? ''));
        $leaseLaunchId = \trim((string)($discovered['launch_id'] ?? ''));
        $canonicalPname = $expectedNameToken . ' --launch-id=' . $leaseLaunchId;
        if ((int)($discovered['pid'] ?? 0) !== $pid
            || $processName === ''
            || !\preg_match('/\Asidecar-[a-f0-9]{32}\z/D', $leaseLaunchId)
            || !\hash_equals($canonicalPname, $leasePname)
            || \strcasecmp((string)($discovered['process_name'] ?? ''), $processName) !== 0
        ) {
            throw new \RuntimeException('Memory sidecar managed-process lease discovery is invalid.');
        }
        $lease = Processer::getManagedProcessLeaseRecord($pid, $leasePname);
        $leasePname = \trim((string)($lease['pname'] ?? ''));
        $leaseLaunchId = \trim((string)($lease['launch_id'] ?? ''));
        $expectedProcessName = \trim(Processer::getProcessCommandLine($pid, true));
        if ((int)($lease['pid'] ?? 0) !== $pid
            || $leasePname === ''
            || $leaseLaunchId === ''
            || $expectedProcessName === ''
            || !\hash_equals($canonicalPname, $leasePname)
            || !\hash_equals((string)$discovered['launch_id'], $leaseLaunchId)
            || \strcasecmp((string)($lease['process_name'] ?? ''), $processName) !== 0
        ) {
            throw new \RuntimeException('Memory sidecar managed-process lease is incomplete or mismatched.');
        }
        $record['_managed_lease_pname'] = $leasePname;
        $record['_managed_lease_launch_id'] = $leaseLaunchId;
        $record['_managed_expected_pname'] = $leasePname;
        $record['_managed_expected_process_name'] = $expectedProcessName;

        return $record;
    }

    /** @return array<string,mixed> */
    private function validatedMemoryRecord(string $instanceName): array
    {
        $record = $this->sharedRegistry->getRecord('memory_server');
        $consumers = \is_array($record['consumers'] ?? null) ? $record['consumers'] : [];
        if (\array_keys($consumers) !== [$instanceName]
            || !\is_array($consumers[$instanceName] ?? null)
            || (string)($consumers[$instanceName]['owner_type'] ?? '') !== 'instance'
            || (string)($record['role'] ?? '') !== 'memory_server'
            || ($record['shared_service'] ?? false) !== true
            || !$this->isLoopbackHost((string)($record['host'] ?? ''))
            || (int)($record['port'] ?? 0) <= 0
            || (int)($record['pid'] ?? 0) <= 0
            || (string)($record['process_name'] ?? '') === ''
            || (string)($record['token_file_name'] ?? '') === ''
            || \basename((string)$record['token_file_name']) !== (string)$record['token_file_name']
            || !Processer::isRunningByPid((int)$record['pid'])
        ) {
            throw new \RuntimeException('Memory sidecar is not exclusively and safely owned by this test instance.');
        }
        $ensuredAt = \strtotime((string)($record['last_ensured_at'] ?? '')) ?: 0;
        if ($ensuredAt <= 0 || \time() - $ensuredAt > self::PROCESS_IDENTITY_MAX_AGE_SECONDS) {
            throw new \RuntimeException('Memory sidecar consumer lease observation is stale.');
        }

        return $record;
    }

    private function assertFreshMemoryCommandLine(array $record, string $instanceName): void
    {
        $pid = (int)$record['pid'];
        $command = Processer::getProcessCommandLine($pid, true);
        $tokens = $this->tokenizeCommandLine($command);
        $scriptIndex = null;
        foreach ($tokens as $index => $token) {
            $normalized = \strtolower(\str_replace('\\', '/', $token));
            if (\str_ends_with($normalized, '/app/code/weline/server/bin/session_server.php')) {
                $scriptIndex = $index;
                break;
            }
        }
        $processName = (string)$record['process_name'];
        $serviceInstance = (string)($record['instance_name'] ?? '');
        $host = (string)$record['host'];
        $port = (int)$record['port'];
        if ($command === ''
            || $scriptIndex === null
            || !isset($tokens[$scriptIndex + 1], $tokens[$scriptIndex + 2], $tokens[$scriptIndex + 3])
            || !\hash_equals(\strtolower($host), \strtolower($tokens[$scriptIndex + 1]))
            || !\ctype_digit($tokens[$scriptIndex + 2])
            || (int)$tokens[$scriptIndex + 2] !== $port
            || !\hash_equals($serviceInstance, $tokens[$scriptIndex + 3])
            || !$this->hasExactCommandToken($tokens, '--role=memory_server', true)
            || !$this->hasExactCommandToken($tokens, '--shared-service=1', true)
            || !$this->hasExactCommandToken($tokens, '--name=' . $processName, true)
            || (isset($record['_managed_lease_launch_id'])
                && !$this->hasExactCommandToken(
                    $tokens,
                    '--launch-id=' . (string)$record['_managed_lease_launch_id'],
                    false,
                ))
            || !$this->hasExactCommandToken($tokens, '--bootstrap-instance=' . $instanceName, true)
            || !$this->hasExactCommandToken($tokens, '--instance-name=' . $serviceInstance, false)
            || !$this->hasExactCommandToken(
                $tokens,
                '--token-file-name=' . (string)$record['token_file_name'],
                false,
            )
        ) {
            throw new \RuntimeException('Fresh Memory sidecar command line did not match the registry identity.');
        }
    }

    /** @return list<string> */
    private function tokenizeCommandLine(string $command): array
    {
        if ($command === '') {
            return [];
        }
        \preg_match_all('/"([^"]*)"|\'([^\']*)\'|([^\\s]+)/', $command, $matches, PREG_SET_ORDER);
        $tokens = [];
        foreach ($matches as $match) {
            foreach ([1, 2, 3] as $index) {
                if (!isset($match[$index]) || $match[$index] === '') {
                    continue;
                }
                $tokens[] = \trim((string)$match[$index], " \t\n\r\0\x0B\"'");
                break;
            }
        }

        return $tokens;
    }

    /** @param list<string> $tokens */
    private function hasExactCommandToken(array $tokens, string $expected, bool $caseInsensitive): bool
    {
        foreach ($tokens as $token) {
            if ($caseInsensitive
                ? \strcasecmp($token, $expected) === 0
                : \hash_equals($expected, $token)
            ) {
                return true;
            }
        }

        return false;
    }

    private function waitForMemorySidecarExit(array $previous): void
    {
        $pid = (int)($previous['pid'] ?? 0);
        $expectedProcessName = (string)($previous['_managed_expected_process_name'] ?? '');
        $expectedLaunchId = (string)($previous['_managed_lease_launch_id'] ?? '');
        $expectedPname = (string)($previous['_managed_expected_pname'] ?? '');
        if ($pid <= 0
            || $expectedProcessName === ''
            || !\preg_match('/\Asidecar-[a-f0-9]{32}\z/D', $expectedLaunchId)
            || $expectedPname === ''
        ) {
            throw new \RuntimeException('Previous Memory sidecar exit identity is incomplete.');
        }
        $deadline = \microtime(true) + 5.0;
        do {
            $probe = Processer::probeManagedProcessIdentity(
                $pid,
                $expectedProcessName,
                $expectedLaunchId,
                $expectedPname,
                true,
            );
            if (\in_array(
                (string)($probe['state'] ?? Processer::PROCESS_STATE_UNKNOWN),
                [Processer::PROCESS_STATE_EXITED, Processer::PROCESS_STATE_IDENTITY_MISMATCH],
                true,
            )) {
                return;
            }
            SchedulerSystem::usleep(50_000);
        } while (\microtime(true) < $deadline);

        throw new \RuntimeException(
            'Previous Memory sidecar launch generation remained active before fault traffic began.'
        );
    }

    private function memoryRecordFingerprint(array $record, string $instanceName): string
    {
        return \hash('sha256', $this->canonicalJson([
            'host' => (string)($record['host'] ?? ''),
            'port' => (int)($record['port'] ?? 0),
            'pid' => (int)($record['pid'] ?? 0),
            'process_name' => (string)($record['process_name'] ?? ''),
            'instance_name' => (string)($record['instance_name'] ?? ''),
            'token_file_name' => (string)($record['token_file_name'] ?? ''),
            'consumer' => $instanceName,
            'managed_lease_pname_sha256' => \hash(
                'sha256',
                (string)($record['_managed_lease_pname'] ?? ''),
            ),
            'managed_lease_launch_id' => (string)($record['_managed_lease_launch_id'] ?? ''),
            'managed_expected_pname' => (string)($record['_managed_expected_pname'] ?? ''),
            'managed_expected_process_name_sha256' => \hash(
                'sha256',
                (string)($record['_managed_expected_process_name'] ?? ''),
            ),
        ]));
    }

    /** @return array<string,mixed> */
    private function waitForMemorySidecarRecovery(string $instanceName, array $previous): array
    {
        $previousLaunchId = \trim((string)($previous['_managed_lease_launch_id'] ?? ''));
        if (!\preg_match('/\Asidecar-[a-f0-9]{32}\z/D', $previousLaunchId)) {
            throw new \RuntimeException('Previous Memory sidecar generation identity is invalid.');
        }
        $deadline = \microtime(true) + self::SIDECAR_RECOVERY_TIMEOUT_SECONDS;
        $lastFailure = 'no recovered sidecar candidate was observed';
        do {
            SchedulerSystem::usleep(100_000);
            try {
                $record = $this->bindManagedMemoryLease($this->validatedMemoryRecord($instanceName));
                if (!\hash_equals(
                    $previousLaunchId,
                    (string)$record['_managed_lease_launch_id'],
                )
                    && $this->kernelListenerPid((int)$record['port']) === (int)$record['pid']
                    && SharedStateProtocolProbe::pingWithTokenBasename(
                        (string)$record['host'],
                        (int)$record['port'],
                        (string)$record['token_file_name'],
                    )
                ) {
                    $this->assertFreshMemoryCommandLine($record, $instanceName);
                    return $record;
                }
                $lastFailure = 'candidate retained the previous launch generation or failed its live checks';
            } catch (\Throwable $throwable) {
                $lastFailure = \substr(
                    \preg_replace('/\s+/', ' ', $throwable->getMessage()) ?: 'recovery check failed',
                    0,
                    240,
                );
            }
        } while (\microtime(true) < $deadline);

        throw new \RuntimeException(
            'Memory sidecar did not recover with a new authenticated generation: ' . $lastFailure
        );
    }

    private function kernelListenerPid(int $port): int
    {
        Processer::clearPortCache($port);

        return Processer::getProcessIdByPort($port);
    }

    /** @return array<int,array<string,mixed>> */
    private function waitForCacheDrain(
        string $instanceName,
        array $workers,
        string $sni,
        int $originPort,
        float $connectTimeoutSeconds,
    ): array {
        $deadline = \microtime(true) + self::SIDECAR_RECOVERY_TIMEOUT_SECONDS;
        do {
            $snapshot = $this->snapshotWorkers(
                $instanceName,
                $workers,
                $sni,
                $originPort,
                $connectTimeoutSeconds,
            );
            if ($this->sumSnapshotCounter($snapshot, 'pending_writes') === 0
                && $this->sumSnapshotCounter($snapshot, 'inflight_writes') === 0
                && $this->sumSnapshotCounter($snapshot, 'writer_pending_responses') === 0
            ) {
                return $snapshot;
            }
            SchedulerSystem::usleep(100_000);
        } while (\microtime(true) < $deadline);

        throw new \RuntimeException('TLS Session cache did not drain after sidecar recovery.');
    }

    private function assertCriticalProcessesUnchanged(
        array $before,
        array $after,
        array $workers,
        array $instanceConfig,
    ): void {
        $afterWorkers = $this->readyWorkers($after, $instanceConfig);
        if ($this->workerIdentityMap($workers) !== $this->workerIdentityMap($afterWorkers)
            || $this->dispatcherIdentity($before) !== $this->dispatcherIdentity($after)
            || (int)($before['epoch'] ?? 0) !== (int)($after['epoch'] ?? 0)
            || (int)($before['metrics']['full_restart_count'] ?? 0)
                !== (int)($after['metrics']['full_restart_count'] ?? 0)
        ) {
            throw new \RuntimeException('Sidecar fault changed a critical WLS process or epoch.');
        }
    }

    private function instanceConfigFingerprint(array $instanceConfig): string
    {
        $runtimeSelection = \is_array($instanceConfig['runtime_selection'] ?? null)
            ? $instanceConfig['runtime_selection']
            : [];

        return \hash('sha256', $this->canonicalJson([
            'instance_name' => (string)($instanceConfig['instance_name'] ?? ''),
            'master_pid' => (int)($instanceConfig['master_pid'] ?? 0),
            'main_port' => $this->mainPort($instanceConfig),
            'public_host' => $this->publicHost($instanceConfig),
            'ssl_cert' => $this->certificatePath($instanceConfig),
            'worker_count' => (int)($instanceConfig['count'] ?? 0),
            'effective_topology' => (string)($runtimeSelection['effective_topology'] ?? ''),
        ]));
    }

    private function counterDelta(array $before, array $after, string $counter): int
    {
        if (\array_keys($before) !== \array_keys($after)) {
            throw new \RuntimeException('TLS cache counter Worker identities changed within a phase.');
        }
        $delta = 0;
        foreach ($after as $workerId => $counters) {
            $previous = $before[$workerId] ?? null;
            if (!\is_array($counters)
                || !\is_array($previous)
                || !\array_key_exists($counter, $counters)
                || !\array_key_exists($counter, $previous)
                || !\is_int($counters[$counter])
                || !\is_int($previous[$counter])
            ) {
                throw new \RuntimeException('TLS cache counter snapshot is incomplete.');
            }
            if ($counters[$counter] < $previous[$counter]) {
                throw new \RuntimeException('TLS cache counter reset during verification.');
            }
            $delta += $counters[$counter] - $previous[$counter];
        }

        return $delta;
    }

    private function sumSnapshotCounter(array $snapshot, string $counter): int
    {
        $sum = 0;
        foreach ($snapshot as $counters) {
            if (!\is_array($counters)
                || !\array_key_exists($counter, $counters)
                || !\is_int($counters[$counter])
                || $counters[$counter] < 0
            ) {
                throw new \RuntimeException('TLS cache final counter snapshot is incomplete.');
            }
            $sum += $counters[$counter];
        }

        return $sum;
    }

    /** @param list<float> $samples */
    private function percentile(array $samples, float $percentile): float
    {
        \sort($samples, SORT_NUMERIC);
        $index = \max(0, (int)\ceil(\count($samples) * $percentile) - 1);

        return \round((float)($samples[$index] ?? 0.0), 3);
    }

    private function isLoopbackHost(string $host): bool
    {
        return \in_array(\strtolower(\trim($host)), ['127.0.0.1', '::1', 'localhost'], true);
    }

    private function isSha256(string $value): bool
    {
        return \preg_match('/^[a-f0-9]{64}$/D', \strtolower($value)) === 1;
    }

    /** @param array<string,mixed> $value */
    private function canonicalJson(array $value): string
    {
        \ksort($value, SORT_STRING);
        foreach ($value as &$item) {
            if (\is_array($item)) {
                $item = $this->canonicalize($item);
            }
        }
        unset($item);

        return \json_encode(
            $value,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );
    }

    private function canonicalize(array $value): array
    {
        if (!\array_is_list($value)) {
            \ksort($value, SORT_STRING);
        }
        foreach ($value as &$item) {
            if (\is_array($item)) {
                $item = $this->canonicalize($item);
            }
        }
        unset($item);

        return $value;
    }
}
