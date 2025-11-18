<?php
declare(strict_types=1);

namespace Weline\Ai\Middleware;

/**
 * 限流中间件
 */
class RateLimit
{
    public function handle($request, $next)
    {
        if ($this->isRateLimited($request)) {
            return $this->rateLimitResponse();
        }
        
        return $next($request);
    }

    private function isRateLimited($request): bool
    {
        // 简单的限流逻辑
        $key = $this->getRateLimitKey($request);
        $count = $this->getRequestCount($key);
        
        return $count > 100; // 每分钟100次请求限制
    }

    private function getRateLimitKey($request): string
    {
        return 'rate_limit:' . $request->getClientIp();
    }

    private function getRequestCount(string $key): int
    {
        // 从缓存或数据库获取请求计数
        return 0;
    }

    private function rateLimitResponse()
    {
        return [
            'success' => false,
            'error' => [
                'code' => 'RATE_LIMIT_EXCEEDED',
                'message' => '请求频率超限'
            ]
        ];
    }
}
