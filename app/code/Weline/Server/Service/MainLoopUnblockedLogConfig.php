<?php
declare(strict_types=1);

namespace Weline\Server\Service;

final class MainLoopUnblockedLogConfig
{
    public const DEFAULT_LOG_EVERY = 10000;
    public const DEFAULT_LOG_INTERVAL_SEC = 10.0;

    /**
     * @param array<string, mixed> $wlsConfig
     * @param list<string> $scopes
     */
    public static function resolve(array $wlsConfig, array $scopes = [], int $default = self::DEFAULT_LOG_EVERY): int
    {
        $resolved = $default;

        $loopConfig = $wlsConfig['loop'] ?? null;
        if (\is_array($loopConfig) && \array_key_exists('main_loop_unblocked_log_every', $loopConfig)) {
            $resolved = self::normalize($loopConfig['main_loop_unblocked_log_every'], $resolved);
        }

        foreach ($scopes as $scope) {
            $scopeConfig = $wlsConfig[$scope] ?? null;
            if (!\is_array($scopeConfig) || !\array_key_exists('main_loop_unblocked_log_every', $scopeConfig)) {
                continue;
            }
            $resolved = self::normalize($scopeConfig['main_loop_unblocked_log_every'], $resolved);
        }

        return $resolved;
    }

    /**
     * @param array<string, mixed> $wlsConfig
     * @param list<string> $scopes
     */
    public static function resolveInterval(array $wlsConfig, array $scopes = [], float $default = self::DEFAULT_LOG_INTERVAL_SEC): float
    {
        $resolved = $default;

        $loopConfig = $wlsConfig['loop'] ?? null;
        if (\is_array($loopConfig) && \array_key_exists('main_loop_unblocked_log_interval_sec', $loopConfig)) {
            $resolved = self::normalizeFloat($loopConfig['main_loop_unblocked_log_interval_sec'], $resolved);
        }

        foreach ($scopes as $scope) {
            $scopeConfig = $wlsConfig[$scope] ?? null;
            if (!\is_array($scopeConfig) || !\array_key_exists('main_loop_unblocked_log_interval_sec', $scopeConfig)) {
                continue;
            }
            $resolved = self::normalizeFloat($scopeConfig['main_loop_unblocked_log_interval_sec'], $resolved);
        }

        return $resolved;
    }

    public static function shouldEmit(int $loopCount, int $logEvery): bool
    {
        return $logEvery > 0 && $loopCount > 0 && ($loopCount % $logEvery) === 0;
    }

    public static function shouldEmitByInterval(float $now, float $lastEmitAt, float $intervalSec): bool
    {
        return $intervalSec > 0.0 && ($lastEmitAt <= 0.0 || ($now - $lastEmitAt) >= $intervalSec);
    }

    private static function normalize(mixed $value, int $fallback): int
    {
        if ($value === null || $value === '') {
            return $fallback;
        }
        if (!\is_int($value) && !\is_float($value) && !\is_numeric($value)) {
            return $fallback;
        }

        return \max(0, (int) $value);
    }

    private static function normalizeFloat(mixed $value, float $fallback): float
    {
        if ($value === null || $value === '') {
            return $fallback;
        }
        if (!\is_int($value) && !\is_float($value) && !\is_numeric($value)) {
            return $fallback;
        }

        return \max(0.0, (float) $value);
    }
}
