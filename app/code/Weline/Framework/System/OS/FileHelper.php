<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\System\OS;

use Exception;
use Weline\Framework\Runtime\SchedulerSystem;

/**
 * 跨平台文件和路径操作辅助类
 * 兼容 Windows 和 Linux 系统
 */
class FileHelper
{
    /**
     * 操作系统类型常量
     */
    const OS_WINDOWS = 'windows';
    const OS_LINUX = 'linux';
    const OS_MACOS = 'macos';
    const OS_UNKNOWN = 'unknown';

    /**
     * 默认目录权限
     */
    const DEFAULT_DIR_PERMISSIONS = 0755;
    
    /**
     * 默认文件权限
     */
    const DEFAULT_FILE_PERMISSIONS = 0644;

    /**
     * 获取当前操作系统类型
     * 
     * @return string
     */
    public static function getOperatingSystem(): string
    {
        $os = strtolower(PHP_OS);
        
        if (strpos($os, 'win') === 0) {
            return self::OS_WINDOWS;
        } elseif (strpos($os, 'linux') === 0) {
            return self::OS_LINUX;
        } elseif (strpos($os, 'darwin') === 0) {
            return self::OS_MACOS;
        }
        
        return self::OS_UNKNOWN;
    }

    /**
     * 是否为Windows系统
     * 
     * @return bool
     */
    public static function isWindows(): bool
    {
        return self::getOperatingSystem() === self::OS_WINDOWS;
    }

    /**
     * 是否为Linux系统
     * 
     * @return bool
     */
    public static function isLinux(): bool
    {
        return self::getOperatingSystem() === self::OS_LINUX;
    }

    /**
     * 是否为macOS系统
     * 
     * @return bool
     */
    public static function isMacOS(): bool
    {
        return self::getOperatingSystem() === self::OS_MACOS;
    }

    /**
     * 获取正确的路径分隔符
     * 
     * @return string
     */
    public static function getDirectorySeparator(): string
    {
        return DIRECTORY_SEPARATOR;
    }

    /**
     * 标准化路径分隔符
     * 将路径中的分隔符统一为当前系统的分隔符
     * 
     * @param string $path
     * @return string
     */
    public static function normalizePath(string $path): string
    {
        // 将所有类型的分隔符统一替换为当前系统的分隔符
        $path = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
        
        // 移除多余的分隔符
        $path = preg_replace('/[' . preg_quote(DIRECTORY_SEPARATOR, '/') . ']+/', DIRECTORY_SEPARATOR, $path);
        
        return rtrim($path, DIRECTORY_SEPARATOR);
    }

    /**
     * 拼接路径
     * 
     * @param string ...$paths
     * @return string
     */
    public static function joinPath(string ...$paths): string
    {
        if (empty($paths)) {
            return '';
        }

        $result = '';
        foreach ($paths as $index => $path) {
            $path = self::normalizePath($path);
            
            if ($index === 0) {
                $result = $path;
            } else {
                $result = rtrim($result, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . ltrim($path, DIRECTORY_SEPARATOR);
            }
        }

        return self::normalizePath($result);
    }

    /**
     * 获取绝对路径
     * 
     * @param string $path
     * @return string
     */
    public static function getAbsolutePath(string $path): string
    {
        $path = self::normalizePath($path);
        return realpath($path) ?: $path;
    }

    /**
     * 创建目录（支持递归创建）
     * 
     * @param string $directory
     * @param int $permissions
     * @param bool $recursive
     * @return bool
     * @throws Exception
     */
    public static function createDirectory(string $directory, int $permissions = self::DEFAULT_DIR_PERMISSIONS, bool $recursive = true): bool
    {
        $directory = self::normalizePath($directory);
        
        if (is_dir($directory)) {
            return true;
        }

        try {
            $result = mkdir($directory, $permissions, $recursive);
            
            if ($result && !self::isWindows()) {
                // 在非Windows系统上显式设置权限
                chmod($directory, $permissions);
            }
            
            return $result;
        } catch (Exception $e) {
            throw new Exception(__("无法创建目录 '%{1}': %{2}", [$directory, $e->getMessage()]));
        }
    }

    /**
     * 删除目录（支持递归删除）
     * 
     * @param string $directory
     * @param bool $recursive
     * @return bool
     * @throws Exception
     */
    public static function removeDirectory(string $directory, bool $recursive = false): bool
    {
        $directory = self::normalizePath($directory);
        
        if (!is_dir($directory)) {
            return true;
        }

        if ($recursive) {
            $files = array_diff(scandir($directory), ['.', '..']);
            
            foreach ($files as $file) {
                $filePath = self::joinPath($directory, $file);
                
                if (is_dir($filePath)) {
                    self::removeDirectory($filePath, true);
                } else {
                    self::deleteFile($filePath);
                }
            }
        }

        return rmdir($directory);
    }

    /**
     * 创建文件
     * 
     * @param string $filePath
     * @param string $content
     * @param int $permissions
     * @return bool
     * @throws Exception
     */
    public static function createFile(string $filePath, string $content = '', int $permissions = self::DEFAULT_FILE_PERMISSIONS): bool
    {
        $filePath = self::normalizePath($filePath);
        
        // 确保目录存在
        $directory = dirname($filePath);
        if (!is_dir($directory)) {
            self::createDirectory($directory);
        }

        try {
            $result = file_put_contents($filePath, $content, LOCK_EX) !== false;
            
            if ($result && !self::isWindows()) {
                chmod($filePath, $permissions);
            }
            
            return $result;
        } catch (Exception $e) {
            throw new Exception(__("无法创建文件 '%{1}': %{2}", [$filePath, $e->getMessage()]));
        }
    }

    /**
     * 删除文件
     * 
     * @param string $filePath
     * @return bool
     */
    public static function deleteFile(string $filePath): bool
    {
        $filePath = self::normalizePath($filePath);
        
        if (!file_exists($filePath)) {
            return true;
        }

        return unlink($filePath);
    }

    /**
     * 复制文件
     * 
     * @param string $source
     * @param string $destination
     * @param bool $createDirectory
     * @return bool
     * @throws Exception
     */
    public static function copyFile(string $source, string $destination, bool $createDirectory = true): bool
    {
        $source = self::normalizePath($source);
        $destination = self::normalizePath($destination);
        
        if (!file_exists($source)) {
            throw new Exception(__("源文件不存在: %{1}", [$source]));
        }

        if ($createDirectory) {
            $directory = dirname($destination);
            if (!is_dir($directory)) {
                self::createDirectory($directory);
            }
        }

        return copy($source, $destination);
    }

    /**
     * 移动文件
     * 
     * @param string $source
     * @param string $destination
     * @param bool $createDirectory
     * @return bool
     * @throws Exception
     */
    public static function moveFile(string $source, string $destination, bool $createDirectory = true): bool
    {
        $source = self::normalizePath($source);
        $destination = self::normalizePath($destination);
        
        if (!file_exists($source)) {
            throw new Exception(__("源文件不存在: %{1}", [$source]));
        }

        if ($createDirectory) {
            $directory = dirname($destination);
            if (!is_dir($directory)) {
                self::createDirectory($directory);
            }
        }

        return rename($source, $destination);
    }

    /**
     * 检查路径是否可写
     * 
     * @param string $path
     * @return bool
     */
    public static function isWritable(string $path): bool
    {
        $path = self::normalizePath($path);
        return is_writable($path);
    }

    /**
     * 检查路径是否可读
     * 
     * @param string $path
     * @return bool
     */
    public static function isReadable(string $path): bool
    {
        $path = self::normalizePath($path);
        return is_readable($path);
    }

    /**
     * 设置文件或目录权限
     * 
     * @param string $path
     * @param int $permissions
     * @return bool
     */
    public static function setPermissions(string $path, int $permissions): bool
    {
        $path = self::normalizePath($path);
        
        if (!file_exists($path)) {
            return false;
        }

        // Windows系统不支持chmod，直接返回true
        if (self::isWindows()) {
            return true;
        }

        return chmod($path, $permissions);
    }

    /**
     * 获取文件或目录权限
     * 
     * @param string $path
     * @return string|false
     */
    public static function getPermissions(string $path)
    {
        $path = self::normalizePath($path);
        
        if (!file_exists($path)) {
            return false;
        }

        $perms = fileperms($path);
        return substr(sprintf('%o', $perms), -4);
    }

    /**
     * 获取文件大小（字节）
     * 
     * @param string $filePath
     * @return int|false
     */
    public static function getFileSize(string $filePath)
    {
        $filePath = self::normalizePath($filePath);
        
        if (!file_exists($filePath) || !is_file($filePath)) {
            return false;
        }

        return filesize($filePath);
    }

    /**
     * 获取格式化的文件大小
     * 
     * @param string $filePath
     * @param int $precision
     * @return string|false
     */
    public static function getFormattedFileSize(string $filePath, int $precision = 2)
    {
        $size = self::getFileSize($filePath);
        
        if ($size === false) {
            return false;
        }

        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $unitIndex = 0;
        
        while ($size >= 1024 && $unitIndex < count($units) - 1) {
            $size /= 1024;
            $unitIndex++;
        }

        return round($size, $precision) . ' ' . $units[$unitIndex];
    }

    /**
     * 安全写入文件（带错误处理、权限检查和重试机制）
     * 
     * @param string $filePath
     * @param string $content
     * @param int $permissions
     * @param int $maxRetries 最大重试次数
     * @param int $retryDelay 重试延迟（微秒）
     * @return bool
     * @throws Exception
     */
    public static function safeWriteFile(string $filePath, string $content, int $permissions = self::DEFAULT_FILE_PERMISSIONS, int $maxRetries = 3, int $retryDelay = 100000): bool
    {
        $filePath = self::normalizePath($filePath);
        $directory = dirname($filePath);

        try {
            // 确保目录存在且可写
            if (!is_dir($directory)) {
                if (!self::createDirectory($directory)) {
                    throw new Exception(__("无法创建目录: %{1}", [$directory]));
                }
            }

            if (!self::isWritable($directory)) {
                throw new Exception(__("目录不可写: %{1}", [$directory]));
            }

            // 重试机制处理文件锁定问题
            for ($i = 0; $i < $maxRetries; $i++) {
                try {
                    // 写入文件
                    $result = file_put_contents($filePath, $content, LOCK_EX);
                    if ($result !== false) {
                        // 设置权限
                        if (!self::isWindows()) {
                            self::setPermissions($filePath, $permissions);
                        }
                        return true;
                    }
                    
                    // 如果是权限错误且还有重试机会，等待后重试
                    if ($i < $maxRetries - 1) {
                        $error = error_get_last();
                        if ($error && (strpos($error['message'], 'Permission denied') !== false || 
                                       strpos($error['message'], 'errno=13') !== false)) {
                            w_log_warning(__("文件写入权限错误，重试第 %{1} 次: %{2}", [($i + 1), $filePath]));
                            SchedulerSystem::usleep($retryDelay);
                            continue;
                        }
                    }
                    
                    throw new Exception(__("无法写入文件: %{1}", [$filePath]));
                    
                } catch (Exception $e) {
                    if ($i < $maxRetries - 1) {
                        $error = error_get_last();
                        if ($error && (strpos($error['message'], 'Permission denied') !== false || 
                                       strpos($error['message'], 'errno=13') !== false)) {
                            w_log_warning(__("文件写入异常，重试第 %{1} 次: %{2} - %{3}", [($i + 1), $filePath, $e->getMessage()]));
                            SchedulerSystem::usleep($retryDelay);
                            continue;
                        }
                    }
                    throw $e;
                }
            }
            
            throw new Exception(__("文件写入最终失败: %{1}", [$filePath]));
            
        } catch (Exception $e) {
            w_log_error(__("文件写入失败: %{1}", [$e->getMessage()]));
            throw $e;
        }
    }

    /**
     * 安全读取文件（带重试机制）
     * 
     * @param string $filePath
     * @param int $maxRetries 最大重试次数
     * @param int $retryDelay 重试延迟（微秒）
     * @return string|false
     */
    public static function safeReadFile(string $filePath, int $maxRetries = 3, int $retryDelay = 100000)
    {
        $filePath = self::normalizePath($filePath);
        
        if (!file_exists($filePath)) {
            w_log_warning(__("文件不存在: %{1}", [$filePath]));
            return false;
        }

        if (!self::isReadable($filePath)) {
            w_log_warning(__("文件不可读: %{1}", [$filePath]));
            return false;
        }

        // 重试机制处理文件锁定问题
        for ($i = 0; $i < $maxRetries; $i++) {
            try {
                $content = file_get_contents($filePath);
                if ($content !== false) {
                    return $content;
                }
                
                // 如果是权限错误且还有重试机会，等待后重试
                if ($i < $maxRetries - 1) {
                    $error = error_get_last();
                    if ($error && (strpos($error['message'], 'Permission denied') !== false || 
                                   strpos($error['message'], 'errno=13') !== false)) {
                        w_log_warning(__("文件读取权限错误，重试第 %{1} 次: %{2}", [($i + 1), $filePath]));
                        SchedulerSystem::usleep($retryDelay);
                        continue;
                    }
                }
                
                w_log_error(__("文件读取失败: %{1}", [$filePath]));
                return false;
                
            } catch (Exception $e) {
                if ($i < $maxRetries - 1) {
                    w_log_warning(__("文件读取异常，重试第 %{1} 次: %{2} - %{3}", [($i + 1), $filePath, $e->getMessage()]));
                    SchedulerSystem::usleep($retryDelay);
                    continue;
                }
                w_log_error(__("文件读取最终失败: %{1} - %{2}", [$filePath, $e->getMessage()]));
                return false;
            }
        }
        
        return false;
    }

    /**
     * 递归获取目录下的所有文件
     * 
     * @param string $directory
     * @param array $extensions 文件扩展名过滤（可选）
     * @return array
     */
    public static function getFilesRecursively(string $directory, array $extensions = []): array
    {
        $directory = self::normalizePath($directory);
        $files = [];

        if (!is_dir($directory)) {
            return $files;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $filePath = self::normalizePath($file->getPathname());
                
                if (empty($extensions) || in_array(strtolower($file->getExtension()), $extensions)) {
                    $files[] = $filePath;
                }
            }
        }

        return $files;
    }
}