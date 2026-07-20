<?php
declare(strict_types=1);

namespace Weline\Server\Service\Runtime;

final class RuntimeStrategyResolver
{
    public const STRATEGY_AUTO = 'auto';
    public const STRATEGY_PERFORMANCE = 'performance';
    public const STRATEGY_STABILITY = 'stability';

    /**
     * @param array<string, mixed> $config
     * @param array<int|string, mixed> $args
     * @return array<string, mixed>
     */
    public function resolve(array $config, array $args, WlsRuntimeProfile $profile): array
    {
        $runtime = \is_array($config['runtime'] ?? null) ? $config['runtime'] : [];
        $loop = \is_array($config['loop'] ?? null) ? $config['loop'] : [];
        $strategy = $this->normalizeStrategy($config['runtime_strategy'] ?? ($runtime['strategy'] ?? self::STRATEGY_AUTO));
        $requestedWorkerCount = $config['worker_count_requested'] ?? ($config['worker_count'] ?? 'auto');
        $workerCount = $this->resolveWorkerCount(
            $config['worker_count'] ?? 'auto',
            (string)($config['mode'] ?? 'io'),
            $strategy,
            $profile
        );
        $topology = $this->resolveTopology($config, $args, $profile);
        $eventLoop = $this->resolveEventLoopDriver(
            (string)($config['event_loop'] ?? ($loop['driver'] ?? 'auto')),
            $profile
        );
        $sslEngine = $this->resolveSslEngine($config);
        $extensions = $profile->get('extensions', []);
        $sslRequired = empty($config['no_ssl']) && ($config['https'] ?? true) !== false;
        try {
            $tlsSessionCache = TlsSessionCacheConfig::fromSslConfig(
                \is_array($config['ssl'] ?? null) ? $config['ssl'] : []
            );
        } catch (\InvalidArgumentException $exception) {
            throw new \RuntimeException($exception->getMessage(), 0, $exception);
        }
        if ($sslRequired && $tlsSessionCache->enabled()) {
            TlsSessionCacheRuntime::assertApiAvailable();
            if ($sslEngine !== 'stream') {
                throw new \RuntimeException(
                    'wls.ssl.session_cache=external requires wls.ssl.engine=stream and the defer-SSL per-connection SNI path.'
                );
            }
        }
        if ($sslRequired
            && \is_array($extensions)
            && \array_key_exists('openssl', $extensions)
            && !$profile->hasExtension('openssl')
        ) {
            throw new \RuntimeException(
                'WLS HTTPS requires the PHP OpenSSL extension in the current PHP binary (' . PHP_BINARY . '); '
                . 'install/enable openssl or explicitly use --no-ssl.'
            );
        }
        if ($topology['effective'] === EffectiveTopology::Dispatcher && $sslEngine === 'event_buffer') {
            throw new \RuntimeException(
                'wls.ssl.engine=event_buffer is not compatible with the authenticated PROXY v2 Dispatcher backend: '
                . 'the current EventBuffer worker starts TLS before it can consume the required backend preface. '
                . 'Use wls.ssl.engine=stream; WLS will not silently corrupt or downgrade the TLS connection.'
            );
        }
        if ($topology['effective'] === EffectiveTopology::Direct) {
            if ($sslEngine === 'event_buffer') {
                throw new \RuntimeException(
                    'WLS direct topology does not support wls.ssl.engine=event_buffer; '
                    . 'use the stream SSL engine or explicitly select --dispatcher.'
                );
            }
            if ($eventLoop['driver'] !== 'event') {
                throw new \RuntimeException(
                    'WLS direct topology requires the PHP event extension and event loop; '
                    . 'install/enable ext-event or explicitly select --dispatcher.'
                );
            }
        }

        $supervisor = $this->resolveSupervisor($config, $profile, $strategy);
        $warnings = \array_merge(
            $topology['warnings'],
            $eventLoop['warnings'],
            $supervisor['warnings']
        );
        $selection = new RuntimeSelection(
            requestedTopology: $topology['requested'],
            effectiveTopology: $topology['effective'],
            source: $topology['source'],
            osFamily: $profile->osFamily(),
            eventLoopDriver: $eventLoop['driver'],
            sslEngine: $sslEngine,
            listenerMode: $topology['listener_mode'],
            policyCompatible: true,
            reasonCodes: [$topology['reason_code']],
            reason: $topology['reason'],
        );

        return [
            'runtime_strategy' => $strategy,
            'status' => $this->resolveStatus($warnings, $topology, $eventLoop, $supervisor),
            'worker_count' => $workerCount,
            'worker_count_reason' => $this->workerCountReason(
                $requestedWorkerCount,
                (string)($config['mode'] ?? 'io'),
                $profile,
                $strategy
            ),
            'event_loop_reason' => $eventLoop['reason'],
            'supervisor_enabled' => $supervisor['enabled'],
            'supervisor_reason' => $supervisor['reason'],
            'warnings' => $warnings,
            'runtime_selection' => $selection,
        ];
    }

    public function resolveWorkerCount(mixed $workerCount, string $mode, string $strategy, WlsRuntimeProfile $profile): int
    {
        $strategy = $this->normalizeStrategy($strategy);
        if (\is_int($workerCount) && $workerCount > 0) {
            return $workerCount;
        }
        if (\is_string($workerCount) && \ctype_digit($workerCount) && (int)$workerCount > 0) {
            return (int) $workerCount;
        }

        $cpu = $profile->cpuCores();
        $mode = \strtolower(\trim($mode)) === 'cpu' ? 'cpu' : 'io';

        if ($profile->isWindows()) {
            $base = $mode === 'cpu' ? $cpu : (int) \ceil($cpu / 2);
            $count = \min(\max(2, $base), 8);
            if ($strategy === self::STRATEGY_PERFORMANCE) {
                $count = \min(\max($count, $cpu), 12);
            }
        } elseif ($profile->isDarwin()) {
            // WLS workers execute PHP application code synchronously inside each process.
            // On heterogeneous Apple Silicon, scheduling one hot worker per performance
            // core avoids efficiency-core spill and the context-switch penalty observed
            // when logical CPUs are multiplied as if all cores had equal throughput.
            $count = \min(\max(1, $profile->performanceCpuCores()), 16);
        } else {
            $count = $mode === 'cpu' ? $cpu : $cpu * 2;
            if ($strategy === self::STRATEGY_STABILITY) {
                $count = $mode === 'cpu' ? $cpu : (int) \ceil($cpu * 1.5);
            }
            $count = \min(\max(2, $count), 16);
        }

        $memoryMb = $profile->memoryMb();
        if ($memoryMb !== null && $memoryMb > 0) {
            $memoryCap = \max(1, (int) \floor($memoryMb / 512));
            $count = \min($count, \max(2, $memoryCap));
        }

        return \max(1, $count);
    }

    /**
     * Resolve the platform topology contract without probing optional runtime
     * dependencies. This is the single pre-install source used by server:start:
     * POSIX auto/direct must ultimately become Direct or fail closed, while an
     * explicit POSIX Dispatcher remains Dispatcher even when ext-event cannot
     * be installed.
     *
     * @param array<string, mixed> $config
     * @param array<int|string, mixed> $args
     * @return array{requested:RequestedTopology,effective:EffectiveTopology,source:string,reason:string,reason_code:string}
     */
    public function resolveTopologyIntent(
        array $config,
        array $args,
        string $osFamily = PHP_OS_FAMILY,
    ): array {
        ['requested' => $requested, 'source' => $source] = $this->resolveRequestedTopology($config, $args);

        if ($osFamily === 'Windows') {
            if ($requested === RequestedTopology::Direct) {
                throw new \RuntimeException(
                    'Windows supports only WLS Dispatcher topology; --direct is not supported.'
                );
            }

            return [
                'requested' => $requested,
                'effective' => EffectiveTopology::Dispatcher,
                'source' => $source,
                'reason' => $requested === RequestedTopology::Auto
                    ? 'auto selected Dispatcher because Windows requires TCP passthrough'
                    : 'explicit Dispatcher topology',
                'reason_code' => $requested === RequestedTopology::Auto
                    ? 'windows_auto_dispatcher'
                    : 'explicit_dispatcher',
            ];
        }

        if (!\in_array($osFamily, ['Linux', 'Darwin'], true)) {
            throw new \RuntimeException(
                'WLS supports only Windows Dispatcher or Linux/macOS Direct and Dispatcher topologies; '
                . 'the current platform "' . $osFamily . '" is unsupported.'
            );
        }

        if ($requested === RequestedTopology::Dispatcher) {
            return [
                'requested' => $requested,
                'effective' => EffectiveTopology::Dispatcher,
                'source' => $source,
                'reason' => 'explicit Dispatcher topology',
                'reason_code' => 'explicit_dispatcher',
            ];
        }

        return [
            'requested' => $requested,
            'effective' => EffectiveTopology::Direct,
            'source' => $source,
            'reason' => $requested === RequestedTopology::Auto
                ? 'auto requires verified direct topology on Linux/macOS'
                : 'explicit direct topology requires verified listener capability',
            'reason_code' => $requested === RequestedTopology::Auto
                ? 'posix_auto_direct'
                : 'explicit_direct',
        ];
    }

    private function normalizeStrategy(mixed $strategy): string
    {
        $strategy = \strtolower(\trim((string)$strategy));
        if (!\in_array($strategy, [
            self::STRATEGY_AUTO,
            self::STRATEGY_PERFORMANCE,
            self::STRATEGY_STABILITY,
        ], true)) {
            throw new \RuntimeException(
                'WLS runtime strategy must be one of auto/performance/stability; received "' . $strategy . '".'
            );
        }

        return $strategy;
    }

    /**
     * @param array<string, mixed> $config
     * @param array<int|string, mixed> $args
     * @return array{requested:RequestedTopology,effective:EffectiveTopology,source:string,listener_mode:string,reason:string,reason_code:string,warnings:string[]}
     */
    private function resolveTopology(
        array $config,
        array $args,
        WlsRuntimeProfile $profile
    ): array {
        $intent = $this->resolveTopologyIntent($config, $args, $profile->osFamily());
        $requested = $intent['requested'];
        $source = $intent['source'];

        if ($intent['effective'] === EffectiveTopology::Dispatcher) {
            return $this->topologyResult(
                $requested,
                EffectiveTopology::Dispatcher,
                $source,
                $intent['reason'],
                $intent['reason_code'],
            );
        }

        if (!$profile->supportsDirectListener()) {
            $probe = $profile->directListenerProbe();
            $reason = \trim((string)($probe['reason'] ?? 'The direct listener capability probe failed.'));
            throw new \RuntimeException(
                'WLS direct topology requires a verified load-balanced listener capability. '
                . $reason . ' Install the required runtime dependencies or explicitly select --dispatcher.'
            );
        }

        $listenerMode = $profile->directListenerMode();
        if (!\in_array($listenerMode, ['reuseport', 'shared_fd'], true)) {
            throw new \RuntimeException(
                'WLS direct topology capability probe returned no usable listener strategy; explicitly select --dispatcher.'
            );
        }
        $listenerLabel = $listenerMode === 'shared_fd'
            ? 'Master-owned shared listener FD'
            : 'SO_REUSEPORT';

        return $this->topologyResult(
            $requested,
            EffectiveTopology::Direct,
            $source,
            $requested === RequestedTopology::Auto
                ? 'auto selected ' . $listenerLabel . ' direct topology on Linux/macOS'
                : 'explicit direct topology with verified ' . $listenerLabel . ' support',
            $requested === RequestedTopology::Auto ? 'posix_auto_direct' : 'explicit_direct',
            [],
            $listenerMode,
        );
    }

    /**
     * @param array<string, mixed> $config
     * @param array<int|string, mixed> $args
     * @return array{requested:RequestedTopology,source:string}
     */
    private function resolveRequestedTopology(array $config, array $args): array
    {
        $removedCliOptions = [
            'topology' => '--topology',
            'no-dispatcher' => '--no-dispatcher',
            'no_dispatcher' => '--no_dispatcher',
            'force-dispatcher' => '--force-dispatcher',
        ];
        foreach ($removedCliOptions as $key => $option) {
            if (\array_key_exists($key, $args)) {
                throw new \RuntimeException(
                    'Removed WLS topology option ' . $option . ' is not supported; use only --direct or --dispatcher.'
                );
            }
        }

        $direct = isset($args['direct']);
        $dispatcher = isset($args['dispatcher']);
        if ($direct && $dispatcher) {
            throw new \RuntimeException('Conflicting WLS topology CLI options: --direct and --dispatcher.');
        }
        if ($direct) {
            return ['requested' => RequestedTopology::Direct, 'source' => 'cli.direct'];
        }
        if ($dispatcher) {
            return ['requested' => RequestedTopology::Dispatcher, 'source' => 'cli.dispatcher'];
        }

        foreach ([
            'topology',
            '_legacy_topology_source',
            'master_mode',
            'dispatcher_enabled',
            'direct_reuse_port',
        ] as $legacyKey) {
            if (\array_key_exists($legacyKey, $config)) {
                throw new \RuntimeException(
                    'Removed WLS topology configuration "wls.' . $legacyKey
                    . '" is not supported; use only wls.runtime.topology.'
                );
            }
        }

        $gateway = $config['gateway'] ?? null;
        if (\is_array($gateway) && \array_key_exists('traffic_mode', $gateway)) {
            throw new \RuntimeException(
                'Removed WLS topology configuration "wls.gateway.traffic_mode" is not supported; '
                . 'use only wls.runtime.topology.'
            );
        }

        $runtime = \is_array($config['runtime'] ?? null) ? $config['runtime'] : [];
        $hasRuntimeTopology = \array_key_exists('topology', $runtime);
        if (!empty($config['_instance_topology_explicit']) && !$hasRuntimeTopology) {
            throw new \RuntimeException(
                'The instance topology marker requires an explicit wls.runtime.topology value.'
            );
        }
        if (!$hasRuntimeTopology) {
            return ['requested' => RequestedTopology::Auto, 'source' => 'auto'];
        }

        $requested = $this->parseRequestedTopology($runtime['topology'], 'wls.runtime.topology');
        return [
            'requested' => $requested,
            'source' => !empty($config['_instance_topology_explicit'])
                ? 'instance.runtime.topology'
                : 'wls.runtime.topology',
        ];
    }



    private function parseRequestedTopology(mixed $value, string $source): RequestedTopology
    {
        $normalized = \strtolower(\trim((string)$value));
        if ($normalized === '') {
            throw new \RuntimeException($source . ' must not be empty.');
        }

        $topology = RequestedTopology::tryFrom($normalized);
        if (!$topology instanceof RequestedTopology) {
            throw new \RuntimeException(
                $source . ' must be one of auto/direct/dispatcher; received "' . $normalized . '".'
            );
        }

        return $topology;
    }

    /**
     * @param string[] $warnings
     * @return array{requested:RequestedTopology,effective:EffectiveTopology,source:string,listener_mode:string,reason:string,reason_code:string,warnings:string[]}
     */
    private function topologyResult(
        RequestedTopology $requested,
        EffectiveTopology $effective,
        string $source,
        string $reason,
        string $reasonCode,
        array $warnings = [],
        string $listenerMode = 'single',
    ): array {
        return [
            'requested' => $requested,
            'effective' => $effective,
            'source' => $source,
            'listener_mode' => $effective->isDirect() ? $listenerMode : 'single',
            'reason' => $reason,
            'reason_code' => $reasonCode,
            'warnings' => $warnings,
        ];
    }

    /**
     * @param array<string, mixed> $config
     */
    private function resolveSslEngine(array $config): string
    {
        $ssl = \is_array($config['ssl'] ?? null) ? $config['ssl'] : [];
        $engine = \strtolower(\trim((string)($ssl['engine'] ?? 'stream')));
        $engine = $engine !== '' ? $engine : 'stream';
        if (!\in_array($engine, ['stream', 'event_buffer'], true)) {
            throw new \RuntimeException(
                'wls.ssl.engine must be stream or event_buffer; received "' . $engine . '".'
            );
        }

        return $engine;
    }

    /**
     * @return array{driver:string,reason:string,warnings:string[]}
     */
    private function resolveEventLoopDriver(string $requested, WlsRuntimeProfile $profile): array
    {
        $requested = \strtolower(\trim($requested));
        if ($requested === '') {
            $requested = 'auto';
        }

        $nativeExtensionIsolationRequired = PhpRuntimeSafetyProfile::requiresNativeExtensionIsolation();
        if ($requested === 'event') {
            if ($nativeExtensionIsolationRequired) {
                throw new \RuntimeException(
                    'wls.event_loop=event is unsafe on Windows ARM64 while PHP runs through x64 emulation; '
                    . 'use the native ARM64 PHP runtime or wls.event_loop=select.'
                );
            }
            if (!$profile->canUseEventLoop()) {
                throw new \RuntimeException('wls.event_loop=event requires PHP event extension and EventBase/Event classes.');
            }
            return ['driver' => 'event', 'reason' => 'explicit event loop', 'warnings' => []];
        }

        if ($requested === 'select') {
            return ['driver' => 'select', 'reason' => 'explicit select event loop', 'warnings' => []];
        }

        if ($nativeExtensionIsolationRequired) {
            return [
                'driver' => 'select',
                'reason' => 'auto selected stable stream_select for Windows ARM64 with x64 PHP emulation',
                'warnings' => [
                    'PHP event extension is disabled for this runtime because native extension crashes were reproduced under x64 emulation.',
                ],
            ];
        }

        if ($profile->canUseEventLoop()) {
            return ['driver' => 'event', 'reason' => 'auto selected PHP event extension', 'warnings' => []];
        }

        return [
            'driver' => 'select',
            'reason' => 'auto fallback to stream_select because PHP event extension is unavailable',
            'warnings' => ['PHP event extension is missing; stream_select is slower.'],
        ];
    }


    /**
     * @param array<string, mixed> $config
     * @return array{enabled:bool,reason:string,warnings:string[]}
     */
    private function resolveSupervisor(array $config, WlsRuntimeProfile $profile, string $strategy): array
    {
        $supervisor = \is_array($config['supervisor'] ?? null) ? $config['supervisor'] : [];
        $runtime = \is_array($config['runtime'] ?? null) ? $config['runtime'] : [];
        $raw = $supervisor['enabled']
            ?? ($runtime['supervisor_enabled'] ?? ($config['supervisor_enabled'] ?? 'auto'));
        $value = \strtolower(\trim((string)$raw));
        if ($value === '1' || $value === 'true' || $value === 'yes' || $value === 'on') {
            return ['enabled' => true, 'reason' => 'explicit supervisor enabled', 'warnings' => []];
        }
        if ($value === '0' || $value === 'false' || $value === 'no' || $value === 'off') {
            return [
                'enabled' => false,
                'reason' => 'explicit supervisor disabled',
                'warnings' => ['Supervisor is disabled; IPC HA channel is degraded.'],
            ];
        }

        if ($profile->isWindows()) {
            return [
                'enabled' => false,
                'reason' => 'auto disabled on Windows; native Master control plane avoids Supervisor reconnect churn',
                'warnings' => ['Supervisor is disabled automatically on Windows; use --supervisor=on only when validating Supervisor HA.'],
            ];
        }
        if (!$profile->canControlProcesses()) {
            return [
                'enabled' => false,
                'reason' => 'process control functions are unavailable',
                'warnings' => ['Supervisor cannot be enabled because process control functions are unavailable.'],
            ];
        }

        return ['enabled' => true, 'reason' => 'auto enabled for high availability', 'warnings' => []];
    }

    /**
     * @param string[] $warnings
     * @param array<string, mixed> $topology
     * @param array<string, mixed> $eventLoop
     * @param array<string, mixed> $supervisor
     */
    private function resolveStatus(array $warnings, array $topology, array $eventLoop, array $supervisor): string
    {
        if (($topology['effective'] ?? null) === EffectiveTopology::Direct
            && ($eventLoop['driver'] ?? '') === 'event'
            && !empty($supervisor['enabled'])
            && $warnings === []) {
            return 'optimal';
        }
        if (($topology['effective'] ?? null) === EffectiveTopology::Dispatcher && $warnings === []) {
            return 'stable';
        }

        return 'degraded';
    }

    private function workerCountReason(mixed $workerCount, string $mode, WlsRuntimeProfile $profile, string $strategy): string
    {
        if ((\is_int($workerCount) && $workerCount > 0) || (\is_string($workerCount) && \ctype_digit($workerCount))) {
            return 'explicit worker count';
        }

        $memory = $profile->memoryMb();
        $memoryNote = $memory === null ? ', memory unknown' : ', memory=' . $memory . 'MB';
        $cpuNote = 'cpu=' . $profile->cpuCores();
        if ($profile->isDarwin()) {
            $cpuNote .= ', physical_cpu=' . $profile->physicalCpuCores()
                . ', performance_cpu=' . $profile->performanceCpuCores()
                . ', cpu_source=' . $profile->cpuTopologySource();
        }

        return 'auto worker count from ' . $cpuNote
            . ', mode=' . (\strtolower($mode) === 'cpu' ? 'cpu' : 'io')
            . ', strategy=' . $strategy
            . $memoryNote;
    }
}
