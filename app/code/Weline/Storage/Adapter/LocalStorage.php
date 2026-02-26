<?php

declare(strict_types=1);

namespace Weline\Storage\Adapter;

use Weline\Storage\Api\StorageInterface;

/**
 * @DESC | 本地文件系统存储适配器
 */
class LocalStorage implements StorageInterface
{
    private string $rootPath;
    private string $baseUrl;
    
    public function __construct(array $config = [])
    {
        $this->rootPath = \rtrim($config['root_path'] ?? (PUB . 'media'), '/\\') . DS;
        $this->baseUrl = \rtrim($config['base_url'] ?? '/pub/media', '/') . '/';
        
        if (!\is_dir($this->rootPath)) {
            @\mkdir($this->rootPath, 0755, true);
        }
    }
    
    public function getDriver(): string
    {
        return 'local';
    }
    
    public function put(string $path, $contents, array $options = []): bool
    {
        $fullPath = $this->getFullPath($path);
        $dir = \dirname($fullPath);
        
        if (!\is_dir($dir)) {
            if (!@\mkdir($dir, 0755, true)) {
                return false;
            }
        }
        
        if (\is_resource($contents)) {
            return $this->putStream($path, $contents, $options);
        }
        
        return @\file_put_contents($fullPath, $contents) !== false;
    }
    
    public function putStream(string $path, $resource, array $options = []): bool
    {
        $fullPath = $this->getFullPath($path);
        $dir = \dirname($fullPath);
        
        if (!\is_dir($dir)) {
            if (!@\mkdir($dir, 0755, true)) {
                return false;
            }
        }
        
        $fp = @\fopen($fullPath, 'wb');
        if ($fp === false) {
            return false;
        }
        
        while (!\feof($resource)) {
            $chunk = \fread($resource, 64 * 1024);
            if ($chunk === false) {
                break;
            }
            \fwrite($fp, $chunk);
        }
        
        \fclose($fp);
        return true;
    }
    
    public function get(string $path): ?string
    {
        $fullPath = $this->getFullPath($path);
        
        if (!\is_file($fullPath)) {
            return null;
        }
        
        $contents = @\file_get_contents($fullPath);
        return $contents !== false ? $contents : null;
    }
    
    public function getStream(string $path)
    {
        $fullPath = $this->getFullPath($path);
        
        if (!\is_file($fullPath)) {
            return null;
        }
        
        $fp = @\fopen($fullPath, 'rb');
        return $fp !== false ? $fp : null;
    }
    
    public function delete(string $path): bool
    {
        $fullPath = $this->getFullPath($path);
        
        if (!\is_file($fullPath)) {
            return true;
        }
        
        return @\unlink($fullPath);
    }
    
    public function deleteMultiple(array $paths): bool
    {
        $success = true;
        foreach ($paths as $path) {
            if (!$this->delete($path)) {
                $success = false;
            }
        }
        return $success;
    }
    
    public function exists(string $path): bool
    {
        return \is_file($this->getFullPath($path));
    }
    
    public function url(string $path): ?string
    {
        $path = \str_replace('\\', '/', $path);
        return $this->baseUrl . \ltrim($path, '/');
    }
    
    public function temporaryUrl(string $path, int $expiration = 3600): ?string
    {
        return $this->url($path);
    }
    
    public function size(string $path): ?int
    {
        $fullPath = $this->getFullPath($path);
        
        if (!\is_file($fullPath)) {
            return null;
        }
        
        $size = @\filesize($fullPath);
        return $size !== false ? $size : null;
    }
    
    public function lastModified(string $path): ?int
    {
        $fullPath = $this->getFullPath($path);
        
        if (!\is_file($fullPath)) {
            return null;
        }
        
        $mtime = @\filemtime($fullPath);
        return $mtime !== false ? $mtime : null;
    }
    
    public function mimeType(string $path): ?string
    {
        $fullPath = $this->getFullPath($path);
        
        if (!\is_file($fullPath)) {
            return null;
        }
        
        if (\function_exists('mime_content_type')) {
            $mime = @\mime_content_type($fullPath);
            if ($mime !== false) {
                return $mime;
            }
        }
        
        return 'application/octet-stream';
    }
    
    public function copy(string $from, string $to): bool
    {
        $fromPath = $this->getFullPath($from);
        $toPath = $this->getFullPath($to);
        
        if (!\is_file($fromPath)) {
            return false;
        }
        
        $dir = \dirname($toPath);
        if (!\is_dir($dir)) {
            if (!@\mkdir($dir, 0755, true)) {
                return false;
            }
        }
        
        return @\copy($fromPath, $toPath);
    }
    
    public function move(string $from, string $to): bool
    {
        $fromPath = $this->getFullPath($from);
        $toPath = $this->getFullPath($to);
        
        if (!\is_file($fromPath)) {
            return false;
        }
        
        $dir = \dirname($toPath);
        if (!\is_dir($dir)) {
            if (!@\mkdir($dir, 0755, true)) {
                return false;
            }
        }
        
        return @\rename($fromPath, $toPath);
    }
    
    public function list(string $directory = '', bool $recursive = false): array
    {
        $fullPath = $this->getFullPath($directory);
        
        if (!\is_dir($fullPath)) {
            return [];
        }
        
        $results = [];
        
        if ($recursive) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($fullPath, \RecursiveDirectoryIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::SELF_FIRST
            );
        } else {
            $iterator = new \DirectoryIterator($fullPath);
        }
        
        foreach ($iterator as $file) {
            if ($file->isDot()) {
                continue;
            }
            
            $relativePath = $this->getRelativePath($file->getPathname());
            
            $results[] = [
                'path' => $relativePath,
                'name' => $file->getFilename(),
                'type' => $file->isDir() ? 'directory' : 'file',
                'size' => $file->isFile() ? $file->getSize() : 0,
                'last_modified' => $file->getMTime(),
            ];
        }
        
        return $results;
    }
    
    public function makeDirectory(string $path): bool
    {
        $fullPath = $this->getFullPath($path);
        
        if (\is_dir($fullPath)) {
            return true;
        }
        
        return @\mkdir($fullPath, 0755, true);
    }
    
    public function deleteDirectory(string $path): bool
    {
        $fullPath = $this->getFullPath($path);
        
        if (!\is_dir($fullPath)) {
            return true;
        }
        
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($fullPath, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        
        foreach ($iterator as $file) {
            if ($file->isDir()) {
                @\rmdir($file->getPathname());
            } else {
                @\unlink($file->getPathname());
            }
        }
        
        return @\rmdir($fullPath);
    }
    
    public function testConnection(): bool
    {
        return \is_dir($this->rootPath) && \is_writable($this->rootPath);
    }
    
    public function getInfo(): array
    {
        return [
            'driver' => 'local',
            'root_path' => $this->rootPath,
            'base_url' => $this->baseUrl,
            'writable' => \is_writable($this->rootPath),
        ];
    }
    
    private function getFullPath(string $path): string
    {
        $path = \str_replace(['/', '\\'], DS, $path);
        $path = \trim($path, DS);
        return $this->rootPath . $path;
    }
    
    private function getRelativePath(string $fullPath): string
    {
        $fullPath = \str_replace('\\', '/', $fullPath);
        $rootPath = \str_replace('\\', '/', $this->rootPath);
        
        if (\str_starts_with($fullPath, $rootPath)) {
            return \substr($fullPath, \strlen($rootPath));
        }
        
        return $fullPath;
    }
}
