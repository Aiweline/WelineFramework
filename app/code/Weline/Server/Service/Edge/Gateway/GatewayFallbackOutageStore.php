<?php

declare(strict_types=1);

namespace Weline\Server\Service\Edge\Gateway;

/**
 * Persists the project Agent's current gateway outage across Agent self-heal.
 *
 * This is project-runtime derived state, not host enrollment state. A different
 * Master identity starts a new outage window.
 */
final class GatewayFallbackOutageStore
{
    private readonly string $directory;

    public function __construct(?string $directory = null)
    {
        $this->directory = $directory
            ?? BP . 'var' . DIRECTORY_SEPARATOR . 'server'
                . DIRECTORY_SEPARATOR . 'gateway-v2'
                . DIRECTORY_SEPARATOR . 'agent-outages';
    }

    public function markDown(
        string $instanceName,
        int $masterPid,
        int $masterEpoch,
        int $now,
    ): int {
        return $this->withLock($instanceName, function () use (
            $instanceName,
            $masterPid,
            $masterEpoch,
            $now,
        ): int {
            $state = $this->read($instanceName);
            $sameMaster = $state !== null
                && \hash_equals($instanceName, (string)($state['instance'] ?? ''))
                && (int)($state['master_pid'] ?? 0) === $masterPid
                && (int)($state['master_epoch'] ?? 0) === $masterEpoch;
            $downSince = $sameMaster
                ? (int)($state['down_since_timestamp'] ?? 0)
                : 0;
            if ($downSince > 0 && $downSince <= $now) {
                return $downSince;
            }
            $downSince = $now;
            $this->publish($instanceName, [
                'schema_version' => 1,
                'instance' => $instanceName,
                'master_pid' => $masterPid,
                'master_epoch' => $masterEpoch,
                'down_since' => \gmdate(DATE_ATOM, $downSince),
                'down_since_timestamp' => $downSince,
                'updated_at' => \gmdate(DATE_ATOM, $now),
                'updated_timestamp' => $now,
            ]);
            return $downSince;
        });
    }

    public function clear(string $instanceName): void
    {
        $this->withLock($instanceName, function () use ($instanceName): void {
            $file = $this->stateFile($instanceName);
            if (\is_file($file) && !\is_link($file)) {
                @\unlink($file);
            }
        });
    }

    /**
     * @template TResult
     * @param callable():TResult $callback
     * @return TResult
     */
    private function withLock(string $instanceName, callable $callback): mixed
    {
        if (\is_link($this->directory)
            || (!\is_dir($this->directory)
                && !@\mkdir($this->directory, 0700, true)
                && !\is_dir($this->directory))
        ) {
            throw new \RuntimeException('Unable to create gateway Agent outage state directory.');
        }
        @\chmod($this->directory, 0700);
        $lockPath = $this->directory . DIRECTORY_SEPARATOR
            . \substr(\hash('sha256', $instanceName), 0, 24) . '.lock';
        if (\is_link($lockPath)) {
            throw new \RuntimeException('Gateway Agent outage lock must not be a symbolic link.');
        }
        $lock = @\fopen($lockPath, 'c+b');
        if (!\is_resource($lock) || !@\flock($lock, LOCK_EX)) {
            throw new \RuntimeException('Unable to lock gateway Agent outage state.');
        }
        @\chmod($lockPath, 0600);
        try {
            return $callback();
        } finally {
            @\flock($lock, LOCK_UN);
            @\fclose($lock);
        }
    }

    /**
     * @return array<string,mixed>|null
     */
    private function read(string $instanceName): ?array
    {
        $file = $this->stateFile($instanceName);
        if (!\is_file($file) || \is_link($file)) {
            return null;
        }
        $encoded = @\file_get_contents($file);
        $state = \is_string($encoded) ? \json_decode($encoded, true) : null;
        return \is_array($state) && (int)($state['schema_version'] ?? 0) === 1
            ? $state
            : null;
    }

    /**
     * @param array<string,mixed> $state
     */
    private function publish(string $instanceName, array $state): void
    {
        $file = $this->stateFile($instanceName);
        if (\is_link($file)) {
            throw new \RuntimeException('Gateway Agent outage state must not be a symbolic link.');
        }
        $encoded = \json_encode(
            $state,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
        if (!\is_string($encoded)) {
            throw new \RuntimeException('Unable to encode gateway Agent outage state.');
        }
        $temporary = $file . '.tmp-' . \bin2hex(\random_bytes(8));
        $stream = @\fopen($temporary, 'x+b');
        if (!\is_resource($stream)) {
            throw new \RuntimeException('Unable to stage gateway Agent outage state.');
        }
        try {
            @\chmod($temporary, 0600);
            if (@\fwrite($stream, $encoded) !== \strlen($encoded)
                || !@\fflush($stream)
                || (\function_exists('fsync') && !@\fsync($stream))
            ) {
                throw new \RuntimeException('Unable to persist gateway Agent outage state.');
            }
        } finally {
            @\fclose($stream);
        }
        if (!@\rename($temporary, $file)) {
            @\unlink($temporary);
            throw new \RuntimeException('Unable to publish gateway Agent outage state.');
        }
        @\chmod($file, 0600);
    }

    private function stateFile(string $instanceName): string
    {
        return $this->directory . DIRECTORY_SEPARATOR
            . \substr(\hash('sha256', $instanceName), 0, 24) . '.json';
    }
}
