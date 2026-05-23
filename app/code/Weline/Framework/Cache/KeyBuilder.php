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

use Weline\Framework\App\State;

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
    public const UNIFIED_CACHE_STATUS_KEY = 'status';

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
     * Build a key for environment-sensitive, non-global caches.
     *
     * Use this for rendered output and presentation data that changes with the
     * current storefront environment. Do not use it for global structural caches
     * such as routes, config, schema, or module metadata.
     *
     * @param array<string, mixed> $context
     * @param array<string, bool> $dimensions
     */
    public static function buildEnvironmentScoped(
        string $identity,
        string $key,
        array $context = [],
        array $dimensions = []
    ): string {
        return self::buildWithContext($identity, $key, self::environmentContext($context, $dimensions));
    }

    /**
     * Return a short stable hash for environment-sensitive caches.
     *
     * This is useful when callers already have an internal cache-key scheme and
     * only need to append the active language/currency/site context.
     *
     * @param array<string, mixed> $context
     * @param array<string, bool> $dimensions
     */
    public static function environmentHash(array $context = [], array $dimensions = []): string
    {
        return \sha1(self::stableEncode(self::environmentContext($context, $dimensions)));
    }

    /**
     * Build the default environment context for rendered output.
     *
     * Dimensions are opt-out to keep the common output-cache path safe while
     * allowing narrower scopes, for example language-only caches.
     *
     * @param array<string, mixed> $context
     * @param array<string, bool> $dimensions
     * @return array<string, mixed>
     */
    public static function environmentContext(array $context = [], array $dimensions = []): array
    {
        $dimensions = \array_merge([
            'area' => true,
            'website' => true,
            'host' => true,
            'base_url' => true,
            'lang' => true,
            'lang_local' => true,
            'currency' => true,
        ], $dimensions);

        $environment = ['schema' => 'env-cache-v1'];
        if (!empty($dimensions['area'])) {
            $environment['area'] = self::getAreaKey();
        }
        if (!empty($dimensions['website'])) {
            $environment['website'] = (string)(\w_env('website_code', '') ?: \w_env('website.id', '') ?: '');
        }
        if (!empty($dimensions['host'])) {
            $environment['host'] = (string)\w_env('server.http_host', '');
        }
        if (!empty($dimensions['base_url'])) {
            $environment['base_url'] = self::resolveBaseUrlForEnvironmentContext();
        }
        if (!empty($dimensions['lang'])) {
            $environment['lang'] = (string)State::getLang();
        }
        if (!empty($dimensions['lang_local'])) {
            $environment['lang_local'] = (string)State::getLangLocal();
        }
        if (!empty($dimensions['currency'])) {
            $environment['currency'] = (string)State::getCurrency();
        }

        return self::normalizeContextValue(\array_replace($environment, $context));
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
        $domain = $domain ?? \w_env('server.http_host', 'default');
        $area = $area ?? \w_env('area', 'frontend');

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
        $websiteCode = \w_env('website_code', '');
        $host = \w_env('server.http_host', '');

        return $websiteCode ?: $host;
    }

    /**
     * 获取区域键
     *
     * @return string
     */
    public static function getAreaKey(): string
    {
        return \w_env('area', 'frontend');
    }

    private static function resolveBaseUrlForEnvironmentContext(): string
    {
        $baseUrl = (string)\w_env('base_url', '');
        if ($baseUrl !== '') {
            return $baseUrl;
        }

        $scheme = (string)\w_env('request.scheme', '');
        $host = (string)\w_env('server.http_host', '');
        if ($scheme !== '' && $host !== '') {
            return $scheme . '://' . $host;
        }

        return $host;
    }

    /**
     * @param mixed $value
     * @return mixed
     */
    private static function normalizeContextValue(mixed $value): mixed
    {
        if ($value === null || \is_scalar($value)) {
            return $value;
        }

        if (\is_array($value)) {
            $normalized = [];
            foreach ($value as $key => $item) {
                $normalized[(string)$key] = self::normalizeContextValue($item);
            }
            \ksort($normalized);
            return $normalized;
        }

        if (\is_object($value)) {
            if (\method_exists($value, 'getId')) {
                return ['class' => $value::class, 'id' => (string)$value->getId()];
            }
            return ['class' => $value::class];
        }

        return (string)\gettype($value);
    }

    /**
     * @param mixed $value
     */
    private static function stableEncode(mixed $value): string
    {
        return \json_encode(
            self::normalizeContextValue($value),
            \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES
        ) ?: '';
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
        $fullUri = \w_env('full_request_uri', $uri);
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
        $fullUri = \w_env('full_request_uri', $uri);
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
        $fullUri = \w_env('full_request_uri', $uri);
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
        $explicitUri = $uri !== '';
        $fullUri = $explicitUri ? $uri : (string)(\w_env('full_request_uri', '') ?? '');
        if ($fullUri === '' || !\str_contains($fullUri, '://')) {
            $serverFull = (string)\Weline\Framework\Env\WelineEnv::server('WELINE_FULL_REQUEST_URI', '');
            if ($serverFull !== '' && \str_contains($serverFull, '://')) {
                $fullUri = $serverFull;
            }
        }
        $usedFallback = false;

        if ($fullUri === '' || !\str_contains($fullUri, '://')) {
            $scheme = \w_env('request.scheme', 'http');
            $host = \w_env('server.http_host', 'localhost');
            $path = $explicitUri ? $uri : \w_env('request.uri', '/');
            $fullUri = $scheme . '://' . $host . (\str_starts_with($path, '/') ? '' : '/') . $path;
            $usedFallback = true;
        }
        if ($fullUri === '' || !\str_contains($fullUri, '://')) {
            $fullUri = 'unknown-' . \w_env('request.uri', '/');
            $usedFallback = true;
        }

        if ($usedFallback && \function_exists('w_log_warning')) {
            w_log_warning(
                '[KeyBuilder] WELINE_FULL_REQUEST_URI missing, used fallback for FPC key',
                ['fullUri' => $fullUri, 'REQUEST_URI' => \w_env('request.uri', '')],
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
