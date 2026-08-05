<?php

declare(strict_types=1);

namespace Weline\Framework\Http;

use Weline\Framework\App\Env;
use Weline\Framework\Cache\AdapterFactory;
use Weline\Framework\Cache\Contract\AtomicCacheAdapterInterface;
use Weline\Framework\Cache\Contract\CacheAdapterHealthInterface;
use Weline\Framework\Cache\Contract\CacheAdapterInterface;
use Weline\Framework\Runtime\SchedulerSystem;

/**
 * Shared, atomic fixed-window state and denied metrics for Scope rate limits.
 */
final class ScopeRateLimitStateStore
{
    private const MAX_CAS_ATTEMPTS = 16;
    private const MAX_WINDOW_SECONDS = 86400;
    private const MAX_METRIC_KEYS = 256;
    private const METRICS_KEY = 'metrics.v1';
    private const METRICS_TTL_SECONDS = 604800;

    private readonly AtomicCacheAdapterInterface $adapter;
    private readonly CacheAdapterHealthInterface $health;
    private readonly string $driverName;

    public function __construct(
        ?CacheAdapterInterface $adapter = null,
        ?AdapterFactory $adapterFactory = null,
        ?string $driverName = null,
    ) {
        $driverName = \strtolower(\trim($driverName ?? (string)Env::get(
            'security.scope_rate_limit.driver',
            'wls_memory',
        )));
        if ($driverName === '') {
            throw new \RuntimeException('scope_rate_limit_driver_required');
        }

        $adapter ??= ($adapterFactory ?? new AdapterFactory())->create(
            $driverName,
            'scope_rate_limit',
        );
        if (!$adapter instanceof AtomicCacheAdapterInterface) {
            throw new \RuntimeException('scope_rate_limit_non_atomic_adapter');
        }
        if (!$adapter instanceof CacheAdapterHealthInterface) {
            throw new \RuntimeException('scope_rate_limit_health_check_required');
        }

        $this->adapter = $adapter;
        $this->health = $adapter;
        $this->driverName = $driverName;
    }

    public function consume(string $logicalKey, int $limit, int $windowSeconds, int $now): bool
    {
        if ($limit <= 0 || $limit > 10000000) {
            throw new \InvalidArgumentException('scope_rate_limit_invalid');
        }
        if ($windowSeconds < 1 || $windowSeconds > self::MAX_WINDOW_SECONDS) {
            throw new \InvalidArgumentException('scope_rate_limit_window_invalid');
        }

        $key = $this->windowKey($logicalKey);
        for ($attempt = 1; $attempt <= self::MAX_CAS_ATTEMPTS; $attempt++) {
            $existing = $this->read($key);
            $row = $this->normalizeWindow($existing);
            if ($row === null || $row['reset_at'] <= $now) {
                $next = [
                    'count' => 1,
                    'reset_at' => $now + $windowSeconds,
                    'limit' => $limit,
                ];
            } elseif ($row['count'] >= $limit) {
                return false;
            } else {
                $next = [
                    'count' => $row['count'] + 1,
                    'reset_at' => $row['reset_at'],
                    'limit' => $limit,
                ];
            }

            $ttl = \max(1, $next['reset_at'] - $now + 1);
            if ($this->adapter->compareAndSet($key, $existing, $next, $ttl)) {
                return true;
            }
            $this->assertHealthy();
            $this->backoff($attempt);
        }

        throw new \RuntimeException('scope_rate_limit_unavailable');
    }

    /**
     * @param list<string> $metricKeys
     */
    public function bumpDenied(array $metricKeys): void
    {
        $metricKeys = \array_values(\array_unique($metricKeys));
        for ($attempt = 1; $attempt <= self::MAX_CAS_ATTEMPTS; $attempt++) {
            $existing = $this->read(self::METRICS_KEY);
            $metrics = $this->normalizeMetrics($existing);
            foreach ($metricKeys as $metricKey) {
                if ($metricKey === '' || \strlen($metricKey) > 512) {
                    throw new \InvalidArgumentException('scope_rate_limit_metric_invalid');
                }
                if (!\array_key_exists($metricKey, $metrics)
                    && \count($metrics) >= self::MAX_METRIC_KEYS
                ) {
                    throw new \RuntimeException('scope_rate_limit_metric_capacity');
                }
                $current = $metrics[$metricKey] ?? 0;
                $metrics[$metricKey] = $current >= PHP_INT_MAX
                    ? PHP_INT_MAX
                    : $current + 1;
            }
            \ksort($metrics);

            if ($this->adapter->compareAndSet(
                self::METRICS_KEY,
                $existing,
                $metrics,
                self::METRICS_TTL_SECONDS,
            )) {
                return;
            }
            $this->assertHealthy();
            $this->backoff($attempt);
        }

        throw new \RuntimeException('scope_rate_limit_unavailable');
    }

    /**
     * A no-op CAS validates that the returned snapshot is still current. This
     * is required for adapters that keep a Worker-local read cache.
     *
     * @return array<string, int>
     */
    public function metrics(): array
    {
        for ($attempt = 1; $attempt <= self::MAX_CAS_ATTEMPTS; $attempt++) {
            $existing = $this->read(self::METRICS_KEY);
            $metrics = $this->normalizeMetrics($existing);
            if ($this->adapter->compareAndSet(
                self::METRICS_KEY,
                $existing,
                $metrics,
                self::METRICS_TTL_SECONDS,
            )) {
                return $metrics;
            }
            $this->assertHealthy();
            $this->backoff($attempt);
        }

        throw new \RuntimeException('scope_rate_limit_unavailable');
    }

    public function clearKey(string $logicalKey): void
    {
        $key = $this->windowKey($logicalKey);
        for ($attempt = 1; $attempt <= self::MAX_CAS_ATTEMPTS; $attempt++) {
            $existing = $this->read($key);
            if ($existing === null) {
                return;
            }
            $this->normalizeWindow($existing);
            if ($this->adapter->compareAndSet($key, $existing, null)) {
                return;
            }
            $this->assertHealthy();
            $this->backoff($attempt);
        }

        throw new \RuntimeException('scope_rate_limit_unavailable');
    }

    public function clearMetricsForBucket(string $bucket): void
    {
        if ($bucket === '' || \strlen($bucket) > 128) {
            throw new \InvalidArgumentException('scope_rate_limit_metric_invalid');
        }

        for ($attempt = 1; $attempt <= self::MAX_CAS_ATTEMPTS; $attempt++) {
            $existing = $this->read(self::METRICS_KEY);
            if ($existing === null) {
                return;
            }
            $metrics = $this->normalizeMetrics($existing);
            foreach (\array_keys($metrics) as $metricKey) {
                if ($metricKey === $bucket || \str_starts_with($metricKey, $bucket . '@')) {
                    unset($metrics[$metricKey]);
                }
            }
            if ($this->adapter->compareAndSet(
                self::METRICS_KEY,
                $existing,
                $metrics === [] ? null : $metrics,
                $metrics === [] ? 0 : self::METRICS_TTL_SECONDS,
            )) {
                return;
            }
            $this->assertHealthy();
            $this->backoff($attempt);
        }

        throw new \RuntimeException('scope_rate_limit_unavailable');
    }

    public function clear(): void
    {
        if ($this->adapter->clear()) {
            return;
        }
        $this->assertHealthy();
        throw new \RuntimeException('scope_rate_limit_unavailable');
    }

    public function driver(): string
    {
        return $this->driverName;
    }

    private function read(string $key): mixed
    {
        $value = $this->adapter->get($key);
        $this->assertHealthy();

        return $value;
    }

    private function assertHealthy(): void
    {
        if (!$this->health->isAvailable()) {
            throw new \RuntimeException('scope_rate_limit_unavailable');
        }
    }

    /**
     * @return array{count:int,reset_at:int,limit:int}|null
     */
    private function normalizeWindow(mixed $value): ?array
    {
        if ($value === null) {
            return null;
        }
        if (!\is_array($value)
            || !isset($value['count'], $value['reset_at'], $value['limit'])
            || !\is_int($value['count'])
            || !\is_int($value['reset_at'])
            || !\is_int($value['limit'])
            || $value['count'] < 1
            || $value['reset_at'] < 1
            || $value['limit'] < 1
        ) {
            throw new \RuntimeException('scope_rate_limit_state_invalid');
        }

        return [
            'count' => $value['count'],
            'reset_at' => $value['reset_at'],
            'limit' => $value['limit'],
        ];
    }

    /**
     * @return array<string, int>
     */
    private function normalizeMetrics(mixed $value): array
    {
        if ($value === null) {
            return [];
        }
        if (!\is_array($value) || \count($value) > self::MAX_METRIC_KEYS) {
            throw new \RuntimeException('scope_rate_limit_metrics_invalid');
        }

        $metrics = [];
        foreach ($value as $key => $count) {
            if (!\is_string($key)
                || $key === ''
                || \strlen($key) > 512
                || !\is_int($count)
                || $count < 0
            ) {
                throw new \RuntimeException('scope_rate_limit_metrics_invalid');
            }
            $metrics[$key] = $count;
        }

        return $metrics;
    }

    private function windowKey(string $logicalKey): string
    {
        return 'window.v1.' . \hash('sha256', $logicalKey);
    }

    private function backoff(int $attempt): void
    {
        SchedulerSystem::usleep(\min(10000, 500 * $attempt));
    }
}
