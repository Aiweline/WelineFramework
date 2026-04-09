<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Router;

use Weline\Framework\Cache\CacheManager as FrameworkCacheManager;
use Weline\Framework\Cache\Contract\CachePoolInterface;
use Weline\Framework\Cache\KeyBuilder;
use Weline\Framework\Http\Request;
use Weline\Framework\Manager\ObjectManager;
/**
 * CacheManager - 路由缓存管理类
 * 
 * 统一管理路由相关的缓存操作，遵循单一职责原则。
 * 封装缓存键的生成、缓存数据的读写等操作。
 * 
 * @since PHP 8.4
 * @deprecated 使用 \Weline\Framework\Cache\CacheManager::pool('router') 代替
 */
class CacheManager
{
    /**
     * 缓存池
     */
    private ?CachePoolInterface $cache = null;
    
    /**
     * 框架缓存管理器
     */
    private ?FrameworkCacheManager $frameworkCacheManager = null;
    
    /**
     * 缓存键
     */
    private string $urlCacheKey = '';
    private string $ruleCacheKey = '';
    private string $routerCacheKey = '';
    private string $unifiedCacheKey = '';
    
    /**
     * 统一缓存数据（避免重复读取）
     */
    private mixed $unifiedCacheData = null;
    
    /**
     * 规则缓存键常量
     */
    public const RULE_CACHE_RULE_KEY = 'rule';
    public const RULE_CACHE_PARAMS_KEY = 'generated_get_params';
    
    /**
     * 初始化缓存管理器
     * 
     * @param Request $request 请求对象
     * @return void
     */
    public function init(Request $request): void
    {
        if ($this->frameworkCacheManager === null) {
            $this->frameworkCacheManager = ObjectManager::getInstance(FrameworkCacheManager::class);
        }
        
        if ($this->cache === null) {
            $this->cache = $this->frameworkCacheManager->pool('router');
        }
        
        $uri = \w_env('request.uri', $request->getUri());
        $method = $request->getMethod() ?: 'GET';
        
        // 规范化 URI
        $uri = KeyBuilder::normalizeUri($uri);
        
        // 生成各种缓存键
        $this->urlCacheKey = KeyBuilder::buildUrlCacheKey($uri, $method);
        $this->ruleCacheKey = KeyBuilder::buildRuleCacheKey($uri, $method);
        $this->routerCacheKey = KeyBuilder::buildRouterStartCacheKey($uri, $method);
        $this->unifiedCacheKey = KeyBuilder::buildUnifiedRequestCacheKey('', $method);
        
        // 重置统一缓存数据
        $this->unifiedCacheData = null;
    }
    
    /**
     * 获取缓存池
     * 
     * @return CachePoolInterface
     */
    public function getCache(): CachePoolInterface
    {
        if ($this->cache === null) {
            if ($this->frameworkCacheManager === null) {
                $this->frameworkCacheManager = ObjectManager::getInstance(FrameworkCacheManager::class);
            }
            $this->cache = $this->frameworkCacheManager->pool('router');
        }
        return $this->cache;
    }
    
    // ==================== 缓存键获取方法 ====================
    
    /**
     * 获取 URL 缓存键
     * 
     * @return string
     */
    public function getUrlCacheKey(): string
    {
        return $this->urlCacheKey;
    }
    
    /**
     * 获取规则缓存键
     * 
     * @return string
     */
    public function getRuleCacheKey(): string
    {
        return $this->ruleCacheKey;
    }
    
    /**
     * 获取路由缓存键
     * 
     * @return string
     */
    public function getRouterCacheKey(): string
    {
        return $this->routerCacheKey;
    }
    
    /**
     * 获取统一缓存键
     * 
     * @return string
     */
    public function getUnifiedCacheKey(): string
    {
        return $this->unifiedCacheKey;
    }
    
    // ==================== 统一缓存操作 ====================
    
    /**
     * 获取统一缓存数据
     * 
     * @return array|null
     */
    public function getUnifiedCacheData(): ?array
    {
        if ($this->unifiedCacheData === null) {
            $cached = $this->getCache()->get($this->unifiedCacheKey);
            $this->unifiedCacheData = ($cached === false || $cached === null) ? null : $cached;
        }
        
        return is_array($this->unifiedCacheData) ? $this->unifiedCacheData : null;
    }
    
    /**
     * 设置统一缓存数据
     * 
     * @param array $data 缓存数据
     * @return bool
     */
    public function setUnifiedCacheData(array $data): bool
    {
        $this->unifiedCacheData = $data;
        return $this->getCache()->set($this->unifiedCacheKey, $data);
    }
    
    /**
     * 获取统一缓存中的 URL 数据
     * 
     * @return string|null
     */
    public function getCachedUrl(): ?string
    {
        $data = $this->getUnifiedCacheData();
        return $data[KeyBuilder::UNIFIED_CACHE_URL_KEY] ?? null;
    }
    
    /**
     * 获取统一缓存中的路由数据
     * 
     * @return array|null
     */
    public function getCachedRouter(): ?array
    {
        $data = $this->getUnifiedCacheData();
        $router = $data[KeyBuilder::UNIFIED_CACHE_ROUTER_KEY] ?? null;
        return is_array($router) && !empty($router) ? $router : null;
    }
    
    /**
     * 获取统一缓存中的规则数据
     * 
     * @return array|null
     */
    public function getCachedRule(): ?array
    {
        $data = $this->getUnifiedCacheData();
        $rule = $data[KeyBuilder::UNIFIED_CACHE_RULE_KEY] ?? null;
        return is_array($rule) ? $rule : null;
    }
    
    /**
     * 获取统一缓存中的 FPC HTML
     * 
     * @return string|null
     */
    public function getCachedFpcHtml(): ?string
    {
        $data = $this->getUnifiedCacheData();
        return $data[KeyBuilder::UNIFIED_CACHE_FPC_KEY] ?? null;
    }
    
    /**
     * 获取统一缓存中的生成的 GET 参数
     * 
     * @return array
     */
    public function getCachedParams(): array
    {
        $data = $this->getUnifiedCacheData();
        $params = $data[KeyBuilder::UNIFIED_CACHE_PARAMS_KEY] ?? [];
        return is_array($params) ? $params : [];
    }
    
    // ==================== 独立缓存操作 ====================
    
    /**
     * 获取 URL 缓存
     * 
     * @return string|null
     */
    public function getUrlCache(): ?string
    {
        $cached = $this->getCache()->get($this->urlCacheKey);
        return ($cached !== false && $cached !== null) ? $cached : null;
    }
    
    /**
     * 设置 URL 缓存
     * 
     * @param string $url URL
     * @return bool
     */
    public function setUrlCache(string $url): bool
    {
        return $this->getCache()->set($this->urlCacheKey, $url);
    }
    
    /**
     * 获取规则缓存
     * 
     * @return array|null
     */
    public function getRuleCache(): ?array
    {
        $cached = $this->getCache()->get($this->ruleCacheKey);
        return is_array($cached) ? $cached : null;
    }
    
    /**
     * 设置规则缓存
     * 
     * @param array $rule 规则数据
     * @param array $params 生成的 GET 参数
     * @return bool
     */
    public function setRuleCache(array $rule, array $params = []): bool
    {
        return $this->getCache()->set($this->ruleCacheKey, [
            self::RULE_CACHE_RULE_KEY => $rule,
            self::RULE_CACHE_PARAMS_KEY => $params,
        ]);
    }
    
    /**
     * 获取路由缓存
     * 
     * @return array|null
     */
    public function getRouterCache(): ?array
    {
        $cached = $this->getCache()->get($this->routerCacheKey);
        return is_array($cached) ? $cached : null;
    }
    
    /**
     * 设置路由缓存
     * 
     * @param array $router 路由数据
     * @return bool
     */
    public function setRouterCache(array $router): bool
    {
        return $this->getCache()->set($this->routerCacheKey, $router);
    }
    
    // ==================== 批量缓存操作 ====================
    
    /**
     * 保存完整的路由缓存数据
     * 
     * @param string $url 处理后的 URL
     * @param array $rule 规则数据
     * @param array $router 路由数据
     * @param array $params 生成的 GET 参数
     * @param string|null $fpcHtml FPC HTML（可选）
     * @return void
     */
    public function saveAllCacheData(
        string $url,
        array $rule,
        array $router,
        array $params = [],
        ?string $fpcHtml = null
    ): void {
        // 保存统一缓存
        $unifiedData = [
            KeyBuilder::UNIFIED_CACHE_URL_KEY => $url,
            KeyBuilder::UNIFIED_CACHE_RULE_KEY => $rule,
            KeyBuilder::UNIFIED_CACHE_ROUTER_KEY => $router,
            KeyBuilder::UNIFIED_CACHE_PARAMS_KEY => $params,
        ];
        
        if ($fpcHtml !== null) {
            $unifiedData[KeyBuilder::UNIFIED_CACHE_FPC_KEY] = $fpcHtml;
        }
        
        $this->setUnifiedCacheData($unifiedData);
        
        // 同时保存独立缓存（兼容性）
        $this->setUrlCache($url);
        $this->setRuleCache($rule, $params);
        $this->setRouterCache($router);
    }
    
    /**
     * 清除当前请求的所有缓存
     * 
     * @return void
     */
    public function clearCurrentCache(): void
    {
        $cache = $this->getCache();
        $cache->delete($this->urlCacheKey);
        $cache->delete($this->ruleCacheKey);
        $cache->delete($this->routerCacheKey);
        $cache->delete($this->unifiedCacheKey);
        $this->unifiedCacheData = null;
    }
    
    /**
     * 重置状态（用于 WLS 模式下的请求隔离）
     * 
     * @return void
     */
    public function reset(): void
    {
        $this->urlCacheKey = '';
        $this->ruleCacheKey = '';
        $this->routerCacheKey = '';
        $this->unifiedCacheKey = '';
        $this->unifiedCacheData = null;
    }
}
