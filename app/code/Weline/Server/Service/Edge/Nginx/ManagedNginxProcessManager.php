<?php

declare(strict_types=1);

namespace Weline\Server\Service\Edge\Nginx;

use Weline\Framework\System\Process\Processer;
use Weline\Framework\Runtime\SchedulerSystem;
use Weline\Server\Service\Edge\Gateway\GatewayBoundedCommandRunner;
use Weline\Server\Service\Edge\Gateway\GatewayProjectStateFilesystem;
use Weline\Server\Service\Edge\Nginx\Runtime\NginxChildProcessProbe;
use Weline\Server\Service\Edge\Nginx\Runtime\NginxProcessIdentity;
use Weline\Server\Service\MasterLeaseRuntimeIdentity;

/**
 * Start/stop/reload the per-project managed nginx process.
 */
final class ManagedNginxProcessManager
{
    private const MAX_CONFIG_BYTES = 16 * 1024 * 1024;
    private const COMMAND_TIMEOUT_SECONDS = 30.0;
    private const GRACEFUL_STOP_TIMEOUT_SECONDS = 30.0;
    private const RELOAD_OLD_WORKER_TIMEOUT_SECONDS = 15.0;
    private const CONFIG_TEST_LOCK_WAIT_SECONDS = 45.0;
    private const CONFIG_TEST_PREFIX = 'wls-nginx-config-test-';
    private const LEGACY_CONFIG_TEST_PID_PREFIX = 'nginx-config-test-';
    private const CONFIG_TEST_LOCK_LEAF = 'managed-nginx.config-test.lock';
    private const MAX_CONFIG_TEST_DIRECTORY_ENTRIES = 8192;
    private const MAX_CONFIG_TEST_ARTIFACTS = 32;
    private const MAX_CONFIG_TEST_ARTIFACTS_PER_TOKEN = 10;
    private const MAX_CONFIG_TEST_PID_BYTES = 32;

    private readonly ManagedNginxPaths $paths;
    private readonly NginxProcessIdentity $processIdentity;

    public function __construct(
        ?ManagedNginxPaths $paths = null,
        ?NginxProcessIdentity $processIdentity = null,
    ) {
        $this->paths = $paths ?? new ManagedNginxPaths();
        $this->processIdentity = $processIdentity ?? new NginxProcessIdentity(
            role: 'legacy-project-nginx',
            binary: $this->paths->binary(),
            prefix: $this->paths->runtimeRoot(),
            config: $this->paths->confFile(),
            installManifest: $this->paths->manifestFile(),
            processManifest: $this->paths->runDir() . DIRECTORY_SEPARATOR . 'nginx.process-identity.json',
        );
    }

    /**
     * @return array{ok:bool,running:bool,pid:int|null,message:string}
     */
    public function status(): array
    {
        try {
            $pid = $this->readPid();
        } catch (\Throwable $throwable) {
            return [
                'ok' => false,
                'running' => false,
                'pid' => null,
                'message' => 'pid file is unreadable or malformed: ' . $throwable->getMessage(),
            ];
        }
        if ($pid === null) {
            try {
                $recordedPid = $this->processIdentity->recordedPid();
            } catch (\Throwable $throwable) {
                return [
                    'ok' => false,
                    'running' => false,
                    'pid' => null,
                    'message' => 'process identity is unreadable or malformed: '
                        . $throwable->getMessage(),
                ];
            }
            if ($recordedPid !== null) {
                $recordedState = $this->pidState($recordedPid);
                if ($recordedState === Processer::PROCESS_STATE_RUNNING) {
                    return [
                        'ok' => false,
                        'running' => false,
                        'pid' => $recordedPid,
                        'message' => 'verified nginx identity is running without its authoritative pid file',
                    ];
                }
                if ($recordedState !== Processer::PROCESS_STATE_EXITED) {
                    return [
                        'ok' => false,
                        'running' => false,
                        'pid' => $recordedPid,
                        'message' => 'process identity PID state is unknown while the authoritative pid file is missing',
                    ];
                }
            }
            return ['ok' => true, 'running' => false, 'pid' => null, 'message' => 'not running'];
        }
        $pidState = $this->pidState($pid);
        if ($pidState === Processer::PROCESS_STATE_EXITED) {
            try {
                $recordedPid = $this->processIdentity->recordedPid();
            } catch (\Throwable $throwable) {
                return [
                    'ok' => false,
                    'running' => false,
                    'pid' => $pid,
                    'message' => 'stale pid file cannot be reconciled with the process identity: '
                        . $throwable->getMessage(),
                ];
            }
            if ($recordedPid !== null) {
                $recordedState = $this->pidState($recordedPid);
                if ($recordedState === Processer::PROCESS_STATE_RUNNING) {
                    if ($recordedPid !== $pid) {
                        return [
                            'ok' => false,
                            'running' => false,
                            'pid' => $recordedPid,
                            'message' => 'a PID-bound managed nginx generation is running while its authoritative pid file is stale',
                        ];
                    }
                    // The PID can become observable between the two probes.
                    // Continue through the full process-identity fence instead
                    // of deleting a pid file that now names a live process.
                    $pidState = $recordedState;
                } elseif ($recordedState !== Processer::PROCESS_STATE_EXITED) {
                    return [
                        'ok' => false,
                        'running' => false,
                        'pid' => $recordedPid,
                        'message' => 'stale pid file cannot be reconciled because the PID-bound process state is unknown',
                    ];
                }
            }
            if ($pidState === Processer::PROCESS_STATE_EXITED) {
                return ['ok' => true, 'running' => false, 'pid' => $pid, 'message' => 'stale pid file'];
            }
        }
        if ($pidState !== Processer::PROCESS_STATE_RUNNING) {
            return ['ok' => false, 'running' => false, 'pid' => $pid, 'message' => 'pid state is unknown'];
        }
        $identity = $this->inspectPidIdentity($pid);
        if (!($identity['ok'] ?? false)) {
            return [
                'ok' => false,
                'running' => false,
                'pid' => $pid,
                'message' => 'pid identity mismatch: ' . (string)($identity['reason'] ?? 'unknown'),
            ];
        }
        return [
            'ok' => true,
            'running' => true,
            'pid' => $pid,
            'message' => 'running',
            'binary_sha256' => (string)($identity['binary_sha256'] ?? ''),
            'runtime_generation' => (string)($identity['runtime_generation'] ?? ''),
        ];
    }

    /**
     * @return array{ok:bool,message:string,pid:int|null}
     */
    public function start(): array
    {
        if (!$this->paths->isInstalled()) {
            return [
                'ok' => false,
                'message' => 'managed nginx binary missing; run php bin/w server:nginx:install',
                'pid' => null,
            ];
        }
        if (!\is_file($this->paths->confFile())) {
            return ['ok' => false, 'message' => 'managed nginx.conf missing; generate config first', 'pid' => null];
        }
        $runtime = $this->processIdentity->runtimeStatus();
        if (!($runtime['ok'] ?? false)) {
            return [
                'ok' => false,
                'message' => 'managed nginx runtime identity is invalid: '
                    . (string)($runtime['reason'] ?? 'unknown'),
                'pid' => null,
            ];
        }
        $status = $this->status();
        if (!($status['ok'] ?? false)) {
            return [
                'ok' => false,
                'message' => 'managed nginx PID identity is unsafe: ' . (string)$status['message'],
                'pid' => $status['pid'],
            ];
        }
        if ($status['running']) {
            return ['ok' => true, 'message' => 'already running', 'pid' => $status['pid']];
        }

        $this->paths->ensureRuntimeDirectories();
        $recordedPid = $this->processIdentity->recordedPid();
        if ($recordedPid !== null) {
            $recordedState = $this->pidState($recordedPid);
            if ($recordedState === Processer::PROCESS_STATE_RUNNING) {
                return [
                    'ok' => false,
                    'message' => 'refusing start: a PID-bound managed Nginx generation is still running',
                    'pid' => $recordedPid,
                ];
            }
            if ($recordedState !== Processer::PROCESS_STATE_EXITED) {
                return [
                    'ok' => false,
                    'message' => 'refusing start: the PID-bound managed Nginx process state is unknown',
                    'pid' => $recordedPid,
                ];
            }
            $this->processIdentity->clear($recordedPid);
        }
        $test = $this->runNginx(['-t']);
        if (($test['code'] ?? 1) !== 0) {
            return [
                'ok' => false,
                'message' => 'nginx -t failed: ' . \trim((string)($test['output'] ?? '')),
                'pid' => null,
            ];
        }

        // Clear stale pid so a fresh master writes a new one.
        if ((\file_exists($this->paths->pidFile()) || \is_link($this->paths->pidFile()))
            && !$status['running']
        ) {
            try {
                GatewayProjectStateFilesystem::removeRegular(
                    $this->paths->pidFile(),
                    'stale managed Nginx PID file',
                );
            } catch (\Throwable $throwable) {
                return [
                    'ok' => false,
                    'message' => 'unable to clear stale managed Nginx PID file: '
                        . $throwable->getMessage(),
                    'pid' => $status['pid'],
                ];
            }
        }

        $started = $this->startNginx();
        if (($started['code'] ?? 1) !== 0) {
            return [
                'ok' => false,
                'message' => 'nginx start failed: ' . \trim((string)($started['output'] ?? '')),
                'pid' => null,
            ];
        }

        for ($i = 0; $i < 20; $i++) {
            SchedulerSystem::usleep(100000);
            $startedStatus = $this->status();
            if (!($startedStatus['ok'] ?? false)) {
                return [
                    'ok' => false,
                    'message' => 'started nginx PID identity could not be verified: '
                        . (string)($startedStatus['message'] ?? 'unknown'),
                    'pid' => $startedStatus['pid'],
                ];
            }
            if ($startedStatus['running']) {
                return ['ok' => true, 'message' => 'started', 'pid' => $startedStatus['pid']];
            }
        }

        return [
            'ok' => false,
            'message' => 'nginx exited immediately after start; check '
                . $this->paths->logsDir() . DIRECTORY_SEPARATOR . 'error.log',
            'pid' => null,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function probeBinaryCapabilities(): array
    {
        if (!$this->paths->isInstalled()) {
            return [
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
                'output' => '',
            ];
        }
        $probe = GatewayBoundedCommandRunner::run(
            [$this->paths->binary(), '-V'],
            self::COMMAND_TIMEOUT_SECONDS,
        );
        $code = (int)($probe['code'] ?? 1);
        $text = (string)($probe['output'] ?? '');
        $version = '';
        if (\preg_match('/nginx\/(\d+\.\d+\.\d+)/i', $text, $match) === 1) {
            $version = (string)$match[1];
        }
        $http2Module = \str_contains($text, '--with-http_v2_module');
        $http3ConfigureFlag = \str_contains($text, '--with-http_v3_module');
        $http3Module = $http3ConfigureFlag && \PHP_OS_FAMILY !== 'Windows';
        $http3Reason = \PHP_OS_FAMILY === 'Windows'
            ? 'ngx_http_v3_module is not supported on Win32; using HTTP/2 with HTTP/1.1 fallback.'
            : ($http3Module
                ? 'ngx_http_v3_module is compiled in.' : 'nginx was built without --with-http_v3_module.');
        $httpSslModule = \str_contains($text, '--with-http_ssl_module');
        $rewriteModule = !\str_contains($text, '--without-http_rewrite_module');
        $gzipModule = !\str_contains($text, '--without-http_gzip_module');
        $opensslVersion = '';
        $opensslComparableVersion = '';
        if (\preg_match_all(
            '/(?:built|running) with OpenSSL\s+([0-9]+\.[0-9]+\.[0-9]+[^\s]*)/i',
            $text,
            $matches,
        ) > 0) {
            $detectedVersions = $matches[1];
            $opensslVersion = (string)\end($detectedVersions);
            if (\preg_match('/\A([0-9]+\.[0-9]+\.[0-9]+)/', $opensslVersion, $versionMatch) === 1) {
                $opensslComparableVersion = (string)$versionMatch[1];
            }
        }
        $tls13Capable = $httpSslModule
            && $opensslComparableVersion !== ''
            && \version_compare($opensslComparableVersion, '1.1.1', '>=');
        $ok = $code === 0 && $version !== '' && $rewriteModule;
        $quicTlsRecommended = $http3Module
            && $opensslComparableVersion !== ''
            && \version_compare($opensslComparableVersion, '3.5.1', '>=');
        $sharedSessionTicketKeys = $httpSslModule && $version !== ''
            && \version_compare($version, '1.23.2', '>=');
        return [
            'ok' => $ok,
            'version' => $version,
            'http2_module' => $http2Module,
            'http_ssl_module' => $httpSslModule,
            'rewrite_module' => $rewriteModule,
            'gzip_module' => $gzipModule,
            'http3_module' => $http3Module,
            'http3_configure_flag' => $http3ConfigureFlag,
            'http3_reason' => $http3Reason,
            'quic_tls_recommended' => $quicTlsRecommended,
            'shared_session_ticket_keys' => $sharedSessionTicketKeys,
            'openssl_version' => $opensslVersion,
            'tls13_capable' => $tls13Capable,
            'message' => $ok
                ? 'nginx binary capabilities detected'
                : ($code === 0 && $version !== '' && !$rewriteModule
                    ? 'managed nginx requires the HTTP rewrite module'
                    : 'unable to inspect managed nginx binary'),
            'output' => $text,
        ];
    }

    /** @return array{code:int,output:string} */
    public function testConfig(string $configFile): array
    {
        if (!\is_file($configFile) || \dirname($configFile) !== $this->paths->confDir()) {
            return ['code' => 1, 'output' => 'managed nginx candidate config is outside the isolated conf directory'];
        }

        try {
            return GatewayProjectStateFilesystem::withExclusiveLock(
                $this->configTestLockFile(),
                fn(): array => $this->testConfigLocked($configFile),
                waitTimeoutSeconds: self::CONFIG_TEST_LOCK_WAIT_SECONDS,
            );
        } catch (\Throwable $throwable) {
            return [
                'code' => 1,
                'output' => 'managed nginx config-test namespace recovery failed: '
                    . $throwable->getMessage(),
            ];
        }
    }

    /** @return array{code:int,output:string} */
    private function testConfigLocked(string $configFile): array
    {
        $this->cleanupConfigTestArtifacts($configFile);

        try {
            $config = GatewayProjectStateFilesystem::read(
                $configFile,
                self::MAX_CONFIG_BYTES,
                'managed Nginx candidate config',
            );
            $token = \bin2hex(\random_bytes(16));
        } catch (\Throwable $throwable) {
            return [
                'code' => 1,
                'output' => 'managed nginx candidate config is unreadable: '
                    . $throwable->getMessage(),
            ];
        }
        $testPidName = self::CONFIG_TEST_PREFIX . $token . '.pid';
        $testConfig = $this->paths->confDir() . DIRECTORY_SEPARATOR
            . self::CONFIG_TEST_PREFIX . $token . '.conf';
        $testPidFile = $this->paths->runDir() . DIRECTORY_SEPARATOR . $testPidName;
        $pidDirectivePattern = '/^\s*pid\s+[^;\r\n]+;\s*$/m';
        if (\preg_match_all($pidDirectivePattern, $config) !== 1) {
            return ['code' => 1, 'output' => 'managed nginx candidate must contain exactly one isolated pid directive'];
        }
        $replacementCount = 0;
        $isolatedConfig = \preg_replace(
            $pidDirectivePattern,
            'pid        run/' . $testPidName . ';',
            $config,
            1,
            $replacementCount,
        );
        if (!\is_string($isolatedConfig) || $replacementCount !== 1) {
            return ['code' => 1, 'output' => 'managed nginx candidate must contain exactly one isolated pid directive'];
        }
        if (\file_exists($testConfig) || \is_link($testConfig)) {
            return ['code' => 1, 'output' => 'isolated managed nginx test config already exists'];
        }
        try {
            GatewayProjectStateFilesystem::atomicWrite(
                $testConfig,
                $isolatedConfig,
                0600,
            );
        } catch (\Throwable $throwable) {
            return [
                'code' => 1,
                'output' => 'unable to write isolated managed nginx test config: '
                    . $throwable->getMessage(),
            ];
        }
        try {
            $result = $this->runNginx(['-t'], $testConfig);
        } catch (\Throwable $throwable) {
            $result = [
                'code' => 1,
                'output' => 'unable to execute isolated managed nginx config test: '
                    . $throwable->getMessage(),
            ];
        }

        try {
            $this->cleanupConfigTestArtifacts($configFile);
        } catch (\Throwable $throwable) {
            $output = \trim($result['output']);
            $cleanup = 'unable to remove isolated managed nginx config-test artifacts: '
                . $throwable->getMessage();
            return [
                'code' => 1,
                'output' => $output === '' ? $cleanup : $output . "\n" . $cleanup,
            ];
        }
        return $result;
    }

    private function configTestLockFile(): string
    {
        return $this->paths->runDir() . DIRECTORY_SEPARATOR
            . self::CONFIG_TEST_LOCK_LEAF;
    }

    /**
     * Collect exact config-test crash evidence while the stable namespace lock
     * is held. Discovery, semantic validation and a complete second snapshot
     * finish before the first unlink. Any ambiguous PID or unsafe leaf keeps
     * the whole set available for diagnosis instead of partially collecting it.
     */
    private function cleanupConfigTestArtifacts(string $protectedConfigFile): void
    {
        $selected = $this->configTestArtifactSnapshot($protectedConfigFile);
        if ($selected['artifacts'] === []) {
            return;
        }
        $rechecked = $this->configTestArtifactSnapshot($protectedConfigFile);
        if (\array_keys($selected['artifacts']) !== \array_keys($rechecked['artifacts'])) {
            throw new \RuntimeException(
                'Managed Nginx config-test recovery artifact set changed before cleanup.',
            );
        }
        foreach ($selected['directories'] as $directory => $identity) {
            $current = $rechecked['directories'][$directory] ?? null;
            if (!\is_array($current)
                || !$this->sameConfigTestStableState($identity, $current)
            ) {
                throw new \RuntimeException(
                    'Managed Nginx config-test recovery directory changed before cleanup.',
                );
            }
        }
        foreach ($selected['artifacts'] as $path => $artifact) {
            $current = $rechecked['artifacts'][$path] ?? null;
            if (!\is_array($current)
                || !\hash_equals($artifact['kind'], $current['kind'])
                || !\hash_equals($artifact['token'], $current['token'])
                || !\hash_equals($artifact['sha256'], $current['sha256'])
                || $artifact['pid'] !== $current['pid']
                || !$this->sameConfigTestStableState(
                    $artifact['identity'],
                    $current['identity'],
                )
            ) {
                throw new \RuntimeException(
                    'Managed Nginx config-test recovery artifact changed before cleanup.',
                );
            }
        }

        // Close the final path/content/PID-state race for every selected leaf
        // before mutating either directory. In particular, no safe config is
        // removed before a later malformed or live PID is discovered.
        foreach ($rechecked['artifacts'] as $artifact) {
            $contents = GatewayProjectStateFilesystem::read(
                $artifact['path'],
                $artifact['kind'] === 'pid'
                    ? self::MAX_CONFIG_TEST_PID_BYTES
                    : self::MAX_CONFIG_BYTES,
                'managed Nginx config-test recovery artifact',
                $artifact['kind'] === 'staging',
            );
            $identity = @\lstat($artifact['path']);
            if (!\is_array($identity)
                || !$this->sameConfigTestStableState($artifact['identity'], $identity)
                || !\hash_equals($artifact['sha256'], \hash('sha256', $contents))
            ) {
                throw new \RuntimeException(
                    'Managed Nginx config-test recovery artifact changed during final preflight.',
                );
            }
            if ($artifact['kind'] === 'pid') {
                $pid = $this->parseConfigTestPid($contents);
                if ($pid !== $artifact['pid']) {
                    throw new \RuntimeException(
                        'Managed Nginx config-test PID changed during final preflight.',
                    );
                }
                $this->assertConfigTestPidExited($pid);
            }
        }
        foreach ($rechecked['directories'] as $directory => $identity) {
            $current = @\lstat($directory);
            if (!\is_array($current)
                || !$this->sameConfigTestStableState($identity, $current)
            ) {
                throw new \RuntimeException(
                    'Managed Nginx config-test recovery directory changed during final preflight.',
                );
            }
        }

        $artifacts = \array_values($rechecked['artifacts']);
        \usort(
            $artifacts,
            static fn(array $left, array $right): int => [
                $left['kind'] === 'pid' ? 0 : 1,
                $left['path'],
            ] <=> [
                $right['kind'] === 'pid' ? 0 : 1,
                $right['path'],
            ],
        );
        foreach ($artifacts as $artifact) {
            if ($artifact['kind'] === 'pid') {
                $contents = GatewayProjectStateFilesystem::read(
                    $artifact['path'],
                    self::MAX_CONFIG_TEST_PID_BYTES,
                    'managed Nginx config-test PID artifact',
                );
                $pid = $this->parseConfigTestPid($contents);
                if ($pid !== $artifact['pid']) {
                    throw new \RuntimeException(
                        'Managed Nginx config-test PID changed before removal.',
                    );
                }
                $this->assertConfigTestPidExited($pid);
            }
            if (!GatewayProjectStateFilesystem::removeRegular(
                $artifact['path'],
                'managed Nginx config-test ' . $artifact['kind'] . ' artifact',
                $artifact['identity'],
            )) {
                throw new \RuntimeException(
                    'Unable to collect a managed Nginx config-test recovery artifact.',
                );
            }
        }
    }

    /**
     * @return array{
     *   artifacts:array<string,array{
     *     path:string,
     *     kind:string,
     *     token:string,
     *     sha256:string,
     *     pid:int|null,
     *     identity:array<string|int,mixed>
     *   }>,
     *   directories:array<string,array<string|int,mixed>>
     * }
     */
    private function configTestArtifactSnapshot(string $protectedConfigFile): array
    {
        $artifacts = [];
        $directories = [];
        $perToken = [];
        foreach ([
            [$this->paths->confDir(), true],
            [$this->paths->runDir(), false],
        ] as [$directory, $isConfigDirectory]) {
            $directoryBefore = @\lstat($directory);
            if (!\is_array($directoryBefore)
                || \is_link($directory)
                || ((((int)$directoryBefore['mode']) & 0170000) !== 0040000)
            ) {
                throw new \RuntimeException(
                    'Managed Nginx config-test recovery directory is unsafe.',
                );
            }
            $stream = @\opendir($directory);
            if (!\is_resource($stream)) {
                throw new \RuntimeException(
                    'Unable to enumerate the managed Nginx config-test recovery directory.',
                );
            }
            $rawEntries = 0;
            try {
                while (($leaf = @\readdir($stream)) !== false) {
                    if ($leaf === '.' || $leaf === '..') {
                        continue;
                    }
                    if (++$rawEntries > self::MAX_CONFIG_TEST_DIRECTORY_ENTRIES) {
                        throw new \RuntimeException(
                            'Managed Nginx config-test recovery directory quota is exhausted.',
                        );
                    }
                    $classification = $this->classifyConfigTestArtifact(
                        $leaf,
                        $isConfigDirectory,
                    );
                    if ($classification === null) {
                        continue;
                    }
                    $path = $directory . DIRECTORY_SEPARATOR . $leaf;
                    if ($path === $protectedConfigFile) {
                        throw new \RuntimeException(
                            'Managed Nginx candidate collides with the reserved config-test recovery namespace.',
                        );
                    }
                    if (\count($artifacts) >= self::MAX_CONFIG_TEST_ARTIFACTS
                        || ($perToken[$classification['token']] ?? 0)
                            >= self::MAX_CONFIG_TEST_ARTIFACTS_PER_TOKEN
                    ) {
                        throw new \RuntimeException(
                            'Managed Nginx config-test recovery artifact quota is exhausted.',
                        );
                    }
                    $maximumBytes = $classification['kind'] === 'pid'
                        ? self::MAX_CONFIG_TEST_PID_BYTES
                        : self::MAX_CONFIG_BYTES;
                    $contents = GatewayProjectStateFilesystem::read(
                        $path,
                        $maximumBytes,
                        'managed Nginx config-test recovery artifact',
                        $classification['kind'] === 'staging',
                    );
                    $pid = null;
                    if ($classification['kind'] === 'pid') {
                        $pid = $this->parseConfigTestPid($contents);
                        $this->assertConfigTestPidExited($pid);
                    } elseif ($classification['kind'] === 'config') {
                        $this->assertConfigTestArtifactContents(
                            $contents,
                            $classification['pid_leaf'],
                        );
                    }
                    $identity = @\lstat($path);
                    if (!\is_array($identity)) {
                        throw new \RuntimeException(
                            'Managed Nginx config-test recovery artifact disappeared during discovery.',
                        );
                    }
                    $artifacts[$path] = [
                        'path' => $path,
                        'kind' => $classification['kind'],
                        'token' => $classification['token'],
                        'sha256' => \hash('sha256', $contents),
                        'pid' => $pid,
                        'identity' => $identity,
                    ];
                    $perToken[$classification['token']]
                        = ($perToken[$classification['token']] ?? 0) + 1;
                }
            } finally {
                @\closedir($stream);
            }
            $directoryAfter = @\lstat($directory);
            if (!\is_array($directoryAfter)
                || !$this->sameConfigTestStableState($directoryBefore, $directoryAfter)
            ) {
                throw new \RuntimeException(
                    'Managed Nginx config-test recovery directory changed during discovery.',
                );
            }
            $directories[$directory] = $directoryAfter;
        }
        \ksort($artifacts, SORT_STRING);
        \ksort($directories, SORT_STRING);
        return ['artifacts' => $artifacts, 'directories' => $directories];
    }

    /**
     * @return array{kind:string,token:string,pid_leaf:string}|null
     */
    private function classifyConfigTestArtifact(
        string $leaf,
        bool $isConfigDirectory,
    ): ?array {
        $foldedLeaf = \strtolower($leaf);
        if ($isConfigDirectory) {
            if (\str_starts_with($foldedLeaf, self::CONFIG_TEST_PREFIX)) {
                if (!\str_starts_with($leaf, self::CONFIG_TEST_PREFIX)) {
                    throw new \RuntimeException(
                        'Managed Nginx config-test recovery found a non-canonical case alias.',
                    );
                }
                if (\preg_match(
                    '/\A' . \preg_quote(self::CONFIG_TEST_PREFIX, '/')
                        . '([a-f0-9]{32})\.conf(?:\.tmp-([a-f0-9]{24}))?\z/D',
                    $leaf,
                    $match,
                ) !== 1) {
                    throw new \RuntimeException(
                        'Managed Nginx config-test recovery found a malformed reserved leaf.',
                    );
                }
                $token = (string)$match[1];
                return [
                    'kind' => isset($match[2])
                        ? 'staging'
                        : 'config',
                    'token' => $token,
                    'pid_leaf' => self::CONFIG_TEST_PREFIX . $token . '.pid',
                ];
            }

            $legacyPrefix = 'nginx.conf.candidate.';
            $legacyPattern = '/\A' . \preg_quote($legacyPrefix, '/')
                . '[1-9][0-9]{0,19}\.[a-f0-9]{8}'
                . '\.test\.([a-f0-9]{32})'
                . '(?:\.tmp-([a-f0-9]{24}))?\z/D';
            $looksLikeLegacyTest = \str_starts_with(
                $foldedLeaf,
                $legacyPrefix,
            ) && \str_contains($foldedLeaf, '.test.');
            if ($looksLikeLegacyTest
                && \preg_match($legacyPattern . 'i', $leaf) === 1
                && \preg_match($legacyPattern, $leaf, $match) !== 1
            ) {
                throw new \RuntimeException(
                    'Managed Nginx legacy config-test recovery found a non-canonical case alias.',
                );
            }
            if ($looksLikeLegacyTest
                && \preg_match($legacyPattern, $leaf, $match) !== 1
            ) {
                throw new \RuntimeException(
                    'Managed Nginx legacy config-test recovery found a malformed reserved leaf.',
                );
            }
            if ($looksLikeLegacyTest) {
                $token = (string)$match[1];
                return [
                    'kind' => isset($match[2])
                        ? 'staging'
                        : 'config',
                    'token' => $token,
                    'pid_leaf' => self::LEGACY_CONFIG_TEST_PID_PREFIX . $token . '.pid',
                ];
            }
            return null;
        }

        if (\hash_equals(
            \strtolower(self::CONFIG_TEST_LOCK_LEAF),
            $foldedLeaf,
        )) {
            if (!\hash_equals(self::CONFIG_TEST_LOCK_LEAF, $leaf)) {
                throw new \RuntimeException(
                    'Managed Nginx config-test lock has a non-canonical case alias.',
                );
            }
            return null;
        }

        foreach ([
            self::CONFIG_TEST_PREFIX,
            self::LEGACY_CONFIG_TEST_PID_PREFIX,
        ] as $prefix) {
            if (!\str_starts_with($foldedLeaf, $prefix)) {
                continue;
            }
            if (!\str_starts_with($leaf, $prefix)) {
                throw new \RuntimeException(
                    'Managed Nginx config-test PID recovery found a non-canonical case alias.',
                );
            }
            if (\preg_match(
                '/\A' . \preg_quote($prefix, '/') . '([a-f0-9]{32})\.pid\z/D',
                $leaf,
                $match,
            ) !== 1) {
                throw new \RuntimeException(
                    'Managed Nginx config-test PID recovery found a malformed reserved leaf.',
                );
            }
            $token = (string)$match[1];
            return [
                'kind' => 'pid',
                'token' => $token,
                'pid_leaf' => $prefix . $token . '.pid',
            ];
        }
        return null;
    }

    private function assertConfigTestArtifactContents(
        string $contents,
        string $expectedPidLeaf,
    ): void {
        $pidDirectivePattern = '/^\s*pid\s+[^;\r\n]+;\s*$/m';
        $expectedPattern = '/^\s*pid\s+run\/'
            . \preg_quote($expectedPidLeaf, '/') . ';\s*$/m';
        if (\preg_match_all($pidDirectivePattern, $contents) !== 1
            || \preg_match_all($expectedPattern, $contents) !== 1
        ) {
            throw new \RuntimeException(
                'Managed Nginx config-test recovery config has an invalid isolated PID directive.',
            );
        }
    }

    private function parseConfigTestPid(string $contents): int
    {
        $raw = \trim($contents);
        if ($raw === '' || !\ctype_digit($raw)) {
            throw new \RuntimeException(
                'Managed Nginx config-test PID artifact is malformed.',
            );
        }
        $pid = (int)$raw;
        if ($pid <= 0 || (string)$pid !== \ltrim($raw, '0')) {
            throw new \RuntimeException(
                'Managed Nginx config-test PID artifact is malformed.',
            );
        }
        return $pid;
    }

    private function assertConfigTestPidExited(int $pid): void
    {
        $state = $this->pidState($pid);
        if ($state === Processer::PROCESS_STATE_RUNNING) {
            throw new \RuntimeException(
                'Refusing recovery while a live config-test PID is present.',
            );
        }
        if ($state !== Processer::PROCESS_STATE_EXITED) {
            throw new \RuntimeException(
                'Refusing recovery while a config-test PID state is indeterminate.',
            );
        }
    }

    /**
     * @param array<string|int,mixed> $before
     * @param array<string|int,mixed> $after
     */
    private function sameConfigTestStableState(array $before, array $after): bool
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

    /**
     * @param list<string> $extra
     * @return array{code:int,output:string}
     */
    private function runNginx(array $extra, ?string $configFile = null): array
    {
        $cmd = \array_merge($this->baseCommand($configFile), $extra);
        return GatewayBoundedCommandRunner::run($cmd, self::COMMAND_TIMEOUT_SECONDS);
    }

    /** @return array{code:int,output:string} */
    private function startNginx(): array
    {
        if (\PHP_OS_FAMILY !== 'Windows') {
            return $this->runNginx([]);
        }
        // Cross a WMI-created broker before Start-Process so nginx cannot keep
        // the lifecycle command's console or remote-exec handles alive.
        $command = $this->baseCommand();
        $binary = $command[0] ?? null;
        if (!\is_string($binary) || $binary === '') {
            return ['code' => 1, 'output' => 'managed nginx binary is unavailable'];
        }
        try {
            $pid = Processer::createWindowsIsolatedArgv(
                $command,
                $this->paths->runtimeRoot(),
                'managed-nginx-' . \substr(\hash('sha256', \strtolower($binary)), 0, 16),
            );
        } catch (\Throwable $throwable) {
            return ['code' => 1, 'output' => $throwable->getMessage()];
        }

        // PID delivery can be ambiguous after a committed WMI submission. Do
        // not resubmit here: start() converges once against nginx's authoritative
        // pid file and command-line identity before it reports success/failure.
        return [
            'code' => 0,
            'output' => $pid > 0
                ? 'isolated nginx launch submitted, pid=' . $pid
                : 'isolated nginx launch submitted; awaiting authoritative pid file',
        ];
    }

    /**
     * @return array{ok:bool,message:string}
     */
    public function stop(?float $deadlineMonotonic = null): array
    {
        $initialBudget = $this->remainingStopBudget($deadlineMonotonic);
        if ($deadlineMonotonic !== null && $initialBudget < 23.0) {
            return [
                'ok' => false,
                'message' => 'managed nginx stop deadline cannot contain process identity verification',
            ];
        }
        $status = $this->status();
        $this->remainingStopBudget($deadlineMonotonic);
        if (!($status['ok'] ?? false)) {
            return ['ok' => false, 'message' => 'refusing stop: ' . (string)$status['message']];
        }
        if (!$status['running']) {
            if (\file_exists($this->paths->pidFile()) || \is_link($this->paths->pidFile())) {
                try {
                    GatewayProjectStateFilesystem::removeRegular(
                        $this->paths->pidFile(),
                        'stale managed Nginx PID file',
                    );
                } catch (\Throwable $throwable) {
                    return [
                        'ok' => false,
                        'message' => 'unable to clear stale managed Nginx PID file: '
                            . $throwable->getMessage(),
                    ];
                }
            }
            return ['ok' => true, 'message' => 'not running'];
        }
        if (!$this->paths->isInstalled()) {
            return $this->killPid((int)$status['pid'], $deadlineMonotonic);
        }

        // Freeze the already-verified master identity before asking Nginx to
        // quit. Windows can temporarily hide the exiting process command line,
        // so a transient status mismatch is not permission to signal anything.
        $masterPid = (int)$status['pid'];
        $cmd = \array_merge($this->baseCommand(), ['-s', 'quit']);
        $commandBudget = self::COMMAND_TIMEOUT_SECONDS;
        if ($deadlineMonotonic !== null) {
            // The bounded command runner reserves up to twelve seconds for
            // Windows Job containment after its child deadline. Keep that
            // recovery work inside the retirement's absolute deadline.
            $commandBudget = \min(
                $commandBudget,
                $this->remainingStopBudget($deadlineMonotonic) - 13.0,
            );
            if ($commandBudget < 0.1) {
                return [
                    'ok' => false,
                    'message' => 'managed nginx stop deadline cannot contain command cleanup',
                ];
            }
        }
        $stopCommand = GatewayBoundedCommandRunner::run($cmd, $commandBudget);
        $deadline = (\hrtime(true) / 1_000_000_000)
            + self::GRACEFUL_STOP_TIMEOUT_SECONDS;
        if ($deadlineMonotonic !== null) {
            $deadline = \min($deadline, $deadlineMonotonic);
        }
        do {
            $remaining = $deadline - (\hrtime(true) / 1_000_000_000);
            if ($remaining <= 0.0) {
                break;
            }
            SchedulerSystem::usleep((int)\max(
                1,
                \min(100_000, \floor($remaining * 1_000_000)),
            ));
            $masterState = $this->pidState($masterPid);
            try {
                $pidFilePid = $this->readPid();
            } catch (\Throwable) {
                continue;
            }
            if ($pidFilePid !== null && $pidFilePid !== $masterPid) {
                return [
                    'ok' => false,
                    'message' => 'nginx master PID changed during graceful stop; no signal was sent to the new identity',
                ];
            }
            if ($masterState === Processer::PROCESS_STATE_EXITED) {
                return $this->finalizeExitedMasterPidFile($masterPid);
            }
        } while ((\hrtime(true) / 1_000_000_000) < $deadline);

        $masterState = $this->pidState($masterPid);
        if ($masterState === Processer::PROCESS_STATE_EXITED) {
            return $this->finalizeExitedMasterPidFile($masterPid);
        }
        if ($masterState !== Processer::PROCESS_STATE_RUNNING) {
            return [
                'ok' => false,
                'message' => 'nginx master state remained unknown after graceful stop timeout',
            ];
        }
        if (!$this->pidIdentityMatches($masterPid)) {
            return [
                'ok' => false,
                'message' => 'nginx master remained alive with an unverifiable identity after graceful stop timeout',
            ];
        }
        $killed = $this->killPid($masterPid, $deadlineMonotonic);
        $killed['message'] = ($killed['ok'] ?? false)
            ? 'killed after graceful shutdown timeout'
            : 'graceful shutdown timed out: ' . (string)($killed['message'] ?? 'termination failed');
        return $killed;
    }

    /**
     * @return array{ok:bool,message:string,exit_code:int|null}
     */
    public function reload(): array
    {
        $status = $this->status();
        if (!($status['ok'] ?? false)) {
            return [
                'ok' => false,
                'message' => 'refusing reload: ' . (string)$status['message'],
                'exit_code' => null,
            ];
        }
        if (!$status['running']) {
            return ['ok' => false, 'message' => 'managed nginx is not running', 'exit_code' => null];
        }
        if (!$this->paths->isInstalled()) {
            return ['ok' => false, 'message' => 'managed nginx binary missing', 'exit_code' => null];
        }
        $masterPid = (int)$status['pid'];
        $oldWorkerPids = $this->childWorkerPids($masterPid);
        if (\PHP_OS_FAMILY !== 'Windows'
            && ($oldWorkerPids === null || $oldWorkerPids === [])
        ) {
            return [
                'ok' => false,
                'message' => 'refusing reload: unable to enumerate the current nginx worker generation',
                'exit_code' => null,
            ];
        }
        $test = $this->testConfig($this->paths->confFile());
        if (($test['code'] ?? 1) !== 0) {
            return [
                'ok' => false,
                'message' => 'isolated nginx -t failed; existing workers were left unchanged: '
                    . \trim((string)($test['output'] ?? '')),
                'exit_code' => $test['code'] ?? 1,
            ];
        }
        $cmd = \array_merge($this->baseCommand(), ['-s', 'reload']);
        $reloadCommand = GatewayBoundedCommandRunner::run($cmd, self::COMMAND_TIMEOUT_SECONDS);
        $code = (int)($reloadCommand['code'] ?? 1);
        $output = (string)($reloadCommand['output'] ?? '');
        $remainingOldWorkerPids = \is_array($oldWorkerPids) ? $oldWorkerPids : [];
        $workerProbeFailed = false;
        if ($code === 0 && $remainingOldWorkerPids !== []) {
            $deadline = (\hrtime(true) / 1_000_000_000)
                + self::RELOAD_OLD_WORKER_TIMEOUT_SECONDS;
            do {
                SchedulerSystem::usleep(100000);
                $currentWorkerPids = $this->childWorkerPids($masterPid);
                if ($currentWorkerPids === null) {
                    $workerProbeFailed = true;
                    break;
                }
                $remainingOldWorkerPids = \array_values(\array_intersect(
                    $oldWorkerPids,
                    $currentWorkerPids,
                ));
            } while ($remainingOldWorkerPids !== []
                && (\hrtime(true) / 1_000_000_000) < $deadline
            );
        } else {
            SchedulerSystem::usleep(100000);
        }
        $finalStatus = $this->status();
        $running = (bool)($finalStatus['ok'] ?? false) && (bool)($finalStatus['running'] ?? false);
        $workersReplaced = !$workerProbeFailed && $remainingOldWorkerPids === [];
        return [
            'ok' => $code === 0 && $running && $workersReplaced,
            'message' => $code === 0 && $running && $workersReplaced
                ? 'configuration tested with an isolated PID and reloaded'
                : (!($finalStatus['ok'] ?? false)
                    ? 'nginx PID identity changed after reload'
                    : ($code !== 0
                        ? \trim($output)
                        : ($workerProbeFailed
                            ? 'unable to prove old nginx worker generation drain'
                            : (!$workersReplaced
                                ? 'old nginx workers did not drain before the reload generation deadline'
                                : 'nginx master exited after reload')))),
            'exit_code' => $code === 0 && $workersReplaced ? 0 : 1,
        ];
    }

    /** @return list<int>|null */
    private function childWorkerPids(int $masterPid): ?array
    {
        return NginxChildProcessProbe::workerPids($masterPid);
    }

    /**
     * @return list<string>
     */
    private function baseCommand(?string $configFile = null): array
    {
        $prefix = $this->nginxFsPath($this->paths->runtimeRoot()) . '/';
        $conf = $this->nginxFsPath($configFile ?? $this->paths->confFile());
        return [
            $this->paths->binary(),
            '-p',
            $prefix,
            '-c',
            $conf,
        ];
    }

    private function nginxFsPath(string $path): string
    {
        return \str_replace('\\', '/', $path);
    }

    private function readPid(): ?int
    {
        $file = $this->paths->pidFile();
        if (!\file_exists($file) && !\is_link($file)) {
            return null;
        }
        $contents = GatewayProjectStateFilesystem::read(
            $file,
            32,
            'Managed Nginx PID file',
        );
        $raw = \trim($contents);
        if ($raw === '' || !\ctype_digit($raw)) {
            throw new \RuntimeException('expected one positive integer');
        }
        $pid = (int)$raw;
        if ($pid <= 0) {
            throw new \RuntimeException('PID must be positive');
        }
        return $pid;
    }

    private function pidState(int $pid): string
    {
        if ($pid <= 0) {
            return Processer::PROCESS_STATE_EXITED;
        }
        $state = Processer::probeProcessState($pid, true);
        return \in_array($state, [
            Processer::PROCESS_STATE_RUNNING,
            Processer::PROCESS_STATE_EXITED,
            Processer::PROCESS_STATE_UNKNOWN,
        ], true)
            ? $state
            : Processer::PROCESS_STATE_UNKNOWN;
    }

    /** @return array{ok:bool,message:string} */
    private function finalizeExitedMasterPidFile(int $masterPid): array
    {
        try {
            $pidFilePid = $this->readPid();
        } catch (\Throwable $throwable) {
            return [
                'ok' => false,
                'message' => 'nginx exited but its PID file is unreadable: ' . $throwable->getMessage(),
            ];
        }
        if ($pidFilePid === null) {
            return ['ok' => true, 'message' => 'stopped'];
        }
        if ($pidFilePid !== $masterPid) {
            return [
                'ok' => false,
                'message' => 'nginx exited but its PID file now belongs to a different identity',
            ];
        }
        try {
            GatewayProjectStateFilesystem::removeRegular(
                $this->paths->pidFile(),
                'exited managed Nginx PID file',
            );
        } catch (\Throwable) {
            return [
                'ok' => false,
                'message' => 'nginx exited but its stale PID file could not be removed',
            ];
        }
        try {
            $this->processIdentity->clear($masterPid);
        } catch (\Throwable $throwable) {
            return [
                'ok' => false,
                'message' => 'nginx exited but its process identity could not be cleared: '
                    . $throwable->getMessage(),
            ];
        }

        return ['ok' => true, 'message' => 'stopped'];
    }

    private function pidIdentityMatches(int $pid): bool
    {
        return (bool)($this->inspectPidIdentity($pid)['ok'] ?? false);
    }

    /** @return array<string,mixed> */
    private function inspectPidIdentity(int $pid): array
    {
        $command = Processer::getProcessCommandLine($pid, true);
        if ($command === '') {
            return ['ok' => false, 'reason' => 'process command line is unavailable'];
        }

        return $this->processIdentity->inspect($pid, $command, true);
    }

    /** @return list<string> */
    private function tokenizeCommandLine(string $command): array
    {
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

    private function normalizeIdentityPath(string $path): string
    {
        $normalized = \rtrim(\str_replace('\\', '/', \trim($path)), '/');

        return \PHP_OS_FAMILY === 'Windows' ? \strtolower($normalized) : $normalized;
    }

    /**
     * @return array{ok:bool,message:string}
     */
    private function killPid(
        int $pid,
        ?float $deadlineMonotonic = null,
    ): array
    {
        try {
            // Freeze the kernel birth before the mutable argv/manifest probe.
            // If the PID is recycled at any later point, the stable handle
            // termination below observes MISMATCH and sends no signal.
            $runtimeIdentity = new MasterLeaseRuntimeIdentity();
            $processIdentity = $runtimeIdentity->captureProcessIdentity($pid);
        } catch (\Throwable $throwable) {
            if ($this->pidState($pid) === Processer::PROCESS_STATE_EXITED) {
                return $this->finalizeExitedMasterPidFile($pid);
            }

            return [
                'ok' => false,
                'message' => 'refusing to kill managed nginx without a stable process birth: '
                    . $throwable->getMessage(),
            ];
        }
        $state = $this->pidState($pid);
        if ($state === Processer::PROCESS_STATE_EXITED) {
            return $this->finalizeExitedMasterPidFile($pid);
        }
        if ($state !== Processer::PROCESS_STATE_RUNNING) {
            return ['ok' => false, 'message' => 'refusing to kill a PID whose process state is unknown'];
        }
        if (!$this->pidIdentityMatches($pid)) {
            return ['ok' => false, 'message' => 'refusing to kill a PID that does not match managed nginx identity'];
        }
        $result = $runtimeIdentity->terminateExactProcessIdentity(
            $pid,
            (string)$processIdentity['birth'],
            (string)$processIdentity['pid_namespace_id'],
            \min(0.5, $this->remainingStopBudget($deadlineMonotonic)),
        );
        if ((bool)($result['released'] ?? false)) {
            return $this->finalizeExitedMasterPidFile($pid);
        }

        return [
            'ok' => false,
            'message' => 'managed nginx stable-handle termination was not proven: '
                . (string)($result['reason'] ?? 'unknown'),
        ];
    }

    private function remainingStopBudget(?float $deadlineMonotonic): float
    {
        if ($deadlineMonotonic === null) {
            return 60.0;
        }
        if (!\is_finite($deadlineMonotonic)) {
            throw new \RuntimeException('Managed Nginx stop deadline is invalid.');
        }
        $remaining = $deadlineMonotonic - (\hrtime(true) / 1_000_000_000);
        if ($remaining <= 0.0) {
            throw new \RuntimeException('Managed Nginx stop deadline was exhausted.');
        }
        return $remaining;
    }
}
