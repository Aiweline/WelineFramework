<?php
declare(strict_types=1);

namespace Weline\Ai\Middleware;

/**
 * 请求/响应日志中间件
 */
class Logging
{
    public function handle($request, $next)
    {
        $startTime = microtime(true);
        
        $response = $next($request);
        
        $this->logRequest($request, $response, microtime(true) - $startTime);
        
        return $response;
    }

    private function logRequest($request, $response, float $duration): void
    {
        // 记录请求日志
        error_log(sprintf(
            'AI Request: %s %s - %dms',
            $request->getMethod(),
            $request->getUri(),
            $duration * 1000
        ));
    }
}
