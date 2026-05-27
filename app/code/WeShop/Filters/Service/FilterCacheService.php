<?php

declare(strict_types=1);

namespace WeShop\Filters\Service;

use WeShop\Filters\Api\FilterResultInterface;
use WeShop\Filters\Model\FilterCache;
use Weline\Framework\Cache\KeyBuilder;
use Weline\Framework\Manager\ObjectManager;

/**
 * 筛选缓存服务
 */
class FilterCacheService
{
    /**
     * @var string 缓存标识前缀
     */
    private const CACHE_PREFIX = 'weshop_filter_';
    
    /**
     * @var int 默认缓存时间（秒）
     */
    private const DEFAULT_TTL = 3600;
    
    /**
     * @var bool 是否启用缓存
     */
    private bool $enabled = true;
    
    /**
     * @var int 缓存时间
     */
    private int $ttl;
    
    /**
     * @var array 内存缓存
     */
    private array $memoryCache = [];
    
    public function __construct(int $ttl = self::DEFAULT_TTL)
    {
        $this->ttl = $ttl;
    }
    
    /**
     * 生成缓存键
     * 
     * @param int $categoryId
     * @param array $filterParams
     * @return string
     */
    public function generateCacheKey(int $categoryId, array $filterParams): string
    {
        // 对筛选参数排序以确保相同参数生成相同的键
        ksort($filterParams);
        foreach ($filterParams as $key => $value) {
            if (is_array($value)) {
                sort($value);
                $filterParams[$key] = $value;
            }
        }
        
        $environmentHash = KeyBuilder::environmentHash([
            'scope' => 'filters-result',
            'category_id' => $categoryId,
        ]);
        $paramString = json_encode($filterParams);
        return self::CACHE_PREFIX . $categoryId . '_' . $environmentHash . '_' . md5((string)$paramString);
    }
    
    /**
     * 获取缓存
     * 
     * @param string $cacheKey
     * @return FilterResultInterface|null
     */
    public function get(string $cacheKey): ?FilterResultInterface
    {
        if (!$this->enabled) {
            return null;
        }
        
        // 先检查内存缓存
        if (isset($this->memoryCache[$cacheKey])) {
            return $this->memoryCache[$cacheKey];
        }
        
        // 尝试从数据库缓存获取
        try {
            /** @var FilterCache $cacheModel */
            $cacheModel = ObjectManager::getInstance(FilterCache::class);
            $cached = $cacheModel->getCacheData($cacheKey);
            
            if ($cached !== null) {
                $this->memoryCache[$cacheKey] = $cached;
                return $cached;
            }
        } catch (\Throwable $e) {
            // 缓存获取失败，返回null
        }
        
        return null;
    }
    
    /**
     * 设置缓存
     * 
     * @param string $cacheKey
     * @param FilterResultInterface $result
     * @return bool
     */
    public function set(string $cacheKey, FilterResultInterface $result): bool
    {
        if (!$this->enabled) {
            return false;
        }
        
        // 设置内存缓存
        $this->memoryCache[$cacheKey] = $result;
        
        // 保存到数据库缓存
        try {
            /** @var FilterCache $cacheModel */
            $cacheModel = ObjectManager::getInstance(FilterCache::class);
            return $cacheModel->setCacheData($cacheKey, $result, $this->ttl);
        } catch (\Throwable $e) {
            return false;
        }
    }
    
    /**
     * 删除缓存
     * 
     * @param string $cacheKey
     * @return bool
     */
    public function delete(string $cacheKey): bool
    {
        unset($this->memoryCache[$cacheKey]);
        
        try {
            /** @var FilterCache $cacheModel */
            $cacheModel = ObjectManager::getInstance(FilterCache::class);
            return $cacheModel->deleteCacheData($cacheKey);
        } catch (\Throwable $e) {
            return false;
        }
    }
    
    /**
     * 清除分类的所有筛选缓存
     * 
     * @param int $categoryId
     * @return bool
     */
    public function clearByCategoryId(int $categoryId): bool
    {
        // 清除内存缓存
        foreach (array_keys($this->memoryCache) as $key) {
            if (strpos($key, self::CACHE_PREFIX . $categoryId . '_') === 0) {
                unset($this->memoryCache[$key]);
            }
        }
        
        try {
            /** @var FilterCache $cacheModel */
            $cacheModel = ObjectManager::getInstance(FilterCache::class);
            return $cacheModel->clearByCategoryId($categoryId);
        } catch (\Throwable $e) {
            return false;
        }
    }
    
    /**
     * 清除所有筛选缓存
     * 
     * @return bool
     */
    public function clearAll(): bool
    {
        $this->memoryCache = [];
        
        try {
            /** @var FilterCache $cacheModel */
            $cacheModel = ObjectManager::getInstance(FilterCache::class);
            return $cacheModel->clearAllCache();
        } catch (\Throwable $e) {
            return false;
        }
    }
    
    /**
     * 清除过期缓存
     * 
     * @return int 清除的缓存数量
     */
    public function clearExpired(): int
    {
        try {
            /** @var FilterCache $cacheModel */
            $cacheModel = ObjectManager::getInstance(FilterCache::class);
            return $cacheModel->clearExpiredCache();
        } catch (\Throwable $e) {
            return 0;
        }
    }
    
    /**
     * 启用缓存
     * 
     * @return self
     */
    public function enable(): self
    {
        $this->enabled = true;
        return $this;
    }
    
    /**
     * 禁用缓存
     * 
     * @return self
     */
    public function disable(): self
    {
        $this->enabled = false;
        return $this;
    }
    
    /**
     * 检查缓存是否启用
     * 
     * @return bool
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }
    
    /**
     * 设置缓存时间
     * 
     * @param int $ttl
     * @return self
     */
    public function setTtl(int $ttl): self
    {
        $this->ttl = $ttl;
        return $this;
    }
    
    /**
     * 获取缓存时间
     * 
     * @return int
     */
    public function getTtl(): int
    {
        return $this->ttl;
    }
}
