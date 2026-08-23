<?php

declare(strict_types=1);

namespace LearningMcp;

use RuntimeException;

final class SessionIdentity
{
    private const KEY_BYTES = 32;

    private readonly string $key;

    public function __construct(Config $config)
    {
        $directory = $config->dataDir();
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create session identity directory');
        }
        @chmod($directory, 0700);
        $path = rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'session-identity.key';
        $handle = @fopen($path, 'c+b');
        if (!is_resource($handle) || !flock($handle, LOCK_EX)) {
            if (is_resource($handle)) {
                fclose($handle);
            }
            throw new RuntimeException('Unable to lock session identity key');
        }
        try {
            $size = fstat($handle)['size'] ?? 0;
            if ($size === 0) {
                $key = random_bytes(self::KEY_BYTES);
                if (fwrite($handle, $key) !== self::KEY_BYTES || !fflush($handle)) {
                    throw new RuntimeException('Unable to persist session identity key');
                }
            }
            rewind($handle);
            $key = stream_get_contents($handle);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
        if (!is_string($key) || strlen($key) !== self::KEY_BYTES) {
            throw new RuntimeException('Session identity key is missing or invalid');
        }
        @chmod($path, 0600);
        $this->key = $key;
    }

    public function hash(string $sessionId): string
    {
        $sessionId = trim($sessionId);
        if ($sessionId === '') {
            throw new RuntimeException('Session ID is required');
        }

        return hash_hmac('sha256', $sessionId, $this->key);
    }
}
