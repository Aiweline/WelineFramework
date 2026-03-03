<?php

declare(strict_types=1);

namespace Weline\Theme\Service;

use Weline\Framework\Cache\Contract\CachePoolInterface;
use Weline\Framework\Manager\ObjectManager;

/**
 * 主题编辑器锁定服务
 * 
 * 管理主题编辑器的锁定状态，防止多用户同时编辑同一主题/页面
 * 
 * 功能：
 * - 获取/设置编辑锁定
 * - 检测锁定状态
 * - 自动过期（5分钟无活动）
 * - 请求接管
 * - 强制接管（超时后）
 */
class EditorLockService
{
    /** 缓存前缀 */
    private const CACHE_PREFIX = 'editor_lock_';
    
    /** 接管请求缓存前缀 */
    private const TAKEOVER_PREFIX = 'editor_takeover_';
    
    /** 锁定超时（秒）：5 分钟无活动自动释放 */
    private const LOCK_TIMEOUT = 300;
    
    /** 接管等待时间（秒）：5 分钟后可强制接管 */
    private const TAKEOVER_WAIT = 300;

    private CachePoolInterface $cache;

    public function __construct()
    {
        $this->cache = w_cache('theme');
    }

    /**
     * 获取锁定状态
     * 
     * @param int $themeId 主题ID
     * @param string $pageType 页面类型
     * @return array|null 锁定信息，未锁定返回 null
     */
    public function getLockInfo(int $themeId, string $pageType): ?array
    {
        $cacheKey = $this->getLockCacheKey($themeId, $pageType);
        $lockInfo = $this->cache->get($cacheKey);
        
        if (!is_array($lockInfo)) {
            return null;
        }
        
        // 检查是否过期
        $lastActivity = $lockInfo['last_activity'] ?? 0;
        if (time() - $lastActivity > self::LOCK_TIMEOUT) {
            // 锁定已过期，自动释放
            $this->releaseLock($themeId, $pageType, $lockInfo['user_id']);
            return null;
        }
        
        return $lockInfo;
    }

    /**
     * 尝试获取锁定
     * 
     * @param int $themeId 主题ID
     * @param string $pageType 页面类型
     * @param int $userId 用户ID
     * @param string $userName 用户名（用于显示）
     * @return array ['success' => bool, 'message' => string, 'lock_info' => array|null]
     */
    public function acquireLock(int $themeId, string $pageType, int $userId, string $userName = ''): array
    {
        $currentLock = $this->getLockInfo($themeId, $pageType);
        
        // 如果已被锁定
        if ($currentLock !== null) {
            // 检查是否是同一用户
            if ($currentLock['user_id'] === $userId) {
                // 更新活动时间
                $this->updateActivity($themeId, $pageType, $userId);
                return [
                    'success' => true,
                    'message' => __('继续编辑'),
                    'lock_info' => $currentLock,
                ];
            }
            
            // 被其他用户锁定
            return [
                'success' => false,
                'message' => __('%{1} 正在编辑此页面', [$currentLock['user_name'] ?: __('其他用户')]),
                'lock_info' => $currentLock,
            ];
        }
        
        // 创建新锁定
        $lockInfo = [
            'theme_id' => $themeId,
            'page_type' => $pageType,
            'user_id' => $userId,
            'user_name' => $userName,
            'locked_at' => time(),
            'last_activity' => time(),
        ];
        
        $cacheKey = $this->getLockCacheKey($themeId, $pageType);
        $this->cache->set($cacheKey, $lockInfo, self::LOCK_TIMEOUT);
        
        return [
            'success' => true,
            'message' => __('已锁定编辑'),
            'lock_info' => $lockInfo,
        ];
    }

    /**
     * 释放锁定
     * 
     * @param int $themeId 主题ID
     * @param string $pageType 页面类型
     * @param int $userId 用户ID
     * @return bool 是否成功释放
     */
    public function releaseLock(int $themeId, string $pageType, int $userId): bool
    {
        $currentLock = $this->getLockInfo($themeId, $pageType);
        
        // 如果未锁定或不是当前用户的锁定
        if ($currentLock === null) {
            return true;
        }
        
        if ($currentLock['user_id'] !== $userId) {
            return false;
        }
        
        $cacheKey = $this->getLockCacheKey($themeId, $pageType);
        $this->cache->delete($cacheKey);
        
        // 同时清除接管请求
        $takeoverKey = $this->getTakeoverCacheKey($themeId, $pageType);
        $this->cache->delete($takeoverKey);
        
        return true;
    }

    /**
     * 更新活动时间
     * 
     * @param int $themeId 主题ID
     * @param string $pageType 页面类型
     * @param int $userId 用户ID
     * @return bool
     */
    public function updateActivity(int $themeId, string $pageType, int $userId): bool
    {
        $currentLock = $this->getLockInfo($themeId, $pageType);
        
        if ($currentLock === null || $currentLock['user_id'] !== $userId) {
            return false;
        }
        
        $currentLock['last_activity'] = time();
        
        $cacheKey = $this->getLockCacheKey($themeId, $pageType);
        $this->cache->set($cacheKey, $currentLock, self::LOCK_TIMEOUT);
        
        return true;
    }

    /**
     * 请求接管
     * 
     * @param int $themeId 主题ID
     * @param string $pageType 页面类型
     * @param int $userId 请求者用户ID
     * @param string $userName 请求者用户名
     * @return array ['success' => bool, 'message' => string]
     */
    public function requestTakeover(int $themeId, string $pageType, int $userId, string $userName = ''): array
    {
        $currentLock = $this->getLockInfo($themeId, $pageType);
        
        if ($currentLock === null) {
            // 没有锁定，可以直接获取
            return $this->acquireLock($themeId, $pageType, $userId, $userName);
        }
        
        if ($currentLock['user_id'] === $userId) {
            return [
                'success' => true,
                'message' => __('您已持有编辑锁定'),
            ];
        }
        
        // 创建接管请求
        $takeoverInfo = [
            'requester_id' => $userId,
            'requester_name' => $userName,
            'requested_at' => time(),
        ];
        
        $takeoverKey = $this->getTakeoverCacheKey($themeId, $pageType);
        $this->cache->set($takeoverKey, $takeoverInfo, self::TAKEOVER_WAIT);
        
        return [
            'success' => true,
            'message' => __('已发送接管请求，等待 %{1} 响应', [$currentLock['user_name'] ?: __('当前用户')]),
            'wait_seconds' => self::TAKEOVER_WAIT,
        ];
    }

    /**
     * 检查是否有接管请求
     * 
     * @param int $themeId 主题ID
     * @param string $pageType 页面类型
     * @return array|null 接管请求信息
     */
    public function getTakeoverRequest(int $themeId, string $pageType): ?array
    {
        $takeoverKey = $this->getTakeoverCacheKey($themeId, $pageType);
        $takeoverInfo = $this->cache->get($takeoverKey);
        
        return is_array($takeoverInfo) ? $takeoverInfo : null;
    }

    /**
     * 强制接管
     * 
     * 只有在接管请求等待时间后才能强制接管
     * 
     * @param int $themeId 主题ID
     * @param string $pageType 页面类型
     * @param int $userId 接管者用户ID
     * @param string $userName 接管者用户名
     * @return array ['success' => bool, 'message' => string]
     */
    public function forceTakeover(int $themeId, string $pageType, int $userId, string $userName = ''): array
    {
        $currentLock = $this->getLockInfo($themeId, $pageType);
        
        if ($currentLock === null) {
            return $this->acquireLock($themeId, $pageType, $userId, $userName);
        }
        
        if ($currentLock['user_id'] === $userId) {
            return [
                'success' => true,
                'message' => __('您已持有编辑锁定'),
            ];
        }
        
        // 检查是否有接管请求且已过等待时间
        $takeoverInfo = $this->getTakeoverRequest($themeId, $pageType);
        
        if ($takeoverInfo === null) {
            return [
                'success' => false,
                'message' => __('请先发送接管请求'),
            ];
        }
        
        if ($takeoverInfo['requester_id'] !== $userId) {
            return [
                'success' => false,
                'message' => __('您不是接管请求者'),
            ];
        }
        
        $waitedTime = time() - $takeoverInfo['requested_at'];
        
        // 检查当前用户是否有活动（如果有活动，不能强制接管）
        $lastActivity = $currentLock['last_activity'] ?? 0;
        $inactiveTime = time() - $lastActivity;
        
        // 如果当前用户仍然活跃（最近活动不超过5分钟），且等待时间不足，不能强制接管
        if ($inactiveTime < self::LOCK_TIMEOUT && $waitedTime < self::TAKEOVER_WAIT) {
            $remainingWait = self::TAKEOVER_WAIT - $waitedTime;
            return [
                'success' => false,
                'message' => __('当前用户仍在活跃，请等待 %{1} 秒后重试', [$remainingWait]),
            ];
        }
        
        // 强制接管
        $cacheKey = $this->getLockCacheKey($themeId, $pageType);
        $lockInfo = [
            'theme_id' => $themeId,
            'page_type' => $pageType,
            'user_id' => $userId,
            'user_name' => $userName,
            'locked_at' => time(),
            'last_activity' => time(),
            'takeover_from' => $currentLock['user_id'],
        ];
        
        $this->cache->set($cacheKey, $lockInfo, self::LOCK_TIMEOUT);
        
        // 清除接管请求
        $takeoverKey = $this->getTakeoverCacheKey($themeId, $pageType);
        $this->cache->delete($takeoverKey);
        
        return [
            'success' => true,
            'message' => __('已成功接管编辑'),
            'lock_info' => $lockInfo,
        ];
    }

    /**
     * 获取锁定缓存键
     */
    private function getLockCacheKey(int $themeId, string $pageType): string
    {
        return self::CACHE_PREFIX . $themeId . '_' . $pageType;
    }

    /**
     * 获取接管请求缓存键
     */
    private function getTakeoverCacheKey(int $themeId, string $pageType): string
    {
        return self::TAKEOVER_PREFIX . $themeId . '_' . $pageType;
    }
}
