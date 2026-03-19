<?php

declare(strict_types=1);

/**
 * 缓存键生成器
 * 
 * 提供统一的缓存键生成策略。
 * 
 * @author Aiweline
 * @email aiweline@qq.com
 */

namespace Weline\Framework\Cache;

class KeyBuilder
{
    /**
     * 统一缓存键名常量
     */
    public const UNIFIED_CACHE_URL_KEY = 'url';
    public const UNIFIED_CACHE_RULE_KEY = 'rule';
    public const UNIFIED_CACHE_ROUTER_KEY = 'router';
    public const UNIFIED_CACHE_PARAMS_KEY = 'params';
    public const UNIFIED_CACHE_FPC_KEY = 'fpc';
    public const UNIFIED_CACHE_HEADERS_KEY = 'headers';

    /**
     * 构建缓存键
     *
     * @param string $identity 池标识
     * @param string $key 原始键
     * @return string
     */
    public static function build(string $identity, string $key): string
    {
        $fullKey = $identity . ':' . $key;
        
        if (function_exists('hash') && in_array('xxh3', hash_algos(), true)) {
            return hash('xxh3', $fullKey);
        }
        
        return sprintf('%08x%08x', crc32($fullKey), crc32($key));
    }

    /**
     * 构建带请求上下文的缓存键
     *
     * @param string $identity 池标识
     * @param string $key 原始键
     * @param array $context 上下文（如 uri, method, params）
     * @return string
     */
    public static function buildWithContext(string $identity, string $key, array $context = []): string
    {
        $contextStr = '';
        
        if (!empty($context)) {
            ksort($context);
            $contextStr = ':' . serialize($context);
        }
        
        return self::build($identity, $key . $contextStr);
    }

    /**
     * 构建带域名的缓存键（用于路由缓存等）
     *
     * @param string $identity 池标识
     * @param string $key 原始键
     * @param string|null $domain 域名
     * @param string|null $area 区域（frontend/backend）
     * @return string
     */
    public static function buildWithDomain(
        string $identity,
        string $key,
        ?string $domain = null,
        ?string $area = null
    ): string {
        $domain = $domain ?? ($_SERVER['HTTP_HOST'] ?? 'default');
        $area = $area ?? ($_SERVER['WELINE_AREA'] ?? 'frontend');
        
        return self::build($identity, $domain . ':' . $area . ':' . $key);
    }

    /**
     * 构建路由缓存键
     *
     * @param string $uri URI
     * @param string $method HTTP 方法
     * @param string|null $domain 域名
     * @param string|null $area 区域
     * @return string
     */
    public static function buildRouteKey(
        string $uri,
        string $method = 'GET',
        ?string $domain = null,
        ?string $area = null
    ): string {
        $uri = self::normalizeUri($uri);
        return self::buildWithDomain('router', $uri . ':' . $method, $domain, $area);
    }

    /**
     * 规范化 URI
     *
     * @param string $uri URI
     * @return string
     */
    public static function normalizeUri(string $uri): string
    {
        if (($pos = strpos($uri, '?')) !== false) {
            $uri = substr($uri, 0, $pos);
        }
        
        $uri = rtrim($uri, '/');
        
        return empty($uri) ? '/' : $uri;
    }

    /**
     * 获取域名键
     *
     * @return string
     */
    public static function getDomainKey(): string
    {
        $websiteCode = $_SERVER['WELINE_WEBSITE_CODE'] ?? '';
        $host = $_SERVER['HTTP_HOST'] ?? '';
        
        return $websiteCode ?: $host;
    }

    /**
     * 获取区域键
     *
     * @return string
     */
    public static function getAreaKey(): string
    {
        return $_SERVER['WELINE_AREA'] ?? 'frontend';
    }

    /**
     * 构建 URL 缓存键
     *
     * @param string $uri URI
     * @param string $method HTTP 方法
     * @return string
     */
    public static function buildUrlCacheKey(string $uri, string $method = 'GET'): string
    {
        $uri = self::normalizeUri($uri);
        $fullUri = $_SERVER['WELINE_FULL_REQUEST_URI'] ?? $uri;
        return self::build('router', 'url:' . $fullUri . ':' . $method);
    }

    /**
     * 构建规则缓存键
     *
     * @param string $uri URI
     * @param string $method HTTP 方法
     * @return string
     */
    public static function buildRuleCacheKey(string $uri, string $method = 'GET'): string
    {
        $uri = self::normalizeUri($uri);
        $fullUri = $_SERVER['WELINE_FULL_REQUEST_URI'] ?? $uri;
        return self::build('router', 'rule:' . $fullUri . ':' . $method);
    }

    /**
     * 构建路由启动缓存键
     *
     * @param string $uri URI
     * @param string $method HTTP 方法
     * @return string
     */
    public static function buildRouterStartCacheKey(string $uri, string $method = 'GET'): string
    {
        $uri = self::normalizeUri($uri);
        $fullUri = $_SERVER['WELINE_FULL_REQUEST_URI'] ?? $uri;
        return self::build('router', 'start:' . $fullUri . ':' . $method);
    }

    /**
     * 校验 fullUri 是否可用于全页缓存键
     * 避免空或无效 URI 导致缓存键碰撞（如 unified::GET）
     *
     * @param string $fullUri 完整请求 URI
     * @return bool
     */
    public static function isValidFullPageCacheKey(string $fullUri): bool
    {
        if ($fullUri === '' || \strlen($fullUri) < 2) {
            return false;
        }
        if (!\str_contains($fullUri, '://')) {
            return false;
        }
        return true;
    }

    /**
     * 构建统一请求缓存键
     *
     * 当 WELINE_FULL_REQUEST_URI 为空时使用 fallback，避免所有 GET 共享同一 key 导致串台。
     *
     * @param string $uri URI（可留空，将使用 WELINE_FULL_REQUEST_URI）
     * @param string $method HTTP 方法
     * @return string
     */
    public static function buildUnifiedRequestCacheKey(string $uri = '', string $method = 'GET'): string
    {
        $fullUri = $_SERVER['WELINE_FULL_REQUEST_URI'] ?? $uri;
        $usedFallback = false;

        if ($fullUri === '' || !\str_contains($fullUri, '://')) {
            $scheme = $_SERVER['REQUEST_SCHEME'] ?? 'http';
            $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
            $path = $_SERVER['REQUEST_URI'] ?? '/';
            $fullUri = $scheme . '://' . $host . (\str_starts_with($path, '/') ? '' : '/') . $path;
            $usedFallback = true;
        }
        if ($fullUri === '' || !\str_contains($fullUri, '://')) {
            $fullUri = 'unknown-' . ($_SERVER['REQUEST_URI'] ?? '/');
            $usedFallback = true;
        }

        if ($usedFallback && \function_exists('w_log_warning')) {
            w_log_warning(
                '[KeyBuilder] WELINE_FULL_REQUEST_URI missing, used fallback for FPC key',
                ['fullUri' => $fullUri, 'REQUEST_URI' => $_SERVER['REQUEST_URI'] ?? ''],
                'fpc_consistency.log'
            );
        }

        return self::build('router', 'unified:' . $fullUri . ':' . $method);
    }

    /**
     * 按「完整请求 URI」（与 WLS 下 WELINE_FULL_REQUEST_URI 形式一致，含 scheme://host[:port]/path?query）
     * 生成 router 池中与 Core::route 写入相关的键（统一 dispatch 缓存 + url/rule/start + 可选旧版 fpc:）。
     *
     * @param list<string> $legacyFpcLangLocals 语言标记列表（与 State::getLangLocal 一致），用于删除旧 fpc: 键
     * @return list<string>
     */
    public static function routerPoolKeysForFullRequestUri(
        string $fullUri,
        string $method = 'GET',
        array $legacyFpcLangLocals = []
    ): array {
        $keys = [
            self::build('router', 'unified:' . $fullUri . ':' . $method),
            self::build('router', 'url:' . $fullUri . ':' . $method),
            self::build('router', 'rule:' . $fullUri . ':' . $method),
            self::build('router', 'start:' . $fullUri . ':' . $method),
        ];
        foreach ($legacyFpcLangLocals as $lang) {
            if (!\is_string($lang)) {
                continue;
            }
            $keys[] = self::build('router', 'fpc:' . $fullUri . ':' . $lang);
        }

        return $keys;
    }
}
