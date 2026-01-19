<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：2025/01/12
 */

namespace Weline\Currency\Cache;

use Weline\Framework\Cache\CacheFactory;
use Weline\Framework\Cache\CacheInterface;

/**
 * 货币探测缓存类
 * 
 * 职责：
 * - 管理货币探测缓存业务逻辑
 * - 实现缓存淘汰策略（防止攻击者填满磁盘）
 * - 不关心具体缓存驱动（文件、Redis等由配置决定）
 * 
 * 遵循SOLID原则：
 * - 单一职责：只负责货币探测缓存业务逻辑
 * - 开闭原则：对扩展开放（可替换缓存驱动），对修改封闭
 * - 依赖倒置：依赖CacheInterface抽象，不依赖具体实现
 */
class CurrencyCache
{
    // 缓存配置常量
    private const CACHE_MAX_ITEMS = 500; // 最大缓存条目数（防止攻击，货币种类通常较少）
    private const CACHE_TTL_FOUND = 3600; // 找到的货币缓存1小时
    private const CACHE_TTL_NOT_FOUND = 300; // 未找到的货币缓存5分钟（避免重复查询）
    private const CACHE_CLEANUP_PROBABILITY = 100; // 清理概率（1/100，即1%的概率触发清理）
    private const CACHE_CLEANUP_THRESHOLD = 1.5; // 清理阈值（超过最大条目数的倍数）
    
    // 缓存键前缀
    private const CACHE_KEY_PREFIX = 'currency_';
    private const CACHE_KEY_ALL_CURRENCIES = 'currency_all_currencies';
    
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
        $this->cacheFactory = new CacheFactory('currency_cache', '货币探测缓存', true);
        $this->cache = $this->cacheFactory->create();
    }
    
    /**
     * 获取所有货币列表缓存
     * 
     * @return array|null 返回货币数组，如果缓存未命中返回null
     */
    public function getAllCurrencies(): ?array
    {
        // 第一层：请求内静态缓存（最快）
        if (self::$staticCache !== null) {
            return self::$staticCache;
        }
        
        // 第二层：文件缓存（跨请求缓存）
        $this->cleanupIfNeeded();
        
        if ($this->cache->exists(self::CACHE_KEY_ALL_CURRENCIES)) {
            $currencies = $this->cache->get(self::CACHE_KEY_ALL_CURRENCIES);
            self::$staticCache = $currencies;
            return $currencies;
        }
        
        // 缓存未命中
        return null;
    }
    
    /**
     * 设置所有货币列表缓存
     * 
     * @param array $currencies 货币数组
     */
    public function setAllCurrencies(array $currencies): void
    {
        // 设置请求内静态缓存
        self::$staticCache = $currencies;
        
        // 设置文件缓存
        $this->cache->set(self::CACHE_KEY_ALL_CURRENCIES, $currencies, self::CACHE_TTL_FOUND);
    }
    
    /**
     * 根据货币代码获取货币缓存
     * 
     * @param string $currencyCode 货币代码
     * @return array|null 返回货币数据，如果缓存未命中返回null
     */
    public function getByCode(string $currencyCode): ?array
    {
        $currencyCode = strtoupper($currencyCode);
        
        // 第一层：请求内静态缓存（最快）
        if (self::$staticCache !== null) {
            foreach (self::$staticCache as $currency) {
                if (isset($currency['code']) && strtoupper($currency['code']) === $currencyCode) {
                    return $currency;
                }
            }
        }
        
        // 第二层：文件缓存（跨请求缓存）
        $this->cleanupIfNeeded();
        
        $cacheKey = self::CACHE_KEY_PREFIX . $currencyCode;
        if ($this->cache->exists($cacheKey)) {
            $currency = $this->cache->get($cacheKey);
            return $currency;
        }
        
        // 缓存未命中
        return null;
    }
    
    /**
     * 设置货币代码对应的货币缓存
     * 
     * @param string $currencyCode 货币代码
     * @param array|null $currency 货币数据，如果未找到传入null
     */
    public function setByCode(string $currencyCode, ?array $currency): void
    {
        $currencyCode = strtoupper($currencyCode);
        $cacheKey = self::CACHE_KEY_PREFIX . $currencyCode;
        $ttl = ($currency !== null) ? self::CACHE_TTL_FOUND : self::CACHE_TTL_NOT_FOUND;
        
        $this->cache->set($cacheKey, $currency, $ttl);
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
