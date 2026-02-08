<?php
declare(strict_types=1);

/**
 * Weline Server - HTTP Request
 * 
 * @author Aiweline
 * @email aiweline@qq.com
 */

namespace Weline\Server\Protocol;

/**
 * Request - HTTP 请求对象
 */
class Request
{
    /**
     * 请求方法
     */
    protected string $method = '';
    
    /**
     * 请求 URI
     */
    protected string $uri = '';
    
    /**
     * 请求路径
     */
    protected string $path = '';
    
    /**
     * 查询字符串
     */
    protected string $queryString = '';
    
    /**
     * HTTP 版本
     */
    protected string $httpVersion = '';
    
    /**
     * 请求头
     */
    protected array $headers = [];
    
    /**
     * GET 参数
     */
    protected array $get = [];
    
    /**
     * POST 参数
     */
    protected array $post = [];
    
    /**
     * Cookie
     */
    protected array $cookies = [];
    
    /**
     * 上传的文件
     */
    protected array $files = [];
    
    /**
     * 请求体（原始数据）
     */
    protected string $rawBody = '';
    
    /**
     * Session ID
     */
    protected string $sessionId = '';
    
    /**
     * 原始请求数据
     */
    protected string $rawData = '';
    
    /**
     * 构造函数
     */
    public function __construct(string $buffer)
    {
        $this->rawData = $buffer;
        $this->parse($buffer);
    }
    
    /**
     * 解析 HTTP 请求
     */
    protected function parse(string $buffer): void
    {
        // 分离 header 和 body
        $headerEnd = strpos($buffer, "\r\n\r\n");
        
        if ($headerEnd === false) {
            return;
        }
        
        $headerPart = substr($buffer, 0, $headerEnd);
        $this->rawBody = substr($buffer, $headerEnd + 4);
        
        // 解析请求行
        $lines = explode("\r\n", $headerPart);
        $requestLine = array_shift($lines);
        
        if (!$requestLine) {
            return;
        }
        
        $parts = explode(' ', $requestLine, 3);
        
        if (count($parts) < 3) {
            return;
        }
        
        $this->method = strtoupper($parts[0]);
        $this->uri = $parts[1];
        $this->httpVersion = $parts[2];
        
        // 解析 URI
        $uriParts = parse_url($this->uri);
        $this->path = $uriParts['path'] ?? '/';
        $this->queryString = $uriParts['query'] ?? '';
        
        // 解析 GET 参数
        if ($this->queryString) {
            parse_str($this->queryString, $this->get);
        }
        
        // 解析请求头
        foreach ($lines as $line) {
            $colonPos = strpos($line, ':');
            
            if ($colonPos !== false) {
                $name = strtolower(trim(substr($line, 0, $colonPos)));
                $value = trim(substr($line, $colonPos + 1));
                $this->headers[$name] = $value;
            }
        }
        
        // 解析 Cookie
        if (isset($this->headers['cookie'])) {
            $this->parseCookies($this->headers['cookie']);
        }
        
        // 解析 POST 数据
        if ($this->rawBody && in_array($this->method, ['POST', 'PUT', 'PATCH'])) {
            $this->parseBody();
        }
    }
    
    /**
     * 解析 Cookie
     */
    protected function parseCookies(string $cookieHeader): void
    {
        $cookies = explode(';', $cookieHeader);
        
        foreach ($cookies as $cookie) {
            $cookie = trim($cookie);
            $equalPos = strpos($cookie, '=');
            
            if ($equalPos !== false) {
                $name = trim(substr($cookie, 0, $equalPos));
                $value = trim(substr($cookie, $equalPos + 1));
                $this->cookies[$name] = urldecode($value);
            }
        }
    }
    
    /**
     * 解析请求体
     */
    protected function parseBody(): void
    {
        $contentType = $this->headers['content-type'] ?? '';
        
        // application/x-www-form-urlencoded
        if (stripos($contentType, 'application/x-www-form-urlencoded') !== false) {
            parse_str($this->rawBody, $this->post);
            return;
        }
        
        // application/json
        if (stripos($contentType, 'application/json') !== false) {
            $data = json_decode($this->rawBody, true);
            if (is_array($data)) {
                $this->post = $data;
            }
            return;
        }
        
        // multipart/form-data
        if (stripos($contentType, 'multipart/form-data') !== false) {
            $this->parseMultipart($contentType);
            return;
        }
    }
    
    /**
     * 解析 multipart/form-data
     */
    protected function parseMultipart(string $contentType): void
    {
        // 获取 boundary
        if (!preg_match('/boundary=(.+?)(?:;|$)/i', $contentType, $match)) {
            return;
        }
        
        $boundary = trim($match[1], '"');
        $parts = explode("--{$boundary}", $this->rawBody);
        
        // 移除第一个（空）和最后一个（结束标记）
        array_shift($parts);
        array_pop($parts);
        
        foreach ($parts as $part) {
            $part = ltrim($part, "\r\n");
            
            // 分离 header 和 content
            $headerEnd = strpos($part, "\r\n\r\n");
            
            if ($headerEnd === false) {
                continue;
            }
            
            $partHeader = substr($part, 0, $headerEnd);
            $partContent = substr($part, $headerEnd + 4);
            $partContent = rtrim($partContent, "\r\n");
            
            // 解析 Content-Disposition
            if (!preg_match('/Content-Disposition:\s*form-data;\s*name="([^"]+)"(?:;\s*filename="([^"]+)")?/i', $partHeader, $match)) {
                continue;
            }
            
            $name = $match[1];
            $filename = $match[2] ?? null;
            
            if ($filename !== null) {
                // 文件上传
                $contentTypeMatch = [];
                preg_match('/Content-Type:\s*([^\r\n]+)/i', $partHeader, $contentTypeMatch);
                $fileContentType = $contentTypeMatch[1] ?? 'application/octet-stream';
                
                // 保存到临时文件
                $tmpFile = tempnam(sys_get_temp_dir(), 'weline_upload_');
                file_put_contents($tmpFile, $partContent);
                
                $this->files[$name] = [
                    'name' => $filename,
                    'type' => $fileContentType,
                    'tmp_name' => $tmpFile,
                    'error' => UPLOAD_ERR_OK,
                    'size' => strlen($partContent),
                ];
            } else {
                // 普通表单字段
                $this->post[$name] = $partContent;
            }
        }
    }
    
    /**
     * 获取请求方法
     */
    public function method(): string
    {
        return $this->method;
    }
    
    /**
     * 获取请求 URI
     */
    public function uri(): string
    {
        return $this->uri;
    }
    
    /**
     * 获取请求路径
     */
    public function path(): string
    {
        return $this->path;
    }
    
    /**
     * 获取查询字符串
     */
    public function queryString(): string
    {
        return $this->queryString;
    }
    
    /**
     * 获取 HTTP 版本
     */
    public function httpVersion(): string
    {
        return $this->httpVersion;
    }
    
    /**
     * 获取请求头
     */
    public function header(?string $name = null, mixed $default = null): mixed
    {
        if ($name === null) {
            return $this->headers;
        }
        
        $name = strtolower($name);
        return $this->headers[$name] ?? $default;
    }
    
    /**
     * 获取 GET 参数
     */
    public function get(?string $name = null, mixed $default = null): mixed
    {
        if ($name === null) {
            return $this->get;
        }
        
        return $this->get[$name] ?? $default;
    }
    
    /**
     * 获取 POST 参数
     */
    public function post(?string $name = null, mixed $default = null): mixed
    {
        if ($name === null) {
            return $this->post;
        }
        
        return $this->post[$name] ?? $default;
    }
    
    /**
     * 获取输入参数（GET + POST）
     */
    public function input(?string $name = null, mixed $default = null): mixed
    {
        $all = array_merge($this->get, $this->post);
        
        if ($name === null) {
            return $all;
        }
        
        return $all[$name] ?? $default;
    }
    
    /**
     * 获取 Cookie
     */
    public function cookie(?string $name = null, mixed $default = null): mixed
    {
        if ($name === null) {
            return $this->cookies;
        }
        
        return $this->cookies[$name] ?? $default;
    }
    
    /**
     * 获取上传的文件
     */
    public function file(?string $name = null): mixed
    {
        if ($name === null) {
            return $this->files;
        }
        
        return $this->files[$name] ?? null;
    }
    
    /**
     * 获取原始请求体
     */
    public function rawBody(): string
    {
        return $this->rawBody;
    }
    
    /**
     * 获取 JSON 解码后的请求体
     */
    public function json(): mixed
    {
        return json_decode($this->rawBody, true);
    }
    
    /**
     * 获取客户端 IP
     */
    public function ip(): string
    {
        // 检查代理头
        $headers = ['x-forwarded-for', 'x-real-ip', 'client-ip'];
        
        foreach ($headers as $header) {
            if (isset($this->headers[$header])) {
                $ips = explode(',', $this->headers[$header]);
                $ip = trim($ips[0]);
                
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }
        
        return '';
    }
    
    /**
     * 获取 Host
     */
    public function host(): string
    {
        return $this->headers['host'] ?? '';
    }
    
    /**
     * 检查是否为 AJAX 请求
     */
    public function isAjax(): bool
    {
        return strtolower($this->headers['x-requested-with'] ?? '') === 'xmlhttprequest';
    }
    
    /**
     * 检查是否为 HTTPS 请求
     */
    public function isSecure(): bool
    {
        return strtolower($this->headers['x-forwarded-proto'] ?? '') === 'https';
    }
    
    /**
     * 获取 User-Agent
     */
    public function userAgent(): string
    {
        return $this->headers['user-agent'] ?? '';
    }
    
    /**
     * 获取 Referer
     */
    public function referer(): string
    {
        return $this->headers['referer'] ?? '';
    }
    
    /**
     * 获取 Accept-Language
     */
    public function acceptLanguage(): string
    {
        return $this->headers['accept-language'] ?? '';
    }
    
    /**
     * 获取原始请求数据
     */
    public function rawData(): string
    {
        return $this->rawData;
    }
}
