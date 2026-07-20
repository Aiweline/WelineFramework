<?php

declare(strict_types=1);

namespace Weline\Server\Service\Runtime;

/**
 * POSIX-only, instance-scoped TLS ticket-ring control-plane store.
 *
 * Publishes key material atomically for the native HTTP/3 TLS consumer while
 * exposing only epoch/digest metadata to the Master and Worker argv.
 */
final class TlsTicketRingStore
{
    private const MAGIC = "WLSTKR01";
    private const VERSION = 1;
    private const SECRET_BYTES = 32;
    private const DIGEST_BYTES = 32;
    private const PREFIX_BYTES = 32;
    private const RECORD_BYTES = 128;
    private const MIN_ROTATION_SECONDS = 300;
    private const MAX_ROTATION_SECONDS = 604800;
    public const DEFAULT_ROTATION_SECONDS = 21600;

    private readonly string $directory;

    public function __construct(?string $directory = null)
    {
        $root = \defined('BP')
            ? \rtrim((string)\constant('BP'), '/\\')
            : \dirname(__DIR__, 6);
        $this->directory = $directory !== null && \trim($directory) !== ''
            ? \rtrim($directory, '/\\')
            : $root . DIRECTORY_SEPARATOR . 'var'
                . DIRECTORY_SEPARATOR . 'server'
                . DIRECTORY_SEPARATOR . 'tls-ticket-rings';
    }

    /**
     * Ensure the instance has one current/previous key snapshot.
     *
     * @return array{epoch:int,digest:string,rotated:bool}
     */
    public function ensure(
        string $instanceName,
        int $rotationSeconds = self::DEFAULT_ROTATION_SECONDS,
        ?int $now = null,
    ): array {
        $this->assertPosix();
        $instanceName = $this->normalizeInstanceName($instanceName);
        $rotationSeconds = \max(
            self::MIN_ROTATION_SECONDS,
            \min(self::MAX_ROTATION_SECONDS, $rotationSeconds)
        );
        $now ??= \time();
        if ($now <= 0) {
            throw new \RuntimeException('TLS ticket-ring clock is invalid.');
        }

        $this->ensureDirectory();
        $lock = $this->openLock();
        if (!\flock($lock, LOCK_EX)) {
            \fclose($lock);
            throw new \RuntimeException('Unable to lock the TLS ticket-ring store.');
        }

        $snapshot = null;
        $current = '';
        $previous = '';
        $payload = '';
        try {
            $path = $this->pathForInstance($instanceName);
            if (\is_file($path)) {
                $snapshot = $this->readSnapshotFromPath($path);
                $expiresAt = $snapshot['created_at'] + $rotationSeconds;
                if ($now < $expiresAt) {
                    return [
                        'epoch' => $snapshot['epoch'],
                        'digest' => $snapshot['digest'],
                        'rotated' => false,
                    ];
                }
                $previous = $snapshot['current'];
            } else {
                $previous = \random_bytes(self::SECRET_BYTES);
            }

            $current = \random_bytes(self::SECRET_BYTES);
            $epoch = ($snapshot['epoch'] ?? 0) + 1;
            $payload = $this->encode(
                $epoch,
                $now,
                $rotationSeconds,
                $current,
                $previous
            );
            $this->atomicWrite($path, $payload);
            $verified = $this->readSnapshotFromPath($path);
            try {
                return [
                    'epoch' => $verified['epoch'],
                    'digest' => $verified['digest'],
                    'rotated' => true,
                ];
            } finally {
                self::wipeSnapshot($verified);
            }
        } finally {
            if (\is_array($snapshot)) {
                self::wipeSnapshot($snapshot);
            }
            self::wipeString($current);
            self::wipeString($previous);
            self::wipeString($payload);
            \flock($lock, LOCK_UN);
            \fclose($lock);
        }
    }

    /**
     * Load a private snapshot for a process-local native HTTP/3 TLS adapter.
     *
     * The caller must invoke wipeSnapshot() in a finally block.
     *
     * @return array{epoch:int,created_at:int,rotation_seconds:int,digest:string,current:string,previous:string}
     */
    public function loadSecretSnapshot(string $instanceName): array
    {
        $this->assertPosix();
        $this->ensureDirectory();

        return $this->readSnapshotFromPath(
            $this->pathForInstance($this->normalizeInstanceName($instanceName))
        );
    }

    /**
     * @param array<string,mixed> $snapshot
     */
    public static function wipeSnapshot(array &$snapshot): void
    {
        foreach (['current', 'previous'] as $field) {
            if (isset($snapshot[$field]) && \is_string($snapshot[$field])) {
                self::wipeString($snapshot[$field]);
            }
            unset($snapshot[$field]);
        }
    }

    private function pathForInstance(string $instanceName): string
    {
        return $this->directory
            . DIRECTORY_SEPARATOR
            . \hash('sha256', $instanceName)
            . '.ring';
    }

    private function normalizeInstanceName(string $instanceName): string
    {
        $instanceName = \trim($instanceName);
        if ($instanceName === '' || \strlen($instanceName) > 512) {
            throw new \InvalidArgumentException('TLS ticket-ring instance name is invalid.');
        }

        return $instanceName;
    }

    private function assertPosix(): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            throw new \RuntimeException('TLS ticket-ring file store is POSIX-only.');
        }
    }

    private function ensureDirectory(): void
    {
        $created = false;
        if (!\is_dir($this->directory)) {
            $created = @\mkdir($this->directory, 0700, true);
            if (!$created && !\is_dir($this->directory)) {
                throw new \RuntimeException('Unable to create the TLS ticket-ring directory.');
            }
        }
        if ($created && !@\chmod($this->directory, 0700)) {
            throw new \RuntimeException('Unable to protect the TLS ticket-ring directory.');
        }

        $stat = @\lstat($this->directory);
        if (!\is_array($stat)
            || (((int)$stat['mode'] & 0170000) !== 0040000)
            || (((int)$stat['mode'] & 0777) !== 0700)
        ) {
            throw new \RuntimeException('TLS ticket-ring directory must be an owned 0700 directory.');
        }
        $this->assertOwner($stat, 'directory');
    }

    /**
     * @return resource
     */
    private function openLock()
    {
        $path = $this->directory . DIRECTORY_SEPARATOR . '.store.lock';
        $before = @\lstat($path);
        $created = false;
        if ($before === false) {
            $handle = @\fopen($path, 'x+b');
            if (\is_resource($handle)) {
                $created = true;
                if (!@\chmod($path, 0600)) {
                    \fclose($handle);
                    @\unlink($path);
                    throw new \RuntimeException('Unable to protect the TLS ticket-ring lock.');
                }
            } else {
                $handle = @\fopen($path, 'r+b');
            }
        } else {
            $this->assertSecureRegularPath($path, $before, 'lock');
            $handle = @\fopen($path, 'r+b');
        }
        if (!\is_resource($handle)) {
            throw new \RuntimeException('Unable to open the TLS ticket-ring lock.');
        }

        try {
            $after = @\lstat($path);
            if (!\is_array($after)) {
                throw new \RuntimeException('TLS ticket-ring lock disappeared.');
            }
            $this->assertSecureRegularPath($path, $after, 'lock');
            $this->assertOpenedPath($handle, $after, 'lock');
        } catch (\Throwable $throwable) {
            \fclose($handle);
            if ($created) {
                @\unlink($path);
            }
            throw $throwable;
        }

        return $handle;
    }

    /**
     * @return array{epoch:int,created_at:int,rotation_seconds:int,digest:string,current:string,previous:string}
     */
    private function readSnapshotFromPath(string $path): array
    {
        $pathStat = @\lstat($path);
        if (!\is_array($pathStat)) {
            throw new \RuntimeException('TLS ticket-ring snapshot is missing.');
        }
        $this->assertSecureRegularPath($path, $pathStat, 'snapshot');

        $handle = @\fopen($path, 'rb');
        if (!\is_resource($handle)) {
            throw new \RuntimeException('Unable to open the TLS ticket-ring snapshot.');
        }

        $payload = '';
        try {
            try {
                $this->assertOpenedPath($handle, $pathStat, 'snapshot');
                $payload = (string) \stream_get_contents(
                    $handle,
                    self::RECORD_BYTES + 1
                );
                if (\strlen($payload) !== self::RECORD_BYTES) {
                    throw new \RuntimeException('TLS ticket-ring snapshot length is invalid.');
                }
            } finally {
                \fclose($handle);
            }
            return $this->decode($payload);
        } finally {
            self::wipeString($payload);
        }
    }

    private function encode(
        int $epoch,
        int $createdAt,
        int $rotationSeconds,
        string $current,
        string $previous,
    ): string {
        if ($epoch <= 0
            || \strlen($current) !== self::SECRET_BYTES
            || \strlen($previous) !== self::SECRET_BYTES
        ) {
            throw new \RuntimeException('TLS ticket-ring key material is invalid.');
        }

        $unsigned = self::MAGIC
            . \pack('N', self::VERSION)
            . $this->packUint64($epoch)
            . $this->packUint64($createdAt)
            . \pack('N', $rotationSeconds)
            . $current
            . $previous;
        $digest = \hash('sha256', $unsigned, true);
        $payload = $unsigned . $digest;
        self::wipeString($unsigned);
        self::wipeString($digest);

        return $payload;
    }

    /**
     * @return array{epoch:int,created_at:int,rotation_seconds:int,digest:string,current:string,previous:string}
     */
    private function decode(string $payload): array
    {
        if (\strlen($payload) !== self::RECORD_BYTES
            || !\hash_equals(self::MAGIC, \substr($payload, 0, 8))
        ) {
            throw new \RuntimeException('TLS ticket-ring magic or length is invalid.');
        }

        $version = \unpack('Nvalue', \substr($payload, 8, 4));
        if ((int)($version['value'] ?? 0) !== self::VERSION) {
            throw new \RuntimeException('TLS ticket-ring version is unsupported.');
        }

        $epoch = $this->unpackUint64(\substr($payload, 12, 8));
        $createdAt = $this->unpackUint64(\substr($payload, 20, 8));
        $rotation = \unpack('Nvalue', \substr($payload, 28, 4));
        $rotationSeconds = (int)($rotation['value'] ?? 0);
        $current = \substr($payload, self::PREFIX_BYTES, self::SECRET_BYTES);
        $previous = \substr(
            $payload,
            self::PREFIX_BYTES + self::SECRET_BYTES,
            self::SECRET_BYTES
        );
        $digest = \substr($payload, self::RECORD_BYTES - self::DIGEST_BYTES);
        $unsigned = \substr($payload, 0, self::RECORD_BYTES - self::DIGEST_BYTES);
        $expected = \hash('sha256', $unsigned, true);

        try {
            if ($epoch <= 0
                || $createdAt <= 0
                || $rotationSeconds < self::MIN_ROTATION_SECONDS
                || $rotationSeconds > self::MAX_ROTATION_SECONDS
                || \strlen($current) !== self::SECRET_BYTES
                || \strlen($previous) !== self::SECRET_BYTES
                || !\hash_equals($expected, $digest)
            ) {
                throw new \RuntimeException('TLS ticket-ring snapshot integrity validation failed.');
            }

            return [
                'epoch' => $epoch,
                'created_at' => $createdAt,
                'rotation_seconds' => $rotationSeconds,
                'digest' => \bin2hex($digest),
                'current' => $current,
                'previous' => $previous,
            ];
        } catch (\Throwable $throwable) {
            self::wipeString($current);
            self::wipeString($previous);
            throw $throwable;
        } finally {
            self::wipeString($digest);
            self::wipeString($unsigned);
            self::wipeString($expected);
        }
    }

    private function atomicWrite(string $path, string $payload): void
    {
        $temporary = $this->directory
            . DIRECTORY_SEPARATOR
            . '.ring.'
            . \bin2hex(\random_bytes(12))
            . '.tmp';
        $handle = @\fopen($temporary, 'x+b');
        if (!\is_resource($handle)) {
            throw new \RuntimeException('Unable to create a TLS ticket-ring temporary file.');
        }

        try {
            if (!@\chmod($temporary, 0600)) {
                throw new \RuntimeException('Unable to protect the TLS ticket-ring temporary file.');
            }
            $offset = 0;
            $length = \strlen($payload);
            while ($offset < $length) {
                $written = \fwrite($handle, \substr($payload, $offset));
                if (!\is_int($written) || $written <= 0) {
                    throw new \RuntimeException('Unable to write the TLS ticket-ring snapshot.');
                }
                $offset += $written;
            }
            if (!\fflush($handle)) {
                throw new \RuntimeException('Unable to flush the TLS ticket-ring snapshot.');
            }
            if (\function_exists('fsync') && !@\fsync($handle)) {
                throw new \RuntimeException('Unable to sync the TLS ticket-ring snapshot.');
            }
            $stat = \fstat($handle);
            if (!\is_array($stat) || (int)($stat['size'] ?? -1) !== self::RECORD_BYTES) {
                throw new \RuntimeException('TLS ticket-ring temporary file length is invalid.');
            }
            \fclose($handle);
            $handle = null;

            $temporaryStat = @\lstat($temporary);
            if (!\is_array($temporaryStat)) {
                throw new \RuntimeException('TLS ticket-ring temporary file disappeared.');
            }
            $this->assertSecureRegularPath($temporary, $temporaryStat, 'temporary file');
            if (!@\rename($temporary, $path)) {
                throw new \RuntimeException('Unable to atomically publish the TLS ticket-ring snapshot.');
            }
            $publishedStat = @\lstat($path);
            if (!\is_array($publishedStat)) {
                throw new \RuntimeException('Published TLS ticket-ring snapshot is missing.');
            }
            $this->assertSecureRegularPath($path, $publishedStat, 'snapshot');
            $this->syncDirectory();
        } finally {
            if (\is_resource($handle)) {
                \fclose($handle);
            }
            if (\is_file($temporary)) {
                @\unlink($temporary);
            }
        }
    }

    /**
     * @param array<string,mixed> $stat
     */
    private function assertSecureRegularPath(string $path, array $stat, string $label): void
    {
        if ((((int)$stat['mode'] & 0170000) !== 0100000)
            || (((int)$stat['mode'] & 0777) !== 0600)
        ) {
            throw new \RuntimeException('TLS ticket-ring ' . $label . ' is not an owned 0600 regular file.');
        }
        $this->assertOwner($stat, $label);
        if (\is_link($path)) {
            throw new \RuntimeException('TLS ticket-ring ' . $label . ' must not be a symbolic link.');
        }
    }

    /**
     * @param resource $handle
     * @param array<string,mixed> $pathStat
     */
    private function assertOpenedPath($handle, array $pathStat, string $label): void
    {
        $opened = \fstat($handle);
        if (!\is_array($opened)
            || (int)($opened['dev'] ?? -1) !== (int)($pathStat['dev'] ?? -2)
            || (int)($opened['ino'] ?? -1) !== (int)($pathStat['ino'] ?? -2)
        ) {
            throw new \RuntimeException('TLS ticket-ring ' . $label . ' changed while opening.');
        }
    }

    /**
     * @param array<string,mixed> $stat
     */
    private function assertOwner(array $stat, string $label): void
    {
        $effectiveUid = \function_exists('posix_geteuid')
            ? (int)\posix_geteuid()
            : (int)\getmyuid();
        if ((int)($stat['uid'] ?? -1) !== $effectiveUid) {
            throw new \RuntimeException('TLS ticket-ring ' . $label . ' owner is invalid.');
        }
    }

    private function packUint64(int $value): string
    {
        if ($value < 0) {
            throw new \RuntimeException('TLS ticket-ring unsigned integer is invalid.');
        }

        return \pack('N2', ($value >> 32) & 0xffffffff, $value & 0xffffffff);
    }

    private function unpackUint64(string $value): int
    {
        $parts = \unpack('Nhigh/Nlow', $value);
        $high = (int)($parts['high'] ?? 0);
        $low = (int)($parts['low'] ?? 0);

        return ($high << 32) | $low;
    }

    private function syncDirectory(): void
    {
        if (!\function_exists('fsync')) {
            return;
        }
        $directory = @\fopen($this->directory, 'r');
        if (\is_resource($directory)) {
            @\fsync($directory);
            \fclose($directory);
        }
    }

    private static function wipeString(string &$value): void
    {
        if ($value === '') {
            return;
        }
        if (\function_exists('sodium_memzero')) {
            \sodium_memzero($value);
            return;
        }
        $value = \str_repeat("\0", \strlen($value));
        $value = '';
    }
}
