<?php
declare(strict_types=1);

/**
 * Weline Framework - 无路由异常
 * 
 * 无路由错误通过抛出此异常来实现，而不是调用 exit()。
 * Runtime 层会捕获此异常并转换为 HTTP 404/403 响应。
 * 
 * @author Aiweline
 * @email aiweline@qq.com
 */

namespace Weline\Framework\Http;

/**
 * 无路由异常
 * 
 * 继承自 ResponseTerminateException，表示请求的路由不存在。
 * Response::noRouter() 会抛出此异常，由 Runtime 层统一处理。
 */
class NoRouterException extends ResponseTerminateException
{
    /**
     * 错误消息
     */
    private string $errorMessage;
    
    /**
     * 构造函数
     * 
     * @param int $code HTTP 状态码（默认 404）
     * @param string $msg 错误消息
     */
    public function __construct(int $code = 404, string $msg = '')
    {
        if (empty($msg)) {
            switch ($code) {
                case 403:
                    $msg = 'Forbidden';
                    break;
                case 404:
                    $msg = 'Not Found';
                    break;
                case 500:
                    $msg = 'Internal Server Error';
                    break;
                default:
                    $msg = 'Unknown Error';
            }
        }
        
        $this->errorMessage = $msg;
        
        // 尝试加载错误页面
        $body = $this->loadErrorPage($code, $msg);
        $headers = ['Content-Type' => 'text/html; charset=utf-8'];
        
        parent::__construct($code, $body, $headers);
    }
    
    /**
     * 获取错误消息
     */
    public function getErrorMessage(): string
    {
        return $this->errorMessage;
    }
    
    /**
     * 加载错误页面
     */
    private function loadErrorPage(int $code, string $msg): string
    {
        $errorFile = (\defined('BP') ? BP : '') . 'pub/errors/' . $code . '.php';
        
        if (\is_file($errorFile)) {
            \ob_start();
            try {
                include $errorFile;
                return \ob_get_clean() ?: '';
            } catch (\Throwable $e) {
                \ob_end_clean();
            }
        }
        
        // 默认错误页面
        return '<h1>' . $code . ' ' . \htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') . '</h1>';
    }
}
