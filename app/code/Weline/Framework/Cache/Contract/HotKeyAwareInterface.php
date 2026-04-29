<?php

declare(strict_types=1);

/**
 * 热点 Key 识别接口
 *
 * 在「滑动窗口 / 阈值」基础上识别高 QPS 缓存 key。
 * 上层调用方（CachePool::remember）在每次命中或回源时通知 tracker，
 * 通过 `isHot()` 决定是否触发热点处理回调（如写入 Redis 副本、加长 TTL）。
 *
 * @author Aiweline
 * @email aiweline@qq.com
 */

namespace Weline\Framework\Cache\Contract;

interface HotKeyAwareInterface
{
    /**
     * 通知一次访问。
     *
     * @param string $identity 池标识（用于隔离不同池的统计）
     * @param string $key 业务键
     */
    public function touch(string $identity, string $key): void;

    /**
     * 当前 key 是否为热点。
     */
    public function isHot(string $identity, string $key): bool;

    /**
     * 当前 key 在窗口内的访问次数（用于诊断/上报）。
     */
    public function getHits(string $identity, string $key): int;

    /**
     * 列出当前已识别的热点 key（按 QPS 降序）。
     *
     * @param int $limit 取前 N 条
     * @return array<int, array{identity:string, key:string, hits:int}>
     */
    public function listHotKeys(int $limit = 50): array;
}
