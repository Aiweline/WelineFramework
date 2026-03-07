<?php

declare(strict_types=1);

namespace Weline\Theme\Service;

use Weline\Framework\Cache\Contract\CachePoolInterface;
use Weline\Framework\Http\Cookie;
use Weline\Framework\Http\Request;
use Weline\Framework\Manager\ObjectManager;

/**
 * 预览 Token 管理服务
 * 
 * 管理主题预览模式的 token 生成、验证和删除。
 * Token 可通过以下方式传递（优先级从高到低）：
 * 1. URL 参数：?weline_preview_token=xxx
 * 2. Cookie：weline_preview_token=xxx
 * 3. HTTP Header：X-Weline-Preview-Token: xxx
 */
class PreviewTokenService
{
    /** Token 参数/Cookie/Header 名称 */
    public const TOKEN_KEY = 'weline_preview_token';
    
    /** Token Header 名称 */
    public const TOKEN_HEADER = 'X-Weline-Preview-Token';
    
    /** Token 缓存前缀 */
    private const CACHE_PREFIX = 'preview_token_';
    
    /** Token 有效期（秒）：默认 1 小时 */
    private const TOKEN_TTL = 3600;
    
    /** Cookie 有效期（秒）：默认 1 小时 */
    private const COOKIE_TTL = 3600;

    private CachePoolInterface $cache;
    private Request $request;
    
    /** 当前请求的预览数据缓存 */
    private static ?array $currentPreviewData = null;
    
    /** 是否已检测过预览模式 */
    private static bool $detected = false;

    public function __construct(
        Request $request
    ) {
        $this->request = $request;
        // 使用框架缓存
        $this->cache = w_cache('theme');
    }

    /**
     * 生成预览 Token
     * 
     * @param int $themeId 主题ID
     * @param string $pageType 页面类型
     * @param int|null $versionId 版本ID（可选）
     * @return string 生成的 token
     */
    public function generateToken(int $themeId, string $pageType, ?int $versionId = null): string
    {
        // 生成唯一 token：主题ID + 时间戳 + 随机数
        $token = sprintf(
            'pv_%d_%d_%s',
            $themeId,
            time(),
            bin2hex(random_bytes(8))
        );
        
        // 存储 token 数据
        $tokenData = [
            'token' => $token,
            'theme_id' => $themeId,
            'page_type' => $pageType,
            'version_id' => $versionId,
            'created_at' => time(),
            'expires_at' => time() + self::TOKEN_TTL,
        ];
        
        $cacheKey = self::CACHE_PREFIX . $token;
        $this->cache->set($cacheKey, $tokenData, self::TOKEN_TTL);
        
        return $token;
    }

    /**
     * 验证 Token 有效性（含自动续期）
     * 
     * 每次验证时自动延长 Token 有效期，实现"有动作自动续期"。
     * 若缓存未命中但 URL 传入的 token 格式正确且时间戳在有效期内，则重建并写入缓存（解决前后端/多进程缓存不一致）。
     * 
     * @param string $token Token 字符串
     * @return array|null Token 数据，无效返回 null
     */
    public function validateToken(string $token): ?array
    {
        if (empty($token)) {
            return null;
        }
        
        $cacheKey = self::CACHE_PREFIX . $token;
        $tokenData = $this->cache->get($cacheKey);
        
        if (is_array($tokenData)) {
            // 检查是否过期
            if (isset($tokenData['expires_at']) && $tokenData['expires_at'] < time()) {
                $this->deleteToken($token);
                return null;
            }
            // 自动续期
            $tokenData['expires_at'] = time() + self::TOKEN_TTL;
            $tokenData['last_activity'] = time();
            $this->cache->set($cacheKey, $tokenData, self::TOKEN_TTL);
            return $tokenData;
        }
        
        // 缓存未命中：若 token 格式为 pv_themeId_timestamp_hex 且时间戳在有效期内，则接受并写回缓存
        $restored = $this->restoreTokenFromUrlFormat($token);
        if ($restored !== null) {
            $this->cache->set($cacheKey, $restored, self::TOKEN_TTL);
            return $restored;
        }
        
        return null;
    }
    
    /**
     * 从 URL 格式的 token 解析并校验（格式 + 时间戳 1 小时内），用于缓存未命中时的兜底
     * 
     * @param string $token 如 pv_5_1770086319_df322eb2dd5fec1e
     * @return array|null 含 theme_id, page_type 等；无效返回 null
     */
    private function restoreTokenFromUrlFormat(string $token): ?array
    {
        if (!preg_match('/^pv_(\d+)_(\d+)_([a-f0-9]{16})$/', $token, $m)) {
            return null;
        }
        $themeId = (int) $m[1];
        $createdAt = (int) $m[2];
        $now = time();
        if ($createdAt > $now || ($now - $createdAt) > self::TOKEN_TTL) {
            return null;
        }
        return [
            'token' => $token,
            'theme_id' => $themeId,
            'page_type' => 'homepage',
            'version_id' => null,
            'created_at' => $createdAt,
            'expires_at' => $now + self::TOKEN_TTL,
            'last_activity' => $now,
        ];
    }

    /**
     * 删除 Token（退出预览）
     * 
     * @param string $token Token 字符串
     * @return bool
     */
    public function deleteToken(string $token): bool
    {
        if (empty($token)) {
            return false;
        }
        
        $cacheKey = self::CACHE_PREFIX . $token;
        $this->cache->delete($cacheKey);
        
        return true;
    }

    /**
     * 设置预览 Cookie
     * 
     * @param string $token Token 字符串
     * @return void
     */
    public function setPreviewCookie(string $token): void
    {
        Cookie::set(self::TOKEN_KEY, $token, self::COOKIE_TTL, ['path' => '/']);
    }

    /**
     * 清除预览 Cookie
     * 
     * @return void
     */
    public function clearPreviewCookie(): void
    {
        // 通过设置过期时间为过去来删除 Cookie
        Cookie::set(self::TOKEN_KEY, '', -3600, ['path' => '/']);
    }

    /**
     * 从当前请求中获取 Token
     * 
     * 优先级：URL 参数 > Cookie > HTTP Header
     * 
     * @return string|null
     */
    public function getTokenFromRequest(): ?string
    {
        // 1. URL 参数（优先级最高，便于分享预览链接）
        $token = $this->request->getParam(self::TOKEN_KEY);
        if (!empty($token)) {
            return $token;
        }
        
        // 2. Cookie
        $token = Cookie::get(self::TOKEN_KEY);
        if (!empty($token)) {
            return $token;
        }
        
        // 3. HTTP Header
        $headerKey = 'HTTP_' . str_replace('-', '_', strtoupper(self::TOKEN_HEADER));
        $token = $_SERVER[$headerKey] ?? null;
        if (!empty($token)) {
            return $token;
        }
        
        return null;
    }

    /**
     * 检测当前是否处于预览模式
     * 
     * @return bool
     */
    public function isPreviewMode(): bool
    {
        $this->detectPreviewMode();
        return self::$currentPreviewData !== null;
    }

    /**
     * 获取当前预览数据
     * 
     * @return array|null
     */
    public function getCurrentPreviewData(): ?array
    {
        $this->detectPreviewMode();
        return self::$currentPreviewData;
    }

    /**
     * 获取当前预览的主题ID
     * 
     * @return int|null
     */
    public function getCurrentThemeId(): ?int
    {
        $data = $this->getCurrentPreviewData();
        return $data['theme_id'] ?? null;
    }

    /**
     * 获取当前预览 Token
     * 
     * @return string|null
     */
    public function getCurrentToken(): ?string
    {
        $data = $this->getCurrentPreviewData();
        return $data['token'] ?? null;
    }

    /**
     * 检测预览模式（内部方法，带缓存）
     */
    private function detectPreviewMode(): void
    {
        if (self::$detected) {
            return;
        }
        
        self::$detected = true;
        
        $token = $this->getTokenFromRequest();
        if ($token === null) {
            self::$currentPreviewData = null;
            return;
        }
        
        self::$currentPreviewData = $this->validateToken($token);
    }

    /**
     * 重置检测状态（用于测试）
     */
    public static function resetDetection(): void
    {
        self::$detected = false;
        self::$currentPreviewData = null;
    }

    /**
     * WLS 请求结束后重置请求级静态状态，防止跨请求残留。
     */
    public static function resetRequestState(): void
    {
        self::resetDetection();
    }

    /**
     * 静态方法：快速检测是否处于预览模式
     * 
     * @return bool
     */
    public static function inPreviewMode(): bool
    {
        /** @var self $instance */
        $instance = ObjectManager::getInstance(self::class);
        return $instance->isPreviewMode();
    }

    /**
     * 静态方法：获取当前预览 Token
     * 
     * @return string|null
     */
    public static function getToken(): ?string
    {
        /** @var self $instance */
        $instance = ObjectManager::getInstance(self::class);
        return $instance->getCurrentToken();
    }

    /**
     * 获取预览 URL（带 token 参数）
     * 
     * @param string $baseUrl 基础 URL
     * @param string $token Token
     * @return string
     */
    public function getPreviewUrl(string $baseUrl, string $token): string
    {
        $separator = strpos($baseUrl, '?') !== false ? '&' : '?';
        return $baseUrl . $separator . self::TOKEN_KEY . '=' . urlencode($token);
    }
}
