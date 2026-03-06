<?php

declare(strict_types=1);

/**
 * 文件缓存适配器
 * 
 * 使用文件系统存储缓存数据。
 * 
 * @author Aiweline
 * @email aiweline@qq.com
 */

namespace Weline\Framework\Cache\Adapter;

use Weline\Framework\Cache\Contract\CacheAdapterInterface;

class FileAdapter implements CacheAdapterInterface
{
    private string $cachePath;
    private string $identity;

    public function __construct(string $identity, array $config = [])
    {
        $this->identity = $identity;
        $basePath = $config['path'] ?? BP . 'var' . DS . 'cache' . DS;
        
        if (IS_WIN) {
            $basePath = str_replace('/', DS, $basePath);
        } else {
            $basePath = str_replace('\\', DS, $basePath);
        }
        
        if (!str_starts_with($basePath, BP)) {
            $basePath = BP . $basePath;
        }
        
        $this->cachePath = rtrim($basePath, DS) . DS . $identity . DS;

        if (!is_dir($this->cachePath)) {
            mkdir($this->cachePath, 0775, true);
        }
    }

    public function get(string $key): mixed
    {
        $file = $this->getFilePath($key);
        
        if (!file_exists($file)) {
            return null;
        }

        $content = @file_get_contents($file);
        if ($content === false) {
            return null;
        }

        $data = @unserialize($content);
        if ($data === false || !is_array($data)) {
            return null;
        }

        if (isset($data['expires']) && $data['expires'] > 0 && $data['expires'] < time()) {
            $this->delete($key);
            return null;
        }

        return $data['value'] ?? null;
    }

    public function set(string $key, mixed $value, int $ttl = 0): bool
    {
        $file = $this->getFilePath($key);
        $dir = dirname($file);
        
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        
        $data = [
            'value' => $value,
            'expires' => $ttl > 0 ? time() + $ttl : 0,
            'created' => time(),
        ];

        return file_put_contents($file, serialize($data), LOCK_EX) !== false;
    }

    public function delete(string $key): bool
    {
        $file = $this->getFilePath($key);
        
        if (file_exists($file)) {
            return @unlink($file);
        }
        
        return true;
    }

    public function clear(): bool
    {
        return $this->deleteDirectory($this->cachePath, false);
    }

    public function has(string $key): bool
    {
        return $this->get($key) !== null;
    }

    /**
     * 获取缓存文件路径
     */
    private function getFilePath(string $key): string
    {
        $hash = $this->hashKey($key);
        $subDir = substr($hash, 0, 2);
        return $this->cachePath . $subDir . DS . $hash;
    }

    /**
     * 哈希键名
     */
    private function hashKey(string $key): string
    {
        if (function_exists('hash') && in_array('xxh3', hash_algos(), true)) {
            return hash('xxh3', $key);
        }
        return sprintf('%08x%08x', crc32($key), crc32(strrev($key)));
    }

    /**
     * 删除目录
     */
    private function deleteDirectory(string $dir, bool $removeSelf = true): bool
    {
        if (!is_dir($dir)) {
            return true;
        }

        $entries = @scandir($dir);
        $files = is_array($entries) ? array_diff($entries, ['.', '..']) : [];
        
        foreach ($files as $file) {
            $path = $dir . DS . $file;
            if (is_dir($path)) {
                $this->deleteDirectory($path, true);
            } else {
                @unlink($path);
            }
        }

        if ($removeSelf) {
            return @rmdir($dir);
        }
        
        return true;
    }

    /**
     * 获取缓存路径
     */
    public function getCachePath(): string
    {
        return $this->cachePath;
    }

    /**
     * 获取标识
     */
    public function getIdentity(): string
    {
        return $this->identity;
    }
}
