<?php

declare(strict_types=1);

/**
 * Remember 能力接口（ISP: 可选能力）
 *
 * 提供「读取或重建」的标准回源能力，统一处理：
 * - 缓存穿透（null 哨兵）
 * - 缓存击穿（single-flight）
 * - 缓存雪崩（TTL 抖动 — 由 CachePool::set 内部处理）
 *
 * 实现者通常是 CachePool 的具体类，但允许独立实现以便测试或专用场景。
 *
 * @author Aiweline
 * @email aiweline@qq.com
 */

namespace Weline\Framework\Cache\Contract;

interface RemembererInterface
{
    /**
     * 读取缓存；未命中时通过 single-flight 协调执行回调并写回。
     *
     * @param string $key 缓存键
     * @param int $ttl 正常 TTL（秒）。0 表示沿用池默认
     * @param callable():mixed $callback 未命中时执行的回源回调
     * @param RememberOptions|null $options 行为选项；null 表示使用框架默认
     * @return mixed 缓存值；若回调返回 null，本次返回 null 但会写入空值哨兵
     */
    public function remember(string $key, int $ttl, callable $callback, ?RememberOptions $options = null): mixed;
}
