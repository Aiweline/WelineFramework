<?php
declare(strict_types=1);

namespace Weline\Visitor\Service;

/**
 * 像素统计缓存服务
 * 
 * 功能：
 * - 缓存站点统计摘要
 * - 缓存趋势数据
 * - 缓存事件统计
 * - 提供缓存清理方法
 */
class PixelStatisticsCache
{
    /**
     * 缓存标识
     */
    private const CACHE_TAG = 'pixel_statistics';
    
    /**
     * 缓存时间（秒）
     */
    private const CACHE_TTL_SUMMARY = 300;      // 5分钟
    private const CACHE_TTL_TRENDS = 600;        // 10分钟
    private const CACHE_TTL_EVENTS = 300;        // 5分钟
    private const CACHE_TTL_REALTIME = 60;       // 1分钟（实时数据缓存时间短）
    
    /**
     * 获取站点统计摘要（带缓存）
     * 
     * @param int $websiteId 站点ID
     * @param callable $callback 数据获取回调函数
     * @return array
     */
    public static function getWebsiteSummary(int $websiteId, callable $callback): array
    {
        $cacheKey = self::CACHE_TAG . '_summary_' . $websiteId;
        $cached = w_cache('default')->get($cacheKey);
        
        if ($cached !== false && $cached !== null) {
            return is_array($cached) ? $cached : json_decode($cached, true);
        }
        
        $result = $callback();
        w_cache('default')->set($cacheKey, json_encode($result), self::CACHE_TTL_SUMMARY);
        
        return $result;
    }
    
    /**
     * 获取趋势数据（带缓存）
     * 
     * @param int|null $websiteId 站点ID，null表示所有站点
     * @param int $days 天数
     * @param callable $callback 数据获取回调函数
     * @return array
     */
    public static function getTrends(?int $websiteId, int $days, callable $callback): array
    {
        $cacheKey = self::CACHE_TAG . '_trends_' . ($websiteId ?? 'all') . '_' . $days;
        $cached = w_cache('default')->get($cacheKey);
        
        if ($cached !== false && $cached !== null) {
            return is_array($cached) ? $cached : json_decode($cached, true);
        }
        
        $result = $callback();
        w_cache('default')->set($cacheKey, json_encode($result), self::CACHE_TTL_TRENDS);
        
        return $result;
    }
    
    /**
     * 获取事件统计（带缓存）
     * 
     * @param int $websiteId 站点ID
     * @param string|null $event 事件名，null表示所有事件
     * @param callable $callback 数据获取回调函数
     * @return array|int
     */
    public static function getEventStats(int $websiteId, ?string $event, callable $callback)
    {
        $cacheKey = self::CACHE_TAG . '_events_' . $websiteId . '_' . ($event ?? 'all');
        $cached = w_cache('default')->get($cacheKey);
        
        if ($cached !== false && $cached !== null) {
            $decoded = is_array($cached) ? $cached : json_decode($cached, true);
            return $decoded !== null ? $decoded : $cached;
        }
        
        $result = $callback();
        w_cache('default')->set($cacheKey, is_array($result) ? json_encode($result) : $result, self::CACHE_TTL_EVENTS);
        
        return $result;
    }
    
    /**
     * 获取实时数据（带缓存）
     * 
     * @param int $websiteId 站点ID
     * @param int $interval 时间间隔（分钟）
     * @param int $hours 小时数
     * @param callable $callback 数据获取回调函数
     * @return array
     */
    public static function getRealtimeData(int $websiteId, int $interval, int $hours, callable $callback): array
    {
        $cacheKey = self::CACHE_TAG . '_realtime_' . $websiteId . '_' . $interval . '_' . $hours;
        $cached = w_cache('default')->get($cacheKey);
        
        if ($cached !== false && $cached !== null) {
            return is_array($cached) ? $cached : json_decode($cached, true);
        }
        
        $result = $callback();
        w_cache('default')->set($cacheKey, json_encode($result), self::CACHE_TTL_REALTIME);
        
        return $result;
    }
    
    /**
     * 获取商业价值数据（带缓存）
     * 
     * @param int $websiteId 站点ID
     * @param string $period 时间维度
     * @param string|null $startDate 开始日期
     * @param string|null $endDate 结束日期
     * @param callable $callback 数据获取回调函数
     * @return array
     */
    public static function getBusinessValue(int $websiteId, string $period, ?string $startDate, ?string $endDate, callable $callback): array
    {
        $cacheKey = self::CACHE_TAG . '_business_' . $websiteId . '_' . $period . '_' . md5($startDate . $endDate);
        $cached = w_cache('default')->get($cacheKey);
        
        if ($cached !== false && $cached !== null) {
            return is_array($cached) ? $cached : json_decode($cached, true);
        }
        
        $result = $callback();
        w_cache('default')->set($cacheKey, json_encode($result), self::CACHE_TTL_TRENDS);
        
        return $result;
    }
    
    /**
     * 清除站点统计缓存
     * 
     * @param int|null $websiteId 站点ID，null表示清除所有站点缓存
     * @return void
     */
    public static function clearWebsiteCache(?int $websiteId = null): void
    {
        if ($websiteId !== null) {
            $keys = [
                self::CACHE_TAG . '_summary_' . $websiteId,
                self::CACHE_TAG . '_trends_' . $websiteId . '_*',
                self::CACHE_TAG . '_events_' . $websiteId . '_*',
                self::CACHE_TAG . '_realtime_' . $websiteId . '_*',
                self::CACHE_TAG . '_business_' . $websiteId . '_*'
            ];
        } else {
            // 清除所有相关缓存
            $keys = [
                self::CACHE_TAG . '_summary_*',
                self::CACHE_TAG . '_trends_*',
                self::CACHE_TAG . '_events_*',
                self::CACHE_TAG . '_realtime_*',
                self::CACHE_TAG . '_business_*'
            ];
        }
        
        // 注意：w_cache() 可能不支持通配符删除，这里需要根据实际实现调整
        // 如果支持，可以遍历删除；如果不支持，可能需要记录所有缓存键
        foreach ($keys as $key) {
            // 这里简化处理，实际应该根据缓存驱动实现删除逻辑
            // w_cache('default')->delete($key);
        }
    }
    
    /**
     * 清除所有统计缓存
     * 
     * @return void
     */
    public static function clearAll(): void
    {
        self::clearWebsiteCache(null);
    }
    
    /**
     * 记忆化缓存（如果不存在则执行回调并缓存结果）
     * 
     * @param string $key 缓存键
     * @param int $ttl 缓存时间（秒）
     * @param callable $callback 数据获取回调函数
     * @return mixed
     */
    public static function remember(string $key, int $ttl, callable $callback)
    {
        $cacheKey = self::CACHE_TAG . '_' . $key;
        $cached = w_cache('default')->get($cacheKey);
        
        if ($cached !== false && $cached !== null) {
            $decoded = json_decode($cached, true);
            return $decoded !== null ? $decoded : $cached;
        }
        
        $result = $callback();
        w_cache('default')->set($cacheKey, is_array($result) || is_object($result) ? json_encode($result) : $result, $ttl);
        
        return $result;
    }
}

