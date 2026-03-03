<?php

declare(strict_types=1);

/**
 * Weline Framework 统一错误响应
 * 
 * 提供多语言支持的错误信息，适用于 WLS 和 FPM 模式
 * 前端可直接使用返回的 title、message 等字段显示友好提示
 */

namespace Weline\Framework\Exception;

class ErrorResponse
{
    /**
     * 状态码图标映射
     */
    private const STATUS_ICONS = [
        400 => '⚠️',
        401 => '🔐',
        403 => '🚫',
        404 => '🔍',
        405 => '⛔',
        422 => '📋',
        429 => '⏳',
        500 => '⚠️',
        502 => '🔄',
        503 => '🔧',
        504 => '⏱️',
    ];

    /**
     * 需要自动重试的状态码及重试秒数
     */
    private const RETRY_STATUS_CODES = [
        502 => 15,
        503 => 15,
        504 => 30,
    ];

    /**
     * 从异常创建错误响应数据
     */
    public static function fromException(\Throwable $exception, bool $isDevMode = false): array
    {
        $statusCode = self::getStatusCode($exception);
        $message = self::getMessage($exception, $statusCode, $isDevMode);
        
        $response = [
            'error' => true,
            'success' => false,
            'code' => $statusCode,
            'title' => self::getTitle($statusCode),
            'message' => $message,
            'icon' => self::getIcon($statusCode),
        ];
        
        // 添加自动重试信息
        if (isset(self::RETRY_STATUS_CODES[$statusCode])) {
            $response['retry_after'] = self::RETRY_STATUS_CODES[$statusCode];
        }
        
        // 开发模式添加调试信息
        if ($isDevMode) {
            $response['debug'] = [
                'exception' => get_class($exception),
                'original_message' => $exception->getMessage(),
                'file' => self::simplifyPath($exception->getFile()),
                'line' => $exception->getLine(),
                'trace' => self::getSimplifiedTrace($exception),
            ];
            
            if ($exception->getPrevious()) {
                $prev = $exception->getPrevious();
                $response['debug']['previous'] = [
                    'exception' => get_class($prev),
                    'message' => $prev->getMessage(),
                    'file' => self::simplifyPath($prev->getFile()),
                    'line' => $prev->getLine(),
                ];
            }
        }
        
        return $response;
    }

    /**
     * 创建简单错误响应（不含调试信息）
     */
    public static function create(int $statusCode, ?string $customMessage = null): array
    {
        return [
            'error' => true,
            'success' => false,
            'code' => $statusCode,
            'title' => self::getTitle($statusCode),
            'message' => $customMessage ?? self::getDefaultMessage($statusCode),
            'icon' => self::getIcon($statusCode),
            'retry_after' => self::RETRY_STATUS_CODES[$statusCode] ?? 0,
        ];
    }

    /**
     * 转换为 JSON 字符串
     */
    public static function toJson(array $response): string
    {
        return json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * 从异常直接生成 JSON 响应
     */
    public static function jsonFromException(\Throwable $exception, bool $isDevMode = false): string
    {
        return self::toJson(self::fromException($exception, $isDevMode));
    }

    /**
     * 获取 HTTP 状态码
     */
    public static function getStatusCode(\Throwable $exception): int
    {
        // 检查是否有 getStatusCode 方法
        if (method_exists($exception, 'getStatusCode')) {
            /** @var object{getStatusCode: callable(): int} $exception */
            return (int) $exception->getStatusCode();
        }
        
        // 检查异常码是否在 HTTP 状态码范围
        $code = $exception->getCode();
        if ($code >= 400 && $code < 600) {
            return $code;
        }
        
        // 根据异常类名映射状态码
        $exceptionClass = get_class($exception);
        
        return match (true) {
            str_contains($exceptionClass, 'NotFound') || str_contains($exceptionClass, 'NoRouter') => 404,
            str_contains($exceptionClass, 'Unauthorized') => 401,
            str_contains($exceptionClass, 'Forbidden') => 403,
            str_contains($exceptionClass, 'BadRequest') => 400,
            str_contains($exceptionClass, 'Validation') || str_contains($exceptionClass, 'Invalid') => 422,
            str_contains($exceptionClass, 'Timeout') => 504,
            str_contains($exceptionClass, 'Maintenance') => 503,
            default => 500,
        };
    }

    /**
     * 获取错误标题（支持多语言）
     */
    public static function getTitle(int $statusCode): string
    {
        return match ($statusCode) {
            400 => __('请求错误'),
            401 => __('未授权'),
            403 => __('禁止访问'),
            404 => __('页面未找到'),
            405 => __('方法不允许'),
            422 => __('验证失败'),
            429 => __('请求过于频繁'),
            500 => __('服务器错误'),
            502 => __('网关错误'),
            503 => __('系统维护中'),
            504 => __('请求超时'),
            default => $statusCode >= 500 ? __('服务器错误') : __('请求错误'),
        };
    }

    /**
     * 获取默认错误消息（支持多语言）
     */
    public static function getDefaultMessage(int $statusCode): string
    {
        return match ($statusCode) {
            400 => __('请求参数有误，请检查后重试。'),
            401 => __('请先登录后再进行操作。'),
            403 => __('您没有权限执行此操作。'),
            404 => __('您访问的页面不存在。'),
            405 => __('请求方法不被允许。'),
            422 => __('提交的数据验证失败，请检查后重试。'),
            429 => __('请求过于频繁，请稍后再试。'),
            500 => __('服务器内部错误，请稍后重试。'),
            502 => __('网关错误，服务可能正在重启中，请稍后刷新页面。'),
            503 => __('系统正在升级维护，请稍后再试。'),
            504 => __('请求超时，服务器响应时间过长，请稍后重试。'),
            default => $statusCode >= 500 
                ? __('服务器内部错误，请稍后重试。') 
                : __('请求处理失败，请稍后重试。'),
        };
    }

    /**
     * 获取错误消息
     */
    public static function getMessage(\Throwable $exception, int $statusCode, bool $isDevMode): string
    {
        $originalMessage = $exception->getMessage();
        
        // 开发模式：显示原始消息
        if ($isDevMode && $originalMessage) {
            return $originalMessage;
        }
        
        // 生产模式：4xx 错误显示原始消息（用户可操作的错误）
        if ($statusCode >= 400 && $statusCode < 500 && $originalMessage) {
            return $originalMessage;
        }
        
        // 生产模式：5xx 错误使用默认消息（隐藏内部错误详情）
        return self::getDefaultMessage($statusCode);
    }

    /**
     * 获取错误图标
     */
    public static function getIcon(int $statusCode): string
    {
        return self::STATUS_ICONS[$statusCode] ?? (
            $statusCode >= 500 ? '⚠️' : '❌'
        );
    }

    /**
     * 简化文件路径
     */
    private static function simplifyPath(string $path): string
    {
        if (defined('BP') && str_starts_with($path, BP)) {
            return substr($path, strlen(BP));
        }
        return $path;
    }

    /**
     * 获取简化的堆栈追踪
     */
    private static function getSimplifiedTrace(\Throwable $exception): array
    {
        $trace = [];
        $frames = $exception->getTrace();
        $limit = min(10, count($frames));
        
        for ($i = 0; $i < $limit; $i++) {
            $frame = $frames[$i];
            $trace[] = [
                'file' => isset($frame['file']) ? self::simplifyPath($frame['file']) : '[internal]',
                'line' => $frame['line'] ?? 0,
                'function' => ($frame['class'] ?? '') . ($frame['type'] ?? '') . ($frame['function'] ?? ''),
            ];
        }

        if (count($frames) > $limit) {
            $trace[] = ['...and ' . (count($frames) - $limit) . ' more frames'];
        }

        return $trace;
    }
}
