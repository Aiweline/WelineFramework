<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：2025/01/XX
 */

namespace Weline\Framework\Router\Cache;

use Weline\Framework\App\Env;
use Weline\Framework\Cache\CacheFactory;
use Weline\Framework\Cache\CacheInterface;

/**
 * URL处理缓存类
 * 
 * 职责：
 * - 管理URL处理结果缓存业务逻辑
 * - 实现缓存淘汰策略（防止攻击者填满磁盘）
 * - 不关心具体缓存驱动（文件、Redis等由配置决定）
 * 
 * 遵循SOLID原则：
 * - 单一职责：只负责URL处理缓存业务逻辑
 * - 开闭原则：对扩展开放（可替换缓存驱动），对修改封闭
 * - 依赖倒置：依赖CacheInterface抽象，不依赖具体实现
 */
class ProcessUrlCache
{
    // 缓存配置常量
    private const CACHE_MAX_ITEMS = 10000; // 最大缓存条目数（URL种类较多，设置较大值）
    private const CACHE_TTL_FOUND = 3600; // 找到的URL缓存1小时（URL处理结果变化较少）
    private const CACHE_TTL_NOT_FOUND = 300; // 未找到的URL缓存5分钟（避免重复处理）
    private const CACHE_CLEANUP_PROBABILITY = 100; // 清理概率（1/100，即1%的概率触发清理）
    private const CACHE_CLEANUP_THRESHOLD = 1.5; // 清理阈值（超过最大条目数的倍数）
    
    // 缓存键前缀
    private const CACHE_KEY_PREFIX = 'process_url_';
    
    /**
     * @var CacheInterface|null 缓存驱动实例（由配置决定，可能是文件、Redis等）
     *                         延迟初始化
     */
    private ?CacheInterface $cache = null;
    
    /**
     * @var CacheFactory|null 缓存工厂
     *                       延迟初始化
     */
    private ?CacheFactory $cacheFactory = null;
    
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
     * 缓存工厂延迟初始化，只有在缓存启用时才创建
     */
    public function __construct()
    {
        // 延迟初始化：只有在缓存启用时才创建缓存对象
        // 这样可以避免在缓存禁用时创建不必要的缓存对象
    }
    
    /**
     * 获取缓存实例（延迟初始化）
     * 
     * 注意：框架的 CacheFactory 和 CacheDriver 已经内置了状态检查机制
     * 当 cache.status.process_url_cache = 0 时，驱动类的 get/set/exists 方法会自动返回 false
     * 因此这里不需要手动检查配置，直接创建缓存对象即可
     * 
     * @return CacheInterface
     */
    private function getCache(): CacheInterface
    {
        // 延迟初始化缓存对象
        // 框架的 CacheFactory 会自动读取 cache.status.process_url_cache 配置
        // 并传递给驱动类，驱动类会在 get/set/exists 等方法中自动检查状态
        if ($this->cache === null) {
            $this->cacheFactory = new CacheFactory('process_url_cache', 'URL处理缓存', true);
            $this->cache = $this->cacheFactory->create();
        }
        
        return $this->cache;
    }
    
    /**
     * 获取URL处理结果缓存
     * 
     * @param string $cacheKey 缓存键
     * @return array|null 返回处理结果数组，如果缓存未命中返回null
     *                   返回格式：['url' => string, 'rule' => array, 'generated_get_params' => array]
     */
    public function getProcessedUrl(string $cacheKey): ?array
    {
        // 第一层：请求内静态缓存（最快）
        // 注意：静态缓存不受配置控制，因为它是请求内的临时缓存
        if (self::$staticCache !== null && isset(self::$staticCache[$cacheKey])) {
            return self::$staticCache[$cacheKey];
        }
        
        // 第二层：文件缓存（跨请求缓存）
        // 框架的缓存驱动会自动检查 cache.status.process_url_cache 配置
        // 如果禁用，exists() 和 get() 方法会自动返回 false
        $cache = $this->getCache();
        $this->cleanupIfNeeded();
        
        $fullCacheKey = self::CACHE_KEY_PREFIX . $cacheKey;
        if ($cache->exists($fullCacheKey)) {
            $result = $cache->get($fullCacheKey);
            // 如果缓存被禁用，get() 会返回 false，需要检查
            if ($result === false) {
                return null;
            }
            // 设置请求内静态缓存
            if (self::$staticCache === null) {
                self::$staticCache = [];
            }
            self::$staticCache[$cacheKey] = $result;
            return $result;
        }
        
        // 缓存未命中
        return null;
    }
    
    /**
     * 设置URL处理结果缓存
     * 
     * @param string $cacheKey 缓存键
     * @param string $url 处理后的URL
     * @param array $rule 路由规则
     * @param array $generatedGetParams 生成的GET参数
     * @param bool $found 是否找到路由（影响TTL）
     */
    public function setProcessedUrl(string $cacheKey, string $url, array $rule, array $generatedGetParams, bool $found = true): void
    {
        $cache = $this->getCache();
        $fullCacheKey = self::CACHE_KEY_PREFIX . $cacheKey;
        $ttl = $found ? self::CACHE_TTL_FOUND : self::CACHE_TTL_NOT_FOUND;
        
        $cacheData = [
            'url' => $url,
            'rule' => $rule,
            'generated_get_params' => $generatedGetParams,
        ];
        
        // 设置请求内静态缓存（请求内缓存不受配置控制）
        if (self::$staticCache === null) {
            self::$staticCache = [];
        }
        self::$staticCache[$cacheKey] = $cacheData;
        
        // 设置文件缓存
        // 框架的缓存驱动会自动检查 cache.status.process_url_cache 配置
        // 如果禁用，set() 方法会自动返回 false，不会实际保存
        $cache->set($fullCacheKey, $cacheData, $ttl);
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
        $cache = $this->getCache();
        
        // 随机触发清理（避免每次请求都检查，降低性能开销）
        if (rand(1, self::CACHE_CLEANUP_PROBABILITY) !== 1) {
            return;
        }
        
        try {
            // 获取缓存统计信息
            // 如果缓存被禁用，getStats() 可能返回空统计，但不会报错
            $stats = $cache->getStats();
            $itemCount = $stats['items'] ?? 0;
            
            // 如果缓存条目数超过限制，清理整个缓存
            if ($itemCount > self::CACHE_MAX_ITEMS * self::CACHE_CLEANUP_THRESHOLD) {
                $cache->clear();
                // 同时清理请求内静态缓存
                self::$staticCache = null;
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
        $cache = $this->getCache();
        // 框架的缓存驱动会自动检查状态，如果禁用，clear() 可能返回 false，但不影响
        return $cache->clear();
    }
    
    /**
     * 获取缓存统计信息
     * 
     * @return array
     */
    public function getStats(): array
    {
        $cache = $this->getCache();
        // 框架的缓存驱动会自动检查状态，如果禁用，getStats() 可能返回空统计
        return $cache->getStats();
    }
}
