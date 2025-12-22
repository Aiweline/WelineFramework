<?php
/**
 * DataTable 错误处理助手类
 * 提供统一的错误处理和格式化
 */

namespace Weline\DataTable\Helper;

use Weline\DataTable\Exception\DataTableException;

class ErrorHandler
{
    /**
     * 处理异常并返回标准格式的错误响应
     *
     * @param \Throwable $exception 异常对象
     * @param string $context 上下文信息
     * @param bool $logError 是否记录日志
     * @return array 标准错误响应格式
     */
    public static function handleException(
        \Throwable $exception,
        string $context = '',
        bool $logError = true
    ): array {
        if ($logError) {
            self::logException($exception, $context);
        }

        $code = $exception->getCode();
        $message = $exception->getMessage();

        // 如果是 DataTableException，使用其错误代码
        if ($exception instanceof DataTableException) {
            $code = $exception->getCode();
        } elseif ($code === 0) {
            // 如果没有错误代码，使用默认代码
            $code = 500;
        }

        // 在生产环境下，隐藏敏感错误信息
        $isDev = defined('DEV') && DEV;
        if (!$isDev && !($exception instanceof DataTableException)) {
            $message = '操作失败，请稍后重试';
        }

        return [
            'code' => $code,
            'msg' => $message,
            'data' => null,
            'error' => $isDev ? [
                'type' => get_class($exception),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'trace' => $isDev ? $exception->getTraceAsString() : null
            ] : null
        ];
    }

    /**
     * 记录异常日志
     *
     * @param \Throwable $exception 异常对象
     * @param string $context 上下文信息
     * @return void
     */
    public static function logException(\Throwable $exception, string $context = ''): void
    {
        if ($exception instanceof DataTableException) {
            $exception->log($context);
            return;
        }

        $logMessage = sprintf(
            "[DataTable Error] %s - %s: %s in %s:%d",
            $context ?: 'Unknown',
            get_class($exception),
            $exception->getMessage(),
            $exception->getFile(),
            $exception->getLine()
        );

        error_log($logMessage);

        // 在开发环境下记录堆栈跟踪
        if (defined('DEV') && DEV) {
            error_log("Stack trace:\n" . $exception->getTraceAsString());
        }
    }

    /**
     * 验证必需参数
     *
     * @param array $params 参数数组
     * @param array $required 必需参数列表
     * @throws DataTableException
     * @return void
     */
    public static function validateRequiredParams(array $params, array $required): void
    {
        $missing = [];
        foreach ($required as $key) {
            if (!isset($params[$key]) || (is_string($params[$key]) && trim($params[$key]) === '')) {
                $missing[] = $key;
            }
        }

        if (!empty($missing)) {
            throw new DataTableException(
                DataTableException::CODE_VALIDATION_FAILED,
                '缺少必需参数: ' . implode(', ', $missing)
            );
        }
    }

    /**
     * 验证模型类是否存在
     *
     * @param string $model 模型类名
     * @throws DataTableException
     * @return void
     */
    public static function validateModel(string $model): void
    {
        if (empty($model)) {
            throw new DataTableException(
                DataTableException::CODE_MODEL_NOT_FOUND,
                '模型类名不能为空'
            );
        }

        if (!class_exists($model)) {
            throw DataTableException::modelNotFound($model);
        }
    }

    /**
     * 安全地执行操作并处理异常
     *
     * @param callable $callback 要执行的回调函数
     * @param string $context 上下文信息
     * @param mixed $default 默认返回值（发生异常时）
     * @return mixed
     */
    public static function safeExecute(callable $callback, string $context = '', $default = null)
    {
        try {
            return $callback();
        } catch (\Throwable $e) {
            self::logException($e, $context);
            return $default;
        }
    }

    /**
     * 格式化错误消息，使其对用户更友好
     *
     * @param string $message 原始错误消息
     * @param bool $isDev 是否为开发环境
     * @return string
     */
    public static function formatErrorMessage(string $message, bool $isDev = false): string
    {
        if ($isDev) {
            return $message;
        }

        // 隐藏技术细节，提供友好的错误消息
        $friendlyMessages = [
            'SQLSTATE' => '数据库操作失败',
            'PDOException' => '数据库连接失败',
            'ClassNotFoundException' => '类不存在',
            'MethodNotFoundException' => '方法不存在',
        ];

        foreach ($friendlyMessages as $key => $friendly) {
            if (strpos($message, $key) !== false) {
                return $friendly;
            }
        }

        return $message;
    }
}

