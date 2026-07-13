<?php

declare(strict_types=1);

/**
 * 热点 Key 跟踪器
 *
 * 基于 1 秒滑动窗口（共 N 个桶）统计每个 (identity, key) 的访问 QPS。
 * 当窗口内总命中数 >= 阈值 → 标记为热点。
 *
 * 存储策略：
 * - 当前实现使用进程内静态数组；跨 Worker 协调由运行时缓存适配器负责
 * - CLI/FPM 仅在当前进程内有效
 *
 * 设计意图：
 * - O(1) touch / O(1) isHot 查询
 * - 窗口超过后自动失效，无需 GC 线程
 * - 存量 key 数量限制为 maxTrackedKeys，超过则淘汰最早项
 *
 * @author Aiweline
 * @email aiweline@qq.com
 */

namespace Weline\Framework\Cache\Service;

use Weline\Framework\Cache\Contract\HotKeyAwareInterface;

class HotKeyTracker implements HotKeyAwareInterface
{
    /**
     * 热点统计存储：(identity:key) => array{first_seen:int, last_seen:int, hits:int}
     *
     * @var array<string, array{first_seen:int, last_seen:int, hits:int}>
     */
    private static array $stats = [];

    public function __construct(
        /** 触发热点的命中阈值（窗口内累计） */
        private int $threshold = 100,
        /** 滑动窗口时长（秒） */
        private int $windowSeconds = 60,
        /** 最大跟踪键数（超过则按 last_seen 升序淘汰） */
        private int $maxTrackedKeys = 5000
    ) {
    }

    public function touch(string $identity, string $key): void
    {
        $now = \time();
        $bucket = $this->bucketKey($identity, $key);

        if (isset(self::$stats[$bucket])) {
            $entry = self::$stats[$bucket];
            if (($now - $entry['first_seen']) > $this->windowSeconds) {
                self::$stats[$bucket] = ['first_seen' => $now, 'last_seen' => $now, 'hits' => 1];
                return;
            }

            $entry['last_seen'] = $now;
            $entry['hits']++;
            self::$stats[$bucket] = $entry;
            return;
        }

        if (\count(self::$stats) >= $this->maxTrackedKeys) {
            $this->evictOldest();
        }

        self::$stats[$bucket] = ['first_seen' => $now, 'last_seen' => $now, 'hits' => 1];
    }

    public function isHot(string $identity, string $key): bool
    {
        return $this->getHits($identity, $key) >= $this->threshold;
    }

    public function getHits(string $identity, string $key): int
    {
        $bucket = $this->bucketKey($identity, $key);
        $entry = self::$stats[$bucket] ?? null;
        if ($entry === null) {
            return 0;
        }

        if ((\time() - $entry['first_seen']) > $this->windowSeconds) {
            unset(self::$stats[$bucket]);
            return 0;
        }

        return $entry['hits'];
    }

    public function listHotKeys(int $limit = 50): array
    {
        $now = \time();
        $rows = [];
        foreach (self::$stats as $bucket => $entry) {
            if (($now - $entry['first_seen']) > $this->windowSeconds) {
                unset(self::$stats[$bucket]);
                continue;
            }

            if ($entry['hits'] < $this->threshold) {
                continue;
            }

            [$identity, $key] = $this->splitBucket($bucket);
            $rows[] = ['identity' => $identity, 'key' => $key, 'hits' => $entry['hits']];
        }

        \usort($rows, static fn(array $a, array $b): int => $b['hits'] <=> $a['hits']);

        if ($limit > 0 && \count($rows) > $limit) {
            $rows = \array_slice($rows, 0, $limit);
        }
        return $rows;
    }

    /**
     * 重置（仅供测试使用）。
     */
    public static function reset(): void
    {
        self::$stats = [];
    }

    private function bucketKey(string $identity, string $key): string
    {
        return $identity . "\x1F" . $key;
    }

    /**
     * @return array{0:string, 1:string}
     */
    private function splitBucket(string $bucket): array
    {
        $pos = \strpos($bucket, "\x1F");
        if ($pos === false) {
            return ['', $bucket];
        }

        return [\substr($bucket, 0, $pos), \substr($bucket, $pos + 1)];
    }

    private function evictOldest(): void
    {
        $oldestKey = null;
        $oldestTs = PHP_INT_MAX;
        foreach (self::$stats as $bucket => $entry) {
            if ($entry['last_seen'] < $oldestTs) {
                $oldestTs = $entry['last_seen'];
                $oldestKey = $bucket;
            }
        }

        if ($oldestKey !== null) {
            unset(self::$stats[$oldestKey]);
        }
    }
}
