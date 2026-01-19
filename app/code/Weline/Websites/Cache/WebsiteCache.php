<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：2025/01/12
 */

namespace Weline\Websites\Cache;

use Weline\Framework\Cache\CacheFactory;
use Weline\Framework\Cache\CacheInterface;

/**
 * 网站探测缓存类
 * 
 * 职责：
 * - 管理网站探测缓存业务逻辑
 * - 实现缓存淘汰策略（防止攻击者填满磁盘）
 * - 不关心具体缓存驱动（文件、Redis等由配置决定）
 * 
 * 遵循SOLID原则：
 * - 单一职责：只负责网站探测缓存业务逻辑
 * - 开闭原则：对扩展开放（可替换缓存驱动），对修改封闭
 * - 依赖倒置：依赖CacheInterface抽象，不依赖具体实现
 */
class WebsiteCache
{
    // 缓存配置常量
    private const CACHE_MAX_ITEMS = 1000; // 最大缓存条目数（防止攻击）
    private const CACHE_TTL_FOUND = 3600; // 找到的网站缓存1小时
    private const CACHE_TTL_NOT_FOUND = 300; // 未找到的网站缓存5分钟（避免重复查询）
    private const CACHE_CLEANUP_PROBABILITY = 100; // 清理概率（1/100，即1%的概率触发清理）
    private const CACHE_CLEANUP_THRESHOLD = 1.5; // 清理阈值（超过最大条目数的倍数）
    
    // 缓存键前缀
    private const CACHE_KEY_PREFIX = 'website_';
    private const CACHE_KEY_ALL_SITES = 'website_all_sites';
    
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
     * @var array<string, array>|null
     */
    private static ?array $staticCache = null;
    
    /**
     * 构造函数
     * 
     * 注意：无参数构造函数，避免 ObjectManager 自动注入错误的依赖类型
     * 缓存工厂在内部创建，确保类型正确
     */
    public function __construct()
    {
        $this->cacheFactory = new CacheFactory('website_cache', '网站探测缓存', true);
        $this->cache = $this->cacheFactory->create();
    }
    
    /**
     * 获取所有网站列表缓存
     * 
     * @return array|null 返回网站数组，如果缓存未命中返回null
     */
    public function getAllSites(): ?array
    {
        // 第一层：请求内静态缓存（最快）
        if (self::$staticCache !== null) {
            return self::$staticCache;
        }
        
        // 第二层：文件缓存（跨请求缓存）
        $this->cleanupIfNeeded();
        
        if ($this->cache->exists(self::CACHE_KEY_ALL_SITES)) {
            $sites = $this->cache->get(self::CACHE_KEY_ALL_SITES);
            self::$staticCache = $sites;
            return $sites;
        }
        
        // 缓存未命中
        return null;
    }
    
    /**
     * 设置所有网站列表缓存
     * 
     * @param array $sites 网站数组
     */
    public function setAllSites(array $sites): void
    {
        // 设置请求内静态缓存
        self::$staticCache = $sites;
        
        // 设置文件缓存
        $this->cache->set(self::CACHE_KEY_ALL_SITES, $sites, self::CACHE_TTL_FOUND);
    }
    
    /**
     * 根据URL获取网站缓存（最长匹配）
     * 
     * @param string $url URL地址
     * @return array|null 返回网站数据，如果缓存未命中返回null
     */
    public function getByUrl(string $url): ?array
    {
        // 第一层：请求内静态缓存（最快）
        // 使用最长匹配策略
        if (self::$staticCache !== null) {
            $matchedSite = null;
            $maxLength = 0;
            foreach (self::$staticCache as $site) {
                if (isset($site['url']) && str_starts_with($url, $site['url'])) {
                    $siteUrlLength = strlen($site['url']);
                    if ($siteUrlLength > $maxLength) {
                        $maxLength = $siteUrlLength;
                        $matchedSite = $site;
                    }
                }
            }
            if ($matchedSite !== null) {
                return $matchedSite;
            }
        }
        
        // 第二层：文件缓存（跨请求缓存）
        $this->cleanupIfNeeded();
        
        $cacheKey = self::CACHE_KEY_PREFIX . md5($url);
        if ($this->cache->exists($cacheKey)) {
            $site = $this->cache->get($cacheKey);
            return $site;
        }
        
        // 缓存未命中
        return null;
    }
    
    /**
     * 设置URL对应的网站缓存
     * 
     * @param string $url URL地址
     * @param array|null $site 网站数据，如果未找到传入null
     */
    public function setByUrl(string $url, ?array $site): void
    {
        $cacheKey = self::CACHE_KEY_PREFIX . md5($url);
        $ttl = ($site !== null) ? self::CACHE_TTL_FOUND : self::CACHE_TTL_NOT_FOUND;
        
        $this->cache->set($cacheKey, $site, $ttl);
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
            
            // 如果缓存条目数超过限制，清理整个缓存
            if ($itemCount > self::CACHE_MAX_ITEMS * self::CACHE_CLEANUP_THRESHOLD) {
                $this->cache->clear();
            }
        } catch (\Throwable $e) {
            // 忽略清理错误，不影响主流程
        }
    }
    
    /**
     * 清除所有缓存
     * 
     * @return bool
     */
    public function clear(): bool
    {
        self::$staticCache = null;
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
