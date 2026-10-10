<?php
declare(strict_types=1);

namespace Weline\Server\Service\Capacity;

use Weline\Backend\Api\Config\BackendConfigStore;

/**
 * Global (backend area) capacity-advisor thresholds via BackendConfigStore.
 * Never reads or writes app/etc/env.php.
 */
final class CapacityAdvisorConfigService
{
    public const MODULE = 'Weline_Server';
    public const KEY_PREFIX = 'capacity_advisor.';
    public const CACHE_TTL_SEC = 45.0;

    /** @var array<string, mixed>|null */
    private static ?array $cache = null;
    private static float $cacheAt = 0.0;

    public function __construct(
        private readonly ?BackendConfigStore $store = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        return [
            'enabled' => true,
            'interval_sec' => 60,
            'window_sec' => 600,
            'ring_points_max' => 64,
            'report_stale_sec' => 90,
            'soft_pressure' => 0.70,
            'frequent_compact_count' => 8,
            'sustained_soft_sample_ratio' => 0.50,
            'volatility_cv' => 0.35,
            'host_available_ratio_min' => 0.15,
            'host_pressure_max' => 0.85,
            'notify_enabled' => true,
            'sample_retain_days' => 14,
            'rules' => [
                'frequent_compact' => true,
                'sustained_soft' => true,
                'high_volatility' => true,
                'host_tight' => true,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function getCached(): array
    {
        $now = \microtime(true);
        if (self::$cache !== null && ($now - self::$cacheAt) < self::CACHE_TTL_SEC) {
            return self::$cache;
        }

        try {
            self::$cache = $this->get();
        } catch (\Throwable) {
            self::$cache = self::defaults();
        }
        self::$cacheAt = $now;

        return self::$cache;
    }

    public static function invalidateCache(): void
    {
        self::$cache = null;
        self::$cacheAt = 0.0;
    }

    /**
     * @return array<string, mixed>
     */
    public function get(): array
    {
        $defaults = self::defaults();
        $store = $this->resolveStore();
        if ($store === null) {
            return $defaults;
        }

        $merged = $defaults;
        foreach ($this->flatKeys($defaults) as $key => $_ignored) {
            try {
                $raw = $store->getConfig(self::KEY_PREFIX . $key, self::MODULE);
            } catch (\Throwable) {
                continue;
            }
            if ($raw === null || $raw === '') {
                continue;
            }
            $merged = $this->applyFlatKey($merged, $key, (string)$raw);
        }

        return $this->normalize($merged);
    }

    /**
     * @param array<string, mixed> $input
     * @return array{ok:bool, config:array<string, mixed>, message:string}
     */
    public function save(array $input): array
    {
        $normalized = $this->normalize(\array_replace_recursive(self::defaults(), $input));
        $store = $this->resolveStore();
        if ($store === null) {
            return [
                'ok' => false,
                'config' => $normalized,
                'message' => (string)__('BackendConfigStore 不可用，无法保存容量顾问配置'),
            ];
        }

        foreach ($this->flatKeys($normalized) as $key => $value) {
            $store->setConfig(self::KEY_PREFIX . $key, $this->encodeValue($value), self::MODULE);
        }
        self::invalidateCache();

        return [
            'ok' => true,
            'config' => $normalized,
            'message' => (string)__('容量顾问配置已保存'),
        ];
    }

    private function resolveStore(): ?BackendConfigStore
    {
        if ($this->store instanceof BackendConfigStore) {
            return $this->store;
        }
        try {
            if (\class_exists(\Weline\Framework\Manager\ObjectManager::class, false)
                || \class_exists(\Weline\Framework\Manager\ObjectManager::class)
            ) {
                $store = \Weline\Framework\Manager\ObjectManager::getInstance(BackendConfigStore::class);
                if ($store instanceof BackendConfigStore) {
                    return $store;
                }
            }
        } catch (\Throwable) {
        }

        return null;
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, scalar>
     */
    private function flatKeys(array $config): array
    {
        $out = [
            'enabled' => (bool)($config['enabled'] ?? true),
            'interval_sec' => (int)($config['interval_sec'] ?? 60),
            'window_sec' => (int)($config['window_sec'] ?? 600),
            'ring_points_max' => (int)($config['ring_points_max'] ?? 64),
            'report_stale_sec' => (int)($config['report_stale_sec'] ?? 90),
            'soft_pressure' => (float)($config['soft_pressure'] ?? 0.70),
            'frequent_compact_count' => (int)($config['frequent_compact_count'] ?? 8),
            'sustained_soft_sample_ratio' => (float)($config['sustained_soft_sample_ratio'] ?? 0.50),
            'volatility_cv' => (float)($config['volatility_cv'] ?? 0.35),
            'host_available_ratio_min' => (float)($config['host_available_ratio_min'] ?? 0.15),
            'host_pressure_max' => (float)($config['host_pressure_max'] ?? 0.85),
            'notify_enabled' => (bool)($config['notify_enabled'] ?? true),
            'sample_retain_days' => (int)($config['sample_retain_days'] ?? 14),
        ];
        $rules = \is_array($config['rules'] ?? null) ? $config['rules'] : [];
        foreach (['frequent_compact', 'sustained_soft', 'high_volatility', 'host_tight'] as $rule) {
            $out['rules.' . $rule] = (bool)($rules[$rule] ?? true);
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private function applyFlatKey(array $config, string $key, string $raw): array
    {
        if (\str_starts_with($key, 'rules.')) {
            $rule = \substr($key, 6);
            if (!\is_array($config['rules'] ?? null)) {
                $config['rules'] = [];
            }
            $config['rules'][$rule] = $this->decodeBool($raw);
            return $config;
        }

        $config[$key] = match ($key) {
            'enabled', 'notify_enabled' => $this->decodeBool($raw),
            'interval_sec', 'window_sec', 'ring_points_max', 'report_stale_sec',
            'frequent_compact_count', 'sample_retain_days' => (int)$raw,
            default => (float)$raw,
        };

        return $config;
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private function normalize(array $config): array
    {
        $d = self::defaults();
        $rulesIn = \is_array($config['rules'] ?? null) ? $config['rules'] : [];
        $rules = $d['rules'];
        foreach (\array_keys($rules) as $rule) {
            $rules[$rule] = (bool)($rulesIn[$rule] ?? $rules[$rule]);
        }

        return [
            'enabled' => (bool)($config['enabled'] ?? $d['enabled']),
            'interval_sec' => \max(60, (int)($config['interval_sec'] ?? $d['interval_sec'])),
            'window_sec' => \max(60, (int)($config['window_sec'] ?? $d['window_sec'])),
            'ring_points_max' => \max(8, \min(256, (int)($config['ring_points_max'] ?? $d['ring_points_max']))),
            'report_stale_sec' => \max(15, (int)($config['report_stale_sec'] ?? $d['report_stale_sec'])),
            'soft_pressure' => \max(0.10, \min(0.99, (float)($config['soft_pressure'] ?? $d['soft_pressure']))),
            'frequent_compact_count' => \max(1, (int)($config['frequent_compact_count'] ?? $d['frequent_compact_count'])),
            'sustained_soft_sample_ratio' => \max(0.05, \min(1.0, (float)($config['sustained_soft_sample_ratio'] ?? $d['sustained_soft_sample_ratio']))),
            'volatility_cv' => \max(0.05, \min(5.0, (float)($config['volatility_cv'] ?? $d['volatility_cv']))),
            'host_available_ratio_min' => \max(0.01, \min(0.95, (float)($config['host_available_ratio_min'] ?? $d['host_available_ratio_min']))),
            'host_pressure_max' => \max(0.10, \min(0.99, (float)($config['host_pressure_max'] ?? $d['host_pressure_max']))),
            'notify_enabled' => (bool)($config['notify_enabled'] ?? $d['notify_enabled']),
            'sample_retain_days' => \max(1, \min(365, (int)($config['sample_retain_days'] ?? $d['sample_retain_days']))),
            'rules' => $rules,
        ];
    }

    private function encodeValue(mixed $value): string
    {
        if (\is_bool($value)) {
            return $value ? '1' : '0';
        }

        return (string)$value;
    }

    private function decodeBool(string $raw): bool
    {
        $v = \strtolower(\trim($raw));

        return !\in_array($v, ['0', 'false', 'off', 'no', ''], true);
    }
}
