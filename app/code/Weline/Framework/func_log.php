<?php

declare(strict_types=1);

/**
 * Weline Framework 日志全局辅助函数
 * 
 * 提供简洁的日志记录 API，自动选择合适的日志实现
 */

use Weline\Framework\Log\LoggerFactory;
use Weline\Framework\Log\LoggerInterface;

if (!function_exists('w_log')) {
    /**
     * 记录任意级别的日志
     *
     * @param string $level 日志级别（debug, info, notice, warning, error, critical, alert, emergency）
     * @param string $message 日志消息，支持 {placeholder} 格式的占位符
     * @param array $context 上下文数据，用于替换消息中的占位符
     * @param string|null $channel 通道名，null 使用默认通道
     * 
     * @example
     * w_log('info', 'User {user} logged in', ['user' => 'john']);
     * w_log('error', 'Database connection failed', ['_exception' => $e], 'database');
     */
    function w_log(string $level, string $message, array $context = [], ?string $channel = null): void
    {
        try {
            $logger = LoggerFactory::create($channel);
            $logger->log($level, $message, $context);
            $sseManual = \getenv('WELINE_CRON_MANUAL_SSE');
            if ($sseManual !== false && $sseManual !== '' && $sseManual !== '0') {
                $echoLevels = ['notice', 'info', 'warning', 'error', 'critical', 'alert', 'emergency'];
                if (\in_array(\strtolower($level), $echoLevels, true) && \defined('STDERR')) {
                    $ch = $channel ?? 'app';
                    @\fwrite(\STDERR, \sprintf('[%s] %s: %s%s', $ch, \strtoupper($level), $message, \PHP_EOL));
                    @\fflush(\STDERR);
                }
            }
        } catch (\Throwable $e) {
            // 日志系统失败时的后备方案
            $fallbackMessage = sprintf(
                "[%s] [%s] [%s] %s\n",
                date('Y-m-d H:i:s'),
                strtoupper($level),
                $channel ?? 'app',
                $message
            );
            @file_put_contents(
                (defined('BP') ? BP : '') . 'var/log/fallback.log',
                $fallbackMessage,
                FILE_APPEND
            );
        }
    }
}

if (!function_exists('w_log_emergency')) {
    /**
     * 记录紧急日志（系统不可用）
     */
    function w_log_emergency(string $message, array $context = [], ?string $channel = null): void
    {
        w_log('emergency', $message, $context, $channel);
    }
}

if (!function_exists('w_log_alert')) {
    /**
     * 记录警报日志（必须立即采取行动）
     */
    function w_log_alert(string $message, array $context = [], ?string $channel = null): void
    {
        w_log('alert', $message, $context, $channel);
    }
}

if (!function_exists('w_log_critical')) {
    /**
     * 记录严重错误日志
     */
    function w_log_critical(string $message, array $context = [], ?string $channel = null): void
    {
        w_log('critical', $message, $context, $channel);
    }
}

if (!function_exists('w_log_error')) {
    /**
     * 记录错误日志
     *
     * @example
     * w_log_error('Failed to save user: {error}', ['error' => $e->getMessage()]);
     * w_log_error('Payment failed', ['_order_id' => $orderId], 'payment');
     */
    function w_log_error(string $message, array $context = [], ?string $channel = null): void
    {
        w_log('error', $message, $context, $channel);
    }
}

if (!function_exists('w_log_warning')) {
    /**
     * 记录警告日志
     *
     * @example
     * w_log_warning('Cache miss for key: {key}', ['key' => $cacheKey]);
     */
    function w_log_warning(string $message, array $context = [], ?string $channel = null): void
    {
        w_log('warning', $message, $context, $channel);
    }
}

if (!function_exists('w_log_notice')) {
    /**
     * 记录通知日志（正常但重要的事件）
     */
    function w_log_notice(string $message, array $context = [], ?string $channel = null): void
    {
        w_log('notice', $message, $context, $channel);
    }
}

if (!function_exists('w_log_info')) {
    /**
     * 记录信息日志
     *
     * @example
     * w_log_info('User registered: {email}', ['email' => $user->getEmail()]);
     */
    function w_log_info(string $message, array $context = [], ?string $channel = null): void
    {
        w_log('info', $message, $context, $channel);
    }
}

if (!function_exists('w_log_debug')) {
    /**
     * 记录调试日志
     *
     * @example
     * w_log_debug('SQL query: {sql}', ['sql' => $query], 'database');
     */
    function w_log_debug(string $message, array $context = [], ?string $channel = null): void
    {
        w_log('debug', $message, $context, $channel);
    }
}

if (!function_exists('w_logger')) {
    /**
     * 获取日志实例
     *
     * @param string|null $channel 通道名
     * @return LoggerInterface
     * 
     * @example
     * $logger = w_logger('payment');
     * $logger->info('Processing payment', ['amount' => $amount]);
     */
    function w_logger(?string $channel = null): LoggerInterface
    {
        return LoggerFactory::create($channel);
    }
}

if (!function_exists('w_log_exception')) {
    /**
     * 记录异常日志
     *
     * @param \Throwable $exception 异常对象
     * @param string|null $message 附加消息
     * @param string|null $channel 通道名
     * 
     * @example
     * try {
     *     // ...
     * } catch (\Exception $e) {
     *     w_log_exception($e, 'Failed to process order');
     * }
     */
    function w_log_exception(\Throwable $exception, ?string $message = null, ?string $channel = null): void
    {
        $exceptionMessage = (string)$exception->getMessage();
        $lowerMessage = \strtolower($exceptionMessage);
        $is402BalanceError = \str_contains($lowerMessage, 'http 402')
            || \str_contains($lowerMessage, 'insufficient balance')
            || \str_contains($lowerMessage, '余额不足')
            || \str_contains($lowerMessage, '额度不足');
        if ($is402BalanceError) {
            static $last402ExceptionLogAt = 0.0;
            $now = \microtime(true);
            if (($now - $last402ExceptionLogAt) < 45.0) {
                return;
            }
            $last402ExceptionLogAt = $now;
        }

        $context = [
            '_exception_class' => get_class($exception),
            '_exception_message' => $exceptionMessage,
            '_exception_code' => $exception->getCode(),
            '_exception_file' => $exception->getFile(),
            '_exception_line' => $exception->getLine(),
            '_exception_trace' => $exception->getTraceAsString(),
        ];

        if ($exception->getPrevious()) {
            $context['_previous_exception'] = get_class($exception->getPrevious()) . ': ' . $exception->getPrevious()->getMessage();
        }

        // 兼容日志占位符替换：异常助手保留 underscore 上下文，同时提供可插值的 plain key
        $context['exception_class'] = $context['_exception_class'];
        $context['exception_message'] = $context['_exception_message'];
        $context['exception_code'] = $context['_exception_code'];
        $context['exception_file'] = $context['_exception_file'];
        $context['exception_line'] = $context['_exception_line'];

        $logMessage = $message ?? 'Exception occurred: {_exception_class}';
        
        w_log('error', $logMessage, $context, $channel ?? 'exception');
    }
}

if (!function_exists('w_log_sql')) {
    /**
     * 记录 SQL 查询日志
     *
     * @param string $sql SQL 语句
     * @param array $bindings 绑定参数
     * @param float|null $executionTime 执行时间（毫秒）
     * 
     * @example
     * w_log_sql('SELECT * FROM users WHERE id = ?', [1], 12.5);
     */
    function w_log_sql(string $sql, array $bindings = [], ?float $executionTime = null): void
    {
        $context = [
            '_sql' => $sql,
        ];
        
        if (!empty($bindings)) {
            $context['_bindings'] = $bindings;
        }
        
        if ($executionTime !== null) {
            $context['_execution_time_ms'] = $executionTime;
        }

        w_log('debug', 'SQL: {_sql}', $context, 'sql');
    }
}

if (!function_exists('w_auth_log')) {
    /**
     * 开发模式下将登录/权限认证步骤与数据写入 var/log/auth.log，生产环境不写入。
     *
     * @param string $step 步骤标识，如 login_post_success, acl_not_logged_in
     * @param string $message 简短描述
     * @param array $data 上下文数据（勿含密码等敏感字段）
     */
    function w_auth_log(string $step, string $message, array $data = []): void
    {
        if (!\defined('DEV') || !DEV) {
            return;
        }
        $line = \json_encode([
            'ts' => \date('Y-m-d H:i:s'),
            'step' => $step,
            'message' => $message,
            'data' => $data,
        ], \JSON_UNESCAPED_UNICODE) . "\n";
        $path = (\defined('BP') ? BP : '') . 'var/log/auth.log';
        @\file_put_contents($path, $line, \FILE_APPEND | \LOCK_EX);
    }
}
