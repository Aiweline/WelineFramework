<?php
declare(strict_types=1);

namespace Weline\Ai\Middleware;

use Weline\Framework\Http\Request;

/**
 * CORS 和安全头中间件
 * 
 * 功能：
 * - 处理 CORS 跨域请求
 * - 添加安全响应头
 * - 防止 XSS 攻击
 * - 防止点击劫持
 * - 内容类型保护
 * 
 * @package Weline_Ai
 */
class Security
{
    private Request $request;

    /**
     * 允许的来源域名（配置化）
     * @var array
     */
    private array $allowedOrigins = [
        '*', // 开发阶段允许所有来源，生产环境应配置具体域名
    ];

    /**
     * 允许的 HTTP 方法
     * @var array
     */
    private array $allowedMethods = [
        'GET',
        'POST',
        'PUT',
        'DELETE',
        'OPTIONS',
        'PATCH',
    ];

    /**
     * 允许的请求头
     * @var array
     */
    private array $allowedHeaders = [
        'Content-Type',
        'Authorization',
        'X-Requested-With',
        'X-API-Version',
        'X-API-Locale',
        'X-Tenant-Code',
        'Accept',
    ];

    /**
     * 暴露的响应头
     * @var array
     */
    private array $exposedHeaders = [
        'X-Request-ID',
        'X-Response-Time',
        'X-Memory-Peak',
        'X-RateLimit-Limit',
        'X-RateLimit-Remaining',
    ];

    public function __construct(Request $request)
    {
        $this->request = $request;
    }

    /**
     * 处理安全和 CORS
     *
     * @param mixed $request
     * @param callable $next
     * @return mixed
     */
    public function handle($request, callable $next)
    {
        // 处理 OPTIONS 预检请求
        if ($this->request->getMethod() === 'OPTIONS') {
            return $this->handlePreflightRequest();
        }

        // 执行请求
        $response = $next($request);

        // 添加 CORS 头
        $this->addCorsHeaders();

        // 添加安全头
        $this->addSecurityHeaders();

        return $response;
    }

    /**
     * 处理 OPTIONS 预检请求
     *
     * @return array
     */
    private function handlePreflightRequest(): array
    {
        $this->addCorsHeaders();
        $this->addSecurityHeaders();

        http_response_code(204); // No Content
        
        return [
            'success' => true,
            'message' => 'Preflight request handled'
        ];
    }

    /**
     * 添加 CORS 响应头
     *
     * @return void
     */
    private function addCorsHeaders(): void
    {
        $origin = $this->request->getHeader('Origin');

        // 检查来源是否允许
        if ($this->isOriginAllowed($origin)) {
            header('Access-Control-Allow-Origin: ' . $origin);
            header('Access-Control-Allow-Credentials: true');
        } elseif (in_array('*', $this->allowedOrigins)) {
            header('Access-Control-Allow-Origin: *');
        }

        // 允许的方法
        header('Access-Control-Allow-Methods: ' . implode(', ', $this->allowedMethods));

        // 允许的请求头
        header('Access-Control-Allow-Headers: ' . implode(', ', $this->allowedHeaders));

        // 暴露的响应头
        header('Access-Control-Expose-Headers: ' . implode(', ', $this->exposedHeaders));

        // 预检请求缓存时间（24小时）
        header('Access-Control-Max-Age: 86400');
    }

    /**
     * 添加安全响应头
     *
     * @return void
     */
    private function addSecurityHeaders(): void
    {
        // 防止 MIME 类型嗅探
        header('X-Content-Type-Options: nosniff');

        // 防止点击劫持
        header('X-Frame-Options: DENY');

        // XSS 保护
        header('X-XSS-Protection: 1; mode=block');

        // 严格传输安全（HTTPS）
        if ($this->request->isSecure()) {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
        }

        // 内容安全策略
        header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'");

        // 引用策略
        header('Referrer-Policy: strict-origin-when-cross-origin');

        // 权限策略
        header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
    }

    /**
     * 检查来源是否允许
     *
     * @param string|null $origin
     * @return bool
     */
    private function isOriginAllowed(?string $origin): bool
    {
        if (empty($origin)) {
            return false;
        }

        // 检查是否在允许列表中
        if (in_array($origin, $this->allowedOrigins)) {
            return true;
        }

        // 检查是否匹配通配符模式
        foreach ($this->allowedOrigins as $allowedOrigin) {
            if ($this->matchesOriginPattern($origin, $allowedOrigin)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 匹配来源模式
     *
     * @param string $origin
     * @param string $pattern
     * @return bool
     */
    private function matchesOriginPattern(string $origin, string $pattern): bool
    {
        // 简单通配符匹配
        $regex = '/^' . str_replace('\*', '.*', preg_quote($pattern, '/')) . '$/';
        return (bool)preg_match($regex, $origin);
    }

    /**
     * 配置允许的来源（用于动态配置）
     *
     * @param array $origins
     * @return void
     */
    public function setAllowedOrigins(array $origins): void
    {
        $this->allowedOrigins = $origins;
    }
}

