<?php
declare(strict_types=1);

/**
 * WLS Layer2 异常处理器
 *
 * 使用 set_exception_handler 捕获所有未捕获的 Throwable：
 * - Exception 及其子类
 * - Error 及其子类（PHP 7+）
 *   - TypeError
 *   - ArgumentCountError
 *   - ArithmeticError
 *   - DivisionByZeroError
 *   - ParseError（部分场景）
 *   - CompileError（部分场景）
 *   - etc.
 *
 * @author Aiweline
 */

namespace Weline\Server\Log\Error;

use Weline\Server\Log\LogLevel;

class ExceptionHandler
{
    /** 是否已注册 */
    private static bool $registered = false;

    /** 原有的异常处理器 */
    private static mixed $previousHandler = null;

    /** 是否在处理异常后退出进程（默认 true，与 PHP 默认行为一致） */
    private static bool $exitOnException = true;

    /**
     * 注册异常处理器
     *
     * @param bool $exitOnException 处理异常后是否退出（默认 true）
     */
    public static function register(bool $exitOnException = true): void
    {
        if (self::$registered) {
            return;
        }

        self::$exitOnException = $exitOnException;
        self::$previousHandler = \set_exception_handler([self::class, 'handle']);
        self::$registered = true;
    }

    /**
     * 注销异常处理器
     */
    public static function unregister(): void
    {
        if (!self::$registered) {
            return;
        }

        \restore_exception_handler();
        self::$registered = false;
        self::$previousHandler = null;
    }

    /**
     * 异常处理回调
     */
    public static function handle(\Throwable $e): void
    {
        // 302 等响应终止异常为正常控制流，不按错误记录，仅转交或退出
        if ($e instanceof \Weline\Framework\Http\ResponseTerminateException) {
            if (self::$previousHandler !== null) {
                \call_user_func(self::$previousHandler, $e);
            }
            if (self::$exitOnException) {
                exit(255);
            }
            return;
        }

        // 收集异常信息
        ErrorCollector::collect([
            'level' => LogLevel::ERROR,
            'type' => 'exception',
            'class' => \get_class($e),
            'code' => $e->getCode(),
            'message' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTrace(),
            'trace_string' => $e->getTraceAsString(),
            'previous' => $e->getPrevious() !== null ? [
                'class' => \get_class($e->getPrevious()),
                'message' => $e->getPrevious()->getMessage(),
                'file' => $e->getPrevious()->getFile(),
                'line' => $e->getPrevious()->getLine(),
            ] : null,
        ]);

        // 调用原有的处理器（如果存在）
        if (self::$previousHandler !== null) {
            \call_user_func(self::$previousHandler, $e);
            // 原处理器可能会退出，所以这里可能不会执行到
        }

        // 退出进程（与 PHP 默认行为一致）
        if (self::$exitOnException) {
            exit(255);
        }
    }

    /**
     * 是否已注册
     */
    public static function isRegistered(): bool
    {
        return self::$registered;
    }

    /**
     * 设置是否在异常后退出
     */
    public static function setExitOnException(bool $exit): void
    {
        self::$exitOnException = $exit;
    }
}
