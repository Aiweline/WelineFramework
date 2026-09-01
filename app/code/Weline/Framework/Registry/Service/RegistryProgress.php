<?php

declare(strict_types=1);

namespace Weline\Framework\Registry\Service;

class RegistryProgress
{
    private static bool $enabled = false;
    private static ?float $startedAt = null;

    /** @var null|callable(string, ?int): void */
    private static $webReporter = null;

    /**
     * 注册 Web/SSE 进度回调；CLI 仍走 stderr，Web 回调与 CLI 可并存。
     *
     * @param null|callable(string $message, ?int $progress): void $reporter
     */
    public static function setWebReporter(?callable $reporter): void
    {
        self::$webReporter = $reporter;
    }

    private static function emitWeb(string $message, ?int $progress = null): void
    {
        if (self::$webReporter !== null) {
            (self::$webReporter)($message, $progress);
        }
    }

    public static function enable(bool $enabled = true): void
    {
        self::$enabled = $enabled;

        if ($enabled && self::$startedAt === null) {
            self::$startedAt = microtime(true);
        }
    }

    public static function isEnabled(): bool
    {
        if (PHP_SAPI !== 'cli') {
            return false;
        }

        return self::$enabled || getenv('WELINE_REGISTRY_PROGRESS') === '1';
    }

    public static function run(callable $callback)
    {
        $previousEnabled = self::$enabled;
        self::enable(true);

        try {
            return $callback();
        } finally {
            self::$enabled = $previousEnabled;
        }
    }

    public static function log(string $message): void
    {
        self::emitWeb($message);
        self::writeCli($message);
    }

    private static function writeCli(string $message): void
    {
        if (!self::isEnabled()) {
            return;
        }

        if (self::$startedAt === null) {
            self::$startedAt = microtime(true);
        }

        $elapsed = microtime(true) - self::$startedAt;
        $line = sprintf(
            '[registry] %s +%.2fs mem=%s peak=%s %s',
            date('H:i:s'),
            $elapsed,
            self::formatBytes(memory_get_usage(true)),
            self::formatBytes(memory_get_peak_usage(true)),
            $message
        );

        if (\defined('STDERR') && \is_resource(STDERR)) {
            \fwrite(STDERR, $line . PHP_EOL);
            \fflush(STDERR);
        } else {
            echo $line . PHP_EOL;
        }

        if (function_exists('ob_flush')) {
            @ob_flush();
        }
        flush();
    }

    public static function section(string $name): void
    {
        self::log('== ' . $name . ' ==');
    }

    public static function module(string $scope, int $index, int $total, string $moduleName, string $message = ''): void
    {
        $suffix = $message !== '' ? ' ' . $message : '';
        $line = sprintf('%s [%d/%d] %s%s', $scope, $index, $total, $moduleName, $suffix);
        $percent = $total > 0 ? (int) round(($index / $total) * 100) : null;
        self::emitWeb($line, $percent);
        self::writeCli($line);
    }

    public static function count(string $scope, int $count, string $label): void
    {
        $line = sprintf('%s: %d %s', $scope, $count, $label);
        self::emitWeb($line);
        self::writeCli($line);
    }

    private static function formatBytes(int $bytes): string
    {
        if ($bytes >= 1024 * 1024 * 1024) {
            return sprintf('%.1fG', $bytes / 1024 / 1024 / 1024);
        }

        if ($bytes >= 1024 * 1024) {
            return sprintf('%.1fM', $bytes / 1024 / 1024);
        }

        if ($bytes >= 1024) {
            return sprintf('%.1fK', $bytes / 1024);
        }

        return $bytes . 'B';
    }
}
