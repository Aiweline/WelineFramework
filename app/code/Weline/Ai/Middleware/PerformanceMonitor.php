<?php
declare(strict_types=1);

namespace Weline\Ai\Middleware;

/**
 * 性能监控中间件
 */
class PerformanceMonitor
{
    public function handle($request, $next)
    {
        $startTime = microtime(true);
        $startMemory = memory_get_usage();
        
        $response = $next($request);
        
        $this->recordMetrics($request, $startTime, $startMemory);
        
        return $response;
    }

    private function recordMetrics($request, float $startTime, int $startMemory): void
    {
        $duration = microtime(true) - $startTime;
        $memoryUsed = memory_get_usage() - $startMemory;
        
        // 记录性能指标
        error_log(sprintf(
            'Performance: %s - %dms, %dKB',
            $request->getUri(),
            $duration * 1000,
            $memoryUsed / 1024
        ));
    }
}
