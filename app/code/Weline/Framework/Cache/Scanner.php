<?php

declare(strict_types=1);

/**
 * 缓存扫描器
 * 
 * 基于新的 CacheManager 架构扫描缓存池。
 * 
 * @author Aiweline
 * @email aiweline@qq.com
 */

namespace Weline\Framework\Cache;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Cache\Contract\CachePoolInterface;

class Scanner
{
    public const dir = 'Cache';

    private CacheManager $cacheManager;

    public function __construct(?CacheManager $cacheManager = null)
    {
        $this->cacheManager = $cacheManager ?? ObjectManager::getInstance(CacheManager::class);
    }

    /**
     * 获取所有缓存池信息
     *
     * @return array
     */
    public function getCaches(): array
    {
        $pools = [];
        $identities = $this->cacheManager->getPoolIdentities();
        
        foreach ($identities as $identity) {
            $pool = $this->cacheManager->pool($identity);
            $pools[] = $this->buildPoolInfo($pool);
        }
        
        return [
            'pools' => $pools,
            'total' => count($pools),
        ];
    }

    /**
     * 获取缓存池列表（用于后台管理）
     *
     * @return array
     */
    public function getPoolList(): array
    {
        $list = [];
        $identities = $this->cacheManager->getPoolIdentities();
        
        foreach ($identities as $identity) {
            $pool = $this->cacheManager->pool($identity);
            $stats = $pool->getStats();
            
            $list[] = [
                'identity' => $identity,
                'tip' => $stats['tip'] ?? '',
                'permanent' => $stats['permanent'] ?? false,
                'default_ttl' => $stats['default_ttl'] ?? 1800,
                'hits' => $stats['hits'] ?? 0,
                'misses' => $stats['misses'] ?? 0,
                'hit_ratio' => $stats['hit_ratio'] ?? 0,
                'adapter' => $stats['adapter'] ?? '',
            ];
        }
        
        return $list;
    }

    /**
     * 构建缓存池信息
     *
     * @param CachePoolInterface $pool
     * @return array
     */
    private function buildPoolInfo(CachePoolInterface $pool): array
    {
        return [
            'identity' => $pool->getIdentity(),
            'tip' => $pool->getTip(),
            'permanent' => $pool->isPermanent(),
            'stats' => $pool->getStats(),
        ];
    }

    /**
     * 清空指定缓存池
     *
     * @param string $identity
     * @return bool
     */
    public function clearPool(string $identity): bool
    {
        if (!$this->cacheManager->hasPool($identity)) {
            return false;
        }
        
        return $this->cacheManager->pool($identity)->clear();
    }

    /**
     * 清空所有非持久缓存
     *
     * @return int 清空的池数量
     */
    public function clearAll(): int
    {
        $count = 0;
        $identities = $this->cacheManager->getPoolIdentities();
        
        foreach ($identities as $identity) {
            $pool = $this->cacheManager->pool($identity);
            if (!$pool->isPermanent()) {
                $pool->clear();
                $count++;
            }
        }
        
        return $count;
    }

    /**
     * 强制清空所有缓存（包括持久缓存）
     *
     * @return int 清空的池数量
     */
    public function flushAll(): int
    {
        $count = 0;
        $identities = $this->cacheManager->getPoolIdentities();
        
        foreach ($identities as $identity) {
            $this->cacheManager->pool($identity)->clear();
            $count++;
        }
        
        return $count;
    }

    /**
     * 获取所有统计信息
     *
     * @return array
     */
    public function getAllStats(): array
    {
        return $this->cacheManager->getAllStats();
    }
}
