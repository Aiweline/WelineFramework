<?php
declare(strict_types=1);

/**
 * WLS 错误捕获启动器
 *
 * 一行代码启用完整的错误捕获系统（Layer 1-3）：
 * - Layer 1: ErrorHandler (Warning/Notice/Deprecated)
 * - Layer 2: ExceptionHandler (Uncaught Exception/Error)
 * - Layer 3: ShutdownHandler (Fatal Error/OOM/Timeout)
 *
 * 用法：
 * ```php
 * ErrorBootstrap::init('Worker#1', ['port' => 9981]);
 * ```
 *
 * @author Aiweline
 */

namespace Weline\Server\Log\Error;

use Weline\Server\Log\LogConfig;
use Weline\Server\Log\LogLevel;
use Weline\Server\Log\WlsLogger;

class ErrorBootstrap
{
    /** 是否已初始化 */
    private static bool $initialized = false;

    /**
     * 初始化错误捕获系统
     *
     * @param string $processTag 进程标识（如 'Worker#1', 'Dispatcher', 'SessionServer:19970'）
     * @param array $context 额外上下文信息（如 ['port' => 9981, 'instance' => 'default']）
     * @param array $options 选项：
     *   - stdout: bool 是否输出到终端（默认遵循 LogConfig::isStdoutEnabled，auto 默认开启，除非显式关闭）
     *   - level: string 最小日志级别（默认从配置读取）
     *   - exit_on_exception: bool 异常后是否退出（默认 true）
     */
    public static function init(string $processTag, array $context = [], array $options = []): void
    {
        if (self::$initialized) {
            // 已初始化，仅更新上下文
            ErrorContext::setProcessTag($processTag);
            ErrorContext::setContext($context);
            return;
        }

        // 设置错误上下文
        ErrorContext::setProcessTag($processTag);
        ErrorContext::setContext($context);

        // 配置 WlsLogger
        self::configureLogger($processTag, $options);

        // Layer 1: 注册错误处理器
        ErrorHandler::register();

        // Layer 2: 注册异常处理器
        $exitOnException = $options['exit_on_exception'] ?? true;
        ExceptionHandler::register($exitOnException);

        // Layer 3: 注册关闭处理器
        ShutdownHandler::register();

        // 配置错误报告
        self::configureErrorReporting();

        self::$initialized = true;
    }

    /**
     * 配置 WlsLogger
     */
    private static function configureLogger(string $processTag, array $options): void
    {
        $logger = WlsLogger::getInstance();
        $logger->setProcessTag($processTag);

        // 日志级别
        $level = $options['level'] ?? LogConfig::getMinLevel();
        $logger->setMinLevel($level);

        // 终端输出
        if (isset($options['stdout'])) {
            $logger->setStdoutEnabled((bool)$options['stdout']);
        } else {
            // auto 模式：默认开启，只有显式配置关闭时才静默
            $isFrontend = \defined('WLS_FRONTEND_MODE') && WLS_FRONTEND_MODE;
            $isDev = LogConfig::isDevMode();
            $logger->setStdoutEnabled(LogConfig::isStdoutEnabled($isFrontend, $isDev));
        }

        // 文件写入（始终启用）
        $logger->setFileEnabled(LogConfig::isEnabled());
    }

    /**
     * 配置错误报告
     */
    private static function configureErrorReporting(): void
    {
        // 开发环境：报告所有错误
        if (LogConfig::isDevMode()) {
            \error_reporting(E_ALL);
        }

        // 不让 PHP 直接显示错误（由我们统一处理）
        \ini_set('display_errors', '0');

        // 但仍然记录到 PHP error_log（双保险）
        \ini_set('log_errors', '1');
    }

    /**
     * 是否已初始化
     */
    public static function isInitialized(): bool
    {
        return self::$initialized;
    }

    /**
     * 重置（用于测试或热重载）
     */
    public static function reset(): void
    {
        if (!self::$initialized) {
            return;
        }

        ErrorHandler::unregister();
        ExceptionHandler::unregister();
        // ShutdownHandler 无法注销（PHP 限制）

        ErrorContext::reset();
        WlsLogger::reset();

        self::$initialized = false;
    }

    /**
     * 快捷方法：为前台模式初始化
     */
    public static function initFrontend(string $processTag, array $context = []): void
    {
        self::init($processTag, $context, [
            'stdout' => true,
            'level' => LogLevel::DEBUG,
        ]);
    }

    /**
     * 快捷方法：为生产环境初始化
     */
    public static function initProduction(string $processTag, array $context = []): void
    {
        self::init($processTag, $context, [
            'stdout' => false,
            'level' => LogLevel::ERROR,
        ]);
    }

    /**
     * 更新请求上下文（在每次请求处理时调用）
     */
    public static function updateRequestContext(
        string $uri = '',
        string $method = '',
        string $clientIp = ''
    ): void {
        ErrorContext::updateRequestContext($uri, $method, $clientIp);
    }

    /**
     * 清除请求上下文（请求处理完成后调用）
     */
    public static function clearRequestContext(): void
    {
        ErrorContext::clearRequestContext();
    }
}
