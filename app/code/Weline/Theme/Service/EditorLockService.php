<?php

declare(strict_types=1);

namespace Weline\Theme\Service;

use Weline\Framework\Cache\Contract\CachePoolInterface;
use Weline\Framework\Cache\Contract\SingleFlightInterface;
use Weline\Framework\Cache\Service\SingleFlightCoordinator;

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
    private SingleFlightInterface $coordinator;

    public function __construct(?SingleFlightInterface $coordinator = null)
    {
        $this->cache = w_cache('theme');
        $this->coordinator = $coordinator ?? new SingleFlightCoordinator();
    }

    /**
     * 获取锁定状态
     * 
     * @param int $themeId 主题ID
     * @param string $pageType 页面类型
     * @return array|null 锁定信息，未锁定返回 null
     */
    public function getLockInfo(int $themeId, string $pageType, string $contextKey = ''): ?array
    {
        $cacheKey = $this->getLockCacheKey($themeId, $pageType, $contextKey);
        $lockInfo = $this->cache->get($cacheKey);
        
        $lockInfo = $this->normalizeLockInfo($lockInfo, $themeId, $pageType, $contextKey);
        if ($lockInfo === null) {
            return null;
        }
        
        // 检查是否过期
        $lastActivity = $lockInfo['last_activity'] ?? 0;
        if (time() - $lastActivity > self::LOCK_TIMEOUT) {
            // Do not delete after a stale read: another Worker may already
            // have replaced this entry with a fresh lock. Cache TTL performs
            // physical cleanup; synchronized writers safely overwrite it.
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
    public function acquireLock(
        int $themeId,
        string $pageType,
        int $userId,
        string $userName = '',
        string $contextKey = '',
    ): array
    {
        $this->assertUserId($userId);
        return $this->synchronized(
            $themeId,
            $pageType,
            $contextKey,
            fn (): array => $this->acquireLockUnlocked(
                $themeId,
                $pageType,
                $userId,
                $userName,
                $contextKey,
            ),
        );
    }

    /** @return array{success:bool,message:mixed,lock_info?:array|null} */
    private function acquireLockUnlocked(
        int $themeId,
        string $pageType,
        int $userId,
        string $userName,
        string $contextKey,
    ): array
    {
        $userName = $this->safeUserName($userName);
        $currentLock = $this->getLockInfo($themeId, $pageType, $contextKey);
        
        // 如果已被锁定
        if ($currentLock !== null) {
            // 检查是否是同一用户
            if ($currentLock['user_id'] === $userId) {
                // 更新活动时间
                $currentLock['last_activity'] = time();
                $updated = $this->cache->set(
                    $this->getLockCacheKey($themeId, $pageType, $contextKey),
                    $currentLock,
                    self::LOCK_TIMEOUT,
                );
                if (!$updated) {
                    throw new \RuntimeException((string)__('编辑锁活动时间更新失败'));
                }
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
            'context_key' => $contextKey,
            'user_id' => $userId,
            'user_name' => $userName,
            'locked_at' => time(),
            'last_activity' => time(),
        ];
        
        $cacheKey = $this->getLockCacheKey($themeId, $pageType, $contextKey);
        $this->cache->delete($this->getTakeoverCacheKey($themeId, $pageType, $contextKey));
        if (!$this->cache->set($cacheKey, $lockInfo, self::LOCK_TIMEOUT)) {
            throw new \RuntimeException((string)__('编辑锁写入失败'));
        }
        
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
    public function releaseLock(int $themeId, string $pageType, int $userId, string $contextKey = ''): bool
    {
        $this->assertUserId($userId);
        return $this->synchronized(
            $themeId,
            $pageType,
            $contextKey,
            function () use ($themeId, $pageType, $userId, $contextKey): bool {
                $currentLock = $this->getLockInfo($themeId, $pageType, $contextKey);
                if ($currentLock === null) {
                    return true;
                }
                if ($currentLock['user_id'] !== $userId) {
                    return false;
                }
                if (!$this->cache->delete($this->getLockCacheKey($themeId, $pageType, $contextKey))) {
                    throw new \RuntimeException((string)__('编辑锁释放失败'));
                }
                $this->cache->delete($this->getTakeoverCacheKey($themeId, $pageType, $contextKey));
                return true;
            },
        );
    }

    /**
     * 更新活动时间
     * 
     * @param int $themeId 主题ID
     * @param string $pageType 页面类型
     * @param int $userId 用户ID
     * @return bool
     */
    public function updateActivity(int $themeId, string $pageType, int $userId, string $contextKey = ''): bool
    {
        $this->assertUserId($userId);
        return $this->synchronized(
            $themeId,
            $pageType,
            $contextKey,
            function () use ($themeId, $pageType, $userId, $contextKey): bool {
                $currentLock = $this->getLockInfo($themeId, $pageType, $contextKey);
                if ($currentLock === null || $currentLock['user_id'] !== $userId) {
                    return false;
                }
                $currentLock['last_activity'] = time();
                $updated = $this->cache->set(
                    $this->getLockCacheKey($themeId, $pageType, $contextKey),
                    $currentLock,
                    self::LOCK_TIMEOUT,
                );
                return $updated;
            },
        );
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
    public function requestTakeover(
        int $themeId,
        string $pageType,
        int $userId,
        string $userName = '',
        string $contextKey = '',
    ): array
    {
        $this->assertUserId($userId);
        return $this->synchronized(
            $themeId,
            $pageType,
            $contextKey,
            fn (): array => $this->requestTakeoverUnlocked(
                $themeId,
                $pageType,
                $userId,
                $userName,
                $contextKey,
            ),
        );
    }

    /** @return array<string,mixed> */
    private function requestTakeoverUnlocked(
        int $themeId,
        string $pageType,
        int $userId,
        string $userName,
        string $contextKey,
    ): array
    {
        $userName = $this->safeUserName($userName);
        $currentLock = $this->getLockInfo($themeId, $pageType, $contextKey);
        
        if ($currentLock === null) {
            // 没有锁定，可以直接获取
            return $this->acquireLockUnlocked($themeId, $pageType, $userId, $userName, $contextKey);
        }
        
        if ($currentLock['user_id'] === $userId) {
            return [
                'success' => true,
                'message' => __('您已持有编辑锁定'),
            ];
        }
        
        $existingTakeover = $this->getTakeoverRequest($themeId, $pageType, $contextKey);
        if ($existingTakeover !== null) {
            if ($existingTakeover['requester_id'] !== $userId) {
                return [
                    'success' => false,
                    'message' => __('已有其他用户发起接管请求'),
                ];
            }
            return [
                'success' => true,
                'message' => __('接管请求已在等待处理'),
                'wait_seconds' => max(
                    0,
                    self::TAKEOVER_WAIT - (time() - (int)$existingTakeover['requested_at']),
                ),
            ];
        }

        // 创建接管请求
        $takeoverInfo = [
            'requester_id' => $userId,
            'requester_name' => $userName,
            'requested_at' => time(),
        ];
        
        $takeoverKey = $this->getTakeoverCacheKey($themeId, $pageType, $contextKey);
        if (!$this->cache->set($takeoverKey, $takeoverInfo, self::TAKEOVER_WAIT)) {
            throw new \RuntimeException((string)__('编辑锁接管请求写入失败'));
        }
        
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
    public function getTakeoverRequest(int $themeId, string $pageType, string $contextKey = ''): ?array
    {
        $takeoverKey = $this->getTakeoverCacheKey($themeId, $pageType, $contextKey);
        $takeoverInfo = $this->cache->get($takeoverKey);
        if (!is_array($takeoverInfo)
            || (int)($takeoverInfo['requester_id'] ?? 0) < 1
            || (int)($takeoverInfo['requested_at'] ?? 0) < 1
            || time() - (int)$takeoverInfo['requested_at'] > self::TAKEOVER_WAIT
        ) {
            return null;
        }
        $takeoverInfo['requester_id'] = (int)$takeoverInfo['requester_id'];
        $takeoverInfo['requested_at'] = (int)$takeoverInfo['requested_at'];
        $takeoverInfo['requester_name'] = $this->safeUserName($takeoverInfo['requester_name'] ?? '');
        return $takeoverInfo;
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
    public function forceTakeover(
        int $themeId,
        string $pageType,
        int $userId,
        string $userName = '',
        string $contextKey = '',
    ): array
    {
        $this->assertUserId($userId);
        return $this->synchronized(
            $themeId,
            $pageType,
            $contextKey,
            fn (): array => $this->forceTakeoverUnlocked(
                $themeId,
                $pageType,
                $userId,
                $userName,
                $contextKey,
            ),
        );
    }

    /** @return array<string,mixed> */
    private function forceTakeoverUnlocked(
        int $themeId,
        string $pageType,
        int $userId,
        string $userName,
        string $contextKey,
    ): array
    {
        $userName = $this->safeUserName($userName);
        $currentLock = $this->getLockInfo($themeId, $pageType, $contextKey);
        
        if ($currentLock === null) {
            return $this->acquireLockUnlocked($themeId, $pageType, $userId, $userName, $contextKey);
        }
        
        if ($currentLock['user_id'] === $userId) {
            return [
                'success' => true,
                'message' => __('您已持有编辑锁定'),
            ];
        }
        
        // 检查是否有接管请求且已过等待时间
        $takeoverInfo = $this->getTakeoverRequest($themeId, $pageType, $contextKey);
        
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
        $cacheKey = $this->getLockCacheKey($themeId, $pageType, $contextKey);
        $lockInfo = [
            'theme_id' => $themeId,
            'page_type' => $pageType,
            'context_key' => $contextKey,
            'user_id' => $userId,
            'user_name' => $userName,
            'locked_at' => time(),
            'last_activity' => time(),
            'takeover_from' => $currentLock['user_id'],
        ];
        
        // 清除接管请求
        $takeoverKey = $this->getTakeoverCacheKey($themeId, $pageType, $contextKey);
        $this->cache->delete($takeoverKey);
        if (!$this->cache->set($cacheKey, $lockInfo, self::LOCK_TIMEOUT)) {
            throw new \RuntimeException((string)__('编辑锁接管写入失败'));
        }
        
        return [
            'success' => true,
            'message' => __('已成功接管编辑'),
            'lock_info' => $lockInfo,
        ];
    }

    /**
     * 获取锁定缓存键
     */
    private function getLockCacheKey(int $themeId, string $pageType, string $contextKey = ''): string
    {
        return self::CACHE_PREFIX . $themeId . '_' . $pageType . $this->contextKeySuffix($contextKey);
    }

    /**
     * 获取接管请求缓存键
     */
    private function getTakeoverCacheKey(int $themeId, string $pageType, string $contextKey = ''): string
    {
        return self::TAKEOVER_PREFIX . $themeId . '_' . $pageType . $this->contextKeySuffix($contextKey);
    }

    /** @template T @param callable():T $operation @return T */
    private function synchronized(
        int $themeId,
        string $pageType,
        string $contextKey,
        callable $operation,
    ): mixed {
        $this->assertLockIdentity($themeId, $pageType, $contextKey);
        $coordinationKey = 'theme_editor_lock_mutation_' . hash(
            'sha256',
            $themeId . "\0" . $pageType . "\0" . $contextKey,
        );
        $token = $this->coordinator->acquire($coordinationKey, 2000, 10);
        if ($token === null) {
            throw new \RuntimeException((string)__('编辑锁服务正忙，请稍后重试'));
        }
        try {
            return $operation();
        } finally {
            $this->coordinator->release($coordinationKey, $token);
        }
    }

    private function assertLockIdentity(int $themeId, string $pageType, string $contextKey): void
    {
        if ($themeId < 1
            || preg_match('/^[a-z][a-z0-9_.:-]{0,63}$/D', $pageType) !== 1
            || $contextKey === ''
            || strlen($contextKey) > 2048
            || preg_match('/[\x00-\x1F\x7F]/', $contextKey) === 1
        ) {
            throw new \InvalidArgumentException((string)__('编辑锁资源身份无效'));
        }
    }

    private function assertUserId(int $userId): void
    {
        if ($userId < 1) {
            throw new \InvalidArgumentException((string)__('编辑锁缺少明确操作人'));
        }
    }

    private function normalizeLockInfo(
        mixed $value,
        int $themeId,
        string $pageType,
        string $contextKey,
    ): ?array {
        if (!is_array($value)
            || (int)($value['theme_id'] ?? 0) !== $themeId
            || !hash_equals($pageType, (string)($value['page_type'] ?? ''))
            || !hash_equals($contextKey, (string)($value['context_key'] ?? ''))
            || (int)($value['user_id'] ?? 0) < 1
            || (int)($value['locked_at'] ?? 0) < 1
            || (int)($value['last_activity'] ?? 0) < (int)($value['locked_at'] ?? 0)
            || (int)($value['last_activity'] ?? 0) > time() + 60
        ) {
            return null;
        }
        $value['theme_id'] = $themeId;
        $value['page_type'] = $pageType;
        $value['context_key'] = $contextKey;
        $value['user_id'] = (int)$value['user_id'];
        $value['locked_at'] = (int)$value['locked_at'];
        $value['last_activity'] = (int)$value['last_activity'];
        $value['user_name'] = $this->safeUserName($value['user_name'] ?? '');
        return $value;
    }

    private function safeUserName(mixed $userName): string
    {
        if (!is_scalar($userName)) {
            return '';
        }
        $userName = trim((string)$userName);
        if (preg_match('//u', $userName) !== 1
            || preg_match('/[\x00-\x1F\x7F]/', $userName) === 1
        ) {
            return '';
        }
        if (function_exists('mb_substr')) {
            return mb_substr($userName, 0, 255, 'UTF-8');
        }
        return substr($userName, 0, 255);
    }

    private function contextKeySuffix(string $contextKey): string
    {
        $contextKey = trim($contextKey);
        return $contextKey === '' ? '' : '_' . hash('sha256', $contextKey);
    }
}
