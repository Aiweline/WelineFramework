<?php

declare(strict_types=1);

/**
 * Weline Framework 异常处理器
 * 
 * 处理未捕获的异常
 */

namespace Weline\Framework\Exception\Handler;

use Weline\Framework\Exception\ExceptionBootstrap;
use Weline\Framework\Exception\Renderer\RendererFactory;

class ExceptionHandler
{
    /**
     * 是否已注册
     */
    private static bool $registered = false;

    /**
     * 之前的处理器
     * @var callable|null
     */
    private static $previousHandler = null;

    /**
     * 注册异常处理器
     */
    public static function register(): void
    {
        if (self::$registered) {
            return;
        }
        self::$registered = true;

        self::$previousHandler = set_exception_handler([self::class, 'handle']);
    }

    /**
     * 处理异常
     */
    public static function handle(\Throwable $exception): void
    {
        // 记录日志
        self::logException($exception);

        // 渲染输出
        self::render($exception);

        // 调用之前的处理器（如果存在且需要）
        // 通常不调用，因为我们已经处理了异常
    }

    /**
     * 记录异常日志
     */
    private static function logException(\Throwable $exception): void
    {
        if (!function_exists('w_log_exception')) {
            return;
        }

        $context = ExceptionBootstrap::getContext();
        $context['_process'] = ExceptionBootstrap::getProcessTag();

        w_log_exception($exception, null, 'exception');
    }

    /**
     * 渲染异常输出
     */
    private static function render(\Throwable $exception): void
    {
        try {
            $renderer = RendererFactory::create();
            $output = $renderer->render($exception);
            
            // 设置响应码
            if (!headers_sent() && !CLI) {
                $code = self::getHttpStatusCode($exception);
                http_response_code($code);
                
                // 对于 API 请求，设置 JSON 头
                if (ExceptionBootstrap::getArea() === 'api') {
                    header('Content-Type: application/json; charset=utf-8');
                }
            }
            
            echo $output;
        } catch (\Throwable $e) {
            // 渲染失败时的后备方案
            self::fallbackRender($exception, $e);
        }
    }

    /**
     * 后备渲染（当正常渲染失败时）
     */
    private static function fallbackRender(\Throwable $original, \Throwable $renderError): void
    {
        $message = ExceptionBootstrap::isDevMode()
            ? sprintf(
                "Original Exception: %s\nRender Error: %s",
                $original->getMessage(),
                $renderError->getMessage()
            )
            : "An error occurred. Please contact the administrator.";

        if (CLI) {
            fwrite(STDERR, $message . PHP_EOL);
        } else {
            echo '<pre>' . htmlspecialchars($message) . '</pre>';
        }
    }

    /**
     * 获取 HTTP 状态码
     */
    private static function getHttpStatusCode(\Throwable $exception): int
    {
        // 检查异常是否实现了状态码接口
        if (method_exists($exception, 'getStatusCode')) {
            return $exception->getStatusCode();
        }

        // 根据异常类型映射状态码
        $code = $exception->getCode();
        
        if ($code >= 400 && $code < 600) {
            return $code;
        }

        // 默认映射
        $exceptionClass = get_class($exception);
        
        return match (true) {
            str_contains($exceptionClass, 'NotFound') => 404,
            str_contains($exceptionClass, 'Unauthorized') => 401,
            str_contains($exceptionClass, 'Forbidden') => 403,
            str_contains($exceptionClass, 'BadRequest') => 400,
            str_contains($exceptionClass, 'Validation') => 422,
            default => 500,
        };
    }
}
