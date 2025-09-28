<?php
declare(strict_types=1);

namespace Weline\Ai\Middleware;

/**
 * 错误处理中间件
 */
class ErrorHandler
{
    public function handle($request, $next)
    {
        try {
            return $next($request);
        } catch (\Exception $e) {
            return $this->handleException($e);
        }
    }

    private function handleException(\Exception $e)
    {
        return [
            'success' => false,
            'error' => [
                'code' => 'INTERNAL_ERROR',
                'message' => '服务器内部错误'
            ]
        ];
    }
}
