<?php
/**
 * 综合错误处理中间件
 * 处理所有类型错误包括数据库、API、业务逻辑、网络、文件系统错误
 * 
 * @author WelineFramework
 * @package Weline\Ai\Middleware
 */

namespace Weline\Ai\Middleware;

use Weline\Framework\Http\Request;
use Weline\Framework\Http\Response;
use Weline\Framework\Output\Cli\Printing;
use Weline\Framework\Exception\Core;

class ComprehensiveErrorHandler
{
    private Printing $printing;
    
    public function __construct(Printing $printing)
    {
        $this->printing = $printing;
    }
    
    /**
     * 处理中间件请求
     * 
     * @param Request $request
     * @param callable $next
     * @return Response
     */
    public function process(Request $request, callable $next): Response
    {
        try {
            return $next($request);
        } catch (\Throwable $e) {
            return $this->handleError($e, $request);
        }
    }
    
    /**
     * 综合错误处理
     * 
     * @param \Throwable $error
     * @param Request $request
     * @return Response
     */
    private function handleError(\Throwable $error, Request $request): Response
    {
        $errorType = $this->classifyError($error);
        $errorData = $this->extractErrorData($error, $request);
        
        // 记录错误日志
        $this->logError($errorData);
        
        // 根据错误类型处理
        switch ($errorType) {
            case 'database':
                return $this->handleDatabaseError($error, $errorData);
                
            case 'api':
                return $this->handleApiError($error, $errorData);
                
            case 'business_logic':
                return $this->handleBusinessLogicError($error, $errorData);
                
            case 'network':
                return $this->handleNetworkError($error, $errorData);
                
            case 'file_system':
                return $this->handleFileSystemError($error, $errorData);
                
            case 'validation':
                return $this->handleValidationError($error, $errorData);
                
            case 'authentication':
                return $this->handleAuthenticationError($error, $errorData);
                
            case 'authorization':
                return $this->handleAuthorizationError($error, $errorData);
                
            case 'system':
            default:
                return $this->handleSystemError($error, $errorData);
        }
    }
    
    /**
     * 错误分类
     * 
     * @param \Throwable $error
     * @return string
     */
    private function classifyError(\Throwable $error): string
    {
        $message = $error->getMessage();
        $class = get_class($error);
        
        // 数据库错误
        if ($this->isDatabaseError($error)) {
            return 'database';
        }
        
        // API错误
        if ($this->isApiError($error)) {
            return 'api';
        }
        
        // 网络错误
        if ($this->isNetworkError($error)) {
            return 'network';
        }
        
        // 文件系统错误
        if ($this->isFileSystemError($error)) {
            return 'file_system';
        }
        
        // 验证错误
        if ($this->isValidationError($error)) {
            return 'validation';
        }
        
        // 认证错误
        if ($this->isAuthenticationError($error)) {
            return 'authentication';
        }
        
        // 授权错误
        if ($this->isAuthorizationError($error)) {
            return 'authorization';
        }
        
        // 业务逻辑错误
        if ($this->isBusinessLogicError($error)) {
            return 'business_logic';
        }
        
        return 'system';
    }
    
    /**
     * 提取错误数据
     * 
     * @param \Throwable $error
     * @param Request $request
     * @return array
     */
    private function extractErrorData(\Throwable $error, Request $request): array
    {
        return [
            'timestamp' => date('Y-m-d H:i:s'),
            'error_id' => uniqid('err_'),
            'type' => get_class($error),
            'message' => $error->getMessage(),
            'code' => $error->getCode(),
            'file' => $error->getFile(),
            'line' => $error->getLine(),
            'trace' => $error->getTraceAsString(),
            'request' => [
                'method' => $request->getMethod(),
                'uri' => $request->getUri(),
                'headers' => $request->getHeaders(),
                'params' => $request->getParams(),
                'ip' => $request->getClientIp(),
                'user_agent' => $request->getHeader('User-Agent')
            ],
            'server' => [
                'php_version' => PHP_VERSION,
                'memory_usage' => memory_get_usage(true),
                'memory_peak' => memory_get_peak_usage(true)
            ]
        ];
    }
    
    /**
     * 记录错误日志
     * 
     * @param array $errorData
     */
    private function logError(array $errorData): void
    {
        $logMessage = sprintf(
            "[%s] %s: %s in %s:%d\nRequest: %s %s\nTrace: %s",
            $errorData['timestamp'],
            $errorData['error_id'],
            $errorData['message'],
            $errorData['file'],
            $errorData['line'],
            $errorData['request']['method'],
            $errorData['request']['uri'],
            $errorData['trace']
        );
        
        // 使用框架日志系统
        w_log_error($logMessage);
        
        // CLI输出
        if (php_sapi_name() === 'cli') {
            $this->printing->error($logMessage);
        }
    }
    
    /**
     * 处理数据库错误
     */
    private function handleDatabaseError(\Throwable $error, array $errorData): Response
    {
        $response = [
            'success' => false,
            'error' => [
                'type' => 'database_error',
                'message' => '数据库操作失败',
                'error_id' => $errorData['error_id']
            ]
        ];
        
        // 开发环境显示详细信息
        if ($this->isDevelopmentMode()) {
            $response['error']['details'] = $error->getMessage();
            $response['error']['file'] = $error->getFile();
            $response['error']['line'] = $error->getLine();
        }
        
        return $this->createErrorResponse($response, 500);
    }
    
    /**
     * 处理API错误
     */
    private function handleApiError(\Throwable $error, array $errorData): Response
    {
        $response = [
            'success' => false,
            'error' => [
                'type' => 'api_error',
                'message' => 'API调用失败',
                'error_id' => $errorData['error_id']
            ]
        ];
        
        // 根据HTTP状态码调整响应
        $statusCode = $this->extractHttpStatusCode($error);
        
        return $this->createErrorResponse($response, $statusCode);
    }
    
    /**
     * 处理业务逻辑错误
     */
    private function handleBusinessLogicError(\Throwable $error, array $errorData): Response
    {
        $response = [
            'success' => false,
            'error' => [
                'type' => 'business_error',
                'message' => $error->getMessage(),
                'error_id' => $errorData['error_id']
            ]
        ];
        
        return $this->createErrorResponse($response, 400);
    }
    
    /**
     * 处理网络错误
     */
    private function handleNetworkError(\Throwable $error, array $errorData): Response
    {
        $response = [
            'success' => false,
            'error' => [
                'type' => 'network_error',
                'message' => '网络连接失败',
                'error_id' => $errorData['error_id']
            ]
        ];
        
        return $this->createErrorResponse($response, 503);
    }
    
    /**
     * 处理文件系统错误
     */
    private function handleFileSystemError(\Throwable $error, array $errorData): Response
    {
        $response = [
            'success' => false,
            'error' => [
                'type' => 'file_system_error',
                'message' => '文件操作失败',
                'error_id' => $errorData['error_id']
            ]
        ];
        
        return $this->createErrorResponse($response, 500);
    }
    
    /**
     * 处理验证错误
     */
    private function handleValidationError(\Throwable $error, array $errorData): Response
    {
        $response = [
            'success' => false,
            'error' => [
                'type' => 'validation_error',
                'message' => $error->getMessage(),
                'error_id' => $errorData['error_id']
            ]
        ];
        
        return $this->createErrorResponse($response, 422);
    }
    
    /**
     * 处理认证错误
     */
    private function handleAuthenticationError(\Throwable $error, array $errorData): Response
    {
        $response = [
            'success' => false,
            'error' => [
                'type' => 'authentication_error',
                'message' => '认证失败',
                'error_id' => $errorData['error_id']
            ]
        ];
        
        return $this->createErrorResponse($response, 401);
    }
    
    /**
     * 处理授权错误
     */
    private function handleAuthorizationError(\Throwable $error, array $errorData): Response
    {
        $response = [
            'success' => false,
            'error' => [
                'type' => 'authorization_error',
                'message' => '权限不足',
                'error_id' => $errorData['error_id']
            ]
        ];
        
        return $this->createErrorResponse($response, 403);
    }
    
    /**
     * 处理系统错误
     */
    private function handleSystemError(\Throwable $error, array $errorData): Response
    {
        $response = [
            'success' => false,
            'error' => [
                'type' => 'system_error',
                'message' => '系统内部错误',
                'error_id' => $errorData['error_id']
            ]
        ];
        
        if ($this->isDevelopmentMode()) {
            $response['error']['details'] = $error->getMessage();
            $response['error']['trace'] = $error->getTraceAsString();
        }
        
        return $this->createErrorResponse($response, 500);
    }
    
    /**
     * 创建错误响应
     */
    private function createErrorResponse(array $data, int $statusCode): Response
    {
        $response = new Response();
        $response->setCode($statusCode);
        $response->setHeader('Content-Type', 'application/json');
        $response->setBody(json_encode($data, JSON_UNESCAPED_UNICODE));
        
        return $response;
    }
    
    /**
     * 检查是否为数据库错误
     */
    private function isDatabaseError(\Throwable $error): bool
    {
        $message = strtolower($error->getMessage());
        $class = get_class($error);
        
        return strpos($message, 'database') !== false ||
               strpos($message, 'sql') !== false ||
               strpos($message, 'mysql') !== false ||
               strpos($message, 'connection') !== false ||
               strpos($class, 'Database') !== false ||
               strpos($class, 'PDO') !== false;
    }
    
    /**
     * 检查是否为API错误
     */
    private function isApiError(\Throwable $error): bool
    {
        $message = strtolower($error->getMessage());
        $class = get_class($error);
        
        return strpos($message, 'api') !== false ||
               strpos($message, 'http') !== false ||
               strpos($message, 'curl') !== false ||
               strpos($class, 'Http') !== false ||
               strpos($class, 'Api') !== false;
    }
    
    /**
     * 检查是否为网络错误
     */
    private function isNetworkError(\Throwable $error): bool
    {
        $message = strtolower($error->getMessage());
        
        return strpos($message, 'network') !== false ||
               strpos($message, 'timeout') !== false ||
               strpos($message, 'connection refused') !== false ||
               strpos($message, 'host not found') !== false;
    }
    
    /**
     * 检查是否为文件系统错误
     */
    private function isFileSystemError(\Throwable $error): bool
    {
        $message = strtolower($error->getMessage());
        
        return strpos($message, 'file') !== false ||
               strpos($message, 'directory') !== false ||
               strpos($message, 'permission') !== false ||
               strpos($message, 'no such file') !== false;
    }
    
    /**
     * 检查是否为验证错误
     */
    private function isValidationError(\Throwable $error): bool
    {
        $class = get_class($error);
        
        return strpos($class, 'Validation') !== false ||
               strpos($class, 'InvalidArgument') !== false ||
               $error instanceof \InvalidArgumentException;
    }
    
    /**
     * 检查是否为认证错误
     */
    private function isAuthenticationError(\Throwable $error): bool
    {
        $message = strtolower($error->getMessage());
        $class = get_class($error);
        
        return strpos($message, 'authentication') !== false ||
               strpos($message, 'login') !== false ||
               strpos($message, 'credential') !== false ||
               strpos($class, 'Auth') !== false;
    }
    
    /**
     * 检查是否为授权错误
     */
    private function isAuthorizationError(\Throwable $error): bool
    {
        $message = strtolower($error->getMessage());
        
        return strpos($message, 'authorization') !== false ||
               strpos($message, 'permission') !== false ||
               strpos($message, 'access denied') !== false ||
               strpos($message, 'forbidden') !== false;
    }
    
    /**
     * 检查是否为业务逻辑错误
     */
    private function isBusinessLogicError(\Throwable $error): bool
    {
        $class = get_class($error);
        
        return strpos($class, 'Business') !== false ||
               strpos($class, 'Logic') !== false ||
               strpos($class, 'Domain') !== false;
    }
    
    /**
     * 提取HTTP状态码
     */
    private function extractHttpStatusCode(\Throwable $error): int
    {
        $code = $error->getCode();
        
        // 如果是有效的HTTP状态码
        if ($code >= 100 && $code < 600) {
            return $code;
        }
        
        // 默认返回500
        return 500;
    }
    
    /**
     * 检查是否为开发模式
     */
    private function isDevelopmentMode(): bool
    {
        // 这里应该根据实际的环境配置来判断
        return defined('WELINE_DEBUG') && constant('WELINE_DEBUG') === true;
    }
}
