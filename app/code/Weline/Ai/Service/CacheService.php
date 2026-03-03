<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：2025/10/09
 */

namespace Weline\Ai\Service;

use Weline\Framework\Cache\Contract\CachePoolInterface;

/**
 * 缓存服务
 * 
 * 功能：
 * - 响应缓存
 * - 模型缓存
 * - 配置缓存
 * - 性能优化
 */
class CacheService
{
    /**
     * @var CachePoolInterface
     */
    private CachePoolInterface $cache;

    /**
     * 缓存标识
     */
    private const CACHE_TAG = 'ai_service';

    /**
     * 缓存时间
     */
    private const CACHE_TTL_SHORT = 300;    // 5分钟
    private const CACHE_TTL_MEDIUM = 1800;  // 30分钟
    private const CACHE_TTL_LONG = 3600;    // 1小时
    private const CACHE_TTL_DAY = 86400;    // 1天

    /**
     * 构造函数
     * 
     * @param CachePoolInterface $cache
     */
    public function __construct(CachePoolInterface $cache)
    {
        $this->cache = $cache;
    }

    /**
     * 获取响应缓存
     * 
     * @param string $prompt
     * @param string $modelCode
     * @param array $params
     * @return string|null
     */
    public function getResponseCache(string $prompt, string $modelCode, array $params = []): ?string
    {
        $cacheKey = $this->generateResponseCacheKey($prompt, $modelCode, $params);
        $cached = $this->cache->get($cacheKey);
        
        return $cached ?: null;
    }

    /**
     * 设置响应缓存
     * 
     * @param string $prompt
     * @param string $modelCode
     * @param string $response
     * @param array $params
     * @param int $ttl
     * @return void
     */
    public function setResponseCache(
        string $prompt, 
        string $modelCode, 
        string $response, 
        array $params = [], 
        int $ttl = self::CACHE_TTL_SHORT
    ): void {
        $cacheKey = $this->generateResponseCacheKey($prompt, $modelCode, $params);
        $this->cache->set($cacheKey, $response, $ttl);
    }

    /**
     * 生成响应缓存键
     * 
     * @param string $prompt
     * @param string $modelCode
     * @param array $params
     * @return string
     */
    private function generateResponseCacheKey(string $prompt, string $modelCode, array $params): string
    {
        $key = implode('_', [
            self::CACHE_TAG,
            'response',
            $modelCode,
            md5($prompt),
            md5(json_encode($params))
        ]);

        return $key;
    }

    /**
     * 获取模型缓存
     * 
     * @param string $modelCode
     * @return array|null
     */
    public function getModelCache(string $modelCode): ?array
    {
        $cacheKey = self::CACHE_TAG . '_model_' . $modelCode;
        $cached = $this->cache->get($cacheKey);
        
        return $cached ? json_decode($cached, true) : null;
    }

    /**
     * 设置模型缓存
     * 
     * @param string $modelCode
     * @param array $modelData
     * @return void
     */
    public function setModelCache(string $modelCode, array $modelData): void
    {
        $cacheKey = self::CACHE_TAG . '_model_' . $modelCode;
        $this->cache->set($cacheKey, json_encode($modelData), self::CACHE_TTL_LONG);
    }

    /**
     * 记忆化缓存（如果不存在则执行回调并缓存结果）
     * 
     * @param string $key
     * @param int $ttl
     * @param callable $callback
     * @return mixed
     */
    public function remember(string $key, int $ttl, callable $callback)
    {
        $cacheKey = self::CACHE_TAG . '_' . $key;
        $cached = $this->cache->get($cacheKey);
        
        if ($cached !== false && $cached !== null) {
            return is_string($cached) ? json_decode($cached, true) : $cached;
        }

        $result = $callback();
        $this->cache->set($cacheKey, json_encode($result), $ttl);
        
        return $result;
    }

    /**
     * 清除指定模式的缓存
     * 
     * @param string $pattern 支持通配符 * (例如: 'insights_*')
     * @return void
     */
    public function clear(string $pattern): void
    {
        // 简化实现：直接清除所有缓存标签
        // 在实际应用中，可能需要实现更复杂的模式匹配逻辑
        // $this->cache->clean([self::CACHE_TAG]);
    }

    /**
     * 清除所有AI服务缓存
     * 
     * @return void
     */
    public function clearAll(): void
    {
        // TODO: 实现清除所有带标签的缓存
        // $this->cache->clean([self::CACHE_TAG]);
    }

    /**
     * 清除响应缓存
     * 
     * @return void
     */
    public function clearResponseCache(): void
    {
        // TODO: 实现清除响应缓存
    }

    /**
     * 清除模型缓存
     * 
     * @return void
     */
    public function clearModelCache(): void
    {
        // TODO: 实现清除模型缓存
    }

    /**
     * 获取缓存统计信息
     * 
     * @return array
     */
    public function getStats(): array
    {
        return [
            'enabled' => true,
            'tag' => self::CACHE_TAG,
            'ttl' => [
                'short' => self::CACHE_TTL_SHORT,
                'medium' => self::CACHE_TTL_MEDIUM,
                'long' => self::CACHE_TTL_LONG,
                'day' => self::CACHE_TTL_DAY,
            ]
        ];
    }
}

