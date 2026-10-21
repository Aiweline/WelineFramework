<?php
declare(strict_types=1);

namespace Weline\Server\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\ModuleProcessCacheResetterRegistry;
use Weline\Framework\Runtime\ProcessCacheResetContext;
use Weline\Framework\Runtime\ProcessMemoryReclaimFacade;
use Weline\Framework\Runtime\WlsConcurrency;

final class WorkerResponseMemoryGuard
{
    public const LARGE_RESPONSE_BYTES = 262144;
    public const LARGE_BUFFER_BYTES = 524288;
    /**
     * Soft water mark: reclaim rebuildable process L1 (cold locale bags).
     * Aligned with env.sample + process-l1-pressure-eviction spec (0.70).
     * 0.55 was too eager on small Worker budgets (e.g. 256M) and thrashed dictionaries.
     */
    private const RUNTIME_CACHE_PRESSURE_THRESHOLD = 0.70;
    /** Hard water mark: aggressive process-bag clear + allocator freelist trim (no Worker drain). */
    private const RUNTIME_CACHE_HARD_PRESSURE_THRESHOLD = 0.85;
    /** 绝对量触发 allocator 整理；未知上限时仍保留原 soft 回收。 */
    private const SOFT_HEAP_FORCE_BYTES = 48 * 1024 * 1024;
    /** 未知内存上限时，保留绝对量触发的 aggressive 回收。 */
    private const LARGE_HEAP_FORCE_AGGRESSIVE_BYTES = 64 * 1024 * 1024;
    /**
     * 回收后 shell（real−used）退役下限。小预算（≤~320M）仍≈历史 48MiB；
     * 大预算再按 {@see ZEND_SHELL_DRAIN_LIMIT_FRACTION} 放大，避免 1G Worker 被 ~50MiB 空壳抖死。
     */
    private const ZEND_SHELL_DRAIN_BYTES_FLOOR = 48 * 1024 * 1024;
    private const ZEND_SHELL_DRAIN_BYTES_CAP = 256 * 1024 * 1024;
    private const ZEND_SHELL_DRAIN_LIMIT_FRACTION = 0.15;
    /** limit 未知时的绝对空壳线（高于 floor，低于典型 1G 缩放值）。 */
    private const ZEND_SHELL_DRAIN_BYTES_UNKNOWN_LIMIT = 128 * 1024 * 1024;
    /** real 相对 warm baseline 超标下限；随 limit 略放大。 */
    private const ZEND_REAL_OVER_BASELINE_DRAIN_BYTES_FLOOR = 32 * 1024 * 1024;
    private const ZEND_REAL_OVER_BASELINE_DRAIN_BYTES_CAP = 128 * 1024 * 1024;
    private const ZEND_REAL_OVER_BASELINE_LIMIT_FRACTION = 0.0625;
    private const ZEND_REAL_OVER_BASELINE_BYTES_UNKNOWN_LIMIT = 64 * 1024 * 1024;
    /** Capture warm baseline only when shell is small enough to look like framework floor. */
    private const ZEND_WARM_BASELINE_MAX_SHELL_BYTES = 24 * 1024 * 1024;
    private const ZEND_WARM_BASELINE_MAX_USED_BYTES = 96 * 1024 * 1024;
    /** Absolute Zend-real headroom below this → loop shed even under soft ratio. */
    private const LOOP_HEADROOM_SHED_BYTES = 64 * 1024 * 1024;
    /**
     * Hard floor for Fiber admission fail-closed: only here (or after full queue wait)
     * do we 503. Soft headroom below {@see LOOP_HEADROOM_SHED_BYTES} prefers delay.
     */
    private const ADMISSION_MEMORY_HARD_FLOOR_BYTES = 16 * 1024 * 1024;
    /**
     * When Worker `--memory-limit` is known and no explicit headroom override is set,
     * soft admission headroom scales as this fraction of the limit (never below
     * {@see LOOP_HEADROOM_SHED_BYTES}). 256M → ~102MiB soft → prefer delay/503 over
     * admitting until process-fatal OOM (Nginx 502).
     */
    /**
     * Soft headroom fraction of Worker memory_limit.
     * Keep one budget for all sizes: loosening ≥512M raised max_active and caused
     * upstream closes (Nginx 502) under mix30; prefer queue/503 over process death.
     */
    private const ADMISSION_MEMORY_HEADROOM_FRACTION = 0.40;
    /**
     * Budget per admitted short-request Fiber when clamping configured max_active
     * to the Worker memory_limit (storefront render growth, not RSS snapshot).
     */
    private const ADMISSION_PER_ACTIVE_FIBER_BUDGET_BYTES = 32 * 1024 * 1024;
    /** Zend shell (real−used) above this → gc_mem_caches without wiping bags. */
    private const LOOP_SHELL_TRIM_BYTES = 24 * 1024 * 1024;
    /** used above this → soft rebuildable process-cache reclaim (busy-safe OM when peers). */
    private const LOOP_USED_SOFT_RECLAIM_BYTES = 96 * 1024 * 1024;
    /** 1G Worker: used 25% (256MiB) and shell 5% (51MiB) before loop reclaim. */
    private const LOOP_USED_SOFT_RECLAIM_LIMIT_FRACTION = 0.25;
    private const LOOP_SHELL_TRIM_LIMIT_FRACTION = 0.05;
    private const LOOP_USED_SOFT_RECLAIM_CAP_BYTES = 384 * 1024 * 1024;
    private const LOOP_SHELL_TRIM_CAP_BYTES = 128 * 1024 * 1024;
    /**
     * A loop shed that frees less than this is a no-op on the current heap shape
     * (usually the framework floor); do not repeat it every scheduler tick.
     */
    private const LOOP_SHED_MIN_GAIN_BYTES = 4 * 1024 * 1024;
    /** After a no-gain shed, wait for this much used growth or the cooldown. */
    private const LOOP_SHED_RETRY_GROWTH_BYTES = 16 * 1024 * 1024;
    private const LOOP_SHED_RETRY_COOLDOWN_SECONDS = 60.0;

    /**
     * Zend allocator sweep (gc_mem_caches) + cycle collector are the top cold
     * request CPU cost once a Worker heap sits under hard ratio pressure: the
     * sweep walks every free chunk and was executed per response (up to 4×).
     * Trim only when the heap actually grew or the cooldown expired; keep an
     * emergency path for true OOM headroom.
     */
    private const ALLOCATOR_TRIM_COOLDOWN_SECONDS = 2.0;
    private const ALLOCATOR_TRIM_MIN_GROWTH_BYTES = 8 * 1024 * 1024;
    private const ALLOCATOR_TRIM_EMERGENCY_HEADROOM_BYTES = 32 * 1024 * 1024;

    private static ?array $runtimeCacheThresholds = null;
    /** Pressure denominator from Worker `--memory-limit` (ignore DevTool-inflated ini). */
    private static ?int $pressureLimitBytes = null;
    /**
     * Optional override for Fiber admission absolute headroom (bytes).
     * Null → {@see LOOP_HEADROOM_SHED_BYTES} or env `wls.fiber.admission_memory_headroom_bytes`.
     */
    private static ?int $admissionMemoryHeadroomBytes = null;
    /** Optional override for hard fail-closed floor (bytes). Null → {@see ADMISSION_MEMORY_HARD_FLOOR_BYTES}. */
    private static ?int $admissionMemoryHardFloorBytes = null;
    /** Lowest clean Zend-real seen after reclaim (framework warm floor). */
    private static ?int $warmRealBaselineBytes = null;
    private static ?string $drainAfterResponseReason = null;
    private static int $incompleteRequestFiberCancelStreak = 0;
    /** Passiveive counters for Master capacity advisor (statusReport scalars). */
    private static int $compactSoftCount = 0;
    private static int $compactHardCount = 0;
    private static int $drainRequestCount = 0;
    private static float $lastPressure = 0.0;
    private static int $admissionMemoryRejects = 0;
    private static int $lastAbsoluteHeadroomBytes = 0;
    /** Loop-shed hysteresis: avoid clearing rebuildable caches when the last attempt freed nothing. */
    private static float $lastLoopShedAt = 0.0;
    private static int $lastLoopShedUsed = 0;
    private static int $lastLoopShedGain = 0;
    private static bool $loopShedSuppressedLogged = false;
    private static float $lastAllocatorTrimAt = 0.0;
    private static int $lastAllocatorTrimReal = 0;

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

    /**
     * Optional absolute headroom threshold for Fiber admission (bytes).
     * Pass 0 to clear and fall back to {@see LOOP_HEADROOM_SHED_BYTES} / env.
     */
    public static function setAdmissionMemoryHeadroomBytes(int $bytes): void
    {
        self::$admissionMemoryHeadroomBytes = $bytes > 0 ? $bytes : null;
    }

    /**
     * Absolute Zend-real headroom: memory_limit − memory_get_usage(true).
     * Unknown / unlimited limit → {@see \PHP_INT_MAX}.
     */
    public static function absoluteHeadroomBytes(): int
    {
        $limitBytes = self::getMemoryLimitBytes();
        $realBytes = \memory_get_usage(true);
        $headroom = $limitBytes > 0 ? \max(0, $limitBytes - $realBytes) : \PHP_INT_MAX;
        self::$lastAbsoluteHeadroomBytes = $headroom;

        return $headroom;
    }

    /**
     * Cheap probe (+ optional critical shed) before admitting a short-request Fiber.
     * Does not emit HTTP, drain, or exit. Healthy path skips compact.
     *
     * Policy: soft headroom miss → prefer queue/delay (`admit=false`, `critical=false`);
     * hard floor miss → fail-closed (`critical=true`). Callers MUST wait when not critical.
     *
     * @return array{
     *   admit:bool,
     *   critical:bool,
     *   absolute_headroom_bytes:int,
     *   pressure:float,
     *   compacted:bool,
     *   reason:?string
     * }
     */
    public static function evaluateAdmissionMemoryGate(bool $compactIfNeeded = true): array
    {
        $thresholds = self::getRuntimeCacheThresholds();
        $pressure = self::getMemoryPressure();
        self::$lastPressure = $pressure;
        $headroom = self::absoluteHeadroomBytes();
        $threshold = self::admissionMemoryHeadroomThresholdBytes();
        $hardFloor = self::admissionMemoryHardFloorBytes();
        $compacted = false;

        // Healthy: enough absolute headroom and below soft ratio → admit without shed.
        if ($headroom >= $threshold && $pressure < $thresholds['soft']) {
            return [
                'admit' => true,
                'critical' => false,
                'absolute_headroom_bytes' => $headroom,
                'pressure' => $pressure,
                'compacted' => false,
                'reason' => null,
            ];
        }

        if ($compactIfNeeded) {
            self::compactForNewRequestHeadroom(false);
            $compacted = true;
            $pressure = self::getMemoryPressure();
            self::$lastPressure = $pressure;
            $headroom = self::absoluteHeadroomBytes();
        }

        $admit = $headroom >= $threshold;
        $critical = !$admit && $headroom < $hardFloor;

        return [
            'admit' => $admit,
            'critical' => $critical,
            'absolute_headroom_bytes' => $headroom,
            'pressure' => $pressure,
            'compacted' => $compacted,
            'reason' => $admit ? null : 'memory_pressure',
        ];
    }

    /**
     * Optional hard-floor override (bytes). Pass 0 to clear.
     */
    public static function setAdmissionMemoryHardFloorBytes(int $bytes): void
    {
        self::$admissionMemoryHardFloorBytes = $bytes > 0 ? $bytes : null;
    }

    public static function noteAdmissionMemoryReject(): void
    {
        self::$admissionMemoryRejects++;
    }

    /**
     * Soft admission headroom. Explicit {@see setAdmissionMemoryHeadroomBytes()} or env
     * `wls.fiber.admission_memory_headroom_bytes` wins; else scales with Worker
     * `--memory-limit` ({@see ADMISSION_MEMORY_HEADROOM_FRACTION}) and never below
     * {@see LOOP_HEADROOM_SHED_BYTES}.
     */
    public static function admissionMemoryHeadroomThresholdBytes(): int
    {
        if (self::$admissionMemoryHeadroomBytes !== null && self::$admissionMemoryHeadroomBytes > 0) {
            return self::$admissionMemoryHeadroomBytes;
        }

        $envRaw = $_ENV['wls.fiber.admission_memory_headroom_bytes']
            ?? $_SERVER['wls.fiber.admission_memory_headroom_bytes']
            ?? \getenv('wls.fiber.admission_memory_headroom_bytes');
        if (\is_string($envRaw) && $envRaw !== '' && \ctype_digit($envRaw)) {
            $parsed = (int)$envRaw;
            if ($parsed > 0) {
                return $parsed;
            }
        }

        return self::scaledAdmissionMemoryHeadroomThresholdBytes(self::$pressureLimitBytes);
    }

    /**
     * Scale soft headroom to Worker memory_limit so low limits stop admitting earlier.
     * Unknown / unlimited limit → {@see LOOP_HEADROOM_SHED_BYTES}.
     */
    public static function scaledAdmissionMemoryHeadroomThresholdBytes(?int $limitBytes): int
    {
        $floor = self::LOOP_HEADROOM_SHED_BYTES;
        if ($limitBytes === null || $limitBytes <= 0) {
            return $floor;
        }

        $scaled = (int) \floor($limitBytes * self::ADMISSION_MEMORY_HEADROOM_FRACTION);
        $hardFloor = self::admissionMemoryHardFloorBytes();
        // Leave at least hard-floor bytes for fail-closed detection.
        $capped = \max($floor, \min($scaled, \max($floor, $limitBytes - $hardFloor)));

        return $capped;
    }

    /** @internal exposed for contracts */
    public static function admissionMemoryHeadroomFractionForLimit(int $limitBytes): float
    {
        unset($limitBytes);

        return self::ADMISSION_MEMORY_HEADROOM_FRACTION;
    }

    /**
     * Clamp configured Fiber max_active to a budget that fits under memory_limit
     * after soft headroom. Prefer queue/503 over process-fatal OOM → Nginx 502.
     * Unlimited (`0`) is left unchanged (memory gate still delays/rejects).
     * Pass `$pinned=true` (fiber.pin_max_active) to keep the configured value.
     */
    public static function clampMaxActiveForMemoryLimit(
        int $configuredMaxActive,
        ?int $limitBytes = null,
        bool $pinned = false
    ): int {
        if ($pinned || $configuredMaxActive <= 0) {
            return $configuredMaxActive;
        }

        $limit = $limitBytes;
        if ($limit === null || $limit <= 0) {
            $limit = self::$pressureLimitBytes;
        }
        if ($limit === null || $limit <= 0) {
            return $configuredMaxActive;
        }

        $headroomNeed = self::scaledAdmissionMemoryHeadroomThresholdBytes($limit);
        if (self::$admissionMemoryHeadroomBytes !== null && self::$admissionMemoryHeadroomBytes > 0) {
            $headroomNeed = self::$admissionMemoryHeadroomBytes;
        }
        $budget = \max(0, $limit - $headroomNeed);
        $perFiber = self::admissionPerActiveFiberBudgetBytesForLimit($limit);
        $byBudget = \max(1, (int) \floor($budget / $perFiber));

        return \min($configuredMaxActive, $byBudget);
    }

    /** Per admitted short-request Fiber budget used by clamp / adaptive max_active. */
    public static function admissionPerActiveFiberBudgetBytes(): int
    {
        return self::ADMISSION_PER_ACTIVE_FIBER_BUDGET_BYTES;
    }

    /** @internal exposed for contracts */
    public static function admissionPerActiveFiberBudgetBytesForLimit(?int $limitBytes): int
    {
        unset($limitBytes);

        return self::ADMISSION_PER_ACTIVE_FIBER_BUDGET_BYTES;
    }

    /**
     * Hard fail-closed floor. Soft misses prefer delay; only below this (or after
     * full admission wait) should callers emit memory_pressure 503.
     */
    public static function admissionMemoryHardFloorBytes(): int
    {
        if (self::$admissionMemoryHardFloorBytes !== null && self::$admissionMemoryHardFloorBytes > 0) {
            return self::$admissionMemoryHardFloorBytes;
        }

        $envRaw = $_ENV['wls.fiber.admission_memory_hard_floor_bytes']
            ?? $_SERVER['wls.fiber.admission_memory_hard_floor_bytes']
            ?? \getenv('wls.fiber.admission_memory_hard_floor_bytes');
        if (\is_string($envRaw) && $envRaw !== '' && \ctype_digit($envRaw)) {
            $parsed = (int)$envRaw;
            if ($parsed > 0) {
                return $parsed;
            }
        }

        return self::ADMISSION_MEMORY_HARD_FLOOR_BYTES;
    }

    /**
     * 空壳退役线：max(floor, min(cap, limit×15%))；limit 未知 → 128MiB。
     * 1024M → ~153MiB；256M → 48MiB（保持小预算敏感度）。
     *
     * @internal 契约/诊断可调用
     */
    public static function zendShellDrainBytesForLimit(?int $limitBytes): int
    {
        if ($limitBytes === null || $limitBytes <= 0) {
            return self::ZEND_SHELL_DRAIN_BYTES_UNKNOWN_LIMIT;
        }

        $scaled = (int) \floor($limitBytes * self::ZEND_SHELL_DRAIN_LIMIT_FRACTION);

        return \max(
            self::ZEND_SHELL_DRAIN_BYTES_FLOOR,
            \min(self::ZEND_SHELL_DRAIN_BYTES_CAP, $scaled)
        );
    }

    /**
     * baseline 超标增量：max(32MiB, min(128MiB, limit×6.25%))；未知 → 64MiB。
     *
     * @internal 契约/诊断可调用
     */
    public static function zendRealOverBaselineDrainBytesForLimit(?int $limitBytes): int
    {
        if ($limitBytes === null || $limitBytes <= 0) {
            return self::ZEND_REAL_OVER_BASELINE_BYTES_UNKNOWN_LIMIT;
        }

        $scaled = (int) \floor($limitBytes * self::ZEND_REAL_OVER_BASELINE_LIMIT_FRACTION);

        return \max(
            self::ZEND_REAL_OVER_BASELINE_DRAIN_BYTES_FLOOR,
            \min(self::ZEND_REAL_OVER_BASELINE_DRAIN_BYTES_CAP, $scaled)
        );
    }

    /**
     * 回收后请求安全退役。
     * shell≥缩放空壳线：不过 soft 比（used↓ 后 ratio 常跌破 soft）。
     * baseline 超标路径仍要求 real/limit ≥ soft（limit≤0 时仅看 baseline）。
     */
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
        $shell = \max(0, $realBytes - \max(0, $usedBytes));
        if ($shell >= self::zendShellDrainBytesForLimit($limitBytes > 0 ? $limitBytes : null)) {
            return true;
        }
        $baselineOver = self::zendRealOverBaselineDrainBytesForLimit($limitBytes > 0 ? $limitBytes : null);
        if ($warmRealBaselineBytes !== null
            && $warmRealBaselineBytes > 0
            && $realBytes >= ($warmRealBaselineBytes + $baselineOver)
        ) {
            if ($limitBytes <= 0) {
                return true;
            }

            return ($realBytes / $limitBytes) >= self::getRuntimeCacheThresholds()['soft'];
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
        self::$drainRequestCount++;
    }

    /**
     * Scalar diagnostics for ControlMessage::statusReport context.
     *
     * @return array{
     *   compact_soft_count:int,
     *   compact_hard_count:int,
     *   drain_request_count:int,
     *   last_pressure:float,
     *   admission_memory_rejects:int,
     *   absolute_headroom_bytes:int,
     *   fiber_max_active_effective:int,
     *   fiber_max_active_ceiling:int,
     *   fiber_admission_waiters:int,
     *   fiber_admission_active:int,
     *   fiber_admission_saturated:int
     * }
     */
    public static function capacityStatusScalars(): array
    {
        try {
            self::$lastPressure = self::getMemoryPressure();
            self::absoluteHeadroomBytes();
        } catch (\Throwable) {
        }

        $adaptive = [];
        try {
            if (\class_exists(AdaptiveFiberMaxActive::class, false)
                || \class_exists(AdaptiveFiberMaxActive::class)
            ) {
                $adaptive = AdaptiveFiberMaxActive::capacityScalars();
            }
        } catch (\Throwable) {
            $adaptive = [];
        }

        return [
            'compact_soft_count' => self::$compactSoftCount,
            'compact_hard_count' => self::$compactHardCount,
            'drain_request_count' => self::$drainRequestCount,
            'last_pressure' => self::$lastPressure,
            'admission_memory_rejects' => self::$admissionMemoryRejects,
            'absolute_headroom_bytes' => self::$lastAbsoluteHeadroomBytes,
        ] + $adaptive;
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

    private static function allocatorTrimDue(int $realBefore, bool $emergency = false): bool
    {
        if ($emergency) {
            self::$lastAllocatorTrimAt = \microtime(true);
            self::$lastAllocatorTrimReal = $realBefore;

            return true;
        }
        $now = \microtime(true);
        $cooldownElapsed = self::$lastAllocatorTrimAt <= 0.0
            || ($now - self::$lastAllocatorTrimAt) >= self::ALLOCATOR_TRIM_COOLDOWN_SECONDS;
        $shellGrew = ($realBefore - self::$lastAllocatorTrimReal) >= self::ALLOCATOR_TRIM_MIN_GROWTH_BYTES;
        if (!$cooldownElapsed && !$shellGrew) {
            return false;
        }
        self::$lastAllocatorTrimAt = $now;
        self::$lastAllocatorTrimReal = $realBefore;

        return true;
    }

    private static function allocatorHeadroomEmergency(): bool
    {
        $limit = self::getMemoryLimitBytes();
        if ($limit <= 0) {
            return false;
        }

        return \max(0, $limit - \memory_get_usage(true)) < self::ALLOCATOR_TRIM_EMERGENCY_HEADROOM_BYTES;
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
        self::$lastPressure = $pressure;
        $thresholds = self::getRuntimeCacheThresholds();
        // hard 压力先清可重建缓存；退役还须满足回收后的 ratchet 与预算判据。
        $criticalHard = $pressure >= $thresholds['hard'];
        $aggressive = $forceAggressive || $criticalHard;

        if ($forceSoftReclaim || $forceAggressive || $pressure >= $thresholds['soft']) {
            $runtimeCacheCompactions = self::compactRuntimeCaches($aggressive);
            if ($aggressive || $criticalHard) {
                self::$compactHardCount++;
            } else {
                self::$compactSoftCount++;
            }
        }

        $forcedReclaim = $forceSoftReclaim || $forceAggressive || $forceAllocatorTrim;
        $cycles = 0;
        $trimmedBytes = 0;
        $realBefore = \memory_get_usage(true);
        $trimDue = self::allocatorTrimDue($realBefore, self::allocatorHeadroomEmergency());
        // Skip cycle collect only under true hard *ratio* pressure (STW risk).
        // Forced aggressive after large responses still runs GC — keep-warm needs it.
        if (!$criticalHard && $trimDue) {
            $cycles = \gc_collect_cycles();
        }
        if ($trimDue && \function_exists('gc_mem_caches')) {
            $trimmedBytes = \max(0, (int) \gc_mem_caches());
            if ($forcedReclaim && self::allocatorHeadroomEmergency()) {
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
     * 只编排：OM → ProcessMemoryReclaimFacade → ModuleProcessCacheResetterRegistry。
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

        if (!WlsConcurrency::canCompactProcessCaches()) {
            return $compactions;
        }

        if (\class_exists(\Weline\Framework\Manager\ObjectManager::class, false)) {
            $objectManagerCompaction = \Weline\Framework\Manager\ObjectManager::relieveMemoryPressure($aggressive);
            $compactions['memory_store_clears'] = (int) ($objectManagerCompaction['memory_store_clears'] ?? 0);
            $compactions['metadata_entries_cleared'] = (int) ($objectManagerCompaction['metadata_entries_cleared'] ?? 0);
        }

        $registry = ProcessMemoryReclaimFacade::registry();
        if ($aggressive) {
            $evict = $registry->evictBytes(ProcessMemoryReclaimFacade::HARD_EVICT_BYTES, true);
            // Ensure FPC last-resort even when estimate was zero (critical parity).
            $fpc = $registry->evictBytes(1, true);
            $compactions['cleared_process_caches'] += \count($evict['details'] ?? []) + \count($fpc['details'] ?? []);
            $compactions['phrase_freed_bytes'] = self::phraseFreedFromDetails(
                \array_merge($evict['details'] ?? [], $fpc['details'] ?? [])
            );
            if (((int)($fpc['freed_bytes'] ?? 0)) > 0 || ((int)($evict['freed_bytes'] ?? 0)) > 0) {
                $compactions['fpc_process_cache_cleared'] = 1;
            }
        } else {
            // Request soft: compactAll (Phrase half-locale / NodePool trim) — not evictBytes(8MiB).
            $compact = $registry->compactAll();
            $compactions['cleared_process_caches'] += \count($compact['details'] ?? []);
            $compactions['phrase_freed_bytes'] = self::phraseFreedFromDetails($compact['details'] ?? []);
        }

        try {
            $context = $aggressive
                ? new ProcessCacheResetContext(
                    ProcessCacheResetContext::REASON_MEMORY_PRESSURE,
                    true,
                    ProcessCacheResetContext::SURFACE_ALL,
                    ProcessCacheResetContext::TIER_HARD,
                )
                : new ProcessCacheResetContext(
                    ProcessCacheResetContext::REASON_MEMORY_PRESSURE,
                    false,
                    ProcessCacheResetContext::SURFACE_CONTENT,
                    ProcessCacheResetContext::TIER_SOFT,
                );
            $compactions['cleared_process_caches'] += ObjectManager::getInstance(
                ModuleProcessCacheResetterRegistry::class,
            )->reset($context);
        } catch (\Throwable) {
            // Incomplete bootstrap (no BP / cache adapters) must not abort reclaim.
        }

        return $compactions;
    }

    /**
     * Absolute used threshold for loop reclaim, scaled to the Worker budget.
     * A fixed 96MiB floor was calibrated for small Workers; on a 1G Worker it
     * fired at ~10% usage and repeatedly cleared caches that immediately rebuilt.
     */
    public static function loopUsedSoftReclaimBytesForLimit(int $limitBytes): int
    {
        if ($limitBytes <= 0) {
            return self::LOOP_USED_SOFT_RECLAIM_BYTES;
        }
        $scaled = (int)\floor($limitBytes * self::LOOP_USED_SOFT_RECLAIM_LIMIT_FRACTION);

        return \max(
            self::LOOP_USED_SOFT_RECLAIM_BYTES,
            \min(self::LOOP_USED_SOFT_RECLAIM_CAP_BYTES, $scaled)
        );
    }

    /**
     * Zend shell threshold for allocator trim, scaled to the Worker budget.
     */
    public static function loopShellTrimBytesForLimit(int $limitBytes): int
    {
        if ($limitBytes <= 0) {
            return self::LOOP_SHELL_TRIM_BYTES;
        }
        $scaled = (int)\floor($limitBytes * self::LOOP_SHELL_TRIM_LIMIT_FRACTION);

        return \max(
            self::LOOP_SHELL_TRIM_BYTES,
            \min(self::LOOP_SHELL_TRIM_CAP_BYTES, $scaled)
        );
    }

    /**
     * Hysteresis for absolute (not ratio/headroom/force) loop sheds: if the last
     * attempt freed less than {@see LOOP_SHED_MIN_GAIN_BYTES}, require either
     * {@see LOOP_SHED_RETRY_GROWTH_BYTES} of new used memory or the cooldown.
     */
    public static function shouldSuppressLoopShed(
        int $usedBefore,
        float $pressure,
        float $softThreshold,
        bool $lowHeadroom,
        bool $force = false
    ): bool {
        if ($force || $lowHeadroom || $pressure >= $softThreshold) {
            return false;
        }
        if (self::$lastLoopShedAt <= 0.0 || self::$lastLoopShedGain >= self::LOOP_SHED_MIN_GAIN_BYTES) {
            return false;
        }
        if ((\microtime(true) - self::$lastLoopShedAt) >= self::LOOP_SHED_RETRY_COOLDOWN_SECONDS) {
            return false;
        }

        return $usedBefore < (self::$lastLoopShedUsed + self::LOOP_SHED_RETRY_GROWTH_BYTES);
    }

    /**
     * A shed's real relief includes allocator/shell trims that lower Zend-real
     * without lowering used. Used by the no-op hysteresis.
     */
    public static function loopShedTotalGain(int $usedGain, int $allocatorTrimmed, int $hotTrimmed): int
    {
        return \max(0, $usedGain) + \max(0, $allocatorTrimmed) + \max(0, $hotTrimmed);
    }

    /**
     * Worker event-loop shed: free headroom for *new* requests while peer Fibers
     * may still be running. Does NOT require {@see WlsConcurrency::canCompactProcessCaches()}.
     * Does NOT request Worker suicide/drain — slow miss is OK; OOM is not.
     *
     * @return array{
     *   triggered:bool,
     *   suppressed:bool,
     *   pressure:float,
     *   locales_evicted:list<string>,
     *   hotcache_trimmed_bytes:int,
     *   allocator_trimmed_bytes:int,
     *   process_caches_cleared:int,
     *   used_before:int,
     *   used_after:int,
     *   gain?:int,
     *   total_gain?:int,
     *   used_threshold?:int,
     *   shell_threshold?:int
     * }
     */
    public static function compactForNewRequestHeadroom(bool $force = false): array
    {
        $pressure = self::getMemoryPressure();
        self::$lastPressure = $pressure;
        $thresholds = self::getRuntimeCacheThresholds();
        $usedBefore = \memory_get_usage(false);
        $realBefore = \memory_get_usage(true);
        $shellBefore = \max(0, $realBefore - $usedBefore);
        $limitBytes = self::getMemoryLimitBytes();
        $absoluteHeadroom = $limitBytes > 0 ? \max(0, $limitBytes - $realBefore) : \PHP_INT_MAX;
        $lowHeadroom = $absoluteHeadroom < self::LOOP_HEADROOM_SHED_BYTES;
        $usedThreshold = self::loopUsedSoftReclaimBytesForLimit($limitBytes);
        $shellThreshold = self::loopShellTrimBytesForLimit($limitBytes);
        $shellHigh = $shellBefore >= $shellThreshold;
        $usedHigh = $usedBefore >= $usedThreshold;
        $empty = [
            'triggered' => false,
            'suppressed' => false,
            'pressure' => $pressure,
            'locales_evicted' => [],
            'hotcache_trimmed_bytes' => 0,
            'allocator_trimmed_bytes' => 0,
            'process_caches_cleared' => 0,
            'used_before' => $usedBefore,
            'used_after' => $usedBefore,
        ];
        // Soft ratio, absolute headroom, Zend shell creep, or used climb.
        if (!$force && $pressure < $thresholds['soft'] && !$lowHeadroom && !$shellHigh && !$usedHigh) {
            return $empty;
        }
        if (self::shouldSuppressLoopShed($usedBefore, $pressure, $thresholds['soft'], $lowHeadroom, $force)) {
            if (!self::$loopShedSuppressedLogged) {
                self::$loopShedSuppressedLogged = true;
                if (\class_exists(\Weline\Framework\Runtime\MemDiag::class, false)
                    || \class_exists(\Weline\Framework\Runtime\MemDiag::class)
                ) {
                    \Weline\Framework\Runtime\MemDiag::event('worker_loop_shed_suppressed', [
                        'pressure' => $pressure,
                        'used' => $usedBefore,
                        'last_shed_used' => self::$lastLoopShedUsed,
                        'last_shed_gain' => self::$lastLoopShedGain,
                        'retry_growth_bytes' => self::LOOP_SHED_RETRY_GROWTH_BYTES,
                        'cooldown_s' => self::LOOP_SHED_RETRY_COOLDOWN_SECONDS,
                    ]);
                }
            }
            $empty['suppressed'] = true;

            return $empty;
        }

        $shedBags = $force || $lowHeadroom || $pressure >= $thresholds['soft'] || $usedHigh;
        $localesEvicted = [];
        $hotTrimmed = 0;
        $processCachesCleared = 0;
        if ($shedBags) {
            // Soft pressure (and low absolute headroom) must shed cold Phrase locales —
            // waiting for hard pressure lets multi-locale crawls inflate bagRefs first.
            $keepWarmLocales = ($pressure >= $thresholds['soft'] || $absoluteHeadroom < (48 * 1024 * 1024))
                ? 0
                : 1;

            if (\class_exists(\Weline\Framework\Phrase\DictionaryCacheNamespace::class, false)
                || \class_exists(\Weline\Framework\Phrase\DictionaryCacheNamespace::class)
            ) {
                // keepWarm=0 表示冷语种全丢。shed 把 maxDrop<=0 当成「只丢一半」，这里改传全部。
                $shed = \Weline\Framework\Phrase\DictionaryCacheNamespace::shedColdLocalesForHeadroom(
                    $keepWarmLocales === 0 ? \PHP_INT_MAX : $keepWarmLocales
                );
                $localesEvicted = $shed['evicted'];
            }

            if (\class_exists(\Weline\Framework\Cache\Service\StorefrontScopeHotCache::class, false)
                || \class_exists(\Weline\Framework\Cache\Service\StorefrontScopeHotCache::class)
            ) {
                $budget = ($pressure >= $thresholds['hard'] || $keepWarmLocales === 0)
                    ? (512 * 1024)
                    : (1536 * 1024);
                $hotTrimmed = (int)\Weline\Framework\Cache\Service\StorefrontScopeHotCache::trimProcessCacheToBudget($budget);
            }

            // Rebuildable reclaim when used climbs (no drain). Near ceiling + idle → hard;
            // peers → OM soft only; otherwise soft Facade + Resetter.
            if ($usedHigh || $pressure >= $thresholds['soft'] || $lowHeadroom) {
                $aggressive = $lowHeadroom && $absoluteHeadroom < (56 * 1024 * 1024);
                if (WlsConcurrency::canCompactProcessCaches()) {
                    $runtime = self::compactRuntimeCaches($aggressive);
                    $processCachesCleared = (int)($runtime['cleared_process_caches'] ?? 0);
                } elseif (\class_exists(ObjectManager::class, false)
                    || \class_exists(ObjectManager::class)
                ) {
                    $om = ObjectManager::relieveMemoryPressure($aggressive);
                    $processCachesCleared = (int)(($om['memory_store_clears'] ?? 0) + ($om['memory_store_evictions'] ?? 0));
                }
            }
        }

        // Allocator freelist trim — primary fix for 256M shell balloon; no bag wipe, no drain.
        // Gated: sweeping every free chunk on each loop tick is the top cold-request CPU cost.
        $allocatorTrimmed = 0;
        $trimDue = self::allocatorTrimDue($realBefore, self::allocatorHeadroomEmergency());
        if ($trimDue) {
            \gc_collect_cycles();
            if (\function_exists('gc_mem_caches')) {
                $allocatorTrimmed = \max(0, (int)\gc_mem_caches());
            }
        }

        if ($localesEvicted !== [] || $hotTrimmed > 0 || $allocatorTrimmed > 0 || $processCachesCleared > 0) {
            self::$compactSoftCount++;
        }

        $usedAfter = \memory_get_usage(false);
        $gain = \max(0, $usedBefore - $usedAfter);
        // Allocator trim lowers Zend-real/RSS without lowering used; count it as
        // relief so a beneficial trim does not trigger the no-op hysteresis.
        $totalGain = self::loopShedTotalGain($gain, $allocatorTrimmed, $hotTrimmed);
        self::$lastLoopShedAt = \microtime(true);
        self::$lastLoopShedUsed = $usedBefore;
        self::$lastLoopShedGain = $totalGain;
        if ($totalGain >= self::LOOP_SHED_MIN_GAIN_BYTES) {
            self::$loopShedSuppressedLogged = false;
        }
        if (\class_exists(\Weline\Framework\Runtime\MemDiag::class, false)
            || \class_exists(\Weline\Framework\Runtime\MemDiag::class)
        ) {
            \Weline\Framework\Runtime\MemDiag::event('worker_loop_headroom_shed', [
                'pressure' => $pressure,
                'used_threshold' => $usedThreshold,
                'shell_threshold' => $shellThreshold,
                'locales_evicted' => $localesEvicted,
                'hotcache_trimmed_bytes' => $hotTrimmed,
                'allocator_trimmed_bytes' => $allocatorTrimmed,
                'process_caches_cleared' => $processCachesCleared,
                'shell_before' => $shellBefore,
                'peers' => WlsConcurrency::getOtherSuspendedRequestFiberCount(),
                'delta_used' => $usedBefore - $usedAfter,
                'gain' => $gain,
                'total_gain' => $totalGain,
                'next_suppressed' => $totalGain < self::LOOP_SHED_MIN_GAIN_BYTES,
            ]);
        }

        return [
            'triggered' => true,
            'suppressed' => false,
            'pressure' => $pressure,
            'locales_evicted' => $localesEvicted,
            'hotcache_trimmed_bytes' => $hotTrimmed,
            'allocator_trimmed_bytes' => $allocatorTrimmed,
            'process_caches_cleared' => $processCachesCleared,
            'used_before' => $usedBefore,
            'used_after' => $usedAfter,
            'gain' => $gain,
            'total_gain' => $totalGain,
            'used_threshold' => $usedThreshold,
            'shell_threshold' => $shellThreshold,
        ];
    }

    /**
     * Host pressure levels → single Guard entry (no private Applier Registry).
     * yellow=soft compactAll; red=evictBytes(8MiB)+soft; critical=hard+FPC.
     *
     * @return array{freed_bytes:int, skipped:int, runtime_cache_compactions?:array}
     */
    public static function compactForHostLevel(string $level): array
    {
        $level = \strtolower(\trim($level));
        if ($level === 'green' || $level === '') {
            return ['freed_bytes' => 0, 'skipped' => 0];
        }
        if (!WlsConcurrency::canCompactProcessCaches()) {
            return ['freed_bytes' => 0, 'skipped' => 1];
        }

        $before = \memory_get_usage(true);
        $result = ['freed_bytes' => 0, 'skipped' => 0];

        if ($level === 'red') {
            $evict = ProcessMemoryReclaimFacade::registry()->evictBytes(
                ProcessMemoryReclaimFacade::SOFT_EVICT_BYTES,
                false
            );
            $result['freed_bytes'] = (int)($evict['freed_bytes'] ?? 0);
            $result['skipped'] = (int)($evict['skipped'] ?? 0);
            $runtime = self::compactRuntimeCaches(false);
        } elseif ($level === 'critical') {
            $runtime = self::compactRuntimeCaches(true);
        } else { // yellow
            $runtime = self::compactRuntimeCaches(false);
        }

        $after = \memory_get_usage(true);
        $observed = \max(0, $before - $after);
        $result['freed_bytes'] = \max((int)($result['freed_bytes'] ?? 0), $observed);
        $result['runtime_cache_compactions'] = $runtime;

        return $result;
    }

    /**
     * @param list<array<string, mixed>> $details
     */
    private static function phraseFreedFromDetails(array $details): int
    {
        foreach ($details as $row) {
            if (($row['name'] ?? '') === 'phrase_dictionary_process_store') {
                return (int)($row['freed_bytes'] ?? 0);
            }
        }

        return 0;
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
        self::$admissionMemoryHeadroomBytes = null;
        self::$admissionMemoryHardFloorBytes = null;
        self::$warmRealBaselineBytes = null;
        self::$admissionMemoryRejects = 0;
        self::$lastAbsoluteHeadroomBytes = 0;
        self::$lastPressure = 0.0;
        self::$lastLoopShedAt = 0.0;
        self::$lastLoopShedUsed = 0;
        self::$lastLoopShedGain = 0;
        self::$loopShedSuppressedLogged = false;
        if (\class_exists(AdaptiveFiberMaxActive::class, false)
            || \class_exists(AdaptiveFiberMaxActive::class)
        ) {
            AdaptiveFiberMaxActive::resetForTests();
        }
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
