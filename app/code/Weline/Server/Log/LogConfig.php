<?php
declare(strict_types=1);

namespace Weline\Server\Log;

use Weline\Server\Service\WlsLogService;

class LogConfig
{
    private const DEFAULTS = [
        'enabled' => true,
        'path' => 'var/log/wls/',
        'level' => LogLevel::INFO,
        'stdout' => 'auto',
        'rotate' => 'daily',
        'max_files' => 7,
        'max_size' => 52428800,
        // 生产环境默认禁用 DEBUG 日志
        'production_level' => LogLevel::INFO,
    ];

    private static ?array $configCache = null;

    public static function get(): array
    {
        if (self::$configCache !== null) {
            return self::$configCache;
        }

        $envConfig = self::loadEnvConfig();
        self::$configCache = \array_merge(self::DEFAULTS, $envConfig);
        self::$configCache['level'] = LogLevel::normalize((string)self::$configCache['level']);

        return self::$configCache;
    }

    public static function getValue(string $key, mixed $default = null): mixed
    {
        $config = self::get();
        return $config[$key] ?? $default;
    }

    public static function getLogDir(?string $instanceName = null, ?string $processTag = null): string
    {
        $path = (string)self::getValue('path', self::DEFAULTS['path']);
        return WlsLogService::getLogDir($instanceName, $processTag, $path);
    }

    public static function getMainLogFile(?string $instanceName = null, ?string $processTag = null): string
    {
        return self::getLogDir($instanceName, $processTag) . 'wls-' . \date('Y-m-d') . '.log';
    }

    public static function getErrorLogFile(?string $instanceName = null, ?string $processTag = null): string
    {
        return self::getLogDir($instanceName, $processTag) . 'error-' . \date('Y-m-d') . '.log';
    }

    public static function getCrashLogFile(?string $instanceName = null, ?string $processTag = null): string
    {
        return self::getLogDir($instanceName, $processTag) . 'crash-' . \date('Y-m-d') . '.log';
    }

    public static function getMinLevel(): string
    {
        $configLevel = (string)self::getValue('level', LogLevel::INFO);

        // 生产环境强制最低 INFO 级别（除非显式配置）
        if (!self::isDevMode()) {
            $productionLevel = (string)self::getValue('production_level', LogLevel::INFO);

            // 如果配置的级别低于生产环境最低级别，使用生产环境级别
            $configPriority = LogLevel::getPriority($configLevel);
            $productionPriority = LogLevel::getPriority($productionLevel);

            if ($configPriority < $productionPriority) {
                return $productionLevel;
            }
        }

        return $configLevel;
    }

    public static function isEnabled(): bool
    {
        return (bool)self::getValue('enabled', true);
    }

    public static function isStdoutEnabled(bool $isFrontend = false, bool $isDev = false): bool
    {
        $value = self::getValue('stdout', 'auto');

        if ($value === 'auto') {
            return $isFrontend || $isDev;
        }

        return (bool)$value;
    }

    public static function isDevMode(): bool
    {
        if (\defined('WLS_DEV_MODE')) {
            return WLS_DEV_MODE;
        }

        if (\defined('DEV')) {
            return DEV;
        }

        $envConfig = self::loadRawEnvConfig();
        return ($envConfig['deploy'] ?? '') === 'dev';
    }

    public static function clearCache(): void
    {
        self::$configCache = null;
        WlsLogService::clearCache();
    }

    private static function loadEnvConfig(): array
    {
        $env = self::loadRawEnvConfig();
        $wls = $env['wls'] ?? [];
        return \is_array($wls['log'] ?? null) ? $wls['log'] : [];
    }

    private static function loadRawEnvConfig(): array
    {
        static $rawConfig = null;

        if ($rawConfig !== null) {
            return $rawConfig;
        }

        if (\defined('BP')) {
            $envFile = BP . 'app' . DIRECTORY_SEPARATOR . 'etc' . DIRECTORY_SEPARATOR . 'env.php';
        } else {
            $envFile = \dirname(__DIR__, 5) . DIRECTORY_SEPARATOR . 'etc' . DIRECTORY_SEPARATOR . 'env.php';
        }

        if (\is_file($envFile)) {
            $rawConfig = (array)@include $envFile;
        } else {
            $rawConfig = [];
        }

        return $rawConfig;
    }
}
