<?php

declare(strict_types=1);

/**
 * 内存存储接口（ISP: WLS 专用能力）
 * 
 * 提供内存缓存的管理能力，包括 LRU 淘汰、内存统计。
 * 只有 WLS 内存适配器需要实现此接口。
 * 
 * @author Aiweline
 * @email aiweline@qq.com
 */

namespace Weline\Framework\Cache\Contract;

interface MemoryStoreInterface
{
    /**
     * 获取内存使用量（字节）
     *
     * @return int
     */
    public function getMemoryUsage(): int;

    /**
     * 获取内存中的条目数
     *
     * @return int
     */
    public function getMemoryItemCount(): int;

    /**
     * 获取最大条目数限制
     *
     * @return int
     */
    public function getMaxItems(): int;

    /**
     * 获取最大内存限制（字节）
     *
     * @return int
     */
    public function getMaxMemory(): int;

    /**
     * 强制 LRU 淘汰
     *
     * @param int $count 淘汰条目数
     * @return int 实际淘汰数
     */
    public function evict(int $count): int;

    /**
     * 清空内存（不影响持久层）
     *
     * @return void
     */
    public function clearMemory(): void;

    /**
     * 预热：从持久层加载到内存
     *
     * @param int $limit 加载数量限制
     * @return int 加载数量
     */
    public function warmUp(int $limit = 1000): int;
}
