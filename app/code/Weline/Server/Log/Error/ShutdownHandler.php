<?php
declare(strict_types=1);

/**
 * WLS Layer3 关闭处理器
 *
 * 使用 register_shutdown_function 捕获致命错误：
 * - E_ERROR
 * - E_PARSE
 * - E_CORE_ERROR
 * - E_CORE_WARNING
 * - E_COMPILE_ERROR
 * - E_COMPILE_WARNING
 * - E_USER_ERROR
 *
 * 这些错误无法被 set_error_handler 捕获，但可以在脚本结束时通过
 * error_get_last() 获取。
 *
 * @author Aiweline
 */

namespace Weline\Server\Log\Error;

use Weline\Server\Log\LogLevel;

class ShutdownHandler
{
    /** 是否已注册 */
    private static bool $registered = false;

    /**
     * 需要捕获的致命错误类型
     */
    private const FATAL_ERROR_TYPES = [
        E_ERROR,
        E_PARSE,
        E_CORE_ERROR,
        E_CORE_WARNING,
        E_COMPILE_ERROR,
        E_COMPILE_WARNING,
        E_USER_ERROR,
    ];

    /**
     * 错误类型名称映射
     */
    private const ERROR_TYPE_NAMES = [
        E_ERROR => 'E_ERROR',
        E_PARSE => 'E_PARSE',
        E_CORE_ERROR => 'E_CORE_ERROR',
        E_CORE_WARNING => 'E_CORE_WARNING',
        E_COMPILE_ERROR => 'E_COMPILE_ERROR',
        E_COMPILE_WARNING => 'E_COMPILE_WARNING',
        E_USER_ERROR => 'E_USER_ERROR',
    ];

    /**
     * 注册关闭处理器
     */
    public static function register(): void
    {
        if (self::$registered) {
            return;
        }

        \register_shutdown_function([self::class, 'handle']);
        self::$registered = true;
    }

    /**
     * 关闭处理回调
     */
    public static function handle(): void
    {
        $error = \error_get_last();

        // 没有错误或非致命错误，不处理
        if ($error === null) {
            return;
        }

        if (!\in_array($error['type'], self::FATAL_ERROR_TYPES, true)) {
            return;
        }

        $typeName = self::ERROR_TYPE_NAMES[$error['type']] ?? "E_UNKNOWN({$error['type']})";

        // 收集致命错误
        ErrorCollector::collect([
            'level' => LogLevel::FATAL,
            'type' => 'fatal_error',
            'errno' => $error['type'],
            'errno_name' => $typeName,
            'message' => $error['message'],
            'file' => $error['file'],
            'line' => $error['line'],
        ]);
    }

    /**
     * 是否已注册
     */
    public static function isRegistered(): bool
    {
        return self::$registered;
    }

    /**
     * 检查错误类型是否为致命错误
     */
    public static function isFatalError(int $errno): bool
    {
        return \in_array($errno, self::FATAL_ERROR_TYPES, true);
    }

    /**
     * 获取所有致命错误类型
     *
     * @return int[]
     */
    public static function getFatalErrorTypes(): array
    {
        return self::FATAL_ERROR_TYPES;
    }
}
