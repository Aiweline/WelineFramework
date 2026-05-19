<?php

declare(strict_types=1);

namespace Weline\Framework\View;

use Weline\Framework\App\Env;

/**
 * Template Cache Manager
 *
 * Provides high-performance template caching with:
 * - Content-based cache keys (MD5 hash + file size)
 * - Dual-cache strategy (meta + content separation)
 * - Process-level locking via flock()
 * - Multi-level caching (memory → disk)
 * - Batch manifest for O(1) lookups
 *
 * @priority 1. This class is foundational - other phases depend on it
 */
class TemplateCacheManager
{
    /** @var self|null Singleton instance */
    private static ?self $instance = null;

    /** @var array L1: Process-local memory cache */
    private static array $memoryCache = [];

    /** @var array<string, array{mtime:int, size:int, key:string}> */
    private static array $sourceFileKeyCache = [];

    /** @var string Cache root directory */
    private string $cacheRoot;

    /** @var string Manifest file path */
    private string $manifestFile;

    /** @var array|null Loaded manifest data */
    private ?array $manifest = null;

    /** @var int|null Manifest file mtime */
    private ?int $manifestMtime = null;

    /** @var bool Whether manifest has been loaded */
    private bool $manifestLoaded = false;

    private function __construct()
    {
        $this->cacheRoot = BP . 'var' . DS . 'cache' . DS . 'template';
        $this->manifestFile = $this->cacheRoot . DS . '.manifest';
        $this->ensureCacheDir();
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Get cache key for a source file
     *
     * Uses content hash + size for cross-platform reliability
     * (mtime alone is unreliable across different filesystems)
     */
    public function getCacheKey(string $sourceFile): string
    {
        if (!is_file($sourceFile)) {
            throw new \RuntimeException(__('源模板文件不存在：%{1}', $sourceFile));
        }
        if (!$this->shouldUseSourceKeyCache()) {
            return $this->buildCacheKeyFromFile($sourceFile);
        }

        $sourceSize = (int) filesize($sourceFile);
        $sourceMtime = (int) (@filemtime($sourceFile) ?: 0);
        $normalizedSource = $this->normalizePath($sourceFile);
        $cached = self::$sourceFileKeyCache[$normalizedSource] ?? null;
        if (
            is_array($cached)
            && ($cached['mtime'] ?? -1) === $sourceMtime
            && ($cached['size'] ?? -1) === $sourceSize
            && isset($cached['key'])
        ) {
            return $cached['key'];
        }

        $cacheKey = $this->buildCacheKeyFromFile($sourceFile);
        self::$sourceFileKeyCache[$normalizedSource] = [
            'mtime' => $sourceMtime,
            'size' => $sourceSize,
            'key' => $cacheKey,
        ];

        return $cacheKey;
    }

    /**
     * Get cached file path if cache is valid
     *
     * @param string $sourceFile Source template file
     * @param bool $isDev Whether in development mode
     * @return string|null Cached file path if valid, null if needs recompile
     */
    public function getCachedFile(string $sourceFile, bool $isDev = false): ?string
    {
        $cacheKey = $this->getCacheKey($sourceFile);
        $sourceHash = substr($cacheKey, 0, 32);

        // L1: Check memory cache first (fastest)
        if (isset(self::$memoryCache[$cacheKey])) {
            $cached = self::$memoryCache[$cacheKey];
            if ($this->isCacheValid($cached, $sourceFile, $isDev)) {
                return $this->cacheRoot . DS . substr($cacheKey, 0, 32) . '.phtml';
            }
        }

        // Load manifest for O(1) lookup
        $this->loadManifest();

        // Check manifest for this source file
        $normalizedSource = $this->normalizePath($sourceFile);
        if (!isset($this->manifest[$normalizedSource])) {
            return null;
        }

        $entry = $this->manifest[$normalizedSource];
        $metaFile = $this->getMetaFilePath($sourceHash);
        $compiledFile = $this->cacheRoot . DS . $sourceHash . '.phtml';

        // Verify meta file exists and is valid
        if (!is_file($metaFile) || !is_file($compiledFile)) {
            return null;
        }

        $meta = unserialize(file_get_contents($metaFile));
        if (!$this->isCacheValid($meta, $sourceFile, $isDev)) {
            return null;
        }

        // Update L1 cache
        self::$memoryCache[$cacheKey] = $meta;

        return $compiledFile;
    }

    /**
     * Check if cached entry is valid
     */
    private function isCacheValid(array $meta, string $sourceFile, bool $isDev): bool
    {
        // Check content hash matches current source
        if ($meta['content_hash'] !== $this->getCacheKey($sourceFile)) {
            return false;
        }

        // In production, content hash match is sufficient
        if (!$isDev) {
            return true;
        }

        // In dev mode, also check mtime for rapid iteration
        // Only recompile if source was modified AFTER cache creation
        // (not just if cache is older than 3s - that approach misses changes)
        if (isset($meta['source_mtime']) && isset($meta['compiled_at'])) {
            $sourceMtime = filemtime($sourceFile);
            // Valid if source hasn't been modified since compilation
            return $sourceMtime <= $meta['compiled_at'];
        }

        return false;
    }

    /**
     * Write compiled template to cache
     *
     * @param string $sourceFile Original source file
     * @param string $compiledContent Compiled content
     * @return string Path to cached file
     */
    public function writeCache(string $sourceFile, string $compiledContent): string
    {
        // Cache directory may be removed by cache clear during long-running process.
        $this->ensureCacheDir();

        $cacheKey = $this->getCacheKey($sourceFile);
        $sourceHash = substr($cacheKey, 0, 32);
        $sourceMtime = filemtime($sourceFile);

        $metaFile = $this->getMetaFilePath($sourceHash);
        $compiledFile = $this->cacheRoot . DS . $sourceHash . '.phtml';
        $lockFile = $this->cacheRoot . DS . $sourceHash . '.lock';

        // Acquire process lock to prevent concurrent compilation
        $lock = fopen($lockFile, 'c+');
        if (!$lock) {
            throw new \RuntimeException(__('无法创建缓存锁文件：%{1}', $lockFile));
        }

        // Non-blocking exclusive lock (LOCK_NB prevents waiting)
        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            // Another process is compiling, wait briefly then check result
            flock($lock, LOCK_UN);
            fclose($lock);

            // Wait 100ms and check if cache was created
            \Weline\Framework\Runtime\SchedulerSystem::yieldDelay(100);
            if (is_file($compiledFile)) {
                return $compiledFile;
            }

            // If still not available, use stale cache or source
            return $sourceFile;
        }

        try {
            // Double-check: another process might have compiled while we waited
            if (is_file($compiledFile) && is_file($metaFile)) {
                $existingMeta = unserialize(file_get_contents($metaFile));
                if ($existingMeta['content_hash'] === $cacheKey) {
                    return $compiledFile;
                }
            }

            // Ensure cache directory exists (including subdirectory for meta files)
            $this->ensureCacheDir(substr($sourceHash, 0, 2));

            // Write compiled content
            if (file_put_contents($compiledFile, $compiledContent) === false) {
                throw new \RuntimeException(__('写入编译缓存失败：%{1}', $compiledFile));
            }

            // Write meta file
            $meta = [
                'content_hash' => $cacheKey,
                'source_size' => filesize($sourceFile),
                'source_mtime' => $sourceMtime,
                'source_path' => $this->normalizePath($sourceFile),
                'compiled_at' => time(),
                'compiled_hash' => md5($compiledContent),
            ];

            if (file_put_contents($metaFile, serialize($meta)) === false) {
                throw new \RuntimeException(__('写入元数据缓存失败：%{1}', $metaFile));
            }

            // Update manifest
            $this->updateManifest($sourceFile, $compiledFile, $meta);

            // Update L1 cache
            self::$memoryCache[$cacheKey] = $meta;

            return $compiledFile;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);

            // Clean up lock file
            @unlink($lockFile);
        }
    }

    /**
     * Get meta file path for a cache key
     */
    private function getMetaFilePath(string $hash): string
    {
        // Use subdirectory to avoid too many files in one directory
        $subDir = substr($hash, 0, 2);
        return $this->cacheRoot . DS . $subDir . DS . $hash . '.meta';
    }

    /**
     * Ensure cache directory exists
     *
     * @param string|null $subDir Optional subdirectory to create (first 2 chars of hash)
     */
    private function ensureCacheDir(?string $subDir = null): void
    {
        if (!is_dir($this->cacheRoot)) {
            mkdir($this->cacheRoot, 0775, true);
        }
        if ($subDir !== null) {
            $fullPath = $this->cacheRoot . DS . $subDir;
            if (!is_dir($fullPath)) {
                mkdir($fullPath, 0775, true);
            }
        }
    }

    /**
     * Load manifest into memory
     */
    private function loadManifest(): void
    {
        if ($this->manifestLoaded) {
            // Check if manifest file was modified
            if (is_file($this->manifestFile)) {
                $currentMtime = filemtime($this->manifestFile);
                if ($currentMtime !== $this->manifestMtime) {
                    $this->manifest = null; // Force reload
                }
            }
        }

        if ($this->manifest !== null) {
            return;
        }

        $this->manifest = [];
        $this->manifestMtime = 0;

        if (is_file($this->manifestFile)) {
            $content = file_get_contents($this->manifestFile);
            if ($content !== false && $content !== '') {
                $this->manifest = unserialize($content);
                $this->manifestMtime = filemtime($this->manifestFile);
            }
        }

        $this->manifestLoaded = true;
    }

    /**
     * Update manifest with new cache entry
     */
    private function updateManifest(string $sourceFile, string $compiledFile, array $meta): void
    {
        $normalizedSource = $this->normalizePath($sourceFile);

        $this->manifest[$normalizedSource] = [
            'hash' => substr($meta['content_hash'], 0, 32),
            'compiled' => $this->normalizePath($compiledFile),
            'compiled_mtime' => $meta['compiled_at'],
            'source_size' => $meta['source_size'],
        ];

        // Write manifest atomically (write to temp, then rename)
        $tempFile = $this->manifestFile . '.tmp';
        $content = serialize($this->manifest);

        file_put_contents($tempFile, $content);
        rename($tempFile, $this->manifestFile);

        $this->manifestMtime = time();
    }

    /**
     * Check if source file needs recompilation
     *
     * @param string $sourceFile Source template file
     * @param bool $isDev Whether in development mode
     * @return bool True if needs recompile
     */
    public function needsRecompile(string $sourceFile, bool $isDev = false): bool
    {
        $cached = $this->getCachedFile($sourceFile, $isDev);
        return $cached === null;
    }

    /**
     * Get compilation lock status for a source file
     *
     * @param string $sourceFile Source template file
     * @return bool True if currently being compiled by another process
     */
    public function isBeingCompiled(string $sourceFile): bool
    {
        // Ensure root exists before probing lock file.
        $this->ensureCacheDir();

        $cacheKey = $this->getCacheKey($sourceFile);
        $sourceHash = substr($cacheKey, 0, 32);
        $lockFile = $this->cacheRoot . DS . $sourceHash . '.lock';

        if (!is_file($lockFile)) {
            return false;
        }

        $lock = fopen($lockFile, 'r');
        if (!$lock) {
            return false;
        }

        // Try non-blocking lock - if fails, someone else has it
        $hasLock = flock($lock, LOCK_EX | LOCK_NB);
        if ($hasLock) {
            flock($lock, LOCK_UN);
        }
        fclose($lock);

        return !$hasLock;
    }

    /**
     * Clear all caches (memory + disk)
     */
    public function clearAll(): void
    {
        // Clear L1 memory cache
        self::$memoryCache = [];
        self::$sourceFileKeyCache = [];

        // Clear disk cache
        $this->ensureCacheDir();
        $files = glob($this->cacheRoot . DS . '*');
        foreach ($files as $file) {
            if (is_file($file)) {
                @unlink($file);
            } elseif (is_dir($file) && basename($file) !== '.' && basename($file) !== '..') {
                // Recursively clean subdirectories
                $this->removeDirectory($file);
            }
        }

        // Reset manifest
        $this->manifest = [];
        $this->manifestMtime = 0;
        $this->manifestLoaded = false;
    }

    /**
     * 仅清理进程内 L1 缓存，保留磁盘缓存与 manifest。
     */
    public function clearMemoryCache(): void
    {
        self::$memoryCache = [];
        self::$sourceFileKeyCache = [];
    }

    /**
     * Clear cache for a specific source file
     */
    public function clearCache(string $sourceFile): void
    {
        if (!is_file($sourceFile)) {
            return;
        }

        $cacheKey = $this->getCacheKey($sourceFile);
        $sourceHash = substr($cacheKey, 0, 32);
        $normalizedSource = $this->normalizePath($sourceFile);

        // Remove from L1
        unset(self::$memoryCache[$cacheKey]);
        unset(self::$sourceFileKeyCache[$normalizedSource]);

        // Remove from disk
        $metaFile = $this->getMetaFilePath($sourceHash);
        $compiledFile = $this->cacheRoot . DS . $sourceHash . '.phtml';

        @unlink($metaFile);
        @unlink($compiledFile);

        // Update manifest
        $this->loadManifest();
        if (isset($this->manifest[$normalizedSource])) {
            unset($this->manifest[$normalizedSource]);
            file_put_contents($this->manifestFile, serialize($this->manifest));
        }
    }

    /**
     * Invalidate caches whose source files no longer exist
     */
    public function invalidateStaleEntries(): int
    {
        $this->loadManifest();
        $staleCount = 0;

        foreach ($this->manifest as $sourcePath => $entry) {
            if (!is_file($sourcePath)) {
                $this->clearCache($sourcePath);
                $staleCount++;
            }
        }

        return $staleCount;
    }

    /**
     * Get cache statistics
     */
    public function getStats(): array
    {
        $this->ensureCacheDir();
        $this->loadManifest();

        $totalSize = 0;
        $fileCount = 0;

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->cacheRoot, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $totalSize += $file->getSize();
                $fileCount++;
            }
        }

        return [
            'memory_cache_entries' => count(self::$memoryCache),
            'source_key_cache_entries' => count(self::$sourceFileKeyCache),
            'manifest_entries' => count($this->manifest),
            'disk_files' => $fileCount,
            'disk_size_bytes' => $totalSize,
            'disk_size_human' => $this->formatBytes($totalSize),
        ];
    }

    /**
     * Normalize path for consistent manifest keys
     */
    private function normalizePath(string $path): string
    {
        return str_replace(['/', '\\'], DS, rtrim($path, '/\\'));
    }

    private function shouldUseSourceKeyCache(): bool
    {
        if (\defined('DEV') && DEV) {
            return false;
        }

        return \Weline\Framework\Runtime\Runtime::isPersistent();
    }

    private function buildCacheKeyFromFile(string $sourceFile): string
    {
        $hash = md5_file($sourceFile);
        if ($hash === false) {
            throw new \RuntimeException(__('Failed to hash template source file: %{1}', $sourceFile));
        }

        return $hash . '-' . filesize($sourceFile);
    }

    /**
     * Remove directory recursively
     */
    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = array_diff(scandir($dir), ['.', '..']);
        foreach ($items as $item) {
            $path = $dir . DS . $item;
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                @unlink($path);
            }
        }
        rmdir($dir);
    }

    /**
     * Format bytes to human readable
     */
    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $factor = floor((strlen((string)$bytes) - 1) / 3);
        return sprintf('%.2f %s', $bytes / pow(1024, $factor), $units[$factor] ?? 'B');
    }
}
