<?php
declare(strict_types=1);

/**
 * WLS 日志配置管理
 *
 * 从 env.php 读取日志配置，提供默认值和配置验证。
 *
 * @author Aiweline
 */

namespace Weline\Server\Log;

class LogConfig
{
    /**
     * 默认配置
     */
    private const DEFAULTS = [
        'enabled' => true,
        'path' => 'var/log/wls/',
        'level' => LogLevel::INFO,
        'stdout' => 'auto',          // auto | true | false
        'rotate' => 'daily',         // daily | size | none
        'max_files' => 7,
        'max_size' => 52428800,      // 50MB
    ];

    private static ?array $configCache = null;

    /**
     * 获取完整配置
     */
    public static function get(): array
    {
        if (self::$configCache !== null) {
            return self::$configCache;
        }

        $envConfig = self::loadEnvConfig();
        self::$configCache = \array_merge(self::DEFAULTS, $envConfig);

        // 标准化日志级别
        self::$configCache['level'] = LogLevel::normalize(self::$configCache['level']);

        return self::$configCache;
    }

    /**
     * 获取单个配置项
     */
    public static function getValue(string $key, mixed $default = null): mixed
    {
        $config = self::get();
        return $config[$key] ?? $default;
    }

    /**
     * 获取日志目录绝对路径
     */
    public static function getLogDir(): string
    {
        $path = self::getValue('path', self::DEFAULTS['path']);

        // 相对路径转绝对路径
        if (!\str_starts_with($path, '/') && !\preg_match('/^[A-Za-z]:/', $path)) {
            $path = BP . $path;
        }

        // 确保以目录分隔符结尾
        if (!\str_ends_with($path, DIRECTORY_SEPARATOR) && !\str_ends_with($path, '/')) {
            $path .= DIRECTORY_SEPARATOR;
        }

        return $path;
    }

    /**
     * 获取主日志文件路径
     */
    public static function getMainLogFile(): string
    {
        return self::getLogDir() . 'wls.log';
    }

    /**
     * 获取错误日志文件路径
     */
    public static function getErrorLogFile(): string
    {
        return self::getLogDir() . 'error.log';
    }

    /**
     * 获取崩溃日志文件路径
     */
    public static function getCrashLogFile(): string
    {
        return self::getLogDir() . 'crash.log';
    }

    /**
     * 获取最小日志级别
     */
    public static function getMinLevel(): string
    {
        return self::getValue('level', LogLevel::INFO);
    }

    /**
     * 是否启用日志
     */
    public static function isEnabled(): bool
    {
        return (bool)self::getValue('enabled', true);
    }

    /**
     * 是否启用终端输出
     *
     * @param bool $isFrontend 是否前台模式
     * @param bool $isDev 是否开发环境
     */
    public static function isStdoutEnabled(bool $isFrontend = false, bool $isDev = false): bool
    {
        $value = self::getValue('stdout', 'auto');

        if ($value === 'auto') {
            return $isFrontend || $isDev;
        }

        return (bool)$value;
    }

    /**
     * 判断是否为开发环境
     */
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

    /**
     * 清除配置缓存（用于热重载）
     */
    public static function clearCache(): void
    {
        self::$configCache = null;
    }

    /**
     * 从 env.php 加载 wls.log
     */
    private static function loadEnvConfig(): array
    {
        $env = self::loadRawEnvConfig();
        $wls = $env['wls'] ?? [];
        return \is_array($wls['log'] ?? null) ? $wls['log'] : [];
    }

    /**
     * 加载原始 env.php 配置
     */
    private static function loadRawEnvConfig(): array
    {
        static $rawConfig = null;

        if ($rawConfig !== null) {
            return $rawConfig;
        }

        // 尝试多种方式获取配置
        if (\defined('BP')) {
            $envFile = BP . 'app' . DIRECTORY_SEPARATOR . 'etc' . DIRECTORY_SEPARATOR . 'env.php';
        } else {
            // 回退：从当前文件路径推算
            $envFile = \dirname(__DIR__, 4) . DIRECTORY_SEPARATOR . 'etc' . DIRECTORY_SEPARATOR . 'env.php';
        }

        if (\is_file($envFile)) {
            $rawConfig = (array)@include $envFile;
        } else {
            $rawConfig = [];
        }

        return $rawConfig;
    }
}
