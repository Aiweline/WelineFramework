<?php

declare(strict_types=1);

/**
 * Weline Framework 关闭处理器
 * 
 * 处理致命错误（E_ERROR, E_PARSE 等无法被 set_error_handler 捕获的错误）
 */

namespace Weline\Framework\Exception\Handler;

use Weline\Framework\Exception\ExceptionBootstrap;

class ShutdownHandler
{
    /**
     * 是否已注册
     */
    private static bool $registered = false;

    /**
     * 致命错误类型
     */
    private const FATAL_ERRORS = [
        E_ERROR,
        E_PARSE,
        E_CORE_ERROR,
        E_COMPILE_ERROR,
    ];

    /**
     * 注册关闭处理器
     */
    public static function register(): void
    {
        if (self::$registered) {
            return;
        }
        self::$registered = true;

        register_shutdown_function([self::class, 'handle']);
    }

    /**
     * 处理关闭事件
     */
    public static function handle(): void
    {
        $error = error_get_last();
        
        if ($error === null) {
            return;
        }

        // 只处理致命错误
        if (!in_array($error['type'], self::FATAL_ERRORS, true)) {
            return;
        }

        // 记录日志
        self::logFatalError($error);

        // 输出错误
        self::outputError($error);
    }

    /**
     * 记录致命错误日志
     */
    private static function logFatalError(array $error): void
    {
        $message = sprintf(
            '[FATAL] %s in %s on line %d',
            $error['message'],
            $error['file'],
            $error['line']
        );

        if (function_exists('w_log_error')) {
            w_log_error($message, [
                '_error_type' => $error['type'],
                '_file' => $error['file'],
                '_line' => $error['line'],
                '_process' => ExceptionBootstrap::getProcessTag(),
            ], 'fatal_error');
        }

        // 同时写入崩溃日志
        self::writeCrashLog($error);
    }

    /**
     * 写入崩溃日志
     */
    private static function writeCrashLog(array $error): void
    {
        $crashData = [
            'timestamp' => date('Y-m-d H:i:s'),
            'type' => self::getErrorTypeName($error['type']),
            'message' => $error['message'],
            'file' => $error['file'],
            'line' => $error['line'],
            'process' => ExceptionBootstrap::getProcessTag(),
            'context' => ExceptionBootstrap::getContext(),
            'memory_peak' => memory_get_peak_usage(true),
        ];

        $logPath = (defined('BP') ? BP : '') . 'var/log/crash.log';
        $dir = dirname($logPath);
        
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        @file_put_contents(
            $logPath,
            json_encode($crashData, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n\n",
            FILE_APPEND
        );
    }

    /**
     * 输出错误信息
     */
    private static function outputError(array $error): void
    {
        $typeName = self::getErrorTypeName($error['type']);

        if (ExceptionBootstrap::isDevMode()) {
            if (PHP_SAPI === 'cli') {
                // STDERR 可能已关闭（管道 Broken pipe），避免再触发 NOTICE；详情见 crash.log
                $cliMsg = sprintf(
                    "\n\033[41;37m FATAL ERROR \033[0m\n\n" .
                    "\033[31m[%s]\033[0m %s\n" .
                    "  in %s on line %d\n\n",
                    $typeName,
                    $error['message'],
                    $error['file'],
                    $error['line']
                );
                @\fwrite(\STDERR, $cliMsg);
            } else {
                echo sprintf(
                    '<div style="padding:20px;background:#4a0000;color:#ffffff;border:2px solid #ff0000;margin:20px;">
                        <h2 style="margin:0 0 10px 0;color:#ff6b6b;">Fatal Error</h2>
                        <p><strong>[%s]</strong></p>
                        <p>%s</p>
                        <p style="color:#888;">%s : %d</p>
                    </div>',
                    $typeName,
                    htmlspecialchars($error['message']),
                    htmlspecialchars($error['file']),
                    $error['line']
                );
            }
        } else {
            // 生产模式：简单提示
            $message = PHP_SAPI === 'cli'
                ? "程序错误：请联系管理员进行修复！日志：var/log/fatal_error.log\n"
                : "<p>程序错误：请联系管理员进行修复！</p>";
            echo $message;
        }
    }

    /**
     * 获取错误类型名称
     */
    private static function getErrorTypeName(int $type): string
    {
        return match ($type) {
            E_ERROR => 'E_ERROR',
            E_PARSE => 'E_PARSE',
            E_CORE_ERROR => 'E_CORE_ERROR',
            E_COMPILE_ERROR => 'E_COMPILE_ERROR',
            default => 'FATAL_ERROR',
        };
    }
}
