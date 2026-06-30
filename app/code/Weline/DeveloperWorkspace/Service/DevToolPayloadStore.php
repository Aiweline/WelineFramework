<?php

declare(strict_types=1);

namespace Weline\DeveloperWorkspace\Service;

use Weline\CacheManager\Service\RuntimeCachePolicy;
use Weline\Framework\App\Env;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\Runtime;
use Weline\Server\Service\MemoryStateFacade;

class DevToolPayloadStore
{
    private const NAMESPACE = 'dev_tool_payload';
    private const DEFAULT_TTL = 60;

    private ?MemoryStateFacade $memory = null;
    private bool $memoryResolved = false;

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(private readonly array $config = [])
    {
    }

    public function remember(string $type, string $key, int $ttl, callable $producer): mixed
    {
        $cached = $this->get($type, $key);
        if ($cached !== null) {
            return $cached;
        }

        $value = $producer();
        $this->set($type, $key, $value, $ttl);

        return $value;
    }

    public function get(string $type, string $key): mixed
    {
        $storeKey = $this->storeKey($type, $key);
        $memory = $this->memory();
        if ($memory !== null) {
            try {
                return $memory->get(self::NAMESPACE, $storeKey);
            } catch (\Throwable) {
                $this->memory = null;
            }
        }

        return $this->getFromFile($type, $key);
    }

    public function set(string $type, string $key, mixed $value, int $ttl = self::DEFAULT_TTL): bool
    {
        $ttl = \max(1, $ttl);
        $storeKey = $this->storeKey($type, $key);
        $memory = $this->memory();
        $storedInMemory = false;
        if ($memory !== null) {
            try {
                $storedInMemory = $memory->set(self::NAMESPACE, $storeKey, $value, $ttl);
            } catch (\Throwable) {
                $this->memory = null;
            }
        }

        if ($type === 'trace') {
            $storedOnFile = $this->setToFile($type, $key, $value, $ttl);

            return $storedInMemory || $storedOnFile;
        }

        if ($storedInMemory) {
            return true;
        }

        return $this->setToFile($type, $key, $value, $ttl);
    }

    public function getLatest(string $type, int $withinSeconds = self::DEFAULT_TTL): mixed
    {
        $dir = $this->baseDir() . $this->safeType($type);
        if (!\is_dir($dir)) {
            return null;
        }

        $cutoff = \time() - \max(1, $withinSeconds);
        $latestFile = '';
        $latestMtime = 0;
        foreach ((array)\glob($dir . DS . '*' . DS . '*.json') as $file) {
            if (!\is_file($file)) {
                continue;
            }

            $mtime = (int)@\filemtime($file);
            if ($mtime < $cutoff || $mtime <= $latestMtime) {
                continue;
            }

            $latestFile = $file;
            $latestMtime = $mtime;
        }

        return $latestFile !== '' ? $this->readPayloadFile($latestFile) : null;
    }

    public static function hashQuery(array $query): string
    {
        \ksort($query);

        return \sha1(\json_encode($query, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?: '');
    }

    private function memory(): ?MemoryStateFacade
    {
        if ($this->memoryResolved) {
            return $this->memory;
        }
        $this->memoryResolved = true;

        if (($this->config['force_file'] ?? false) === true) {
            return null;
        }

        if (!\class_exists(Runtime::class, false) || !Runtime::isPersistent() || !\class_exists(MemoryStateFacade::class)) {
            return null;
        }

        try {
            $this->memory = new MemoryStateFacade($this->cachePolicy()->memoryOptions([
                'consumer_code' => self::NAMESPACE,
                'prefer_direct_connect' => true,
                'pool_size' => 1,
                'auto_start' => false,
            ]));
        } catch (\Throwable) {
            $this->memory = null;
        }

        return $this->memory;
    }

    private function cachePolicy(): RuntimeCachePolicy
    {
        return ObjectManager::getInstance(RuntimeCachePolicy::class);
    }

    private function getFromFile(string $type, string $key): mixed
    {
        $path = $this->filePath($type, $key);
        if (!\is_file($path)) {
            return null;
        }

        return $this->readPayloadFile($path);
    }

    private function readPayloadFile(string $path): mixed
    {
        $raw = @\file_get_contents($path);
        if (!\is_string($raw) || $raw === '') {
            return null;
        }

        $payload = \json_decode($raw, true);
        if (!\is_array($payload)) {
            return null;
        }

        $expiresAt = (int)($payload['expires_at'] ?? 0);
        if ($expiresAt > 0 && $expiresAt < \time()) {
            @\unlink($path);
            return null;
        }

        return $payload['value'] ?? null;
    }

    private function setToFile(string $type, string $key, mixed $value, int $ttl): bool
    {
        $path = $this->filePath($type, $key);
        $dir = \dirname($path);
        if (!\is_dir($dir) && !@\mkdir($dir, 0775, true) && !\is_dir($dir)) {
            return false;
        }

        $payload = [
            'expires_at' => \time() + $ttl,
            'key' => $key,
            'value' => $value,
        ];
        $json = \json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if (!\is_string($json)) {
            return false;
        }

        $tmp = $path . '.' . \bin2hex(\random_bytes(4)) . '.tmp';
        if (@\file_put_contents($tmp, $json, LOCK_EX) === false) {
            return false;
        }

        $ok = @\rename($tmp, $path);
        if (!$ok) {
            @\unlink($tmp);
            return false;
        }

        $this->gcTypePrefix($type, \substr(\sha1($this->storeKey($type, $key)), 0, 2));

        return true;
    }

    private function gcTypePrefix(string $type, string $prefix): void
    {
        if (\random_int(1, 100) !== 1) {
            return;
        }

        $dir = $this->baseDir() . $this->safeType($type) . DS . $prefix;
        if (!\is_dir($dir)) {
            return;
        }

        $now = \time();
        $checked = 0;
        foreach ((array)\glob($dir . DS . '*.json') as $file) {
            if (++$checked > 50) {
                break;
            }
            if (\is_file($file) && @\filemtime($file) !== false && ((int)@\filemtime($file)) < ($now - 3600)) {
                @\unlink($file);
            }
        }
    }

    private function filePath(string $type, string $key): string
    {
        $hash = \sha1($this->storeKey($type, $key));

        return $this->baseDir() . $this->safeType($type) . DS . \substr($hash, 0, 2) . DS . $hash . '.json';
    }

    private function baseDir(): string
    {
        return Env::VAR_DIR . 'dev_tool' . DS . 'payload' . DS;
    }

    private function storeKey(string $type, string $key): string
    {
        return $this->safeType($type) . ':' . $key;
    }

    private function safeType(string $type): string
    {
        $safe = \preg_replace('/[^a-z0-9_-]+/i', '_', $type);

        return $safe !== '' ? $safe : 'misc';
    }
}
