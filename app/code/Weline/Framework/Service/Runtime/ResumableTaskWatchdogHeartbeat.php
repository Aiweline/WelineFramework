<?php

declare(strict_types=1);

namespace Weline\Framework\Service\Runtime;

use Weline\Framework\Runtime\Resumable\ResumableTaskRuntimeUnavailableException;
use Weline\Framework\System\OS\FileHelper;

/**
 * Small, process-independent liveness witness for the Runtime Watchdog.
 *
 * A task is never allowed to fall back into an HTTP/SSE request when no
 * watchdog exists.  The watchdog records a short-lived heartbeat here and
 * `ResumableTaskRuntime::start()` checks it before creating a new task.
 */
final class ResumableTaskWatchdogHeartbeat
{
    private const MAX_AGE_SECONDS = 5;

    public function beat(string $ownerId, string $instanceName): void
    {
        $ownerId = trim($ownerId);
        if ($ownerId === '') {
            throw new \InvalidArgumentException('Runtime watchdog owner id is required.');
        }

        $instanceName = trim($instanceName);
        if ($instanceName === '') {
            throw new \InvalidArgumentException('Runtime watchdog instance name is required.');
        }

        $directory = $this->directory();
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new ResumableTaskRuntimeUnavailableException('Runtime watchdog heartbeat directory is unavailable.');
        }

        $path = $this->path($instanceName);
        $lock = @fopen($path . '.lock', 'c+b');
        if (!is_resource($lock) || !flock($lock, LOCK_EX)) {
            if (is_resource($lock)) {
                fclose($lock);
            }
            throw new ResumableTaskRuntimeUnavailableException('Runtime watchdog heartbeat lock is unavailable.');
        }

        $temporary = $path . '.' . bin2hex(random_bytes(6)) . '.tmp';
        try {
            $payload = json_encode([
                'owner_id' => $ownerId,
                'instance_name' => $instanceName,
                'instance_key' => $this->instanceKey($instanceName),
                'pid' => getmypid() ?: 0,
                'updated_at' => time(),
            ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            if (file_put_contents($temporary, $payload, LOCK_EX) === false) {
                FileHelper::deleteFile($temporary);
                throw new ResumableTaskRuntimeUnavailableException('Runtime watchdog heartbeat cannot be persisted.');
            }
            // PHP on Windows cannot atomically rename over an existing file.
            // Readers and the sole writer are already fenced by this
            // instance-specific lock, so removing the old target inside the
            // critical section preserves a consistent cooperative view.
            if (PHP_OS_FAMILY === 'Windows'
                && is_file($path)
                && !FileHelper::deleteFile($path)
            ) {
                throw new ResumableTaskRuntimeUnavailableException('Runtime watchdog heartbeat cannot be replaced.');
            }
            if (!@rename($temporary, $path)) {
                throw new ResumableTaskRuntimeUnavailableException('Runtime watchdog heartbeat cannot be activated.');
            }
        } finally {
            FileHelper::deleteFile($temporary);
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function assertAvailable(?string $instanceName = null): void
    {
        $instanceName = is_string($instanceName) ? trim($instanceName) : '';
        if ($instanceName !== '') {
            $payload = $this->read($instanceName);
            $updatedAt = is_array($payload) ? (int)($payload['updated_at'] ?? 0) : 0;
            if ($updatedAt > 0 && $updatedAt + self::MAX_AGE_SECONDS >= time()) {
                return;
            }
        } else {
            foreach (glob($this->directory() . DS . '*.json') ?: [] as $path) {
                $payload = $this->readPath($path);
                $updatedAt = is_array($payload) ? (int)($payload['updated_at'] ?? 0) : 0;
                if ($updatedAt > 0 && $updatedAt + self::MAX_AGE_SECONDS >= time()) {
                    return;
                }
            }
        }

        throw new ResumableTaskRuntimeUnavailableException(
            'Resumable task runtime is unavailable because its Watchdog is not active.'
        );
    }

    public function clearIfOwner(string $ownerId, string $instanceName): void
    {
        $ownerId = trim($ownerId);
        $instanceName = trim($instanceName);
        if ($ownerId === '' || $instanceName === '') {
            return;
        }

        $path = $this->path($instanceName);
        $lock = @fopen($path . '.lock', 'c+b');
        if (!is_resource($lock) || !flock($lock, LOCK_EX)) {
            if (is_resource($lock)) {
                fclose($lock);
            }
            return;
        }

        try {
            $payload = $this->decodeContent(@file_get_contents($path));
            if (!is_array($payload) || !hash_equals((string)($payload['owner_id'] ?? ''), $ownerId)) {
                return;
            }
            FileHelper::deleteFile($path);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** @return array<string,mixed>|null */
    private function read(string $instanceName): ?array
    {
        return $this->readPath($this->path($instanceName));
    }

    private function directory(): string
    {
        return BP . 'var' . DS . 'runtime' . DS . 'resumable' . DS . 'watchdogs';
    }

    private function readPath(string $path): ?array
    {
        $lock = @fopen($path . '.lock', 'c+b');
        if (is_resource($lock)) {
            flock($lock, LOCK_SH);
        }
        try {
            return $this->decodeContent(@file_get_contents($path));
        } finally {
            if (is_resource($lock)) {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }
    }

    /** @return array<string,mixed>|null */
    private function decodeContent(string|false $content): ?array
    {
        if (!is_string($content) || $content === '') {
            return null;
        }
        try {
            $decoded = json_decode($content, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        return is_array($decoded) ? $decoded : null;
    }

    private function instanceKey(string $instanceName): string
    {
        $instanceName = trim($instanceName);
        if ($instanceName === '') {
            throw new \InvalidArgumentException('Runtime watchdog instance name is required.');
        }

        $safe = preg_replace('/[^A-Za-z0-9._-]+/', '-', $instanceName);
        $safe = trim(is_string($safe) ? $safe : '', '.-_');
        if ($safe === '') {
            $safe = 'instance';
        }

        return substr($safe, 0, 80) . '-' . substr(hash('sha256', $instanceName), 0, 16);
    }

    private function path(string $instanceName): string
    {
        return $this->directory() . DS . $this->instanceKey($instanceName) . '.json';
    }
}
