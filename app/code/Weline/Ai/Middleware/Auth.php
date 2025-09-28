<?php
declare(strict_types=1);

namespace Weline\Ai\Middleware;

/**
 * 认证中间件
 */
class Auth
{
    public function handle($request, $next)
    {
        // 验证用户认证
        if (!$this->isAuthenticated($request)) {
            return $this->unauthorizedResponse();
        }
        
        return $next($request);
    }

    private function isAuthenticated($request): bool
    {
        $token = $request->getHeader('Authorization');
        return !empty($token);
    }

    private function unauthorizedResponse()
    {
        return [
            'success' => false,
            'error' => [
                'code' => 'UNAUTHORIZED',
                'message' => '未授权访问'
            ]
        ];
    }
}
