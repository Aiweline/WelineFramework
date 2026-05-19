<?php
declare(strict_types=1);

namespace Weline\Server\Service;

use Weline\Framework\App\Env;
use Weline\Server\Log\WlsLogger;

final class WorkerMemoryProbe
{
    private static float $lastLogAt = 0.0;

    /**
     * @param array<string, mixed> $meta
     * @return array<string, mixed>
     */
    public static function begin(array $meta = []): array
    {
        if (!self::isEnabled()) {
            return ['enabled' => false];
        }

        return [
            'enabled' => true,
            'started_at' => \microtime(true),
            'usage_start' => \memory_get_usage(false),
            'real_start' => \memory_get_usage(true),
            'peak_start' => \memory_get_peak_usage(true),
            'meta' => $meta,
        ];
    }

    /**
     * @param array<string, mixed>|null $state
     * @param array<string, mixed> $meta
     * @return array<string, mixed>
     */
    public static function finish(?array $state, array $meta = []): array
    {
        if (empty($state['enabled'])) {
            return [];
        }

        $usageBefore = \memory_get_usage(false);
        $realBefore = \memory_get_usage(true);
        $peakBefore = \memory_get_peak_usage(true);
        $usageStart = (int)($state['usage_start'] ?? $usageBefore);
        $realStart = (int)($state['real_start'] ?? $realBefore);

        $config = self::config();
        $softBytes = self::mbToBytes((float)($config['compact_soft_mb'] ?? 128));
        $hardBytes = self::mbToBytes((float)($config['compact_hard_mb'] ?? 192));
        $deltaBytes = self::mbToBytes((float)($config['delta_log_mb'] ?? 12));
        $highBytes = self::mbToBytes((float)($config['high_log_mb'] ?? 1));

        $shouldCompact = $realBefore >= $softBytes || ($realBefore - $realStart) >= $deltaBytes;
        $compaction = null;
        if ($shouldCompact && \class_exists(WorkerResponseMemoryGuard::class)) {
            $cycles = \gc_collect_cycles();
            $trimmedBytes = \function_exists('gc_mem_caches') ? \max(0, (int)\gc_mem_caches()) : 0;
            $runtimeCompaction = WorkerResponseMemoryGuard::compactRuntimeCaches($realBefore >= $hardBytes);
            $compaction = [
                'cycles' => $cycles,
                'trimmed_bytes' => $trimmedBytes,
                'runtime_cache_compactions' => $runtimeCompaction,
            ];
        }

        $usageAfter = \memory_get_usage(false);
        $realAfter = \memory_get_usage(true);
        $peakAfter = \memory_get_peak_usage(true);
        $runtimeCaches = \class_exists(WorkerResponseMemoryGuard::class)
            ? WorkerResponseMemoryGuard::describeRuntimeCaches()
            : [];
        $startMeta = \is_array($state['meta'] ?? null) ? $state['meta'] : [];

        $payload = [
            'pid' => \function_exists('getmypid') ? (int)\getmypid() : 0,
            'instance' => (string)($meta['instance'] ?? ($startMeta['instance'] ?? '')),
            'worker_id' => (string)($meta['worker_id'] ?? ($startMeta['worker_id'] ?? '')),
            'worker_port' => (string)($meta['worker_port'] ?? ($startMeta['worker_port'] ?? '')),
            'request_count' => (int)($meta['request_count'] ?? ($startMeta['request_count'] ?? 0)),
            'method' => (string)($meta['method'] ?? ($startMeta['method'] ?? '')),
            'uri' => self::limitString((string)($meta['uri'] ?? ($startMeta['uri'] ?? '')), 220),
            'duration_ms' => isset($meta['total_ms']) ? (float)$meta['total_ms'] : \round((\microtime(true) - (float)$state['started_at']) * 1000, 2),
            'response_bytes' => (int)($meta['response_bytes'] ?? 0),
            'usage_start_mb' => self::bytesToMb($usageStart),
            'usage_before_gc_mb' => self::bytesToMb($usageBefore),
            'usage_after_gc_mb' => self::bytesToMb($usageAfter),
            'real_start_mb' => self::bytesToMb($realStart),
            'real_before_gc_mb' => self::bytesToMb($realBefore),
            'real_after_gc_mb' => self::bytesToMb($realAfter),
            'peak_mb' => self::bytesToMb(\max($peakBefore, $peakAfter)),
            'real_delta_mb' => self::bytesToMb($realBefore - $realStart),
            'memory_limit' => (string)\ini_get('memory_limit'),
            'compaction' => $compaction,
            'runtime_caches' => $runtimeCaches,
        ];

        if (self::shouldLog($payload, $highBytes, $deltaBytes)) {
            self::log($payload);
        }

        return $payload;
    }

    private static function isEnabled(): bool
    {
        $value = self::config()['enabled'] ?? null;
        if ($value === null) {
            return true;
        }

        return self::boolValue($value, true);
    }

    /**
     * @return array<string, mixed>
     */
    private static function config(): array
    {
        try {
            $config = Env::get('wls.memory_probe', []);
            return \is_array($config) ? $config : [];
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function shouldLog(array $payload, int $highBytes, int $deltaBytes): bool
    {
        $realBefore = (float)($payload['real_before_gc_mb'] ?? 0.0) * 1024 * 1024;
        $realDelta = \abs((float)($payload['real_delta_mb'] ?? 0.0) * 1024 * 1024);
        if ($realDelta >= $deltaBytes || $realBefore >= $highBytes) {
            return self::passesLogInterval();
        }

        $sampleEvery = (int)(self::config()['sample_every'] ?? 0);
        $requestCount = (int)($payload['request_count'] ?? 0);
        return $sampleEvery > 0 && $requestCount > 0 && ($requestCount % $sampleEvery) === 0 && self::passesLogInterval();
    }

    private static function passesLogInterval(): bool
    {
        $interval = (float)(self::config()['min_log_interval_sec'] ?? 5.0);
        $now = \microtime(true);
        if ($interval <= 0.0 || ($now - self::$lastLogAt) >= $interval) {
            self::$lastLogAt = $now;
            return true;
        }

        return false;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function log(array $payload): void
    {
        $json = \json_encode($payload, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_INVALID_UTF8_SUBSTITUTE);
        if (!\is_string($json)) {
            $json = '{}';
        }
        self::writeProbeFile($json);

        if (\class_exists(WlsLogger::class, false)) {
            WlsLogger::warning_('[WLSMemoryProbe] ' . $json);
            return;
        }

        if (\function_exists('w_log_warning')) {
            \w_log_warning('[WLSMemoryProbe] ' . $json);
        }
    }

    private static function writeProbeFile(string $json): void
    {
        if (!\defined('BP')) {
            return;
        }

        $dir = BP . 'var' . \DIRECTORY_SEPARATOR . 'log' . \DIRECTORY_SEPARATOR . 'wls';
        if (!\is_dir($dir)) {
            @\mkdir($dir, 0755, true);
        }

        $line = '[' . \date('Y-m-d H:i:s') . '] [WLSMemoryProbe] ' . $json . \PHP_EOL;
        @\file_put_contents($dir . \DIRECTORY_SEPARATOR . 'memory_probe.log', $line, \FILE_APPEND);
    }

    private static function mbToBytes(float $mb): int
    {
        return \max(0, (int)\round($mb * 1024 * 1024));
    }

    private static function bytesToMb(int|float $bytes): float
    {
        return \round(((float)$bytes) / 1024 / 1024, 2);
    }

    private static function boolValue(mixed $value, bool $default): bool
    {
        if (\is_bool($value)) {
            return $value;
        }
        if (\is_int($value) || \is_float($value)) {
            return ((int)$value) !== 0;
        }
        if (\is_string($value)) {
            $normalized = \strtolower(\trim($value));
            if (\in_array($normalized, ['1', 'true', 'yes', 'on', 'enable', 'enabled'], true)) {
                return true;
            }
            if (\in_array($normalized, ['0', 'false', 'no', 'off', 'disable', 'disabled'], true)) {
                return false;
            }
        }

        return $default;
    }

    private static function limitString(string $value, int $max): string
    {
        if (\strlen($value) <= $max) {
            return $value;
        }

        return \substr($value, 0, $max);
    }
}
