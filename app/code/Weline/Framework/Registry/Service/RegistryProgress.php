<?php

declare(strict_types=1);

namespace Weline\Framework\Registry\Service;

class RegistryProgress
{
    private static bool $enabled = false;
    private static ?float $startedAt = null;

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
        if (!self::isEnabled()) {
            return;
        }

        if (self::$startedAt === null) {
            self::$startedAt = microtime(true);
        }

        $elapsed = microtime(true) - self::$startedAt;
        echo sprintf('[registry] %s +%.2fs %s', date('H:i:s'), $elapsed, $message) . PHP_EOL;

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
        self::log(sprintf('%s [%d/%d] %s%s', $scope, $index, $total, $moduleName, $suffix));
    }

    public static function count(string $scope, int $count, string $label): void
    {
        self::log(sprintf('%s: %d %s', $scope, $count, $label));
    }
}
