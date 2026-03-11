<?php
declare(strict_types=1);

/**
 * Weline Framework - 响应终止异常
 * 
 * 统一的请求终止异常基类。框架内部不再使用 exit()/die()，
 * 而是通过抛出此异常来终止请求流程，由 Runtime 层统一处理。
 * 
 * 这种设计使框架天然支持常驻内存模式（如 WLS），无需在业务代码中
 * 编写模式判断逻辑。
 * 
 * @author Aiweline
 * @email aiweline@qq.com
 */

namespace Weline\Framework\Http;

/**
 * 响应终止异常
 * 
 * 继承 \Error 而非 \Exception，使业务层 `catch (\Exception $e)` 不会意外捕获
 * 重定向等控制流异常，避免 "Response terminate with status 302" 被当成错误。
 * 
 * 所有需要终止请求的场景都通过此异常实现：
 * - 重定向 (RedirectException)
 * - 无路由 (NoRouterException)
 * - 下载文件 (DownloadException)
 * - 直接响应 HTTP 状态码
 * 
 * Runtime 层（FpmRuntime/WlsRuntime）会捕获此异常并根据运行模式处理：
 * - FPM 模式：发送 header 并 exit()
 * - WLS 模式：构建 HTTP 响应字符串返回
 */
class ResponseTerminateException extends \Error
{
    /**
     * HTTP 状态码
     */
    protected int $statusCode;
    
    /**
     * 响应头
     * @var array<string, string>
     */
    protected array $headers = [];
    
    /**
     * 响应体
     */
    protected string $body = '';
    
    /**
     * 构造函数
     * 
     * @param int $statusCode HTTP 状态码
     * @param string $body 响应体
     * @param array $headers 响应头
     */
    public function __construct(int $statusCode = 200, string $body = '', array $headers = [])
    {
        parent::__construct("Response terminate with status {$statusCode}", $statusCode);
        $this->statusCode = $statusCode;
        $this->body = $body;
        $this->headers = $headers;
    }
    
    /**
     * 获取 HTTP 状态码
     */
    public function getStatusCode(): int
    {
        return $this->statusCode;
    }
    
    /**
     * 获取响应头
     * 
     * @return array<string, string>
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }
    
    /**
     * 设置响应头
     */
    public function setHeader(string $name, string $value): static
    {
        $this->headers[$name] = $value;
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
     * 设置响应体
     */
    public function setBody(string $body): static
    {
        $this->body = $body;
        return $this;
    }
    
    /**
     * 构建 HTTP 响应字符串（用于 WLS 模式）
     */
    public function toHttpString(): string
    {
        $statusText = $this->getStatusText($this->statusCode);
        $response = "HTTP/1.1 {$this->statusCode} {$statusText}\r\n";
        
        // 添加 headers
        foreach ($this->headers as $name => $value) {
            $response .= "{$name}: {$value}\r\n";
        }
        
        // 如果没有 Content-Length，自动添加
        if (!isset($this->headers['Content-Length']) && $this->body !== '') {
            $response .= "Content-Length: " . \strlen($this->body) . "\r\n";
        }
        
        // 添加 Connection header
        if (!isset($this->headers['Connection'])) {
            // WLS 常驻模式下默认保持连接，减少 TLS 重握手开销。
            $response .= "Connection: keep-alive\r\n";
        }
        
        $response .= "\r\n";
        $response .= $this->body;
        
        return $response;
    }
    
    /**
     * 发送响应（用于 FPM 模式）
     * 
     * @param bool $terminate 是否终止脚本
     */
    public function emit(bool $terminate = true): void
    {
        if (!\headers_sent()) {
            \http_response_code($this->statusCode);
            foreach ($this->headers as $name => $value) {
                \header("{$name}: {$value}");
            }
        }
        
        if ($this->body !== '') {
            echo $this->body;
        }
        
        if ($terminate) {
            exit(0);
        }
    }
    
    /**
     * 获取 HTTP 状态文本
     */
    protected function getStatusText(int $code): string
    {
        static $statusTexts = [
            200 => 'OK',
            201 => 'Created',
            204 => 'No Content',
            301 => 'Moved Permanently',
            302 => 'Found',
            303 => 'See Other',
            304 => 'Not Modified',
            307 => 'Temporary Redirect',
            308 => 'Permanent Redirect',
            400 => 'Bad Request',
            401 => 'Unauthorized',
            403 => 'Forbidden',
            404 => 'Not Found',
            405 => 'Method Not Allowed',
            413 => 'Request Entity Too Large',
            500 => 'Internal Server Error',
            502 => 'Bad Gateway',
            503 => 'Service Unavailable',
        ];
        
        return $statusTexts[$code] ?? 'Unknown';
    }
}
