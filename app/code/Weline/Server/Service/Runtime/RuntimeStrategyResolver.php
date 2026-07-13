<?php
declare(strict_types=1);

namespace Weline\Server\Service\Runtime;

final class RuntimeStrategyResolver
{
    public const STRATEGY_AUTO = 'auto';
    public const STRATEGY_PERFORMANCE = 'performance';
    public const STRATEGY_STABILITY = 'stability';
    public const STRATEGY_COMPATIBILITY = 'compatibility';

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
        $workerCount = $this->resolveWorkerCount($config['worker_count'] ?? 'auto', (string)($config['mode'] ?? 'io'), $strategy, $profile);
        $topology = $this->resolveTopology($config, $args, $profile);
        $eventLoop = $this->resolveEventLoopDriver(
            (string)($config['event_loop'] ?? ($loop['driver'] ?? 'auto')),
            $profile
        );
        $sslEngine = $this->resolveSslEngine($config);
        $extensions = $profile->get('extensions', []);
        $sslRequired = empty($config['no_ssl']) && ($config['https'] ?? true) !== false;
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
            'worker_count_reason' => $this->workerCountReason($requestedWorkerCount, (string)($config['mode'] ?? 'io'), $profile, $strategy),
            'requested_topology' => $selection->requestedTopology->value,
            'effective_topology' => $selection->effectiveTopology->value,
            'topology' => $selection->effectiveTopology->value,
            'topology_source' => $selection->source,
            'dispatcher_enabled' => $topology['dispatcher_enabled'],
            'direct_reuse_port' => $topology['direct_reuse_port'],
            'direct_listener_mode' => $selection->listenerMode,
            'listener_strategy' => $selection->listenerMode,
            'topology_reason' => $selection->reason,
            'topology_reason_codes' => $selection->reasonCodes,
            'event_loop_driver' => $eventLoop['driver'],
            'event_loop_reason' => $eventLoop['reason'],
            'ssl_engine' => $selection->sslEngine,
            'policy_compatible' => $selection->policyCompatible,
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
            if ($strategy === self::STRATEGY_STABILITY || $strategy === self::STRATEGY_COMPATIBILITY) {
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
            if ($requested === RequestedTopology::Direct || $requested === RequestedTopology::Independent) {
                throw new \RuntimeException(
                    'Windows supports only WLS Dispatcher topology; direct/independent/no-dispatcher modes are not supported.'
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
            if ($requested === RequestedTopology::Direct || $requested === RequestedTopology::Independent) {
                throw new \RuntimeException(
                    'This platform supports only WLS Dispatcher topology; direct/independent modes require Linux or macOS.'
                );
            }

            return [
                'requested' => $requested,
                'effective' => EffectiveTopology::Dispatcher,
                'source' => $source,
                'reason' => 'auto selected Dispatcher compatibility topology on this platform',
                'reason_code' => 'other_platform_dispatcher',
            ];
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

        if ($requested === RequestedTopology::Independent) {
            return [
                'requested' => $requested,
                'effective' => EffectiveTopology::Independent,
                'source' => $source,
                'reason' => 'deprecated independent diagnostic topology',
                'reason_code' => 'deprecated_independent',
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
        return \in_array($strategy, [
            self::STRATEGY_AUTO,
            self::STRATEGY_PERFORMANCE,
            self::STRATEGY_STABILITY,
            self::STRATEGY_COMPATIBILITY,
        ], true) ? $strategy : self::STRATEGY_AUTO;
    }

    /**
     * @param array<string, mixed> $config
     * @param array<int|string, mixed> $args
     * @return array{requested:RequestedTopology,effective:EffectiveTopology,topology:string,source:string,dispatcher_enabled:bool,direct_reuse_port:bool,reason:string,reason_code:string,warnings:string[]}
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

        if ($intent['effective'] === EffectiveTopology::Independent) {
            return $this->topologyResult(
                $requested,
                EffectiveTopology::Independent,
                $source,
                $intent['reason'],
                $intent['reason_code'],
                ['Independent topology is deprecated; use direct or Dispatcher topology.'],
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
        $cliRequests = [];
        if (isset($args['topology'])) {
            $cliRequests['cli.topology'] = $this->parseRequestedTopology($args['topology'], 'CLI --topology');
        }
        if (isset($args['direct'])) {
            $cliRequests['cli.direct'] = RequestedTopology::Direct;
        }
        if (isset($args['no-dispatcher']) || isset($args['no_dispatcher'])) {
            $cliRequests['cli.no-dispatcher'] = RequestedTopology::Independent;
        }
        if (isset($args['dispatcher']) || isset($args['force-dispatcher'])) {
            $cliRequests['cli.dispatcher'] = RequestedTopology::Dispatcher;
        }
        if ($cliRequests !== []) {
            $values = \array_values(\array_unique(\array_map(
                static fn(RequestedTopology $topology): string => $topology->value,
                $cliRequests
            )));
            if (\count($values) > 1) {
                throw new \RuntimeException('Conflicting WLS topology CLI options: ' . \implode(', ', \array_keys($cliRequests)) . '.');
            }

            return [
                'requested' => \reset($cliRequests),
                'source' => (string)\array_key_first($cliRequests),
            ];
        }

        $runtime = \is_array($config['runtime'] ?? null) ? $config['runtime'] : [];
        $runtimeValue = $this->parseRequestedTopology($runtime['topology'] ?? 'auto', 'wls.runtime.topology');
        if (!empty($config['_instance_topology_explicit'])) {
            return ['requested' => $runtimeValue, 'source' => 'instance.runtime.topology'];
        }
        if ($runtimeValue !== RequestedTopology::Auto) {
            return ['requested' => $runtimeValue, 'source' => 'wls.runtime.topology'];
        }

        $legacySource = \trim((string)($config['_legacy_topology_source'] ?? ''));
        $legacySource = $legacySource !== '' ? $legacySource : 'legacy.wls.topology';
        $legacyValue = $this->parseRequestedTopology($config['topology'] ?? 'auto', 'legacy wls.topology');
        if ($legacyValue !== RequestedTopology::Auto) {
            return ['requested' => $legacyValue, 'source' => $legacySource];
        }

        $legacyRequests = [];
        $gateway = \is_array($config['gateway'] ?? null) ? $config['gateway'] : [];
        $trafficMode = \strtolower(\trim(\str_replace('-', '_', (string)($gateway['traffic_mode'] ?? ''))));
        if ($trafficMode === 'direct_listen') {
            $legacyRequests['legacy.gateway.traffic_mode'] = RequestedTopology::Direct;
        } elseif ($trafficMode === 'passthrough') {
            $legacyRequests['legacy.gateway.traffic_mode'] = RequestedTopology::Dispatcher;
        }

        $directReusePort = !empty($config['direct_reuse_port']);
        if ($directReusePort) {
            $legacyRequests['legacy.direct_reuse_port'] = RequestedTopology::Direct;
        }

        $masterMode = \strtolower(\trim((string)($config['master_mode'] ?? '')));
        if (\in_array($masterMode, ['direct', 'linux-direct'], true)) {
            $legacyRequests['legacy.master_mode'] = RequestedTopology::Direct;
        } elseif (\in_array($masterMode, ['dispatcher', 'windows-dispatcher'], true)) {
            $legacyRequests['legacy.master_mode'] = RequestedTopology::Dispatcher;
        } elseif ($masterMode === 'independent') {
            $legacyRequests['legacy.master_mode'] = RequestedTopology::Independent;
        }

        if (\array_key_exists('dispatcher_enabled', $config)) {
            $dispatcherEnabled = $this->parseLegacyBoolean($config['dispatcher_enabled'], 'legacy dispatcher_enabled');
            if ($dispatcherEnabled) {
                $legacyRequests['legacy.dispatcher_enabled'] = RequestedTopology::Dispatcher;
            } elseif (!$directReusePort && $masterMode === '') {
                // A lone legacy false represented the old independent mode.
                // Keep recognizing it so the normal independent fail-closed
                // gate can explain the migration instead of silently changing
                // the requested topology.
                $legacyRequests['legacy.dispatcher_enabled'] = RequestedTopology::Independent;
            }
        }

        if ($legacyRequests !== []) {
            $values = \array_values(\array_unique(\array_map(
                static fn(RequestedTopology $topology): string => $topology->value,
                $legacyRequests
            )));
            if (\count($values) > 1) {
                throw new \RuntimeException(
                    'Conflicting legacy WLS topology configuration: ' . \implode(', ', \array_keys($legacyRequests)) . '.'
                );
            }

            return [
                'requested' => \reset($legacyRequests),
                'source' => (string)\array_key_first($legacyRequests),
            ];
        }

        return ['requested' => RequestedTopology::Auto, 'source' => 'auto'];
    }

    private function parseLegacyBoolean(mixed $value, string $source): bool
    {
        if (\is_bool($value)) {
            return $value;
        }
        if (\is_int($value) && \in_array($value, [0, 1], true)) {
            return $value === 1;
        }

        $normalized = \strtolower(\trim((string)$value));
        if (\in_array($normalized, ['1', 'true', 'yes', 'on'], true)) {
            return true;
        }
        if (\in_array($normalized, ['0', 'false', 'no', 'off'], true)) {
            return false;
        }

        throw new \RuntimeException($source . ' must be a boolean value.');
    }

    private function parseRequestedTopology(mixed $value, string $source): RequestedTopology
    {
        $normalized = \strtolower(\trim((string)$value));
        if ($normalized === '') {
            $normalized = RequestedTopology::Auto->value;
        }
        $topology = RequestedTopology::tryFrom($normalized);
        if (!$topology instanceof RequestedTopology) {
            throw new \RuntimeException(
                $source . ' must be one of auto/direct/dispatcher/independent; received "' . $normalized . '".'
            );
        }

        return $topology;
    }

    /**
     * @param string[] $warnings
     * @return array{requested:RequestedTopology,effective:EffectiveTopology,topology:string,source:string,dispatcher_enabled:bool,direct_reuse_port:bool,listener_mode:string,reason:string,reason_code:string,warnings:string[]}
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
            'topology' => $effective->value,
            'source' => $source,
            'dispatcher_enabled' => $effective->isDispatcher(),
            'direct_reuse_port' => $effective->isDirect() && $listenerMode === 'reuseport',
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

        if ($requested === 'event') {
            if (!$profile->canUseEventLoop()) {
                throw new \RuntimeException('wls.event_loop=event requires PHP event extension and EventBase/Event classes.');
            }
            return ['driver' => 'event', 'reason' => 'explicit event loop', 'warnings' => []];
        }

        if ($requested === 'select') {
            return ['driver' => 'select', 'reason' => 'explicit select event loop', 'warnings' => []];
        }

        if ($profile->canUseEventLoop()) {
            return ['driver' => 'event', 'reason' => 'auto selected PHP event extension', 'warnings' => []];
        }

        return [
            'driver' => 'select',
            'reason' => 'auto fallback to stream_select because PHP event extension is unavailable',
            'warnings' => ['PHP event extension is missing; stream_select compatibility mode is slower.'],
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

        if ($strategy === self::STRATEGY_COMPATIBILITY) {
            return [
                'enabled' => false,
                'reason' => 'compatibility strategy keeps supervisor disabled',
                'warnings' => ['Supervisor is disabled by compatibility strategy.'],
            ];
        }
        if ($profile->isWindows()) {
            return [
                'enabled' => false,
                'reason' => 'auto disabled on Windows; legacy control plane avoids Supervisor reconnect churn',
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
        if (($topology['topology'] ?? '') === 'direct'
            && ($eventLoop['driver'] ?? '') === 'event'
            && !empty($supervisor['enabled'])
            && $warnings === []) {
            return 'optimal';
        }

        if (($topology['topology'] ?? '') === 'dispatcher') {
            return $warnings === [] ? 'stable' : 'compatibility';
        }

        return $warnings === [] ? 'degraded' : 'compatibility';
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
