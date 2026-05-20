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

    /** 与 server:start -log / 前台模式 / 实例 enable_log 对齐：开发态额外行为见 WlsLogger、MasterProcess */
    private static ?bool $runtimeVerbose = null;

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
        $configLevel = LogLevel::normalize((string)self::getValue('level', LogLevel::INFO));

        // 生产环境强制最低级别（除非显式配置更高）
        if (!self::isDevMode()) {
            $productionLevel = LogLevel::normalize((string)self::getValue('production_level', LogLevel::INFO));
            if (LogLevel::getPriority($configLevel) < LogLevel::getPriority($productionLevel)) {
                $configLevel = $productionLevel;
            }
        }

        return $configLevel;
    }

    /**
     * 设置运行时 verbose 标志（与 endpoint enable_log / 命令行 -log / 前台启动一致；影响开发态 stdout 等，不再压低主链日志级别）。
     */
    public static function bootstrapVerbose(bool $verbose): void
    {
        if (!\defined('WLS_VERBOSE_LOG')) {
            \define('WLS_VERBOSE_LOG', $verbose);
        }
        self::$runtimeVerbose = $verbose;
    }

    /**
     * 从实例运行态 JSON 读取 enable_log 并应用（供 worker/dispatcher 等子进程）。
     */
    public static function bootstrapVerboseFromInstanceFile(string $instanceName): void
    {
        self::bootstrapVerbose(WlsLogService::readEnableLogFromInstanceFile($instanceName));
    }

    public static function isVerboseWlsLog(): bool
    {
        if (self::$runtimeVerbose !== null) {
            return self::$runtimeVerbose;
        }
        if (\defined('WLS_VERBOSE_LOG')) {
            return (bool) WLS_VERBOSE_LOG;
        }
        $env = self::loadRawEnvConfig();
        $wlsLog = \is_array($env['wls']['log'] ?? null) ? $env['wls']['log'] : [];

        return (bool)($wlsLog['verbose'] ?? false);
    }

    public static function isEnabled(): bool
    {
        return self::isVerboseWlsLog() && (bool)self::getValue('enabled', true);
    }

    public static function isStdoutEnabled(bool $isFrontend = false, bool $isDev = false): bool
    {
        return self::resolveStdoutEnabled(self::getValue('stdout', 'auto'), $isFrontend, $isDev);
    }

    public static function resolveStdoutEnabled(mixed $value, bool $isFrontend = false, bool $isDev = false): bool
    {
        if (\is_string($value)) {
            $normalized = \strtolower(\trim($value));
            if ($normalized === 'auto') {
                return true;
            }

            $boolean = \filter_var($normalized, \FILTER_VALIDATE_BOOLEAN, \FILTER_NULL_ON_FAILURE);
            if ($boolean !== null) {
                return $boolean;
            }
        }

        if ($value === null) {
            return true;
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
