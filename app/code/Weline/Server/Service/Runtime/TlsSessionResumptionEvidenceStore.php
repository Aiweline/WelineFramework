<?php

declare(strict_types=1);

namespace Weline\Server\Service\Runtime;

use Weline\Framework\App\Env;

/**
 * Immutable, non-secret evidence for the PHP 8.6 external stateful TLS cache.
 *
 * Only the live verifier can reach the private writer. Callers may select
 * bounded verification thresholds, but cannot submit scope or proof facts.
 * Session DER, Session IDs, cache keys and control tokens are never persisted.
 */
final class TlsSessionResumptionEvidenceStore
{
    public const SCHEMA_VERSION = 2;
    public const KIND = 'tcp_tls_external_stateful_session_resumption';

    private const MAX_EVIDENCE_FILES = 64;
    private const MAX_PERFORMANCE_REPORTS = 16;
    private const MAX_EVIDENCE_BYTES = 1_048_576;
    private const PRODUCTION_RESUMPTION_TLS_P95_LIMIT_MS = 50.0;

    /** @var list<string> */
    private const INTEGRATION_FILES = [
        'app/code/Weline/Server/Console/Server/Benchmark.php',
        'app/code/Weline/Framework/System/Process/Driver/AbstractProcessDriver.php',
        'app/code/Weline/Framework/System/Process/Driver/LinuxProcessDriver.php',
        'app/code/Weline/Framework/System/Process/Driver/WindowsProcessDriver.php',
        'app/code/Weline/Framework/System/Process/Processer.php',
        'app/code/Weline/Server/Service/Runtime/RuntimeStrategyResolver.php',
        'app/code/Weline/Server/Service/Runtime/TlsSessionCacheClient.php',
        'app/code/Weline/Server/Service/Runtime/TlsSessionCacheConfig.php',
        'app/code/Weline/Server/Service/Runtime/TlsSessionCacheRuntime.php',
        'app/code/Weline/Server/Service/Runtime/TlsSessionCacheTokenState.php',
        'app/code/Weline/Server/Service/ServiceOrchestrator.php',
        'app/code/Weline/Server/Service/SharedStateServiceManager.php',
        'app/code/Weline/Server/Service/SharedStateProtocolProbe.php',
        'app/code/Weline/Server/Service/SharedStateServiceRegistry.php',
        'app/code/Weline/Server/Service/WlsWorkerGlobals.php',
        'app/code/Weline/Server/Session/Server/SessionServer.php',
        'app/code/Weline/Server/Session/Server/TlsSessionCacheStore.php',
        'app/code/Weline/Server/bin/session_server.php',
        'app/code/Weline/Server/bin/worker_ssl.php',
    ];

    /** @var list<string> */
    private const VERIFIER_FILES = [
        'app/code/Weline/Server/Service/Runtime/TlsSessionResumptionEvidenceStore.php',
        'app/code/Weline/Server/Service/Runtime/TlsSessionResumptionLiveVerifier.php',
    ];

    /** @var list<string> */
    private const DOCUMENT_KEYS = [
        'schema_version',
        'kind',
        'captured_at',
        'runtime',
        'bindings',
        'config',
        'verification',
        'scope',
        'proof',
        'performance_reports',
        'evidence_sha256',
    ];

    /** @var list<string> */
    private const RUNTIME_KEYS = [
        'os_family',
        'os_release',
        'os_build',
        'os_architecture',
        'process_architecture',
        'native_execution_verified',
        'native_execution_source',
        'windows_native_architecture',
        'windows_native_architecture_source',
        'php_version',
        'php_version_id',
        'php_version_line',
        'php_release_channel',
        'php_release_stage',
        'php_prerelease',
        'php_binary_sha256',
        'openssl_version_text',
        'openssl_version_number',
        'openssl_extension_version',
        'openssl_extension_linkage',
        'openssl_extension_binary_sha256',
    ];

    /** @var list<string> */
    private const BINDING_KEYS = [
        'integration_sha256',
        'verifier_sha256',
        'config_sha256',
    ];

    /** @var list<string> */
    private const CONFIG_KEYS = [
        'mode',
        'enabled',
        'timeout_seconds',
        'num_tickets',
        'local_cache_size',
        'max_session_bytes',
        'max_entries',
        'max_total_bytes',
        'callback_timeout_ms',
        'ready_timeout_ms',
        'reconnect_cooldown_ms',
        'context_epoch',
    ];

    /** @var list<string> */
    private const VERIFIER_OPTION_KEYS = [
        'same_worker_rounds',
        'cross_worker_rounds',
        'reload_rounds',
        'fault_rounds',
        'post_recovery_rounds',
        'connect_timeout_ms',
        'resumption_tls_p95_limit_ms',
        'performance_reports',
    ];

    /** @var list<string> */
    private const VERIFICATION_KEYS = [
        'same_worker_rounds',
        'cross_worker_rounds',
        'reload_rounds',
        'fault_rounds',
        'post_recovery_rounds',
        'connect_timeout_ms',
        'resumption_tls_p95_limit_ms',
    ];

    /** @var list<string> */
    private const FACT_KEYS = [
        'captured_at',
        'scope',
        'proof',
    ];

    /** @var list<string> */
    private const SCOPE_KEYS = [
        'instance_name',
        'mechanism',
        'transport',
        'topology',
        'worker_count',
        'policy_digest',
        'sni_sha256',
        'certificate_sha256',
        'instance_scope_sha256',
    ];

    /** @var list<string> */
    private const PROOF_KEYS = [
        'same_worker_rounds',
        'same_worker_http_success',
        'same_worker_client_resumed_pairs',
        'same_worker_server_resumed_delta',
        'initial_rounds',
        'initial_http_success',
        'initial_client_resumed_pairs',
        'initial_server_resumed_delta',
        'initial_cross_worker_pairs',
        'post_reload_rounds',
        'post_reload_http_success',
        'post_reload_client_resumed_pairs',
        'post_reload_server_resumed_delta',
        'post_reload_cross_worker_pairs',
        'reload_worker_fingerprint_changed',
        'reload_preserved_session_resumed',
        'reload_preserved_session_server_resumed_delta',
        'sidecar_fault_rounds',
        'sidecar_fault_http_success',
        'sidecar_fault_client_resumed_pairs',
        'sidecar_fault_server_resumed_delta',
        'sidecar_fault_cross_worker_pairs',
        'sidecar_fault_method',
        'sidecar_generation_changed',
        'post_recovery_rounds',
        'post_recovery_http_success',
        'post_recovery_client_resumed_pairs',
        'post_recovery_server_resumed_delta',
        'post_recovery_cross_worker_pairs',
        'reuse_observation_missing',
        'cache_failure_delta',
        'dropped_write_delta',
        'pending_writes_final',
        'inflight_writes_final',
        'resumption_tls_p95_ms',
        'resumption_tls_p95_limit_ms',
    ];

    /** @var list<string> */
    private const PERFORMANCE_REPORT_KEYS = [
        'path',
        'sha256',
        'generated_at',
        'benchmark_valid',
        'quality_gate_passed',
        'integration_sha256',
        'verifier_sha256',
        'instance_name',
        'policy_digest',
        'target_host_sha256',
        'os_family',
        'process_architecture',
        'php_version',
        'topology',
        'worker_count',
        'http_version',
        'requests',
        'success_count',
        'qps',
        'request_p95_ms',
        'tls_handshake_p95_ms',
    ];

    /** @var list<string> */
    private const FORBIDDEN_EVIDENCE_KEYS = [
        'der',
        'session_der',
        'session_data',
        'session_id',
        'saved_session_id',
        'token',
        'control_token',
        'master_token',
        'cache_key',
        'key_pem',
    ];

    /** @var list<string> */
    private const REQUIRED_PRODUCTION_PLATFORM_PROFILES = [
        'darwin_arm64_native_direct',
        'linux_arm64_native_direct',
        'windows_11_arm64_native_dispatcher',
    ];

    private static ?array $runtimeIdentityCache = null;

    public function __construct(private readonly ?string $rootDirectory = null)
    {
    }

    /**
     * Run the durable verifier and privately publish its freshly collected
     * facts. There is deliberately no API that accepts caller-supplied proof.
     *
     * @param array<string, mixed> $options
     * @return array{path:string,assessment:array<string,mixed>}
     */
    public function verifyAndPublish(string $instanceName, array $options = []): array
    {
        $instanceName = \trim($instanceName);
        $this->assertInstanceName($instanceName);
        $normalizedOptions = $this->normalizeVerifierOptions($options);

        $initialConfig = $this->freshTlsSessionCacheConfig();
        if (!$initialConfig->enabled()) {
            throw new \RuntimeException('Cannot verify TLS resumption while the external cache is disabled.');
        }
        $frozenBindings = [
            'integration_sha256' => $this->integrationSha256(),
            'verifier_sha256' => $this->verifierSha256(),
            'config_sha256' => $this->configSha256($initialConfig),
        ];
        $frozenRuntimeIdentitySha256 = $this->runtimeIdentitySha256();
        $verifierOptions = $normalizedOptions;
        $verifierOptions['expected_config_sha256'] = $frozenBindings['config_sha256'];
        $verifierOptions['expected_runtime_identity_sha256'] = $frozenRuntimeIdentitySha256;

        $facts = (new TlsSessionResumptionLiveVerifier())->verify($instanceName, $verifierOptions);
        if (!\is_array($facts)) {
            throw new \RuntimeException('TLS Session Resumption verifier returned an invalid result.');
        }

        $finalConfig = $this->freshTlsSessionCacheConfig();
        $finalBindings = [
            'integration_sha256' => $this->integrationSha256(),
            'verifier_sha256' => $this->verifierSha256(),
            'config_sha256' => $this->configSha256($finalConfig),
        ];
        if ($finalBindings !== $frozenBindings
            || $finalConfig->toArray() !== $initialConfig->toArray()
            || !\hash_equals($frozenRuntimeIdentitySha256, $this->runtimeIdentitySha256())
        ) {
            throw new \RuntimeException(
                'TLS verifier source or external-cache configuration drifted during live verification.'
            );
        }

        $path = $this->publishVerified(
            $instanceName,
            $facts,
            $normalizedOptions,
            $frozenBindings,
            $initialConfig,
        );
        $document = $this->readDocument($path);
        if ($document === null) {
            throw new \RuntimeException('Published TLS evidence could not be read back safely.');
        }
        $assessment = $this->decorateWithPlatformMatrix(
            $this->withCurrentScope(
                $this->assessDocument($document, $path, true),
                \is_array($document['scope'] ?? null) ? $document['scope'] : [],
                \is_array($facts['scope'] ?? null) ? $facts['scope'] : [],
            )
        );
        if (!(bool)($assessment['evidence_integrity_valid'] ?? false)) {
            throw new \RuntimeException('Published TLS evidence failed its read-back integrity assessment.');
        }

        return [
            'path' => $path,
            'assessment' => $assessment,
        ];
    }

    /** @return array<string, mixed> */
    public function readForCurrentRuntime(): array
    {
        $runtime = $this->currentRuntimeIdentity();
        $directory = $this->root() . DIRECTORY_SEPARATOR . $this->runtimeFingerprint($runtime);
        $scan = $this->boundedJsonFiles($directory, false);
        if ($scan['overflow']) {
            return $this->emptyAssessment(
                'TLS resumption evidence directory exceeds the 64-file safety limit.'
            );
        }

        $candidates = [];
        foreach ($scan['paths'] as $path) {
            $evidence = $this->readDocument($path);
            if ($evidence === null) {
                continue;
            }
            $assessment = $this->assessDocument($evidence, $path, true);
            if (!(bool)($assessment['evidence_integrity_valid'] ?? false)) {
                continue;
            }
            $candidates[] = $assessment;
        }

        if ($candidates === []) {
            return $this->emptyAssessment('No valid TLS resumption evidence exists for this PHP runtime.');
        }
        \usort($candidates, static function (array $left, array $right): int {
            foreach ([
                'runtime_mechanism_verified',
                'integration_matches_evidence',
                'verifier_matches_evidence',
                'active_config_matches_evidence',
                'resumption_latency_gate_verified',
                'performance_baseline_verified',
            ] as $key) {
                $leftRank = (int)(bool)($left[$key] ?? false);
                $rightRank = (int)(bool)($right[$key] ?? false);
                if ($leftRank !== $rightRank) {
                    return $rightRank <=> $leftRank;
                }
            }

            return ((int)($right['captured_timestamp'] ?? 0))
                <=> ((int)($left['captured_timestamp'] ?? 0));
        });

        return $this->decorateWithPlatformMatrix($candidates[0]);
    }

    public function runtimeIdentitySha256(): string
    {
        return $this->runtimeFingerprint($this->currentRuntimeIdentity());
    }

    public function integrationSha256(): string
    {
        return $this->sourceSetSha256(self::INTEGRATION_FILES, 'TLS integration source');
    }

    public function verifierSha256(): string
    {
        return $this->sourceSetSha256(self::VERIFIER_FILES, 'TLS live verifier source');
    }

    /** @param list<string> $relativePaths */
    private function sourceSetSha256(array $relativePaths, string $label): string
    {
        $files = [];
        foreach ($relativePaths as $relativePath) {
            $path = $this->projectRoot() . DIRECTORY_SEPARATOR
                . \str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
            if (!\is_file($path) || \is_link($path)) {
                throw new \RuntimeException($label . ' is missing or unsafe: ' . $relativePath);
            }
            $hash = @\hash_file('sha256', $path);
            if (!\is_string($hash) || $hash === '') {
                throw new \RuntimeException('Unable to hash ' . $label . ': ' . $relativePath);
            }
            $files[$relativePath] = $hash;
        }

        return \hash('sha256', $this->canonicalJson($files));
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function normalizeVerifierOptions(array $options): array
    {
        $unknown = \array_diff(\array_keys($options), self::VERIFIER_OPTION_KEYS);
        if ($unknown !== []) {
            throw new \InvalidArgumentException(
                'Unknown TLS verifier option(s): ' . \implode(', ', $unknown)
            );
        }

        $normalized = [
            'same_worker_rounds' => $options['same_worker_rounds'] ?? 12,
            'cross_worker_rounds' => $options['cross_worker_rounds'] ?? 24,
            'reload_rounds' => $options['reload_rounds'] ?? 24,
            'fault_rounds' => $options['fault_rounds'] ?? 200,
            'post_recovery_rounds' => $options['post_recovery_rounds'] ?? 24,
            'connect_timeout_ms' => $options['connect_timeout_ms'] ?? 2_000,
            'resumption_tls_p95_limit_ms' => $options['resumption_tls_p95_limit_ms'] ?? 50.0,
            'performance_reports' => $options['performance_reports'] ?? [],
        ];
        foreach ([
            'same_worker_rounds' => [10, 512],
            'cross_worker_rounds' => [24, 512],
            'reload_rounds' => [24, 512],
            'fault_rounds' => [100, 1_024],
            'post_recovery_rounds' => [24, 512],
            'connect_timeout_ms' => [100, 30_000],
        ] as $key => [$minimum, $maximum]) {
            if (!\is_int($normalized[$key])
                || $normalized[$key] < $minimum
                || $normalized[$key] > $maximum
            ) {
                throw new \InvalidArgumentException(\sprintf(
                    'TLS verifier option %s must be an integer between %d and %d.',
                    $key,
                    $minimum,
                    $maximum
                ));
            }
        }
        if (!\is_int($normalized['resumption_tls_p95_limit_ms'])
            && !\is_float($normalized['resumption_tls_p95_limit_ms'])
        ) {
            throw new \InvalidArgumentException(
                'TLS verifier option resumption_tls_p95_limit_ms must be numeric.'
            );
        }
        $latencyLimit = (float)$normalized['resumption_tls_p95_limit_ms'];
        if (!\is_finite($latencyLimit) || $latencyLimit <= 0.0 || $latencyLimit > 200.0) {
            throw new \InvalidArgumentException(
                'TLS verifier option resumption_tls_p95_limit_ms must be within (0, 200].'
            );
        }
        $normalized['resumption_tls_p95_limit_ms'] = $latencyLimit;

        $reports = $normalized['performance_reports'];
        if (!\is_array($reports) || !\array_is_list($reports)
            || \count($reports) > self::MAX_PERFORMANCE_REPORTS
        ) {
            throw new \InvalidArgumentException(
                'TLS verifier performance_reports must be a list containing at most 16 paths.'
            );
        }
        $normalizedReports = [];
        foreach ($reports as $report) {
            if (!\is_string($report)) {
                throw new \InvalidArgumentException('TLS verifier performance report paths must be strings.');
            }
            $report = \trim($report);
            if (!$this->isSafeProjectRelativePath($report)) {
                throw new \InvalidArgumentException(
                    'TLS verifier performance report paths must be safe project-relative paths.'
                );
            }
            $normalizedReports[] = $report;
        }
        $normalized['performance_reports'] = $normalizedReports;

        return $normalized;
    }

    /**
     * @param array<string, mixed> $facts
     * @param array<string, mixed> $normalizedOptions
     */
    private function publishVerified(
        string $instanceName,
        array $facts,
        array $normalizedOptions,
        array $frozenBindings,
        TlsSessionCacheConfig $config,
    ): string {
        $this->assertExactKeys($facts, self::FACT_KEYS, 'verifier facts');
        $scope = \is_array($facts['scope'] ?? null) ? $facts['scope'] : [];
        $proof = \is_array($facts['proof'] ?? null) ? $facts['proof'] : [];
        $this->assertScope($scope);
        if (!\hash_equals($instanceName, (string)$scope['instance_name'])) {
            throw new \RuntimeException('TLS verifier scope does not match the requested WLS instance.');
        }
        $this->assertProofSchema($proof);
        $this->assertProofMatchesOptions($proof, $normalizedOptions);
        $proofAssessment = $this->assessProof($proof);
        if (!$proofAssessment['verified']) {
            throw new \RuntimeException(
                'TLS Session Resumption proof did not pass: '
                . \implode('; ', $proofAssessment['failure_reasons'])
            );
        }

        $capturedAt = \trim((string)($facts['captured_at'] ?? ''));
        $capturedTimestamp = \strtotime($capturedAt);
        if ($capturedAt === '' || $capturedTimestamp === false
            || $capturedTimestamp < 1_577_836_800
            || $capturedTimestamp > \time() + 300
        ) {
            throw new \InvalidArgumentException('captured_at must be a valid bounded, non-future timestamp.');
        }

        $this->assertExactKeys($frozenBindings, self::BINDING_KEYS, 'frozen bindings');
        foreach ($frozenBindings as $binding) {
            if (!\is_string($binding) || !$this->isSha256($binding)) {
                throw new \RuntimeException('TLS verifier frozen binding is invalid.');
            }
        }
        if (!$config->enabled()) {
            throw new \RuntimeException('Cannot publish TLS resumption evidence while the external cache is disabled.');
        }
        if (!TlsSessionCacheRuntime::apiAvailable()) {
            throw new \RuntimeException(
                'Cannot publish TLS resumption evidence without the PHP 8.6 external cache API.'
            );
        }

        $verification = [];
        foreach (self::VERIFICATION_KEYS as $key) {
            $verification[$key] = $normalizedOptions[$key];
        }
        $runtime = $this->currentRuntimeIdentity();
        $document = [
            'schema_version' => self::SCHEMA_VERSION,
            'kind' => self::KIND,
            'captured_at' => \date('c', $capturedTimestamp),
            'runtime' => $runtime,
            'bindings' => $frozenBindings,
            'config' => $config->toArray(),
            'verification' => $verification,
            'scope' => $scope,
            'proof' => $proof,
            'performance_reports' => $this->normalizePerformanceReports(
                $normalizedOptions['performance_reports'],
                $scope,
                $runtime,
                $frozenBindings,
            ),
        ];
        $this->assertNoSecrets($document);
        $document['evidence_sha256'] = $this->documentSha256($document);
        $schemaFailures = $this->documentSchemaFailures($document, '');
        if ($schemaFailures !== []) {
            throw new \RuntimeException(
                'TLS evidence failed its fixed schema: ' . \implode(', ', $schemaFailures)
            );
        }

        $runtimeFingerprint = $this->runtimeFingerprint($runtime);
        $directory = $this->root() . DIRECTORY_SEPARATOR . $runtimeFingerprint;
        $this->ensureEvidenceDirectory($directory);
        $path = $directory . DIRECTORY_SEPARATOR . $document['evidence_sha256'] . '.json';
        if (\is_link($path)) {
            throw new \RuntimeException('TLS evidence target must not be a symbolic link.');
        }
        $json = \json_encode(
            $document,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        ) . PHP_EOL;
        if (\is_file($path)) {
            $existing = @\file_get_contents($path);
            if ($existing !== $json) {
                throw new \RuntimeException('Immutable TLS evidence path already contains different bytes.');
            }

            return $path;
        }

        $temporary = $directory . DIRECTORY_SEPARATOR . '.tmp-'
            . \getmypid() . '-' . \bin2hex(\random_bytes(6));
        $handle = @\fopen($temporary, 'xb');
        if (!\is_resource($handle)) {
            throw new \RuntimeException('Unable to create temporary TLS evidence file.');
        }
        $writeSucceeded = false;
        try {
            $writeSucceeded = @\fwrite($handle, $json) === \strlen($json) && @\fflush($handle);
        } finally {
            @\fclose($handle);
        }
        if (!$writeSucceeded) {
            @\unlink($temporary);
            throw new \RuntimeException('Unable to write complete TLS evidence bytes.');
        }
        @\chmod($temporary, 0640);
        if (\is_link($directory) || \is_link($path)) {
            @\unlink($temporary);
            throw new \RuntimeException('TLS evidence publication path became unsafe.');
        }
        if (@\link($temporary, $path)) {
            @\unlink($temporary);

            return $path;
        }

        // Parallels/UNC filesystems may not support hard links. Preserve the
        // no-overwrite contract with exclusive creation on that fallback.
        $targetHandle = @\fopen($path, 'xb');
        if (\is_resource($targetHandle)) {
            $targetWritten = false;
            try {
                $targetWritten = @\fwrite($targetHandle, $json) === \strlen($json)
                    && @\fflush($targetHandle);
            } finally {
                @\fclose($targetHandle);
            }
            @\unlink($temporary);
            if (!$targetWritten) {
                @\unlink($path);
                throw new \RuntimeException('Unable to publish complete TLS evidence bytes.');
            }
            @\chmod($path, 0640);

            return $path;
        }
        @\unlink($temporary);
        if (\is_link($path)) {
            throw new \RuntimeException('TLS evidence target became a symbolic link.');
        }
        $existing = \is_file($path) ? @\file_get_contents($path) : false;
        if ($existing !== $json) {
            throw new \RuntimeException('Immutable TLS evidence target already exists with different bytes.');
        }

        return $path;
    }

    /**
     * @param array<string, mixed> $document
     * @return array<string, mixed>
     */
    private function assessDocument(array $document, string $path, bool $requireCurrentRuntime): array
    {
        $integrityFailures = $this->documentSchemaFailures($document, $path);
        try {
            $this->assertNoSecrets($document);
        } catch (\Throwable) {
            $integrityFailures[] = 'forbidden_secret_material';
        }

        $expectedDigest = \strtolower((string)($document['evidence_sha256'] ?? ''));
        if (!$this->isSha256($expectedDigest)
            || !\hash_equals($expectedDigest, $this->documentSha256($document))
        ) {
            $integrityFailures[] = 'evidence_digest_mismatch';
        }
        if ($path !== '' && \basename($path) !== $expectedDigest . '.json') {
            $integrityFailures[] = 'evidence_filename_mismatch';
        }

        $runtime = \is_array($document['runtime'] ?? null) ? $document['runtime'] : [];
        if ($path !== '' && \basename(\dirname($path)) !== $this->runtimeFingerprint($runtime)) {
            $integrityFailures[] = 'runtime_directory_mismatch';
        }
        $currentRuntime = $this->currentRuntimeIdentity();
        $runtimeMatches = $runtime === $currentRuntime;
        if ($requireCurrentRuntime && !$runtimeMatches) {
            $integrityFailures[] = 'runtime_identity_mismatch';
        }

        $bindings = \is_array($document['bindings'] ?? null) ? $document['bindings'] : [];
        $integrationMatches = \hash_equals(
            $this->integrationSha256(),
            \strtolower((string)($bindings['integration_sha256'] ?? ''))
        );
        $verifierMatches = \hash_equals(
            $this->verifierSha256(),
            \strtolower((string)($bindings['verifier_sha256'] ?? ''))
        );
        $config = $this->freshTlsSessionCacheConfig();
        $configMatches = \hash_equals(
            $this->configSha256($config),
            \strtolower((string)($bindings['config_sha256'] ?? ''))
        );
        $proof = \is_array($document['proof'] ?? null) ? $document['proof'] : [];
        $proofAssessment = $this->assessProof($proof);
        $capturedTimestamp = \strtotime((string)($document['captured_at'] ?? '')) ?: 0;
        $integrityFailures = \array_values(\array_unique($integrityFailures));
        $evidenceIntegrityValid = $integrityFailures === [];
        $runtimeMechanismVerified = $evidenceIntegrityValid
            && $runtimeMatches
            && $integrationMatches
            && $verifierMatches
            && $proofAssessment['verified'];
        // No active instance scope is available on a global runtime read.
        // The just-completed live-verifier path may set this via withCurrentScope().
        $activeRuntimeVerified = false;
        $performanceReports = \is_array($document['performance_reports'] ?? null)
            ? $document['performance_reports']
            : [];
        $scope = \is_array($document['scope'] ?? null) ? $document['scope'] : [];
        $performanceBaselineVerified = $this->performanceReportsVerified(
            $performanceReports,
            $scope,
            $runtime,
        );
        $resumptionLatencyVerified = $this->resumptionLatencyVerified($proof);

        $reason = match (true) {
            !$evidenceIntegrityValid => 'TLS evidence failed integrity validation: '
                . \implode(', ', $integrityFailures),
            !$integrationMatches || !$verifierMatches =>
                'TLS evidence belongs to an older WLS integration or verifier revision.',
            !$proofAssessment['verified'] => 'TLS evidence facts no longer satisfy the verifier gates.',
            !$configMatches || !$config->enabled() =>
                'This runtime has verified TLS resumption evidence, but the active external-cache configuration does not match it.',
            !$resumptionLatencyVerified =>
                'Current runtime/config has verified resumption continuity; its resumed-handshake latency gate is pending.',
            !$performanceBaselineVerified =>
                'Current runtime/config has verified resumption continuity; its performance baseline is pending.',
            default => 'Matching runtime/config has durable TLS resumption evidence; active instance scope was not evaluated.',
        };

        return [
            'evidence_available' => true,
            'evidence_integrity_valid' => $evidenceIntegrityValid,
            'evidence_path' => $this->relativePath($path),
            'evidence_sha256' => $expectedDigest,
            'captured_at' => (string)($document['captured_at'] ?? ''),
            'captured_timestamp' => $capturedTimestamp,
            'runtime_identity_matches' => $runtimeMatches,
            'runtime_prerelease' => (bool)($runtime['php_prerelease'] ?? true),
            'runtime_matrix_key' => $this->platformMatrixKey($runtime, $scope),
            'integration_matches_evidence' => $integrationMatches,
            'verifier_matches_evidence' => $verifierMatches,
            'active_config_matches_evidence' => $configMatches && $config->enabled(),
            'current_scope_matches_evidence' => false,
            'implementation_supported' => TlsSessionCacheRuntime::apiAvailable(),
            'runtime_mechanism_verified' => $runtimeMechanismVerified,
            'active_runtime_verified' => $activeRuntimeVerified,
            'server_session_reuse_observable' => (bool)$proofAssessment['server_observable'],
            'same_worker_verified' => (bool)$proofAssessment['same_worker_verified'],
            'cross_worker_verified' => (bool)$proofAssessment['cross_worker_verified'],
            'reload_continuity_verified' => (bool)$proofAssessment['reload_verified'],
            'sidecar_recovery_verified' => (bool)$proofAssessment['sidecar_recovery_verified'],
            'performance_baseline_verified' => $performanceBaselineVerified,
            'resumption_latency_gate_verified' => $resumptionLatencyVerified,
            'production_platform_matrix_verified' => false,
            'platforms_verified' => [],
            'platform_families_verified' => [],
            'production_ready' => false,
            'proof_summary' => $proofAssessment['summary'],
            'failure_reasons' => \array_values(\array_unique(\array_merge(
                $integrityFailures,
                $proofAssessment['failure_reasons']
            ))),
            'reason' => $reason,
        ];
    }

    /**
     * @param array<string, mixed> $proof
     * @return array{verified:bool,server_observable:bool,same_worker_verified:bool,cross_worker_verified:bool,reload_verified:bool,sidecar_recovery_verified:bool,failure_reasons:list<string>,summary:array<string,int|float|bool>}
     */
    private function assessProof(array $proof): array
    {
        try {
            $this->assertProofSchema($proof);
        } catch (\Throwable) {
            return [
                'verified' => false,
                'server_observable' => false,
                'same_worker_verified' => false,
                'cross_worker_verified' => false,
                'reload_verified' => false,
                'sidecar_recovery_verified' => false,
                'failure_reasons' => ['proof_schema'],
                'summary' => [],
            ];
        }

        $failures = [];
        $sameRounds = $proof['same_worker_rounds'];
        $sameResumed = $proof['same_worker_client_resumed_pairs'];
        $sameServerResumed = $proof['same_worker_server_resumed_delta'];
        $minimumSameResumed = (int)\ceil($sameRounds * 0.95);
        $sameWorkerVerified = $sameRounds >= 10
            && $proof['same_worker_http_success'] === $sameRounds * 2
            && $sameResumed >= $minimumSameResumed
            && $sameServerResumed >= $sameResumed;
        if (!$sameWorkerVerified) {
            $failures[] = 'same_worker_resumption_gate';
        }

        $initialRounds = $proof['initial_rounds'];
        $initialResumed = $proof['initial_client_resumed_pairs'];
        $initialServerResumed = $proof['initial_server_resumed_delta'];
        $initialCrossWorker = $proof['initial_cross_worker_pairs'];
        $minimumInitialResumed = (int)\ceil($initialRounds * 0.95);
        $initialVerified = $initialRounds >= 24
            && $proof['initial_http_success'] === $initialRounds * 2
            && $initialResumed >= $minimumInitialResumed
            && $initialServerResumed >= $initialResumed
            && $initialCrossWorker === $initialRounds;
        if (!$initialVerified) {
            $failures[] = 'initial_cross_worker_resumption_gate';
        }

        $reloadRounds = $proof['post_reload_rounds'];
        $reloadResumed = $proof['post_reload_client_resumed_pairs'];
        $reloadServerResumed = $proof['post_reload_server_resumed_delta'];
        $minimumReloadResumed = (int)\ceil($reloadRounds * 0.95);
        $reloadVerified = $reloadRounds >= 24
            && $proof['post_reload_http_success'] === $reloadRounds * 2
            && $reloadResumed >= $minimumReloadResumed
            && $reloadServerResumed >= $reloadResumed
            && $proof['post_reload_cross_worker_pairs'] === $reloadRounds
            && $proof['reload_worker_fingerprint_changed']
            && $proof['reload_preserved_session_resumed']
            && $proof['reload_preserved_session_server_resumed_delta'] >= 1;
        if (!$reloadVerified) {
            $failures[] = 'reload_continuity_gate';
        }

        $faultRounds = $proof['sidecar_fault_rounds'];
        $faultResumed = $proof['sidecar_fault_client_resumed_pairs'];
        $faultServerResumed = $proof['sidecar_fault_server_resumed_delta'];
        $faultVerified = $faultRounds >= 100
            && $proof['sidecar_fault_http_success'] === $faultRounds * 2
            && $faultServerResumed >= $faultResumed
            && $proof['sidecar_fault_cross_worker_pairs'] === $faultRounds
            && $proof['sidecar_generation_changed'];
        if (!$faultVerified) {
            $failures[] = 'sidecar_fault_availability_gate';
        }

        $recoveryRounds = $proof['post_recovery_rounds'];
        $recoveryResumed = $proof['post_recovery_client_resumed_pairs'];
        $recoveryServerResumed = $proof['post_recovery_server_resumed_delta'];
        $minimumRecoveryResumed = (int)\ceil($recoveryRounds * 0.95);
        $recoveryVerified = $recoveryRounds >= 24
            && $proof['post_recovery_http_success'] === $recoveryRounds * 2
            && $recoveryResumed >= $minimumRecoveryResumed
            && $recoveryServerResumed >= $recoveryResumed
            && $proof['post_recovery_cross_worker_pairs'] === $recoveryRounds;
        if (!$recoveryVerified) {
            $failures[] = 'post_recovery_gate';
        }
        if ($proof['reuse_observation_missing'] !== 0) {
            $failures[] = 'server_reuse_observation_missing';
        }
        if ($proof['dropped_write_delta'] !== 0
            || $proof['pending_writes_final'] !== 0
            || $proof['inflight_writes_final'] !== 0
        ) {
            $failures[] = 'external_cache_write_drain_gate';
        }
        $latencyVerified = $this->requestedResumptionLatencyVerified($proof);
        if (!$latencyVerified) {
            $failures[] = 'resumption_latency_gate';
        }

        $serverObservable = $sameServerResumed >= $sameResumed
            && $initialServerResumed >= $initialResumed
            && $reloadServerResumed >= $reloadResumed
            && $proof['reload_preserved_session_server_resumed_delta'] >= 1
            && $faultServerResumed >= $faultResumed
            && $recoveryServerResumed >= $recoveryResumed;
        $crossWorkerVerified = $initialVerified;
        $sidecarRecoveryVerified = $faultVerified && $recoveryVerified;

        return [
            'verified' => $failures === [],
            'server_observable' => $serverObservable,
            'same_worker_verified' => $sameWorkerVerified,
            'cross_worker_verified' => $crossWorkerVerified,
            'reload_verified' => $reloadVerified,
            'sidecar_recovery_verified' => $sidecarRecoveryVerified,
            'failure_reasons' => $failures,
            'summary' => [
                'same_worker_rounds' => $sameRounds,
                'same_worker_resumed_pairs' => $sameResumed,
                'initial_rounds' => $initialRounds,
                'initial_resumed_pairs' => $initialResumed,
                'initial_cross_worker_pairs' => $initialCrossWorker,
                'post_reload_resumed_pairs' => $reloadResumed,
                'reload_preserved_session_resumed' => $proof['reload_preserved_session_resumed'],
                'sidecar_fault_rounds' => $faultRounds,
                'sidecar_fault_resumed_pairs' => $faultResumed,
                'post_recovery_resumed_pairs' => $recoveryResumed,
                'cache_failure_delta' => $proof['cache_failure_delta'],
                'dropped_write_delta' => $proof['dropped_write_delta'],
                'server_reuse_observable' => $serverObservable,
                'resumption_tls_p95_ms' => $proof['resumption_tls_p95_ms'],
                'resumption_tls_p95_limit_ms' => $proof['resumption_tls_p95_limit_ms'],
            ],
        ];
    }

    /** @param array<string, mixed> $scope */
    private function assertScope(array $scope): void
    {
        $this->assertExactKeys($scope, self::SCOPE_KEYS, 'scope');
        $this->assertInstanceName($scope['instance_name']);
        if ($scope['mechanism'] !== 'php86_external_stateful_cache'
            || $scope['transport'] !== 'tcp'
        ) {
            throw new \InvalidArgumentException(
                'TLS evidence scope must identify the PHP 8.6 external stateful TCP cache.'
            );
        }
        if (!\in_array($scope['topology'], ['direct', 'dispatcher'], true)) {
            throw new \InvalidArgumentException('TLS evidence topology must be direct or dispatcher.');
        }
        if (!\is_int($scope['worker_count']) || $scope['worker_count'] < 2 || $scope['worker_count'] > 4_096) {
            throw new \InvalidArgumentException('TLS evidence scope requires a bounded worker_count.');
        }
        foreach ([
            'policy_digest',
            'sni_sha256',
            'certificate_sha256',
            'instance_scope_sha256',
        ] as $field) {
            if (!\is_string($scope[$field]) || !$this->isSha256($scope[$field])) {
                throw new \InvalidArgumentException('TLS evidence scope requires SHA-256 field: ' . $field);
            }
        }
        $scopeBinding = $scope;
        unset($scopeBinding['instance_scope_sha256']);
        $expectedScopeHash = \hash('sha256', $this->canonicalJson($scopeBinding));
        if (!\hash_equals($expectedScopeHash, $scope['instance_scope_sha256'])) {
            throw new \InvalidArgumentException('TLS evidence instance_scope_sha256 does not bind its scope.');
        }
    }

    private function assertInstanceName(mixed $instanceName): void
    {
        if (!\is_string($instanceName)
            || $instanceName === ''
            || \strlen($instanceName) > 128
            || !\preg_match('/^[A-Za-z0-9._-]+$/D', $instanceName)
        ) {
            throw new \InvalidArgumentException('TLS evidence requires a bounded WLS instance_name.');
        }
    }

    /** @param array<string, mixed> $proof */
    private function assertProofSchema(array $proof): void
    {
        $this->assertExactKeys($proof, self::PROOF_KEYS, 'proof');
        $booleanKeys = [
            'reload_worker_fingerprint_changed',
            'reload_preserved_session_resumed',
            'sidecar_generation_changed',
        ];
        foreach ($booleanKeys as $key) {
            if (!\is_bool($proof[$key])) {
                throw new \InvalidArgumentException('TLS evidence proof field must be boolean: ' . $key);
            }
        }
        if (!\is_string($proof['sidecar_fault_method'])
            || !\in_array($proof['sidecar_fault_method'], [
                'identity_safe_process_termination',
                'authenticated_server_shutdown',
            ], true)
        ) {
            throw new \InvalidArgumentException('TLS evidence sidecar_fault_method is invalid.');
        }
        $numericKeys = ['resumption_tls_p95_ms', 'resumption_tls_p95_limit_ms'];
        foreach ($numericKeys as $key) {
            if ((!\is_int($proof[$key]) && !\is_float($proof[$key]))
                || !\is_finite((float)$proof[$key])
                || (float)$proof[$key] < 0.0
            ) {
                throw new \InvalidArgumentException(
                    'TLS evidence proof field must be a finite non-negative number: ' . $key
                );
            }
        }
        foreach (\array_diff(
            self::PROOF_KEYS,
            $booleanKeys,
            $numericKeys,
            ['sidecar_fault_method']
        ) as $key) {
            if (!\is_int($proof[$key]) || $proof[$key] < 0) {
                throw new \InvalidArgumentException(
                    'TLS evidence proof field must be a non-negative integer: ' . $key
                );
            }
        }
    }

    /**
     * @param array<string, mixed> $proof
     * @param array<string, mixed> $options
     */
    private function assertProofMatchesOptions(array $proof, array $options): void
    {
        $expectedRounds = [
            'same_worker_rounds' => 'same_worker_rounds',
            'initial_rounds' => 'cross_worker_rounds',
            'post_reload_rounds' => 'reload_rounds',
            'sidecar_fault_rounds' => 'fault_rounds',
            'post_recovery_rounds' => 'post_recovery_rounds',
        ];
        foreach ($expectedRounds as $proofKey => $optionKey) {
            if ($proof[$proofKey] !== $options[$optionKey]) {
                throw new \RuntimeException(
                    'TLS verifier proof did not execute the requested threshold: ' . $proofKey
                );
            }
        }
        if (\abs(
            (float)$proof['resumption_tls_p95_limit_ms']
            - (float)$options['resumption_tls_p95_limit_ms']
        ) > 0.000_001) {
            throw new \RuntimeException('TLS verifier proof changed the requested latency limit.');
        }
    }

    /**
     * @param list<mixed> $reports
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $runtime
     * @param array<string, mixed> $frozenBindings
     * @return list<array<string, mixed>>
     */
    private function normalizePerformanceReports(
        array $reports,
        array $scope,
        array $runtime,
        array $frozenBindings,
    ): array
    {
        $expectedIntegrationSha256 = (string)($frozenBindings['integration_sha256'] ?? '');
        $expectedVerifierSha256 = (string)($frozenBindings['verifier_sha256'] ?? '');
        if (!$this->isSha256($expectedIntegrationSha256)
            || !$this->isSha256($expectedVerifierSha256)
            || !\hash_equals($expectedIntegrationSha256, $this->integrationSha256())
            || !\hash_equals($expectedVerifierSha256, $this->verifierSha256())
        ) {
            throw new \RuntimeException(
                'TLS integration or verifier source drifted before performance-report binding.'
            );
        }
        $normalized = [];
        foreach ($reports as $report) {
            $relativePath = \trim((string)$report);
            if (!$this->isSafeProjectRelativePath($relativePath)) {
                throw new \InvalidArgumentException('Performance report paths must be project-relative.');
            }
            $path = $this->projectRoot() . DIRECTORY_SEPARATOR
                . \str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
            if (!\is_file($path) || \is_link($path) || \filesize($path) > self::MAX_EVIDENCE_BYTES) {
                throw new \RuntimeException('TLS evidence performance report is missing or unsafe: ' . $relativePath);
            }
            $raw = @\file_get_contents($path);
            if (!\is_string($raw) || \strlen($raw) > self::MAX_EVIDENCE_BYTES) {
                throw new \RuntimeException(
                    'TLS evidence performance report exceeds its safe byte limit: ' . $relativePath
                );
            }
            $decoded = \json_decode($raw, true);
            if (!\is_array($decoded)) {
                throw new \RuntimeException('TLS evidence performance report is invalid JSON: ' . $relativePath);
            }
            $generatedAt = \trim((string)($decoded['generated_at'] ?? ''));
            $generatedTimestamp = \strtotime($generatedAt);
            $reportInstance = (string)($decoded['instance_name'] ?? '');
            $reportPolicyDigest = \strtolower((string)($decoded['policy_digest'] ?? ''));
            $reportPhpVersion = (string)($decoded['php_version'] ?? '');
            $reportRuntimeSelection = \is_array($decoded['runtime_selection'] ?? null)
                ? $decoded['runtime_selection']
                : [];
            $reportTopology = \strtolower((string)(
                $reportRuntimeSelection['effective_topology'] ?? ''
            ));
            $reportOsFamily = (string)($reportRuntimeSelection['os_family'] ?? '');
            $reportArchitecture = $this->normalizeArchitecture((string)(
                $decoded['architecture'] ?? $decoded['arch'] ?? ''
            ));
            $reportWorkerCount = (int)($decoded['worker_count'] ?? 0);
            $reportIntegrationSha256 = \strtolower((string)(
                $decoded['tls_evidence_integration_sha256'] ?? ''
            ));
            $reportVerifierSha256 = \strtolower((string)(
                $decoded['tls_evidence_verifier_sha256'] ?? ''
            ));
            $targetUrl = (string)($decoded['target_url'] ?? '');
            $targetHost = \parse_url($targetUrl, PHP_URL_HOST);
            $targetHost = \is_string($targetHost)
                ? \strtolower(\rtrim($targetHost, '.'))
                : '';
            if (($decoded['benchmark_valid'] ?? null) !== true
                || $generatedTimestamp === false
                || $generatedTimestamp < \time() - 86_400
                || $generatedTimestamp > \time() + 300
                || $reportInstance !== $scope['instance_name']
                || !\hash_equals($scope['policy_digest'], $reportPolicyDigest)
                || $reportPhpVersion !== $runtime['php_version']
                || $reportTopology !== $scope['topology']
                || $reportOsFamily !== $runtime['os_family']
                || $reportArchitecture !== $runtime['process_architecture']
                || $reportWorkerCount !== $scope['worker_count']
                || !\hash_equals($expectedIntegrationSha256, $reportIntegrationSha256)
                || !\hash_equals($expectedVerifierSha256, $reportVerifierSha256)
                || $targetHost === ''
                || !\hash_equals($scope['sni_sha256'], \hash('sha256', $targetHost))
            ) {
                throw new \RuntimeException(
                    'TLS performance report is not bound to the verified runtime/instance: '
                    . $relativePath
                );
            }
            $hash = \hash('sha256', $raw);
            $normalized[] = [
                'path' => \str_replace('\\', '/', $relativePath),
                'sha256' => $hash,
                'generated_at' => $generatedAt,
                'benchmark_valid' => true,
                'quality_gate_passed' => (bool)($decoded['quality_gate']['passed'] ?? false),
                'integration_sha256' => $reportIntegrationSha256,
                'verifier_sha256' => $reportVerifierSha256,
                'instance_name' => $reportInstance,
                'policy_digest' => $reportPolicyDigest,
                'target_host_sha256' => \hash('sha256', $targetHost),
                'os_family' => $reportOsFamily,
                'process_architecture' => $reportArchitecture,
                'php_version' => $reportPhpVersion,
                'topology' => $reportTopology,
                'worker_count' => $reportWorkerCount,
                'http_version' => (string)($decoded['http_version_negotiated'] ?? ''),
                'requests' => (int)($decoded['total_requests'] ?? 0),
                'success_count' => (int)($decoded['success_count'] ?? 0),
                'qps' => (float)($decoded['success_qps'] ?? $decoded['qps'] ?? 0.0),
                'request_p95_ms' => (float)($decoded['latency_ms']['p95'] ?? 0.0),
                'tls_handshake_p95_ms' => (float)($decoded['curl_tls_handshake_time_ms']['p95'] ?? 0.0),
            ];
        }
        if (!\hash_equals($expectedIntegrationSha256, $this->integrationSha256())
            || !\hash_equals($expectedVerifierSha256, $this->verifierSha256())
        ) {
            throw new \RuntimeException(
                'TLS integration or verifier source drifted while performance reports were read.'
            );
        }

        return $normalized;
    }

    /**
     * @param list<mixed> $reports
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $runtime
     */
    private function performanceReportsVerified(
        array $reports,
        array $scope,
        array $runtime,
    ): bool
    {
        if ($reports === [] || !$this->performanceReportsBoundToScope($reports, $scope, $runtime)) {
            return false;
        }
        $http2Verified = false;
        $http11Verified = false;
        foreach ($reports as $report) {
            if (!\is_array($report) || !$this->performanceReportSchemaValid($report)) {
                return false;
            }
            if ($report['quality_gate_passed'] !== true
                || $report['benchmark_valid'] !== true
                || $report['requests'] <= 0
                || $report['success_count'] !== $report['requests']
            ) {
                return false;
            }
            if ((float)$report['qps'] <= 0.0) {
                return false;
            }
            $httpVersion = (string)$report['http_version'];
            if ($httpVersion === '2') {
                $http2Verified = $http2Verified || (
                    $report['requests'] >= 500
                    && (float)$report['qps'] >= 1_000.0
                    && (float)$report['request_p95_ms'] <= 100.0
                    && (float)$report['tls_handshake_p95_ms'] <= 100.0
                );
            } elseif ($httpVersion === '1.1') {
                $http11Verified = $http11Verified || (
                    $report['requests'] >= 120
                    && (float)$report['qps'] >= 50.0
                    && (float)$report['request_p95_ms'] <= 500.0
                    && (float)$report['tls_handshake_p95_ms'] <= 200.0
                );
            }
        }

        return $http2Verified && $http11Verified;
    }

    /**
     * @param list<mixed> $reports
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $runtime
     */
    private function performanceReportsBoundToScope(
        array $reports,
        array $scope,
        array $runtime,
    ): bool {
        foreach ($reports as $report) {
            if (!\is_array($report)
                || !$this->performanceReportSchemaValid($report)
                || $report['instance_name'] !== ($scope['instance_name'] ?? null)
                || $report['policy_digest'] !== ($scope['policy_digest'] ?? null)
                || $report['target_host_sha256'] !== ($scope['sni_sha256'] ?? null)
                || $report['topology'] !== ($scope['topology'] ?? null)
                || $report['worker_count'] !== ($scope['worker_count'] ?? null)
                || $report['os_family'] !== ($runtime['os_family'] ?? null)
                || $report['process_architecture'] !== ($runtime['process_architecture'] ?? null)
                || $report['php_version'] !== ($runtime['php_version'] ?? null)
                || $report['integration_sha256'] !== $this->integrationSha256()
                || $report['verifier_sha256'] !== $this->verifierSha256()
            ) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array{keys:list<string>,families:list<string>,profiles:list<string>}
     */
    private function verifiedPlatformMatrix(): array
    {
        $currentConfigHash = $this->configSha256($this->freshTlsSessionCacheConfig());
        $currentIntegrationHash = $this->integrationSha256();
        $currentVerifierHash = $this->verifierSha256();
        $currentPhpLine = (string)$this->currentRuntimeIdentity()['php_version_line'];
        $scan = $this->boundedJsonFiles($this->root(), true);
        if ($scan['overflow']) {
            return ['keys' => [], 'families' => [], 'profiles' => []];
        }

        $matrixKeys = [];
        $families = [];
        $profiles = [];
        foreach ($scan['paths'] as $path) {
            $document = $this->readDocument($path);
            if ($document === null) {
                continue;
            }
            $assessment = $this->assessDocument($document, $path, false);
            $bindings = \is_array($document['bindings'] ?? null) ? $document['bindings'] : [];
            $runtime = \is_array($document['runtime'] ?? null) ? $document['runtime'] : [];
            if (!(bool)($assessment['evidence_integrity_valid'] ?? false)
                || !\hash_equals($currentIntegrationHash, (string)($bindings['integration_sha256'] ?? ''))
                || !\hash_equals($currentVerifierHash, (string)($bindings['verifier_sha256'] ?? ''))
                || !\hash_equals($currentConfigHash, (string)($bindings['config_sha256'] ?? ''))
                || (string)($runtime['php_version_line'] ?? '') !== $currentPhpLine
                || (bool)($runtime['php_prerelease'] ?? true)
                || (string)($runtime['php_release_channel'] ?? '') !== 'stable'
                || !(bool)($assessment['performance_baseline_verified'] ?? false)
                || !(bool)($assessment['resumption_latency_gate_verified'] ?? false)
            ) {
                continue;
            }
            $proof = \is_array($document['proof'] ?? null) ? $document['proof'] : [];
            if (!$this->assessProof($proof)['verified']) {
                continue;
            }
            $osFamily = (string)($runtime['os_family'] ?? '');
            $scope = \is_array($document['scope'] ?? null) ? $document['scope'] : [];
            $profile = $this->productionPlatformProfile($runtime, $scope);
            if ($profile === null) {
                continue;
            }
            $matrixKeys[$this->platformMatrixKey($runtime, $scope)] = true;
            $families[$osFamily] = true;
            $profiles[$profile] = true;
        }
        $keys = \array_keys($matrixKeys);
        $familyList = \array_keys($families);
        $profileList = \array_keys($profiles);
        \sort($keys, SORT_STRING);
        \sort($familyList, SORT_STRING);
        \sort($profileList, SORT_STRING);

        return ['keys' => $keys, 'families' => $familyList, 'profiles' => $profileList];
    }

    /**
     * Mark scope active only for the scope returned by the live verifier in
     * the same verifyAndPublish() call. Global evidence reads cannot do this.
     *
     * @param array<string, mixed> $assessment
     * @param array<string, mixed> $evidenceScope
     * @param array<string, mixed> $currentScope
     * @return array<string, mixed>
     */
    private function withCurrentScope(
        array $assessment,
        array $evidenceScope,
        array $currentScope,
    ): array {
        $scopeMatches = $evidenceScope !== []
            && $currentScope !== []
            && $evidenceScope === $currentScope;
        $assessment['current_scope_matches_evidence'] = $scopeMatches;
        $assessment['active_runtime_verified'] = $scopeMatches
            && (bool)($assessment['runtime_mechanism_verified'] ?? false)
            && (bool)($assessment['active_config_matches_evidence'] ?? false)
            && (bool)($assessment['implementation_supported'] ?? false)
            && (bool)($assessment['resumption_latency_gate_verified'] ?? false);
        if ($assessment['active_runtime_verified']) {
            $assessment['reason'] = 'The just-verified WLS instance scope has external stateful TLS Session Resumption evidence.';
        }

        return $assessment;
    }

    /** @param array<string, mixed> $assessment */
    private function decorateWithPlatformMatrix(array $assessment): array
    {
        $matrix = $this->verifiedPlatformMatrix();
        $matrixVerified = !\array_diff(
            self::REQUIRED_PRODUCTION_PLATFORM_PROFILES,
            $matrix['profiles'],
        );
        $assessment['platforms_verified'] = $matrix['keys'];
        $assessment['platform_families_verified'] = $matrix['families'];
        $assessment['production_platform_matrix_verified'] = $matrixVerified;
        $assessment['production_ready'] = (bool)($assessment['active_runtime_verified'] ?? false)
            && (bool)($assessment['resumption_latency_gate_verified'] ?? false)
            && (bool)($assessment['performance_baseline_verified'] ?? false)
            && $matrixVerified
            && !(bool)($assessment['runtime_prerelease'] ?? true);

        if ((bool)($assessment['active_runtime_verified'] ?? false)) {
            if ((bool)($assessment['runtime_prerelease'] ?? true)) {
                $assessment['reason'] = 'Verified TLS resumption evidence is running on a prerelease PHP build; it cannot qualify for production.';
            } elseif (!(bool)($assessment['resumption_latency_gate_verified'] ?? false)) {
                $assessment['reason'] = 'TLS resumption is verified; its resumed-handshake latency gate is pending.';
            } elseif (!(bool)($assessment['performance_baseline_verified'] ?? false)) {
                $assessment['reason'] = 'TLS resumption is verified; its request performance baseline is pending.';
            } elseif (!$matrixVerified) {
                $assessment['reason'] = 'TLS resumption is verified on this runtime; the stable Darwin/Linux/Windows production matrix is incomplete.';
            }
        }

        return $assessment;
    }

    /** @return array<string, mixed> */
    private function currentRuntimeIdentity(): array
    {
        if (self::$runtimeIdentityCache !== null) {
            return self::$runtimeIdentityCache;
        }
        $binaryHash = @\hash_file('sha256', PHP_BINARY);
        if (!\is_string($binaryHash) || $binaryHash === '') {
            throw new \RuntimeException('Unable to hash the active PHP binary for TLS evidence binding.');
        }

        $processArchitecture = $this->processBinaryArchitecture();
        $windowsNativeArchitecture = '';
        $windowsNativeArchitectureSource = '';
        if (PHP_OS_FAMILY === 'Windows') {
            [$windowsNativeArchitecture, $windowsNativeArchitectureSource]
                = $this->windowsNativeArchitecture($processArchitecture);
        }
        $osArchitecture = PHP_OS_FAMILY === 'Windows'
            ? $windowsNativeArchitecture
            : $this->normalizeArchitecture((string)\php_uname('m'));
        [$nativeExecutionVerified, $nativeExecutionSource]
            = $this->nativeExecutionIdentity(
                $osArchitecture,
                $processArchitecture,
                $windowsNativeArchitectureSource,
            );
        [$releaseChannel, $releaseStage] = $this->phpReleaseIdentity();
        [$extensionVersion, $extensionLinkage, $extensionHash] = $this->opensslExtensionIdentity(
            $binaryHash
        );

        return self::$runtimeIdentityCache = [
            'os_family' => PHP_OS_FAMILY,
            'os_release' => (string)\php_uname('r'),
            'os_build' => (string)\php_uname('v'),
            'os_architecture' => $osArchitecture,
            'process_architecture' => $processArchitecture,
            'native_execution_verified' => $nativeExecutionVerified,
            'native_execution_source' => $nativeExecutionSource,
            'windows_native_architecture' => $windowsNativeArchitecture,
            'windows_native_architecture_source' => $windowsNativeArchitectureSource,
            'php_version' => PHP_VERSION,
            'php_version_id' => PHP_VERSION_ID,
            'php_version_line' => PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION,
            'php_release_channel' => $releaseChannel,
            'php_release_stage' => $releaseStage,
            'php_prerelease' => $releaseChannel === 'prerelease',
            'php_binary_sha256' => $binaryHash,
            'openssl_version_text' => \defined('OPENSSL_VERSION_TEXT')
                ? (string)OPENSSL_VERSION_TEXT
                : '',
            'openssl_version_number' => \defined('OPENSSL_VERSION_NUMBER')
                ? \sprintf('0x%08x', (int)OPENSSL_VERSION_NUMBER)
                : '',
            'openssl_extension_version' => $extensionVersion,
            'openssl_extension_linkage' => $extensionLinkage,
            'openssl_extension_binary_sha256' => $extensionHash,
        ];
    }

    /** @return array{0:string,1:string} */
    private function phpReleaseIdentity(): array
    {
        $version = \strtolower(PHP_VERSION);
        $stage = match (true) {
            \str_contains($version, 'alpha') => 'alpha',
            \str_contains($version, 'beta') => 'beta',
            (bool)\preg_match('/(?:^|[^a-z])rc\d*/i', PHP_VERSION) => 'rc',
            \str_contains($version, 'dev') => 'dev',
            default => 'stable',
        };

        return [$stage === 'stable' ? 'stable' : 'prerelease', $stage];
    }

    /** @return array{0:string,1:string,2:string} */
    private function opensslExtensionIdentity(string $phpBinaryHash): array
    {
        $extensionVersion = '';
        try {
            $extensionVersion = (string)((new \ReflectionExtension('openssl'))->getVersion() ?: '');
        } catch (\Throwable) {
        }

        $extensionDirectory = \trim((string)\ini_get('extension_dir'));
        $candidates = [];
        if ($extensionDirectory !== '') {
            $extensionDirectories = [$extensionDirectory];
            if (!$this->isAbsolutePath($extensionDirectory)) {
                $extensionDirectories[] = \dirname(PHP_BINARY) . DIRECTORY_SEPARATOR . $extensionDirectory;
            }
            $names = PHP_OS_FAMILY === 'Windows'
                ? ['php_openssl.dll', 'openssl.dll']
                : ['openssl.' . PHP_SHLIB_SUFFIX, 'php_openssl.' . PHP_SHLIB_SUFFIX];
            foreach ($extensionDirectories as $directory) {
                foreach ($names as $name) {
                    $candidates[] = \rtrim($directory, '/\\') . DIRECTORY_SEPARATOR . $name;
                }
            }
        }
        foreach ($candidates as $candidate) {
            $resolved = \realpath($candidate);
            if (!\is_string($resolved) || !\is_file($resolved)) {
                continue;
            }
            $hash = @\hash_file('sha256', $resolved);
            if (\is_string($hash) && $hash !== '') {
                return [$extensionVersion, 'shared', $hash];
            }
        }

        return [$extensionVersion, 'builtin', $phpBinaryHash];
    }

    /**
     * @param array<string, mixed> $runtime
     * @param array<string, mixed> $scope
     */
    private function platformMatrixKey(array $runtime, array $scope = []): string
    {
        $build = (string)($runtime['os_build'] ?? '');

        return \implode('|', [
            'family=' . $this->matrixComponent((string)($runtime['os_family'] ?? 'unknown')),
            'release=' . $this->matrixComponent((string)($runtime['os_release'] ?? 'unknown')),
            'build=' . \substr(\hash('sha256', $build), 0, 16),
            'os_arch=' . $this->matrixComponent((string)($runtime['os_architecture'] ?? 'unknown')),
            'process_arch=' . $this->matrixComponent((string)($runtime['process_architecture'] ?? 'unknown')),
            'native_execution=' . $this->matrixComponent(
                (string)($runtime['native_execution_source'] ?? 'unknown')
            ),
            'native_arch=' . $this->matrixComponent((string)($runtime['windows_native_architecture'] ?? 'na')),
            'native_source=' . $this->matrixComponent(
                (string)($runtime['windows_native_architecture_source'] ?? 'na')
            ),
            'php=' . $this->matrixComponent((string)($runtime['php_version'] ?? 'unknown')),
            'php_binary=' . \substr((string)($runtime['php_binary_sha256'] ?? 'unknown'), 0, 16),
            'openssl=' . $this->matrixComponent((string)($runtime['openssl_version_number'] ?? 'unknown')),
            'openssl_ext=' . \substr(
                (string)($runtime['openssl_extension_binary_sha256'] ?? 'unknown'),
                0,
                16
            ),
            'topology=' . $this->matrixComponent((string)($scope['topology'] ?? 'unknown')),
            'policy=' . \substr((string)($scope['policy_digest'] ?? 'unknown'), 0, 16),
        ]);
    }

    /**
     * Production qualification is intentionally tied to the ARM64 platform
     * family being validated by this WLS release. Emulated x64 PHP on Windows
     * ARM can provide durable functional evidence, but cannot satisfy this
     * native production matrix.
     *
     * @param array<string, mixed> $runtime
     * @param array<string, mixed> $scope
     */
    private function productionPlatformProfile(array $runtime, array $scope): ?string
    {
        if (!$this->runtimeSchemaValid($runtime)) {
            return null;
        }
        $osFamily = (string)($runtime['os_family'] ?? '');
        $osArchitecture = $this->normalizeArchitecture((string)($runtime['os_architecture'] ?? ''));
        $processArchitecture = $this->normalizeArchitecture(
            (string)($runtime['process_architecture'] ?? '')
        );
        $topology = (string)($scope['topology'] ?? '');
        if ($osArchitecture !== 'arm64'
            || $processArchitecture !== 'arm64'
            || ($runtime['native_execution_verified'] ?? null) !== true
        ) {
            return null;
        }

        if ($osFamily === 'Darwin' && $topology === 'direct') {
            return 'darwin_arm64_native_direct';
        }
        if ($osFamily === 'Linux' && $topology === 'direct') {
            return 'linux_arm64_native_direct';
        }
        if ($osFamily !== 'Windows'
            || $topology !== 'dispatcher'
            || $this->normalizeArchitecture(
                (string)($runtime['windows_native_architecture'] ?? '')
            ) !== 'arm64'
            || (string)($runtime['windows_native_architecture_source'] ?? '') === ''
            || (string)($runtime['windows_native_architecture_source'] ?? '')
                === 'process_environment_ambiguous'
            || !\str_contains(\strtolower((string)($runtime['os_build'] ?? '')), 'windows 11')
        ) {
            return null;
        }

        return 'windows_11_arm64_native_dispatcher';
    }

    private function matrixComponent(string $value): string
    {
        $normalized = \preg_replace('/[^A-Za-z0-9._-]+/', '-', \trim($value)) ?? '';

        return $normalized === '' ? 'na' : \substr($normalized, 0, 96);
    }

    private function normalizeArchitecture(string $architecture): string
    {
        return match (\strtolower(\trim($architecture))) {
            'amd64', 'x86_64', 'x64' => 'x86_64',
            'arm64', 'aarch64' => 'arm64',
            'x86', 'i386', 'i486', 'i586', 'i686' => 'x86',
            default => \strtolower(\trim($architecture)) ?: 'unknown',
        };
    }

    /** @return array{0:bool,1:string} */
    private function nativeExecutionIdentity(
        string $osArchitecture,
        string $processArchitecture,
        string $windowsNativeArchitectureSource,
    ): array {
        if ($osArchitecture === 'unknown' || $processArchitecture === 'unknown') {
            return [false, 'architecture_unknown'];
        }
        if ($osArchitecture !== $processArchitecture) {
            return [false, 'binary_os_architecture_mismatch'];
        }
        if (PHP_OS_FAMILY === 'Windows') {
            $verified = $windowsNativeArchitectureSource !== ''
                && $windowsNativeArchitectureSource !== 'process_environment_ambiguous';

            return [$verified, $verified
                ? 'pe_native_architecture_match'
                : 'windows_native_architecture_ambiguous'];
        }
        if (PHP_OS_FAMILY === 'Darwin') {
            return [true, 'macho_uname_architecture_match'];
        }
        if (PHP_OS_FAMILY !== 'Linux') {
            return [false, 'unsupported_os_native_proof'];
        }

        return $this->linuxNativeExecutionIdentity($processArchitecture);
    }

    /** @return array{0:bool,1:string} */
    private function linuxNativeExecutionIdentity(string $processArchitecture): array
    {
        if (!\in_array($processArchitecture, ['arm64', 'arm', 'x86_64', 'x86'], true)) {
            return [false, 'linux_process_architecture_unsupported'];
        }
        $maps = $this->boundedProcFile('/proc/self/maps', 1_048_576);
        $cpuInfo = $this->boundedProcFile('/proc/cpuinfo', 1_048_576);
        if ($maps === null || $cpuInfo === null) {
            return [false, 'linux_procfs_unavailable'];
        }
        $translationPattern = '/(?:qemu-(?:aarch64|arm)|box64|fex(?:interpreter|loader)?)/i';
        if ((bool)\preg_match($translationPattern, $maps)) {
            return [false, 'linux_user_mode_translator_mapped'];
        }
        $binfmtNames = match ($processArchitecture) {
            'arm64' => ['qemu-aarch64', 'box64', 'FEX-aarch64'],
            'arm' => ['qemu-arm'],
            'x86_64' => ['qemu-x86_64'],
            'x86' => ['qemu-i386'],
            default => [],
        };
        foreach ($binfmtNames as $binfmtName) {
            $state = $this->boundedProcFile(
                '/proc/sys/fs/binfmt_misc/' . $binfmtName,
                16_384
            );
            if ($state !== null && (bool)\preg_match('/^enabled\b/im', $state)) {
                return [false, 'linux_user_mode_binfmt_registered'];
            }
        }
        $x86Cpu = (bool)\preg_match(
            '/(?:GenuineIntel|AuthenticAMD|vendor_id\s*:|model name\s*:.*(?:Intel|AMD))/i',
            $cpuInfo
        );
        $armCpu = (bool)\preg_match(
            '/^(?:CPU implementer|CPU architecture|Features)\s*:/mi',
            $cpuInfo
        );
        if (\in_array($processArchitecture, ['arm64', 'arm'], true) && (!$armCpu || $x86Cpu)) {
            return [false, 'linux_cpuinfo_host_architecture_mismatch'];
        }
        if (\in_array($processArchitecture, ['x86_64', 'x86'], true) && (!$x86Cpu || $armCpu)) {
            return [false, 'linux_cpuinfo_host_architecture_mismatch'];
        }

        return [true, 'elf_uname_procfs_native_' . $processArchitecture];
    }

    private function boundedProcFile(string $path, int $maxBytes): ?string
    {
        $handle = @\fopen($path, 'rb');
        if (!\is_resource($handle)) {
            return null;
        }
        try {
            $content = @\stream_get_contents($handle, $maxBytes + 1);
        } finally {
            @\fclose($handle);
        }
        if (!\is_string($content) || \strlen($content) > $maxBytes) {
            return null;
        }

        return $content;
    }

    /**
     * Read the executable format instead of trusting uname/environment values.
     * The latter describe the host kernel and can hide a translated PHP process.
     */
    private function processBinaryArchitecture(): string
    {
        $handle = @\fopen(PHP_BINARY, 'rb');
        if (\is_resource($handle)) {
            try {
                $header = @\fread($handle, 64);
                if (!\is_string($header) || \strlen($header) < 24) {
                    return 'unknown';
                }
                if (\substr($header, 0, 2) === 'MZ' && \strlen($header) >= 64) {
                    $offset = \unpack('Voffset', \substr($header, 60, 4));
                    $peOffset = \is_array($offset) ? (int)($offset['offset'] ?? 0) : 0;
                    if ($peOffset > 0 && @\fseek($handle, $peOffset) === 0) {
                        $peHeader = @\fread($handle, 6);
                        if (\is_string($peHeader) && \strlen($peHeader) === 6
                            && \substr($peHeader, 0, 4) === "PE\0\0"
                        ) {
                            $machine = \unpack('vmachine', \substr($peHeader, 4, 2));
                            $architecture = $this->windowsMachineArchitecture(
                                \is_array($machine) ? (int)($machine['machine'] ?? 0) : 0
                            );
                            if ($architecture !== 'unknown') {
                                return $architecture;
                            }
                        }
                    }
                }
                if (\substr($header, 0, 4) === "\x7fELF") {
                    $encoding = \ord($header[5]);
                    $machine = match ($encoding) {
                        1 => \unpack('vmachine', \substr($header, 18, 2)),
                        2 => \unpack('nmachine', \substr($header, 18, 2)),
                        default => false,
                    };
                    if (\is_array($machine)) {
                        return $this->elfMachineArchitecture((int)($machine['machine'] ?? 0));
                    }
                }
                $machMagic = \substr($header, 0, 4);
                $machEndian = match ($machMagic) {
                    "\xce\xfa\xed\xfe", "\xcf\xfa\xed\xfe" => 'little',
                    "\xfe\xed\xfa\xce", "\xfe\xed\xfa\xcf" => 'big',
                    default => null,
                };
                if ($machEndian !== null) {
                    $machine = \unpack(
                        $machEndian === 'little' ? 'Vmachine' : 'Nmachine',
                        \substr($header, 4, 4)
                    );
                    if (\is_array($machine)) {
                        return $this->machMachineArchitecture((int)($machine['machine'] ?? 0));
                    }
                }
            } finally {
                @\fclose($handle);
            }
        }

        return 'unknown';
    }

    private function elfMachineArchitecture(int $machine): string
    {
        return match ($machine) {
            3 => 'x86',
            40 => 'arm',
            62 => 'x86_64',
            183 => 'arm64',
            default => 'unknown',
        };
    }

    private function machMachineArchitecture(int $machine): string
    {
        return match ($machine) {
            7 => 'x86',
            12 => 'arm',
            0x01000007 => 'x86_64',
            0x0100000c => 'arm64',
            default => 'unknown',
        };
    }

    /** @return array{0:string,1:string} */
    private function windowsNativeArchitecture(string $processArchitecture): array
    {
        if (\class_exists(\FFI::class, false)) {
            try {
                $ffi = \FFI::cdef(
                    'typedef void* HANDLE; typedef int BOOL;'
                    . ' HANDLE GetCurrentProcess(void);'
                    . ' BOOL IsWow64Process2(HANDLE, unsigned short*, unsigned short*);',
                    'kernel32.dll'
                );
                $processMachine = $ffi->new('unsigned short');
                $nativeMachine = $ffi->new('unsigned short');
                $succeeded = $ffi->IsWow64Process2(
                    $ffi->GetCurrentProcess(),
                    \FFI::addr($processMachine),
                    \FFI::addr($nativeMachine)
                );
                if ($succeeded !== 0) {
                    $architecture = $this->windowsMachineArchitecture((int)$nativeMachine->cdata);
                    if ($architecture !== 'unknown') {
                        return [$architecture, 'is_wow64_process2'];
                    }
                }
            } catch (\Throwable) {
            }
        }

        $wowArchitecture = \trim((string)(\getenv('PROCESSOR_ARCHITEW6432') ?: ''));
        if ($wowArchitecture !== '') {
            return [$this->normalizeArchitecture($wowArchitecture), 'processor_architew6432'];
        }
        $processorIdentifier = \strtolower((string)(\getenv('PROCESSOR_IDENTIFIER') ?: ''));
        if (\str_contains($processorIdentifier, 'arm')
            || \str_contains($processorIdentifier, 'aarch64')
        ) {
            return ['arm64', 'processor_identifier'];
        }
        if ($processArchitecture === 'arm64') {
            return ['arm64', 'native_process'];
        }

        return [
            $this->normalizeArchitecture((string)(
                \getenv('PROCESSOR_ARCHITECTURE') ?: \php_uname('m')
            )),
            'process_environment_ambiguous',
        ];
    }

    private function windowsMachineArchitecture(int $machine): string
    {
        return match ($machine) {
            0x014c => 'x86',
            0x01c0, 0x01c2, 0x01c4 => 'arm',
            0x8664 => 'x86_64',
            0xaa64 => 'arm64',
            default => 'unknown',
        };
    }

    private function freshTlsSessionCacheConfig(): TlsSessionCacheConfig
    {
        $path = Env::path_ENV_FILE;
        if (!\is_file($path) || \is_link($path)) {
            throw new \RuntimeException('WLS environment configuration is missing or unsafe.');
        }
        $size = @\filesize($path);
        if (!\is_int($size) || $size <= 0 || $size > 8_388_608) {
            throw new \RuntimeException('WLS environment configuration size is invalid.');
        }
        $environment = (static function (string $environmentPath): mixed {
            return include $environmentPath;
        })($path);
        if (!\is_array($environment)) {
            throw new \RuntimeException('WLS environment configuration did not return an array.');
        }
        $wls = \is_array($environment['wls'] ?? null) ? $environment['wls'] : [];
        $ssl = \is_array($wls['ssl'] ?? null) ? $wls['ssl'] : [];

        return TlsSessionCacheConfig::fromSslConfig($ssl);
    }

    private function configSha256(TlsSessionCacheConfig $config): string
    {
        return $config->sha256();
    }

    /** @param array<string, mixed> $config */
    private function configSha256FromArray(array $config): string
    {
        return \hash('sha256', $this->canonicalJson($config));
    }

    /** @param array<string, mixed> $runtime */
    private function runtimeFingerprint(array $runtime): string
    {
        return \hash('sha256', $this->canonicalJson($runtime));
    }

    /** @param array<string, mixed> $document */
    private function documentSha256(array $document): string
    {
        unset($document['evidence_sha256']);

        return \hash('sha256', $this->canonicalJson($document));
    }

    /** @param array<string, mixed> $document */
    private function assertNoSecrets(array $document): void
    {
        $walk = function (array $value) use (&$walk): void {
            foreach ($value as $key => $item) {
                $normalizedKey = \strtolower((string)$key);
                if (\in_array($normalizedKey, self::FORBIDDEN_EVIDENCE_KEYS, true)) {
                    throw new \InvalidArgumentException(
                        'Forbidden secret-bearing TLS evidence key: ' . $normalizedKey
                    );
                }
                if (\is_array($item)) {
                    $walk($item);
                }
            }
        };
        $walk($document);
    }

    /**
     * @param array<string, mixed> $document
     * @return list<string>
     */
    private function documentSchemaFailures(array $document, string $path): array
    {
        $failures = [];
        if (!$this->hasExactKeys($document, self::DOCUMENT_KEYS)) {
            $failures[] = 'document_schema_keys';
        }
        if (($document['schema_version'] ?? null) !== self::SCHEMA_VERSION) {
            $failures[] = 'schema_version_mismatch';
        }
        if (($document['kind'] ?? null) !== self::KIND) {
            $failures[] = 'kind_mismatch';
        }
        $capturedAt = $document['captured_at'] ?? null;
        $capturedTimestamp = \is_string($capturedAt) ? \strtotime($capturedAt) : false;
        if ($capturedTimestamp === false || $capturedTimestamp > \time() + 300) {
            $failures[] = 'captured_at_invalid';
        }

        $runtime = \is_array($document['runtime'] ?? null) ? $document['runtime'] : [];
        if (!$this->runtimeSchemaValid($runtime)) {
            $failures[] = 'runtime_schema';
        }
        $bindings = \is_array($document['bindings'] ?? null) ? $document['bindings'] : [];
        if (!$this->hasExactKeys($bindings, self::BINDING_KEYS)) {
            $failures[] = 'bindings_schema';
        } else {
            foreach (self::BINDING_KEYS as $key) {
                if (!\is_string($bindings[$key]) || !$this->isSha256($bindings[$key])) {
                    $failures[] = 'bindings_schema';
                    break;
                }
            }
        }
        $config = \is_array($document['config'] ?? null) ? $document['config'] : [];
        if (!$this->configSchemaValid($config)) {
            $failures[] = 'config_schema';
        }
        $verification = \is_array($document['verification'] ?? null)
            ? $document['verification']
            : [];
        if (!$this->verificationSchemaValid($verification)) {
            $failures[] = 'verification_schema';
        }
        $scope = \is_array($document['scope'] ?? null) ? $document['scope'] : [];
        try {
            $this->assertScope($scope);
        } catch (\Throwable) {
            $failures[] = 'scope_schema';
        }
        $proof = \is_array($document['proof'] ?? null) ? $document['proof'] : [];
        try {
            $this->assertProofSchema($proof);
        } catch (\Throwable) {
            $failures[] = 'proof_schema';
        }
        if ($this->verificationSchemaValid($verification)
            && !\in_array('proof_schema', $failures, true)
        ) {
            try {
                $this->assertProofMatchesOptions($proof, $verification);
            } catch (\Throwable) {
                $failures[] = 'verification_proof_mismatch';
            }
        }
        if ($this->configSchemaValid($config)
            && $this->hasExactKeys($bindings, self::BINDING_KEYS)
            && $this->isSha256((string)($bindings['config_sha256'] ?? ''))
            && !\hash_equals(
                $this->configSha256FromArray($config),
                (string)$bindings['config_sha256']
            )
        ) {
            $failures[] = 'config_binding_mismatch';
        }
        $reports = $document['performance_reports'] ?? null;
        $reportsSchemaValid = true;
        if (!\is_array($reports) || !\array_is_list($reports)
            || \count($reports) > self::MAX_PERFORMANCE_REPORTS
        ) {
            $failures[] = 'performance_reports_schema';
            $reportsSchemaValid = false;
        } else {
            foreach ($reports as $report) {
                if (!\is_array($report) || !$this->performanceReportSchemaValid($report)) {
                    $failures[] = 'performance_reports_schema';
                    $reportsSchemaValid = false;
                    break;
                }
            }
        }
        if ($reportsSchemaValid
            && !$this->performanceReportsBoundToScope($reports, $scope, $runtime)
        ) {
            $failures[] = 'performance_report_binding_mismatch';
        }
        if (!\is_string($document['evidence_sha256'] ?? null)
            || !$this->isSha256((string)$document['evidence_sha256'])
        ) {
            $failures[] = 'evidence_sha256_schema';
        }
        if ($path !== '' && (\is_link($path) || \is_link(\dirname($path)))) {
            $failures[] = 'symlink_rejected';
        }

        return \array_values(\array_unique($failures));
    }

    /** @param array<string, mixed> $runtime */
    private function runtimeSchemaValid(array $runtime): bool
    {
        if (!$this->hasExactKeys($runtime, self::RUNTIME_KEYS)) {
            return false;
        }
        foreach ([
            'os_family',
            'os_release',
            'os_build',
            'os_architecture',
            'process_architecture',
            'native_execution_source',
            'windows_native_architecture',
            'windows_native_architecture_source',
            'php_version',
            'php_version_line',
            'php_release_channel',
            'php_release_stage',
            'openssl_version_text',
            'openssl_version_number',
            'openssl_extension_version',
            'openssl_extension_linkage',
        ] as $key) {
            if (!\is_string($runtime[$key])) {
                return false;
            }
        }
        if (!\is_bool($runtime['native_execution_verified'])
            || !$this->nativeExecutionSchemaValid($runtime)
        ) {
            return false;
        }

        $windowsArchitectureValid = $runtime['os_family'] === 'Windows'
            ? $runtime['windows_native_architecture'] !== ''
                && \in_array($runtime['windows_native_architecture_source'], [
                    'is_wow64_process2',
                    'processor_architew6432',
                    'processor_identifier',
                    'native_process',
                    'process_environment_ambiguous',
                ], true)
            : $runtime['windows_native_architecture'] === ''
                && $runtime['windows_native_architecture_source'] === '';

        return $windowsArchitectureValid
            && $runtime['os_architecture'] !== ''
            && $runtime['process_architecture'] !== ''
            && \is_int($runtime['php_version_id'])
            && \is_bool($runtime['php_prerelease'])
            && \in_array($runtime['php_release_channel'], ['stable', 'prerelease'], true)
            && \in_array($runtime['php_release_stage'], ['stable', 'alpha', 'beta', 'rc', 'dev'], true)
            && (($runtime['php_release_channel'] === 'prerelease') === $runtime['php_prerelease'])
            && (($runtime['php_release_stage'] !== 'stable') === $runtime['php_prerelease'])
            && $this->phpVersionIdentityValid($runtime)
            && \is_string($runtime['php_binary_sha256'])
            && $this->isSha256($runtime['php_binary_sha256'])
            && \in_array($runtime['openssl_extension_linkage'], ['builtin', 'shared'], true)
            && \is_string($runtime['openssl_extension_binary_sha256'])
            && $this->isSha256($runtime['openssl_extension_binary_sha256']);
    }

    /** @param array<string, mixed> $runtime */
    private function nativeExecutionSchemaValid(array $runtime): bool
    {
        $verified = $runtime['native_execution_verified'];
        $source = $runtime['native_execution_source'];
        $osArchitecture = $this->normalizeArchitecture($runtime['os_architecture']);
        $processArchitecture = $this->normalizeArchitecture($runtime['process_architecture']);
        if ($verified === true
            && ($osArchitecture === 'unknown' || $osArchitecture !== $processArchitecture)
        ) {
            return false;
        }
        $falseSources = [
            'architecture_unknown',
            'binary_os_architecture_mismatch',
        ];

        return match ($runtime['os_family']) {
            'Windows' => $verified === true
                ? $source === 'pe_native_architecture_match'
                : \in_array($source, [
                    ...$falseSources,
                    'windows_native_architecture_ambiguous',
                ], true),
            'Darwin' => $verified === true
                ? $source === 'macho_uname_architecture_match'
                : \in_array($source, $falseSources, true),
            'Linux' => $verified === true
                ? \in_array($processArchitecture, ['arm64', 'arm', 'x86_64', 'x86'], true)
                    && $source === 'elf_uname_procfs_native_' . $processArchitecture
                : \in_array($source, [
                    ...$falseSources,
                    'linux_process_architecture_unsupported',
                    'linux_procfs_unavailable',
                    'linux_user_mode_translator_mapped',
                    'linux_user_mode_binfmt_registered',
                    'linux_cpuinfo_host_architecture_mismatch',
                ], true),
            default => $verified === false
                && \in_array($source, [
                    ...$falseSources,
                    'unsupported_os_native_proof',
                ], true),
        };
    }

    /** @param array<string, mixed> $config */
    private function configSchemaValid(array $config): bool
    {
        if (!$this->hasExactKeys($config, self::CONFIG_KEYS)
            || !\is_string($config['mode'])
            || !\is_bool($config['enabled'])
            || !\is_string($config['context_epoch'])
        ) {
            return false;
        }
        foreach ([
            'timeout_seconds',
            'num_tickets',
            'local_cache_size',
            'max_session_bytes',
            'max_entries',
            'max_total_bytes',
        ] as $key) {
            if (!\is_int($config[$key]) || $config[$key] < 0) {
                return false;
            }
        }
        foreach (['callback_timeout_ms', 'ready_timeout_ms', 'reconnect_cooldown_ms'] as $key) {
            if ((!\is_int($config[$key]) && !\is_float($config[$key]))
                || !\is_finite((float)$config[$key])
                || (float)$config[$key] < 0.0
            ) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string, mixed> $runtime */
    private function phpVersionIdentityValid(array $runtime): bool
    {
        if (!\preg_match(
            '/^(\d+)\.(\d+)\.(\d+)(?:alpha\d+|beta\d+|RC\d+|dev.*)?$/iD',
            $runtime['php_version'],
            $matches
        )) {
            return false;
        }
        $versionId = ((int)$matches[1] * 10_000) + ((int)$matches[2] * 100) + (int)$matches[3];
        $versionLine = $matches[1] . '.' . $matches[2];
        $version = \strtolower($runtime['php_version']);
        $stage = match (true) {
            \str_contains($version, 'alpha') => 'alpha',
            \str_contains($version, 'beta') => 'beta',
            (bool)\preg_match('/rc\d*/i', $version) => 'rc',
            \str_contains($version, 'dev') => 'dev',
            default => 'stable',
        };

        return $runtime['php_version_id'] === $versionId
            && $runtime['php_version_line'] === $versionLine
            && $runtime['php_release_stage'] === $stage;
    }

    /** @param array<string, mixed> $report */
    private function performanceReportSchemaValid(array $report): bool
    {
        if (!$this->hasExactKeys($report, self::PERFORMANCE_REPORT_KEYS)
            || !\is_string($report['path'])
            || !$this->isSafeProjectRelativePath($report['path'])
            || !\is_string($report['sha256'])
            || !$this->isSha256($report['sha256'])
            || !\is_string($report['generated_at'])
            || \strtotime($report['generated_at']) === false
            || (int)\strtotime($report['generated_at']) > \time() + 300
            || !\is_bool($report['benchmark_valid'])
            || !\is_bool($report['quality_gate_passed'])
            || !\is_string($report['integration_sha256'])
            || !$this->isSha256($report['integration_sha256'])
            || !\is_string($report['verifier_sha256'])
            || !$this->isSha256($report['verifier_sha256'])
            || !\is_string($report['instance_name'])
            || !\is_string($report['policy_digest'])
            || !$this->isSha256($report['policy_digest'])
            || !\is_string($report['target_host_sha256'])
            || !$this->isSha256($report['target_host_sha256'])
            || !\is_string($report['os_family'])
            || !\is_string($report['process_architecture'])
            || !\is_string($report['php_version'])
            || !\is_string($report['topology'])
            || !\is_int($report['worker_count'])
            || $report['worker_count'] < 2
            || !\is_string($report['http_version'])
            || !\is_int($report['requests'])
            || !\is_int($report['success_count'])
            || $report['requests'] < 0
            || $report['success_count'] < 0
        ) {
            return false;
        }
        foreach (['qps', 'request_p95_ms', 'tls_handshake_p95_ms'] as $key) {
            if ((!\is_int($report[$key]) && !\is_float($report[$key]))
                || !\is_finite((float)$report[$key])
                || (float)$report[$key] < 0.0
            ) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string, mixed> $verification */
    private function verificationSchemaValid(array $verification): bool
    {
        if (!$this->hasExactKeys($verification, self::VERIFICATION_KEYS)) {
            return false;
        }
        foreach (\array_diff(self::VERIFICATION_KEYS, ['resumption_tls_p95_limit_ms']) as $key) {
            if (!\is_int($verification[$key]) || $verification[$key] < 0) {
                return false;
            }
        }
        $latencyLimit = $verification['resumption_tls_p95_limit_ms'];

        return (\is_int($latencyLimit) || \is_float($latencyLimit))
            && \is_finite((float)$latencyLimit)
            && (float)$latencyLimit > 0.0;
    }

    /** @param array<string, mixed> $proof */
    private function resumptionLatencyVerified(array $proof): bool
    {
        $p95 = $proof['resumption_tls_p95_ms'] ?? null;
        $limit = $proof['resumption_tls_p95_limit_ms'] ?? null;

        return (\is_int($p95) || \is_float($p95))
            && (\is_int($limit) || \is_float($limit))
            && \is_finite((float)$p95)
            && \is_finite((float)$limit)
            && (float)$p95 >= 0.0
            && (float)$limit > 0.0
            && (float)$p95 <= \min(
                (float)$limit,
                self::PRODUCTION_RESUMPTION_TLS_P95_LIMIT_MS,
            );
    }

    /** @param array<string, mixed> $proof */
    private function requestedResumptionLatencyVerified(array $proof): bool
    {
        $p95 = $proof['resumption_tls_p95_ms'] ?? null;
        $limit = $proof['resumption_tls_p95_limit_ms'] ?? null;

        return (\is_int($p95) || \is_float($p95))
            && (\is_int($limit) || \is_float($limit))
            && \is_finite((float)$p95)
            && \is_finite((float)$limit)
            && (float)$p95 >= 0.0
            && (float)$limit > 0.0
            && (float)$p95 <= (float)$limit;
    }

    /**
     * @param array<string, mixed> $value
     * @param list<string> $expectedKeys
     */
    private function assertExactKeys(array $value, array $expectedKeys, string $label): void
    {
        if (!$this->hasExactKeys($value, $expectedKeys)) {
            throw new \InvalidArgumentException('TLS evidence ' . $label . ' does not match its fixed schema.');
        }
    }

    /**
     * @param array<string, mixed> $value
     * @param list<string> $expectedKeys
     */
    private function hasExactKeys(array $value, array $expectedKeys): bool
    {
        if (\array_is_list($value)) {
            return false;
        }
        $actual = \array_keys($value);
        \sort($actual, SORT_STRING);
        \sort($expectedKeys, SORT_STRING);

        return $actual === $expectedKeys;
    }

    /**
     * @return array{paths:list<string>,overflow:bool}
     */
    private function boundedJsonFiles(string $directory, bool $recursive): array
    {
        if (!\is_dir($directory) || \is_link($directory)) {
            return ['paths' => [], 'overflow' => false];
        }
        $paths = [];
        try {
            $directories = [$directory];
            while ($directories !== []) {
                $current = \array_shift($directories);
                if (!\is_string($current) || \is_link($current)) {
                    continue;
                }
                $iterator = new \FilesystemIterator($current, \FilesystemIterator::SKIP_DOTS);
                foreach ($iterator as $entry) {
                    if ($entry->isLink()) {
                        continue;
                    }
                    if ($recursive && $entry->isDir()) {
                        if ((bool)\preg_match('/^[a-f0-9]{64}$/D', $entry->getFilename())) {
                            $directories[] = $entry->getPathname();
                        }
                        continue;
                    }
                    if (!$entry->isFile()
                        || !(bool)\preg_match('/^[a-f0-9]{64}\.json$/D', $entry->getFilename())
                    ) {
                        continue;
                    }
                    $paths[] = $entry->getPathname();
                    if (\count($paths) > self::MAX_EVIDENCE_FILES) {
                        return ['paths' => [], 'overflow' => true];
                    }
                }
            }
        } catch (\UnexpectedValueException) {
            return ['paths' => [], 'overflow' => false];
        }
        \sort($paths, SORT_STRING);

        return ['paths' => $paths, 'overflow' => false];
    }

    /** @return array<string, mixed>|null */
    private function readDocument(string $path): ?array
    {
        if (!\is_file($path) || \is_link($path) || \is_link(\dirname($path))) {
            return null;
        }
        $size = @\filesize($path);
        if (!\is_int($size) || $size <= 0 || $size > self::MAX_EVIDENCE_BYTES) {
            return null;
        }
        $raw = @\file_get_contents($path);
        if (!\is_string($raw) || $raw === '' || \strlen($raw) > self::MAX_EVIDENCE_BYTES) {
            return null;
        }
        try {
            $document = \json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return \is_array($document) && !\array_is_list($document) ? $document : null;
    }

    private function ensureEvidenceDirectory(string $directory): void
    {
        $root = $this->root();
        if (\is_link($root) || \is_link($directory)) {
            throw new \RuntimeException('TLS evidence directories must not be symbolic links.');
        }
        if (!\is_dir($directory) && !@\mkdir($directory, 0750, true) && !\is_dir($directory)) {
            throw new \RuntimeException('Unable to create TLS evidence directory: ' . $directory);
        }
        if (\is_link($root) || \is_link($directory)) {
            throw new \RuntimeException('TLS evidence directory became a symbolic link.');
        }
    }

    private function canonicalJson(mixed $value): string
    {
        $normalize = function (mixed $item) use (&$normalize): mixed {
            if (!\is_array($item)) {
                return $item;
            }
            if (!\array_is_list($item)) {
                \ksort($item, SORT_STRING);
            }
            foreach ($item as $key => $nested) {
                $item[$key] = $normalize($nested);
            }

            return $item;
        };

        return \json_encode(
            $normalize($value),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );
    }

    private function isSha256(string $value): bool
    {
        return (bool)\preg_match('/^[a-f0-9]{64}$/D', $value);
    }

    private function isSafeProjectRelativePath(string $path): bool
    {
        if ($path === '' || \strlen($path) > 512 || \str_contains($path, '\\')) {
            return false;
        }
        foreach (\explode('/', $path) as $segment) {
            if ($segment === ''
                || $segment === '.'
                || $segment === '..'
                || !\preg_match('/^[A-Za-z0-9._@+ -]+$/D', $segment)
            ) {
                return false;
            }
        }

        return true;
    }

    private function isAbsolutePath(string $path): bool
    {
        return \str_starts_with($path, '/')
            || \str_starts_with($path, '\\\\')
            || (bool)\preg_match('/^[A-Za-z]:[\\\\\/]/D', $path);
    }

    /** @return array<string, mixed> */
    private function emptyAssessment(string $reason): array
    {
        return [
            'evidence_available' => false,
            'evidence_integrity_valid' => false,
            'evidence_path' => '',
            'evidence_sha256' => '',
            'runtime_identity_matches' => false,
            'runtime_prerelease' => (bool)$this->currentRuntimeIdentity()['php_prerelease'],
            'runtime_matrix_key' => $this->platformMatrixKey($this->currentRuntimeIdentity()),
            'integration_matches_evidence' => false,
            'verifier_matches_evidence' => false,
            'active_config_matches_evidence' => false,
            'current_scope_matches_evidence' => false,
            'implementation_supported' => TlsSessionCacheRuntime::apiAvailable(),
            'runtime_mechanism_verified' => false,
            'active_runtime_verified' => false,
            'server_session_reuse_observable' => false,
            'same_worker_verified' => false,
            'cross_worker_verified' => false,
            'reload_continuity_verified' => false,
            'sidecar_recovery_verified' => false,
            'performance_baseline_verified' => false,
            'resumption_latency_gate_verified' => false,
            'production_platform_matrix_verified' => false,
            'platforms_verified' => [],
            'platform_families_verified' => [],
            'production_ready' => false,
            'proof_summary' => [],
            'failure_reasons' => ['evidence_unavailable'],
            'reason' => $reason,
        ];
    }

    private function root(): string
    {
        if ($this->rootDirectory !== null && \trim($this->rootDirectory) !== '') {
            return \rtrim($this->rootDirectory, '/\\');
        }

        return $this->projectRoot() . DIRECTORY_SEPARATOR . 'var'
            . DIRECTORY_SEPARATOR . 'server'
            . DIRECTORY_SEPARATOR . 'runtime-evidence'
            . DIRECTORY_SEPARATOR . 'tls-session-resumption';
    }

    private function projectRoot(): string
    {
        return \rtrim((string)BP, '/\\');
    }

    private function relativePath(string $path): string
    {
        $root = $this->projectRoot() . DIRECTORY_SEPARATOR;
        if (\str_starts_with($path, $root)) {
            return \str_replace('\\', '/', \substr($path, \strlen($root)));
        }

        return \str_replace('\\', '/', $path);
    }
}
