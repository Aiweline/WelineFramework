<?php

declare(strict_types=1);

namespace Weline\Framework\Runtime;

use Weline\Framework\App\Env;
use Weline\Framework\Manager\ObjectManager;

/**
 * Layer B contract: Process L1 Hydrate from Shared (no SSR).
 *
 * Layer A (Shared inventory) stays Cron/Clear + cache.warmer.*.
 * Layer B runs inside each business Worker — CLI Cron cannot fill Worker bags.
 * Soft reclaim / READY-after schedule call {@see request()} so Workers re-hydrate.
 *
 * Fail-open; never invents FPC HIT; never clears Shared.
 */
final class StorefrontProcessL1HydrateCoordinator
{
    private static bool $pendingRequest = false;

    private static string $pendingReason = '';

    private static float $notBefore = 0.0;

    /** Drop reclaim storms within this window after a reclaim request is accepted. */
    private static float $reclaimCoalesceUntil = 0.0;

    /**
     * 同因失败退避状态（每 Worker 一进程）。
     *
     * 场景：homepage 已命中但 chrome bags 仍 shared_miss/key_unresolved 时，Layer B
     * 会每 30s 重试一次；若失败原因逐字不变（bag_errors 指纹相同），重试永远不会
     * 成功却无限期刷日志、重复重算 key。这里把「同因连续失败」记成指纹，
     * 超过基础次数后指数退避（封顶 300s）。**指纹一变就立刻回到基础间隔**，
     * 保证 Layer A 把 chrome Shared 填上后非 owner Worker 能马上拉到。
     */
    private static string $lastFailureFingerprint = '';

    private static int $sameFailureStreak = 0;

    private static float $backoffSeconds = 0.0;

    /**
     * 记录一轮失败的指纹，返回本轮应使用的重试间隔（秒）。
     *
     * @param string $fingerprint 失败指纹（reason + bag_errors 的 hash）；'' 表示非同类失败
     * @param float $baseSeconds 基础间隔，默认 30s（与既有 Layer B 节流一致）
     * @param int $graceStreak 保持基础间隔的次数，超过后才开始退避
     */
    public static function observeFailure(
        string $fingerprint,
        float $baseSeconds = 30.0,
        int $graceStreak = 3,
    ): float {
        if ($fingerprint === '' || $fingerprint !== self::$lastFailureFingerprint) {
            // 原因变化（含 Layer A 补齐后的 shared_hit → 无 error）→ 立即恢复基础间隔。
            self::$lastFailureFingerprint = $fingerprint;
            self::$sameFailureStreak = $fingerprint === '' ? 0 : 1;
            self::$backoffSeconds = 0.0;

            return $baseSeconds;
        }
        ++self::$sameFailureStreak;
        if (self::$sameFailureStreak <= $graceStreak) {
            return $baseSeconds;
        }
        self::$backoffSeconds = \min(
            300.0,
            $baseSeconds * (2 ** (self::$sameFailureStreak - $graceStreak)),
        );

        return self::$backoffSeconds;
    }

    /** 当前记录的失败指纹（'' = 尚无失败或最近一轮成功）。 */
    public static function lastFailureFingerprint(): string
    {
        return self::$lastFailureFingerprint;
    }

    /** 当前退避秒数（0 = 未进入退避）。 */
    public static function currentBackoffSeconds(): float
    {
        return self::$backoffSeconds;
    }

    /** 某一轮成功后清零退避状态。 */
    public static function resetFailureBackoff(): void
    {
        self::$lastFailureFingerprint = '';
        self::$sameFailureStreak = 0;
        self::$backoffSeconds = 0.0;
    }

    /**
     * Ask the current Worker to run Layer B soon (idle Fiber).
     * Cross-process CLI callers must not pretend bags are filled.
     */
    public static function request(string $reason = 'manual'): void
    {
        self::$pendingRequest = true;
        self::$pendingReason = \substr(\trim($reason), 0, 120);
        self::$notBefore = 0.0;
    }

    public static function requestAfterReclaim(string $reason = 'soft_reclaim'): void
    {
        $reason = $reason !== '' ? $reason : 'soft_reclaim';
        // Soft reclaim can fire every response under memory trim; coalesce so Layer B
        // idle Fiber does not spin Shared→Process peeks every few seconds.
        $now = \microtime(true);
        if ($now < self::$reclaimCoalesceUntil) {
            return;
        }
        self::$reclaimCoalesceUntil = $now + 12.0;
        self::request($reason);
    }

    public static function hasPendingRequest(): bool
    {
        return self::$pendingRequest && \microtime(true) >= self::$notBefore;
    }

    public static function pendingReason(): string
    {
        return self::$pendingReason;
    }

    /**
     * @return array{taken:bool,reason:string}
     */
    public static function takePendingRequest(): array
    {
        if (!self::hasPendingRequest()) {
            return ['taken' => false, 'reason' => ''];
        }
        $reason = self::$pendingReason;
        self::$pendingRequest = false;
        self::$pendingReason = '';

        return ['taken' => true, 'reason' => $reason];
    }

    public static function deferRetry(float $seconds = 5.0): void
    {
        $seconds = \max(0.5, \min(60.0, $seconds));
        self::$pendingRequest = true;
        if (self::$pendingReason === '') {
            self::$pendingReason = 'retry_after_shared_miss';
        }
        self::$notBefore = \microtime(true) + $seconds;
    }

    public static function resetForTests(): void
    {
        self::$pendingRequest = false;
        self::$pendingReason = '';
        self::$notBefore = 0.0;
        self::$reclaimCoalesceUntil = 0.0;
        self::resetFailureBackoff();
    }

    public static function isEnabled(): bool
    {
        $raw = \getenv('WLS_WORKER_PROCESS_L1_HYDRATE_ENABLED');
        if ($raw === false || \trim((string)$raw) === '') {
            $raw = Env::get('wls.worker.process_l1_hydrate_enabled', '1');
        }

        return \in_array(
            \strtolower(\trim((string)$raw)),
            ['1', 'true', 'yes', 'on'],
            true,
        );
    }

    /**
     * Invoke every bag Provider in hydrate-only mode (Product before Theme = MRU chrome).
     *
     * @return array{
     *   seeded:int,
     *   peeked:int,
     *   bags:list<string>,
     *   errors:list<string>,
     *   providers:int
     * }
     */
    public function hydrateBags(): array
    {
        $seeded = 0;
        $peeked = 0;
        $bags = [];
        $errors = [];
        $providers = 0;

        $implementations = [];
        try {
            $registry = ObjectManager::getInstance(\Weline\Framework\Compilation\ServiceProviderRegistry::class);
            foreach ($registry->implementationsWithPrefix(
                StorefrontHotCacheBagWarmupProviderInterface::CAPABILITY_PREFIX
            ) as $capability => $implementation) {
                if (\is_string($implementation) && $implementation !== '') {
                    $implementations[(string)$capability] = $implementation;
                }
            }
        } catch (\Throwable $e) {
            $errors[] = 'registry:' . $e->getMessage();
        }

        foreach ([
            'storefront_hot_cache_bag_warmup.Weline_Product'
                => 'Weline\\Product\\Api\\Runtime\\StorefrontHotCacheBagWarmupProvider',
            'storefront_hot_cache_bag_warmup.Weline_Theme'
                => 'Weline\\Theme\\Api\\Runtime\\StorefrontHotCacheBagWarmupProvider',
        ] as $capability => $implementation) {
            if (!isset($implementations[$capability]) && \class_exists($implementation)) {
                $implementations[$capability] = $implementation;
            }
        }

        $ordered = [];
        foreach ([
            'storefront_hot_cache_bag_warmup.Weline_Product',
            'storefront_hot_cache_bag_warmup.Weline_Theme',
        ] as $capability) {
            if (isset($implementations[$capability])) {
                $ordered[$capability] = $implementations[$capability];
                unset($implementations[$capability]);
            }
        }
        foreach ($implementations as $capability => $implementation) {
            $ordered[$capability] = $implementation;
        }

        foreach ($ordered as $capability => $implementation) {
            if (!\is_string($implementation)
                || !\class_exists($implementation)
                || !\is_subclass_of($implementation, StorefrontHotCacheBagWarmupProviderInterface::class)
            ) {
                continue;
            }
            $providers++;
            try {
                $provider = ObjectManager::getInstance($implementation);
                if (!$provider instanceof StorefrontHotCacheBagWarmupProviderInterface) {
                    continue;
                }
                $result = $provider->hydrateCriticalBagsFromShared();
                $seeded += (int)($result['seeded'] ?? 0);
                $peeked += (int)($result['peeked'] ?? 0);
                if ($seeded > 0) {
                    // Hydrate contract forbids seed; surface as error for diagnostics.
                    $errors[] = (string)$capability . ':hydrate_seeded_unexpected=' . $seeded;
                    $seeded = 0;
                }
                foreach (\is_array($result['bags'] ?? null) ? $result['bags'] : [] as $bag) {
                    $bag = \trim((string)$bag);
                    if ($bag !== '') {
                        $bags[$bag] = $bag;
                    }
                }
                foreach (\is_array($result['errors'] ?? null) ? $result['errors'] : [] as $error) {
                    $errors[] = (string)$capability . ':' . (string)$error;
                }
            } catch (\Throwable $e) {
                $errors[] = (string)$capability . ':' . $e->getMessage();
            }
            try {
                SchedulerSystem::yield();
            } catch (\Throwable) {
            }
        }

        if ($providers === 0) {
            $errors[] = 'no_bag_hydrate_providers';
        }

        return [
            'seeded' => 0,
            'peeked' => $peeked,
            'bags' => \array_values($bags),
            'errors' => \array_slice($errors, 0, 12),
            'providers' => $providers,
        ];
    }
}
