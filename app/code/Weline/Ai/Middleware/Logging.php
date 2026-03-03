<?php
declare(strict_types=1);

namespace Weline\Ai\Middleware;

use Weline\Framework\Http\Request;
use Weline\Framework\Manager\ObjectManager;

/**
 * 请求/响应日志中间件
 * 
 * 功能：
 * - 记录 API 请求日志
 * - 记录响应时间
 * - 记录错误信息
 * - 性能监控数据
 * 
 * @package Weline_Ai
 */
class Logging
{
    private Request $request;

    public function __construct(Request $request)
    {
        $this->request = $request;
    }

    /**
     * 处理请求日志记录
     *
     * @param mixed $request
     * @param callable $next
     * @return mixed
     */
    public function handle($request, callable $next)
    {
        $startTime = microtime(true);
        $requestId = $this->generateRequestId();
        
        // 记录请求开始
        $this->logRequestStart($requestId);
        
        try {
            // 执行请求
            $response = $next($request);
            
            // 计算响应时间
            $duration = microtime(true) - $startTime;
            
            // 记录请求完成
            $this->logRequestEnd($requestId, $response, $duration);
            
            // 添加性能头
            $this->addPerformanceHeaders($response, $duration, $requestId);
            
            return $response;
        } catch (\Throwable $e) {
            // 记录请求错误
            $duration = microtime(true) - $startTime;
            $this->logRequestError($requestId, $e, $duration);
            throw $e;
        }
    }

    /**
     * 生成请求ID
     *
     * @return string
     */
    private function generateRequestId(): string
    {
        return 'req_' . uniqid() . '_' . bin2hex(random_bytes(8));
    }

    /**
     * 记录请求开始
     *
     * @param string $requestId
     * @return void
     */
    private function logRequestStart(string $requestId): void
    {
        $logData = [
            'request_id' => $requestId,
            'method' => $this->request->getMethod(),
            'uri' => $this->request->getUri(),
            'ip' => $this->request->getClientIp(),
            'user_agent' => $this->request->getHeader('User-Agent'),
            'tenant_id' => $this->request->getData('tenant_id'),
            'user_id' => $this->request->getData('user_id'),
            'timestamp' => date('Y-m-d H:i:s'),
        ];

        $this->writeLog('INFO', 'Request Start', $logData);
    }

    /**
     * 记录请求完成
     *
     * @param string $requestId
     * @param mixed $response
     * @param float $duration
     * @return void
     */
    private function logRequestEnd(string $requestId, $response, float $duration): void
    {
        $durationMs = round($duration * 1000, 2);
        
        $logData = [
            'request_id' => $requestId,
            'duration_ms' => $durationMs,
            'memory_peak' => $this->formatBytes(memory_get_peak_usage(true)),
            'status' => 'success',
        ];

        // 性能警告
        if ($durationMs > 3000) {
            $this->writeLog('WARNING', 'Slow Request', $logData);
        } else {
            $this->writeLog('INFO', 'Request Complete', $logData);
        }
    }

    /**
     * 记录请求错误
     *
     * @param string $requestId
     * @param \Throwable $exception
     * @param float $duration
     * @return void
     */
    private function logRequestError(string $requestId, \Throwable $exception, float $duration): void
    {
        $logData = [
            'request_id' => $requestId,
            'duration_ms' => round($duration * 1000, 2),
            'error_type' => get_class($exception),
            'error_message' => $exception->getMessage(),
            'error_file' => $exception->getFile(),
            'error_line' => $exception->getLine(),
        ];

        $this->writeLog('ERROR', 'Request Failed', $logData);
    }

    /**
     * 写入日志
     *
     * @param string $level
     * @param string $message
     * @param array $context
     * @return void
     */
    private function writeLog(string $level, string $message, array $context): void
    {
        $logMessage = sprintf(
            '[%s] %s: %s | Context: %s',
            $level,
            date('Y-m-d H:i:s'),
            $message,
            json_encode($context, JSON_UNESCAPED_UNICODE)
        );

        // 写入框架日志
        w_log_info($logMessage);
        
        // TODO: 集成框架的 Logger 系统
        // $logger = ObjectManager::getInstance(\Weline\Framework\Logger\Logger::class);
        // $logger->log($level, $message, $context);
    }

    /**
     * 添加性能响应头
     *
     * @param mixed $response
     * @param float $duration
     * @param string $requestId
     * @return void
     */
    private function addPerformanceHeaders($response, float $duration, string $requestId): void
    {
        // 设置性能相关的响应头
        header('X-Request-ID: ' . $requestId);
        header('X-Response-Time: ' . round($duration * 1000, 2) . 'ms');
        header('X-Memory-Peak: ' . \Weline\Ai\Helper\PerformanceHelper::formatBytes(memory_get_peak_usage(true)));
    }
}

