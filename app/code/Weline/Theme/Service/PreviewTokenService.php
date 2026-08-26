<?php

declare(strict_types=1);

namespace Weline\Theme\Service;

use Weline\Backend\Api\Auth\BackendUserContextProviderInterface;
use Weline\Framework\Cache\Adapter\FileAdapter;
use Weline\Framework\Cache\Contract\CachePoolInterface;
use Weline\Framework\Cache\Pool\CachePool;
use Weline\Framework\Http\Cookie;
use Weline\Framework\Http\Request;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Session\SessionCookieNameResolver;

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

    /** Sliding renewal can never extend a bearer capability beyond this lifetime. */
    private const TOKEN_MAX_LIFETIME = 8 * 3600;
    
    /** Cookie 有效期（秒）：默认 1 小时 */
    private const COOKIE_TTL = 3600;

    private const MAX_CONTEXT_BYTES = 32768;
    private const MAX_CONTEXT_DEPTH = 8;
    private const MAX_CONTEXT_ENTRIES = 128;

    private const REQUEST_STATE_KEY = 'theme.preview_token.state.v1';

    /** Durable shared fallback identity (bypasses WLS memory hijack of theme pool). */
    private const FILE_FALLBACK_IDENTITY = 'theme_preview_token';

    private CachePoolInterface $cache;
    private ?CachePoolInterface $fileFallback = null;
    private Request $request;
    
    public function __construct(
        Request $request,
        private readonly PreviewRequestInspector $previewRequestInspector,
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
     * @param array $context Normalized preview/editor context payload
     * @return string 生成的 token
     */
    public function generateToken(int $themeId, string $pageType, ?int $versionId = null, array $context = []): string
    {
        if ($themeId < 1) {
            throw new \InvalidArgumentException((string)__('Theme 预览主题标识无效。'));
        }
        $pageType = trim($pageType);
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,127}$/D', $pageType) !== 1) {
            throw new \InvalidArgumentException((string)__('Theme 预览页面类型无效。'));
        }
        if ($versionId !== null && $versionId < 1) {
            throw new \InvalidArgumentException((string)__('Theme 预览版本标识无效。'));
        }
        unset(
            $context['preview_token'],
            $context[self::TOKEN_KEY],
            $context['file_access_actor_id'],
            $context['file_access_policy_revision'],
        );
        $context = $this->bindAuthenticatedFileAccessActor($context);
        $context = $this->boundedContext($context);

        // 256-bit opaque capability. Identity is retained only in the protected
        // cache payload; it is not encoded into the bearer token.
        $token = 'pv_' . rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        
        // 存储 token 数据
        $tokenData = [
            'token' => $token,
            'theme_id' => $themeId,
            'page_type' => $pageType,
            'version_id' => $versionId,
            'context' => $context,
            'created_at' => time(),
            'expires_at' => time() + self::TOKEN_TTL,
        ];
        
        $cacheKey = self::CACHE_PREFIX . $token;
        // Primary pool may be wls_memory under WLS and can fail transiently
        // (sidecar cool-down). Persist to shared file fallback so another Worker
        // can still validate the bearer capability.
        if (!$this->storeCapability($cacheKey, $tokenData)) {
            throw new \RuntimeException((string)__('Theme 预览 Token 无法写入共享缓存。'));
        }
        
        return $token;
    }

    /**
     * 验证 Token 有效性（含自动续期）
     * 
     * 每次验证时自动延长 Token 有效期，实现"有动作自动续期"。
     * 缓存未命中必须视为无效；Token 中的时间戳和主题 ID 不是授权凭据。
     * 
     * @param string $token Token 字符串
     * @return array|null Token 数据，无效返回 null
     */
    public function validateToken(string $token): ?array
    {
        $token = trim($token);
        if (!$this->isTokenFormatValid($token)) {
            return null;
        }
        
        $cacheKey = self::CACHE_PREFIX . $token;
        $tokenData = $this->loadCapability($cacheKey);
        
        if (is_array($tokenData)) {
            if (!$this->isTokenPayloadValid($token, $tokenData)) {
                $this->deleteToken($token);
                return null;
            }
            // 检查是否过期
            if (isset($tokenData['expires_at']) && $tokenData['expires_at'] < time()) {
                $this->deleteToken($token);
                return null;
            }
            $absoluteExpiry = (int)$tokenData['created_at'] + self::TOKEN_MAX_LIFETIME;
            if ($absoluteExpiry <= time()) {
                $this->deleteToken($token);
                return null;
            }
            // 自动续期，但不能让 bearer capability 无限存活。
            $tokenData['expires_at'] = min(time() + self::TOKEN_TTL, $absoluteExpiry);
            $tokenData['last_activity'] = time();
            $this->storeCapability($cacheKey, $tokenData);
            return $tokenData;
        }
        
        return null;
    }

    /**
     * 删除 Token（退出预览）
     * 
     * @param string $token Token 字符串
     * @return bool
     */
    public function deleteToken(string $token): bool
    {
        $token = trim($token);
        if (!$this->isTokenFormatValid($token)) {
            return false;
        }
        
        $cacheKey = self::CACHE_PREFIX . $token;
        $this->deleteCapability($cacheKey);
        
        return true;
    }

    /**
     * Write capability to primary theme pool and/or durable file fallback.
     * Success if either store accepts the payload (cross-Worker readable).
     *
     * @param array<string,mixed> $tokenData
     */
    private function storeCapability(string $cacheKey, array $tokenData): bool
    {
        $primaryOk = false;
        try {
            $primaryOk = (bool)$this->cache->set($cacheKey, $tokenData, self::TOKEN_TTL);
        } catch (\Throwable) {
            $primaryOk = false;
        }

        $fileOk = false;
        try {
            $fileOk = (bool)$this->fileFallback()->set($cacheKey, $tokenData, self::TOKEN_TTL);
        } catch (\Throwable) {
            $fileOk = false;
        }

        return $primaryOk || $fileOk;
    }

    /** @return array<string,mixed>|null */
    private function loadCapability(string $cacheKey): ?array
    {
        try {
            $tokenData = $this->cache->get($cacheKey);
            if (is_array($tokenData)) {
                return $tokenData;
            }
        } catch (\Throwable) {
            // fall through to durable file
        }

        try {
            $tokenData = $this->fileFallback()->get($cacheKey);
            return is_array($tokenData) ? $tokenData : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function deleteCapability(string $cacheKey): void
    {
        try {
            $this->cache->delete($cacheKey);
        } catch (\Throwable) {
            // ignore primary delete failure
        }
        try {
            $this->fileFallback()->delete($cacheKey);
        } catch (\Throwable) {
            // ignore fallback delete failure
        }
    }

    /**
     * Shared file store that is not remapped to wls_memory under WLS.
     * Injectable via reflection for unit tests.
     */
    private function fileFallback(): CachePoolInterface
    {
        if ($this->fileFallback instanceof CachePoolInterface) {
            return $this->fileFallback;
        }

        $adapter = new FileAdapter(self::FILE_FALLBACK_IDENTITY, [
            'path' => BP . 'var' . DS . 'cache' . DS,
        ]);
        $this->fileFallback = new CachePool(
            self::FILE_FALLBACK_IDENTITY,
            $adapter,
            'Theme preview token durable shared fallback',
            false,
            self::TOKEN_TTL,
            true,
        );

        return $this->fileFallback;
    }

    /**
     * 设置预览 Cookie
     * 
     * @param string $token Token 字符串
     * @return void
     */
    public function setPreviewCookie(string $token): void
    {
        if (!$this->isTokenFormatValid($token)) {
            throw new \InvalidArgumentException((string)__('Theme 预览 token 无效。'));
        }
        Cookie::set(self::TOKEN_KEY, $token, self::COOKIE_TTL, $this->cookieOptions());
    }

    /**
     * 清除预览 Cookie
     * 
     * @return void
     */
    public function clearPreviewCookie(): void
    {
        Cookie::delete(self::TOKEN_KEY, $this->cookieOptions());
    }

    /**
     * 从当前请求中获取 Token
     * 
     * 优先级：URL 参数 > Cookie > HTTP Header
     * 
     * @return string|null
     */
    public function getTokenFromRequest(bool $allowCookie = true): ?string
    {
        // 1. URL 参数（优先级最高，便于分享预览链接）
        $token = $this->request->getParam(self::TOKEN_KEY);
        if (is_scalar($token) && $this->isTokenFormatValid((string)$token)) {
            return trim((string)$token);
        }
        
        // 2. HTTP Header
        $token = $this->request->getHeader(str_replace('-', '_', self::TOKEN_HEADER));
        if (is_scalar($token) && $this->isTokenFormatValid((string)$token)) {
            return trim((string)$token);
        }

        // 3. Cookie
        if ($allowCookie && $this->previewRequestInspector->shouldAllowPreviewTokenCookie()) {
            $token = Cookie::get(self::TOKEN_KEY);
            if (is_scalar($token) && $this->isTokenFormatValid((string)$token)) {
                return trim((string)$token);
            }
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
        return self::requestState()['preview_data'] !== null;
    }

    /**
     * 获取当前预览数据
     * 
     * @return array|null
     */
    public function getCurrentPreviewData(): ?array
    {
        $this->detectPreviewMode();
        return self::requestState()['preview_data'];
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
     * 注意：在 WLS 环境下，静态变量会跨请求保持
     * 策略：如果当前请求有 token，使用新 token；没有 token 则重置状态
     */
    private function detectPreviewMode(): void
    {
        // Request 对象可能在同一请求的预览装配阶段更新，因此每次都重读 token。
        $token = $this->getTokenFromRequest();

        if ($token !== null) {
            // 当前请求有 token，使用它（并更新缓存）
            self::storeRequestState([
                'detected' => true,
                'preview_data' => $this->validateToken($token),
            ]);
            return;
        }

        // 当前请求没有 token - 重置状态，不使用上一个请求的缓存
        self::storeRequestState([
            'detected' => true,
            'preview_data' => null,
        ]);
    }

    /**
     * 重置检测状态（用于测试）
     */
    public static function resetDetection(): void
    {
        RequestContext::remove(self::REQUEST_STATE_KEY);
    }

    /**
     * WLS 请求结束后重置请求级静态状态，防止跨请求残留。
     */
    public static function resetRequestState(): void
    {
        self::resetDetection();
    }

    /** @return array{detected: bool, preview_data: array|null} */
    private static function requestState(): array
    {
        $state = RequestContext::get(self::REQUEST_STATE_KEY, []);
        if (!is_array($state)) {
            $state = [];
        }
        $previewData = $state['preview_data'] ?? null;

        return [
            'detected' => (bool)($state['detected'] ?? false),
            'preview_data' => is_array($previewData) ? $previewData : null,
        ];
    }

    /** @param array{detected: bool, preview_data: array|null} $state */
    private static function storeRequestState(array $state): void
    {
        RequestContext::set(self::REQUEST_STATE_KEY, $state);
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

    /** @return array{path:string,secure:bool,httponly:bool,samesite:string} */
    private function cookieOptions(): array
    {
        $secure = $this->request->isSecure();
        return [
            'path' => '/',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => SessionCookieNameResolver::resolveSameSite($secure),
        ];
    }

    private function isTokenFormatValid(string $token): bool
    {
        // Keep already-issued pre-2.1 tokens valid until their one-hour cache
        // entry expires; all newly issued tokens use the 43-character branch.
        return preg_match(
            '/^pv_(?:[A-Za-z0-9_-]{43}|[1-9][0-9]{0,18}_[0-9]{9,12}_[a-f0-9]{16})$/D',
            trim($token),
        ) === 1;
    }

    /** @param array<string,mixed> $payload */
    private function isTokenPayloadValid(string $token, array $payload): bool
    {
        if (!is_string($payload['token'] ?? null)
            || !hash_equals($token, (string)$payload['token'])
            || (int)($payload['theme_id'] ?? 0) < 1
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,127}$/D', (string)($payload['page_type'] ?? '')) !== 1
            || !is_int($payload['created_at'] ?? null)
            || !is_int($payload['expires_at'] ?? null)
            || (int)$payload['expires_at'] < (int)$payload['created_at']
            || (int)$payload['created_at'] > time() + 60
            || (int)$payload['created_at'] < time() - self::TOKEN_MAX_LIFETIME
            || (int)$payload['expires_at'] > time() + self::TOKEN_TTL + 60
            || (int)$payload['expires_at'] > (int)$payload['created_at'] + self::TOKEN_MAX_LIFETIME
            || !is_array($payload['context'] ?? null)
        ) {
            return false;
        }
        try {
            $this->boundedContext($payload['context']);
        } catch (\Throwable) {
            return false;
        }
        return true;
    }

    /** @param array<string,mixed> $context @return array<string,mixed> */
    private function bindAuthenticatedFileAccessActor(array $context): array
    {
        try {
            $user = ObjectManager::getInstance(BackendUserContextProviderInterface::class)->current();
            if ($user !== null && $user->getIsEnabled() && $user->getId() > 0) {
                $context['file_access_actor_id'] = $user->getId();
                // The actor and role are resolved again on every preview request;
                // this field is only a bounded policy-snapshot contract version.
                $context['file_access_policy_revision'] = 1;
            }
        } catch (\Throwable) {
            // A token without an authenticated actor remains valid for public
            // content, while private FileAsset policy fails closed.
        }

        return $context;
    }

    /** @param array<string,mixed> $context @return array<string,mixed> */
    private function boundedContext(array $context): array
    {
        $entries = 0;
        $walk = function (mixed $value, int $depth) use (&$walk, &$entries): mixed {
            if ($depth > self::MAX_CONTEXT_DEPTH || ++$entries > self::MAX_CONTEXT_ENTRIES) {
                throw new \InvalidArgumentException((string)__('Theme 预览上下文超过上限。'));
            }
            if (is_array($value)) {
                $result = [];
                foreach ($value as $key => $item) {
                    if ((!is_int($key) && !is_string($key))
                        || (is_string($key) && (strlen($key) > 128 || preg_match('/[\x00-\x1F\x7F]/', $key) === 1))
                    ) {
                        throw new \InvalidArgumentException((string)__('Theme 预览上下文字段无效。'));
                    }
                    $result[$key] = $walk($item, $depth + 1);
                }
                return $result;
            }
            if (!is_scalar($value) && $value !== null) {
                throw new \InvalidArgumentException((string)__('Theme 预览上下文值无效。'));
            }
            if (is_string($value)
                && (strlen($value) > 8192 || preg_match('/\x00/', $value) === 1)
            ) {
                throw new \InvalidArgumentException((string)__('Theme 预览上下文值超过上限。'));
            }
            return $value;
        };
        $normalized = $walk($context, 0);
        $encoded = json_encode($normalized, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (strlen($encoded) > self::MAX_CONTEXT_BYTES) {
            throw new \InvalidArgumentException((string)__('Theme 预览上下文超过大小上限。'));
        }
        return $normalized;
    }
}
