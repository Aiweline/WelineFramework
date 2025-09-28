<?php
declare(strict_types=1);

namespace Weline\Ai\Middleware;

/**
 * CORS和安全头中间件
 */
class Security
{
    public function handle($request, $next)
    {
        $response = $next($request);
        
        // 添加安全头
        $response->setHeader('X-Content-Type-Options', 'nosniff');
        $response->setHeader('X-Frame-Options', 'DENY');
        $response->setHeader('X-XSS-Protection', '1; mode=block');
        
        return $response;
    }
}
