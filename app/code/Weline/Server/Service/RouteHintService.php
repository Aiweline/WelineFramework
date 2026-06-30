<?php
declare(strict_types=1);

/**
 * Weline Server - Worker 路由提示服务
 *
 * 提供 Worker 自报告路由信息的能力，用于 TCP 透传架构下的智能路由。
 *
 * 工作原理：
 * 1. Worker 处理完请求后，在响应头中添加 X-Weline-Route-Hint
 * 2. Dispatcher 解析此头部，缓存路由信息
 * 3. 后续相同 SNI/IP 的请求直接路由到对应 Worker
 *
 * 使用场景：
 * - TCP 透传模式下，Dispatcher 无法解析 HTTP 头
 * - 通过 Worker 自报告实现智能路由
 * - 支持 Keep-Alive 场景的会话粘性
 *
 * @author Aiweline
 * @email aiweline@qq.com
 */

namespace Weline\Server\Service;

class RouteHintService
{
    /**
     * 当前 Worker 端口（启动时设置）
     */
    private static int $workerPort = 0;
    
    /**
     * 是否启用路由提示
     */
    private static bool $enabled = true;
    
    /**
     * 默认 TTL（秒）
     */
    private static int $defaultTtl = 3600;
    
    /**
     * 路由提示头名称
     */
    public const HEADER_NAME = 'X-Weline-Route-Hint';
    
    /**
     * 初始化服务（Worker 启动时调用）
     *
     * @param int $workerPort Worker 监听端口
     * @param bool $enabled 是否启用路由提示
     * @param int $defaultTtl 默认 TTL（秒）
     */
    public static function init(int $workerPort, bool $enabled = true, int $defaultTtl = 3600): void
    {
        self::$workerPort = $workerPort;
        self::$enabled = $enabled;
        self::$defaultTtl = $defaultTtl;
    }
    
    /**
     * 设置 Worker 端口
     *
     * @param int $port Worker 端口
     */
    public static function setWorkerPort(int $port): void
    {
        self::$workerPort = $port;
    }
    
    /**
     * 获取 Worker 端口
     *
     * @return int Worker 端口
     */
    public static function getWorkerPort(): int
    {
        return self::$workerPort;
    }
    
    /**
     * 设置是否启用
     *
     * @param bool $enabled 是否启用
     */
    public static function setEnabled(bool $enabled): void
    {
        self::$enabled = $enabled;
    }
    
    /**
     * 是否已启用
     *
     * @return bool 是否启用
     */
    public static function isEnabled(): bool
    {
        return self::$enabled && self::$workerPort > 0;
    }
    
    /**
     * 生成路由提示头值
     *
     * @param string $sni SNI 主机名（可选）
     * @param int|null $ttl 缓存 TTL（秒），null 使用默认值
     * @return string 路由提示头值
     */
    public static function generateHint(string $sni = '', ?int $ttl = null): string
    {
        $parts = ['port=' . self::$workerPort];
        
        if (!empty($sni)) {
            $parts[] = 'sni=' . $sni;
        }
        
        $parts[] = 'ttl=' . ($ttl ?? self::$defaultTtl);
        
        return \implode(',', $parts);
    }
    
    /**
     * 为 HTTP 响应字符串添加路由提示头
     *
     * @param string $response HTTP 响应字符串
     * @param string $sni SNI 主机名（可选）
     * @param int|null $ttl 缓存 TTL（秒）
     * @return string 添加头部后的响应
     */
    public static function addHintToResponse(string $response, string $sni = '', ?int $ttl = null): string
    {
        if (!self::isEnabled()) {
            return $response;
        }
        
        // 查找头部结束位置
        $headerEnd = \strpos($response, "\r\n\r\n");
        if ($headerEnd === false) {
            return $response;
        }
        
        // 检查是否已有路由提示头
        $headers = \substr($response, 0, $headerEnd);
        if (\stripos($headers, self::HEADER_NAME) !== false) {
            return $response;
        }
        
        // 插入路由提示头
        $hint = self::generateHint($sni, $ttl);
        // $headers 本身通常以 \r\n 结尾（最后一行 header 后包含换行）。
        // 这里不应再额外插入空行，否则会把额外的 \r\n 带到 body 前，造成 Content-Length 与实际收到字节不一致。
        return \substr_replace($response, self::HEADER_NAME . ': ' . $hint . "\r\n", $headerEnd + 2, 0);
    }
    
    /**
     * 为 WlsResponse 添加路由提示头
     *
     * @param \Weline\Framework\Http\WlsResponse $response WLS 响应对象
     * @param string $sni SNI 主机名（可选）
     * @param int|null $ttl 缓存 TTL（秒）
     */
    public static function addHintToWlsResponse(\Weline\Framework\Http\WlsResponse $response, string $sni = '', ?int $ttl = null): void
    {
        if (!self::isEnabled()) {
            return;
        }
        
        $hint = self::generateHint($sni, $ttl);
        $response->setHeader(self::HEADER_NAME, $hint);
    }

    /**
     * Add a route hint header to the unified framework Response.
     */
    public static function addHintToFrameworkResponse(\Weline\Framework\Http\Response $response, string $sni = '', ?int $ttl = null): void
    {
        if (!self::isEnabled()) {
            return;
        }

        $hint = self::generateHint($sni, $ttl);
        $response->setHeader(self::HEADER_NAME, $hint);
    }
    
    /**
     * 从请求中提取 SNI（如果可用）
     *
     * 在 TCP 透传模式下，Dispatcher 可能会将 SNI 作为头部传递给 Worker
     *
     * @param array $headers 请求头数组
     * @return string SNI 主机名，未找到返回空字符串
     */
    public static function extractSniFromHeaders(array $headers): string
    {
        // 优先检查 Weline 自定义头
        foreach ($headers as $name => $value) {
            $lowerName = \strtolower($name);
            if ($lowerName === 'weline-sni' || $lowerName === 'x-weline-sni') {
                return \is_array($value) ? ($value[0] ?? '') : $value;
            }
        }
        
        // 回退到 Host 头
        foreach ($headers as $name => $value) {
            if (\strtolower($name) === 'host') {
                $host = \is_array($value) ? ($value[0] ?? '') : $value;
                // 移除端口号
                $colonPos = \strpos($host, ':');
                if ($colonPos !== false) {
                    $host = \substr($host, 0, $colonPos);
                }
                return \strtolower($host);
            }
        }
        
        return '';
    }
    
    /**
     * 从原始请求中提取 SNI
     *
     * @param string $rawRequest 原始 HTTP 请求
     * @return string SNI 主机名，未找到返回空字符串
     */
    public static function extractSniFromRawRequest(string $rawRequest): string
    {
        // 检查 Weline-SNI 或 X-Weline-SNI 头
        if (\preg_match('/^(?:Weline-SNI|X-Weline-SNI):\s*([^\r\n]+)/mi', $rawRequest, $matches)) {
            return \trim($matches[1]);
        }
        
        // 回退到 Host 头
        if (\preg_match('/^Host:\s*([^\r\n:]+)/mi', $rawRequest, $matches)) {
            return \strtolower(\trim($matches[1]));
        }
        
        return '';
    }
    
    /**
     * 解析路由提示头值
     *
     * @param string $hintValue 路由提示头值
     * @return array{port: int, sni: string, ttl: int} 解析后的路由信息
     */
    public static function parseHint(string $hintValue): array
    {
        $result = ['port' => 0, 'sni' => '', 'ttl' => 3600];
        
        $parts = \explode(',', $hintValue);
        foreach ($parts as $part) {
            $part = \trim($part);
            if (\str_starts_with($part, 'port=')) {
                $result['port'] = (int) \substr($part, 5);
            } elseif (\str_starts_with($part, 'sni=')) {
                $result['sni'] = \strtolower(\substr($part, 4));
            } elseif (\str_starts_with($part, 'ttl=')) {
                $result['ttl'] = (int) \substr($part, 4);
            }
        }
        
        return $result;
    }
}
