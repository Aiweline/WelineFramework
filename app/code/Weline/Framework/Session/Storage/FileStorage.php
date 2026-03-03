<?php

declare(strict_types=1);

namespace Weline\Framework\Session\Storage;

use Weline\Framework\App\Env;

/**
 * 文件存储实现
 *
 * 将 Session 数据存储到文件系统，适用于单机部署或 FPM 模式。
 * 每个 Session ID 对应一个文件，文件内容为序列化的 Session 数据。
 */
final class FileStorage implements SessionStorageInterface
{
    /** Session 文件存储路径 */
    private string $path;

    /** 配置 */
    private array $config;

    /** 默认 TTL */
    private int $defaultTtl;

    /**
     * 构造函数
     *
     * @param array $config 配置项
     */
    public function __construct(array $config = [])
    {
        $this->config = $config;
        $this->defaultTtl = (int)($config['lifetime'] ?? $config['session_ttl'] ?? 3600);
        
        $configPath = $config['path'] ?? 'var/session/';
        $this->path = BP . \str_replace('/', DS, $configPath);
        
        if (!\is_dir($this->path)) {
            @\mkdir($this->path, 0700, true);
        }
    }

    /**
     * 获取 Session 文件路径
     */
    private function getFilePath(string $sessionId): string
    {
        return $this->path . $sessionId;
    }

    /**
     * @inheritDoc
     */
    public function read(string $sessionId): array
    {
        $filePath = $this->getFilePath($sessionId);
        
        if (!\file_exists($filePath)) {
            return [];
        }

        $mtime = \filemtime($filePath);
        if ($mtime !== false && (\time() - $mtime) > $this->defaultTtl) {
            @\unlink($filePath);
            return [];
        }

        $content = @\file_get_contents($filePath);
        if ($content === false || $content === '') {
            return [];
        }

        $data = @\unserialize($content);
        if (!\is_array($data)) {
            return [];
        }

        return $data;
    }

    /**
     * @inheritDoc
     */
    public function write(string $sessionId, array $data, int $ttl): bool
    {
        $filePath = $this->getFilePath($sessionId);
        $content = \serialize($data);
        
        $result = @\file_put_contents($filePath, $content, LOCK_EX);
        
        return $result !== false;
    }

    /**
     * @inheritDoc
     */
    public function destroy(string $sessionId): bool
    {
        $filePath = $this->getFilePath($sessionId);
        
        if (\file_exists($filePath)) {
            return @\unlink($filePath);
        }
        
        return true;
    }

    /**
     * @inheritDoc
     */
    public function exists(string $sessionId): bool
    {
        $filePath = $this->getFilePath($sessionId);
        
        if (!\file_exists($filePath)) {
            return false;
        }

        $mtime = \filemtime($filePath);
        if ($mtime !== false && (\time() - $mtime) > $this->defaultTtl) {
            @\unlink($filePath);
            return false;
        }

        return true;
    }

    /**
     * @inheritDoc
     */
    public function touch(string $sessionId, int $ttl): bool
    {
        $filePath = $this->getFilePath($sessionId);
        
        if (!\file_exists($filePath)) {
            return false;
        }

        return @\touch($filePath);
    }

    /**
     * @inheritDoc
     */
    public function gc(int $maxLifetime): int
    {
        $count = 0;
        $now = \time();
        
        $files = @\glob($this->path . '*');
        if ($files === false) {
            return 0;
        }
        
        foreach ($files as $file) {
            if (\is_file($file)) {
                $mtime = \filemtime($file);
                if ($mtime !== false && ($now - $mtime) > $maxLifetime) {
                    if (@\unlink($file)) {
                        $count++;
                    }
                }
            }
        }
        
        return $count;
    }

    /**
     * @inheritDoc
     */
    public function getConfig(): array
    {
        return $this->config;
    }

    /**
     * 获取存储路径
     *
     * @return string 路径
     */
    public function getPath(): string
    {
        return $this->path;
    }

    /**
     * @inheritDoc
     */
    public function list(array $options = []): array
    {
        $filter = $options['filter'] ?? [];
        $limit = (int)($options['limit'] ?? 50);
        
        if (!\is_dir($this->path)) {
            return [];
        }
        
        $sessionFiles = [];
        $iterator = new \DirectoryIterator($this->path);
        
        foreach ($iterator as $fileInfo) {
            if ($fileInfo->isDot() || !$fileInfo->isFile()) {
                continue;
            }
            
            $sessionFiles[] = [
                'path' => $fileInfo->getPathname(),
                'session_id' => $fileInfo->getFilename(),
                'mtime' => $fileInfo->getMTime(),
            ];
        }
        
        \usort($sessionFiles, static fn(array $a, array $b): int => $b['mtime'] <=> $a['mtime']);
        
        $result = [];
        $count = 0;
        $now = \time();
        
        foreach ($sessionFiles as $sessionFile) {
            if ($count >= $limit) {
                break;
            }
            
            if (($now - $sessionFile['mtime']) > $this->defaultTtl) {
                continue;
            }
            
            $content = @\file_get_contents($sessionFile['path']);
            if ($content === false || $content === '') {
                continue;
            }
            
            $data = @\unserialize($content);
            if (!\is_array($data)) {
                continue;
            }
            
            if (!empty($filter)) {
                $match = true;
                foreach ($filter as $key => $value) {
                    if (($data[$key] ?? null) !== $value) {
                        $match = false;
                        break;
                    }
                }
                if (!$match) {
                    continue;
                }
            }
            
            $result[] = [
                'session_id' => $sessionFile['session_id'],
                'data' => $data,
            ];
            $count++;
        }
        
        return $result;
    }
}
