<?php
declare(strict_types=1);

/**
 * Weline Framework - WLS 响应对象
 * 
 * 将框架响应转换为 HTTP 响应字符串
 * 
 * @author Aiweline
 * @email aiweline@qq.com
 */

namespace Weline\Framework\Http;

/**
 * WLS 响应对象
 * 
 * 功能：
 * - 将框架响应转换为 HTTP 响应字符串
 * - 支持 HTTP/1.1
 * - 支持 Keep-Alive
 * - 支持压缩
 */
class WlsResponse
{
    /**
     * WLS 服务器版本号
     */
    public const SERVER_VERSION = '1.0.0';
    
    /**
     * 服务器标识（类似 nginx/1.24.0, Apache/2.4.57）
     */
    public const SERVER_SIGNATURE = 'Weline-Server/' . self::SERVER_VERSION;
    
    /**
     * HTTP 状态码
     */
    private int $statusCode = 200;
    
    /**
     * 状态消息
     */
    private string $statusMessage = 'OK';
    
    /**
     * 响应头
     */
    private array $headers = [];
    
    /**
     * 响应体
     */
    private string $body = '';
    
    /**
     * Set-Cookie 头（支持多个 Cookie）
     */
    private array $cookies = [];
    
    /**
     * HTTP 状态码消息映射
     */
    private const STATUS_MESSAGES = [
        200 => 'OK',
        201 => 'Created',
        204 => 'No Content',
        301 => 'Moved Permanently',
        302 => 'Found',
        304 => 'Not Modified',
        400 => 'Bad Request',
        401 => 'Unauthorized',
        403 => 'Forbidden',
        404 => 'Not Found',
        405 => 'Method Not Allowed',
        500 => 'Internal Server Error',
        502 => 'Bad Gateway',
        503 => 'Service Unavailable',
    ];
    
    /**
     * 创建响应对象
     * 
     * @param string $body 响应体
     * @param int $statusCode 状态码
     * @param array $headers 响应头
     */
    public function __construct(string $body = '', int $statusCode = 200, array $headers = [])
    {
        $this->body = $body;
        $this->statusCode = $statusCode;
        $this->statusMessage = self::STATUS_MESSAGES[$statusCode] ?? 'Unknown';
        $this->headers = $headers;
    }
    
    /**
     * 从框架响应内容创建
     * 
     * @param string $content 响应内容
     * @param int $statusCode 状态码
     * @param string|null $contentType 内容类型
     * @return self
     */
    public static function fromContent(string $content, int $statusCode = 200, ?string $contentType = null): self
    {
        $response = new self($content, $statusCode);
        
        // 自动检测内容类型
        if ($contentType === null) {
            $contentType = self::detectContentType($content);
        }
        
        $response->setHeader('Content-Type', $contentType);
        
        return $response;
    }
    
    /**
     * 创建 JSON 响应
     */
    public static function json(array $data, int $statusCode = 200): self
    {
        $response = new self(\json_encode($data, JSON_UNESCAPED_UNICODE), $statusCode);
        $response->setHeader('Content-Type', 'application/json; charset=utf-8');
        return $response;
    }
    
    /**
     * 创建 HTML 响应
     */
    public static function html(string $html, int $statusCode = 200): self
    {
        $response = new self($html, $statusCode);
        $response->setHeader('Content-Type', 'text/html; charset=utf-8');
        return $response;
    }
    
    /**
     * 创建重定向响应
     */
    public static function redirect(string $url, int $statusCode = 302): self
    {
        $response = new self('', $statusCode);
        $response->setHeader('Location', $url);
        return $response;
    }
    
    /**
     * 创建错误响应
     */
    public static function error(string $message, int $statusCode = 500): self
    {
        $response = new self($message, $statusCode);
        $response->setHeader('Content-Type', 'text/plain; charset=utf-8');
        return $response;
    }
    
    /**
     * 设置状态码
     */
    public function setStatusCode(int $code): self
    {
        $this->statusCode = $code;
        $this->statusMessage = self::STATUS_MESSAGES[$code] ?? 'Unknown';
        return $this;
    }
    
    /**
     * 获取状态码
     */
    public function getStatusCode(): int
    {
        return $this->statusCode;
    }
    
    /**
     * 设置响应头
     */
    public function setHeader(string $name, string $value): self
    {
        $this->headers[$name] = $value;
        return $this;
    }
    
    /**
     * 添加 Set-Cookie 头（支持多个 Cookie，不会覆盖）
     * 
     * @param string $cookieString 完整的 Set-Cookie 值字符串
     * @return self
     */
    public function addCookieHeader(string $cookieString): self
    {
        $this->cookies[] = $cookieString;
        return $this;
    }
    
    /**
     * 获取响应头
     */
    public function getHeader(string $name): ?string
    {
        return $this->headers[$name] ?? null;
    }
    
    /**
     * 获取所有响应头
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }
    
    /**
     * 设置响应体
     */
    public function setBody(string $body): self
    {
        $this->body = $body;
        return $this;
    }
    
    /**
     * 获取响应体
     */
    public function getBody(): string
    {
        return $this->body;
    }
    
    /**
     * 转换为 HTTP 响应字符串
     * 
     * @param bool $keepAlive 是否保持连接
     * @return string
     */
    public function toHttpString(bool $keepAlive = true): string
    {
        $body = $this->body;
        
        // 设置默认头
        if (!isset($this->headers['Content-Type'])) {
            $this->headers['Content-Type'] = self::detectContentType($body);
        }
        
        $this->headers['Content-Length'] = (string) \strlen($body);
        $this->headers['Connection'] = $keepAlive ? 'keep-alive' : 'close';
        
        // 添加服务器标识（类似 nginx/1.24.0, Apache/2.4.57）
        if (!isset($this->headers['Server'])) {
            $this->headers['Server'] = self::SERVER_SIGNATURE;
        }
        
        // 添加 X-Powered-By 标识
        if (!isset($this->headers['X-Powered-By'])) {
            $this->headers['X-Powered-By'] = 'WLS/' . self::SERVER_VERSION . ' PHP/' . PHP_VERSION;
        }
        
        // 添加日期
        if (!isset($this->headers['Date'])) {
            $this->headers['Date'] = \gmdate('D, d M Y H:i:s') . ' GMT';
        }
        
        // 构建响应
        $response = "HTTP/1.1 {$this->statusCode} {$this->statusMessage}\r\n";
        
        foreach ($this->headers as $name => $value) {
            $response .= "{$name}: {$value}\r\n";
        }
        
        // Set-Cookie 头（支持多个，每个独立一行）
        foreach ($this->cookies as $cookieString) {
            $response .= "Set-Cookie: {$cookieString}\r\n";
        }
        
        $response .= "\r\n";
        $response .= $body;
        
        return $response;
    }
    
    /**
     * 检测内容类型
     */
    private static function detectContentType(string $content): string
    {
        $trimmed = \ltrim($content);
        
        // JSON 检测
        if (($trimmed[0] ?? '') === '{' || ($trimmed[0] ?? '') === '[') {
            $decoded = \json_decode($content);
            if (\json_last_error() === JSON_ERROR_NONE) {
                return 'application/json; charset=utf-8';
            }
        }
        
        // HTML 检测 - 增强检测逻辑
        // 1. 检查 <!DOCTYPE 开头
        if (\stripos($trimmed, '<!DOCTYPE') === 0) {
            return 'text/html; charset=utf-8';
        }
        // 2. 检查 <html 标签
        if (\stripos($trimmed, '<html') !== false) {
            return 'text/html; charset=utf-8';
        }
        // 3. 检查常见的 HTML 标签开头（处理布局模板没有正确包含的情况）
        // 这些标签表明内容是 HTML 片段，应该作为 HTML 返回
        $htmlStartTags = ['<div', '<span', '<p', '<h1', '<h2', '<h3', '<h4', '<h5', '<h6', 
                          '<table', '<form', '<ul', '<ol', '<li', '<nav', '<header', '<footer',
                          '<main', '<section', '<article', '<aside', '<head', '<body', '<meta',
                          '<link', '<script', '<style', '<!--', '<w:', '<template'];
        foreach ($htmlStartTags as $tag) {
            if (\stripos($trimmed, $tag) === 0) {
                return 'text/html; charset=utf-8';
            }
        }
        // 4. 检查内容中是否包含 HTML 结构特征（更宽松的检测）
        if (\preg_match('/<(div|span|p|a|img|table|form|ul|ol|li|h[1-6]|head|body|html|script|style)\b/i', $trimmed)) {
            return 'text/html; charset=utf-8';
        }
        
        // XML 检测
        if (\stripos($trimmed, '<?xml') === 0) {
            return 'application/xml; charset=utf-8';
        }
        
        // 默认纯文本
        return 'text/plain; charset=utf-8';
    }
    
    /**
     * 支持 Gzip 压缩
     */
    public function compress(string $acceptEncoding = ''): self
    {
        if (empty($this->body) || \strlen($this->body) < 1024) {
            return $this;
        }
        
        if (\stripos($acceptEncoding, 'gzip') !== false && \function_exists('gzencode')) {
            $compressed = \gzencode($this->body, 6);
            if ($compressed !== false) {
                $this->body = $compressed;
                $this->headers['Content-Encoding'] = 'gzip';
            }
        }
        
        return $this;
    }
}
