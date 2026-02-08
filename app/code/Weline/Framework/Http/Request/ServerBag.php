<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Http\Request;

use Weline\Framework\Runtime\RequestContext;

/**
 * ServerBag - 服务器变量管理类
 * 
 * 封装 $_SERVER 访问，遵循单一职责原则。
 * 提供统一的服务器变量访问接口，支持 WLS 模式下的隔离。
 * 
 * @since PHP 8.4
 */
class ServerBag
{
    /**
     * 服务器变量存储
     */
    private array $server = [];
    
    /**
     * Headers 缓存
     */
    private ?array $headers = null;
    
    /**
     * 是否已初始化
     */
    private bool $initialized = false;
    
    /**
     * 构造函数
     * 
     * @param array $server 服务器变量（可选，默认从 $_SERVER 获取）
     */
    public function __construct(array $server = [])
    {
        $this->server = $server;
    }
    
    /**
     * 从超全局变量初始化
     * 
     * WLS 模式下，每个新请求都必须重新初始化，以获取正确的 $_SERVER 值。
     * 通过检查当前请求 ID 是否与上次初始化时相同来判断是否需要重新初始化。
     * 
     * @param bool $force 是否强制重新初始化（WLS 模式下每个请求都应该调用）
     * @return static
     */
    public function initFromGlobals(bool $force = false): static
    {
        // WLS 模式下，检测是否是新请求
        if (!$force && $this->initialized) {
            // 检查是否是新请求（通过 RequestContext 的请求 ID）
            if (\class_exists(\Weline\Framework\Runtime\RequestContext::class, false)) {
                $currentRequestId = \Weline\Framework\Runtime\RequestContext::getRequestId();
                $lastRequestId = $this->server['__SERVERBAG_REQUEST_ID__'] ?? null;
                
                // 如果请求 ID 相同，直接返回（同一个请求内的多次调用）
                if ($currentRequestId !== null && $currentRequestId === $lastRequestId) {
                    return $this;
                }
                // 请求 ID 不同，需要重新初始化
            } else {
                // 非 WLS 模式或 RequestContext 不可用，直接返回
                return $this;
            }
        }
        
        $this->server = $_SERVER;
        $this->headers = null; // 清除缓存
        $this->initialized = true;
        
        // 记录当前请求 ID，用于下次比较
        if (\class_exists(\Weline\Framework\Runtime\RequestContext::class, false)) {
            $this->server['__SERVERBAG_REQUEST_ID__'] = \Weline\Framework\Runtime\RequestContext::getRequestId();
        }
        
        return $this;
    }
    
    /**
     * 从数组初始化（用于 WLS 模式）
     * 
     * @param array $server 服务器变量数组
     * @return static
     */
    public function initFromArray(array $server): static
    {
        $this->server = $server;
        $this->headers = null;
        $this->initialized = true;
        return $this;
    }
    
    // ==================== 基础访问方法 ====================
    
    /**
     * 获取服务器变量
     * 
     * @param string $key 变量名，空字符串返回所有
     * @param mixed $default 默认值
     * @return mixed
     */
    public function get(string $key = '', mixed $default = null): mixed
    {
        if ($key === '') {
            return $this->server;
        }
        return $this->server[$key] ?? $default;
    }
    
    /**
     * 设置服务器变量
     * 
     * @param string $key 变量名
     * @param mixed $value 变量值
     * @return static
     */
    public function set(string $key, mixed $value): static
    {
        $this->server[$key] = $value;
        $_SERVER[$key] = $value; // 同步到超全局变量
        $this->headers = null; // 清除 headers 缓存
        return $this;
    }
    
    /**
     * 检查服务器变量是否存在
     * 
     * @param string $key 变量名
     * @return bool
     */
    public function has(string $key): bool
    {
        return isset($this->server[$key]);
    }
    
    /**
     * 删除服务器变量
     * 
     * @param string $key 变量名
     * @return static
     */
    public function remove(string $key): static
    {
        unset($this->server[$key]);
        unset($_SERVER[$key]);
        return $this;
    }
    
    /**
     * 获取所有服务器变量
     * 
     * @return array
     */
    public function all(): array
    {
        return $this->server;
    }
    
    // ==================== HTTP Headers 相关 ====================
    
    /**
     * 获取所有 HTTP Headers
     * 
     * @return array
     */
    public function getHeaders(): array
    {
        if ($this->headers !== null) {
            return $this->headers;
        }
        
        $this->headers = [];
        foreach ($this->server as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $headerName = str_replace('_', '-', substr($key, 5));
                $this->headers[$headerName] = $value;
            } elseif (in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH'], true)) {
                $headerName = str_replace('_', '-', $key);
                $this->headers[$headerName] = $value;
            }
        }
        
        return $this->headers;
    }
    
    /**
     * 获取指定 HTTP Header
     * 
     * @param string $name Header 名称（如 'Authorization', 'Content-Type'）
     * @param mixed $default 默认值
     * @return mixed
     */
    public function getHeader(string $name, mixed $default = null): mixed
    {
        // 尝试直接获取
        $serverKey = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        if (isset($this->server[$serverKey])) {
            return $this->server[$serverKey];
        }
        
        // 特殊处理 Content-Type 和 Content-Length
        $upperName = strtoupper(str_replace('-', '_', $name));
        if (in_array($upperName, ['CONTENT_TYPE', 'CONTENT_LENGTH'], true)) {
            return $this->server[$upperName] ?? $default;
        }
        
        return $default;
    }
    
    /**
     * 检查 HTTP Header 是否存在
     * 
     * @param string $name Header 名称
     * @return bool
     */
    public function hasHeader(string $name): bool
    {
        $serverKey = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        if (isset($this->server[$serverKey])) {
            return true;
        }
        
        $upperName = strtoupper(str_replace('-', '_', $name));
        return in_array($upperName, ['CONTENT_TYPE', 'CONTENT_LENGTH'], true) 
            && isset($this->server[$upperName]);
    }
    
    // ==================== 请求信息快捷方法 ====================
    
    /**
     * 获取请求方法
     * 
     * @return string
     */
    public function getMethod(): string
    {
        return strtoupper($this->get('REQUEST_METHOD', 'GET'));
    }
    
    /**
     * 获取请求 URI
     * 
     * @return string
     */
    public function getRequestUri(): string
    {
        return $this->get('REQUEST_URI', '/');
    }
    
    /**
     * 获取查询字符串
     * 
     * @return string
     */
    public function getQueryString(): string
    {
        return $this->get('QUERY_STRING', '');
    }
    
    /**
     * 获取请求 Host
     * 
     * @return string
     */
    public function getHost(): string
    {
        return $this->get('HTTP_HOST', '');
    }
    
    /**
     * 获取服务器端口
     * 
     * @return int
     */
    public function getPort(): int
    {
        return (int) $this->get('SERVER_PORT', 80);
    }
    
    /**
     * 获取客户端 IP
     * 
     * @return string
     */
    public function getClientIp(): string
    {
        // 按优先级检查各种可能的 IP 来源
        $ipKeys = [
            'HTTP_CLIENT_IP',
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_FORWARDED',
            'HTTP_X_CLUSTER_CLIENT_IP',
            'HTTP_FORWARDED_FOR',
            'HTTP_FORWARDED',
            'REMOTE_ADDR',
        ];
        
        foreach ($ipKeys as $key) {
            $ip = $this->get($key);
            if ($ip) {
                // X-Forwarded-For 可能包含多个 IP，取第一个
                if (str_contains($ip, ',')) {
                    $ip = trim(explode(',', $ip)[0]);
                }
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }
        
        return '0.0.0.0';
    }
    
    /**
     * 获取 User Agent
     * 
     * @return string
     */
    public function getUserAgent(): string
    {
        return $this->get('HTTP_USER_AGENT', '');
    }
    
    /**
     * 获取 Referer
     * 
     * @return string
     */
    public function getReferer(): string
    {
        return $this->get('HTTP_REFERER', '');
    }
    
    /**
     * 获取 Content-Type
     * 
     * @return string
     */
    public function getContentType(): string
    {
        return $this->get('CONTENT_TYPE', '');
    }
    
    /**
     * 获取协议（HTTP/HTTPS）
     * 
     * @return string
     */
    public function getScheme(): string
    {
        $https = $this->get('HTTPS', '');
        if ($https && $https !== 'off') {
            return 'https';
        }
        
        // 检查代理设置的协议头
        $forwardedProto = $this->get('HTTP_X_FORWARDED_PROTO', '');
        if ($forwardedProto === 'https') {
            return 'https';
        }
        
        return 'http';
    }
    
    /**
     * 是否是 HTTPS 请求
     * 
     * @return bool
     */
    public function isSecure(): bool
    {
        return $this->getScheme() === 'https';
    }
    
    /**
     * 是否是 AJAX 请求
     * 
     * @return bool
     */
    public function isAjax(): bool
    {
        return strtolower($this->get('HTTP_X_REQUESTED_WITH', '')) === 'xmlhttprequest';
    }
    
    // ==================== Weline 框架特定变量 ====================
    
    /**
     * 获取 Weline Area
     * 
     * @return string
     */
    public function getWelineArea(): string
    {
        // 优先从 RequestContext 获取（支持 WLS 隔离）
        if (class_exists(RequestContext::class)) {
            return RequestContext::getWelineArea() ?? $this->get('WELINE_AREA', 'frontend');
        }
        return $this->get('WELINE_AREA', 'frontend');
    }
    
    /**
     * 是否是后台请求
     * 
     * @return bool
     */
    public function isBackend(): bool
    {
        if (class_exists(RequestContext::class)) {
            return RequestContext::isBackendArea();
        }
        return (bool) $this->get('WELINE_IS_BACKEND', false);
    }
    
    /**
     * 获取 Weline Route 前缀
     * 
     * @return string
     */
    public function getWelineAreaRoute(): string
    {
        if (class_exists(RequestContext::class)) {
            return RequestContext::getWelineAreaRoute() ?? $this->get('WELINE_AREA_ROUTE', '');
        }
        return $this->get('WELINE_AREA_ROUTE', '');
    }
    
    // ==================== 认证相关 ====================
    
    /**
     * 获取 Bearer Token
     * 
     * @return string|null
     */
    public function getBearerToken(): ?string
    {
        $auth = $this->getHeader('Authorization');
        if ($auth && str_starts_with($auth, 'Bearer ')) {
            return substr($auth, 7);
        }
        return null;
    }
    
    /**
     * 获取 Basic Auth 凭据
     * 
     * @return array{user: string, password: string}|null
     */
    public function getBasicAuth(): ?array
    {
        $user = $this->get('PHP_AUTH_USER');
        $password = $this->get('PHP_AUTH_PW');
        
        if ($user !== null) {
            return [
                'user' => $user,
                'password' => $password ?? '',
            ];
        }
        
        return null;
    }
    
    // ==================== 重置和清理 ====================
    
    /**
     * 重置服务器变量
     * 
     * @return static
     */
    public function reset(): static
    {
        $this->server = [];
        $this->headers = null;
        $this->initialized = false;
        return $this;
    }
    
    /**
     * 替换所有服务器变量
     * 
     * @param array $server 新的服务器变量
     * @return static
     */
    public function replace(array $server): static
    {
        $this->server = $server;
        $this->headers = null;
        return $this;
    }
    
    /**
     * 同步到 $_SERVER
     * 
     * @return static
     */
    public function syncToGlobals(): static
    {
        foreach ($this->server as $key => $value) {
            $_SERVER[$key] = $value;
        }
        return $this;
    }
}
