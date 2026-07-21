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
    public const MEMORY_POOL_SIZE = 8;
    public const MEMORY_MIN_IDLE = 0;

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
            'connect_timeout' => $wlsMode ? 0.05 : 1.0,
            'timeout' => $wlsMode ? 0.05 : 2.0,
            'pool_size' => self::MEMORY_POOL_SIZE,
            'pool_min_idle' => self::MEMORY_MIN_IDLE,
            'acquire_timeout' => $wlsMode ? 0.01 : 0.2,
            'idle_timeout' => 86400.0,
            'pool_health_ping_idle' => false,
            'fail_fast_on_cooldown' => $wlsMode,
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
