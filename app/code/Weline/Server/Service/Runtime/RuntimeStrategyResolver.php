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
        $workerCount = $this->resolveWorkerCount($config['worker_count'] ?? 'auto', (string)($config['mode'] ?? 'io'), $strategy, $profile);
        $topology = $this->resolveTopology($config, $args, $profile, $workerCount, $strategy);
        $eventLoop = $this->resolveEventLoopDriver(
            (string)($config['event_loop'] ?? ($loop['driver'] ?? 'auto')),
            $profile
        );
        $supervisor = $this->resolveSupervisor($config, $profile, $strategy);

        $warnings = \array_merge(
            $topology['warnings'],
            $eventLoop['warnings'],
            $supervisor['warnings']
        );

        return [
            'runtime_strategy' => $strategy,
            'status' => $this->resolveStatus($warnings, $topology, $eventLoop, $supervisor),
            'worker_count' => $workerCount,
            'worker_count_reason' => $this->workerCountReason($config['worker_count'] ?? 'auto', (string)($config['mode'] ?? 'io'), $profile, $strategy),
            'topology' => $topology['topology'],
            'dispatcher_enabled' => $topology['dispatcher_enabled'],
            'direct_reuse_port' => $topology['direct_reuse_port'],
            'topology_reason' => $topology['reason'],
            'event_loop_driver' => $eventLoop['driver'],
            'event_loop_reason' => $eventLoop['reason'],
            'supervisor_enabled' => $supervisor['enabled'],
            'supervisor_reason' => $supervisor['reason'],
            'warnings' => $warnings,
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
     * @return array{topology:string,dispatcher_enabled:bool,direct_reuse_port:bool,reason:string,warnings:string[]}
     */
    private function resolveTopology(
        array $config,
        array $args,
        WlsRuntimeProfile $profile,
        int $workerCount,
        string $strategy
    ): array {
        $runtime = \is_array($config['runtime'] ?? null) ? $config['runtime'] : [];
        $requested = \strtolower(\trim((string)($config['topology'] ?? ($runtime['topology'] ?? 'auto'))));
        if (isset($args['direct'])) {
            $requested = 'direct';
        } elseif (isset($args['no-dispatcher']) || isset($args['no_dispatcher'])) {
            $requested = 'independent';
        } elseif (isset($args['dispatcher']) || isset($args['force-dispatcher'])) {
            $requested = 'dispatcher';
        }
        if (!\in_array($requested, ['auto', 'direct', 'dispatcher', 'independent'], true)) {
            $requested = 'auto';
        }

        if ($requested === 'direct') {
            if (!$profile->supportsReusePort()) {
                throw new \RuntimeException('WLS direct topology requires SO_REUSEPORT on this OS/kernel.');
            }
            return [
                'topology' => 'direct',
                'dispatcher_enabled' => false,
                'direct_reuse_port' => true,
                'reason' => 'explicit --direct with SO_REUSEPORT support',
                'warnings' => [],
            ];
        }

        if ($requested === 'dispatcher') {
            return [
                'topology' => 'dispatcher',
                'dispatcher_enabled' => true,
                'direct_reuse_port' => false,
                'reason' => 'explicit Dispatcher topology',
                'warnings' => [],
            ];
        }

        if ($requested === 'independent') {
            return [
                'topology' => $workerCount <= 1 ? 'single' : 'independent',
                'dispatcher_enabled' => false,
                'direct_reuse_port' => false,
                'reason' => $workerCount <= 1
                    ? 'explicit no-dispatcher single worker topology'
                    : 'explicit no-dispatcher independent worker ports',
                'warnings' => [],
            ];
        }

        return [
            'topology' => 'dispatcher',
            'dispatcher_enabled' => true,
            'direct_reuse_port' => false,
            'reason' => $profile->isWindows()
                ? 'auto selected Dispatcher on Windows for stable multi-process networking'
                : 'auto selected Dispatcher default topology; use --direct to enable SO_REUSEPORT direct mode',
            'warnings' => [],
        ];
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
        return 'auto worker count from cpu=' . $profile->cpuCores()
            . ', mode=' . (\strtolower($mode) === 'cpu' ? 'cpu' : 'io')
            . ', strategy=' . $strategy
            . $memoryNote;
    }
}
