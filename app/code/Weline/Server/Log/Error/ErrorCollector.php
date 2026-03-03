<?php
declare(strict_types=1);

/**
 * WLS 错误收集器
 *
 * 统一收集所有层级的错误，格式化后写入日志。
 * 同时输出到 stderr（供 Master 管道捕获）和本地日志文件。
 *
 * @author Aiweline
 */

namespace Weline\Server\Log\Error;

use Weline\Server\Log\LogLevel;
use Weline\Server\Log\WlsLogger;
use Weline\Server\Log\LogConfig;

class ErrorCollector
{
    /**
     * PHP 错误类型名称映射
     * 注：E_STRICT 常量在 PHP 8.4 中已被弃用，改用硬编码值 2048
     */
    private const ERROR_TYPE_NAMES = [
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
        2048 => 'E_STRICT',
        E_RECOVERABLE_ERROR => 'E_RECOVERABLE_ERROR',
        E_DEPRECATED => 'E_DEPRECATED',
        E_USER_DEPRECATED => 'E_USER_DEPRECATED',
    ];

    /**
     * 收集并处理错误
     *
     * @param array $error 错误信息数组，包含：
     *   - level: 日志级别 (LogLevel::*)
     *   - type: 错误类型 (php_error/exception/fatal_error)
     *   - message: 错误消息
     *   - file: 文件路径（可选）
     *   - line: 行号（可选）
     *   - errno: PHP 错误代码（可选）
     *   - trace: 堆栈跟踪（可选）
     *   - trace_string: 堆栈跟踪字符串（可选）
     *   - class: 异常类名（可选）
     *   - code: 异常代码（可选）
     */
    public static function collect(array $error): void
    {
        // 添加时间戳和上下文
        $error['timestamp'] = \date('Y-m-d H:i:s.u');
        $error['context'] = ErrorContext::getFullContext();

        // 格式化错误信息
        $formatted = self::format($error);

        // 输出到 stderr（供 Master 管道捕获）
        self::writeStderr($formatted, $error['level']);

        // 写入 WlsLogger
        self::writeToLogger($error);

        // FATAL 级别写入专门的崩溃日志
        if ($error['level'] === LogLevel::FATAL) {
            self::writeCrashLog($error);
        }
    }

    /**
     * 格式化错误信息为可读字符串
     */
    private static function format(array $error): string
    {
        $level = $error['level'] ?? LogLevel::ERROR;
        $tag = $error['context']['process_tag'] ?? ErrorContext::getProcessTag();
        $time = $error['timestamp'] ?? \date('Y-m-d H:i:s');

        $lines = [];
        $lines[] = "[{$time}] [{$tag}] [{$level}] {$error['message']}";

        // 位置信息
        if (isset($error['file'])) {
            $lines[] = "  Location: {$error['file']}:{$error['line']}";
        }

        // 错误类型信息
        if (isset($error['errno'])) {
            $typeName = self::ERROR_TYPE_NAMES[$error['errno']] ?? "E_UNKNOWN({$error['errno']})";
            $lines[] = "  Type: {$typeName}";
        }

        // 异常类信息
        if (isset($error['class'])) {
            $lines[] = "  Exception: {$error['class']} (code: {$error['code']})";
        }

        // 堆栈跟踪（非 WARNING/NOTICE 级别）
        if (isset($error['trace_string']) && !in_array($level, [LogLevel::WARNING, LogLevel::NOTICE])) {
            $lines[] = "  Stack trace:";
            foreach (\explode("\n", $error['trace_string']) as $traceLine) {
                if (\trim($traceLine) !== '') {
                    $lines[] = "    " . \trim($traceLine);
                }
            }
        }

        // 内存信息（FATAL 级别）
        if ($level === LogLevel::FATAL && isset($error['context']['memory_usage'])) {
            $memMB = \round($error['context']['memory_usage'] / 1024 / 1024, 2);
            $peakMB = \round($error['context']['memory_peak'] / 1024 / 1024, 2);
            $lines[] = "  Memory: {$memMB}MB (peak: {$peakMB}MB)";
        }

        return \implode("\n", $lines) . "\n";
    }

    /**
     * 输出到 stderr
     */
    private static function writeStderr(string $message, string $level): void
    {
        $color = LogLevel::getColor($level);
        $reset = LogLevel::getReset();

        if (\defined('STDERR') && \is_resource(STDERR)) {
            @\fwrite(STDERR, $color . $message . $reset);
            @\fflush(STDERR);
        }
    }

    /**
     * 写入 WlsLogger
     */
    private static function writeToLogger(array $error): void
    {
        $level = $error['level'] ?? LogLevel::ERROR;
        $message = $error['message'] ?? 'Unknown error';

        // 构建上下文
        $context = [];
        if (isset($error['file'])) {
            $context['file'] = $error['file'];
            $context['line'] = $error['line'];
        }
        if (isset($error['errno'])) {
            $context['errno'] = $error['errno'];
        }
        if (isset($error['class'])) {
            $context['exception'] = $error['class'];
        }

        try {
            WlsLogger::getInstance()->log($level, $message, $context);
        } catch (\Throwable $e) {
            // Logger 失败时的 fallback：直接写文件
            self::fallbackWrite($error);
        }
    }

    /**
     * 写入崩溃日志（JSON 格式）
     */
    private static function writeCrashLog(array $error): void
    {
        $crashData = [
            'time' => $error['timestamp'] ?? \date('Y-m-d H:i:s'),
            'process' => $error['context']['process_tag'] ?? ErrorContext::getProcessTag(),
            'pid' => $error['context']['pid'] ?? \getmypid(),
            'error' => [
                'type' => $error['type'] ?? 'unknown',
                'errno_name' => isset($error['errno']) ? (self::ERROR_TYPE_NAMES[$error['errno']] ?? null) : null,
                'message' => $error['message'],
                'file' => $error['file'] ?? null,
                'line' => $error['line'] ?? null,
            ],
            'memory' => [
                'usage' => $error['context']['memory_usage'] ?? null,
                'peak' => $error['context']['memory_peak'] ?? null,
            ],
            'context' => $error['context'],
        ];

        // 移除重复数据
        unset($crashData['context']['memory_usage'], $crashData['context']['memory_peak']);

        try {
            WlsLogger::getInstance()->writeCrashLog($crashData);
        } catch (\Throwable $e) {
            // Fallback
            $logDir = LogConfig::getLogDir();
            if (!\is_dir($logDir)) {
                @\mkdir($logDir, 0755, true);
            }
            $crashLog = $logDir . 'crash.log';
            $json = \json_encode($crashData, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            @\file_put_contents($crashLog, $json . "\n\n", FILE_APPEND | LOCK_EX);
        }
    }

    /**
     * Fallback 写入（Logger 失败时使用）
     */
    private static function fallbackWrite(array $error): void
    {
        $formatted = self::format($error);

        // 尝试写入 var/log/wls/
        $logDir = \defined('BP') ? BP . 'var/log/wls/' : \sys_get_temp_dir() . '/wls-log/';
        if (!\is_dir($logDir)) {
            @\mkdir($logDir, 0755, true);
        }

        $logFile = $logDir . 'wls.log';
        @\file_put_contents($logFile, $formatted, FILE_APPEND | LOCK_EX);
    }

    /**
     * 获取 PHP 错误类型名称
     */
    public static function getErrorTypeName(int $errno): string
    {
        return self::ERROR_TYPE_NAMES[$errno] ?? "E_UNKNOWN({$errno})";
    }
}
