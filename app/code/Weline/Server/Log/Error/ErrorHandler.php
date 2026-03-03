<?php
declare(strict_types=1);

/**
 * WLS Layer1 错误处理器
 *
 * 使用 set_error_handler 捕获：
 * - E_WARNING
 * - E_NOTICE
 * - E_DEPRECATED
 * - E_USER_WARNING
 * - E_USER_NOTICE
 * - E_USER_DEPRECATED
 * - E_RECOVERABLE_ERROR
 *
 * 注：E_STRICT 在 PHP 8.4+ 已废弃，不再处理
 *
 * @author Aiweline
 */

namespace Weline\Server\Log\Error;

use Weline\Server\Log\LogLevel;

class ErrorHandler
{
    /** 是否已注册 */
    private static bool $registered = false;

    /** 原有的错误处理器 */
    private static mixed $previousHandler = null;

    /**
     * PHP 错误代码到日志级别的映射
     */
    private const LEVEL_MAP = [
        E_WARNING => LogLevel::WARNING,
        E_NOTICE => LogLevel::NOTICE,
        E_DEPRECATED => LogLevel::NOTICE,
        E_USER_WARNING => LogLevel::WARNING,
        E_USER_NOTICE => LogLevel::NOTICE,
        E_USER_DEPRECATED => LogLevel::NOTICE,
        E_RECOVERABLE_ERROR => LogLevel::ERROR,
    ];

    /**
     * 注册错误处理器
     */
    public static function register(): void
    {
        if (self::$registered) {
            return;
        }

        self::$previousHandler = \set_error_handler([self::class, 'handle']);
        self::$registered = true;
    }

    /**
     * 注销错误处理器
     */
    public static function unregister(): void
    {
        if (!self::$registered) {
            return;
        }

        \restore_error_handler();
        self::$registered = false;
        self::$previousHandler = null;
    }

    /**
     * 错误处理回调
     *
     * @return bool 返回 false 让 PHP 继续标准错误处理
     */
    public static function handle(
        int $errno,
        string $errstr,
        string $errfile = '',
        int $errline = 0
    ): bool {
        // 被 @ 抑制的错误不处理
        if (!(\error_reporting() & $errno)) {
            return false;
        }

        // 获取日志级别
        $level = self::LEVEL_MAP[$errno] ?? LogLevel::WARNING;

        // 收集错误
        ErrorCollector::collect([
            'level' => $level,
            'type' => 'php_error',
            'errno' => $errno,
            'message' => $errstr,
            'file' => $errfile,
            'line' => $errline,
            'trace' => \debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 10),
        ]);

        // 调用原有的处理器（如果存在）
        if (self::$previousHandler !== null) {
            return \call_user_func(self::$previousHandler, $errno, $errstr, $errfile, $errline);
        }

        // 返回 false 让 PHP 继续标准处理（写入 error_log 等）
        return false;
    }

    /**
     * 是否已注册
     */
    public static function isRegistered(): bool
    {
        return self::$registered;
    }
}
