<?php
declare(strict_types=1);

namespace Weline\Server\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\ModuleProcessCacheResetterRegistry;
use Weline\Framework\Runtime\ProcessCacheResetContext;
use Weline\Framework\Runtime\WlsConcurrency;

final class WorkerResponseMemoryGuard
{
    public const LARGE_RESPONSE_BYTES = 262144;
    public const LARGE_BUFFER_BYTES = 524288;
    /** Soft water mark: reclaim rebuildable process L1 (keep-warm ratchet control). */
    private const RUNTIME_CACHE_PRESSURE_THRESHOLD = 0.55;
    /** Hard water mark: aggressive process-bag clear + allocator freelist trim (no Worker drain). */
    private const RUNTIME_CACHE_HARD_PRESSURE_THRESHOLD = 0.70;
    /** 绝对量触发 allocator 整理；未知上限时仍保留原 soft 回收。 */
    private const SOFT_HEAP_FORCE_BYTES = 48 * 1024 * 1024;
    /** 未知内存上限时，保留绝对量触发的 aggressive 回收。 */
    private const LARGE_HEAP_FORCE_AGGRESSIVE_BYTES = 64 * 1024 * 1024;
    /** 回收后 shell 达此量是 ratchet 候选；有限预算下还须达到 soft 压力。 */
    private const ZEND_SHELL_DRAIN_BYTES = 48 * 1024 * 1024;
    /** real may exceed captured warm baseline by this much before drain. */
    private const ZEND_REAL_OVER_BASELINE_DRAIN_BYTES = 32 * 1024 * 1024;
    /** Capture warm baseline only when shell is small enough to look like framework floor. */
    private const ZEND_WARM_BASELINE_MAX_SHELL_BYTES = 24 * 1024 * 1024;
    private const ZEND_WARM_BASELINE_MAX_USED_BYTES = 96 * 1024 * 1024;

    private static ?array $runtimeCacheThresholds = null;
    /** Pressure denominator from Worker `--memory-limit` (ignore DevTool-inflated ini). */
    private static ?int $pressureLimitBytes = null;
    /** Lowest clean Zend-real seen after reclaim (framework warm floor). */
    private static ?int $warmRealBaselineBytes = null;
    private static ?string $drainAfterResponseReason = null;
    private static int $incompleteRequestFiberCancelStreak = 0;

    /** Consecutive incomplete Fiber cancels before Worker quarantine. */
    public const INCOMPLETE_REQUEST_FIBER_CANCEL_QUARANTINE_STREAK = 3;

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

    public static function responseRequestsConnectionClose(string $httpResponse): bool
    {
        $headerEnd = \strpos($httpResponse, "\r\n\r\n");
        if ($headerEnd === false) {
            return false;
        }

        $headers = \substr($httpResponse, 0, $headerEnd);
        if (\preg_match('/(?:^|\r\n)Connection:\s*close(?:\r\n|$)/i', $headers) === 1) {
            return true;
        }

        return false;
    }

    /**
     * An event-backed listener shared by multiple processes can deliver one
     * wake-up for a whole connection burst. Accepting only one socket from
     * that wake-up leaves the kernel queue exposed while the Worker handles
     * the accepted request. This is especially visible on macOS, where the
     * effective listen backlog is commonly 128 and an HTTP/2 edge connection
     * can fan out hundreds of loopback upstream connects at once.
     *
     * Drain a bounded batch for every event-backed shared listener. Retain
     * one-at-a-time fairness only for the level-triggered select driver.
     */
    public static function listenerAcceptBatchLimit(
        bool $sharedListener,
        string $osFamily,
        string $eventLoopDriver
    ): int {
        if (!$sharedListener) {
            return 64;
        }

        return \strtolower(\trim($eventLoopDriver)) === 'event'
            ? 64
            : 1;
    }

    /**
     * During a server-initiated drain the response already advertises
     * `Connection: close`. Keep the transport readable until the peer
     * acknowledges that contract with FIN (or the bounded drain deadline
     * expires) instead of closing immediately after fwrite().
     *
     * This prevents an upstream keepalive peer from selecting the socket in
     * the small interval between the Worker write and its response-header
     * parsing, which would otherwise turn the close into a request-side RST.
     * Multiplexed protocols use their own GOAWAY/stream-drain handshake.
     */
    public static function shouldAwaitPeerCloseAfterDrainResponse(
        bool $drainRequested,
        bool $isLongLivedProtocol,
        bool $isMultiplexedProtocol = false
    ): bool {
        return $drainRequested && !$isLongLivedProtocol && !$isMultiplexedProtocol;
    }

    public static function shouldCompactAfterDrain(int $releasedBytes): bool
    {
        return $releasedBytes >= self::LARGE_RESPONSE_BYTES;
    }

    /**
     * Call after the request Fiber is removed from the Worker active set.
     * During after_reset the current Fiber is still counted, so compaction must
     * wait until the active set no longer includes it (keep-warm lifecycle).
     *
     * @return array<string,mixed>|null
     */
    public static function setPressureMemoryLimitBytes(int $bytes): void
    {
        self::$pressureLimitBytes = $bytes > 0 ? $bytes : null;
    }

    /** 仅在回收后仍有预算压力且符合 ratchet 候选时，请求安全退役。 */
    public static function shouldDrainForZendRatchet(
        int $realBytes,
        int $usedBytes,
        ?int $warmRealBaselineBytes,
        bool $afterForcedReclaim
    ): bool {
        if (!$afterForcedReclaim || $realBytes <= 0) {
            return false;
        }
        $limitBytes = self::getMemoryLimitBytes();
        if ($limitBytes > 0 && $realBytes / $limitBytes < self::getRuntimeCacheThresholds()['soft']) {
            return false;
        }
        $shell = \max(0, $realBytes - \max(0, $usedBytes));
        if ($shell >= self::ZEND_SHELL_DRAIN_BYTES) {
            return true;
        }
        if ($warmRealBaselineBytes !== null
            && $warmRealBaselineBytes > 0
            && $realBytes >= ($warmRealBaselineBytes + self::ZEND_REAL_OVER_BASELINE_DRAIN_BYTES)
        ) {
            return true;
        }

        return false;
    }

    public static function noteWarmHeapBaseline(int $realBytes, int $usedBytes): void
    {
        $shell = \max(0, $realBytes - \max(0, $usedBytes));
        if ($shell > self::ZEND_WARM_BASELINE_MAX_SHELL_BYTES
            || $usedBytes > self::ZEND_WARM_BASELINE_MAX_USED_BYTES
            || $realBytes <= 0
        ) {
            return;
        }
        if (self::$warmRealBaselineBytes === null || $realBytes < self::$warmRealBaselineBytes) {
            self::$warmRealBaselineBytes = $realBytes;
        }
    }

    public static function warmRealBaselineBytes(): ?int
    {
        return self::$warmRealBaselineBytes;
    }

    public static function compactAfterRequestFiberReleased(int $releasedResponseBytes = 0): ?array
    {
        if (!WlsConcurrency::canCompactProcessCaches()) {
            return null;
        }

        $real = \memory_get_usage(true);
        $largeResponse = $releasedResponseBytes >= self::LARGE_RESPONSE_BYTES;
        $limitBytes = self::getMemoryLimitBytes();
        if ($limitBytes > 0) {
            $pressure = $real / $limitBytes;
            $thresholds = self::getRuntimeCacheThresholds();
            $forceAggressive = $pressure >= $thresholds['hard'];
            $forceSoft = $pressure >= $thresholds['soft'];
        } else {
            $forceAggressive = $largeResponse || $real >= self::LARGE_HEAP_FORCE_AGGRESSIVE_BYTES;
            $forceSoft = $forceAggressive || $real >= self::SOFT_HEAP_FORCE_BYTES;
        }
        // 大响应或绝对量仍整理 allocator；有限预算下只按真实压力清进程缓存。
        if ($forceSoft || $largeResponse || $real >= self::SOFT_HEAP_FORCE_BYTES) {
            return self::compactMemory($forceSoft, $forceAggressive, true);
        }

        return self::compactIfPressure();
    }

    public static function requestDrainAfterResponse(string $reason): void
    {
        $reason = \trim($reason);
        self::$drainAfterResponseReason = $reason !== '' ? $reason : 'memory_pressure';
    }

    public static function drainTimeoutSecondsForReason(string $reason, int $currentTimeout): int
    {
        // An allocator ratchet retires a healthy Worker to reclaim memory;
        // existing HTTP/2 streams still need the ordinary graceful window.
        // Output overflows and other urgent drains retain their short bound.
        return $reason === 'zend_mm_ratchet'
            ? \max($currentTimeout, 120)
            : \min($currentTimeout, 10);
    }

    public static function shouldRestartDrainAfterResponse(string $reason, bool $alreadyDraining): bool
    {
        // A large response can request the same allocator retirement again
        // while older HTTP/2 streams drain. Do not restart its hard deadline.
        return !$alreadyDraining || $reason !== 'zend_mm_ratchet';
    }

    public static function noteIncompleteRequestFiberCancel(): int
    {
        self::$incompleteRequestFiberCancelStreak++;

        return self::$incompleteRequestFiberCancelStreak;
    }

    public static function clearIncompleteRequestFiberCancelStreak(): void
    {
        self::$incompleteRequestFiberCancelStreak = 0;
    }

    public static function incompleteRequestFiberCancelStreak(): int
    {
        return self::$incompleteRequestFiberCancelStreak;
    }

    public static function consumeDrainAfterResponseReason(): ?string
    {
        $reason = self::$drainAfterResponseReason;
        self::$drainAfterResponseReason = null;

        return $reason;
    }

    public static function hasDrainAfterResponseRequest(): bool
    {
        return self::$drainAfterResponseReason !== null;
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
     *     },
     *     cycle_collection_skipped:bool,
     *     drain_requested:bool
     * }
     */
    /**
     * @param bool $forceSoftReclaim 显式请求 soft 进程缓存回收，保留原调用语义。
     * @param bool $forceAggressive 显式请求 aggressive 进程缓存回收。
     */
    public static function compact(bool $forceSoftReclaim = false, bool $forceAggressive = false): array
    {
        return self::compactMemory($forceSoftReclaim, $forceAggressive);
    }

    private static function compactMemory(
        bool $forceSoftReclaim,
        bool $forceAggressive,
        bool $forceAllocatorTrim = false,
    ): array
    {
        $runtimeCacheCompactions = [
            'memory_store_clears' => 0,
            'metadata_entries_cleared' => 0,
            'cleared_process_caches' => 0,
        ];

        $pressure = self::getMemoryPressure();
        $thresholds = self::getRuntimeCacheThresholds();
        // hard 压力先清可重建缓存；退役还须满足回收后的 ratchet 与预算判据。
        $criticalHard = $pressure >= $thresholds['hard'];
        $aggressive = $forceAggressive || $criticalHard;

        if ($forceSoftReclaim || $forceAggressive || $pressure >= $thresholds['soft']) {
            $runtimeCacheCompactions = self::compactRuntimeCaches($aggressive);
        }

        $forcedReclaim = $forceSoftReclaim || $forceAggressive || $forceAllocatorTrim;
        $cycles = 0;
        $trimmedBytes = 0;
        // Skip cycle collect only under true hard *ratio* pressure (STW risk).
        // Forced aggressive after large responses still runs GC — keep-warm needs it.
        if (!$criticalHard) {
            $cycles = \gc_collect_cycles();
        }
        if (\function_exists('gc_mem_caches')) {
            $trimmedBytes = \max(0, (int) \gc_mem_caches());
            if ($forcedReclaim) {
                $trimmedBytes += \max(0, (int) \gc_mem_caches());
            }
        }

        $realAfter = \memory_get_usage(true);
        $usedAfter = \memory_get_usage(false);
        $shellAfter = \max(0, $realAfter - $usedAfter);
        if ($forcedReclaim) {
            self::noteWarmHeapBaseline($realAfter, $usedAfter);
            if (self::shouldDrainForZendRatchet(
                $realAfter,
                $usedAfter,
                self::$warmRealBaselineBytes,
                true
            )) {
                self::requestDrainAfterResponse('zend_mm_ratchet');
            }
        }

        if (\class_exists(\Weline\Framework\Runtime\MemDiag::class, false)
            && \Weline\Framework\Runtime\MemDiag::isArmed()
        ) {
            \Weline\Framework\Runtime\MemDiag::event('worker_memory_compact', [
                'pressure' => $pressure,
                'soft' => $thresholds['soft'],
                'hard' => $thresholds['hard'],
                'aggressive' => $aggressive,
                'force_soft' => $forceSoftReclaim,
                'force_aggressive' => $forceAggressive,
                'force_allocator_trim' => $forceAllocatorTrim,
                'critical_hard' => $criticalHard,
                'cycles' => $cycles,
                'trimmed_bytes' => $trimmedBytes,
                'runtime_cache_compactions' => $runtimeCacheCompactions,
                'real_after' => $realAfter,
                'used_after' => $usedAfter,
                'shell_after' => $shellAfter,
                'warm_real_baseline' => self::$warmRealBaselineBytes,
                'drain_requested' => self::hasDrainAfterResponseRequest(),
                'drain_reason' => self::$drainAfterResponseReason,
            ]);
        }

        return [
            'cycles' => $cycles,
            'trimmed_bytes' => $trimmedBytes,
            'runtime_cache_compactions' => $runtimeCacheCompactions,
            'cycle_collection_skipped' => $criticalHard,
            'drain_requested' => self::hasDrainAfterResponseRequest(),
        ];
    }

    public static function compactIfPressure(float $threshold = self::RUNTIME_CACHE_PRESSURE_THRESHOLD): ?array
    {
        $threshold = self::normalizeThreshold($threshold, self::RUNTIME_CACHE_PRESSURE_THRESHOLD);
        if (self::getMemoryPressure() < $threshold) {
            return null;
        }

        return self::compact();
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

        // ObjectManager MemoryStore and process registries are shared by all
        // request Fibers. A peer may still be rendering from those objects, so
        // only the worker-owned WlsConcurrency fact source may authorize a
        // process-cache compaction window.
        if (!WlsConcurrency::canCompactProcessCaches()) {
            return $compactions;
        }

        if (\class_exists(\Weline\Framework\Manager\ObjectManager::class, false)) {
            $objectManagerCompaction = \Weline\Framework\Manager\ObjectManager::relieveMemoryPressure($aggressive);
            $compactions['memory_store_clears'] = (int) ($objectManagerCompaction['memory_store_clears'] ?? 0);
            $compactions['metadata_entries_cleared'] = (int) ($objectManagerCompaction['metadata_entries_cleared'] ?? 0);
        }

        if (\class_exists(\Weline\Framework\View\TemplateCacheManager::class, false)) {
            \Weline\Framework\View\TemplateCacheManager::clearProcessMemoryCache();
            $compactions['cleared_process_caches']++;
        }

        // Phrase: soft = locale-bucket reclaim; hard/aggressive = full worker bag clear.
        if (\class_exists(\Weline\Framework\Phrase\DictionaryCacheNamespace::class, false)
            || \class_exists(\Weline\Framework\Phrase\DictionaryCacheNamespace::class, true)
        ) {
            if ($aggressive) {
                if (\class_exists(\Weline\Framework\Phrase\Parser::class, false)
                    || \class_exists(\Weline\Framework\Phrase\Parser::class, true)
                ) {
                    \Weline\Framework\Phrase\Parser::clearWorkerCaches();
                    $compactions['cleared_process_caches']++;
                }
            } else {
                $phraseReclaim = \Weline\Framework\Phrase\DictionaryCacheNamespace::processMemoryReclaimable()
                    ->compact();
                if (empty($phraseReclaim['skipped'])) {
                    $compactions['cleared_process_caches']++;
                    $compactions['phrase_freed_bytes'] = (int)($phraseReclaim['freed_bytes'] ?? 0);
                }
            }
        } elseif (\class_exists(\Weline\Framework\Phrase\Parser::class, false)
            || \class_exists(\Weline\Framework\Phrase\Parser::class, true)
        ) {
            \Weline\Framework\Phrase\Parser::clearWorkerCaches();
            $compactions['cleared_process_caches']++;
        }

        try {
            $compactions['cleared_process_caches'] += ObjectManager::getInstance(
                ModuleProcessCacheResetterRegistry::class,
            )->reset(new ProcessCacheResetContext(
                ProcessCacheResetContext::REASON_MEMORY_PRESSURE,
                $aggressive,
            ));
        } catch (\Throwable) {
            // Incomplete bootstrap (no BP / cache adapters) must not abort reclaim.
        }

        if ($aggressive && \class_exists(\Weline\Framework\Event\EventData::class, false)) {
            \Weline\Framework\Event\EventData::clearCache();
            $compactions['cleared_process_caches']++;
        }

        if ($aggressive && \class_exists(\Weline\Framework\Event\EventRegistry::class, false)
            && \method_exists(\Weline\Framework\Event\EventRegistry::class, 'clearRuntimeCache')
        ) {
            \Weline\Framework\Event\EventRegistry::clearRuntimeCache();
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

        // D14: FPC process L1 is last-resort reclaimable under hard pressure only.
        if ($aggressive
            && (\class_exists(\Weline\Framework\Router\FullPageCacheCoordinator::class, false)
                || \class_exists(\Weline\Framework\Router\FullPageCacheCoordinator::class, true))
        ) {
            \Weline\Framework\Router\FullPageCacheCoordinator::clearProcessCache();
            $compactions['cleared_process_caches']++;
            $compactions['fpc_process_cache_cleared'] = 1;
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
        if (self::$pressureLimitBytes !== null && self::$pressureLimitBytes > 0) {
            return self::$pressureLimitBytes;
        }

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
        self::$pressureLimitBytes = null;
        self::$warmRealBaselineBytes = null;
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
