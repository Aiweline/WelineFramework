<?php

declare(strict_types=1);

/**
 * 缓存工厂接口（兼容层）
 * 
 * 提供旧 CacheFactory 模式的兼容接口，内部桥接到新的 CacheManager/CachePool 架构。
 * 
 * @deprecated 推荐直接使用 CacheManager::pool() 获取 CachePoolInterface
 * @author Aiweline
 * @email aiweline@qq.com
 */

namespace Weline\Framework\Cache;

use Weline\Framework\Cache\Contract\CachePoolInterface;

interface CacheFactoryInterface
{
    /**
     * 创建/获取缓存池实例
     *
     * @param string $driver 缓存驱动（可选，使用默认驱动）
     * @param string $tip 缓存说明
     * @return CachePoolInterface
     */
    public function create(string $driver = '', string $tip = ''): CachePoolInterface;

    /**
     * 是否为持久缓存（需要 -f 强制清理）
     *
     * @return bool
     */
    public function isKeep(): bool;

    /**
     * 获取缓存标识
     *
     * @return string
     */
    public function getIdentity(): string;
}
