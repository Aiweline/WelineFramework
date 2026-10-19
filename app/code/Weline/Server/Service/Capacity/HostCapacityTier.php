<?php
declare(strict_types=1);

namespace Weline\Server\Service\Capacity;

use Weline\Framework\App\Env;
use Weline\Server\Service\Memory\WorkerMemoryBudgetCalculator;

/**
 * Host memory tier → Fiber / Nginx / L7 ceilings.
 * Aligns small upper bound with {@see WorkerMemoryBudgetCalculator::DEFAULT_LOW_MEM_LIMIT_MB}.
 * Does not change worker_count (that remains worker_budget).
 */
final class HostCapacityTier
{
    public const TIER_TINY = 'tiny';
    public const TIER_SMALL = 'small';
    public const TIER_MEDIUM = 'medium';
    public const TIER_LARGE = 'large';

    public const ENV_HOST_LIMIT_MB = 'WLS_HOST_LIMIT_MB';
    public const ENV_FORCE_TIER = 'WLS_CAPACITY_TIER_FORCE';

    public const DEFAULT_TINY_MAX_MB = 1536;
    public const DEFAULT_SMALL_MAX_MB = WorkerMemoryBudgetCalculator::DEFAULT_LOW_MEM_LIMIT_MB;
    public const DEFAULT_MEDIUM_MAX_MB = 8192;

    /**
     * @return array{
     *   enabled:bool,
     *   tier:string,
     *   host_limit_mb:int,
     *   reason:string,
     *   survival_overrides_pin:bool,
     *   fiber_max_active_cap:?int,
     *   fiber_queue_wait_ms_cap:?int,
     *   admission_hard_floor_bytes:int,
     *   nginx_worker_connections_cap:int,
     *   nginx_upstream_keepalive_cap:int,
     *   edge_limit_enabled:bool,
     *   edge_limit_req_rate:int,
     *   edge_limit_req_burst:int,
     *   edge_limit_conn:int,
     *   l7_rate_scale:float
     * }
     */
    public static function resolve(?int $hostLimitMb = null, ?array $config = null): array
    {
        $config = $config ?? self::loadConfig();
        $enabled = !\array_key_exists('enabled', $config) || (bool)$config['enabled'];
        if (!$enabled) {
            return self::snapshot(
                enabled: false,
                tier: self::TIER_LARGE,
                hostLimitMb: \max(0, (int)($hostLimitMb ?? 0)),
                reason: 'capacity_tier.enabled=false',
                survivalOverridesPin: false,
                fiberMaxActiveCap: null,
                fiberQueueWaitMsCap: null,
                hardFloorBytes: 16 * 1024 * 1024,
                nginxConnectionsCap: 32768,
                nginxKeepaliveCap: 256,
                edgeLimitEnabled: false,
                edgeReqRate: 200,
                edgeReqBurst: 400,
                edgeLimitConn: 200,
                l7RateScale: 1.0,
            );
        }

        $force = self::normalizeForce(
            (string)($config['force'] ?? ''),
            self::envString(self::ENV_FORCE_TIER)
        );
        $limitMb = $hostLimitMb;
        if ($limitMb === null || $limitMb <= 0) {
            $limitMb = self::detectHostLimitMb();
        }

        if ($force !== null) {
            $tier = $force;
            $reason = 'force=' . $tier;
        } elseif ($limitMb === null || $limitMb <= 0) {
            // Fail-open: unknown host capacity must not down-tier a healthy machine.
            $tier = self::TIER_MEDIUM;
            $reason = 'host_limit_unknown_fail_open_medium';
            $limitMb = 0;
        } else {
            $tinyMax = self::positiveInt($config['tiny_max_mb'] ?? null, self::DEFAULT_TINY_MAX_MB);
            $smallMax = self::positiveInt($config['small_max_mb'] ?? null, self::DEFAULT_SMALL_MAX_MB);
            $mediumMax = self::positiveInt($config['medium_max_mb'] ?? null, self::DEFAULT_MEDIUM_MAX_MB);
            if ($limitMb <= $tinyMax) {
                $tier = self::TIER_TINY;
            } elseif ($limitMb <= $smallMax) {
                $tier = self::TIER_SMALL;
            } elseif ($limitMb <= $mediumMax) {
                $tier = self::TIER_MEDIUM;
            } else {
                $tier = self::TIER_LARGE;
            }
            $reason = 'host_limit_mb=' . $limitMb . ' → ' . $tier;
        }

        return match ($tier) {
            self::TIER_TINY => self::snapshot(
                enabled: true,
                tier: self::TIER_TINY,
                hostLimitMb: (int)$limitMb,
                reason: $reason,
                survivalOverridesPin: true,
                fiberMaxActiveCap: 2,
                fiberQueueWaitMsCap: 10000,
                hardFloorBytes: 32 * 1024 * 1024,
                nginxConnectionsCap: 4096,
                nginxKeepaliveCap: 32,
                edgeLimitEnabled: true,
                edgeReqRate: 20,
                edgeReqBurst: 40,
                edgeLimitConn: 20,
                l7RateScale: 0.25,
            ),
            self::TIER_SMALL => self::snapshot(
                enabled: true,
                tier: self::TIER_SMALL,
                hostLimitMb: (int)$limitMb,
                reason: $reason,
                survivalOverridesPin: true,
                fiberMaxActiveCap: 4,
                fiberQueueWaitMsCap: 15000,
                hardFloorBytes: 24 * 1024 * 1024,
                nginxConnectionsCap: 8192,
                nginxKeepaliveCap: 64,
                edgeLimitEnabled: true,
                edgeReqRate: 40,
                edgeReqBurst: 80,
                edgeLimitConn: 40,
                l7RateScale: 0.5,
            ),
            self::TIER_MEDIUM => self::snapshot(
                enabled: true,
                tier: self::TIER_MEDIUM,
                hostLimitMb: (int)$limitMb,
                reason: $reason,
                survivalOverridesPin: false,
                fiberMaxActiveCap: null,
                fiberQueueWaitMsCap: null,
                hardFloorBytes: 16 * 1024 * 1024,
                nginxConnectionsCap: 16384,
                nginxKeepaliveCap: 128,
                edgeLimitEnabled: true,
                edgeReqRate: 80,
                edgeReqBurst: 160,
                edgeLimitConn: 80,
                l7RateScale: 1.0,
            ),
            default => self::snapshot(
                enabled: true,
                tier: self::TIER_LARGE,
                hostLimitMb: (int)$limitMb,
                reason: $reason,
                survivalOverridesPin: false,
                fiberMaxActiveCap: null,
                fiberQueueWaitMsCap: null,
                hardFloorBytes: 16 * 1024 * 1024,
                nginxConnectionsCap: 32768,
                nginxKeepaliveCap: 256,
                edgeLimitEnabled: true,
                edgeReqRate: 200,
                edgeReqBurst: 400,
                edgeLimitConn: 200,
                l7RateScale: 1.0,
            ),
        };
    }

    /**
     * Single Fiber admission apply point (call after clampMaxActiveForMemoryLimit).
     *
     * @param array{
     *   max_active:int,
     *   queue_wait_ms:int,
     *   queue_depth:int,
     *   queue_slice_ms:int,
     *   admission_memory_headroom_bytes?:int,
     *   pin_max_active:bool,
     *   adaptive_max_active:bool
     * } $fiberConfig
     * @return array{
     *   max_active:int,
     *   queue_wait_ms:int,
     *   queue_depth:int,
     *   queue_slice_ms:int,
     *   admission_memory_headroom_bytes:int,
     *   pin_max_active:bool,
     *   adaptive_max_active:bool,
     *   capacity_tier:string,
     *   capacity_tier_reason:string,
     *   admission_hard_floor_bytes:int
     * }
     */
    public static function applyFiberAdmission(
        array $fiberConfig,
        int $clampedMaxActive,
        ?int $hostLimitMb = null,
        ?array $config = null
    ): array {
        $snap = self::resolve($hostLimitMb, $config);
        $maxActive = $clampedMaxActive;
        $pin = (bool)($fiberConfig['pin_max_active'] ?? false);
        $waitMs = (int)($fiberConfig['queue_wait_ms'] ?? 5000);

        if ($snap['fiber_max_active_cap'] !== null) {
            $cap = (int)$snap['fiber_max_active_cap'];
            if ($maxActive <= 0) {
                // Unlimited on tiny/small still gets a survival cap.
                if ($snap['survival_overrides_pin']) {
                    $maxActive = $cap;
                }
            } else {
                $maxActive = \min($maxActive, $cap);
            }
        }

        if ($snap['fiber_queue_wait_ms_cap'] !== null) {
            $waitMs = \min($waitMs, (int)$snap['fiber_queue_wait_ms_cap']);
        }

        if ($snap['survival_overrides_pin']) {
            $pin = false;
        }

        return [
            'max_active' => $maxActive,
            'queue_wait_ms' => $waitMs,
            'queue_depth' => (int)($fiberConfig['queue_depth'] ?? 0),
            'queue_slice_ms' => (int)($fiberConfig['queue_slice_ms'] ?? 25),
            'admission_memory_headroom_bytes' => (int)($fiberConfig['admission_memory_headroom_bytes'] ?? 0),
            'pin_max_active' => $pin,
            'adaptive_max_active' => (bool)($fiberConfig['adaptive_max_active'] ?? true),
            'capacity_tier' => $snap['tier'],
            'capacity_tier_reason' => $snap['reason'],
            'admission_hard_floor_bytes' => $snap['admission_hard_floor_bytes'],
        ];
    }

    /** medium+/large + pin：店面 Ready 底线（keep pin；不压低显式更大 requested）。 */
    public const PINNED_STOREFRONT_WORKER_FLOOR = 4;

    /**
     * When capacity tier is medium|large and fiber pin_max_active is on, raise
     * worker_count to at least {@see PINNED_STOREFRONT_WORKER_FLOOR}.
     * tiny/small survival path and explicit higher counts are left alone.
     *
     * @param array<string, mixed>|null $capacitySnap result of {@see resolve()}
     * @return array{count:int, applied:bool, reason:string}
     */
    public static function applyPinnedStorefrontWorkerFloor(
        int $workerCount,
        bool $pinMaxActive,
        ?array $capacitySnap = null,
        ?int $hostLimitMb = null,
        ?array $config = null,
    ): array {
        $snap = $capacitySnap ?? self::resolve($hostLimitMb, $config);
        $tier = (string)($snap['tier'] ?? '');
        $survival = (bool)($snap['survival_overrides_pin'] ?? false);
        $count = \max(1, $workerCount);
        if (!$pinMaxActive || $survival) {
            return ['count' => $count, 'applied' => false, 'reason' => 'skip_pin_or_survival'];
        }
        if ($tier !== self::TIER_MEDIUM && $tier !== self::TIER_LARGE) {
            return ['count' => $count, 'applied' => false, 'reason' => 'tier_not_medium_plus'];
        }
        $floor = self::PINNED_STOREFRONT_WORKER_FLOOR;
        if ($count >= $floor) {
            return ['count' => $count, 'applied' => false, 'reason' => 'already_ge_floor'];
        }

        return [
            'count' => $floor,
            'applied' => true,
            'reason' => 'pinned_storefront_worker_floor=' . $floor . ';tier=' . $tier,
        ];
    }

    public static function clampNginxWorkerConnections(int $configured, ?int $hostLimitMb = null, ?array $config = null): int
    {
        $snap = self::resolve($hostLimitMb, $config);
        return \max(1024, \min($configured, $snap['nginx_worker_connections_cap']));
    }

    public static function clampNginxUpstreamKeepalive(int $configured, ?int $hostLimitMb = null, ?array $config = null): int
    {
        $snap = self::resolve($hostLimitMb, $config);
        return \max(16, \min($configured, $snap['nginx_upstream_keepalive_cap']));
    }

    /**
     * Scale L7 rate_limit numbers on an in-memory rules snapshot only.
     * Never persist the result back to SecurityPolicyStateStore.
     *
     * @param array<string, mixed> $rules
     * @return array<string, mixed>
     */
    public static function scaleAttackRules(array $rules, ?int $hostLimitMb = null, ?array $config = null): array
    {
        $snap = self::resolve($hostLimitMb, $config);
        $scale = (float)$snap['l7_rate_scale'];
        if ($scale >= 0.999 || $scale <= 0.0) {
            return $rules;
        }

        if (\is_array($rules['rate_limit'] ?? null)) {
            $max = (int)($rules['rate_limit']['max_requests'] ?? 0);
            if ($max > 0) {
                $rules['rate_limit']['max_requests'] = \max(1, (int)\floor($max * $scale));
            }
        }

        if (\is_array($rules['path_rate_limits']['rules'] ?? null)) {
            foreach ($rules['path_rate_limits']['rules'] as $i => $row) {
                if (!\is_array($row)) {
                    continue;
                }
                $max = (int)($row['max_requests'] ?? 0);
                if ($max > 0) {
                    $rules['path_rate_limits']['rules'][$i]['max_requests'] = \max(1, (int)\floor($max * $scale));
                }
            }
        }

        return $rules;
    }

    /**
     * @return array{enabled:bool,rate:int,burst:int,conn:int,http_zone_block:string,location_directives:string}
     */
    public static function nginxEdgeLimitFragments(?int $hostLimitMb = null, ?array $config = null): array
    {
        $snap = self::resolve($hostLimitMb, $config);
        if (!$snap['edge_limit_enabled']) {
            return [
                'enabled' => false,
                'rate' => 0,
                'burst' => 0,
                'conn' => 0,
                'http_zone_block' => '',
                'location_directives' => '',
            ];
        }

        $rate = (int)$snap['edge_limit_req_rate'];
        $burst = (int)$snap['edge_limit_req_burst'];
        $conn = (int)$snap['edge_limit_conn'];
        $httpZone = <<<NGINX
    limit_req_zone \$binary_remote_addr zone=wls_req:10m rate={$rate}r/s;
    limit_conn_zone \$binary_remote_addr zone=wls_conn:10m;

NGINX;
        $location = <<<NGINX
            limit_req zone=wls_req burst={$burst} nodelay;
            limit_conn wls_conn {$conn};

NGINX;

        return [
            'enabled' => true,
            'rate' => $rate,
            'burst' => $burst,
            'conn' => $conn,
            'http_zone_block' => $httpZone,
            'location_directives' => $location,
        ];
    }

    public static function detectHostLimitMb(): ?int
    {
        $env = self::envString(self::ENV_HOST_LIMIT_MB);
        if ($env !== null && \ctype_digit($env) && (int)$env > 0) {
            return (int)$env;
        }

        try {
            if (\class_exists(\Weline\Server\Service\Runtime\RuntimeCapabilityDetector::class)) {
                $detector = new \Weline\Server\Service\Runtime\RuntimeCapabilityDetector();
                if (\method_exists($detector, 'detect')) {
                    $profile = $detector->detect();
                    if (\is_object($profile) && \method_exists($profile, 'memoryMb')) {
                        $mb = $profile->memoryMb();
                        if (\is_int($mb) && $mb > 0) {
                            return $mb;
                        }
                    }
                }
            }
        } catch (\Throwable) {
            // fall through
        }

        $cgroup = self::readCgroupMemoryMaxMb();
        if ($cgroup !== null) {
            return $cgroup;
        }

        return self::readMemTotalMb();
    }

    /**
     * @return array<string, mixed>
     */
    public static function loadConfig(): array
    {
        try {
            if (!\defined('BP') && !\defined('Weline\\Framework\\App\\BP')) {
                return [];
            }
            $config = Env::get('wls.capacity_tier', []);
            return \is_array($config) ? $config : [];
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @return array{
     *   enabled:bool,
     *   tier:string,
     *   host_limit_mb:int,
     *   reason:string,
     *   survival_overrides_pin:bool,
     *   fiber_max_active_cap:?int,
     *   fiber_queue_wait_ms_cap:?int,
     *   admission_hard_floor_bytes:int,
     *   nginx_worker_connections_cap:int,
     *   nginx_upstream_keepalive_cap:int,
     *   edge_limit_enabled:bool,
     *   edge_limit_req_rate:int,
     *   edge_limit_req_burst:int,
     *   edge_limit_conn:int,
     *   l7_rate_scale:float
     * }
     */
    private static function snapshot(
        bool $enabled,
        string $tier,
        int $hostLimitMb,
        string $reason,
        bool $survivalOverridesPin,
        ?int $fiberMaxActiveCap,
        ?int $fiberQueueWaitMsCap,
        int $hardFloorBytes,
        int $nginxConnectionsCap,
        int $nginxKeepaliveCap,
        bool $edgeLimitEnabled,
        int $edgeReqRate,
        int $edgeReqBurst,
        int $edgeLimitConn,
        float $l7RateScale,
    ): array {
        return [
            'enabled' => $enabled,
            'tier' => $tier,
            'host_limit_mb' => $hostLimitMb,
            'reason' => $reason,
            'survival_overrides_pin' => $survivalOverridesPin,
            'fiber_max_active_cap' => $fiberMaxActiveCap,
            'fiber_queue_wait_ms_cap' => $fiberQueueWaitMsCap,
            'admission_hard_floor_bytes' => $hardFloorBytes,
            'nginx_worker_connections_cap' => $nginxConnectionsCap,
            'nginx_upstream_keepalive_cap' => $nginxKeepaliveCap,
            'edge_limit_enabled' => $edgeLimitEnabled,
            'edge_limit_req_rate' => $edgeReqRate,
            'edge_limit_req_burst' => $edgeReqBurst,
            'edge_limit_conn' => $edgeLimitConn,
            'l7_rate_scale' => $l7RateScale,
        ];
    }

    private static function normalizeForce(string $configForce, ?string $envForce): ?string
    {
        $raw = \strtolower(\trim($configForce !== '' ? $configForce : (string)$envForce));
        return match ($raw) {
            self::TIER_TINY, self::TIER_SMALL, self::TIER_MEDIUM, self::TIER_LARGE => $raw,
            default => null,
        };
    }

    private static function envString(string $key): ?string
    {
        $v = $_ENV[$key] ?? $_SERVER[$key] ?? \getenv($key);
        if (!\is_string($v) || \trim($v) === '') {
            return null;
        }

        return \trim($v);
    }

    private static function positiveInt(mixed $value, int $default): int
    {
        if (\is_int($value) && $value > 0) {
            return $value;
        }
        if (\is_string($value) && \ctype_digit($value) && (int)$value > 0) {
            return (int)$value;
        }

        return $default;
    }

    private static function readMemTotalMb(): ?int
    {
        $path = '/proc/meminfo';
        if (!\is_readable($path)) {
            return null;
        }
        $raw = @\file_get_contents($path);
        if (!\is_string($raw) || $raw === '') {
            return null;
        }
        if (!\preg_match('/^MemTotal:\s+(\d+)\s+kB/m', $raw, $m)) {
            return null;
        }

        return \max(1, (int)\floor(((int)$m[1]) / 1024));
    }

    private static function readCgroupMemoryMaxMb(): ?int
    {
        foreach (['/sys/fs/cgroup/memory.max', '/sys/fs/cgroup/memory/memory.limit_in_bytes'] as $path) {
            if (!\is_readable($path)) {
                continue;
            }
            $raw = \trim((string)@\file_get_contents($path));
            if ($raw === '' || \strtolower($raw) === 'max') {
                continue;
            }
            if (!\ctype_digit($raw)) {
                continue;
            }
            $bytes = (int)$raw;
            if ($bytes <= 0 || $bytes >= PHP_INT_MAX / 2) {
                continue;
            }

            return \max(1, (int)\floor($bytes / (1024 * 1024)));
        }

        return null;
    }
}
