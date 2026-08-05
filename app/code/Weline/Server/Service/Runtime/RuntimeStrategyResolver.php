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
        $workerCountInput = \is_string($requestedWorkerCount)
            && \strtolower(\trim($requestedWorkerCount)) === 'auto'
                ? 'auto'
                : ($config['worker_count'] ?? $requestedWorkerCount);
        $workerMemoryLimitMb = \Weline\Server\Service\Memory\WorkerMemoryBudgetCalculator::memoryLimitToMb(
            $config['worker_memory_limit'] ?? '256M'
        );
        $workerCountResult = $this->resolveWorkerCountDetailed(
            $workerCountInput,
            (string)($config['mode'] ?? 'io'),
            $strategy,
            $profile,
            $workerMemoryLimitMb
        );
        $workerCount = $workerCountResult['count'];
        $topology = $this->resolveTopology($config, $args, $profile);
        $eventLoop = $this->resolveEventLoopDriver(
            (string)($config['event_loop'] ?? ($loop['driver'] ?? 'auto')),
            $profile
        );
        $sslEngine = $this->resolveSslEngine($config);
        if ($topology['effective'] === EffectiveTopology::Direct) {
            if ($topology['listener_mode'] !== 'worker_ports' && $eventLoop['driver'] !== 'event') {
                throw new \RuntimeException(
                    'WLS shared-listener Direct topology requires the PHP event extension and event loop; '
                    . 'install/enable ext-event, use Windows worker_ports, or explicitly select --dispatcher.'
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
            'worker_count_reason' => $workerCountResult['reason'] !== ''
                ? $workerCountResult['reason']
                : $this->workerCountReason(
                    $requestedWorkerCount,
                    (string)($config['mode'] ?? 'io'),
                    $profile,
                    $strategy
                ),
            'budget_ceiling' => $workerCountResult['budget_ceiling'],
            'worker_budget' => $workerCountResult['budget'],
            'event_loop_reason' => $eventLoop['reason'],
            'supervisor_enabled' => $supervisor['enabled'],
            'supervisor_reason' => $supervisor['reason'],
            'warnings' => $warnings,
            'runtime_selection' => $selection,
        ];
    }

    public function resolveWorkerCount(
        mixed $workerCount,
        string $mode,
        string $strategy,
        WlsRuntimeProfile $profile,
        int $workerMemoryLimitMb = 256
    ): int {
        return $this->resolveWorkerCountDetailed(
            $workerCount,
            $mode,
            $strategy,
            $profile,
            $workerMemoryLimitMb
        )['count'];
    }

    /**
     * @return array{
     *   count:int,
     *   budget_ceiling:int,
     *   reason:string,
     *   budget:?array<string, mixed>
     * }
     */
    public function resolveWorkerCountDetailed(
        mixed $workerCount,
        string $mode,
        string $strategy,
        WlsRuntimeProfile $profile,
        int $workerMemoryLimitMb = 256
    ): array {
        $strategy = $this->normalizeStrategy($strategy);
        if (\is_int($workerCount) && $workerCount > 0) {
            return [
                'count' => $workerCount,
                'budget_ceiling' => $workerCount,
                'reason' => 'explicit worker count',
                'budget' => null,
            ];
        }
        if (\is_string($workerCount) && \ctype_digit($workerCount) && (int)$workerCount > 0) {
            $explicit = (int)$workerCount;
            return [
                'count' => $explicit,
                'budget_ceiling' => $explicit,
                'reason' => 'explicit worker count',
                'budget' => null,
            ];
        }

        $cpuBased = $this->resolveCpuBasedWorkerCount($mode, $strategy, $profile);
        $calculator = new \Weline\Server\Service\Memory\WorkerMemoryBudgetCalculator();
        if ($calculator->isEnabled()) {
            $limitMb = $profile->memoryMb() ?? 0;
            $limitSource = $profile->memoryLimitSource();
            if ($limitMb <= 0) {
                return [
                    'count' => \max(1, $cpuBased),
                    'budget_ceiling' => \max(1, $cpuBased),
                    'reason' => 'auto worker count without memory capacity; cpu_based=' . $cpuBased,
                    'budget' => null,
                ];
            }
            $budget = $calculator->calculate(
                $cpuBased,
                $limitMb,
                $limitSource,
                $workerMemoryLimitMb,
                $strategy
            );

            return [
                'count' => $budget['desired'],
                'budget_ceiling' => $budget['budget_ceiling'],
                'reason' => $budget['reason'],
                'budget' => $budget,
            ];
        }

        // Legacy fallback when worker_budget.enabled=false.
        $count = $cpuBased;
        $memoryMb = $profile->memoryMb();
        if ($memoryMb !== null && $memoryMb > 0) {
            $memoryCap = \max(1, (int)\floor($memoryMb / 512));
            $count = \min($count, \max(2, $memoryCap));
        }

        $count = \max(1, $count);
        return [
            'count' => $count,
            'budget_ceiling' => $count,
            'reason' => '',
            'budget' => null,
        ];
    }

    private function resolveCpuBasedWorkerCount(string $mode, string $strategy, WlsRuntimeProfile $profile): int
    {
        $strategy = $this->normalizeStrategy($strategy);
        $cpu = $profile->cpuCores();
        $mode = \strtolower(\trim($mode)) === 'cpu' ? 'cpu' : 'io';

        if ($profile->isWindows()) {
            $base = $mode === 'cpu' ? $cpu : (int)\ceil($cpu / 2);
            $count = \min(\max(2, $base), 8);
            if ($strategy === self::STRATEGY_PERFORMANCE) {
                $count = \min(\max($count, $cpu), 12);
            }

            return \max(1, $count);
        }

        if ($profile->isDarwin()) {
            return \min(\max(1, $profile->performanceCpuCores()), 16);
        }

        $count = $mode === 'cpu' ? $cpu : $cpu * 2;
        if ($strategy === self::STRATEGY_STABILITY) {
            $count = $mode === 'cpu' ? $cpu : (int)\ceil($cpu * 1.5);
        }

        return \min(\max(2, $count), 16);
    }

    /**
     * Resolve the platform topology contract without probing optional runtime
     * dependencies. This is the single pre-install source used by server:start:
     * POSIX uses a verified shared listener. A Windows schema-5 startup lease
     * is duplicated into one Dispatcher so the reserved socket remains owned
     * continuously; legacy managed Nginx without that marker may still use one
     * loopback port per Worker.
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
        $listenerMode = $this->configuredDirectListenerMode($config);
        $edge = \is_array($config['edge'] ?? null) ? $config['edge'] : [];
        $pureWls = \strtolower(\trim((string)(
            $config['edge_adapter']
            ?? $edge['adapter']
            ?? \Weline\Server\Service\Edge\EdgeAdapterInterface::NAME_NGINX
        ))) === \Weline\Server\Service\Edge\EdgeAdapterInterface::NAME_WLS;
        $gateway = \is_array($config['gateway'] ?? null) ? $config['gateway'] : [];
        $startupHandoff = \is_array($gateway['startup_listener_handoff'] ?? null)
            ? $gateway['startup_listener_handoff']
            : [];
        $requiresWindowsDispatcher = ($startupHandoff['continuous_ownership'] ?? false) === true
            && \hash_equals(
                WindowsListenerHandoff::TRANSPORT,
                (string)($startupHandoff['transport'] ?? ''),
            );

        if ($osFamily === 'Windows') {
            if ($requiresWindowsDispatcher && $requested === RequestedTopology::Direct) {
                throw new \RuntimeException(
                    'Windows continuous listener handoff requires the single Dispatcher owner; '
                    . 'remove --direct and start with --dispatcher.'
                );
            }
            if ($requested === RequestedTopology::Dispatcher
                || $requiresWindowsDispatcher
                || ($pureWls && $requested === RequestedTopology::Auto)
            ) {
                if ($listenerMode !== 'auto') {
                    throw new \RuntimeException(
                        'wls.runtime.listener_mode is valid only for Direct topology; explicit Dispatcher requires auto.'
                    );
                }

                return [
                    'requested' => $requested,
                    'effective' => EffectiveTopology::Dispatcher,
                    'source' => $source,
                    'reason' => $requiresWindowsDispatcher
                        ? 'Windows schema-5 listener ownership selected the single Dispatcher endpoint'
                        : ($pureWls && $requested === RequestedTopology::Auto
                            ? 'pure WLS on Windows selected the single public Dispatcher endpoint'
                            : 'explicit Dispatcher topology'),
                    'reason_code' => $requiresWindowsDispatcher
                        ? 'windows_continuous_listener_dispatcher'
                        : ($pureWls && $requested === RequestedTopology::Auto
                            ? 'windows_pure_wls_auto_dispatcher'
                            : 'explicit_dispatcher'),
                ];
            }
            if ($pureWls) {
                throw new \RuntimeException(
                    'Windows pure WLS cannot use per-Worker Direct ports without Nginx; '
                    . 'remove --direct or explicitly use --dispatcher.'
                );
            }
            if (!\in_array($listenerMode, ['auto', 'worker_ports'], true)) {
                throw new \RuntimeException(
                    'Windows Direct requires wls.runtime.listener_mode=auto or worker_ports.'
                );
            }

            return [
                'requested' => $requested,
                'effective' => EffectiveTopology::Direct,
                'source' => $source,
                'reason' => $requested === RequestedTopology::Auto
                    ? 'auto selected Nginx-balanced per-Worker loopback ports on Windows'
                    : 'explicit Direct topology with Nginx-balanced per-Worker loopback ports',
                'reason_code' => $requested === RequestedTopology::Auto
                    ? 'windows_auto_direct_worker_ports'
                    : 'windows_explicit_direct_worker_ports',
            ];
        }

        if (!\in_array($osFamily, ['Linux', 'Darwin'], true)) {
            throw new \RuntimeException(
                'WLS supports Windows and Linux/macOS Direct plus explicit Dispatcher topology; '
                . 'the current platform "' . $osFamily . '" is unsupported.'
            );
        }

        if ($requested === RequestedTopology::Dispatcher) {
            if ($listenerMode !== 'auto') {
                throw new \RuntimeException(
                    'wls.runtime.listener_mode is valid only for Direct topology; explicit Dispatcher requires auto.'
                );
            }
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

        $listenerMode = $this->resolveDirectListenerMode(
            $this->configuredDirectListenerMode($config),
            $profile,
        );
        $listenerLabel = match ($listenerMode) {
            'shared_fd' => 'Master-owned shared listener FD',
            'reuseport' => 'SO_REUSEPORT',
            'worker_ports' => 'Nginx-balanced per-Worker loopback ports',
            default => $listenerMode,
        };
        $platformLabel = $profile->isWindows()
            ? 'Windows'
            : ($profile->isLinux() ? 'Linux' : 'macOS');

        $warnings = $listenerMode === 'reuseport'
            ? [
                'Explicit SO_REUSEPORT uses one TCP accept queue per Worker; connections already queued '
                . 'to a retiring Worker can be reset during replacement. Use listener_mode=auto/shared_fd '
                . 'when lossless reload is required.',
            ]
            : [];

        return $this->topologyResult(
            $requested,
            EffectiveTopology::Direct,
            $source,
            $requested === RequestedTopology::Auto
                ? 'auto selected ' . $listenerLabel . ' direct topology on ' . $platformLabel
                : 'explicit direct topology with verified ' . $listenerLabel . ' support',
            $intent['reason_code'],
            $warnings,
            $listenerMode,
        );
    }

    /**
     * POSIX auto uses the Master-owned shared FD so every Worker consumes one
     * accept queue. A retiring Worker can then close only its descriptor
     * without resetting connections that the kernel has already queued.
     * Linux SO_REUSEPORT remains an explicit performance option because its
     * per-Worker accept queues cannot provide lossless process replacement
     * without a privileged TCP reuseport steering program.
     */
    private function resolveDirectListenerMode(string $requested, WlsRuntimeProfile $profile): string
    {
        if ($profile->isWindows()) {
            if (!\in_array($requested, ['auto', 'worker_ports'], true)) {
                throw new \RuntimeException(
                    'Windows Direct requires wls.runtime.listener_mode=auto or worker_ports.'
                );
            }

            return 'worker_ports';
        }

        if ($requested === 'worker_ports') {
            throw new \RuntimeException(
                'wls.runtime.listener_mode=worker_ports is supported only on Windows Direct topology.'
            );
        }
        if ($requested === 'reuseport') {
            if (!$profile->isLinux()) {
                throw new \RuntimeException(
                    'Explicit wls.runtime.listener_mode=reuseport is supported only on Linux Direct topology.'
                );
            }
            if (!$profile->supportsReusePort()) {
                $probe = $profile->reusePortProbe();
                $reason = \trim((string)($probe['reason'] ?? 'The SO_REUSEPORT capability probe failed.'));
                throw new \RuntimeException(
                    'Explicit wls.runtime.listener_mode=reuseport requires verified SO_REUSEPORT support. '
                    . $reason
                );
            }

            return 'reuseport';
        }
        $probe = $profile->directListenerProbe();
        $sharedFdSupported = $profile->supportsDirectListener()
            && $profile->directListenerMode() === 'shared_fd';
        if (!$sharedFdSupported) {
            $reason = \trim((string)($probe['reason'] ?? 'The shared-listener capability probe failed.'));
            $prefix = $requested === 'shared_fd'
                ? 'Explicit wls.runtime.listener_mode=shared_fd requires verified inherited-FD support. '
                : 'WLS direct topology requires a verified Master-owned shared listener. ';
            throw new \RuntimeException(
                $prefix . $reason
                . ' Install the required runtime dependencies, explicitly configure listener_mode=reuseport, '
                . 'or explicitly select --dispatcher.'
            );
        }

        return 'shared_fd';
    }

    /**
     * @param array<string, mixed> $config
     */
    private function configuredDirectListenerMode(array $config): string
    {
        $runtime = \is_array($config['runtime'] ?? null) ? $config['runtime'] : [];
        $requested = \strtolower(\trim((string)($runtime['listener_mode'] ?? 'auto')));
        $requested = $requested !== '' ? $requested : 'auto';
        if (!\in_array($requested, ['auto', 'shared_fd', 'reuseport', 'worker_ports'], true)) {
            throw new \RuntimeException(
                'wls.runtime.listener_mode must be one of auto/shared_fd/reuseport/worker_ports; received "'
                . $requested . '".'
            );
        }

        return $requested;
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
        $edge = \is_array($config['edge'] ?? null) ? $config['edge'] : [];
        $edgeAdapter = \strtolower(\trim((string)(
            $config['edge_adapter']
            ?? $edge['adapter']
            ?? \Weline\Server\Service\Edge\EdgeAdapterInterface::NAME_NGINX
        )));
        $pureWls = $edgeAdapter === \Weline\Server\Service\Edge\EdgeAdapterInterface::NAME_WLS;
        $defaultEngine = $pureWls ? 'stream' : 'none';
        $configuredEngine = \strtolower(\trim((string)($ssl['engine'] ?? $defaultEngine)));
        $configuredEngine = $configuredEngine !== '' ? $configuredEngine : $defaultEngine;
        if (!\in_array($configuredEngine, ['none', 'stream'], true)) {
            throw new \RuntimeException(
                (string)__('wls.ssl.engine 仅支持 none/stream；收到 %{1}。', [
                    $configuredEngine,
                ])
            );
        }

        try {
            $tlsSessionCache = TlsSessionCacheConfig::fromSslConfig($ssl);
        } catch (\InvalidArgumentException $exception) {
            throw new \RuntimeException($exception->getMessage(), 0, $exception);
        }
        if (!$pureWls && $tlsSessionCache->enabled()) {
            throw new \RuntimeException(
                (string)__('Nginx 模式不允许 WLS external TLS Session Cache；TLS Session Cache 与 Ticket 由项目托管 Nginx 处理。')
            );
        }

        // Managed Nginx terminates public TLS and always uses a plaintext WLS
        // backend. Explicit pure WLS owns TLS in the PHP Stream SSL engine.
        return $pureWls ? 'stream' : 'none';
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
