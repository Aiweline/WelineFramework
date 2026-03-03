<?php

declare(strict_types=1);

/**
 * 缓存管理器接口（统一入口）
 * 
 * 提供缓存池的统一获取入口，支持全局标签失效和统计。
 * 
 * @author Aiweline
 * @email aiweline@qq.com
 */

namespace Weline\Framework\Cache\Contract;

interface CacheManagerInterface
{
    /**
     * 获取缓存池
     *
     * @param string $identity 池标识（如 router, config, database）
     * @return CachePoolInterface
     */
    public function pool(string $identity): CachePoolInterface;

    /**
     * 检查池是否存在
     *
     * @param string $identity 池标识
     * @return bool
     */
    public function hasPool(string $identity): bool;

    /**
     * 获取所有已注册的池标识
     *
     * @return array<string>
     */
    public function getPoolIdentities(): array;

    /**
     * 全局按标签失效
     *
     * @param string $tag 标签
     * @return void
     */
    public function invalidateTag(string $tag): void;

    /**
     * 清空所有缓存（非持久）
     *
     * @return void
     */
    public function clearAll(): void;

    /**
     * 强制清空所有缓存（包括持久）
     *
     * @return void
     */
    public function flushAll(): void;

    /**
     * 获取所有池的统计
     *
     * @return array<string, array>
     */
    public function getAllStats(): array;
}
