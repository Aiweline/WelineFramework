<?php

declare(strict_types=1);

/**
 * Weline Framework 错误处理器
 * 
 * 处理 PHP 错误（E_WARNING, E_NOTICE, E_DEPRECATED 等）
 */

namespace Weline\Framework\Exception\Handler;

use Weline\Framework\Exception\ExceptionBootstrap;

class ErrorHandler
{
    /**
     * E_STRICT 的历史错误码（2048）。
     * PHP 8.4+ 直接访问 E_STRICT 常量会触发 deprecation，故使用数值兼容旧日志级别映射。
     */
    private const LEGACY_E_STRICT = 2048;

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
     * 注册错误处理器
     */
    public static function register(): void
    {
        if (self::$registered) {
            return;
        }
        self::$registered = true;

        self::$previousHandler = set_error_handler([self::class, 'handle']);
    }

    /**
     * 处理错误
     *
     * @param int $errno 错误级别
     * @param string $errstr 错误消息
     * @param string $errfile 错误文件
     * @param int $errline 错误行号
     * @return bool
     */
    public static function handle(int $errno, string $errstr, string $errfile, int $errline): bool
    {
        // 如果错误被抑制（@ 操作符），检查是否应该忽略
        if (!(error_reporting() & $errno)) {
            return false;
        }

        // 确定日志级别
        $level = self::mapErrorToLevel($errno);
        $levelName = self::getErrorLevelName($errno);

        // 简化文件路径
        $file = self::simplifyPath($errfile);

        // 构建日志消息
        $message = sprintf('[%s] %s', $levelName, $errstr);

        // 记录日志
        if (function_exists('w_log')) {
            w_log($level, $message, [
                '_errno' => $errno,
                '_file' => $file,
                '_line' => $errline,
                '_process' => ExceptionBootstrap::getProcessTag(),
            ], 'php_error');
        }

        // 对于严重错误，输出信息
        $fatalErrors = [E_ERROR, E_USER_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_RECOVERABLE_ERROR];
        if (in_array($errno, $fatalErrors, true)) {
            self::outputError($errno, $errstr, $errfile, $errline);
        }

        // 调用之前的处理器
        if (self::$previousHandler !== null) {
            return (self::$previousHandler)($errno, $errstr, $errfile, $errline);
        }

        return true;
    }

    /**
     * 将 PHP 错误级别映射到日志级别
     */
    private static function mapErrorToLevel(int $errno): string
    {
        return match ($errno) {
            E_ERROR, E_USER_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_RECOVERABLE_ERROR => 'error',
            E_WARNING, E_USER_WARNING, E_CORE_WARNING, E_COMPILE_WARNING => 'warning',
            E_NOTICE, E_USER_NOTICE => 'notice',
            E_DEPRECATED, E_USER_DEPRECATED => 'debug',
            self::LEGACY_E_STRICT => 'info',
            default => 'warning',
        };
    }

    /**
     * 获取错误级别名称
     */
    private static function getErrorLevelName(int $errno): string
    {
        return match ($errno) {
            E_ERROR => 'E_ERROR',
            E_WARNING => 'E_WARNING',
            E_PARSE => 'E_PARSE',
            E_NOTICE => 'E_NOTICE',
            E_CORE_ERROR => 'E_CORE_ERROR',
            E_CORE_WARNING => 'E_CORE_WARNING',
            E_COMPILE_ERROR => 'E_COMPILE_ERROR',
            E_COMPILE_WARNING => 'E_COMPILE_WARNING',
            E_USER_ERROR => 'E_USER_ERROR',
            E_USER_WARNING => 'E_USER_WARNING',
            E_USER_NOTICE => 'E_USER_NOTICE',
            self::LEGACY_E_STRICT => 'E_STRICT',
            E_RECOVERABLE_ERROR => 'E_RECOVERABLE_ERROR',
            E_DEPRECATED => 'E_DEPRECATED',
            E_USER_DEPRECATED => 'E_USER_DEPRECATED',
            default => 'UNKNOWN',
        };
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
     * 输出错误信息
     */
    private static function outputError(int $errno, string $errstr, string $errfile, int $errline): void
    {
        if (!ExceptionBootstrap::isDevMode()) {
            return;
        }

        $levelName = self::getErrorLevelName($errno);

        if (PHP_SAPI === 'cli') {
            fprintf(
                STDERR,
                "\033[31m[%s]\033[0m %s\n  in %s on line %d\n",
                $levelName,
                $errstr,
                $errfile,
                $errline
            );
        } else {
            echo sprintf(
                '<div style="padding:15px;background:#2d1515;color:#ff6b6b;border-left:4px solid #ff0000;margin:10px;">
                    <strong>[%s]</strong> %s<br>
                    <small>%s : %d</small>
                </div>',
                $levelName,
                htmlspecialchars($errstr),
                htmlspecialchars($errfile),
                $errline
            );
        }
    }
}
