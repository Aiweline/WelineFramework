<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：2025/01/12
 */

namespace Weline\UrlManager\Cache;

use Weline\Framework\Cache\CacheFactory;
use Weline\Framework\Cache\CacheInterface;

/**
 * URL重写缓存类
 * 
 * 职责：
 * - 管理URL重写缓存业务逻辑
 * - 实现缓存淘汰策略（防止攻击者填满磁盘）
 * - 不关心具体缓存驱动（文件、Redis等由配置决定）
 * 
 * 遵循SOLID原则：
 * - 单一职责：只负责URL重写缓存业务逻辑
 * - 开闭原则：对扩展开放（可替换缓存驱动），对修改封闭
 * - 依赖倒置：依赖CacheInterface抽象，不依赖具体实现
 */
class UrlRewriteCache
{
    // 缓存配置常量
    private const CACHE_MAX_ITEMS = 10000; // 最大缓存条目数（防止攻击）
    private const CACHE_TTL_FOUND = 3600; // 找到的URL缓存1小时
    private const CACHE_TTL_NOT_FOUND = 300; // 未找到的URL缓存5分钟（避免重复查询）
    private const CACHE_CLEANUP_PROBABILITY = 100; // 清理概率（1/100，即1%的概率触发清理）
    private const CACHE_CLEANUP_THRESHOLD = 1.5; // 清理阈值（超过最大条目数的倍数）
    
    // 缓存键前缀
    private const CACHE_KEY_PREFIX = 'rewrite_';
    
    /**
     * @var CacheInterface 缓存驱动实例（由配置决定，可能是文件、Redis等）
     */
    private CacheInterface $cache;
    
    /**
     * @var CacheFactory 缓存工厂
     */
    private CacheFactory $cacheFactory;
    
    /**
     * 请求内静态缓存（避免同一请求内重复查询）
     * 
     * @var array<string, array{path: string}|null>
     */
    private static array $staticCache = [];
    
    /**
     * 构造函数
     * 
     * 注意：无参数构造函数，避免 ObjectManager 自动注入错误的依赖类型
     * 缓存工厂在内部创建，确保类型正确
     */
    public function __construct()
    {
        $this->cacheFactory = new CacheFactory('url_rewrite_cache', 'URL重写缓存', true);
        $this->cache = $this->cacheFactory->create();
    }
    
    /**
     * 获取URL重写缓存
     * 
     * @param string $uri URI路径
     * @return array{path: string}|null|false 返回重写路径（数组），如果缓存了"未找到"返回null，如果缓存未命中返回false
     */
    public function get(string $uri): array|null|false
    {
        // 第一层：请求内静态缓存（最快）
        $cacheKey1 = $uri;
        $cacheKey2 = '/' . $uri;
        
        if (array_key_exists($cacheKey1, self::$staticCache)) {
            return self::$staticCache[$cacheKey1];
        }
        
        if (array_key_exists($cacheKey2, self::$staticCache)) {
            return self::$staticCache[$cacheKey2];
        }
        
        // 第二层：文件缓存（跨请求缓存）
        // 定期清理缓存（防止攻击者填满磁盘）
        $this->cleanupIfNeeded();
        
        $fileCacheKey1 = self::CACHE_KEY_PREFIX . md5($cacheKey1);
        $fileCacheKey2 = self::CACHE_KEY_PREFIX . md5($cacheKey2);
        
        // 检查缓存是否存在
        if ($this->cache->exists($fileCacheKey1)) {
            $rewriteData = $this->cache->get($fileCacheKey1);
            // 同步到静态缓存
            self::$staticCache[$cacheKey1] = $rewriteData;
            return $rewriteData; // 可能是数组或null（缓存了"未找到"的结果）
        }
        
        if ($this->cache->exists($fileCacheKey2)) {
            $rewriteData = $this->cache->get($fileCacheKey2);
            // 同步到静态缓存
            self::$staticCache[$cacheKey2] = $rewriteData;
            return $rewriteData; // 可能是数组或null（缓存了"未找到"的结果）
        }
        
        // 缓存未命中
        return false;
    }
    
    /**
     * 设置URL重写缓存
     * 
     * @param string $uri URI路径
     * @param array{path: string}|null $rewriteData 重写数据，如果未找到传入null
     */
    public function set(string $uri, ?array $rewriteData): void
    {
        $cacheKey1 = $uri;
        $cacheKey2 = '/' . $uri;
        
        // 设置请求内静态缓存
        self::$staticCache[$cacheKey1] = $rewriteData;
        self::$staticCache[$cacheKey2] = $rewriteData;
        
        // 设置文件缓存
        $fileCacheKey1 = self::CACHE_KEY_PREFIX . md5($cacheKey1);
        $fileCacheKey2 = self::CACHE_KEY_PREFIX . md5($cacheKey2);
        
        // 根据是否找到设置不同的缓存时间
        $ttl = $rewriteData !== null ? self::CACHE_TTL_FOUND : self::CACHE_TTL_NOT_FOUND;
        
        $this->cache->set($fileCacheKey1, $rewriteData, $ttl);
        $this->cache->set($fileCacheKey2, $rewriteData, $ttl);
    }
    
    /**
     * 检查并清理缓存（防止攻击者填满磁盘）
     * 
     * 策略：
     * - 随机触发清理（1%概率），避免每次请求都检查
     * - 当缓存条目数超过限制时，清理整个缓存
     * - 异常处理：清理失败不影响主流程
     */
    private function cleanupIfNeeded(): void
    {
        // 随机触发清理（避免每次请求都检查，降低性能开销）
        if (rand(1, self::CACHE_CLEANUP_PROBABILITY) !== 1) {
            return;
        }
        
        try {
            // 获取缓存统计信息
            $stats = $this->cache->getStats();
            $itemCount = $stats['items'] ?? 0;
            
            // 如果缓存条目数超过限制，清理最旧的缓存
            if ($itemCount > self::CACHE_MAX_ITEMS * self::CACHE_CLEANUP_THRESHOLD) {
                // 清理策略：删除超过最大限制的条目
                // 由于文件缓存可能不支持直接获取所有键，我们使用更简单的方法：
                // 定期清理整个缓存（在缓存条目过多时）
                // 注意：这是一个保守的策略，实际应用中可以考虑更精细的LRU实现
                $this->cache->clear();
                
            }
        } catch (\Throwable $e) {
            // 忽略清理错误，不影响主流程
            // 在生产环境中可以记录错误日志
        }
    }
    
    /**
     * 清除所有缓存
     * 
     * @return bool
     */
    public function clear(): bool
    {
        self::$staticCache = [];
        return $this->cache->clear();
    }
    
    /**
     * 获取缓存统计信息
     * 
     * @return array
     */
    public function getStats(): array
    {
        return $this->cache->getStats();
    }
}
