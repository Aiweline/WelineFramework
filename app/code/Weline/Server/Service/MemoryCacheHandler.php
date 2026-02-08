<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Server\Service;

/**
 * 内存缓存处理器
 * 
 * 处理请求的缓存检查和响应缓存，供 Dispatcher 使用
 * 
 * @package Weline_Server
 */
class MemoryCacheHandler
{
    /**
     * 检查请求缓存
     * 
     * 如果缓存命中，返回缓存的响应；否则返回 null
     * 
     * @param string $rawRequest 原始 HTTP 请求
     * @return string|null 缓存的响应或 null
     */
    public static function checkCache(string $rawRequest): ?string
    {
        // 检查是否启用内存缓存
        if (!MemoryCacheRuleManager::isEnabled()) {
            return null;
        }

        // 解析请求获取缓存键
        $requestInfo = self::parseRequest($rawRequest);
        if ($requestInfo === null) {
            return null;
        }

        // 检查是否应该缓存
        $ruleManager = MemoryCacheRuleManager::getInstance();
        if (!$ruleManager->shouldCache($rawRequest)) {
            return null;
        }

        // 构建缓存键
        $cacheKey = MemoryCacheService::buildCacheKey(
            $requestInfo['uri'],
            $requestInfo['host'],
            $requestInfo['method'],
            $requestInfo['query_string']
        );

        // 检查缓存
        $cached = MemoryCacheService::get($cacheKey);
        if ($cached === null) {
            return null;
        }

        // 构建响应，添加缓存命中头
        $response = $cached['response'];
        
        // 在响应头中添加缓存命中标识
        $response = self::addCacheHeader($response, 'X-WLS-Cache', 'HIT');
        $response = self::addCacheHeader($response, 'X-WLS-Cache-Age', (string)$cached['age']);

        return $response;
    }

    /**
     * 缓存响应
     * 
     * @param string $rawRequest 原始 HTTP 请求
     * @param string $response 响应内容
     * @return bool 是否成功缓存
     */
    public static function cacheResponse(string $rawRequest, string $response): bool
    {
        // 检查是否启用内存缓存
        if (!MemoryCacheRuleManager::isEnabled()) {
            return false;
        }

        // 解析请求
        $requestInfo = self::parseRequest($rawRequest);
        if ($requestInfo === null) {
            return false;
        }

        // 检查是否应该缓存
        $ruleManager = MemoryCacheRuleManager::getInstance();
        if (!$ruleManager->shouldCache($rawRequest)) {
            return false;
        }

        // 检查响应状态码是否可缓存
        $statusCode = self::getResponseStatusCode($response);
        $cacheableStatusCodes = $ruleManager->getCacheableStatusCodes($rawRequest);
        if (!in_array($statusCode, $cacheableStatusCodes, true)) {
            return false;
        }

        // 检查响应头中是否有 Cache-Control: no-cache 或 no-store
        if (self::hasNoCacheHeader($response)) {
            return false;
        }

        // 获取 TTL
        $ttl = $ruleManager->getCacheTtl($rawRequest);

        // 构建缓存键
        $cacheKey = MemoryCacheService::buildCacheKey(
            $requestInfo['uri'],
            $requestInfo['host'],
            $requestInfo['method'],
            $requestInfo['query_string']
        );

        // 解析响应头
        $headers = self::parseResponseHeaders($response);

        // 生成缓存 Tag
        $tags = self::generateCacheTags($requestInfo, $headers);

        // 添加缓存标识头
        $response = self::addCacheHeader($response, 'X-WLS-Cache', 'MISS');

        // 存储缓存
        $fullUrl = ($requestInfo['scheme'] ?? 'https') . '://' . $requestInfo['host'] . $requestInfo['uri'];
        if ($requestInfo['query_string']) {
            $fullUrl .= '?' . $requestInfo['query_string'];
        }

        return MemoryCacheService::set(
            $cacheKey,
            $response,
            $headers,
            $ttl,
            $tags,
            $requestInfo['host'],
            $fullUrl
        );
    }

    /**
     * 解析请求
     * 
     * @param string $rawRequest 原始 HTTP 请求
     * @return array|null
     */
    public static function parseRequest(string $rawRequest): ?array
    {
        $lines = explode("\r\n", $rawRequest);
        $firstLine = $lines[0] ?? '';
        
        // 解析请求行
        $parts = explode(' ', $firstLine);
        if (count($parts) < 2) {
            return null;
        }
        
        $method = $parts[0] ?? 'GET';
        $fullUri = $parts[1] ?? '/';
        
        // 分离 URI 和查询字符串
        $uriParts = parse_url($fullUri);
        $uri = $uriParts['path'] ?? '/';
        $queryString = $uriParts['query'] ?? '';
        
        // 解析请求头获取 Host
        $host = '';
        for ($i = 1; $i < count($lines); $i++) {
            $line = $lines[$i];
            if (empty($line)) {
                break;
            }
            
            if (stripos($line, 'Host:') === 0) {
                $host = trim(substr($line, 5));
                break;
            }
        }

        return [
            'method' => strtoupper($method),
            'uri' => $uri,
            'query_string' => $queryString,
            'host' => $host,
            'full_uri' => $fullUri,
        ];
    }

    /**
     * 获取响应状态码
     * 
     * @param string $response 响应内容
     * @return int
     */
    public static function getResponseStatusCode(string $response): int
    {
        // 解析响应行：HTTP/1.1 200 OK
        $firstLine = substr($response, 0, strpos($response, "\r\n") ?: strlen($response));
        
        if (preg_match('/HTTP\/[\d.]+\s+(\d+)/', $firstLine, $matches)) {
            return (int)$matches[1];
        }

        return 0;
    }

    /**
     * 检查响应是否有 no-cache 头
     * 
     * @param string $response 响应内容
     * @return bool
     */
    public static function hasNoCacheHeader(string $response): bool
    {
        // 获取响应头部分
        $headerEnd = strpos($response, "\r\n\r\n");
        if ($headerEnd === false) {
            return false;
        }
        
        $headers = substr($response, 0, $headerEnd);
        
        // 检查 Cache-Control
        if (preg_match('/Cache-Control:\s*([^\r\n]+)/i', $headers, $matches)) {
            $cacheControl = strtolower($matches[1]);
            if (str_contains($cacheControl, 'no-cache') || str_contains($cacheControl, 'no-store') || str_contains($cacheControl, 'private')) {
                return true;
            }
        }

        // 检查 Pragma: no-cache
        if (preg_match('/Pragma:\s*no-cache/i', $headers)) {
            return true;
        }

        return false;
    }

    /**
     * 解析响应头
     * 
     * @param string $response 响应内容
     * @return array
     */
    public static function parseResponseHeaders(string $response): array
    {
        $headers = [];
        
        $headerEnd = strpos($response, "\r\n\r\n");
        if ($headerEnd === false) {
            return $headers;
        }
        
        $headerSection = substr($response, 0, $headerEnd);
        $lines = explode("\r\n", $headerSection);
        
        // 跳过状态行
        for ($i = 1; $i < count($lines); $i++) {
            $line = $lines[$i];
            $colonPos = strpos($line, ':');
            if ($colonPos !== false) {
                $name = trim(substr($line, 0, $colonPos));
                $value = trim(substr($line, $colonPos + 1));
                $headers[$name] = $value;
            }
        }

        return $headers;
    }

    /**
     * 添加缓存头
     * 
     * @param string $response 响应内容
     * @param string $headerName 头名称
     * @param string $headerValue 头值
     * @return string
     */
    public static function addCacheHeader(string $response, string $headerName, string $headerValue): string
    {
        $headerEnd = strpos($response, "\r\n\r\n");
        if ($headerEnd === false) {
            return $response;
        }

        $headerSection = substr($response, 0, $headerEnd);
        $body = substr($response, $headerEnd);

        // 检查是否已存在该头
        $pattern = '/' . preg_quote($headerName, '/') . ':\s*[^\r\n]+\r\n/i';
        if (preg_match($pattern, $headerSection)) {
            // 替换现有头
            $headerSection = preg_replace($pattern, "{$headerName}: {$headerValue}\r\n", $headerSection);
        } else {
            // 添加新头
            $headerSection .= "{$headerName}: {$headerValue}\r\n";
        }

        return $headerSection . $body;
    }

    /**
     * 生成缓存标签
     * 
     * @param array $requestInfo 请求信息
     * @param array $headers 响应头
     * @return array
     */
    public static function generateCacheTags(array $requestInfo, array $headers): array
    {
        $tags = [];

        // 基于 URI 路径生成标签
        $uri = $requestInfo['uri'] ?? '/';
        $pathParts = explode('/', trim($uri, '/'));
        
        if (!empty($pathParts[0])) {
            $tags[] = 'path:' . $pathParts[0];
        }

        // 基于 Host 生成标签
        if (!empty($requestInfo['host'])) {
            $tags[] = 'host:' . $requestInfo['host'];
        }

        // 检查响应头中的 Cache-Tag
        if (isset($headers['Cache-Tag'])) {
            $cacheTags = explode(',', $headers['Cache-Tag']);
            foreach ($cacheTags as $tag) {
                $tags[] = trim($tag);
            }
        }

        // 检查 Surrogate-Key（类似 Fastly）
        if (isset($headers['Surrogate-Key'])) {
            $surrogateKeys = explode(' ', $headers['Surrogate-Key']);
            foreach ($surrogateKeys as $key) {
                $tags[] = trim($key);
            }
        }

        return array_unique($tags);
    }

    /**
     * 构建绕过缓存的响应
     * 
     * @param string $response 原始响应
     * @return string
     */
    public static function addBypassHeader(string $response): string
    {
        return self::addCacheHeader($response, 'X-WLS-Cache', 'BYPASS');
    }

    /**
     * 获取缓存统计信息
     * 
     * @return array
     */
    public static function getStats(): array
    {
        return MemoryCacheService::getStats();
    }
}
