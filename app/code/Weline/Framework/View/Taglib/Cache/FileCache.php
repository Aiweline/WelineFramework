<?php
declare(strict_types=1);

/**
 * Weline Framework
 * 
 * @DESC         | L3 文件缓存
 * @Author       | Weline Framework
 * @Package      | Weline\Framework\View\Taglib\Cache
 */

namespace Weline\Framework\View\Taglib\Cache;

/**
 * L3 文件缓存
 * 
 * 持久化编译结果到文件系统，OPcache 可缓存
 * 访问速度：约 0.5ms（热读取）
 */
final class FileCache implements CacheInterface
{
    /**
     * 缓存目录
     */
    private readonly string $cacheDir;

    /**
     * 文件扩展名
     */
    private const EXT = '.php';

    public function __construct(?string $cacheDir = null)
    {
        $this->cacheDir = $cacheDir ?? $this->getDefaultCacheDir();
    }

    /**
     * @inheritDoc
     */
    public function get(string $path, string $hash): ?string
    {
        $filePath = $this->getCachePath($path, $hash);
        
        if (!file_exists($filePath)) {
            return null;
        }

        // 直接读取文件内容
        $content = file_get_contents($filePath);
        if ($content === false) {
            return null;
        }

        return $content;
    }

    /**
     * @inheritDoc
     */
    public function set(string $path, string $hash, string $compiled): bool
    {
        $filePath = $this->getCachePath($path, $hash);
        $dir = dirname($filePath);

        // 确保目录存在
        if (!is_dir($dir)) {
            if (!mkdir($dir, 0755, true) && !is_dir($dir)) {
                return false;
            }
        }

        // 删除旧版本缓存
        $this->deleteOldVersions($path, $hash);

        // 原子写入
        $tmpFile = $filePath . '.tmp.' . getmypid();
        if (file_put_contents($tmpFile, $compiled, LOCK_EX) === false) {
            return false;
        }

        // Windows 需要先删除目标文件
        if (file_exists($filePath)) {
            @unlink($filePath);
        }

        return rename($tmpFile, $filePath);
    }

    /**
     * @inheritDoc
     */
    public function delete(string $path): bool
    {
        $pattern = $this->getCacheDir($path) . DIRECTORY_SEPARATOR . '*' . self::EXT;
        $files = glob($pattern);
        
        if ($files === false) {
            return false;
        }

        $success = true;
        foreach ($files as $file) {
            if (!unlink($file)) {
                $success = false;
            }
        }

        return $success;
    }

    /**
     * @inheritDoc
     */
    public function flush(): bool
    {
        return $this->deleteDirectory($this->cacheDir);
    }

    /**
     * 获取缓存文件路径
     *
     * @return string 编译后的 PHP 文件路径
     */
    public function getCachePath(string $path, string $hash): string
    {
        $dir = $this->getCacheDir($path);
        return $dir . DIRECTORY_SEPARATOR . $hash . self::EXT;
    }

    /**
     * 获取模板对应的缓存目录
     */
    private function getCacheDir(string $path): string
    {
        // 使用路径哈希作为子目录，避免单目录文件过多
        $pathHash = hash('xxh3', $path);
        return $this->cacheDir . DIRECTORY_SEPARATOR . substr($pathHash, 0, 2);
    }

    /**
     * 删除旧版本缓存
     */
    private function deleteOldVersions(string $path, string $currentHash): void
    {
        $pattern = $this->getCacheDir($path) . DIRECTORY_SEPARATOR . '*' . self::EXT;
        $files = glob($pattern);
        
        if ($files === false) {
            return;
        }

        $currentFile = $this->getCachePath($path, $currentHash);
        foreach ($files as $file) {
            if ($file !== $currentFile) {
                @unlink($file);
            }
        }
    }

    /**
     * 获取默认缓存目录
     */
    private function getDefaultCacheDir(): string
    {
        // 使用框架 var 目录，如果 BP 未定义则使用系统临时目录
        $basePath = defined('BP') ? BP : sys_get_temp_dir();
        $varDir = $basePath . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'cache';
        return $varDir . DIRECTORY_SEPARATOR . 'taglib';
    }

    /**
     * 递归删除目录
     */
    private function deleteDirectory(string $dir): bool
    {
        if (!is_dir($dir)) {
            return true;
        }

        $items = scandir($dir);
        if ($items === false) {
            return false;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $dir . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path)) {
                $this->deleteDirectory($path);
            } else {
                @unlink($path);
            }
        }

        return @rmdir($dir);
    }

    /**
     * 获取统计信息
     */
    public function stats(): array
    {
        $count = 0;
        $size = 0;

        if (is_dir($this->cacheDir)) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->cacheDir, \RecursiveDirectoryIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $count++;
                    $size += $file->getSize();
                }
            }
        }

        return [
            'cacheDir' => $this->cacheDir,
            'count' => $count,
            'size' => $size,
        ];
    }
}
