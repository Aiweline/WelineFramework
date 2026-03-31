<?php
declare(strict_types=1);

/**
 * Weline Framework - Header 收集器
 * 
 * 统一管理 HTTP 响应头，替代直接调用 header() 函数。
 * 这是请求级单例，每个请求一个实例。
 * 
 * @author Aiweline
 * @email aiweline@qq.com
 */

namespace Weline\Framework\Http;

use Weline\Framework\Runtime\StateManager;

/**
 * Header 收集器
 * 
 * 特点：
 * - 收集响应头，不立即发送
 * - 支持 Cookie 管理
 * - 请求级单例，WLS 模式下每个请求重置
 */
class HeaderCollector implements HeaderCollectorInterface
{
    /**
     * 当前实例
     */
    private static ?HeaderCollector $instance = null;
    
    /**
     * 收集的响应头
     */
    private array $headers = [];
    
    /**
     * 收集的 Cookie
     */
    private array $cookies = [];
    
    /**
     * HTTP 状态码
     */
    private int $statusCode = 200;

    /**
     * Whether the HTTP status code was explicitly overridden during this request.
     */
    private bool $statusCodeExplicitlySet = false;
    
    /**
     * 私有构造函数，强制使用 getInstance()
     */
    private function __construct()
    {
        // 注册自动重置
        StateManager::registerResetCallback('header_collector', function () {
            self::reset();
        });
    }
    
    /**
     * 获取单例实例
     * 
     * @return HeaderCollector
     */
    public static function getInstance(): HeaderCollector
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * 重置实例（用于 WLS 模式下每个请求后）
     */
    public static function reset(): void
    {
        if (self::$instance !== null) {
            self::$instance->headers = [];
            self::$instance->cookies = [];
            self::$instance->statusCode = 200;
            self::$instance->statusCodeExplicitlySet = false;
        }
    }
    
    /**
     * @inheritDoc
     */
    public function setHeader(string $name, string $value, bool $replace = true): static
    {
        $normalizedName = $this->normalizeHeaderName($name);
        
        if ($replace || !isset($this->headers[$normalizedName])) {
            $this->headers[$normalizedName] = $value;
        } elseif (\is_array($this->headers[$normalizedName])) {
            $this->headers[$normalizedName][] = $value;
        } else {
            $this->headers[$normalizedName] = [$this->headers[$normalizedName], $value];
        }
        
        return $this;
    }
    
    /**
     * @inheritDoc
     */
    public function setHeaders(array $headers, bool $replace = true): static
    {
        foreach ($headers as $name => $value) {
            $this->setHeader($name, $value, $replace);
        }
        return $this;
    }
    
    /**
     * @inheritDoc
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }
    
    /**
     * @inheritDoc
     */
    public function getHeader(string $name): string|array|null
    {
        $normalizedName = $this->normalizeHeaderName($name);
        return $this->headers[$normalizedName] ?? null;
    }
    
    /**
     * @inheritDoc
     */
    public function hasHeader(string $name): bool
    {
        $normalizedName = $this->normalizeHeaderName($name);
        return isset($this->headers[$normalizedName]);
    }
    
    /**
     * @inheritDoc
     */
    public function removeHeader(string $name): static
    {
        $normalizedName = $this->normalizeHeaderName($name);
        unset($this->headers[$normalizedName]);
        return $this;
    }
    
    /**
     * @inheritDoc
     */
    public function clearHeaders(): static
    {
        $this->headers = [];
        return $this;
    }
    
    /**
     * @inheritDoc
     */
    public function setStatusCode(int $code): static
    {
        $this->statusCode = $code;
        $this->statusCodeExplicitlySet = true;
        return $this;
    }
    
    /**
     * @inheritDoc
     */
    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    /**
     * Whether the current request explicitly set an HTTP status override.
     */
    public function hasExplicitStatusCode(): bool
    {
        return $this->statusCodeExplicitlySet;
    }
    
    /**
     * @inheritDoc
     */
    public function setCookie(
        string $name,
        string $value,
        int $expire = 0,
        string $path = '/',
        string $domain = '',
        bool $secure = false,
        bool $httpOnly = true,
        string $sameSite = 'Lax'
    ): static {
        $this->cookies[$name] = [
            'name' => $name,
            'value' => $value,
            'expire' => $expire,
            'path' => $path,
            'domain' => $domain,
            'secure' => $secure,
            'httpOnly' => $httpOnly,
            'sameSite' => $sameSite,
        ];
        return $this;
    }

    /**
     * 移除已收集的 Cookie（按名称），响应中不再发送该 Set-Cookie。
     *
     * @param string $name Cookie 名称
     * @return static
     */
    public function removeCookie(string $name): static
    {
        unset($this->cookies[$name]);
        return $this;
    }
    
    /**
     * 获取所有 Cookie
     * 
     * @return array
     */
    public function getCookies(): array
    {
        return $this->cookies;
    }
    
    /**
     * 发送所有响应头（FPM 模式使用）
     * 
     * @param bool $sendStatusCode 是否发送状态码
     */
    public function emit(bool $sendStatusCode = true): void
    {
        if (\headers_sent()) {
            return;
        }
        
        // 发送状态码
        if ($sendStatusCode && $this->statusCode !== 200) {
            \http_response_code($this->statusCode);
        }
        
        // 发送响应头
        foreach ($this->headers as $name => $value) {
            if (\is_array($value)) {
                foreach ($value as $v) {
                    \header("{$name}: {$v}", false);
                }
            } else {
                \header("{$name}: {$value}");
            }
        }
        
        // 发送 Cookie
        foreach ($this->cookies as $cookie) {
            \setcookie(
                $cookie['name'],
                $cookie['value'],
                [
                    'expires' => $cookie['expire'],
                    'path' => $cookie['path'],
                    'domain' => $cookie['domain'],
                    'secure' => $cookie['secure'],
                    'httponly' => $cookie['httpOnly'],
                    'samesite' => $cookie['sameSite'],
                ]
            );
        }
    }
    
    /**
     * 生成 HTTP 响应头字符串（WLS 模式使用）
     * 
     * @return string HTTP 头字符串
     */
    public function toHttpHeaderString(): string
    {
        $statusText = $this->getStatusText($this->statusCode);
        $headerString = "HTTP/1.1 {$this->statusCode} {$statusText}\r\n";
        
        // 响应头
        foreach ($this->headers as $name => $value) {
            if (\is_array($value)) {
                foreach ($value as $v) {
                    $headerString .= "{$name}: {$v}\r\n";
                }
            } else {
                $headerString .= "{$name}: {$value}\r\n";
            }
        }
        
        // Cookie 头
        foreach ($this->cookies as $cookie) {
            $headerString .= "Set-Cookie: " . $this->buildCookieString($cookie) . "\r\n";
        }
        
        return $headerString;
    }
    
    /**
     * 构建 Cookie 字符串
     */
    private function buildCookieString(array $cookie): string
    {
        $parts = [\urlencode($cookie['name']) . '=' . \urlencode($cookie['value'])];
        
        if ($cookie['expire'] !== 0) {
            $parts[] = 'Expires=' . \gmdate('D, d M Y H:i:s T', $cookie['expire']);
        }
        
        if ($cookie['path'] !== '') {
            $parts[] = 'Path=' . $cookie['path'];
        }
        
        if ($cookie['domain'] !== '') {
            $parts[] = 'Domain=' . $cookie['domain'];
        }
        
        if ($cookie['secure']) {
            $parts[] = 'Secure';
        }
        
        if ($cookie['httpOnly']) {
            $parts[] = 'HttpOnly';
        }
        
        if ($cookie['sameSite'] !== '') {
            $parts[] = 'SameSite=' . $cookie['sameSite'];
        }
        
        return \implode('; ', $parts);
    }
    
    /**
     * 规范化 header 名称（首字母大写）
     */
    private function normalizeHeaderName(string $name): string
    {
        return \str_replace(' ', '-', \ucwords(\str_replace('-', ' ', \strtolower($name))));
    }
    
    /**
     * 获取状态码对应的文本
     */
    private function getStatusText(int $code): string
    {
        static $statusTexts = [
            100 => 'Continue',
            101 => 'Switching Protocols',
            200 => 'OK',
            201 => 'Created',
            204 => 'No Content',
            206 => 'Partial Content',
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
            408 => 'Request Timeout',
            429 => 'Too Many Requests',
            500 => 'Internal Server Error',
            502 => 'Bad Gateway',
            503 => 'Service Unavailable',
            504 => 'Gateway Timeout',
        ];
        
        return $statusTexts[$code] ?? 'Unknown';
    }
}
