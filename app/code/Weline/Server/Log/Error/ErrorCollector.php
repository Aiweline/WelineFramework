<?php
declare(strict_types=1);

namespace Weline\Server\Log\Error;

use Weline\Server\Log\LogLevel;
use Weline\Server\Log\WlsLogger;
use Weline\Server\Service\WlsLogService;

class ErrorCollector
{
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

    public static function collect(array $error): void
    {
        $error['timestamp'] = \date('Y-m-d H:i:s.u');
        $error['context'] = ErrorContext::getFullContext();

        $formatted = self::format($error);
        self::writeStderr($formatted, (string)($error['level'] ?? LogLevel::ERROR));
        self::writeToLogger($error);

        if (($error['level'] ?? LogLevel::ERROR) === LogLevel::FATAL) {
            self::writeCrashLog($error);
        }
    }

    private static function format(array $error): string
    {
        $level = (string)($error['level'] ?? LogLevel::ERROR);
        $tag = (string)($error['context']['process_tag'] ?? ErrorContext::getProcessTag());
        $time = (string)($error['timestamp'] ?? \date('Y-m-d H:i:s'));

        $lines = [];
        $lines[] = "[{$time}] [{$tag}] [{$level}] " . (string)($error['message'] ?? 'Unknown error');

        if (isset($error['file'])) {
            $line = $error['line'] ?? 0;
            $lines[] = "  Location: {$error['file']}:{$line}";
        }

        if (isset($error['errno'])) {
            $errno = (int)$error['errno'];
            $typeName = self::ERROR_TYPE_NAMES[$errno] ?? "E_UNKNOWN({$errno})";
            $lines[] = "  Type: {$typeName}";
        }

        if (isset($error['class'])) {
            $code = $error['code'] ?? 0;
            $lines[] = "  Exception: {$error['class']} (code: {$code})";
        }

        if (isset($error['trace_string']) && !\in_array($level, [LogLevel::WARNING, LogLevel::NOTICE], true)) {
            $lines[] = '  Stack trace:';
            foreach (\explode("\n", (string)$error['trace_string']) as $traceLine) {
                $traceLine = \trim($traceLine);
                if ($traceLine !== '') {
                    $lines[] = '    ' . $traceLine;
                }
            }
        }

        if ($level === LogLevel::FATAL && isset($error['context']['memory_usage'])) {
            $memMB = \round(((int)$error['context']['memory_usage']) / 1024 / 1024, 2);
            $peakMB = \round(((int)($error['context']['memory_peak'] ?? 0)) / 1024 / 1024, 2);
            $lines[] = "  Memory: {$memMB}MB (peak: {$peakMB}MB)";
        }

        return \implode("\n", $lines) . "\n";
    }

    private static function writeStderr(string $message, string $level): void
    {
        $colored = LogLevel::colorLine($message, $level);
        if (\defined('STDERR') && \is_resource(STDERR)) {
            @\fwrite(STDERR, $colored);
            @\fflush(STDERR);
        }
    }

    private static function writeToLogger(array $error): void
    {
        $level = (string)($error['level'] ?? LogLevel::ERROR);
        $message = (string)($error['message'] ?? 'Unknown error');

        $context = [];
        if (isset($error['file'])) {
            $context['file'] = $error['file'];
            $context['line'] = $error['line'] ?? 0;
        }
        if (isset($error['errno'])) {
            $context['errno'] = (int)$error['errno'];
        }
        if (isset($error['class'])) {
            $context['exception'] = (string)$error['class'];
        }

        try {
            WlsLogger::getInstance()->log($level, $message, $context);
        } catch (\Throwable $e) {
            self::fallbackWrite($error);
        }
    }

    private static function writeCrashLog(array $error): void
    {
        $crashData = [
            'time' => $error['timestamp'] ?? \date('Y-m-d H:i:s'),
            'process' => $error['context']['process_tag'] ?? ErrorContext::getProcessTag(),
            'pid' => $error['context']['pid'] ?? \getmypid(),
            'error' => [
                'type' => $error['type'] ?? 'unknown',
                'errno_name' => isset($error['errno'])
                    ? (self::ERROR_TYPE_NAMES[(int)$error['errno']] ?? null)
                    : null,
                'message' => $error['message'] ?? 'Unknown fatal error',
                'file' => $error['file'] ?? null,
                'line' => $error['line'] ?? null,
            ],
            'memory' => [
                'usage' => $error['context']['memory_usage'] ?? null,
                'peak' => $error['context']['memory_peak'] ?? null,
            ],
            'context' => $error['context'] ?? [],
        ];

        unset($crashData['context']['memory_usage'], $crashData['context']['memory_peak']);

        try {
            WlsLogger::getInstance()->writeCrashLog($crashData);
        } catch (\Throwable $e) {
            $instance = \is_string($error['context']['instance'] ?? null)
                ? (string)$error['context']['instance']
                : null;
            $processTag = \is_string($error['context']['process_tag'] ?? null)
                ? (string)$error['context']['process_tag']
                : null;
            $crashLog = WlsLogService::getCrashLogFile($instance, $processTag);
            $logDir = \dirname($crashLog);
            if (!\is_dir($logDir)) {
                @\mkdir($logDir, 0755, true);
            }
            $json = \json_encode($crashData, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            @\file_put_contents($crashLog, $json . "\n\n", FILE_APPEND | LOCK_EX);
        }
    }

    private static function fallbackWrite(array $error): void
    {
        $formatted = self::format($error);

        $instance = \is_string($error['context']['instance'] ?? null)
            ? (string)$error['context']['instance']
            : null;
        $processTag = \is_string($error['context']['process_tag'] ?? null)
            ? (string)$error['context']['process_tag']
            : null;

        $logFile = WlsLogService::getMainLogFile($instance, $processTag);
        $logDir = \dirname($logFile);
        if (!\is_dir($logDir)) {
            @\mkdir($logDir, 0755, true);
        }

        @\file_put_contents($logFile, $formatted, FILE_APPEND | LOCK_EX);
    }

    public static function getErrorTypeName(int $errno): string
    {
        return self::ERROR_TYPE_NAMES[$errno] ?? "E_UNKNOWN({$errno})";
    }
}

