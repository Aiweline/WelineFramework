<?php
declare(strict_types=1);

namespace Weline\Server\Service;

final class WorkerResponseMemoryGuard
{
    public const LARGE_RESPONSE_BYTES = 262144;
    public const LARGE_BUFFER_BYTES = 524288;
    private const RUNTIME_CACHE_PRESSURE_THRESHOLD = 0.70;
    private const RUNTIME_CACHE_HARD_PRESSURE_THRESHOLD = 0.85;
    private static ?array $runtimeCacheThresholds = null;

    /** SSE 等长连接写队列上限：客户端读慢时防止无限积压导致 Worker OOM */
    public const SSE_MAX_PENDING_WRITE_BYTES = 8388608;

    public static function shouldForceConnectionClose(
        bool $keepAlive,
        bool $isLongLivedProtocol,
        int $responseBytes,
        int $bufferedBytes = 0
    ): bool {
        if (!$keepAlive || $isLongLivedProtocol) {
            return false;
        }

        if ($responseBytes >= self::LARGE_RESPONSE_BYTES) {
            return true;
        }

        if ($bufferedBytes >= self::LARGE_BUFFER_BYTES) {
            return true;
        }

        return ($responseBytes + $bufferedBytes) >= self::LARGE_BUFFER_BYTES;
    }

    public static function forceConnectionCloseHeader(string $httpResponse): string
    {
        $headerEnd = \strpos($httpResponse, "\r\n\r\n");
        if ($headerEnd === false) {
            return $httpResponse;
        }

        $headers = \substr($httpResponse, 0, $headerEnd);
        $body = \substr($httpResponse, $headerEnd);

        if (\preg_match('/\r\nConnection:\s*[^\r\n]*/i', $headers) === 1) {
            $headers = (string) \preg_replace(
                '/\r\nConnection:\s*[^\r\n]*/i',
                "\r\nConnection: close",
                $headers,
                1
            );
        } else {
            $headers .= "\r\nConnection: close";
        }

        return $headers . $body;
    }

    public static function shouldCompactAfterDrain(int $releasedBytes): bool
    {
        return $releasedBytes >= self::LARGE_RESPONSE_BYTES;
    }

    public static function sseWriteBufferWouldExceed(int $currentBufferedBytes, int $appendBytes): bool
    {
        if ($appendBytes <= 0) {
            return false;
        }

        return ($currentBufferedBytes + $appendBytes) > self::SSE_MAX_PENDING_WRITE_BYTES;
    }

    /**
     * @return array{
     *     cycles:int,
     *     trimmed_bytes:int,
     *     runtime_cache_compactions:array{
     *         memory_store_clears:int,
     *         metadata_entries_cleared:int,
     *         cleared_process_caches:int
     *     }
     * }
     */
    public static function compact(): array
    {
        $cycles = \gc_collect_cycles();
        $trimmedBytes = 0;

        if (\function_exists('gc_mem_caches')) {
            $trimmedBytes = \max(0, (int) \gc_mem_caches());
        }

        $runtimeCacheCompactions = [
            'memory_store_clears' => 0,
            'metadata_entries_cleared' => 0,
            'cleared_process_caches' => 0,
        ];

        $pressure = self::getMemoryPressure();
        $thresholds = self::getRuntimeCacheThresholds();
        if ($pressure >= $thresholds['soft']) {
            $runtimeCacheCompactions = self::compactRuntimeCaches(
                $pressure >= $thresholds['hard']
            );
        }

        return [
            'cycles' => $cycles,
            'trimmed_bytes' => $trimmedBytes,
            'runtime_cache_compactions' => $runtimeCacheCompactions,
        ];
    }

    /**
     * 清理长生命周期 Worker 中可安全重建的热点缓存。
     *
     * `aggressive=true` 时会额外清理部分进程级注册表/路由热点缓存，
     * 优先避免高压下继续膨胀导致 OOM。
     *
     * @return array{memory_store_clears:int, metadata_entries_cleared:int, cleared_process_caches:int}
     */
    public static function compactRuntimeCaches(bool $aggressive = false): array
    {
        $compactions = [
            'memory_store_clears' => 0,
            'metadata_entries_cleared' => 0,
            'cleared_process_caches' => 0,
        ];

        if (\class_exists(\Weline\Framework\Manager\ObjectManager::class, false)) {
            $objectManagerCompaction = \Weline\Framework\Manager\ObjectManager::relieveMemoryPressure($aggressive);
            $compactions['memory_store_clears'] = (int) ($objectManagerCompaction['memory_store_clears'] ?? 0);
            $compactions['metadata_entries_cleared'] = (int) ($objectManagerCompaction['metadata_entries_cleared'] ?? 0);
        }

        if (\class_exists(\Weline\Framework\View\TemplateCacheManager::class, false)) {
            \Weline\Framework\View\TemplateCacheManager::getInstance()->clearMemoryCache();
            $compactions['cleared_process_caches']++;
        }

        if (\class_exists(\Weline\Widget\Service\WidgetData::class, false)) {
            \Weline\Widget\Service\WidgetData::clearCache();
            $compactions['cleared_process_caches']++;
        }

        if (\class_exists(\Weline\Theme\Block\Partials::class, false)) {
            \Weline\Theme\Block\Partials::clearMetaCache();
            $compactions['cleared_process_caches']++;
        }

        if ($aggressive && \class_exists(\Weline\Framework\Event\EventData::class, false)) {
            \Weline\Framework\Event\EventData::clearCache();
            $compactions['cleared_process_caches']++;
        }

        if ($aggressive && \class_exists(\Weline\Framework\Extends\ExtendsData::class, false)) {
            \Weline\Framework\Extends\ExtendsData::clearCache();
            $compactions['cleared_process_caches']++;
        }

        if ($aggressive && \class_exists(\Weline\Framework\Router\Core::class, false)) {
            \Weline\Framework\Router\Core::resetGeneratedRouterFileCache();
            $compactions['cleared_process_caches']++;
        }

        if ($aggressive && \class_exists(\Weline\Server\Service\MemoryCacheService::class, false)) {
            \Weline\Server\Service\MemoryCacheService::purgeAll();
            \Weline\Server\Service\MemoryCacheService::resetStats();
            $compactions['cleared_process_caches']++;
        }

        if ($aggressive && \class_exists(\Weline\Framework\Support\Php84::class, false)) {
            \Weline\Framework\Support\Php84::clearCache();
            $compactions['cleared_process_caches']++;
        }

        if ($aggressive && \class_exists(\Weline\Framework\System\Process\Processer::class, false)) {
            \Weline\Framework\System\Process\Processer::clearTrustedPidCache();
            \Weline\Framework\System\Process\Processer::clearPortCache();
            \Weline\Framework\System\Process\Processer::clearLogEnabledCache();
            $compactions['cleared_process_caches']++;
        }

        return $compactions;
    }

    private static function getMemoryPressure(): float
    {
        $limitBytes = self::getMemoryLimitBytes();
        if ($limitBytes <= 0) {
            return 0.0;
        }

        return \memory_get_usage(true) / $limitBytes;
    }

    private static function getMemoryLimitBytes(): int
    {
        $limit = \ini_get('memory_limit');
        if ($limit === false) {
            return 0;
        }

        return self::parseMemoryLimit((string) $limit);
    }

    /**
     * @return array{soft:float, hard:float}
     */
    private static function getRuntimeCacheThresholds(): array
    {
        if (self::$runtimeCacheThresholds !== null) {
            return self::$runtimeCacheThresholds;
        }

        $soft = self::RUNTIME_CACHE_PRESSURE_THRESHOLD;
        $hard = self::RUNTIME_CACHE_HARD_PRESSURE_THRESHOLD;

        try {
            if (\defined('BP') && \class_exists(\Weline\Framework\App\Env::class, false)) {
                $config = \Weline\Framework\App\Env::get('wls.memory_guard', []);
                if (\is_array($config)) {
                    $soft = self::normalizeThreshold(
                        $config['runtime_cache_pressure_threshold'] ?? $soft,
                        $soft
                    );
                    $hard = \max(
                        $soft,
                        self::normalizeThreshold(
                            $config['runtime_cache_hard_pressure_threshold'] ?? $hard,
                            $hard
                        )
                    );
                }
            }
        } catch (\Throwable) {
            // 框架尚未完整引导时回退默认阈值
        }

        self::$runtimeCacheThresholds = [
            'soft' => $soft,
            'hard' => $hard,
        ];

        return self::$runtimeCacheThresholds;
    }

    private static function normalizeThreshold(mixed $value, float $default): float
    {
        if (!\is_numeric($value)) {
            return $default;
        }

        $threshold = (float) $value;
        if ($threshold <= 0.0) {
            return $default;
        }
        if ($threshold >= 1.0) {
            return 1.0;
        }

        return $threshold;
    }

    public static function resetThresholdCache(): void
    {
        self::$runtimeCacheThresholds = null;
    }

    private static function parseMemoryLimit(string $limit): int
    {
        $limit = \trim($limit);
        if ($limit === '' || $limit === '-1' || $limit === '0') {
            return 0;
        }

        $unit = \strtolower($limit[\strlen($limit) - 1]);
        $value = (int) $limit;
        switch ($unit) {
            case 'g':
                $value *= 1024;
                // no break
            case 'm':
                $value *= 1024;
                // no break
            case 'k':
                $value *= 1024;
                break;
        }

        return $value;
    }
}
