<?php

declare(strict_types=1);

namespace Agent\CursorBase\Helper;

/**
 * 平台检测助手
 * 
 * 职责：检测操作系统平台，提供平台相关的工具方法
 */
class PlatformHelper
{
    /**
     * 检查是否为 Windows 系统
     */
    public static function isWindows(): bool
    {
        return strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
    }

    /**
     * 检查是否为 macOS 系统
     */
    public static function isMac(): bool
    {
        return strtoupper(PHP_OS) === 'DARWIN';
    }

    /**
     * 检查是否为 Linux 系统
     */
    public static function isLinux(): bool
    {
        return strtoupper(PHP_OS) === 'LINUX';
    }

    /**
     * 获取平台名称
     */
    public static function getPlatformName(): string
    {
        if (self::isWindows()) {
            return 'windows';
        }
        if (self::isMac()) {
            return 'mac';
        }
        if (self::isLinux()) {
            return 'linux';
        }
        return 'unknown';
    }

    /**
     * 获取临时目录
     */
    public static function getTempDir(): string
    {
        return sys_get_temp_dir();
    }

    /**
     * 规范化路径分隔符
     */
    public static function normalizePath(string $path): string
    {
        return str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $path);
    }

    /**
     * 转换为 Unix 风格路径（用于 CLI 命令）
     */
    public static function toUnixPath(string $path): string
    {
        return str_replace('\\', '/', $path);
    }

    /**
     * 确保目录存在
     */
    public static function ensureDirectoryExists(string $path): bool
    {
        if (!is_dir($path)) {
            return mkdir($path, 0755, true);
        }
        return true;
    }
}
