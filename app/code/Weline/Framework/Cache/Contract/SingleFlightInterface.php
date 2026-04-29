<?php

declare(strict_types=1);

/**
 * Single-flight 协调接口
 *
 * 保证同一 key 的并发回源在所有 Worker / 协程间最多由 1 个执行回调，
 * 其它请求等待并复用结果。用于防缓存击穿。
 *
 * 不同运行时（WLS/FPM/CLI）由不同实现承载：
 * - WLS：`WlsMemoryAdapter::compareAndSet` 原子占位
 * - FPM：基于文件锁 (flock)
 * - CLI：进程内数组锁（仅同进程有效）
 *
 * @author Aiweline
 * @email aiweline@qq.com
 */

namespace Weline\Framework\Cache\Contract;

interface SingleFlightInterface
{
    /**
     * 尝试在指定超时内获取 key 的执行权。
     *
     * @param string $key 协调键（通常 = 缓存键）
     * @param int $timeoutMs 等待超时（毫秒）。<=0 表示不等待
     * @param int $ttlSeconds 锁的最长持有时间（秒），用于防止持有者崩溃造成死锁
     * @return string|null 持有令牌（用于 release），无法获取返回 null
     */
    public function acquire(string $key, int $timeoutMs = 1500, int $ttlSeconds = 30): ?string;

    /**
     * 释放锁。释放后等待方应在下一轮重新读缓存而非立即调用回调。
     *
     * @param string $key 协调键
     * @param string $token acquire 时获得的令牌
     */
    public function release(string $key, string $token): void;
}
