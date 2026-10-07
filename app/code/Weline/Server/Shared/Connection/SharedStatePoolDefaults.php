<?php

declare(strict_types=1);

namespace Weline\Server\Shared\Connection;

/**
 * Single source of default SharedState (Session/Memory) client pool options.
 *
 * MemoryStateFacade and SharedRuntimeConnectionWarmup must consume these
 * defaults so prewarm sockets and business leases share the same budgets.
 */
final class SharedStatePoolDefaults
{
    /**
     * Per-Worker Memory client pool. Under high concurrency (c1000+, shared
     * sidecar), 8 slots + 50ms IO budgets produce systematic read timeouts and
     * wls_cache_cas remote_unavailable; keep fail-fast but sized for contention.
     */
    public const MEMORY_POOL_SIZE = 32;
    public const MEMORY_MIN_IDLE = 2;

    /**
     * @return array{
     *   connect_timeout:float,
     *   timeout:float,
     *   pool_size:int,
     *   pool_min_idle:int,
     *   acquire_timeout:float,
     *   idle_timeout:float,
     *   pool_health_ping_idle:bool,
     *   fail_fast_on_cooldown:bool
     * }
     */
    public static function memoryClientOptions(bool $wlsMode = true): array
    {
        return [
            // Local shared-state p99 is sub-ms when idle; under Worker×Fiber
            // contention the Memory sidecar queue routinely exceeds 50ms.
            'connect_timeout' => $wlsMode ? 0.15 : 1.0,
            'timeout' => $wlsMode ? 0.25 : 2.0,
            'pool_size' => self::MEMORY_POOL_SIZE,
            'pool_min_idle' => self::MEMORY_MIN_IDLE,
            'acquire_timeout' => $wlsMode ? 0.1 : 0.2,
            'idle_timeout' => 86400.0,
            'pool_health_ping_idle' => false,
            'fail_fast_on_cooldown' => $wlsMode,
        ];
    }

    /** @param array<string, mixed> $config @return array<string, mixed> */
    public static function sessionClientOptions(array $config = []): array
    {
        return [
            'connect_timeout' => (float) ($config['connect_timeout'] ?? 0.5),
            'timeout' => (float) ($config['timeout'] ?? 1.0),
            'pool_size' => (int) ($config['pool_size'] ?? 8),
            'pool_min_idle' => (int) ($config['pool_min_idle'] ?? 0),
            'acquire_timeout' => (float) ($config['acquire_timeout'] ?? 0.1),
            'idle_timeout' => (float) ($config['idle_timeout'] ?? 86400.0),
            'pool_health_ping_idle' => (bool) ($config['pool_health_ping_idle'] ?? false),
        ];
    }

    /**
     * Prewarm uses the same connect/read budgets as the business Memory facade.
     *
     * @param array<string, mixed> $policyOverrides optional RuntimeCachePolicy values (ignored when stricter defaults apply)
     * @return array<string, mixed>
     */
    public static function memoryPrewarmOptions(array $policyOverrides = []): array
    {
        $base = self::memoryClientOptions(true);
        // Prefer the unified WLS budgets; only allow policy to tighten further.
        $connect = (float) ($policyOverrides['connect_timeout'] ?? $base['connect_timeout']);
        $timeout = (float) ($policyOverrides['timeout'] ?? $base['timeout']);
        $acquire = (float) ($policyOverrides['acquire_timeout'] ?? $base['acquire_timeout']);

        $base['connect_timeout'] = \min($base['connect_timeout'], \max(0.001, $connect));
        $base['timeout'] = \min($base['timeout'], \max(0.001, $timeout));
        $base['acquire_timeout'] = \min($base['acquire_timeout'], \max(0.001, $acquire));

        return $base;
    }
}
